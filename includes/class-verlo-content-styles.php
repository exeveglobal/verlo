<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Baseline front-end CSS fallback for AI-generated tables and pull quotes.
 *
 * The article generator writes real <table> markup for genuinely tabular
 * content and sparing <blockquote> pull quotes (verlo-saas's
 * buildArticleSystemPrompt()). class-verlo-generator.php::to_blocks() wraps
 * these as a Custom HTML block (class="wp-block-table") and a native
 * Gutenberg quote block (class="wp-block-quote") respectively — both
 * standard WordPress core block classes, which WordPress's own
 * wp-block-library-theme.css styles by default, and most themes style
 * further on top. That's why this mostly self-solves.
 *
 * It's not guaranteed, though: a minimal/custom theme that never enqueues
 * (or deliberately dequeues) the block-library stylesheet leaves both
 * completely unstyled — structurally correct HTML that reads as broken.
 * Found 2026-09 while checking the raw article HTML by hand outside
 * WordPress; the actual target here is a bare WordPress theme, not a
 * non-WordPress destination (out of scope — Verlo only ships into
 * WordPress via this plugin).
 *
 * This is a pure safety net, not house styling. Every rule is wrapped in
 * :where(), which always contributes zero specificity — any real rule the
 * active theme or WordPress core declares for the same selector, at ANY
 * specificity above zero, wins outright. It can only fill a gap, never
 * override a theme's own design.
 */
class Verlo_Content_Styles {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		// No real file to enqueue — inline it onto a no-op registered handle,
		// same pattern as the admin screens' own inline CSS (see
		// admin/class-verlo-profile-admin.php).
		wp_register_style( 'verlo-content-fallback', false, array(), VERLO_VERSION );
		wp_enqueue_style( 'verlo-content-fallback' );
		wp_add_inline_style( 'verlo-content-fallback', self::css() );
	}

	protected static function css() {
		return '
			:where(.wp-block-table){border-collapse:collapse;width:100%;}
			:where(.wp-block-table) th,:where(.wp-block-table) td{border:1px solid #ddd;padding:8px 12px;text-align:left;}
			:where(.wp-block-table) th{background:#f5f5f5;font-weight:600;}
			:where(.wp-block-quote){margin:1.5em 0;padding-left:1em;border-left:3px solid #ccc;font-style:italic;}
			:where(.wp-block-quote) p{margin:0 0 .5em;}
			:where(.wp-block-quote) p:last-child{margin-bottom:0;}
		';
	}
}
