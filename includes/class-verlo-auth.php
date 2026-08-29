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

		// Store license key encrypted at rest where possible (see
		// encrypt_license_key() below). Purpose: auto-refresh when the token
		// expires without asking the user to re-enter the key.
		update_option( self::OPT_LK, self::encrypt_license_key( $license_key ), 'no' );

		Verlo_Log::info( 'auth.verified', 'Verlo connected', array(
			'site_id' => $data['site_id'] ?? '',
			'plan'    => $data['plan'] ?? '',
		) );

		return $data;
	}

	/**
	 * Complete the "Connect with Verlo" redirect flow: exchange the one-time
	 * claim token (handed back by the dashboard after the user authorized the
	 * connection) for the actual license key, then run it through the exact
	 * same verify() every manual connection already goes through. Returns the
	 * full response array or WP_Error.
	 */
	public static function connect_via_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'verlo_no_token', 'Missing connection token.' );
		}

		$url      = Verlo_SaaS_Client::base_url() . '/v1/auth/plugin-connect-exchange';
		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'token' => $token ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'verlo_transport',
				'Could not reach the Verlo server: ' . $response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['license_key'] ) ) {
			$msg = isset( $data['message'] ) ? $data['message'] : ( 'Connection failed (HTTP ' . (int) $code . ').' );
			return new WP_Error( 'verlo_connect_failed', $msg );
		}

		return self::verify( (string) $data['license_key'] );
	}

	/**
	 * Re-verify using the stored license key. Called automatically on token expiry.
	 */
	public static function refresh() {
		$lk_enc = (string) get_option( self::OPT_LK, '' );
		if ( '' === $lk_enc ) {
			return new WP_Error( 'verlo_no_key', 'No license key stored. Please reconnect Verlo.' );
		}
		$license_key = self::decrypt_license_key( $lk_enc );
		if ( '' === $license_key ) {
			return new WP_Error( 'verlo_no_key', 'Stored license key could not be read. Please reconnect Verlo.' );
		}
		return self::verify( $license_key );
	}

	/**
	 * Encrypts the license key with a per-site key derived from wp_salt(),
	 * so a leaked DB backup or another plugin reading wp_options can't
	 * trivially recover it the way a plain base64 encoding could. Falls back
	 * to the previous base64-only encoding if the openssl extension isn't
	 * available — WordPress has no stronger secret-storage primitive to
	 * reach for here, so this is the best available improvement, not a claim
	 * of airtight secrecy against someone who already has DB *and* code
	 * access on the same box.
	 */
	private static function encrypt_license_key( $license_key ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $license_key );
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $license_key, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return 'b64:' . base64_encode( $license_key );
		}
		return 'enc1:' . base64_encode( $iv . $ct );
	}

	/**
	 * Reverses encrypt_license_key(), and also reads the plain-base64 format
	 * used before this was added (no prefix) so already-connected sites keep
	 * working without needing to reconnect. Returns '' on any failure.
	 */
	private static function decrypt_license_key( $stored ) {
		if ( 0 === strpos( $stored, 'enc1:' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
			$raw = base64_decode( substr( $stored, 5 ) );
			if ( false === $raw || strlen( $raw ) < 17 ) { return ''; }
			$iv  = substr( $raw, 0, 16 );
			$ct  = substr( $raw, 16 );
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $pt ? '' : $pt;
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$pt = base64_decode( substr( $stored, 4 ) );
			return false === $pt ? '' : $pt;
		}
		// Legacy value from before this change: plain base64, no prefix.
		$pt = base64_decode( $stored );
		return false === $pt ? '' : $pt;
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

	/**
	 * Best-effort release of this site on the Verlo SaaS side, so the same
	 * URL can be connected under a different account. Called from the admin
	 * "Disconnect" handler BEFORE the local auth data is cleared — that local
	 * clear happens regardless of what this returns.
	 *
	 * Returns true when the SaaS released the site (or there was nothing to
	 * release), or a WP_Error describing why it did not:
	 *   - 'verlo_free_plan_no_release' — the account is on Free; the site
	 *     stays linked server-side (Free is one site for the life of the
	 *     account). Not really a failure, just a no-op with a reason.
	 *   - 'verlo_transport' / 'verlo_release_failed' — could not reach the
	 *     server, or it returned an unexpected status.
	 */
	public static function release_remote() {
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( '' === $token ) {
			// Never connected, or already cleared — nothing to release.
			return true;
		}

		$url      = Verlo_SaaS_Client::base_url() . '/v1/auth/disconnect';
		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( array( 'source' => 'plugin' ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'verlo_transport',
				'Could not reach the Verlo server to release this site: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			Verlo_Log::info( 'auth.released', 'Site released on Verlo' );
			return true;
		}

		if ( 403 === $code ) {
			$msg = isset( $data['message'] )
				? (string) $data['message']
				: 'Moving a site to another Verlo account is available on paid plans.';
			return new WP_Error( 'verlo_free_plan_no_release', $msg );
		}

		$msg = isset( $data['message'] ) ? (string) $data['message'] : ( 'Verlo server returned HTTP ' . $code . '.' );
		return new WP_Error( 'verlo_release_failed', $msg );
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
