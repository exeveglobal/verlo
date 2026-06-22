<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Schema for the knowledge graph.
 *
 * Two tables implementing an inverted index:
 *   verlo_kg_objects  — one row per indexed post/page/CPT
 *   verlo_kg_terms    — many rows per object (term -> weight), indexed on term
 *
 * "Find related content" is then a single indexed GROUP BY query rather than a
 * PHP scan of the whole graph, which is what keeps it fast at tens of thousands
 * of objects across many sites.
 */
class Verlo_Install {

	public static function objects_table() {
		global $wpdb;
		return $wpdb->prefix . 'verlo_kg_objects';
	}

	public static function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'verlo_kg_terms';
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$objects = self::objects_table();
		$terms   = self::terms_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_objects = "CREATE TABLE {$objects} (
			object_id BIGINT(20) UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'post',
			title TEXT NOT NULL,
			url TEXT NOT NULL,
			word_count INT NOT NULL DEFAULT 0,
			indexed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (object_id),
			KEY type (type)
		) {$charset};";

		// term length kept modest; weight is summed at query time.
		$sql_terms = "CREATE TABLE {$terms} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_id BIGINT(20) UNSIGNED NOT NULL,
			term VARCHAR(150) NOT NULL,
			weight INT NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY term (term),
			KEY object_id (object_id)
		) {$charset};";

		dbDelta( $sql_objects );
		dbDelta( $sql_terms );
	}

	/**
	 * Empty both tables (used at the start of a full rebuild).
	 */
	public static function truncate() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::objects_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . self::terms_table() );
	}
}
