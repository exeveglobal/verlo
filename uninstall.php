<?php
/**
 * Fires only on "Delete" from the Plugins list (never on deactivate), after
 * WordPress has already confirmed the request. Removes everything Verlo
 * leaves in the database: the two knowledge-graph tables, every verlo_*
 * option (including the encrypted license key and SaaS token), and every
 * verlo_*-prefixed transient.
 *
 * Deliberately uses wildcard DELETEs against wp_options instead of an
 * exhaustive hardcoded list of option names: several call sites store
 * per-article locks/tokens under dynamic names (e.g. `_verlo_lock_verlo_gen_lock_123`,
 * `verlo_rel_<hash>`), so no fixed list can stay complete — the same failure
 * mode that left activation-only schema setup silently stale on update
 * (see verlo_maybe_upgrade()) would repeat here if this list had to be
 * maintained by hand every time a new option is introduced.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

require_once __DIR__ . '/includes/class-verlo-install.php';

$wpdb->query( 'DROP TABLE IF EXISTS ' . Verlo_Install::objects_table() );
$wpdb->query( 'DROP TABLE IF EXISTS ' . Verlo_Install::terms_table() );

// Plain options: verlo_settings, verlo_saas_token, verlo_license_key,
// verlo_article_log, verlo_db_version, etc. — everything not underscore-prefixed.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'verlo_' ) . '%'
	)
);

// Per-article generation locks, e.g. _verlo_lock_verlo_gen_lock_123.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_verlo_lock_' ) . '%'
	)
);

// Transients (verlo_activation_redirect, verlo_probe_token/result,
// verlo_gen_token_<id>, verlo_rel_<hash>) — only relevant when transients are
// backed by wp_options rather than an external object cache, but harmless
// either way.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_verlo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_verlo_' ) . '%'
	)
);
