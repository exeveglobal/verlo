<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds FAQPage JSON-LD from an article's ACTUAL rendered FAQ section, not
 * from the brief's original question list (which can drift from what the
 * model actually wrote). Structured data has to match visible page content;
 * building it from anything else risks a mismatch between what's marked up
 * and what a reader (or Google) actually sees on the page.
 */
class Verlo_Faq_Schema {

	/**
	 * Extract Q&A pairs from the FAQ section of clean article HTML (before
	 * to_blocks() runs, so plain <h2>/<h3>/<p> tags rather than Gutenberg
	 * block comments) and return a <script type="application/ld+json"> tag,
	 * or '' if no FAQ section was found.
	 */
	public static function build( $html ) {
		$pairs = self::extract_pairs( $html );
		if ( empty( $pairs ) ) { return ''; }

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				function ( $p ) {
					return array(
						'@type'          => 'Question',
						'name'           => $p['question'],
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $p['answer'],
						),
					);
				},
				$pairs
			),
		);

		$json = wp_json_encode( $schema );
		if ( ! $json ) { return ''; }

		// Defensive: a literal "</script" inside answer text (rare, but the
		// model doesn't know this is being embedded in one) would otherwise
		// close the tag early and break the page.
		$json = str_replace( '</script', '<\/script', $json );

		return "\n\n<script type=\"application/ld+json\">{$json}</script>\n";
	}

	/**
	 * Walk the FAQ H2's siblings, pairing each H3 (question) with the plain
	 * text of the paragraph(s) that follow it (answer), stopping at the next
	 * H2 or the end of the document. Defensive throughout: a missing or
	 * malformed FAQ section (shouldn't happen given the prompt always
	 * includes one, but never assume) just yields an empty array.
	 */
	protected static function extract_pairs( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) { return array(); }

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$bodies = $dom->getElementsByTagName( 'body' );
		$root   = $bodies->length ? $bodies->item( 0 ) : null;
		if ( ! $root ) { return array(); }

		// Find the FAQ heading among the top-level nodes.
		$nodes = iterator_to_array( $root->childNodes );
		$faq_index = null;
		foreach ( $nodes as $i => $node ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType || 'h2' !== strtolower( $node->nodeName ) ) { continue; }
			$text = strtolower( trim( $node->textContent ) );
			if ( 'faq' === $text || false !== strpos( $text, 'frequently asked questions' ) ) {
				$faq_index = $i;
				break;
			}
		}
		if ( null === $faq_index ) { return array(); }

		$pairs        = array();
		$question     = null;
		$answer_parts = array();

		$flush = function () use ( &$pairs, &$question, &$answer_parts ) {
			if ( null === $question ) { return; }
			$answer = trim( implode( ' ', $answer_parts ) );
			if ( '' !== $answer ) {
				$pairs[] = array( 'question' => $question, 'answer' => $answer );
			}
		};

		for ( $i = $faq_index + 1; $i < count( $nodes ); $i++ ) {
			$node = $nodes[ $i ];
			if ( XML_ELEMENT_NODE !== $node->nodeType ) { continue; }
			$tag = strtolower( $node->nodeName );

			if ( 'h2' === $tag ) { break; } // next section, FAQ block is over

			if ( 'h3' === $tag ) {
				$flush();
				$question     = trim( $node->textContent );
				$answer_parts = array();
				if ( '' === $question ) { $question = null; }
				continue;
			}

			if ( null !== $question && in_array( $tag, array( 'p', 'ul', 'ol' ), true ) ) {
				$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
				if ( '' !== $text ) { $answer_parts[] = $text; }
			}
		}
		$flush();

		return $pairs;
	}
}
