<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, VERSIONED record of every article Verlo has generated. This is
 * intentionally SEPARATE from briefs and the topical map: rebuilding the map
 * or pruning briefs must never erase the record of work already produced.
 * The actual article lives as a normal WordPress post; this store keeps the
 * metadata and a stable link to it, so there is always a single place to see
 * everything Verlo has written — even after a map rebuild.
 *
 * Keyed by post_id, but each post_id maps to a LIST of version rows (oldest
 * first) rather than a single row — regenerating a draft appends a new
 * version instead of silently overwriting the record of the previous one, so
 * "what did Verlo actually write the first time" is never lost.
 *
 * Live status (draft/published/trashed/deleted) is computed from the real post
 * at display time, so the history can never show a stale or false state.
 */
class Verlo_Article_Log {

	const OPT           = 'verlo_article_log';
	const MAX_ROWS       = 500; // generous; one entry per distinct generated article (post)
	const MAX_VERSIONS   = 20;  // per post — bounds storage growth from repeated regenerates

	/**
	 * Record a new generation. Keyed by post_id; each call APPENDS a version
	 * rather than replacing the post's history, so every past generation of
	 * the same draft stays visible.
	 */
	public static function record( $data ) {
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		if ( ! $post_id ) { return; }

		$rows     = self::all();
		$existing = self::normalize_versions( isset( $rows[ $post_id ] ) ? $rows[ $post_id ] : array() );

		$version = array(
			'version'     => count( $existing ) + 1,
			'article_id'  => isset( $data['article_id'] ) ? (int) $data['article_id'] : 0,
			'keyword'     => isset( $data['keyword'] ) ? (string) $data['keyword'] : '',
			'title'       => isset( $data['title'] ) ? (string) $data['title'] : '',
			'pillar'      => isset( $data['pillar'] ) ? (string) $data['pillar'] : '',
			'word_target' => isset( $data['word_target'] ) ? (int) $data['word_target'] : 0,
			'gen_seconds' => isset( $data['gen_seconds'] ) ? (float) $data['gen_seconds'] : null,
			'run_id'      => isset( $data['run_id'] ) ? (string) $data['run_id'] : '',
			'generated_at'=> time(),
		);

		$existing[] = $version;
		if ( count( $existing ) > self::MAX_VERSIONS ) {
			$existing = array_slice( $existing, -self::MAX_VERSIONS );
		}

		$rows[ $post_id ] = $existing;

		// Cap distinct posts (keep most recently generated) — defensive; normally well under MAX.
		if ( count( $rows ) > self::MAX_ROWS ) {
			uasort( $rows, function ( $a, $b ) {
				return self::latest( $b )['generated_at'] <=> self::latest( $a )['generated_at'];
			} );
			$rows = array_slice( $rows, 0, self::MAX_ROWS, true );
		}

		update_option( self::OPT, $rows, 'no' );
	}

	/**
	 * Raw per-post version lists, normalized to the current (list-of-versions)
	 * shape. Transparently upgrades rows written before versioning existed
	 * (a single flat row per post_id) into a one-entry version list, so
	 * history recorded before this feature shipped is never lost or errors.
	 */
	public static function all() {
		$rows = get_option( self::OPT, array() );
		if ( ! is_array( $rows ) ) { return array(); }

		foreach ( $rows as $post_id => $entry ) {
			$rows[ $post_id ] = self::normalize_versions( $entry );
		}
		return $rows;
	}

	public static function count() {
		return count( self::all() );
	}

	/**
	 * One row per POST, newest-first by most recent generation, each enriched
	 * with a LIVE status computed from the actual post (so the history never
	 * lies about what currently exists). The row's top-level fields reflect
	 * the LATEST version; the full version history (oldest-first) is under
	 * 'versions' for callers that want to show past generations too.
	 * Status: 'published' | 'draft' | 'pending' | 'future' | 'private' |
	 *         'trashed' | 'deleted' | 'other'.
	 */
	public static function recent( $limit = 200 ) {
		$rows = self::all();

		uasort( $rows, function ( $a, $b ) {
			return self::latest( $b )['generated_at'] <=> self::latest( $a )['generated_at'];
		} );
		$rows = array_slice( $rows, 0, (int) $limit, true );

		$out = array();
		foreach ( $rows as $post_id => $versions ) {
			$post_id = (int) $post_id;
			$latest  = self::latest( $versions );

			$status = self::live_status( $post_id );
			$out[]  = array_merge( $latest, array(
				'post_id'      => $post_id,
				'created_at'   => $versions[0]['generated_at'],
				'updated_at'   => $latest['generated_at'],
				'version_count'=> count( $versions ),
				'versions'     => array_reverse( $versions ), // newest-first for display
				'status'       => $status,
				'edit_url'     => 'deleted' === $status ? '' : get_edit_post_link( $post_id, 'raw' ),
				'view_url'     => ( 'published' === $status ) ? get_permalink( $post_id ) : '',
			) );
		}
		return $out;
	}

	/** Most recent version row from a (already-normalized) version list. */
	protected static function latest( $versions ) {
		return $versions[ count( $versions ) - 1 ];
	}

	/**
	 * Normalize a stored entry to a list-of-versions. Handles the pre-
	 * versioning shape (a single flat assoc row, no 'version' key) by
	 * wrapping it as version 1, so old history keeps working unchanged.
	 */
	protected static function normalize_versions( $entry ) {
		if ( empty( $entry ) ) { return array(); }

		// Already a version list: numeric keys, each element an array with a 'version' key.
		if ( isset( $entry[0] ) && is_array( $entry[0] ) && isset( $entry[0]['version'] ) ) {
			return array_values( $entry );
		}

		// Legacy shape: one flat row per post_id, from before this feature existed.
		if ( isset( $entry['post_id'] ) || isset( $entry['keyword'] ) ) {
			return array( array(
				'version'     => 1,
				'article_id'  => isset( $entry['article_id'] ) ? (int) $entry['article_id'] : 0,
				'keyword'     => isset( $entry['keyword'] ) ? (string) $entry['keyword'] : '',
				'title'       => isset( $entry['title'] ) ? (string) $entry['title'] : '',
				'pillar'      => isset( $entry['pillar'] ) ? (string) $entry['pillar'] : '',
				'word_target' => isset( $entry['word_target'] ) ? (int) $entry['word_target'] : 0,
				'gen_seconds' => isset( $entry['gen_seconds'] ) ? (float) $entry['gen_seconds'] : null,
				'run_id'      => isset( $entry['run_id'] ) ? (string) $entry['run_id'] : '',
				'generated_at'=> isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : time(),
			) );
		}

		return array();
	}

	/**
	 * Compute the current real status of an article from its post.
	 */
	public static function live_status( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return 'deleted'; }
		switch ( $post->post_status ) {
			case 'publish': return 'published';
			case 'draft':   return 'draft';
			case 'pending': return 'pending';
			case 'future':  return 'future';
			case 'private': return 'private';
			case 'trash':   return 'trashed';
			default:        return 'other';
		}
	}

	/**
	 * Optional: drop the record for a post (not used by the UI, which is
	 * read-only, but available for housekeeping/uninstall).
	 */
	public static function forget( $post_id ) {
		$rows = self::all();
		unset( $rows[ (int) $post_id ] );
		update_option( self::OPT, $rows, 'no' );
	}
}
