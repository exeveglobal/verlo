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
	 * Run AI inference via the Verlo SaaS and return a proposed field array (NOT saved).
	 * Caller reviews before saving.
	 */
	public static function infer() {
		if ( ! Verlo_Auth::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Connect Verlo first under Strategy Profile → Verlo connection.' );
		}

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

		$result = Verlo_SaaS_Client::run_job( 'analyse', $payload, 60 );
		if ( is_wp_error( $result ) ) { return $result; }

		$models = array_keys( self::monetization_models() );
		return Verlo_Text::humanize_deep( array(
			'niche'              => sanitize_text_field( $result['niche'] ?? '' ),
			'audience'           => sanitize_textarea_field( $result['audience'] ?? '' ),
			'voice'              => sanitize_textarea_field( $result['voice'] ?? '' ),
			'monetization_model' => in_array( ( $result['monetization_model'] ?? '' ), $models, true ) ? $result['monetization_model'] : 'authority',
			'constraints'        => sanitize_textarea_field( $result['constraints'] ?? '' ),
		) );
	}
}
