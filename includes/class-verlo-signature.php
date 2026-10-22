<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request signing, identical in both directions.
 *
 *   X-Verlo-Timestamp:  seconds since 1970
 *   X-Verlo-Signature:  sha256=<hex HMAC-SHA256 of "<timestamp>.<body>" with the connection key>
 *
 * Verifying is constant-time and rejects anything outside a five minute window, so a captured request cannot be
 * changed or replayed later.
 */
class Verlo_Signature {

	const WINDOW = 300;

	public static function sign( $key, $timestamp, $body ) {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $key );
	}

	public static function headers( $key, $body, $extra = array() ) {
		$ts = time();
		return array_merge(
			array(
				'X-Verlo-Timestamp' => (string) $ts,
				'X-Verlo-Signature' => self::sign( $key, $ts, $body ),
			),
			$extra
		);
	}

	public static function verify( $key, $timestamp, $signature, $body, $now = null ) {
		if ( ! is_string( $key ) || '' === $key || ! is_string( $timestamp ) || ! is_string( $signature ) ) { return false; }
		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) ) { return false; }
		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - (int) $timestamp ) > self::WINDOW ) { return false; }
		return hash_equals( self::sign( $key, $timestamp, $body ), $signature );
	}
}
