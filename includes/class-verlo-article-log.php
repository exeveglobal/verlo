<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable record of every article Verlo has generated. This is intentionally
 * SEPARATE from briefs and the topical map: rebuilding the map or pruning
 * briefs must never erase the record of work already produced. The actual
 * article lives as a normal WordPress post; this store keeps the metadata and
 * a stable link to it, so there is always a single place to see everything
 * Verlo has written — even after a map rebuild.
 *
 * Live status (draft/published/trashed/deleted) is computed from the real post
 * at display time, so the history can never show a stale or false state.
 */
class Verlo_Article_Log {

	const OPT      = 'verlo_article_log';
	const MAX_ROWS = 500; // generous; one row per generated article

	/**
	 * Record (or update) a generated article. Keyed by post_id so regenerating
	 * the same draft updates its row rather than duplicating it.
	 */
	public static function record( $data ) {
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		if ( ! $post_id ) { return; }

		$rows = self::all();

		$row = array(
			'post_id'     => $post_id,
			'article_id'  => isset( $data['article_id'] ) ? (int) $data['article_id'] : 0,
			'keyword'     => isset( $data['keyword'] ) ? (string) $data['keyword'] : '',
			'title'       => isset( $data['title'] ) ? (string) $data['title'] : '',
			'pillar'      => isset( $data['pillar'] ) ? (string) $data['pillar'] : '',
			'word_target' => isset( $data['word_target'] ) ? (int) $data['word_target'] : 0,
			'gen_seconds' => isset( $data['gen_seconds'] ) ? (float) $data['gen_seconds'] : null,
			'run_id'      => isset( $data['run_id'] ) ? (string) $data['run_id'] : '',
			'created_at'  => isset( $rows[ $post_id ]['created_at'] ) ? (int) $rows[ $post_id ]['created_at'] : time(),
			'updated_at'  => time(),
		);

		$rows[ $post_id ] = $row;

		// Cap (keep most recently updated) — defensive; normally well under MAX.
		if ( count( $rows ) > self::MAX_ROWS ) {
			uasort( $rows, function ( $a, $b ) {
				return ( (int) $b['updated_at'] ) <=> ( (int) $a['updated_at'] );
			} );
			$rows = array_slice( $rows, 0, self::MAX_ROWS, true );
		}

		update_option( self::OPT, $rows, 'no' );
	}

	public static function all() {
		$rows = get_option( self::OPT, array() );
		return is_array( $rows ) ? $rows : array();
	}

	public static function count() {
		return count( self::all() );
	}

	/**
	 * Rows newest-first, each enriched with a LIVE status computed from the
	 * actual post (so the history never lies about what currently exists).
	 * Status: 'published' | 'draft' | 'pending' | 'future' | 'private' |
	 *         'trashed' | 'deleted' | 'other'.
	 */
	public static function recent( $limit = 200 ) {
		$rows = self::all();
		usort( $rows, function ( $a, $b ) {
			return ( (int) $b['updated_at'] ) <=> ( (int) $a['updated_at'] );
		} );
		$rows = array_slice( $rows, 0, (int) $limit );

		foreach ( $rows as &$r ) {
			$r['status']    = self::live_status( (int) $r['post_id'] );
			$r['edit_url']  = $r['status'] === 'deleted' ? '' : get_edit_post_link( (int) $r['post_id'], 'raw' );
			$r['view_url']  = ( 'published' === $r['status'] ) ? get_permalink( (int) $r['post_id'] ) : '';
		}
		unset( $r );
		return $rows;
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
