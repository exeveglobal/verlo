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
	/**
	 * Submit-then-poll topical-map generation, same pattern as
	 * Verlo_Generator::do_generate_draft() and Verlo_Strategist::do_build_brief()
	 * (see either docblock for the full "why"). $job_key ('topical-map')
	 * tracks the in-flight SaaS job id across invocations of one queued cycle
	 * via Verlo_Async_Job::get_saas_job()/set_saas_job(). $context carries the
	 * 'force' flag from the original request; reopen() (which flips the map
	 * off 'approved' so the locked-map check below can pass) runs once, only
	 * on the submit step, never repeated on later poll invocations of the
	 * same cycle. Returns the saved map array|WP_Error, including
	 * 'verlo_still_writing' while the job it just submitted or checked on
	 * isn't done yet.
	 */
	protected static function do_generate_map( $job_key, $context ) {
		$job_id = Verlo_Async_Job::get_saas_job( $job_key );

		if ( ! $job_id ) {
			if ( ! empty( $context['force'] ) ) { self::reopen(); }

			$map = self::get();
			if ( 'approved' === $map['status'] ) {
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

			$job_id = Verlo_SaaS_Client::request_job( 'topical-map', $payload );
			if ( is_wp_error( $job_id ) ) {
				$transient = in_array( $job_id->get_error_code(), array( 'verlo_timeout', 'verlo_transport' ), true );
				if ( $transient && self::job_age_s( $job_key ) <= 10 * MINUTE_IN_SECONDS ) {
					return new WP_Error( 'verlo_still_writing', 'Could not reach the Verlo server, will retry: ' . $job_id->get_error_message() );
				}
				return $job_id;
			}

			Verlo_Async_Job::set_saas_job( $job_key, $job_id );
			return new WP_Error( 'verlo_still_writing', 'Topical map job submitted; waiting for the AI to finish.' );
		}

		// A job is already in flight for this cycle. Check it once - never a
		// blocking wait - and only proceed past here if it's genuinely done.
		$poll = Verlo_SaaS_Client::poll_job( $job_id );
		if ( is_wp_error( $poll ) ) {
			if ( self::job_age_s( $job_key ) > 10 * MINUTE_IN_SECONDS ) { return $poll; }
			return new WP_Error( 'verlo_still_writing', 'Status check failed, will retry: ' . $poll->get_error_message() );
		}

		$job_state = isset( $poll['status'] ) ? (string) $poll['status'] : 'unknown';
		if ( 'error' === $job_state ) {
			$msg = isset( $poll['message'] ) ? (string) $poll['message'] : 'Topical map generation failed.';
			return new WP_Error( 'verlo_job_error', $msg );
		}
		if ( 'done' !== $job_state ) {
			return new WP_Error( 'verlo_still_writing', 'Still waiting on the AI (status: ' . $job_state . ').' );
		}

		$result = isset( $poll['result'] ) && is_array( $poll['result'] ) ? $poll['result'] : array();
		$saved  = self::persist_map_result( $result );
		if ( is_wp_error( $saved ) ) { return $saved; }

		Verlo_Async_Job::set_saas_job( $job_key, '' ); // clear on genuine completion
		return $saved;
	}

	/** Seconds since $job_key's current cycle was queued (see Verlo_Async_Job::queue()). */
	protected static function job_age_s( $job_key ) {
		$queued_at = Verlo_Async_Job::get_status( $job_key )['queued_at'];
		return $queued_at ? ( time() - (int) $queued_at ) : 0;
	}

	/**
	 * Turn a completed SaaS topical-map result into a saved map. Split out of
	 * the old single-request generate() so do_generate_map() can call it only
	 * once the AI job is genuinely done, from whichever poll cycle that
	 * happens to be.
	 */
	protected static function persist_map_result( $result ) {
		if ( empty( $result['pillars'] ) || ! is_array( $result['pillars'] ) ) {
			return new WP_Error( 'verlo_bad_map', 'AI did not return any pillars.' );
		}

		$map  = self::get();
		$cats = self::existing_categories();

		// Preserve IDs across regeneration: match new pillars/articles back to
		// the map that existed before this call by normalised name/keyword, and
		// reuse their id. Only genuinely new items get a fresh id, and fresh
		// ids always start above every id ever used so far - never reused for
		// a different keyword later. Without this, ids reset to 1 on every
		// generate() call and briefs/published-post links (stored keyed by
		// article id) silently attach themselves to whatever new keyword ends
		// up at that recycled number.
		$old_pillar_by_name = array();
		$old_article_by_kw  = array();
		$max_pid            = 0;
		$max_aid            = 0;
		foreach ( $map['pillars'] as $op ) {
			$max_pid = max( $max_pid, (int) $op['id'] );
			$old_pillar_by_name[ self::normalise_key( $op['name'] ) ] = (int) $op['id'];
			foreach ( $op['articles'] as $oa ) {
				$max_aid = max( $max_aid, (int) $oa['id'] );
				$old_article_by_kw[ self::normalise_key( $oa['keyword'] ) ] = (int) $oa['id'];
			}
		}
		$next_pid = $max_pid + 1;
		$next_aid = $max_aid + 1;

		$pillars = array();
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

				$norm = self::normalise_key( $kw );
				$id   = isset( $old_article_by_kw[ $norm ] ) ? $old_article_by_kw[ $norm ] : $next_aid++;

				$articles[] = array(
					'id'      => $id,
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

			$pillar_norm = self::normalise_key( $name );
			$pillar_id   = isset( $old_pillar_by_name[ $pillar_norm ] ) ? $old_pillar_by_name[ $pillar_norm ] : $next_pid++;

			$pillars[] = array(
				'id'          => $pillar_id,
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
		$saved = self::save( $map );

		// Not persisted into the stored map (would show stale billing info on
		// every future page load) - just attached to this call's own return
		// value so run_pending() can build an accurate completion message for
		// the generation that just happened.
		if ( isset( $result['billing'] ) && is_array( $result['billing'] ) ) {
			$saved['billing'] = $result['billing'];
		}
		return $saved;
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
	 * Normalise a keyword or pillar name for cross-regeneration identity
	 * matching: lowercase, collapse whitespace, trim trailing punctuation.
	 * Deliberately simple (exact-ish match, not fuzzy) - matching too loosely
	 * risks merging two genuinely different planned articles onto one id,
	 * which would be worse than the bug this is fixing.
	 */
	protected static function normalise_key( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = rtrim( $text, " ?.!" );
		return $text;
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

	/**
	 * Async-job runner for 'topical-map' (see Verlo_Async_Job). $context
	 * carries the 'force' flag from the original request, handed unchanged to
	 * do_generate_map() (reopen() runs once there, only on the submit step).
	 * Message reports wall_clock_s - real elapsed time since this cycle was
	 * queued, not just this invocation's own duration (same reasoning as
	 * Verlo_Strategist::run_pending(), see its docblock).
	 */
	public static function run_pending( $job_key, $context = array() ) {
		$res = self::do_generate_map( $job_key, $context );
		if ( is_wp_error( $res ) ) { return $res; }

		$wall_clock_s = self::job_age_s( $job_key );
		if ( class_exists( 'Verlo_Log' ) ) {
			Verlo_Log::info( 'topical_map.timing', 'Topical map generated in ' . $wall_clock_s . 's', array( 'wall_clock_s' => $wall_clock_s ) );
		}

		$message = 'Draft map generated in ' . $wall_clock_s . 's. Review the pillars below, edit as needed, then Approve.';
		$billing = isset( $res['billing'] ) && is_array( $res['billing'] ) ? $res['billing'] : null;
		if ( $billing ) {
			if ( ! empty( $billing['was_charged'] ) ) {
				$message .= ' You used your ' . (int) ( $billing['free_included_per_month'] ?? 3 )
					. ' free regenerations for this month, so this one used your Verlo wallet'
					. ( isset( $billing['amount_charged_usd'] ) ? ' ($' . number_format( (float) $billing['amount_charged_usd'], 2 ) . ' charged).' : '.' );
			} else {
				$remaining = (int) ( $billing['free_remaining_this_month'] ?? 0 );
				$message  .= ' ' . $remaining . ' free regeneration' . ( 1 === $remaining ? '' : 's' ) . ' left this month.';
			}
		}
		return array(
			'message' => $message,
			'meta'    => array( 'wall_clock_s' => $wall_clock_s ),
		);
	}
}
