<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Manages the connection between the plugin and the Verlo SaaS.
 *
 * Auth flow:
 *   1. User enters license key → Verlo_Auth::verify() → POST /v1/auth/verify
 *   2. SaaS returns JWT + site_id + plan → stored in wp_options (autoload off)
 *   3. Each SaaS request uses Authorization: Bearer {token}
 *   4. Token expires after 7 days → auto-refresh via stored license key
 *   5. On manual disconnect, all auth options are cleared
 */
class Verlo_Auth {

	const OPT_TOKEN      = 'verlo_saas_token';
	const OPT_SITE_ID    = 'verlo_saas_site_id';
	const OPT_PLAN       = 'verlo_saas_plan';
	const OPT_FEATURES   = 'verlo_saas_features';
	const OPT_EXPIRES_AT = 'verlo_saas_expires_at';
	const OPT_LK         = 'verlo_license_key'; // stored for auto-refresh on token expiry

	/**
	 * Get the current JWT token. Auto-refreshes if within 1 hour of expiry.
	 * Returns token string or WP_Error.
	 */
	public static function token() {
		$token      = (string) get_option( self::OPT_TOKEN, '' );
		$expires_at = (int) get_option( self::OPT_EXPIRES_AT, 0 );

		if ( '' === $token ) {
			return new WP_Error(
				'verlo_not_connected',
				'Verlo is not connected. Enter your license key under Strategy Profile → Verlo connection.'
			);
		}

		// Attempt auto-refresh when within 1 hour of expiry.
		if ( $expires_at && time() > ( $expires_at - HOUR_IN_SECONDS ) ) {
			$refreshed = self::refresh();
			if ( ! is_wp_error( $refreshed ) ) {
				return (string) get_option( self::OPT_TOKEN, '' );
			}
			// Refresh failed — use the current token if it is still valid.
			if ( $expires_at && time() < $expires_at ) {
				return $token;
			}
			return new WP_Error( 'verlo_token_expired', 'Verlo connection expired. Reconnect under Strategy Profile.' );
		}

		return $token;
	}

	/**
	 * Connect with a license key. Calls POST /v1/auth/verify, stores auth data.
	 * Returns the full response array or WP_Error.
	 */
	public static function verify( $license_key ) {
		$license_key = trim( (string) $license_key );
		if ( '' === $license_key ) {
			return new WP_Error( 'verlo_no_key', 'Enter a license key.' );
		}

		$url      = Verlo_SaaS_Client::base_url() . '/v1/auth/verify';
		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'site_url'       => home_url(),
				'license_key'    => $license_key,
				'plugin_version' => VERLO_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'verlo_transport',
				'Could not reach the Verlo server: ' . $response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['valid'] ) ) {
			$msg = isset( $data['message'] ) ? $data['message'] : ( 'Connection failed (HTTP ' . (int) $code . ').' );
			return new WP_Error( 'verlo_auth_failed', $msg );
		}

		// Persist auth data (autoload off — only loaded when needed).
		update_option( self::OPT_TOKEN,      $data['token'],                       'no' );
		update_option( self::OPT_SITE_ID,    $data['site_id'],                     'no' );
		update_option( self::OPT_PLAN,       $data['plan'],                        'no' );
		update_option( self::OPT_FEATURES,   $data['features'] ?? array(),         'no' );
		update_option( self::OPT_EXPIRES_AT, strtotime( $data['expires_at'] ?? '' ) ?: 0, 'no' );

		// Store license key (base64 only — not encryption, but avoids plaintext in DB view).
		// Purpose: auto-refresh when token expires without asking user to re-enter the key.
		update_option( self::OPT_LK, base64_encode( $license_key ), 'no' );

		Verlo_Log::info( 'auth.verified', 'Verlo connected', array(
			'site_id' => $data['site_id'] ?? '',
			'plan'    => $data['plan'] ?? '',
		) );

		return $data;
	}

	/**
	 * Re-verify using the stored license key. Called automatically on token expiry.
	 */
	public static function refresh() {
		$lk_enc = (string) get_option( self::OPT_LK, '' );
		if ( '' === $lk_enc ) {
			return new WP_Error( 'verlo_no_key', 'No license key stored. Please reconnect Verlo.' );
		}
		$license_key = base64_decode( $lk_enc );
		return self::verify( $license_key );
	}

	/** True if a token is stored and not expired. */
	public static function is_connected() {
		$token      = (string) get_option( self::OPT_TOKEN, '' );
		$expires_at = (int) get_option( self::OPT_EXPIRES_AT, 0 );
		if ( '' === $token ) { return false; }
		if ( $expires_at && time() >= $expires_at ) { return false; }
		return true;
	}

	public static function site_id() {
		return (string) get_option( self::OPT_SITE_ID, '' );
	}

	public static function plan() {
		return (string) get_option( self::OPT_PLAN, 'free' );
	}

	public static function features() {
		$f = get_option( self::OPT_FEATURES, array() );
		return is_array( $f ) ? $f : array();
	}

	public static function has_feature( $feature ) {
		return in_array( $feature, self::features(), true );
	}

	/** Clear all auth data (user-initiated disconnect). */
	public static function disconnect() {
		delete_option( self::OPT_TOKEN );
		delete_option( self::OPT_SITE_ID );
		delete_option( self::OPT_PLAN );
		delete_option( self::OPT_FEATURES );
		delete_option( self::OPT_EXPIRES_AT );
		delete_option( self::OPT_LK );
		Verlo_Log::info( 'auth.disconnected', 'Verlo disconnected' );
	}
}
