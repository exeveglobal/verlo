<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared cleanup applied to ALL generated text in the plugin. Currently its job
 * is to remove em/en dashes, which are a strong "AI-written" tell and rare in
 * natural human writing. Prompt instructions help but are not reliable, so this
 * runs deterministically after every generation (articles, briefs, the map).
 *
 * Legitimate hyphens (U+002D, e.g. "flat-faced", "dog-to-dog") are preserved;
 * only em (—), en (–) and horizontal-bar (―) dashes are handled.
 */
class Verlo_Text {

	/**
	 * Remove em/en dashes from a string (plain text or HTML), replacing them
	 * with natural punctuation, then tidy any punctuation artifacts.
	 */
	public static function humanize( $text ) {
		if ( ! is_string( $text ) || '' === $text ) { return $text; }

		// Normalise dash HTML entities to their literal characters first.
		$text = str_replace(
			array( '&mdash;', '&#8212;', '&#x2014;', '&horbar;', '&#8213;', '&#x2015;' ),
			'—',
			$text
		);
		$text = str_replace(
			array( '&ndash;', '&#8211;', '&#x2013;' ),
			'–',
			$text
		);

		// Numeric ranges ("10–15 minutes") should become a hyphen, not a comma.
		$text = preg_replace( '/(\d)\s*[\x{2013}\x{2014}\x{2015}]\s*(\d)/u', '$1-$2', $text );

		// --- Bold "lead-in term — explanation" pattern -------------------------
		// Lists often look like "<strong>Sit</strong> — The easiest..." or, in
		// raw model output, "**Sit**—The easiest...". Naively deleting the dash
		// fuses the term to its explanation ("SitThe easiest"). We normalise any
		// dash (em/en/bar) that sits between a bold lead-in and its text into a
		// "colon + single space", which reads naturally and never collapses.
		// Handled BEFORE the generic dash rule. Covers HTML <strong>/<b>, an
		// optional &nbsp; or whitespace on either side, and Markdown **term**.
		$sep = '(?:\s|&nbsp;|&#160;|&#xA0;)*';
		$dash = '[\x{2013}\x{2014}\x{2015}]';

		// HTML bold: "</strong>  —  text" -> "</strong>: text"
		$text = preg_replace(
			'/(<\/(?:strong|b|em|i)>)' . $sep . $dash . $sep . '/u',
			'$1: ',
			$text
		);
		// Markdown bold: "**term**—text" or "**term** — text" -> "**term**: text"
		$text = preg_replace(
			'/(\*\*[^*\n]+\*\*)' . $sep . $dash . $sep . '/u',
			'$1: ',
			$text
		);
		// Safety net for the "no separator" model output where the dash is gone
		// but the term still fused the next word: insert ": " when a bold tag is
		// immediately followed by a capital letter with no space/punctuation.
		$text = preg_replace(
			'/(<\/(?:strong|b)>)(?=[A-Z])/',
			'$1: ',
			$text
		);
		$text = preg_replace(
			'/(\*\*[^*\n]+\*\*)(?=[A-Z])/',
			'$1: ',
			$text
		);
		// -----------------------------------------------------------------------

		// Every remaining em/en/bar dash becomes a comma (reads as a natural pause).
		$text = preg_replace( '/\s*[\x{2013}\x{2014}\x{2015}]\s*/u', ', ', $text );

		// Tidy punctuation artifacts the substitution may create.
		$text = preg_replace( '/(,\s*){2,}/', ', ', $text );      // collapse ", , ," -> ", "
		$text = preg_replace( '/\s+,/', ',', $text );             // " ," -> ","
		$text = preg_replace( '/,(\s*[.!?;:])/', '$1', $text );   // ", ." -> "."
		// A comma immediately before a CLOSING tag is an artifact ("text, </p>"
		// -> "text </p>"); but a comma before an OPENING tag may be a real
		// separator ("foo, <strong>bar</strong>") and must be kept.
		$text = preg_replace( '/,(\s*<\/[a-z])/i', '$1', $text ); // ", </p>" -> " </p>"
		// Only strip a comma that leads a block (start of string), NOT one that
		// merely follows a closing inline tag — that comma separates real words.
		$text = preg_replace( '/^\s*,\s*/', '', $text );          // leading comma at very start
		$text = preg_replace( '/(>)\s*,\s*(<)/', '$1$2', $text ); // ">, <" between tags only
		$text = preg_replace( '/,\s*$/', '', $text );             // trailing comma
		$text = preg_replace( '/\s+(<\/(?:p|li|h[1-6]|td|th|div|span|strong|b|em|i)>)/i', '$1', $text ); // stray space before a closing tag

		return $text;
	}

	/**
	 * Apply humanize() to every string in an array (recursively), leaving
	 * non-strings untouched. Useful for cleaning structured AI output.
	 */
	public static function humanize_deep( $value ) {
		if ( is_string( $value ) ) { return self::humanize( $value ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) { $value[ $k ] = self::humanize_deep( $v ); }
		}
		return $value;
	}

	/**
	 * Deterministic guard: strip any PAST year (e.g. "2024" when it is now
	 * 2026) from text, whatever the AI returned. The current year (and any
	 * future year) is left alone. Safe for both short keywords ("...cost in
	 * the UK 2024" -> "...cost in the UK") and full prose/titles ("...Skills
	 * at Work in 2024" -> "...Skills at Work", not "...Skills at Work in").
	 *
	 * Plain removal of a bare year can leave a dangling preposition ("in",
	 * "for", "of", ...) when the year was the object of that preposition, so
	 * a "<preposition> <stale year>" pair is removed as a unit first; any
	 * remaining bare stale year (e.g. "(2024)", "2024 trends") is then
	 * removed as a token, and a final pass mops up leftover dangling
	 * prepositions and punctuation artifacts.
	 */
	public static function scrub_stale_years( $text ) {
		if ( ! is_string( $text ) || '' === $text ) { return $text; }
		$current = (int) wp_date( 'Y' );
		$preps   = 'in|for|of|during|throughout|since|by|from';

		// 1) "<preposition> <stale year>" -> remove as a unit (kills the
		//    dangling-preposition case: "at Work in 2024" -> "at Work").
		$text = preg_replace_callback(
			'/\b(?:' . $preps . ')\s+((?:19|20)\d{2})\b/i',
			function ( $m ) use ( $current ) {
				return ( (int) $m[1] >= $current ) ? $m[0] : '';
			},
			$text
		);

		// 2) Any remaining bare stale year -> remove the token.
		$text = preg_replace_callback(
			'/\b(19|20)\d{2}\b/',
			function ( $m ) use ( $current ) {
				return ( (int) $m[0] >= $current ) ? $m[0] : '';
			},
			$text
		);

		// 3) Tidy whitespace/punctuation artifacts left behind.
		$text = preg_replace( '/\(\s*\)/', '', $text );        // empty "( )" from "(2024)"
		$text = preg_replace( '/\s{2,}/', ' ', $text );         // collapse double spaces
		$text = preg_replace( '/\s+([,.;:!?])/', '$1', $text ); // " ." -> "."

		// 4) Mop up a trailing dangling preposition at the very end of the
		//    string (covers cases step 1's pattern didn't catch).
		$text = preg_replace( '/\s+(?:' . $preps . ')\s*([.!?:;]?)\s*$/i', '$1', $text );

		return trim( $text );
	}
}
