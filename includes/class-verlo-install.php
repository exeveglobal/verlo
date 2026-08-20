<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Schema for the knowledge graph, plus article content-version storage.
 *
 * Three tables:
 *   verlo_kg_objects     — one row per indexed post/page/CPT (inverted index)
 *   verlo_kg_terms       — many rows per object (term -> weight), indexed on term
 *   verlo_article_versions — full post_content snapshot per generated-article
 *                            version, keyed by (post_id, version)
 *
 * "Find related content" is then a single indexed GROUP BY query rather than a
 * PHP scan of the whole graph, which is what keeps it fast at tens of thousands
 * of objects across many sites.
 *
 * Article content snapshots live in their own table rather than inside
 * Verlo_Article_Log's wp_options row: that option already stores per-post
 * version METADATA (title/keyword/timestamps, capped at 20 versions/post,
 * 500 posts), and appending full article HTML to every version there would
 * make that option grow into the tens of megabytes — options are frequently
 * autoloaded/cached as a whole, so that's a real performance risk a plugin
 * a customer didn't ask to slow down shouldn't introduce. A dedicated table
 * with the same dbDelta/upgrade machinery already used for the knowledge
 * graph avoids that entirely.
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

	public static function article_versions_table() {
		global $wpdb;
		return $wpdb->prefix . 'verlo_article_versions';
	}

	public static function create_tables() {
		global $wpdb;
		$charset  = $wpdb->get_charset_collate();
		$objects  = self::objects_table();
		$terms    = self::terms_table();
		$versions = self::article_versions_table();

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

		// One row per generated-article version, holding the full post_content
		// at that point — what Verlo_Article_Log's diff/restore actions read
		// from. Pruned in lockstep with Verlo_Article_Log's MAX_VERSIONS cap.
		$sql_versions = "CREATE TABLE {$versions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			version INT UNSIGNED NOT NULL,
			content LONGTEXT NOT NULL,
			saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_version (post_id, version)
		) {$charset};";

		dbDelta( $sql_objects );
		dbDelta( $sql_terms );
		dbDelta( $sql_versions );
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
