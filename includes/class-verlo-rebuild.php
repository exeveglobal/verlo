<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Full-site (re)build of the knowledge graph.
 *
 * Designed to handle large sites without PHP timeouts and without an external
 * job library: it snapshots the target object IDs, then processes them in
 * time-boxed batches, re-queueing a single cron event until done. The admin
 * trigger also runs the first few seconds inline so progress is visible
 * immediately even on low-traffic sites where WP-Cron is sluggish.
 *
 * (Action Scheduler is the recommended drop-in upgrade for very high volume;
 * the batch interface here maps onto it cleanly later.)
 */
class Verlo_Rebuild {

	const BATCH = 25;

	public static function init() {
		add_action( VERLO_HOOK_REBUILD_CONTINUE, array( __CLASS__, 'continue_cron' ) );
		// Self-healing: advance the build on plugin-page loads so it completes
		// even when WP-Cron never fires (quiet or local sites).
		add_action( 'admin_init', array( __CLASS__, 'maybe_advance_inline' ) );
	}

	/**
	 * If a rebuild is running and the admin is viewing the plugin page, process
	 * a short time-boxed batch. Keeps progress moving without WP-Cron.
	 */
	public static function maybe_advance_inline() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( ! isset( $_GET['page'] ) || 'verlo' !== $_GET['page'] ) { return; }
		$progress = self::progress();
		if ( empty( $progress['running'] ) ) { return; }
		self::run_until( 5 );
	}

	/**
	 * Snapshot all published target objects and reset the graph, opening a
	 * logged build run. $trigger: activation | admin | cron.
	 */
	public static function start( $post_types, $trigger = 'admin' ) {
		$ids = get_posts( array(
			'post_type'        => $post_types,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		) );
		$ids = array_map( 'intval', (array) $ids );

		Verlo_Install::truncate();

		// Empty site: open and immediately complete a run so status never sticks.
		if ( empty( $ids ) ) {
			$run_id = Verlo_Build_Log::start_run( $trigger, 0 );
			Verlo_Build_Log::update( $run_id, array(
				'status'      => 'completed',
				'finished_at' => time(),
				'duration'    => 0,
				'peak_memory' => size_format( memory_get_peak_usage( true ) ),
			) );
			delete_option( VERLO_OPT_SNAPSHOT );
			update_option( VERLO_OPT_PROGRESS, array(
				'total'    => 0,
				'done'     => 0,
				'running'  => false,
				'started'  => time(),
				'finished' => time(),
				'run_id'   => $run_id,
			), 'no' );
			return;
		}

		$run_id = Verlo_Build_Log::start_run( $trigger, count( $ids ) );

		update_option( VERLO_OPT_SNAPSHOT, $ids, 'no' );
		update_option( VERLO_OPT_PROGRESS, array(
			'total'          => count( $ids ),
			'done'           => 0,
			'running'        => true,
			'started'        => time(),
			'run_id'         => $run_id,
			'guard_cursor'   => -1,
			'guard_attempts' => 0,
		), 'no' );
	}

	/**
	 * Process up to one batch from the current cursor, capturing per-object
	 * failures into the build log. Advances the cursor per object so a caught
	 * error doesn't re-run, and guards against an object that repeatedly aborts
	 * the request (uncatchable fatal) by skipping it after two attempts.
	 */
	public static function process_batch( $size = self::BATCH ) {
		global $wpdb;
		$progress = self::progress();
		if ( empty( $progress['running'] ) ) { return $progress; }

		$run_id = isset( $progress['run_id'] ) ? (int) $progress['run_id'] : 0;
		$ids    = get_option( VERLO_OPT_SNAPSHOT, array() );
		$ids    = is_array( $ids ) ? $ids : array();

		$succeeded = 0;
		$failed    = 0;
		$errors    = array();
		$count     = 0;

		while ( $count < $size && (int) $progress['done'] < (int) $progress['total'] ) {
			$cursor  = (int) $progress['done'];
			$post_id = isset( $ids[ $cursor ] ) ? (int) $ids[ $cursor ] : 0;

			// Poison-pill guard: same cursor attempted repeatedly => skip it.
			if ( (int) ( $progress['guard_cursor'] ?? -1 ) === $cursor ) {
				$progress['guard_attempts'] = (int) ( $progress['guard_attempts'] ?? 0 ) + 1;
			} else {
				$progress['guard_cursor']   = $cursor;
				$progress['guard_attempts'] = 1;
			}

			if ( (int) $progress['guard_attempts'] > 2 ) {
				$errors[] = array( $post_id, 'Skipped after repeated aborts on this object (likely an uncatchable error such as memory exhaustion or timeout while indexing it).' );
				$failed++;
				$progress['done']           = $cursor + 1;
				$progress['guard_cursor']   = -1;
				$progress['guard_attempts'] = 0;
				update_option( VERLO_OPT_PROGRESS, $progress, 'no' );
				$count++;
				continue;
			}

			// Persist the guard BEFORE processing so an uncatchable fatal here is
			// detectable on the next request.
			update_option( VERLO_OPT_PROGRESS, $progress, 'no' );

			try {
				if ( $wpdb ) { $wpdb->last_error = ''; }
				Verlo_Knowledge_Graph::index_object( $post_id );
				if ( $wpdb && ! empty( $wpdb->last_error ) ) {
					throw new Exception( 'Database error: ' . $wpdb->last_error );
				}
				$succeeded++;
			} catch ( \Throwable $e ) {
				$failed++;
				$errors[] = array( $post_id, $e->getMessage() );
			}

			$progress['done']           = $cursor + 1;
			$progress['guard_cursor']   = -1;
			$progress['guard_attempts'] = 0;
			update_option( VERLO_OPT_PROGRESS, $progress, 'no' );
			$count++;
		}

		$complete = ( (int) $progress['done'] >= (int) $progress['total'] );
		if ( $complete ) {
			$progress['running']  = false;
			$progress['finished'] = time();
			delete_option( VERLO_OPT_SNAPSHOT );
			update_option( VERLO_OPT_PROGRESS, $progress, 'no' );
		}

		// Record this batch's outcome in the run log.
		if ( $run_id ) {
			$run = Verlo_Build_Log::get( $run_id );
			if ( $run ) {
				Verlo_Build_Log::update( $run_id, array(
					'processed' => (int) $run['processed'] + $succeeded + $failed,
					'succeeded' => (int) $run['succeeded'] + $succeeded,
					'failed'    => (int) $run['failed'] + $failed,
				) );
				foreach ( $errors as $e ) {
					Verlo_Build_Log::add_error( $run_id, $e[0], $e[1] );
				}
				if ( $complete ) {
					$run    = Verlo_Build_Log::get( $run_id );
					$status = ( (int) $run['failed'] > 0 )
						? ( (int) $run['succeeded'] > 0 ? 'completed_with_errors' : 'failed' )
						: 'completed';
					Verlo_Build_Log::update( $run_id, array(
						'status'      => $status,
						'finished_at' => time(),
						'duration'    => time() - (int) $run['started_at'],
						'peak_memory' => size_format( memory_get_peak_usage( true ) ),
					) );
				}
			}
		}

		return $progress;
	}

	/**
	 * Process batches until a time budget is spent or the build completes.
	 */
	public static function run_until( $seconds = 15 ) {
		$deadline = microtime( true ) + $seconds;
		do {
			$progress = self::process_batch();
		} while ( ! empty( $progress['running'] ) && microtime( true ) < $deadline );
		return $progress;
	}

	/**
	 * Cron callback: work for a while, then re-queue itself if not finished.
	 */
	public static function continue_cron() {
		$progress = self::run_until( 20 );
		if ( ! empty( $progress['running'] ) ) {
			wp_schedule_single_event( time() + 5, VERLO_HOOK_REBUILD_CONTINUE );
		}
	}

	/**
	 * Kick off a rebuild from the admin: reset, work inline briefly, then hand
	 * off the remainder to cron.
	 */
	public static function trigger_from_admin( $post_types ) {
		self::start( $post_types, 'admin' );
		$progress = self::run_until( 6 );
		if ( ! empty( $progress['running'] ) && ! wp_next_scheduled( VERLO_HOOK_REBUILD_CONTINUE ) ) {
			wp_schedule_single_event( time() + 5, VERLO_HOOK_REBUILD_CONTINUE );
		}
		return $progress;
	}

	/**
	 * Empty the graph data without rebuilding (leaves the build history intact).
	 */
	public static function clear_graph() {
		wp_clear_scheduled_hook( VERLO_HOOK_REBUILD_CONTINUE );
		Verlo_Install::truncate();
		delete_option( VERLO_OPT_SNAPSHOT );
		update_option( VERLO_OPT_PROGRESS, array(
			'total'   => 0,
			'done'    => 0,
			'running' => false,
			'cleared' => time(),
		), 'no' );
		update_option( VERLO_OPT_CACHE_VER, (int) get_option( VERLO_OPT_CACHE_VER, 1 ) + 1, 'no' );
	}

	public static function progress() {
		$p = get_option( VERLO_OPT_PROGRESS, array() );
		return is_array( $p ) ? wp_parse_args( $p, array( 'total' => 0, 'done' => 0, 'running' => false ) ) : array( 'total' => 0, 'done' => 0, 'running' => false );
	}
}
