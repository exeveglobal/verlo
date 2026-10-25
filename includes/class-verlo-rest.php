<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The endpoints Verlo calls when it sends an article to this site. There is no login: every request must carry a valid
 * signature made with this site's connection key. The signature is checked on the raw body before anything is parsed.
 */
class Verlo_Rest {

	const MAX_BODY = 1500000; // bytes

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'verlo/v1', '/publish', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'publish' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( 'verlo/v1', '/wake', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'wake' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
		register_rest_route( 'verlo/v1', '/ping', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ping' ),
			'permission_callback' => array( __CLASS__, 'authorize' ),
		) );
	}

	/** @return true|WP_Error */
	public static function authorize( $request ) {
		$deny = new WP_Error( 'verlo_unauthorized', 'Invalid or expired signature.', array( 'status' => 401 ) );
		$s    = Verlo_Settings::get();
		if ( ! Verlo_Settings::is_connected() ) { return $deny; }
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) { return new WP_Error( 'verlo_too_large', 'Request too large.', array( 'status' => 413 ) ); }
		$ok = Verlo_Signature::verify( $s['key'], (string) $request->get_header( 'x_verlo_timestamp' ), (string) $request->get_header( 'x_verlo_signature' ), $body );
		return $ok ? true : $deny;
	}

	public static function ping( $request ) {
		Verlo_Settings::update( array( 'last_contact' => time(), 'last_ok' => time(), 'last_error' => '' ) );
		return new WP_REST_Response( array( 'ok' => true, 'plugin' => VERLO_VERSION, 'wp' => get_bloginfo( 'version' ), 'seo' => self::seo_plugin() ), 200 );
	}

	/**
	 * Verlo says an article is waiting. A site that collects its articles would otherwise only find out at its next
	 * scheduled check (up to five minutes later, and only when someone visits the site), so collect now. The request
	 * carries nothing but its signature; what is collected still comes from Verlo and is checked as usual, and a
	 * second wake while a check is running does nothing (the check holds its own lock).
	 */
	public static function wake( $request ) {
		ignore_user_abort( true ); // finish collecting even if Verlo has stopped waiting for the answer
		$res = Verlo_Poller::run();
		return new WP_REST_Response( array( 'ok' => true, 'published' => (int) $res['published'] ), 200 );
	}

	public static function publish( $request ) {
		$payload = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $payload ) ) { return new WP_REST_Response( array( 'error' => 'The request was not valid JSON.' ), 400 ); }
		$key = (string) $request->get_header( 'x_verlo_idempotency_key' );

		$result = Verlo_Publisher::publish( $payload, $key );
		if ( is_wp_error( $result ) ) {
			Verlo_Settings::note_error( $result->get_error_message() );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		Verlo_Settings::update( array(
			'last_contact' => time(),
			'last_ok'      => time(),
			'last_error'   => '',
			'last_article' => array( 'title' => sanitize_text_field( (string) ( $payload['article']['title'] ?? '' ) ), 'at' => time(), 'status' => $result['status'] ),
		) );
		return new WP_REST_Response( $result, 200 );
	}

	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) { return 'yoast'; }
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) { return 'rank_math'; }
		if ( defined( 'SEOPRESS_VERSION' ) ) { return 'seopress'; }
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) { return 'the_seo_framework'; }
		return 'none';
	}
}
