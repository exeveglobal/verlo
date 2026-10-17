<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen. Removes Verlo's settings and its schedule. Posts that
 * Verlo published are ordinary WordPress posts and are left exactly as they are.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

wp_clear_scheduled_hook( 'verlo_poll' );
delete_option( 'verlo_connection' );
delete_transient( 'verlo_poll_lock' );

// Version 1 left more behind (knowledge graph, license key, tokens); clean those up too if this site ever ran it.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'verlo\\_%' OR option_name LIKE '\\_transient\\_verlo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_verlo\\_%'" );
foreach ( array( 'verlo_kg_objects', 'verlo_kg_terms', 'verlo_article_versions' ) as $suffix ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix );
}
