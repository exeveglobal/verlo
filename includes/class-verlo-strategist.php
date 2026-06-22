<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Content Strategist: turns a planned map article into a Content Brief by
 * combining the approved map, the Strategy Profile, and the knowledge graph.
 * Picks WHAT to write next and specifies HOW — but does not write it.
 */
class Verlo_Strategist {

	/**
	 * All planned (non-covered) articles from the approved map, flattened with
	 * their pillar context. Returns [ ['id','keyword','intent','status','pillar','pillar_desc','has_brief'], ... ].
	 */
	/**
	 * Human-meaningful pipeline status for a planned article. Returns
	 * [ 'state' => key, 'label' => text, 'badge' => ok|warn|off, 'post_id' => int ].
	 * States: none → brief → draft → pending → published.
	 */
	public static function pipeline_status( $article_id ) {
		$brief = Verlo_Brief::get( $article_id );
		if ( ! $brief ) {
			return array( 'state' => 'none', 'label' => 'Not started', 'badge' => 'off', 'post_id' => 0 );
		}
		$post_id = isset( $brief['draft']['post_id'] ) ? (int) $brief['draft']['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'trash' === $post->post_status ) {
			return array( 'state' => 'brief', 'label' => 'Brief ready · article not written', 'badge' => 'info', 'post_id' => 0 );
		}
		switch ( $post->post_status ) {
			case 'publish':
				return array( 'state' => 'published', 'label' => 'Published', 'badge' => 'ok', 'post_id' => $post_id );
			case 'pending':
				return array( 'state' => 'pending', 'label' => 'Draft written · pending review', 'badge' => 'review', 'post_id' => $post_id );
			case 'future':
				return array( 'state' => 'scheduled', 'label' => 'Scheduled', 'badge' => 'scheduled', 'post_id' => $post_id );
			default:
				return array( 'state' => 'draft', 'label' => 'Draft written · review & publish', 'badge' => 'warn', 'post_id' => $post_id );
		}
	}

	public static function planned_articles() {
		$map = Verlo_Topical_Map::get();
		$out = array();
		foreach ( $map['pillars'] as $p ) {
			foreach ( $p['articles'] as $a ) {
				if ( 'covered' === $a['status'] ) { continue; }
				$out[] = array(
					'id'          => (int) $a['id'],
					'keyword'     => $a['keyword'],
					'intent'      => $a['intent'],
					'status'      => $a['status'],
					'pillar'      => $p['name'],
					'pillar_desc' => $p['description'],
					'has_brief'   => Verlo_Brief::exists( (int) $a['id'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * The next planned article without a brief, chosen ROUND-ROBIN across
	 * pillars: one from each pillar before any pillar gets a second. With many
	 * pillars this spreads coverage so no category waits for others to finish.
	 * Null if every planned article already has a brief.
	 */
	public static function pick_next() {
		$articles = self::planned_articles();
		if ( empty( $articles ) ) { return null; }

		// Group un-briefed articles by pillar, preserving map order.
		$pillars = array();
		$order   = array();
		foreach ( $articles as $a ) {
			if ( $a['has_brief'] ) { continue; }
			if ( ! isset( $pillars[ $a['pillar'] ] ) ) {
				$pillars[ $a['pillar'] ] = array();
				$order[] = $a['pillar'];
			}
			$pillars[ $a['pillar'] ][] = $a;
		}
		if ( empty( $order ) ) { return null; }

		// Count existing briefs per pillar so we resume the rotation correctly
		// (the pillar with the fewest briefs so far goes next).
		$brief_counts = array();
		foreach ( $articles as $a ) {
			if ( $a['has_brief'] ) {
				$brief_counts[ $a['pillar'] ] = ( $brief_counts[ $a['pillar'] ] ?? 0 ) + 1;
			}
		}

		$best = null; $best_count = PHP_INT_MAX; $best_pos = PHP_INT_MAX;
		foreach ( $order as $pos => $pillar ) {
			$count = $brief_counts[ $pillar ] ?? 0;
			if ( $count < $best_count || ( $count === $best_count && $pos < $best_pos ) ) {
				$best       = $pillars[ $pillar ][0];
				$best_count = $count;
				$best_pos   = $pos;
			}
		}
		return $best;
	}

	/**
	 * Locate one planned article by id (with pillar context).
	 */
	public static function find_article( $article_id ) {
		foreach ( self::planned_articles() as $a ) {
			if ( $a['id'] === (int) $article_id ) { return $a; }
		}
		return null;
	}

	/**
	 * Build (and save) a brief for a planned article id. Returns brief|WP_Error.
	 */
	public static function build_brief( $article_id ) {
		if ( ! Verlo_Topical_Map::is_approved() ) {
			return new WP_Error( 'verlo_map_not_approved', 'Approve the Topical Map before generating briefs.' );
		}
		if ( ! Verlo_Profile::is_complete() ) {
			return new WP_Error( 'verlo_profile_incomplete', 'Complete the Strategy Profile first.' );
		}
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}

		$article = self::find_article( $article_id );
		if ( ! $article ) {
			return new WP_Error( 'verlo_no_article', 'That planned article is not in the approved map.' );
		}

		$profile    = Verlo_Profile::get();
		$candidates = self::internal_link_candidates( $article['keyword'] );

		$existing_articles = Verlo_Knowledge_Graph::get_titles_sample( 20 );

		$payload = array(
			'profile' => array(
				'niche'              => $profile['niche'],
				'audience'           => $profile['audience'],
				'voice'              => $profile['voice'],
				'monetization_model' => $profile['monetization_model'],
				'geo'                => $profile['geo'],
				'language'           => $profile['language'],
				'constraints'        => $profile['constraints'],
			),
			'article' => array(
				'keyword'     => $article['keyword'],
				'intent'      => $article['intent'],
				'pillar'      => $article['pillar'],
				'pillar_desc' => $article['pillar_desc'],
			),
			'internal_link_candidates' => $candidates,
			'existing_articles'        => $existing_articles,
		);

		$res = Verlo_SaaS_Client::run_job( 'brief', $payload, 60 );
		if ( is_wp_error( $res ) ) { return $res; }

		$brief = self::sanitize_brief( $res, $article, $candidates );
		$brief['meta'] = array( 'generated_at' => time(), 'updated_at' => time() );
		return Verlo_Brief::save( $article_id, $brief );
	}

	/**
	 * Internal-link candidates from the knowledge graph (title + url).
	 */
	public static function internal_link_candidates( $keyword, $limit = 6 ) {
		$rows = Verlo_Knowledge_Graph::related_objects( $keyword, $limit );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'title' => $r['title'], 'url' => $r['url'] );
		}
		return $out;
	}

	/**
	 * Sanitize an AI brief into our schema; only allow internal links whose URL
	 * was actually in the candidate set.
	 */
	protected static function sanitize_brief( $res, $article, $candidates ) {
		$allowed = array();
		foreach ( $candidates as $c ) { $allowed[ $c['url'] ] = $c['title']; }

		$links = array();
		foreach ( (array) ( $res['internal_links'] ?? array() ) as $l ) {
			$url = esc_url_raw( $l['url'] ?? '' );
			if ( $url && isset( $allowed[ $url ] ) ) {
				$links[] = array(
					'url'    => $url,
					'anchor' => sanitize_text_field( $l['anchor'] ?? $allowed[ $url ] ),
				);
			}
		}

		$to_list = function ( $arr ) {
			$out = array();
			foreach ( (array) $arr as $v ) {
				$v = sanitize_text_field( is_string( $v ) ? $v : '' );
				if ( '' !== $v ) { $out[] = $v; }
			}
			return $out;
		};

		return Verlo_Text::humanize_deep( array(
			'keyword'         => $article['keyword'],
			'intent'          => $article['intent'],
			'pillar'          => $article['pillar'],
			'suggested_title' => sanitize_text_field( $res['suggested_title'] ?? '' ),
			'angle'           => sanitize_textarea_field( $res['angle'] ?? '' ),
			'search_intent'   => sanitize_textarea_field( $res['search_intent'] ?? '' ),
			'audience_note'   => sanitize_textarea_field( $res['audience_note'] ?? '' ),
			'outline'         => $to_list( $res['outline'] ?? array() ),
			'internal_links'  => $links,
			'external_ideas'  => $to_list( $res['external_ideas'] ?? array() ),
			'faq'             => $to_list( $res['faq'] ?? array() ),
			'word_count'      => max( 300, (int) ( $res['word_count'] ?? 1500 ) ),
			'voice_note'      => sanitize_textarea_field( $res['voice_note'] ?? '' ),
		) );
	}

	public static function stats() {
		$planned = self::planned_articles();
		$with    = 0;
		foreach ( $planned as $a ) { if ( $a['has_brief'] ) { $with++; } }
		return array(
			'planned'     => count( $planned ),
			'with_brief'  => $with,
			'without'     => count( $planned ) - $with,
		);
	}
}
