<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The knowledge graph: build, maintain, and query the inverted index.
 *
 * Term extraction runs entirely in PHP (no API tokens). Title and taxonomy
 * terms are weighted higher than body terms so matching reflects what a page is
 * actually about. The graph is derived data — it can be rebuilt from the site's
 * own content at any time, so it never needs migrating.
 */
class Verlo_Knowledge_Graph {

	const MAX_TERMS_PER_OBJECT = 40;   // cap rows per object
	const TITLE_WEIGHT        = 4;
	const TAXONOMY_WEIGHT     = 3;
	const BODY_WEIGHT         = 1;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Record the time of a content edit that changed the graph. Called only from
	 * the incremental hooks (not from a full rebuild), so the admin can see
	 * whether the graph has drifted from the last full build via post edits.
	 */
	protected static function touch_last_edit() {
		update_option( 'verlo_kg_last_edit', time(), 'no' );
	}

	public static function on_delete( $post_id ) {
		self::remove_object( $post_id );
		self::touch_last_edit();
	}

	/* ---------------------------------------------------------------------
	 * Incremental maintenance
	 * ------------------------------------------------------------------- */

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
		if ( ! in_array( $post->post_type, verlo_target_post_types(), true ) ) { return; }
		if ( 'publish' === $post->post_status ) {
			self::index_object( $post_id );
		} else {
			self::remove_object( $post_id );
		}
		self::touch_last_edit();
	}

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! in_array( $post->post_type, verlo_target_post_types(), true ) ) { return; }
		if ( 'publish' === $new_status ) {
			self::index_object( $post->ID );
			self::touch_last_edit();
		} elseif ( 'publish' === $old_status ) {
			self::remove_object( $post->ID );
			self::touch_last_edit();
		}
	}

	/* ---------------------------------------------------------------------
	 * Indexing
	 * ------------------------------------------------------------------- */

	/**
	 * Index (or re-index) a single object: write its object row and replace its
	 * terms. Idempotent — safe to call repeatedly.
	 */
	public static function index_object( $post_id ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::remove_object( $post_id );
			return;
		}

		$body_text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$tax_terms = self::object_taxonomy_terms( $post_id, $post->post_type );

		// Build the weighted term map from title, taxonomy, and body.
		$weighted = array();
		self::accumulate( $weighted, self::tokenize( $post->post_title ), self::TITLE_WEIGHT );
		self::accumulate( $weighted, self::tokenize( implode( ' ', $tax_terms ) ), self::TAXONOMY_WEIGHT );
		self::accumulate( $weighted, self::tokenize( $body_text ), self::BODY_WEIGHT );

		arsort( $weighted );
		$weighted = array_slice( $weighted, 0, self::MAX_TERMS_PER_OBJECT, true );

		$objects = Verlo_Install::objects_table();
		$terms   = Verlo_Install::terms_table();

		$wpdb->replace(
			$objects,
			array(
				'object_id'  => $post_id,
				'type'       => $post->post_type,
				'title'      => $post->post_title,
				'url'        => get_permalink( $post_id ),
				'word_count' => str_word_count( $body_text ),
				'indexed_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		// Replace this object's terms.
		$wpdb->delete( $terms, array( 'object_id' => $post_id ), array( '%d' ) );
		self::bulk_insert_terms( $post_id, $weighted );

		self::bump_cache();
	}

	public static function remove_object( $post_id ) {
		global $wpdb;
		$wpdb->delete( Verlo_Install::objects_table(), array( 'object_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( Verlo_Install::terms_table(), array( 'object_id' => $post_id ), array( '%d' ) );
		self::bump_cache();
	}

	/**
	 * Bulk-insert terms in chunks to keep query size sane.
	 */
	protected static function bulk_insert_terms( $post_id, $weighted ) {
		global $wpdb;
		if ( empty( $weighted ) ) { return; }
		$terms = Verlo_Install::terms_table();

		$chunks = array_chunk( array_keys( $weighted ), 50 );
		foreach ( $chunks as $chunk ) {
			$values       = array();
			$placeholders = array();
			foreach ( $chunk as $term ) {
				$placeholders[] = '(%d, %s, %d)';
				$values[]       = $post_id;
				$values[]       = $term;
				$values[]       = (int) $weighted[ $term ];
			}
			$sql = "INSERT INTO {$terms} (object_id, term, weight) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Querying — the whole point of the inverted index
	 * ------------------------------------------------------------------- */

	/**
	 * Find objects most related to a piece of text (e.g. a planned article
	 * topic). Returns [ ['object_id','title','url','type','score'], ... ].
	 *
	 * One indexed GROUP BY query, cached per cache-version so it auto-busts on
	 * any content change.
	 */
	public static function related_objects( $text, $limit = 6, $exclude_id = 0 ) {
		global $wpdb;

		$target = array_keys( self::tokenize( $text ) );
		$target = array_slice( $target, 0, 30 );
		if ( empty( $target ) ) { return array(); }

		$cache_key = 'verlo_rel_' . md5( wp_json_encode( array( self::cache_ver(), $target, $limit, $exclude_id ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) { return $cached; }

		$objects = Verlo_Install::objects_table();
		$terms   = Verlo_Install::terms_table();

		$placeholders = implode( ',', array_fill( 0, count( $target ), '%s' ) );
		$params       = $target;

		$exclude_sql = '';
		if ( $exclude_id > 0 ) {
			$exclude_sql = ' AND t.object_id != %d';
			$params[]    = $exclude_id;
		}
		$params[] = (int) $limit;

		$sql = "SELECT o.object_id, o.title, o.url, o.type, SUM(t.weight) AS score
			FROM {$terms} t
			INNER JOIN {$objects} o ON o.object_id = t.object_id
			WHERE t.term IN ({$placeholders}){$exclude_sql}
			GROUP BY t.object_id
			ORDER BY score DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		set_transient( $cache_key, $rows, HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * How many indexed objects already cover a topic — the topic-gap signal.
	 */
	public static function coverage_for( $text ) {
		$related = self::related_objects( $text, 100 );
		return count( $related );
	}

	/* ---------------------------------------------------------------------
	 * Coverage check (stricter than related_objects)
	 *
	 * related_objects() is the right tool for internal-link candidates: on a
	 * niche site, every post sharing the niche vocabulary IS a fine link
	 * target. Coverage is a different question: "does an existing post match
	 * the DISTINCTIVE part of this keyword?" On a French-bulldog site the
	 * terms "french"/"bulldog" appear in nearly every post and prove nothing;
	 * "lifespan"/"average" are what distinguish the topic. So we weight by
	 * rarity (document frequency) and require the distinctive terms to match.
	 * ------------------------------------------------------------------- */

	const COVERAGE_MAX_DF_RATIO = 0.5;  // term in >50% of docs = niche-generic, ignored
	const COVERAGE_MIN_DISTINCT = 2;    // below 2 distinctive unigrams we cannot judge
	const COVERAGE_MIN_MATCHED  = 2;    // a single broad-term match must NOT count as coverage
	const COVERAGE_MIN_RATIO    = 0.67; // (3+ distinctive case) proportion that must match
	const COVERAGE_MIN_DOCS     = 5;    // below this, DF stats are meaningless: never "covered"
	const COVERAGE_CANDIDATES   = 5;    // how many top related posts to test for coverage

	/**
	 * Check whether the site already covers a keyword. Returns:
	 * [ 'covered' => bool, 'url' => ?, 'title' => ?, 'ratio' => float,
	 *   'distinctive' => [terms], 'matched' => [terms] ]
	 */
	public static function coverage_check( $keyword ) {
		global $wpdb;
		$none = array( 'covered' => false, 'url' => '', 'title' => '', 'ratio' => 0, 'distinctive' => array(), 'matched' => array() );

		$candidates = self::related_objects( $keyword, self::COVERAGE_CANDIDATES );
		if ( empty( $candidates ) ) { return $none; }

		$query_terms = array_keys( self::tokenize( $keyword ) );
		if ( empty( $query_terms ) ) { return $none; }

		$objects = Verlo_Install::objects_table();
		$terms   = Verlo_Install::terms_table();

		$total_docs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$objects}" );

		// Document frequency for each query term (independent of candidate).
		$placeholders = implode( ',', array_fill( 0, count( $query_terms ), '%s' ) );
		$df_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT term, COUNT(DISTINCT object_id) AS df FROM {$terms} WHERE term IN ({$placeholders}) GROUP BY term",
			$query_terms
		), ARRAY_A );
		$df = array();
		foreach ( (array) $df_rows as $row ) { $df[ $row['term'] ] = (int) $row['df']; }

		// Test each top candidate; the first that distinctively matches wins.
		// (The true covering post is not always the highest raw-overlap post.)
		$best = $none;
		foreach ( $candidates as $cand ) {
			$cand_terms = $wpdb->get_col( $wpdb->prepare(
				"SELECT term FROM {$terms} WHERE object_id = %d",
				(int) $cand['object_id']
			) );
			$verdict = self::decide_coverage( $query_terms, $df, $total_docs, (array) $cand_terms );
			if ( $verdict['covered'] ) {
				$verdict['url']   = $cand['url'];
				$verdict['title'] = $cand['title'];
				return $verdict;
			}
			if ( $verdict['ratio'] > $best['ratio'] ) { $best = $verdict; }
		}
		// Nothing crossed the bar; return the closest (not covered) for diagnostics.
		$best['url']   = '';
		$best['title'] = '';
		return $best;
	}

	/**
	 * Pure decision function (no DB) so the calibration is unit-testable.
	 *
	 * @param array $query_terms terms extracted from the keyword
	 * @param array $df          term => number of documents containing it
	 * @param int   $total_docs  total indexed documents
	 * @param array $cand_terms  the candidate post's stored terms
	 */
	public static function decide_coverage( $query_terms, $df, $total_docs, $cand_terms ) {
		$out = array( 'covered' => false, 'ratio' => 0, 'distinctive' => array(), 'matched' => array() );
		if ( $total_docs < self::COVERAGE_MIN_DOCS ) { return $out; }

		// Distinctive = appears in few documents relative to the site.
		// UNIGRAMS ONLY: bigrams ("bulldog cost", "cost the") almost never match
		// exactly between two differently-worded posts and unfairly sink the
		// ratio, so they are excluded from the coverage judgment.
		$distinctive = array();
		foreach ( $query_terms as $t ) {
			if ( false !== strpos( $t, ' ' ) ) { continue; } // skip bigrams
			$term_df = isset( $df[ $t ] ) ? (int) $df[ $t ] : 0;
			$ratio   = $term_df / max( 1, $total_docs );
			if ( $ratio <= self::COVERAGE_MAX_DF_RATIO ) {
				$distinctive[] = $t;
			}
		}
		$out['distinctive'] = $distinctive;
		$nd = count( $distinctive );
		if ( $nd < self::COVERAGE_MIN_DISTINCT ) { return $out; } // can't judge on <2 distinctive terms

		$cand_lookup = array_flip( $cand_terms );
		$matched     = array();
		foreach ( $distinctive as $t ) {
			if ( isset( $cand_lookup[ $t ] ) ) { $matched[] = $t; }
		}
		$out['matched'] = $matched;
		$out['ratio']   = count( $matched ) / $nd;

		// The rare, topic-DEFINING terms must all be present. On a niche-dominated
		// site the breed name is itself "distinctive", so a same-breed post can
		// match breed + a filler word ("boston, terrier, much") and look covered
		// while the defining term ("cost", "train") never matched. Requiring every
		// low-frequency term to match closes that gap.
		$spec_threshold = max( 2, (int) ceil( 0.05 * $total_docs ) );
		$matched_lookup = array_flip( $matched );
		$required_ok    = true;
		foreach ( $distinctive as $t ) {
			$tdf = isset( $df[ $t ] ) ? (int) $df[ $t ] : 0;
			if ( $tdf <= $spec_threshold && ! isset( $matched_lookup[ $t ] ) ) {
				$required_ok = false;
				break;
			}
		}

		// Tiered rule (in addition to: every defining term must match):
		//  - exactly 2 distinctive terms: BOTH must match.
		//  - 3+ distinctive terms: at least MIN_MATCHED matched AND a high ratio.
		if ( 2 === $nd ) {
			$out['covered'] = ( 2 === count( $matched ) );
		} else {
			$out['covered'] = ( count( $matched ) >= self::COVERAGE_MIN_MATCHED && $out['ratio'] >= self::COVERAGE_MIN_RATIO );
		}
		$out['covered'] = ( $out['covered'] && $required_ok );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Term extraction (free, in PHP)
	 * ------------------------------------------------------------------- */

	/**
	 * Tokenize into a frequency map of unigrams + bigrams, stopword-filtered.
	 */
	public static function tokenize( $text ) {
		$text  = strtolower( wp_strip_all_tags( (string) $text ) );
		$text  = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = self::stopwords();

		$clean = array();
		foreach ( $words as $w ) {
			if ( strlen( $w ) < 3 || isset( $stop[ $w ] ) || ctype_digit( $w ) ) { continue; }
			$clean[] = $w;
		}

		$freq = array();
		foreach ( $clean as $w ) {
			$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
		}
		$n = count( $clean );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$bg          = $clean[ $i ] . ' ' . $clean[ $i + 1 ];
			$freq[ $bg ] = ( $freq[ $bg ] ?? 0 ) + 1;
		}
		return $freq;
	}

	protected static function accumulate( &$map, $freq, $weight ) {
		foreach ( $freq as $term => $count ) {
			$map[ $term ] = ( $map[ $term ] ?? 0 ) + ( $count * $weight );
		}
	}

	/**
	 * Category + tag (+ custom taxonomy) names for an object, so the site's
	 * structure influences matching.
	 */
	protected static function object_taxonomy_terms( $post_id, $post_type ) {
		$names = array();
		$taxes = get_object_taxonomies( $post_type, 'names' );
		foreach ( $taxes as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$names[] = $t->name;
				}
			}
		}
		return $names;
	}

	/* ---------------------------------------------------------------------
	 * Stats + cache
	 * ------------------------------------------------------------------- */

	/**
	 * A sample of post titles (most substantial first) for AI site inference.
	 */
	public static function get_titles_sample( $limit = 40 ) {
		global $wpdb;
		$objects = Verlo_Install::objects_table();
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT title FROM {$objects} ORDER BY word_count DESC LIMIT %d",
			(int) $limit
		) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The site's top vocabulary by summed term weight — cheap topical signal.
	 */
	public static function get_top_terms( $limit = 30 ) {
		global $wpdb;
		$terms = Verlo_Install::terms_table();
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT term FROM {$terms} GROUP BY term ORDER BY SUM(weight) DESC LIMIT %d",
			(int) $limit
		) );
		return is_array( $rows ) ? $rows : array();
	}

	public static function stats() {
		global $wpdb;
		$objects = Verlo_Install::objects_table();
		$terms   = Verlo_Install::terms_table();
		$by_type = $wpdb->get_results( "SELECT type, COUNT(*) AS n FROM {$objects} GROUP BY type", ARRAY_A );
		return array(
			'objects'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$objects}" ),
			'terms'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$terms}" ),
			'by_type'   => is_array( $by_type ) ? $by_type : array(),
			'last_edit' => (int) get_option( 'verlo_kg_last_edit', 0 ),
		);
	}

	protected static function cache_ver() {
		return (int) get_option( VERLO_OPT_CACHE_VER, 1 );
	}

	protected static function bump_cache() {
		update_option( VERLO_OPT_CACHE_VER, self::cache_ver() + 1, 'no' );
	}

	protected static function stopwords() {
		static $s = null;
		if ( null !== $s ) { return $s; }
		$list = array( 'the','and','for','are','but','not','you','all','any','can','her','was','one','our','out','his','has','had','how','its','who','did','this','that','with','from','your','have','will','they','what','when','about','into','than','then','them','were','been','more','most','some','such','only','very','also','just','over','here','there','their','would','could','should','which','these','those','because','while','where','after','before','being','other','using','used','use','make','made','like','get','got','via','per','etc','too','off','own','few','may','say','says','said','new','now','one','two','three' );
		$s = array_flip( $list );
		return $s;
	}
}
