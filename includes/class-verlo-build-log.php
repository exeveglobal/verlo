<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Persistent log of knowledge-graph build runs.
 *
 * Each run captures trigger, status, counts, timing, environment, and any
 * per-object errors so a failed or partial build is diagnosable after the fact.
 * Stored as a capped option (no custom table needed for this low-volume log).
 */
class Verlo_Build_Log {

	const OPT        = 'verlo_build_runs';
	const MAX_RUNS   = 25;
	const MAX_ERRORS = 30;

	public static function all() {
		$runs = get_option( self::OPT, array() );
		return is_array( $runs ) ? $runs : array();
	}

	protected static function save( $runs ) {
		update_option( self::OPT, $runs, 'no' );
	}

	/**
	 * Open a new run. Any previously "running" run is marked aborted (superseded).
	 */
	public static function start_run( $trigger, $total ) {
		$runs = self::all();
		foreach ( $runs as &$r ) {
			if ( 'running' === $r['status'] ) {
				$r['status']      = 'aborted';
				$r['finished_at'] = time();
			}
		}
		unset( $r );

		$id = 1;
		foreach ( $runs as $r ) {
			if ( $r['id'] >= $id ) { $id = $r['id'] + 1; }
		}

		$run = array(
			'id'          => $id,
			'trigger'     => $trigger,
			'status'      => 'running',
			'total'       => (int) $total,
			'processed'   => 0,
			'succeeded'   => 0,
			'failed'      => 0,
			'started_at'  => time(),
			'finished_at' => null,
			'duration'    => null,
			'peak_memory' => null,
			'env'         => array(
				'php'    => PHP_VERSION,
				'wp'     => get_bloginfo( 'version' ),
				'plugin' => defined( 'VERLO_VERSION' ) ? VERLO_VERSION : '',
			),
			'errors'      => array(),
		);

		array_unshift( $runs, $run );
		$runs = array_slice( $runs, 0, self::MAX_RUNS );
		self::save( $runs );
		return $id;
	}

	public static function get( $id ) {
		foreach ( self::all() as $r ) {
			if ( (int) $r['id'] === (int) $id ) { return $r; }
		}
		return null;
	}

	public static function update( $id, $changes ) {
		$runs = self::all();
		foreach ( $runs as &$r ) {
			if ( (int) $r['id'] === (int) $id ) {
				$r = array_merge( $r, $changes );
				break;
			}
		}
		unset( $r );
		self::save( $runs );
	}

	public static function add_error( $id, $object_id, $message ) {
		$runs = self::all();
		foreach ( $runs as &$r ) {
			if ( (int) $r['id'] === (int) $id ) {
				if ( count( $r['errors'] ) < self::MAX_ERRORS ) {
					$r['errors'][] = array(
						'object_id' => (int) $object_id,
						'message'   => substr( (string) $message, 0, 300 ),
						'time'      => time(),
					);
				}
				break;
			}
		}
		unset( $r );
		self::save( $runs );
	}

	/**
	 * The run that produced the data currently in the tables: most recent run
	 * that actually completed (with or without errors).
	 */
	public static function current_build() {
		foreach ( self::all() as $r ) {
			if ( in_array( $r['status'], array( 'completed', 'completed_with_errors' ), true ) ) {
				return $r;
			}
		}
		return null;
	}

	public static function clear() {
		delete_option( self::OPT );
	}
}
