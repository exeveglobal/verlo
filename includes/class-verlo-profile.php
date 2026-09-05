<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Site Strategy Profile: the one-time configuration brain that conditions
 * every downstream decision. Stored as JSON (exportable so it templates across
 * sites). Can be filled manually, or pre-filled by AI inference from the graph.
 */
class Verlo_Profile {

	const OPT = 'verlo_profile';

	public static function monetization_models() {
		return array(
			'adsense'  => 'AdSense / display ads: traffic volume is the product (broad informational long-tails)',
			'lead_gen' => 'Lead generation / services: conversions matter (bottom-funnel, strict brand voice)',
			'ecommerce'=> 'E-commerce: product sales (buying guides, comparisons, category content)',
			'authority'=> 'Authority / audience: brand and trust building (depth, original perspective)',
		);
	}

	public static function defaults() {
		return array(
			'site_name'          => get_bloginfo( 'name' ),
			'tagline'            => '',
			'niche'              => '',
			'monetization_model' => 'authority',
			'audience'           => '',
			'voice'              => '',
			'language'           => 'en',
			'geo'                => '',
			'constraints'        => '',
			'meta'               => array(
				'updated_at'  => 0,
				'inferred_at' => 0,
				'version'     => 1,
			),
		);
	}

	public static function get() {
		$saved = get_option( self::OPT, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$profile = wp_parse_args( $saved, self::defaults() );
		$profile['meta'] = wp_parse_args( isset( $saved['meta'] ) ? $saved['meta'] : array(), self::defaults()['meta'] );
		return $profile;
	}

	public static function is_complete() {
		$p = self::get();
		foreach ( array( 'niche', 'audience', 'voice' ) as $f ) {
			if ( '' === trim( (string) $p[ $f ] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Save from a raw (untrusted) associative array; sanitises known fields only.
	 */
	public static function save( $input, $mark = 'manual' ) {
		$current = self::get();
		$models  = array_keys( self::monetization_models() );

		$clean = array(
			'site_name'          => sanitize_text_field( $input['site_name'] ?? $current['site_name'] ),
			'tagline'            => sanitize_text_field( $input['tagline'] ?? $current['tagline'] ),
			'niche'              => sanitize_text_field( $input['niche'] ?? $current['niche'] ),
			'monetization_model' => in_array( ( $input['monetization_model'] ?? '' ), $models, true ) ? $input['monetization_model'] : $current['monetization_model'],
			'audience'           => sanitize_textarea_field( $input['audience'] ?? $current['audience'] ),
			'voice'              => sanitize_textarea_field( $input['voice'] ?? $current['voice'] ),
			'language'           => sanitize_text_field( $input['language'] ?? $current['language'] ),
			'geo'                => sanitize_text_field( $input['geo'] ?? $current['geo'] ),
			'constraints'        => sanitize_textarea_field( $input['constraints'] ?? $current['constraints'] ),
		);

		$meta = $current['meta'];
		$meta['updated_at'] = time();
		if ( 'inferred' === $mark ) { $meta['inferred_at'] = time(); }
		$clean['meta'] = $meta;

		update_option( self::OPT, $clean, 'no' );
		return $clean;
	}

	public static function export_json() {
		return wp_json_encode( self::get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Import a profile from a JSON string. Returns true or WP_Error.
	 */
	public static function import_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'verlo_bad_import', 'That is not valid JSON.' );
		}
		self::save( $data, 'manual' );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * AI inference from the knowledge graph
	 * ------------------------------------------------------------------- */

	/**
	 * Build a compact, low-token snapshot of the site from the graph: a sample of
	 * titles plus the site's top vocabulary. No full post bodies are sent.
	 */
	public static function site_snapshot( $title_limit = 40, $term_limit = 30 ) {
		$titles = Verlo_Knowledge_Graph::get_titles_sample( $title_limit );
		$terms  = Verlo_Knowledge_Graph::get_top_terms( $term_limit );
		return array( 'titles' => $titles, 'terms' => $terms );
	}

	/**
	 * Submit-then-poll AI inference via the Verlo SaaS, same pattern as
	 * Verlo_Generator::do_generate_draft() and Verlo_Strategist::do_build_brief()
	 * (see either docblock for the full "why"). $job_key ('analyze') tracks
	 * the in-flight SaaS job id across invocations of one queued cycle via
	 * Verlo_Async_Job::get_saas_job()/set_saas_job(). Returns
	 * proposed-fields-array|WP_Error, including 'verlo_still_writing' while
	 * the job it just submitted or checked on isn't done yet.
	 */
	protected static function do_infer( $job_key ) {
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}
		if ( ! Verlo_Auth::is_active() ) {
			return new WP_Error( 'verlo_site_paused', 'This site is paused on Verlo because your plan covers fewer sites than you have connected. Re-enable it in your Verlo dashboard, or upgrade to cover more sites.' );
		}

		$job_id = Verlo_Async_Job::get_saas_job( $job_key );

		if ( ! $job_id ) {
			$snap = self::site_snapshot();
			if ( empty( $snap['titles'] ) ) {
				return new WP_Error(
					'verlo_no_content',
					'No content to analyze yet. Fill in the Strategy Profile fields manually (niche, audience, voice, and monetization model), then generate your topical map. You can run Verlo analysis later once the site has published posts.'
				);
			}

			$p = self::get();

			// Build sample_posts from the titles snapshot (titles are all we have from the KG snapshot).
			$sample_posts = array_map( function ( $title ) {
				return array( 'title' => $title, 'categories' => array(), 'word_count' => 0 );
			}, $snap['titles'] );

			$existing_cats = array();
			if ( class_exists( 'Verlo_Topical_Map' ) ) {
				foreach ( Verlo_Topical_Map::existing_categories() as $c ) {
					$existing_cats[] = $c['name'];
				}
			}

			$payload = array(
				'site_url'            => home_url(),
				'sample_posts'        => $sample_posts,
				'existing_categories' => $existing_cats,
				'language'            => $p['language'] ?: 'en',
				'geo'                 => $p['geo'] ?: '',
				'top_terms'           => $snap['terms'],
			);

			$job_id = Verlo_SaaS_Client::request_job( 'analyse', $payload );
			if ( is_wp_error( $job_id ) ) {
				$transient = in_array( $job_id->get_error_code(), array( 'verlo_timeout', 'verlo_transport' ), true );
				if ( $transient && self::job_age_s( $job_key ) <= 10 * MINUTE_IN_SECONDS ) {
					return new WP_Error( 'verlo_still_writing', 'Could not reach the Verlo server, will retry: ' . $job_id->get_error_message() );
				}
				return $job_id;
			}

			Verlo_Async_Job::set_saas_job( $job_key, $job_id );
			return new WP_Error( 'verlo_still_writing', 'Analysis job submitted; waiting for the AI to finish.' );
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
			$msg = isset( $poll['message'] ) ? (string) $poll['message'] : 'Analysis failed.';
			return new WP_Error( 'verlo_job_error', $msg );
		}
		if ( 'done' !== $job_state ) {
			return new WP_Error( 'verlo_still_writing', 'Still waiting on the AI (status: ' . $job_state . ').' );
		}

		$result = isset( $poll['result'] ) && is_array( $poll['result'] ) ? $poll['result'] : array();
		$models = array_keys( self::monetization_models() );

		Verlo_Async_Job::set_saas_job( $job_key, '' ); // clear on genuine completion
		return Verlo_Text::humanize_deep( array(
			'niche'              => sanitize_text_field( $result['niche'] ?? '' ),
			'audience'           => sanitize_textarea_field( $result['audience'] ?? '' ),
			'voice'              => sanitize_textarea_field( $result['voice'] ?? '' ),
			'monetization_model' => in_array( ( $result['monetization_model'] ?? '' ), $models, true ) ? $result['monetization_model'] : 'authority',
			'constraints'        => sanitize_textarea_field( $result['constraints'] ?? '' ),
		) );
	}

	/** Seconds since $job_key's current cycle was queued (see Verlo_Async_Job::queue()). */
	protected static function job_age_s( $job_key ) {
		$queued_at = Verlo_Async_Job::get_status( $job_key )['queued_at'];
		return $queued_at ? ( time() - (int) $queued_at ) : 0;
	}

	/**
	 * Async-job runner for 'analyze' (see Verlo_Async_Job). Wraps do_infer()
	 * with the save() step that used to happen in the browser's own request,
	 * synchronously, right after infer() returned - now it has to happen
	 * here instead, since the browser isn't waiting around for it any more.
	 * Message reports wall_clock_s - real elapsed time since this cycle was
	 * queued, not just this invocation's own duration (same reasoning as
	 * Verlo_Strategist::run_pending(), see its docblock).
	 */
	public static function run_pending( $job_key, $context = array() ) {
		$proposed = self::do_infer( $job_key );
		if ( is_wp_error( $proposed ) ) { return $proposed; }
		self::save( $proposed, 'inferred' );

		$wall_clock_s = self::job_age_s( $job_key );
		if ( class_exists( 'Verlo_Log' ) ) {
			Verlo_Log::info( 'analyze.timing', 'Analysis completed in ' . $wall_clock_s . 's', array( 'wall_clock_s' => $wall_clock_s ) );
		}

		return array(
			'message' => 'Verlo proposed values from your content in ' . $wall_clock_s . 's. Review the fields below and Save profile.',
			'meta'    => array( 'wall_clock_s' => $wall_clock_s ),
		);
	}
}
