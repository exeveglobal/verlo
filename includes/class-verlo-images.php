<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stock image integration via Pexels, searched server-side through the Verlo
 * SaaS (a single shared account-level key, not a per-site key) — niche-agnostic:
 * it searches by the article's own keyword, so it works on any site. Images
 * are sideloaded into the Media Library, the first becomes the featured
 * image, and any extras are returned as ready-to-insert Gutenberg image blocks.
 *
 * Image work never breaks article generation: any failure is caught and the
 * draft is saved without images.
 */
class Verlo_Images {

	/**
	 * Images require an active connection (the search call goes through the
	 * SaaS) — there's no per-site credential to check anymore.
	 */
	public static function is_configured() {
		return Verlo_Auth::is_connected();
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
	 * Search for stock photos via the SaaS (POST /v1/images/search — a fast
	 * passthrough to Pexels using a shared server-side key, not a queued job).
	 * Returns a list of normalised photo arrays: [ 'url' => download src,
	 * 'url_hi' => higher-res src, 'alt' => alt text,
	 * 'credit' => 'Photo by X on Pexels', 'credit_url' => photographer url ].
	 * Empty array on any failure — image work must never break generation.
	 */
	public static function search( $query, $count = 1 ) {
		if ( ! self::is_configured() || '' === trim( (string) $query ) ) { return array(); }

		$photos = Verlo_SaaS_Client::search_images( $query, max( 1, (int) $count ) );
		if ( is_wp_error( $photos ) ) { return array(); }

		// The SaaS already returns the exact shape below — just sanitize/coerce
		// types defensively rather than trusting a remote response blindly.
		$out = array();
		foreach ( $photos as $p ) {
			if ( empty( $p['url'] ) ) { continue; }
			$out[] = array(
				'url'        => (string) $p['url'],
				'url_hi'     => isset( $p['url_hi'] ) ? (string) $p['url_hi'] : (string) $p['url'],
				'alt'        => isset( $p['alt'] ) ? (string) $p['alt'] : '',
				'credit'     => isset( $p['credit'] ) ? (string) $p['credit'] : 'Photo from Pexels',
				'credit_url' => isset( $p['credit_url'] ) ? (string) $p['credit_url'] : 'https://www.pexels.com',
			);
		}
		return $out;
	}

	/**
	 * Restricts sideload() to the known Pexels CDN — download_url() (unlike
	 * wp_safe_remote_get()) does not block private/reserved IPs, so without
	 * this check a compromised or MITM'd SaaS response could point the site's
	 * own server at an internal URL. $image_url is always SaaS-supplied today,
	 * not directly user-controlled, so this is defense-in-depth for that trust
	 * boundary rather than a fix for a reachable bug.
	 */
	private static function is_allowed_image_host( $image_url ) {
		$parts = wp_parse_url( (string) $image_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return false; }
		if ( 'https' !== $parts['scheme'] ) { return false; }
		$host = strtolower( $parts['host'] );
		return 'pexels.com' === $host || substr( $host, -( strlen( '.pexels.com' ) ) ) === '.pexels.com';
	}

	/**
	 * Sideload a remote image into the Media Library, attached to $post_id.
	 * Returns [ 'id' => attachment_id, 'url' => attachment_url ] or null.
	 */
	public static function sideload( $image_url, $post_id, $alt, $credit, $credit_url ) {
		if ( ! self::is_allowed_image_host( $image_url ) ) { return null; }

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
			$alt = self::build_alt_text( $featured['alt'] ?? '', $keyword, 0 );
			$att = self::sideload( $featured['url_hi'], $post_id, $alt, $featured['credit'], $featured['credit_url'] );
			if ( $att ) { set_post_thumbnail( $post_id, $att['id'] ); }
		}

		// Remaining photos -> in-body image blocks (never more than $target_inline).
		$blocks = array();
		$i      = 0;
		foreach ( $photos as $photo ) {
			if ( $i >= $target_inline ) { break; }
			$alt = self::build_alt_text( $photo['alt'] ?? '', $keyword, $i );
			$att = self::sideload( $photo['url'], $post_id, $alt, $photo['credit'], $photo['credit_url'] );
			if ( $att ) {
				$blocks[] = self::image_block( $att['id'], $att['url'], $alt, $photo['credit'] );
				$i++;
			}
		}

		return empty( $blocks ) ? $content : self::insert_inline( $content, $blocks );
	}

	/**
	 * Build real, descriptive alt text for one image. Pexels' own API returns
	 * a short human-written description per photo (e.g. "A red bicycle
	 * leaning against a brick wall") which search() already fetches into
	 * $photo['alt'] — previously discarded in favour of the bare focus
	 * keyword repeated across every image in the article, which was
	 * identical (or near-identical) alt text site-wide and described nothing
	 * about what was actually in each photo.
	 *
	 * Falls back to the previous keyword-based behaviour only when the
	 * source photo has no description at all (rare, but Pexels doesn't
	 * guarantee one for every photo).
	 */
	protected static function build_alt_text( $photo_alt, $keyword, $index ) {
		$photo_alt = trim( (string) $photo_alt );
		if ( '' === $photo_alt ) {
			return $keyword . ( $index > 0 ? ' ' . ( $index + 1 ) : '' );
		}
		// Keep the real description; only add the keyword if it isn't
		// naturally present already, so this never reads as keyword-stuffed.
		if ( false === stripos( $photo_alt, $keyword ) ) {
			return rtrim( $photo_alt, '.' ) . ', ' . $keyword;
		}
		return $photo_alt;
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
		//
		// NOTE: the previous version picked slots where ($i % $step === 0). For
		// n=1 that made $step = $slot_count, which only ever matches on the very
		// last iteration - i.e. every single-image article landed on the LAST
		// heading, no matter how long the article was. Fixed by spacing slots
		// across the 1..n+1 fractions of the article instead of using modulo.
		$n          = min( $n, count( $slots ) );
		$slot_count = count( $slots );

		$chosen = array();
		for ( $k = 1; $k <= $n; $k++ ) {
			$idx = (int) round( ( $k / ( $n + 1 ) ) * $slot_count ) - 1;
			$idx = max( 0, min( $slot_count - 1, $idx ) );
			$chosen[ $idx ] = $slots[ $idx ]; // keyed by index -> de-dupes automatically
		}
		// Safety net: if de-duping left us short (only possible when n is close
		// to slot_count), fill remaining unused slots left-to-right.
		if ( count( $chosen ) < $n ) {
			foreach ( $slots as $idx => $offset ) {
				if ( count( $chosen ) >= $n ) { break; }
				if ( ! isset( $chosen[ $idx ] ) ) { $chosen[ $idx ] = $offset; }
			}
		}
		ksort( $chosen );
		$chosen = array_values( $chosen );

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
