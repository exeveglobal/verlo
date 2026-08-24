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
		delete_option( self::gen_status_option( $article_id ) );
	}

	public static function count() {
		return count( self::all() );
	}

	/**
	 * Generation status for an article's draft, used to drive the async UI.
	 * Returns one of: 'idle' (no run in progress), 'queued', 'running',
	 * 'done', 'error', plus a message/timestamp/run_id.
	 *
	 * Stored in its OWN per-article option, NOT inside the shared 'verlo_briefs'
	 * blob (all() / save() below). It used to live on the brief itself, but
	 * that blob holds every brief on the site as one array under a single
	 * option, and save() always does a full read-modify-write of the whole
	 * thing. Status changes multiple times per generation (queued -> running
	 * -> done/error) via three separate dispatch paths (loopback, WP-Cron,
	 * poll-driven self-heal) that can genuinely run close together in time -
	 * confirmed live 2026-08-24: a fresh 'queued' write was found reverted to
	 * a 75-second-old 'error' by the time WP-Cron's fallback checked it, with
	 * nothing in between ever setting it back to 'error' - a classic lost
	 * update, not a code bug in any single write. A per-article option makes
	 * each article's status update_option() call independent of every other
	 * article's (and of the brief content's own, much less frequent, saves).
	 */
	protected static function gen_status_option( $article_id ) {
		return '_verlo_gen_status_' . (int) $article_id;
	}

	public static function get_gen_status( $article_id ) {
		$raw = get_option( self::gen_status_option( $article_id ), array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), array(
			'state' => 'idle', 'message' => '', 'updated_at' => 0, 'run_id' => '',
		) );
	}

	public static function set_gen_status( $article_id, $state, $message = '' ) {
		// Preserve any existing run_id across status transitions.
		$current = self::get_gen_status( $article_id );
		update_option( self::gen_status_option( $article_id ), array(
			'state'      => $state,
			'message'    => $message,
			'updated_at' => time(),
			'run_id'     => $current['run_id'],
		), 'no' );
	}

	/**
	 * Correlation id grouping every log row for one generation. Set when a run
	 * is queued; read by the worker/timing logs so the Logs tab can group them.
	 */
	public static function get_run_id( $article_id ) {
		return self::get_gen_status( $article_id )['run_id'];
	}

	public static function set_run_id( $article_id, $run_id ) {
		$current = self::get_gen_status( $article_id );
		update_option( self::gen_status_option( $article_id ), array(
			'state'      => $current['state'],
			'message'    => $current['message'],
			'updated_at' => $current['updated_at'],
			'run_id'     => (string) $run_id,
		), 'no' );
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
			'brief_id'       => '', // server-side brief_records id, sent back on the article job
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
