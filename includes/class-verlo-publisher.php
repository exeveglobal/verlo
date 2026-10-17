<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates or updates the WordPress post for one article. The same article always maps to the same post (tracked by
 * the _verlo_article_id meta), so an update changes that post and never creates a second one, and a repeat of the
 * same delivery (same idempotency key) changes nothing at all.
 */
class Verlo_Publisher {

	/**
	 * @param array  $payload          The delivery body from Verlo (already signature-checked by the caller).
	 * @param string $idempotency_key  Stable across retries of the same version and mode.
	 * @return array|WP_Error          array( 'external_id' => string, 'url' => string, 'status' => string )
	 */
	public static function publish( $payload, $idempotency_key = '' ) {
		$a    = isset( $payload['article'] ) && is_array( $payload['article'] ) ? $payload['article'] : null;
		$dest = isset( $payload['destination'] ) && is_array( $payload['destination'] ) ? $payload['destination'] : array();
		if ( ! $a || empty( $a['id'] ) || empty( $a['title'] ) || ! isset( $a['content_html'] ) ) {
			return new WP_Error( 'verlo_bad_payload', 'The article was incomplete.' );
		}
		$article_id = sanitize_text_field( (string) $a['id'] );
		$mode       = isset( $dest['post_mode'] ) && 'publish' === $dest['post_mode'] ? 'publish' : 'draft';

		$post_id = self::find_post( $article_id, isset( $a['external_id'] ) ? $a['external_id'] : '' );

		// The same delivery again (a retry after a lost answer): report the existing post, change nothing.
		if ( $post_id && '' !== $idempotency_key && get_post_meta( $post_id, '_verlo_last_key', true ) === $idempotency_key ) {
			return self::result( $post_id );
		}

		$title   = sanitize_text_field( (string) $a['title'] );
		$excerpt = sanitize_textarea_field( isset( $a['excerpt'] ) ? (string) $a['excerpt'] : '' );
		$content = self::build_content( (string) $a['content_html'] );

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_type'    => 'post',
		);

		if ( $post_id ) {
			$postarr['ID'] = $post_id; // an update keeps the post's status and address
			$id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_status'] = $mode;
			$postarr['post_author'] = self::author_id();
			$slug = isset( $a['slug'] ) ? sanitize_title( (string) $a['slug'] ) : '';
			if ( '' !== $slug ) { $postarr['post_name'] = $slug; }
			$id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $id ) ) { return $id; }
		$id = (int) $id;
		if ( ! $id ) { return new WP_Error( 'verlo_save_failed', 'WordPress could not save the post.' ); }

		update_post_meta( $id, '_verlo_article_id', $article_id );
		update_post_meta( $id, '_verlo_version', isset( $a['version'] ) ? (int) $a['version'] : 0 );
		if ( '' !== $idempotency_key ) { update_post_meta( $id, '_verlo_last_key', $idempotency_key ); }

		self::apply_seo_meta( $id, $title, isset( $a['meta_description'] ) ? (string) $a['meta_description'] : '', isset( $a['focus_keyword'] ) ? (string) $a['focus_keyword'] : '' );

		if ( ! empty( $a['image'] ) && is_array( $a['image'] ) ) {
			self::set_featured_image( $id, $a['image'], $title );
		}

		if ( ! empty( $dest['config']['category'] ) && is_string( $dest['config']['category'] ) ) {
			self::set_category( $id, $dest['config']['category'] );
		}

		return self::result( $id );
	}

	/**
	 * Adds the hero image to the media library and makes it the featured image, with its alt text and credit. Only
	 * Pexels photo addresses are accepted, over https, so a delivery cannot make this site fetch an arbitrary URL.
	 * A failure here never stops the article from being published; the post just has no image.
	 */
	protected static function set_featured_image( $post_id, $image, $title ) {
		$url = isset( $image['url'] ) ? (string) $image['url'] : '';
		if ( ! preg_match( '#^https://images\.pexels\.com/photos/\d+/[A-Za-z0-9._%/\-]+(\?[A-Za-z0-9=&._%\-]*)?$#', $url ) ) { return; }
		$alt    = sanitize_text_field( isset( $image['alt'] ) ? (string) $image['alt'] : $title );
		$credit = sanitize_text_field( isset( $image['credit'] ) ? (string) $image['credit'] : 'Photo from Pexels' );

		// Same image already attached to this post: nothing to do (updates must not pile up copies).
		if ( get_post_meta( $post_id, '_verlo_image_url', true ) === $url && has_post_thumbnail( $post_id ) ) { return; }

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) { return; }
		$name = sanitize_file_name( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $name || ! preg_match( '/\.(jpe?g|png|webp)$/i', $name ) ) { $name = 'verlo-' . $post_id . '.jpeg'; }
		$check = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) { @unlink( $tmp ); return; }

		$att = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $alt );
		if ( is_wp_error( $att ) ) { @unlink( $tmp ); return; }
		update_post_meta( $att, '_wp_attachment_image_alt', $alt );
		wp_update_post( array( 'ID' => $att, 'post_excerpt' => $credit ) ); // shown as the caption
		set_post_thumbnail( $post_id, $att );
		update_post_meta( $post_id, '_verlo_image_url', $url );
	}

	protected static function result( $post_id ) {
		return array(
			'external_id' => (string) $post_id,
			'url'         => get_permalink( $post_id ),
			'status'      => get_post_status( $post_id ),
		);
	}

	protected static function find_post( $article_id, $external_id ) {
		$ids = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => '_verlo_article_id',
			'meta_value'       => $article_id,
			'suppress_filters' => true,
		) );
		if ( $ids ) { return (int) $ids[0]; }
		// Fall back to the id we gave Verlo before, but only if it really is one of ours (and not trashed).
		if ( $external_id && ctype_digit( (string) $external_id ) ) {
			$p = get_post( (int) $external_id );
			if ( $p && 'trash' !== $p->post_status && get_post_meta( $p->ID, '_verlo_article_id', true ) ) { return (int) $p->ID; }
		}
		return 0;
	}

	/** Blocks for the editor, plus the FAQ structured data as the last block. Content is cleaned even though Verlo already did. */
	protected static function build_content( $html ) {
		$clean  = wp_kses_post( $html );
		$blocks = Verlo_Blocks::to_blocks( $clean );
		$schema = Verlo_Faq_Schema::build( $clean );
		if ( '' !== $schema ) {
			$blocks .= "\n\n<!-- wp:html -->" . $schema . "<!-- /wp:html -->";
		}
		return $blocks;
	}

	protected static function author_id() {
		$users = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
		return $users ? (int) $users[0] : 1;
	}

	protected static function set_category( $post_id, $name ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) { return; }
		$term = term_exists( $name, 'category' );
		if ( ! $term ) { $term = wp_insert_term( $name, 'category' ); }
		if ( ! is_wp_error( $term ) ) {
			wp_set_post_categories( $post_id, array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ) );
		}
	}

	/** SEO title, description and focus keyword for whichever SEO plugin is active (detected, not assumed). */
	protected static function apply_seo_meta( $post_id, $title, $meta_desc, $keyword ) {
		$seo_title = Verlo_Blocks::clamp_text( $title, 60 );
		$meta_desc = Verlo_Blocks::clamp_text( $meta_desc, 155 );
		$keyword   = sanitize_text_field( $keyword );

		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $seo_title );
			update_post_meta( $post_id, 'rank_math_description', $meta_desc );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			update_post_meta( $post_id, '_seopress_titles_title', $seo_title );
			update_post_meta( $post_id, '_seopress_titles_desc', $meta_desc );
			update_post_meta( $post_id, '_seopress_analysis_target_kw', $keyword );
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || class_exists( 'The_SEO_Framework\Load' ) ) {
			update_post_meta( $post_id, '_genesis_title', $seo_title );
			update_post_meta( $post_id, '_genesis_description', $meta_desc );
		}
	}
}
