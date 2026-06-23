<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Topical Map: pillars (-> categories) and clusters of planned articles.
 * This IS the forward content roadmap. Governance baked in:
 *  - a pillar is only valid with a minimum planned cluster behind it (no
 *    single-post categories, no category spam)
 *  - generation elsewhere is gated on an APPROVED map
 *  - approval applies ADDITIVE structure only (creates missing categories);
 *    nothing is ever merged/renamed/deleted automatically.
 */
class Verlo_Topical_Map {

	const OPT          = 'verlo_topical_map';
	const MIN_CLUSTER  = 3;  // minimum planned articles to justify a pillar/category

	public static function defaults() {
		return array(
			'status'       => 'none', // none | draft | approved
			'generated_at' => 0,
			'approved_at'  => 0,
			'pillars'      => array(),
			'audit'        => array(),
		);
	}

	public static function get() {
		$saved = get_option( self::OPT, array() );
		$map   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( ! is_array( $map['pillars'] ) ) { $map['pillars'] = array(); }
		if ( ! is_array( $map['audit'] ) ) { $map['audit'] = array(); }
		return $map;
	}

	public static function save( $map ) {
		update_option( self::OPT, $map, 'no' );
		return $map;
	}

	public static function is_approved() {
		return 'approved' === self::get()['status'];
	}

	/* ---------------------------------------------------------------------
	 * AI generation
	 * ------------------------------------------------------------------- */

	/**
	 * Generate a draft map with AI from the profile + graph + existing
	 * categories. Replaces any existing draft; never touches an approved map
	 * unless $force.
	 */
	public static function generate( $force = false ) {
		$map = self::get();
		if ( 'approved' === $map['status'] && ! $force ) {
			return new WP_Error( 'verlo_map_locked', 'The map is approved. Set it back to draft before regenerating.' );
		}
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}
		if ( ! Verlo_Profile::is_complete() ) {
			return new WP_Error( 'verlo_profile_incomplete', 'Complete the Strategy Profile first (niche, audience, voice).' );
		}

		$snap = Verlo_Profile::site_snapshot( 40, 30 );
		// No content guard here — a new site with zero posts is valid. The SaaS
		// generates the content roadmap from the profile alone; empty covered_topics
		// simply means nothing is pre-covered, which is correct for a fresh site.

		$profile = Verlo_Profile::get();
		$cats    = self::existing_categories();

		$cat_names = array_map( function ( $c ) { return $c['name']; }, $cats );

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
			'knowledge_graph_summary' => array(
				'covered_topics'      => $snap['titles'],
				'site_titles'         => $snap['titles'],
				'top_terms'           => $snap['terms'],
				'existing_categories' => $cat_names,
				'coverage_gaps'       => array(),
				'total_posts'         => count( $snap['titles'] ),
			),
		);

		$result = Verlo_SaaS_Client::run_job( 'topical-map', $payload, 90 );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['pillars'] ) || ! is_array( $result['pillars'] ) ) {
			return new WP_Error( 'verlo_bad_map', 'AI did not return any pillars.' );
		}

		$pillars = array();
		$pid     = 1;
		$aid     = 1;
		foreach ( $result['pillars'] as $rp ) {
			$name = sanitize_text_field( $rp['name'] ?? '' );
			if ( '' === $name ) { continue; }

			$articles = array();
			foreach ( (array) ( $rp['articles'] ?? array() ) as $ra ) {
				$kw = sanitize_text_field( $ra['keyword'] ?? '' );
				if ( '' === $kw ) { continue; }
				$kw = self::scrub_stale_years( $kw );
				$kw = Verlo_Text::humanize( $kw );
				$intent = in_array( ( $ra['intent'] ?? '' ), array( 'informational', 'commercial', 'transactional', 'navigational' ), true ) ? $ra['intent'] : 'informational';
				$articles[] = array(
					'id'      => $aid++,
					'keyword' => $kw,
					'intent'  => $intent,
					'status'  => 'planned', // planned | covered | drafted | published
				);
			}

			// Governance: drop pillars that can't justify a category.
			if ( count( $articles ) < self::MIN_CLUSTER ) { continue; }

			$existing_name = sanitize_text_field( $rp['existing_category'] ?? '' );
			$existing_id   = 0;
			if ( '' !== $existing_name ) {
				foreach ( $cats as $c ) {
					if ( 0 === strcasecmp( $c['name'], $existing_name ) ) { $existing_id = (int) $c['term_id']; break; }
				}
			}

			$pillars[] = array(
				'id'          => $pid++,
				'name'        => Verlo_Text::humanize( $name ),
				'description' => Verlo_Text::humanize( sanitize_text_field( $rp['description'] ?? '' ) ),
				'category_id' => $existing_id, // 0 => to be created on approval
				'articles'    => $articles,
			);
		}

		if ( empty( $pillars ) ) {
			return new WP_Error( 'verlo_bad_map', 'No pillar met the minimum cluster size of ' . self::MIN_CLUSTER . '.' );
		}

		$map = array(
			'status'       => 'draft',
			'generated_at' => time(),
			'approved_at'  => 0,
			'pillars'      => self::mark_coverage( $pillars ),
			'audit'        => self::audit_categories( $pillars ),
		);
		return self::save( $map );
	}

	/**
	 * Deterministic guard: strip any past year from a keyword, whatever the AI
	 * returned. The current year is allowed (freshness terms); anything older
	 * is removed because stale-dated keywords plan content for dead demand.
	 * Delegates to the shared, prose-safe Verlo_Text::scrub_stale_years().
	 */
	public static function scrub_stale_years( $keyword ) {
		return Verlo_Text::scrub_stale_years( $keyword );
	}

	/**
	 * Mark planned articles that the site effectively already covers, using the
	 * graph's distinctive-term coverage check (free). On niche sites this
	 * ignores ubiquitous niche vocabulary and only marks "covered" when an
	 * existing post matches the keyword's distinguishing terms.
	 */
	public static function mark_coverage( $pillars ) {
		foreach ( $pillars as &$p ) {
			foreach ( $p['articles'] as &$a ) {
				$check = Verlo_Knowledge_Graph::coverage_check( $a['keyword'] );
				if ( ! empty( $check['covered'] ) ) {
					$a['status']      = 'covered';
					$a['covered_by']  = $check['url'];
					$a['cover_title'] = $check['title'];
					$a['cover_match'] = round( $check['ratio'] * 100 ) . '% of distinctive terms: ' . implode( ', ', $check['matched'] );
				}
			}
		}
		unset( $p, $a );
		return $pillars;
	}

	/**
	 * Audit existing categories against the planned map (pure PHP, free):
	 *  - in_map: category is reused as a pillar -> keep
	 *  - empty/thin and not in map -> review (merge or retire), HUMAN decision
	 *  - has traffic-bearing content -> always keep-flagged, never auto-touch
	 */
	public static function audit_categories( $pillars ) {
		$in_map = array();
		foreach ( $pillars as $p ) {
			if ( ! empty( $p['category_id'] ) ) { $in_map[ (int) $p['category_id'] ] = true; }
		}

		$audit = array();
		foreach ( self::existing_categories() as $c ) {
			$tid = (int) $c['term_id'];
			if ( isset( $in_map[ $tid ] ) ) {
				if ( 0 === (int) $c['count'] ) {
					$verdict = 'keep';
					$note    = 'New pillar category. No posts yet; planned articles will populate it.';
				} else {
					$verdict = 'keep';
					$note    = 'Reused as a pillar (' . (int) $c['count'] . ' existing posts).';
				}
			} elseif ( 0 === (int) $c['count'] ) {
				$verdict = 'review';
				$note    = 'Empty category, not in the map. Candidate to retire (manual action; not automated).';
			} elseif ( (int) $c['count'] < self::MIN_CLUSTER ) {
				$verdict = 'review';
				$note    = 'Thin category (' . (int) $c['count'] . ' posts), not in the map. Consider merging its posts into a pillar (manual action).';
			} else {
				$verdict = 'keep';
				$note    = 'Populated category outside the map. Left untouched.';
			}
			$audit[] = array(
				'term_id' => $tid,
				'name'    => $c['name'],
				'count'   => (int) $c['count'],
				'verdict' => $verdict,
				'note'    => $note,
			);
		}
		return $audit;
	}

	public static function existing_categories() {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		$out   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( 'uncategorized' === $t->slug ) { continue; }
				$out[] = array( 'term_id' => (int) $t->term_id, 'name' => $t->name, 'count' => (int) $t->count );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Editing (review phase)
	 * ------------------------------------------------------------------- */

	public static function delete_pillar( $pillar_id ) {
		$map = self::get();
		$map['pillars'] = array_values( array_filter( $map['pillars'], function ( $p ) use ( $pillar_id ) {
			return (int) $p['id'] !== (int) $pillar_id;
		} ) );
		return self::save( $map );
	}

	public static function delete_article( $article_id ) {
		$map = self::get();
		foreach ( $map['pillars'] as &$p ) {
			$p['articles'] = array_values( array_filter( $p['articles'], function ( $a ) use ( $article_id ) {
				return (int) $a['id'] !== (int) $article_id;
			} ) );
		}
		unset( $p );
		return self::save( $map );
	}

	public static function add_article( $pillar_id, $keyword, $intent = 'informational' ) {
		$map = self::get();
		$max = 0;
		foreach ( $map['pillars'] as $p ) {
			foreach ( $p['articles'] as $a ) { $max = max( $max, (int) $a['id'] ); }
		}

		$keyword = self::scrub_stale_years( sanitize_text_field( $keyword ) );

		// Manually added keywords get the SAME coverage check as generated ones.
		$article = array(
			'id'      => $max + 1,
			'keyword' => $keyword,
			'intent'  => in_array( $intent, array( 'informational', 'commercial', 'transactional', 'navigational' ), true ) ? $intent : 'informational',
			'status'  => 'planned',
		);
		$check = Verlo_Knowledge_Graph::coverage_check( $keyword );
		if ( ! empty( $check['covered'] ) ) {
			$article['status']      = 'covered';
			$article['covered_by']  = $check['url'];
			$article['cover_title'] = $check['title'];
			$article['cover_match'] = round( $check['ratio'] * 100 ) . '% of distinctive terms: ' . implode( ', ', $check['matched'] );
		}

		foreach ( $map['pillars'] as &$p ) {
			if ( (int) $p['id'] === (int) $pillar_id ) {
				$p['articles'][] = $article;
				break;
			}
		}
		unset( $p );
		return self::save( $map );
	}

	public static function add_pillar( $name, $description = '' ) {
		$map = self::get();
		$max = 0;
		foreach ( $map['pillars'] as $p ) { $max = max( $max, (int) $p['id'] ); }
		$map['pillars'][] = array(
			'id'          => $max + 1,
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_text_field( $description ),
			'category_id' => 0,
			'articles'    => array(),
		);
		return self::save( $map );
	}

	/* ---------------------------------------------------------------------
	 * Approval gate
	 * ------------------------------------------------------------------- */

	/**
	 * Validate and approve the map. ADDITIVE side effect only: creates WP
	 * categories for pillars that don't have one. Returns array of created
	 * category names, or WP_Error if governance fails.
	 */
	public static function approve() {
		$map = self::get();
		if ( 'draft' !== $map['status'] ) {
			return new WP_Error( 'verlo_not_draft', 'Only a draft map can be approved.' );
		}
		if ( empty( $map['pillars'] ) ) {
			return new WP_Error( 'verlo_empty_map', 'The map has no pillars.' );
		}
		foreach ( $map['pillars'] as $p ) {
			$plannable = count( $p['articles'] );
			if ( $plannable < self::MIN_CLUSTER ) {
				return new WP_Error(
					'verlo_thin_pillar',
					sprintf( 'Pillar "%s" has only %d planned article(s); minimum is %d. Add articles or delete the pillar.', $p['name'], $plannable, self::MIN_CLUSTER )
				);
			}
		}

		$created = array();
		foreach ( $map['pillars'] as &$p ) {
			if ( empty( $p['category_id'] ) ) {
				$existing = get_term_by( 'name', $p['name'], 'category' );
				if ( $existing instanceof WP_Term ) {
					$p['category_id'] = (int) $existing->term_id;
				} else {
					$res = wp_insert_term( $p['name'], 'category', array( 'description' => $p['description'] ) );
					if ( ! is_wp_error( $res ) && isset( $res['term_id'] ) ) {
						$p['category_id'] = (int) $res['term_id'];
						$created[]        = $p['name'];
					}
				}
			}
		}
		unset( $p );

		$map['status']      = 'approved';
		$map['approved_at'] = time();
		// Refresh the audit: newly created categories must show up as
		// "reused as a pillar", not be invisible to the audit snapshot.
		$map['audit'] = self::audit_categories( $map['pillars'] );
		self::save( $map );

		// Keep briefs in sync: drop any whose planned article no longer exists.
		if ( class_exists( 'Verlo_Brief' ) ) {
			$valid = array();
			foreach ( $map['pillars'] as $p ) {
				foreach ( $p['articles'] as $a ) { $valid[] = (int) $a['id']; }
			}
			Verlo_Brief::prune( $valid );
		}
		return $created;
	}

	/**
	 * Reopen for editing. Categories already created are left in place
	 * (subtractive changes stay manual, per governance).
	 */
	public static function reopen() {
		$map = self::get();
		$map['status']      = 'draft';
		$map['approved_at'] = 0;
		return self::save( $map );
	}

	public static function stats() {
		$map = self::get();
		$planned = 0; $covered = 0;
		foreach ( $map['pillars'] as $p ) {
			foreach ( $p['articles'] as $a ) {
				if ( 'covered' === $a['status'] ) { $covered++; } else { $planned++; }
			}
		}
		return array(
			'pillars' => count( $map['pillars'] ),
			'planned' => $planned,
			'covered' => $covered,
			'thin'    => self::thin_pillars(),
		);
	}

	/**
	 * Names of pillars below the minimum cluster size (blockers for approval).
	 */
	public static function thin_pillars() {
		$thin = array();
		foreach ( self::get()['pillars'] as $p ) {
			if ( count( $p['articles'] ) < self::MIN_CLUSTER ) {
				$thin[] = $p['name'];
			}
		}
		return $thin;
	}
}
