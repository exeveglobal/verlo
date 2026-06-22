<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stock image integration via the free Pexels API. Niche-agnostic: it searches
 * by the article's own keyword, so it works on any site. Images are sideloaded
 * into the Media Library, the first becomes the featured image, and any extras
 * are returned as ready-to-insert Gutenberg image blocks.
 *
 * Image work never breaks article generation: any failure is caught and the
 * draft is saved without images.
 */
class Verlo_Images {

	const ENDPOINT = 'https://api.pexels.com/v1/search';

	public static function api_key() {
		$s = verlo_get_settings();
		return isset( $s['pexels_api_key'] ) ? trim( (string) $s['pexels_api_key'] ) : '';
	}

	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * The configured MAXIMUM number of in-body images (0-3). The actual number
	 * used per article is decided by apply_to_post() based on article length
	 * and where images can safely go (see heading_slots()).
	 */
	public static function max_inline_images() {
		$s = verlo_get_settings();
		$n = isset( $s['inline_images'] ) ? (int) $s['inline_images'] : 1;
		return max( 0, min( 3, $n ) );
	}

	/**
	 * Search Pexels for a query. Returns a list of normalised photo arrays:
	 * [ 'url' => download src, 'alt' => alt text, 'credit' => 'Photo by X on Pexels',
	 *   'credit_url' => photographer url ]. Empty array on any failure.
	 */
	public static function search( $query, $count = 1 ) {
		$key = self::api_key();
		if ( '' === $key || '' === trim( (string) $query ) ) { return array(); }

		$url = add_query_arg(
			array(
				'query'       => rawurlencode( $query ),
				'per_page'    => max( 1, (int) $count ),
				'orientation' => 'landscape',
			),
			self::ENDPOINT
		);

		$res = wp_remote_get( $url, array(
			'headers' => array( 'Authorization' => $key ),
			'timeout' => 20,
		) );
		if ( is_wp_error( $res ) ) { return array(); }
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return array(); }

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return self::normalise( $body );
	}

	/**
	 * Convert a Pexels API response body into our normalised photo list.
	 * Pure function (no WP/network) so it is unit-testable.
	 */
	public static function normalise( $body ) {
		$out = array();
		if ( empty( $body['photos'] ) || ! is_array( $body['photos'] ) ) { return $out; }
		foreach ( $body['photos'] as $p ) {
			// Standard source for in-body images (~940px wide).
			$src = '';
			if ( ! empty( $p['src']['large'] ) ) {
				$src = $p['src']['large'];
			} elseif ( ! empty( $p['src']['original'] ) ) {
				$src = $p['src']['original'];
			} elseif ( ! empty( $p['src']['medium'] ) ) {
				$src = $p['src']['medium'];
			}
			if ( '' === $src ) { continue; }

			// Higher-res source for the featured image / OG sharing. We prefer
			// Pexels "large" (~940px wide) which comfortably covers the 1200x630
			// OG target after WordPress generates its sizes, and downloads far
			// faster than "large2x" (~1880px). Fall back to 2x/original only if
			// "large" is somehow absent.
			$hi = '';
			if ( ! empty( $p['src']['large'] ) ) {
				$hi = $p['src']['large'];
			} elseif ( ! empty( $p['src']['large2x'] ) ) {
				$hi = $p['src']['large2x'];
			} elseif ( ! empty( $p['src']['original'] ) ) {
				$hi = $p['src']['original'];
			} else {
				$hi = $src;
			}

			$photographer = isset( $p['photographer'] ) ? $p['photographer'] : '';
			$out[] = array(
				'url'        => $src,
				'url_hi'     => $hi,
				'alt'        => isset( $p['alt'] ) ? (string) $p['alt'] : '',
				'credit'     => $photographer ? 'Photo by ' . $photographer . ' on Pexels' : 'Photo from Pexels',
				'credit_url' => isset( $p['photographer_url'] ) ? $p['photographer_url'] : 'https://www.pexels.com',
			);
		}
		return $out;
	}

	/**
	 * Sideload a remote image into the Media Library, attached to $post_id.
	 * Returns [ 'id' => attachment_id, 'url' => attachment_url ] or null.
	 */
	public static function sideload( $image_url, $post_id, $alt, $credit, $credit_url ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $image_url, 30 );
		if ( is_wp_error( $tmp ) ) { return null; }

		$name = sanitize_title( $alt ? $alt : 'image' );
		$file = array(
			'name'     => ( $name ? $name : 'stock-image' ) . '-' . wp_generate_password( 6, false ) . '.jpg',
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload(
			$file,
			(int) $post_id,
			$credit, // attachment description
			array( 'post_excerpt' => $credit ) // caption
		);
		if ( is_wp_error( $attach_id ) ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); }
			return null;
		}

		if ( $alt ) { update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt ); }
		update_post_meta( $attach_id, '_verlo_image_source', 'pexels' );
		update_post_meta( $attach_id, '_verlo_image_credit_url', esc_url_raw( $credit_url ) );

		return array( 'id' => (int) $attach_id, 'url' => wp_get_attachment_url( $attach_id ) );
	}

	/**
	 * Find candidate insertion points for in-body images: the position right
	 * after each top-level "<!-- /wp:heading -->" block, EXCLUDING the FAQ
	 * section and anything after it. If no FAQ heading is found, all heading
	 * breaks are candidates.
	 *
	 * @return int[] byte offsets into $content, in ascending order.
	 */
	public static function heading_slots( $content ) {
		if ( ! preg_match_all(
			'/<!-- wp:heading[^>]*-->\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>\s*<!-- \/wp:heading -->/is',
			$content,
			$m,
			PREG_OFFSET_CAPTURE
		) ) {
			return array();
		}

		// Find the first heading whose text mentions "FAQ" -> everything from
		// its start offset onward is off-limits for image placement.
		$cutoff = PHP_INT_MAX;
		foreach ( $m[1] as $i => $heading_text ) {
			$text = trim( wp_strip_all_tags( $heading_text[0] ) );
			if ( preg_match( '/\bfaq\b/i', $text ) ) {
				$cutoff = $m[0][ $i ][1]; // start offset of this whole heading block
				break;
			}
		}

		$slots = array();
		foreach ( $m[0] as $whole ) {
			$start = $whole[1];
			if ( $start >= $cutoff ) { break; }
			$slots[] = $start + strlen( $whole[0] ); // position right after "<!-- /wp:heading -->"
		}
		return $slots;
	}

	/**
	 * Plain-text word count of block-markup content (tags stripped).
	 */
	public static function word_count_of( $content ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $content ) ) );
		return '' === $text ? 0 : count( explode( ' ', $text ) );
	}

	/**
	 * Add stock images to a post: set the featured image and splice in-body
	 * images into the content. The configured "in-body images" setting is a
	 * MAXIMUM; the actual number used scales down for shorter articles and is
	 * never placed inside the FAQ section. When the setting is >= 1, at least
	 * one in-body image is used (falling back to placement after the intro
	 * paragraph if the article has no usable heading slots).
	 *
	 * @return string the (possibly unchanged) content, with image blocks spliced in.
	 */
	public static function apply_to_post( $post_id, $keyword, $content ) {
		if ( ! self::is_configured() ) { return $content; }

		$max_inline = self::max_inline_images();
		$slots      = self::heading_slots( $content );

		$target_inline = 0;
		if ( $max_inline > 0 ) {
			// Roughly one in-body image per 1000 words, at least 1, capped by
			// the configured maximum and by how many safe slots exist (if no
			// safe heading slots exist, allow exactly 1 via the intro-paragraph
			// fallback in insert_inline()).
			$by_length = max( 1, (int) floor( self::word_count_of( $content ) / 1000 ) );
			$cap       = count( $slots ) > 0 ? count( $slots ) : 1;
			$target_inline = max( 1, min( $max_inline, $by_length, $cap ) );
		}

		$photos = self::search( $keyword, 1 + $target_inline );
		if ( empty( $photos ) ) { return $content; }

		// First photo -> featured image (unless one is already set). Use the
		// higher-res source so the hero and social/OG image look sharp.
		$featured = array_shift( $photos );
		if ( ! get_post_thumbnail_id( $post_id ) ) {
			$att = self::sideload( $featured['url_hi'], $post_id, $keyword, $featured['credit'], $featured['credit_url'] );
			if ( $att ) { set_post_thumbnail( $post_id, $att['id'] ); }
		}

		// Remaining photos -> in-body image blocks (never more than $target_inline).
		$blocks = array();
		$i      = 0;
		foreach ( $photos as $photo ) {
			if ( $i >= $target_inline ) { break; }
			$alt = $keyword . ( $i > 0 ? ' ' . ( $i + 1 ) : '' );
			$att = self::sideload( $photo['url'], $post_id, $alt, $photo['credit'], $photo['credit_url'] );
			if ( $att ) {
				$blocks[] = self::image_block( $att['id'], $att['url'], $alt, $photo['credit'] );
				$i++;
			}
		}

		return empty( $blocks ) ? $content : self::insert_inline( $content, $blocks );
	}

	/**
	 * Build a Gutenberg image block string.
	 */
	public static function image_block( $id, $url, $alt, $caption ) {
		$id  = (int) $id;
		$url = esc_url( $url );
		$alt = esc_attr( $alt );
		$cap = esc_html( $caption );
		return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n"
			. "<figure class=\"wp-block-image size-large\"><img src=\"{$url}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/>"
			. "<figcaption class=\"wp-element-caption\">{$cap}</figcaption></figure>\n"
			. "<!-- /wp:image -->";
	}

	/**
	 * Insert in-body image blocks into block-markup content at FAQ-aware
	 * heading slots (see heading_slots()), evenly distributed. If there are
	 * no usable slots (e.g. a very short article whose only heading is the
	 * FAQ), falls back to placing a single image right after the intro
	 * paragraph; if even that isn't found, appends at the end as a last
	 * resort. Pure string function so it is testable without WP.
	 */
	public static function insert_inline( $content, $blocks ) {
		if ( empty( $blocks ) ) { return $content; }

		$slots = self::heading_slots( $content );
		$n     = count( $blocks );

		if ( empty( $slots ) ) {
			// Fallback: place the first image after the intro paragraph (still
			// within the content area, never inside the FAQ). Any extra blocks
			// (rare: target_inline is capped to 1 in this scenario) go after it.
			if ( preg_match( '/<!-- \/wp:paragraph -->/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos = $m[0][1] + strlen( $m[0][0] );
				return substr( $content, 0, $pos ) . "\n\n" . implode( "\n\n", $blocks ) . "\n\n" . substr( $content, $pos );
			}
			return $content . "\n\n" . implode( "\n\n", $blocks );
		}

		// Cap to the number of available slots and pick evenly-spaced ones.
		$n          = min( $n, count( $slots ) );
		$slot_count = count( $slots );
		$step       = max( 1, (int) floor( $slot_count / $n ) );

		$chosen = array();
		for ( $i = 1; $i <= $slot_count && count( $chosen ) < $n; $i++ ) {
			if ( 0 === ( $i % $step ) ) { $chosen[] = $slots[ $i - 1 ]; }
		}
		// Safety net: if the step math left us short (shouldn't normally happen).
		while ( count( $chosen ) < $n ) { $chosen[] = $slots[ $slot_count - 1 ]; }
		$chosen = array_values( array_unique( $chosen ) );
		sort( $chosen );

		$out  = '';
		$last = 0;
		foreach ( $chosen as $i => $offset ) {
			$out .= substr( $content, $last, $offset - $last );
			$out .= "\n\n" . $blocks[ $i ] . "\n\n";
			$last = $offset;
		}
		$out .= substr( $content, $last );
		return $out;
	}
}
