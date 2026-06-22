<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Content Brief storage. One brief per planned map article (keyed by the map's
 * article id). A brief is the reviewable spec the generator will later write
 * from — no article is produced at this stage.
 */
class Verlo_Brief {

	const OPT = 'verlo_briefs';

	public static function all() {
		$b = get_option( self::OPT, array() );
		return is_array( $b ) ? $b : array();
	}

	public static function get( $article_id ) {
		$all = self::all();
		return isset( $all[ (int) $article_id ] ) ? $all[ (int) $article_id ] : null;
	}

	public static function exists( $article_id ) {
		return null !== self::get( $article_id );
	}

	public static function save( $article_id, $brief ) {
		$all = self::all();
		$all[ (int) $article_id ] = $brief;
		update_option( self::OPT, $all, 'no' );
		return $brief;
	}

	public static function delete( $article_id ) {
		$all = self::all();
		unset( $all[ (int) $article_id ] );
		update_option( self::OPT, $all, 'no' );
	}

	public static function count() {
		return count( self::all() );
	}

	/**
	 * Generation status for an article's draft, used to drive the async UI.
	 * Returns one of: 'idle' (no run in progress), 'queued', 'running',
	 * 'done', 'error', plus a message/timestamp. Stored on the brief itself.
	 */
	public static function get_gen_status( $article_id ) {
		$brief = self::get( $article_id );
		if ( ! $brief || empty( $brief['gen'] ) || ! is_array( $brief['gen'] ) ) {
			return array( 'state' => 'idle', 'message' => '', 'updated_at' => 0 );
		}
		return wp_parse_args( $brief['gen'], array( 'state' => 'idle', 'message' => '', 'updated_at' => 0 ) );
	}

	public static function set_gen_status( $article_id, $state, $message = '' ) {
		$brief = self::get( $article_id );
		if ( ! $brief ) { return; }
		$brief['gen'] = array(
			'state'      => $state,
			'message'    => $message,
			'updated_at' => time(),
			// Preserve any existing run_id across status transitions.
			'run_id'     => isset( $brief['gen']['run_id'] ) ? $brief['gen']['run_id'] : '',
		);
		self::save( (int) $article_id, $brief );
	}

	/**
	 * Correlation id grouping every log row for one generation. Set when a run
	 * is queued; read by the worker/timing logs so the Logs tab can group them.
	 */
	public static function get_run_id( $article_id ) {
		$brief = self::get( $article_id );
		return ( $brief && ! empty( $brief['gen']['run_id'] ) ) ? (string) $brief['gen']['run_id'] : '';
	}

	public static function set_run_id( $article_id, $run_id ) {
		$brief = self::get( $article_id );
		if ( ! $brief ) { return; }
		if ( ! isset( $brief['gen'] ) || ! is_array( $brief['gen'] ) ) {
			$brief['gen'] = array( 'state' => 'idle', 'message' => '', 'updated_at' => time() );
		}
		$brief['gen']['run_id'] = (string) $run_id;
		self::save( (int) $article_id, $brief );
	}

	/**
	 * Reconcile briefs against the current map. Briefs whose planned article is
	 * no longer in the map are NOT deleted (that would silently destroy work the
	 * user already generated). Instead they are marked 'archived': hidden from
	 * the active brief list, but preserved — and any generated article remains
	 * untouched in WordPress and in the durable article history. Returns the
	 * number newly archived.
	 */
	public static function prune( $valid_ids ) {
		$all      = self::all();
		$valid    = array_flip( array_map( 'intval', $valid_ids ) );
		$archived = 0;
		$changed  = false;
		foreach ( $all as $aid => $brief ) {
			if ( ! isset( $valid[ (int) $aid ] ) ) {
				if ( empty( $brief['archived'] ) ) {
					$brief['archived']    = true;
					$brief['archived_at'] = time();
					$all[ $aid ]          = $brief;
					$archived++;
					$changed = true;
				}
			} else {
				// Article is back in the map — un-archive if it was archived.
				if ( ! empty( $brief['archived'] ) ) {
					unset( $all[ $aid ]['archived'], $all[ $aid ]['archived_at'] );
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			update_option( self::OPT, $all, 'no' );
		}
		return $archived;
	}

	/**
	 * Active briefs only (not archived) — what the brief list should show.
	 */
	public static function active() {
		$out = array();
		foreach ( self::all() as $aid => $brief ) {
			if ( empty( $brief['archived'] ) ) { $out[ $aid ] = $brief; }
		}
		return $out;
	}

	/**
	 * Count of briefs that have been archived by a map rebuild.
	 */
	public static function archived_count() {
		$n = 0;
		foreach ( self::all() as $brief ) {
			if ( ! empty( $brief['archived'] ) ) { $n++; }
		}
		return $n;
	}

	/**
	 * Default/empty brief shape (also documents the schema).
	 */
	public static function blank() {
		return array(
			'keyword'        => '',
			'intent'         => 'informational',
			'pillar'         => '',
			'suggested_title'=> '',
			'angle'          => '',
			'search_intent'  => '',
			'audience_note'  => '',
			'outline'        => array(),  // list of H2 section headings
			'internal_links' => array(),  // [ {url, anchor}, ... ]
			'external_ideas' => array(),  // authoritative source types to cite
			'faq'            => array(),  // suggested FAQ questions
			'word_count'     => 1500,
			'voice_note'     => '',
			'meta'           => array( 'generated_at' => 0, 'updated_at' => 0 ),
		);
	}
}
