<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Turns clean article HTML into native Gutenberg block markup, so a published article opens as real blocks in
 * the editor with no "convert to blocks" step. Carried over unchanged from Verlo 1, where it was proven on live
 * sites: tables are Custom HTML blocks on purpose (core/table re-validates its markup and trips "attempt
 * recovery" on AI-written cells), and pull quotes use the core quote block.
 */
class Verlo_Blocks {

	/**
	 * Convert clean article HTML into native Gutenberg block markup. This makes
	 * the draft load as real blocks (no "convert to blocks" step) and removes the
	 * stray whitespace/line-break artifacts that broke lists in the editor.
	 * Falls back to whitespace-normalised HTML if DOMDocument is unavailable.
	 */
	public static function to_blocks( $html ) {
		$html = trim( $html );
		if ( '' === $html ) { return ''; }

		if ( ! class_exists( 'DOMDocument' ) ) {
			// Fallback: at least collapse inter-tag whitespace so lists are tight.
			$html = preg_replace( '/>\s+</', '><', $html );
			return $html;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// Let libxml wrap the fragment in html>body; iterate the body's children.
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$bodies = $dom->getElementsByTagName( 'body' );
		$root   = $bodies->length ? $bodies->item( 0 ) : null;
		if ( ! $root ) { return preg_replace( '/>\s+</', '><', $html ); }

		$out = '';
		foreach ( $root->childNodes as $node ) {
			$out .= self::node_to_block( $dom, $node );
		}
		return trim( $out );
	}

	protected static function node_to_block( $dom, $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return ''; // ignore stray top-level text/whitespace
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) { return ''; }

		$tag   = strtolower( $node->nodeName );
		$inner = self::inner_html( $dom, $node );
		$inner = trim( preg_replace( '/\s+/', ' ', $inner ) );

		switch ( $tag ) {
			case 'p':
				if ( '' === $inner ) { return ''; }
				return "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->\n\n";
			case 'h2':
			case 'h3':
			case 'h4':
				$level = (int) substr( $tag, 1 );
				return "<!-- wp:heading {\"level\":{$level}} -->\n<{$tag}>{$inner}</{$tag}>\n<!-- /wp:heading -->\n\n";
			case 'ul':
			case 'ol':
				return self::list_to_block( $dom, $node, 'ol' === $tag );
			case 'table':
				return self::table_to_block( $dom, $node );
			case 'blockquote':
				return self::blockquote_to_block( $dom, $node );
			default:
				// Wrap anything else as a paragraph if it has content.
				return '' !== $inner ? "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->\n\n" : '';
		}
	}

	protected static function list_to_block( $dom, $node, $ordered ) {
		$items = '';
		foreach ( $node->childNodes as $li ) {
			if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
				$text = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $li ) ) );
				if ( '' !== $text ) {
					$items .= "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";
				}
			}
		}
		if ( '' === $items ) { return ''; }
		$tag  = $ordered ? 'ol' : 'ul';
		$attr = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list{$attr} -->\n<{$tag}>\n{$items}</{$tag}>\n<!-- /wp:list -->\n\n";
	}

	/**
	 * Wrap a <table> element as a Custom HTML block, not Gutenberg's native
	 * wp:table - deliberately, not an oversight. wp:table isn't a raw-HTML
	 * passthrough: core/table's head/body/foot attributes are derived FROM
	 * the markup via query-selector sourcing (its block.json), and Gutenberg
	 * re-validates by re-serializing those attributes back to HTML on every
	 * load. Any subtle mismatch from AI-generated markup (whitespace, cell
	 * structure) trips "Block contains unexpected or invalid content, Attempt
	 * recovery" even though the HTML itself is perfectly valid and renders
	 * fine - confirmed live 2026-08-26 on a real generated article (this
	 * function previously used wp:table specifically to fix an EARLIER
	 * "attempt recovery" cause - the default case's <p>-wrapping - without
	 * knowing wp:table had its own separate one). wp:html has no attributes
	 * to reconstruct, so there is nothing left to mismatch. Same
	 * wp-block-table wrapper/classes so themes styling that class still
	 * apply; the only real cost is the table isn't cell-click-editable in
	 * the block editor, which doesn't matter for AI-generated content that's
	 * reviewed as a whole before publishing anyway.
	 */
	protected static function table_to_block( $dom, $node ) {
		$inner = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $node ) ) );
		if ( '' === $inner ) { return ''; }
		return "<!-- wp:html -->\n"
			. "<figure class=\"wp-block-table\"><table class=\"wp-block-table\">{$inner}</table></figure>\n"
			. "<!-- /wp:html -->\n\n";
	}

	/**
	 * Wrap a <blockquote> in Gutenberg's quote block markup - the sparing,
	 * standalone-insight "pull quote" the article prompt is now allowed to
	 * use (see verlo-saas's buildArticleSystemPrompt() PULL QUOTE rule),
	 * kept distinct from a plain paragraph so it actually renders with the
	 * theme's real quote styling instead of silently degrading into
	 * unstyled text via the default case below.
	 */
	protected static function blockquote_to_block( $dom, $node ) {
		$inner = trim( preg_replace( '/\s+/', ' ', self::inner_html( $dom, $node ) ) );
		if ( '' === $inner ) { return ''; }
		// The AI is instructed to wrap the quote text in its own <p>, but
		// tolerate bare text too (Gutenberg's quote block still expects a
		// <p> child either way).
		if ( ! preg_match( '/<p[\s>]/i', $inner ) ) {
			$inner = "<p>{$inner}</p>";
		}
		return "<!-- wp:quote -->\n"
			. "<blockquote class=\"wp-block-quote\">{$inner}</blockquote>\n"
			. "<!-- /wp:quote -->\n\n";
	}

	protected static function inner_html( $dom, $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Trim text to a maximum length at a word boundary (no mid-word cut, no
	 * trailing punctuation), so SEO title/description sit in Yoast's green range.
	 */
	public static function clamp_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) <= $max ) { return $text; }
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > $max * 0.6 ) { $cut = substr( $cut, 0, $sp ); }
		return rtrim( $cut, " ,.;:-" );
	}
}
