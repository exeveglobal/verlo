<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * THE gateway between the plugin and the Verlo SaaS.
 *
 * All SaaS communication goes through this class. Nothing else in the plugin
 * makes HTTP calls for AI purposes.
 *
 * Job flow:
 *   1. request_job($type, $payload) → POST /v1/jobs/:type → job_id (immediate)
 *   2. poll_job($job_id)           → GET  /v1/jobs/:id   → status
 *   3. wait_for_result($job_id)    → polls until done or timeout
 *   4. run_job($type, $payload)    → request + wait in one call (convenience)
 *
 * Job types: analyse · topical-map · brief · article
 */
class Verlo_SaaS_Client {

	const DEFAULT_SAAS_URL = 'https://api.verlo.app';

	/**
	 * Submit a job. Returns job_id (string) or WP_Error.
	 * Handles 401 by attempting one token refresh + retry.
	 */
	public static function request_job( $type, $payload, $retry = true ) {
		$token = Verlo_Auth::token();
		if ( is_wp_error( $token ) ) { return $token; }

		$payload['site_id'] = Verlo_Auth::site_id();

		$url      = self::base_url() . '/v1/jobs/' . rawurlencode( $type );
		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body' => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === (int) $code && $retry ) {
			// Token rejected — refresh once and retry.
			$refreshed = Verlo_Auth::refresh();
			if ( is_wp_error( $refreshed ) ) { return $refreshed; }
			return self::request_job( $type, $payload, false );
		}

		if ( 403 === (int) $code ) {
			$msg = isset( $data['message'] ) ? $data['message'] : 'Plan limit reached. Upgrade your Verlo plan.';
			return new WP_Error( 'verlo_plan_limit', $msg );
		}

		if ( (int) $code < 200 || (int) $code >= 300 ) {
			$msg = isset( $data['message'] ) ? $data['message'] : ( 'Request failed (HTTP ' . (int) $code . ').' );
			return new WP_Error( 'verlo_request_failed', $msg );
		}

		$job_id = isset( $data['job_id'] ) ? (string) $data['job_id'] : '';
		if ( '' === $job_id ) {
			return new WP_Error( 'verlo_no_job_id', 'Verlo server did not return a job ID.' );
		}

		Verlo_Log::info( 'saas.job_queued', 'Job submitted', array(
			'job_type' => $type,
			'job_id'   => $job_id,
			'site_id'  => $payload['site_id'] ?? '',
		) );

		return $job_id;
	}

	/**
	 * Poll a job once. Returns status array or WP_Error.
	 *
	 * Status array shapes:
	 *   ['status' => 'queued'|'running', 'progress' => '...']
	 *   ['status' => 'done',   'result'  => [...]]
	 *   ['status' => 'error',  'message' => '...']
	 */
	public static function poll_job( $job_id ) {
		$token = Verlo_Auth::token();
		if ( is_wp_error( $token ) ) { return $token; }

		$url      = self::base_url() . '/v1/jobs/' . rawurlencode( $job_id );
		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( (int) $code < 200 || (int) $code >= 300 ) {
			$msg = isset( $data['message'] ) ? $data['message'] : ( 'Poll failed (HTTP ' . (int) $code . ').' );
			return new WP_Error( 'verlo_poll_failed', $msg );
		}

		return is_array( $data ) ? $data : array( 'status' => 'unknown' );
	}

	/**
	 * Submit a job and wait for the result (blocking poll).
	 * Use from background workers — there is no browser timeout constraint there.
	 * Returns result array or WP_Error.
	 */
	public static function run_job( $type, $payload, $timeout_s = 60 ) {
		$job_id = self::request_job( $type, $payload );
		if ( is_wp_error( $job_id ) ) { return $job_id; }
		return self::wait_for_result( $job_id, $timeout_s, $type );
	}

	/**
	 * Block and poll until a job is done, returns an error, or the timeout fires.
	 * Returns the result array or WP_Error.
	 *
	 * Poll interval: 2s. On transient network errors, retries up to 3 times
	 * before giving up (the deadline still applies).
	 */
	public static function wait_for_result( $job_id, $timeout_s = 60, $job_type = '' ) {
		$interval     = 2;       // seconds between polls
		$deadline     = time() + (int) $timeout_s;
		$net_failures = 0;
		$polls        = 0;

		while ( time() < $deadline ) {
			$polls++;
			$status = self::poll_job( $job_id );

			if ( is_wp_error( $status ) ) {
				$net_failures++;
				if ( $net_failures >= 3 ) { return $status; }
				sleep( $interval );
				continue;
			}
			$net_failures = 0; // reset on successful poll

			$state = isset( $status['status'] ) ? (string) $status['status'] : 'unknown';

			if ( 'done' === $state ) {
				$result = isset( $status['result'] ) ? $status['result'] : null;
				if ( ! is_array( $result ) ) {
					return new WP_Error( 'verlo_bad_result', 'Verlo returned a done status but no result.' );
				}
				Verlo_Log::info( 'saas.job_done', 'Job completed', array(
					'job_id'   => $job_id,
					'job_type' => $job_type,
					'polls'    => $polls,
				) );
				return $result;
			}

			if ( 'error' === $state ) {
				$msg = isset( $status['message'] ) ? (string) $status['message'] : 'Job failed. Please try again.';
				Verlo_Log::error( 'saas.job_error', 'Job failed', array(
					'job_id'   => $job_id,
					'job_type' => $job_type,
					'message'  => $msg,
				) );
				return new WP_Error( 'verlo_job_error', $msg );
			}

			// queued or running — wait and poll again
			sleep( $interval );
		}

		Verlo_Log::warn( 'saas.job_timeout', 'Timed out waiting for job', array(
			'job_id'     => $job_id,
			'job_type'   => $job_type,
			'timeout_s'  => $timeout_s,
			'polls'      => $polls,
		) );

		return new WP_Error(
			'verlo_timeout',
			'The Verlo server took too long to respond. The operation may complete in the background — check back in a moment.'
		);
	}

	/**
	 * Base URL for all SaaS requests.
	 * Override via VERLO_SAAS_URL constant (for local dev) or settings.
	 */
	public static function base_url() {
		if ( defined( 'VERLO_SAAS_URL' ) ) {
			return rtrim( VERLO_SAAS_URL, '/' );
		}
		$s = verlo_get_settings();
		$url = isset( $s['saas_url'] ) ? trim( $s['saas_url'] ) : '';
		return rtrim( $url ?: self::DEFAULT_SAAS_URL, '/' );
	}

	/** Map transport-level errors to user-friendly WP_Error. */
	protected static function transport_error( $err ) {
		$msg = $err->get_error_message();
		if ( false !== stripos( $msg, 'cURL error 28' ) || false !== stripos( $msg, 'timed out' ) ) {
			return new WP_Error( 'verlo_timeout', 'Could not reach the Verlo server (connection timed out). Check your internet connection and try again.' );
		}
		return new WP_Error( 'verlo_transport', 'Could not reach the Verlo server: ' . $msg );
	}
}
