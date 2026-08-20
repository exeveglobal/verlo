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
 *
 * The full post_content per version lives separately, in
 * Verlo_Install::article_versions_table() — see that class's docblock for
 * why (this option stores metadata only; full HTML per version would bloat
 * an autoloaded wp_options row). get_version_content()/restore() below read
 * and write that table; every other method here only ever touches metadata.
 */
class Verlo_Article_Log {

	const OPT           = 'verlo_article_log';
	const MAX_ROWS       = 500; // generous; one entry per distinct generated article (post)
	const MAX_VERSIONS   = 20;  // per post — bounds storage growth from repeated regenerates

	/**
	 * Record a new generation. Keyed by post_id; each call APPENDS a version
	 * rather than replacing the post's history, so every past generation of
	 * the same draft stays visible. Pass 'content' (the post_content just
	 * written) to also snapshot it for later diff/restore — optional so
	 * existing callers that don't have it handy still work, but the
	 * generator and restore() below always pass it.
	 */
	public static function record( $data ) {
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		if ( ! $post_id ) { return; }

		$rows     = self::all();
		$existing = self::normalize_versions( isset( $rows[ $post_id ] ) ? $rows[ $post_id ] : array() );

		$version_num = count( $existing ) + 1;
		$version     = array(
			'version'       => $version_num,
			'article_id'    => isset( $data['article_id'] ) ? (int) $data['article_id'] : 0,
			'keyword'       => isset( $data['keyword'] ) ? (string) $data['keyword'] : '',
			'title'         => isset( $data['title'] ) ? (string) $data['title'] : '',
			'pillar'        => isset( $data['pillar'] ) ? (string) $data['pillar'] : '',
			'word_target'   => isset( $data['word_target'] ) ? (int) $data['word_target'] : 0,
			'gen_seconds'   => isset( $data['gen_seconds'] ) ? (float) $data['gen_seconds'] : null,
			'run_id'        => isset( $data['run_id'] ) ? (string) $data['run_id'] : '',
			// Set only when this version was produced by restore(), not a
			// fresh generation — the history UI uses this to label it.
			'restored_from' => isset( $data['restored_from'] ) ? (int) $data['restored_from'] : 0,
			'generated_at'  => time(),
		);

		$existing[] = $version;
		$dropped    = array();
		if ( count( $existing ) > self::MAX_VERSIONS ) {
			$dropped  = array_slice( $existing, 0, count( $existing ) - self::MAX_VERSIONS );
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

		if ( isset( $data['content'] ) ) {
			self::save_version_content( $post_id, $version_num, (string) $data['content'] );
		}
		// Keep the content table in lockstep with whatever the metadata trim
		// above just dropped, so it can never grow past what's actually
		// still referenced from the option.
		foreach ( $dropped as $old ) {
			self::delete_version_content( $post_id, (int) $old['version'] );
		}
	}

	/** Insert/replace one version's full post_content snapshot. */
	protected static function save_version_content( $post_id, $version, $content ) {
		global $wpdb;
		$wpdb->replace(
			Verlo_Install::article_versions_table(),
			array(
				'post_id' => (int) $post_id,
				'version' => (int) $version,
				'content' => $content,
				'saved_at'=> current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	protected static function delete_version_content( $post_id, $version ) {
		global $wpdb;
		$wpdb->delete(
			Verlo_Install::article_versions_table(),
			array( 'post_id' => (int) $post_id, 'version' => (int) $version ),
			array( '%d', '%d' )
		);
	}

	/**
	 * The stored post_content for one past version, or null if none was
	 * ever saved for it (e.g. a version recorded before this feature
	 * shipped, or one already pruned past MAX_VERSIONS).
	 */
	public static function get_version_content( $post_id, $version ) {
		global $wpdb;
		$table = Verlo_Install::article_versions_table();
		$row   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$table} WHERE post_id = %d AND version = %d",
				(int) $post_id,
				(int) $version
			)
		);
		return null === $row ? null : (string) $row;
	}

	/**
	 * Sets the live post's content back to a past version, and records that
	 * as a NEW version rather than silently overwriting — so restoring
	 * never erases what was live immediately before the restore either.
	 * Returns true on success, or a WP_Error if the version's content was
	 * never stored (too old / already pruned) or the post no longer exists.
	 */
	public static function restore( $post_id, $version ) {
		$post_id = (int) $post_id;
		$content = self::get_version_content( $post_id, $version );
		if ( null === $content ) {
			return new WP_Error( 'verlo_no_version_content', 'That version has no saved content to restore (it may predate this feature, or have been pruned).' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'verlo_post_missing', 'That article no longer exists.' );
		}

		$updated = wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ), true );
		if ( is_wp_error( $updated ) ) { return $updated; }

		// Pull the restored version's own metadata forward onto the new
		// entry, so the history row still shows a sensible title/keyword
		// rather than blanks.
		$rows     = self::all();
		$existing = self::normalize_versions( isset( $rows[ $post_id ] ) ? $rows[ $post_id ] : array() );
		$source   = null;
		foreach ( $existing as $v ) {
			if ( (int) $v['version'] === (int) $version ) { $source = $v; break; }
		}

		self::record( array(
			'post_id'       => $post_id,
			'article_id'    => $source ? $source['article_id'] : 0,
			'keyword'       => $source ? $source['keyword'] : '',
			'title'         => $source ? $source['title'] : get_the_title( $post_id ),
			'pillar'        => $source ? $source['pillar'] : '',
			'word_target'   => $source ? $source['word_target'] : 0,
			'content'       => $content,
			'restored_from' => (int) $version,
		) );

		return true;
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
	 * Drop the record for a post, metadata and stored content both —
	 * available for housekeeping/uninstall (not exposed as a per-post UI
	 * action; the history views are otherwise read-only aside from
	 * restore()).
	 */
	public static function forget( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;

		$rows = self::all();
		unset( $rows[ $post_id ] );
		update_option( self::OPT, $rows, 'no' );

		$wpdb->delete( Verlo_Install::article_versions_table(), array( 'post_id' => $post_id ), array( '%d' ) );
	}
}
