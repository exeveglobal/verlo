<?php
/**
 * Plugin Name:       Verlo
 * Plugin URI:        https://exeve.global/
 * Description:       Verlo plans, writes, and optimizes SEO content for your site, end to end. It builds a knowledge graph of your existing content, designs a topical map of pillars and planned articles, turns each into a content brief, and generates publish-ready, human-quality draft articles, complete with on-page SEO, internal links, and stock images, for your review before publishing.
 * Version:           1.1.29
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EXEVE
 * Author URI:        https://exeve.global/
 * License:           GPL-2.0-or-later
 * Text Domain:       verlo
 * Domain Path:       /languages
 *
 * Verlo v1.0 — initial release.
 * Core pipeline: Knowledge Graph -> Strategy Profile -> Topical Map ->
 * Content Briefs -> AI Generator (with humanization, on-page SEO, and
 * stock imagery) -> WordPress drafts for human review and publishing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VERLO_VERSION', '1.1.29' );
define( 'VERLO_FILE', __FILE__ );
define( 'VERLO_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERLO_URL', plugin_dir_url( __FILE__ ) );
define( 'VERLO_DOCS_URL', 'https://verlohub.com/guide' );

// Option keys.
define( 'VERLO_OPT_POST_TYPES', 'verlo_post_types' );
define( 'VERLO_OPT_PROGRESS', 'verlo_rebuild_progress' );
define( 'VERLO_OPT_SNAPSHOT', 'verlo_rebuild_snapshot' );
define( 'VERLO_OPT_CACHE_VER', 'verlo_kg_cache_ver' );
define( 'VERLO_OPT_SETTINGS', 'verlo_settings' );
define( 'VERLO_OPT_DB_VERSION', 'verlo_db_version' );

// Cron / action hooks.
define( 'VERLO_HOOK_REBUILD_CONTINUE', 'verlo_rebuild_continue' );

require_once VERLO_DIR . 'includes/class-verlo-install.php';
require_once VERLO_DIR . 'includes/class-verlo-build-log.php';
require_once VERLO_DIR . 'includes/class-verlo-knowledge-graph.php';
require_once VERLO_DIR . 'includes/class-verlo-rebuild.php';
require_once VERLO_DIR . 'includes/class-verlo-log.php';
require_once VERLO_DIR . 'includes/class-verlo-article-log.php';
require_once VERLO_DIR . 'includes/class-verlo-env.php';
require_once VERLO_DIR . 'includes/class-verlo-text.php';
require_once VERLO_DIR . 'includes/class-verlo-auth.php';           // must load before saas-client
require_once VERLO_DIR . 'includes/class-verlo-saas-client.php';    // gateway; all SaaS calls go here
require_once VERLO_DIR . 'includes/class-verlo-profile.php';
require_once VERLO_DIR . 'includes/class-verlo-topical-map.php';
require_once VERLO_DIR . 'includes/class-verlo-brief.php';
require_once VERLO_DIR . 'includes/class-verlo-strategist.php';
require_once VERLO_DIR . 'includes/class-verlo-faq-schema.php';
require_once VERLO_DIR . 'includes/class-verlo-generator.php';
require_once VERLO_DIR . 'includes/class-verlo-async-job.php';
require_once VERLO_DIR . 'includes/class-verlo-images.php';
require_once VERLO_DIR . 'admin/class-verlo-admin.php';
require_once VERLO_DIR . 'admin/class-verlo-onboarding-admin.php';
require_once VERLO_DIR . 'admin/class-verlo-guided-tour.php';
require_once VERLO_DIR . 'admin/class-verlo-profile-admin.php';
require_once VERLO_DIR . 'admin/class-verlo-map-admin.php';
require_once VERLO_DIR . 'admin/class-verlo-brief-admin.php';
require_once VERLO_DIR . 'admin/class-verlo-log-admin.php';

/**
 * Plugin settings (API credentials etc.).
 */
function verlo_default_settings() {
	return array(
		'outbound_domains'=> '',   // extra trusted domains for outbound links (one per line)
		'inline_images'   => 1,    // number of in-body stock images (0-3)
	);
}

function verlo_get_settings() {
	$saved = get_option( VERLO_OPT_SETTINGS, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), verlo_default_settings() );
}

/**
 * Default target post types: all public types except attachments.
 */
function verlo_default_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );
	return array_values( $types );
}

function verlo_target_post_types() {
	$saved = get_option( VERLO_OPT_POST_TYPES, null );
	if ( is_array( $saved ) && ! empty( $saved ) ) {
		return $saved;
	}
	return verlo_default_post_types();
}

/**
 * Activation: create tables, then kick off the first full index build.
 */
function verlo_activate() {
	Verlo_Install::create_tables();
	update_option( VERLO_OPT_DB_VERSION, VERLO_VERSION, '', 'yes' );
	add_option( VERLO_OPT_CACHE_VER, 1, '', 'no' );
	// Build the graph for the first time (chunked, in the background).
	Verlo_Rebuild::start( verlo_target_post_types(), 'activation' );
	if ( ! wp_next_scheduled( VERLO_HOOK_REBUILD_CONTINUE ) ) {
		wp_schedule_single_event( time() + 5, VERLO_HOOK_REBUILD_CONTINUE );
	}
	// Marks this activation as a fresh one-time redirect target — checked
	// (and cleared) on the very next admin_init, so it fires once and never
	// hijacks a later, unrelated admin page load.
	set_transient( 'verlo_activation_redirect', 1, 60 );
}
register_activation_hook( __FILE__, 'verlo_activate' );

/**
 * Sends a brand-new single-plugin activation straight to the Getting
 * Started checklist (Verlo_Onboarding_Admin) instead of leaving it wherever
 * WP happened to land (usually the Plugins list, with only a generic
 * "Plugin activated" notice) — onboarding previously relied entirely on the
 * README, then just landed on the Knowledge Graph page with no indication
 * of what to do next or in what order. Skipped for bulk/network activation,
 * where a forced redirect would be disruptive rather than helpful.
 */
function verlo_activation_redirect() {
	if ( ! get_transient( 'verlo_activation_redirect' ) ) {
		return;
	}
	delete_transient( 'verlo_activation_redirect' );

	if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_safe_redirect( Verlo_Onboarding_Admin::url() );
	exit;
}
add_action( 'admin_init', 'verlo_activation_redirect' );

/**
 * Docs/Support links on the Plugins list — the only in-admin pointer to
 * documentation or a human before this shipped.
 */
add_filter( 'plugin_action_links_' . plugin_basename( VERLO_FILE ), 'verlo_plugin_action_links' );
function verlo_plugin_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( VERLO_DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'verlo' ) . '</a>' );
	return $links;
}

add_filter( 'plugin_row_meta', 'verlo_plugin_row_meta', 10, 2 );
function verlo_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( VERLO_FILE ) !== $file ) {
		return $links;
	}
	$links[] = '<a href="' . esc_url( VERLO_DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'verlo' ) . '</a>';
	$links[] = '<a href="mailto:support@verlohub.com">' . esc_html__( 'Support', 'verlo' ) . '</a>';
	return $links;
}

/**
 * Deactivation: clear scheduled continuation (keep tables + data).
 */
function verlo_deactivate() {
	wp_clear_scheduled_hook( VERLO_HOOK_REBUILD_CONTINUE );
	wp_clear_scheduled_hook( 'verlo_cron_generate' );
	wp_clear_scheduled_hook( 'verlo_cron_async' );
}
register_deactivation_hook( __FILE__, 'verlo_deactivate' );

/**
 * Re-applies the schema (via dbDelta(), which safely diffs and only applies
 * what's changed) whenever the installed DB version doesn't match
 * VERLO_VERSION. register_activation_hook only fires on a fresh activate,
 * never on an in-place update (zip re-upload or a future auto-update), so
 * without this a schema change shipped in a later release would silently
 * never reach an already-installed site. get_option() here is effectively
 * free once VERLO_OPT_DB_VERSION is autoloaded (the common case), so this
 * runs safely on every request rather than only on an explicit update hook.
 */
function verlo_maybe_upgrade() {
	if ( get_option( VERLO_OPT_DB_VERSION ) === VERLO_VERSION ) {
		return;
	}
	Verlo_Install::create_tables();
	update_option( VERLO_OPT_DB_VERSION, VERLO_VERSION, '', 'yes' );
}

/**
 * Loads translations for the "verlo" text domain from /languages. Plugins
 * hosted on WordPress.org get this for free since 4.6; a plugin distributed
 * outside .org (this one) still needs the explicit call for a shipped
 * .mo/.po translation to actually be found and used.
 */
function verlo_load_textdomain() {
	load_plugin_textdomain( 'verlo', false, dirname( plugin_basename( VERLO_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'verlo_load_textdomain' );

/**
 * Boot runtime hooks.
 */
function verlo_boot() {
	verlo_maybe_upgrade();
	Verlo_Knowledge_Graph::init();   // incremental index hooks
	Verlo_Rebuild::init();           // cron continuation handler

	// Background article generation: the loopback worker (admin-post, incl.
	// no-priv since the loopback carries no cookie) and the WP-Cron fallback.
	// Registered outside is_admin() because neither context is guaranteed to
	// report as admin.
	add_action( 'admin_post_verlo_run_generation', array( 'Verlo_Generator', 'run_background' ) );
	add_action( 'admin_post_nopriv_verlo_run_generation', array( 'Verlo_Generator', 'run_background' ) );
	add_action( 'verlo_cron_generate', array( 'Verlo_Generator', 'run_via_cron' ), 10, 1 );

	// Background site-level jobs (analyze, topical map, next brief): same
	// loopback/cron pattern, generalized - see class-verlo-async-job.php.
	add_action( 'admin_post_verlo_run_async', array( 'Verlo_Async_Job', 'run_background' ) );
	add_action( 'admin_post_nopriv_verlo_run_async', array( 'Verlo_Async_Job', 'run_background' ) );
	add_action( 'verlo_cron_async', array( 'Verlo_Async_Job', 'run_via_cron' ), 10, 1 );
	add_action( 'wp_ajax_verlo_async_status', array( 'Verlo_Async_Job', 'ajax_status' ) );

	// Background-execution health probe (so we know whether this host runs
	// async work, and can warn + adapt if it does not).
	add_action( 'admin_post_verlo_bg_probe', array( 'Verlo_Env', 'probe_worker' ) );
	add_action( 'admin_post_nopriv_verlo_bg_probe', array( 'Verlo_Env', 'probe_worker' ) );

	if ( is_admin() ) {
		Verlo_Admin::init();
		Verlo_Onboarding_Admin::init();
		Verlo_Guided_Tour::init();
		Verlo_Profile_Admin::init();
		Verlo_Map_Admin::init();
		Verlo_Brief_Admin::init();
		Verlo_Log_Admin::init();

		// On any Verlo admin page: resolve a pending probe and (if due) kick one
		// off, then surface a guidance notice when background work is blocked.
		add_action( 'current_screen', 'verlo_env_admin_check' );
		add_action( 'admin_notices', 'verlo_env_admin_notice' );
		add_action( 'admin_notices', 'verlo_connect_admin_notice' );
	}
}
add_action( 'plugins_loaded', 'verlo_boot' );

/**
 * Resolve / start the background-health probe when viewing a Verlo screen.
 */
function verlo_env_admin_check() {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( (string) $screen->id, 'verlo' ) ) { return; }
	Verlo_Env::resolve_probe();
	Verlo_Env::start_probe(); // no-ops if recently checked
}

/**
 * Site-wide guidance notice: if background execution is blocked, tell the user
 * plainly what is happening and how to fix it (allowlist in their security
 * plugin), with a re-check action. Shown only on Verlo pages.
 */
function verlo_env_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( (string) $screen->id, 'verlo' ) ) { return; }

	if ( ! Verlo_Env::is_assisted() ) { return; }

	$recheck = wp_nonce_url( add_query_arg( 'verlo_recheck_env', 1 ), 'verlo_recheck_env' );
	echo '<div class="notice notice-warning"><p><strong>Verlo: background tasks are being blocked on this site.</strong> '
		. 'Articles and other generation steps still complete (the open admin tab finishes the work), but they start slower and you may see a "Run now" button. '
		. 'This is usually a security/firewall plugin (e.g. WP Hide, Wordfence) or a host setting blocking WordPress "loopback" requests and/or WP-Cron.</p>'
		. '<p>To make Verlo fully automatic, allow internal requests to <code>admin-post.php</code> and <code>wp-cron.php</code> for this site in your security plugin '
		. '(and approve outbound requests to the Verlo server for the Verlo plugin). '
		. '<a href="' . esc_url( $recheck ) . '" class="button button-small">Re-check now</a></p></div>';
}

/**
 * Site-wide guidance notice: if Verlo isn't connected, say so plainly and
 * link straight to the connection card, on every Verlo admin page. The
 * generation buttons are disabled in this state too (see class-verlo-map-admin.php
 * / class-verlo-brief-admin.php), but a user landing on any Verlo page should
 * see this before they even get to a disabled button.
 */
function verlo_connect_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( (string) $screen->id, 'verlo' ) ) { return; }

	if ( Verlo_Auth::is_connected() ) { return; }

	$connect_url = admin_url( 'admin.php?page=verlo-profile' );
	echo '<div class="notice notice-warning"><p><strong>Verlo is not connected.</strong> '
		. 'Connect your license to enable topical map, content brief, and article generation. '
		. '<a href="' . esc_url( $connect_url ) . '" class="button button-small">Connect Verlo</a></p></div>';
}

/**
 * Handle the "re-check" action from the notice.
 */
function verlo_env_handle_recheck() {
	if ( empty( $_GET['verlo_recheck_env'] ) ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'verlo_recheck_env' ) ) { return; }
	Verlo_Env::reprobe();
	wp_safe_redirect( remove_query_arg( array( 'verlo_recheck_env', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'verlo_env_handle_recheck' );
