<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lightweight, self-contained event log for Verlo. Captures technical detail
 * about AI calls, generation attempts, dispatch mechanics, and failures so we
 * can diagnose problems on real sites (e.g. Anthropic credit exhaustion, rate
 * limits, security-plugin blocks, host timeouts) instead of guessing.
 *
 * Storage: a single capped option (ring buffer), autoload off. No custom table,
 * so it is dependency-free and safe on any host. Sensitive values (API keys)
 * are never logged.
 */
class Verlo_Log {

	const OPT      = 'verlo_event_log';
	const MAX_ROWS = 300; // ring buffer cap

	const DEBUG = 'debug';
	const INFO  = 'info';
	const WARN  = 'warn';
	const ERROR = 'error';

	/**
	 * Record an event.
	 *
	 * @param string $level   one of debug|info|warn|error
	 * @param string $event   short machine-ish event key, e.g. 'api.error'
	 * @param string $message human-readable summary
	 * @param array  $context extra structured detail (auto-sanitised)
	 */
	public static function add( $level, $event, $message, $context = array() ) {
		$rows = self::all();

		$rows[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( self::DEBUG, self::INFO, self::WARN, self::ERROR ), true ) ? $level : self::INFO,
			'event'   => (string) $event,
			'message' => self::truncate( (string) $message, 1000 ),
			'context' => self::scrub_context( $context ),
			'user'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);

		// Cap to the most recent MAX_ROWS.
		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, -self::MAX_ROWS );
		}

		update_option( self::OPT, $rows, 'no' );
	}

	public static function debug( $event, $message, $context = array() ) { self::add( self::DEBUG, $event, $message, $context ); }
	public static function info( $event, $message, $context = array() )  { self::add( self::INFO,  $event, $message, $context ); }
	public static function warn( $event, $message, $context = array() )  { self::add( self::WARN,  $event, $message, $context ); }
	public static function error( $event, $message, $context = array() ) { self::add( self::ERROR, $event, $message, $context ); }

	/**
	 * Log a WP_Error with its code and any HTTP/context data attached.
	 */
	public static function from_wp_error( $event, $wp_error, $context = array() ) {
		$code = is_wp_error( $wp_error ) ? $wp_error->get_error_code() : 'unknown';
		$msg  = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : (string) $wp_error;
		$data = is_wp_error( $wp_error ) ? $wp_error->get_error_data() : null;
		if ( null !== $data ) { $context['error_data'] = $data; }
		$context['error_code'] = $code;
		self::add( self::ERROR, $event, $msg, $context );
	}

	public static function all() {
		$rows = get_option( self::OPT, array() );
		return is_array( $rows ) ? $rows : array();
	}

	public static function recent( $limit = 100 ) {
		$rows = self::all();
		$rows = array_reverse( $rows ); // newest first
		return array_slice( $rows, 0, (int) $limit );
	}

	public static function count() {
		return count( self::all() );
	}

	public static function clear() {
		delete_option( self::OPT );
	}

	/**
	 * Remove sensitive values and shrink large blobs before storing context.
	 */
	protected static function scrub_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array( 'value' => self::truncate( (string) $context, 500 ) );
		}
		$out = array();
		foreach ( $context as $k => $v ) {
			$key = strtolower( (string) $k );
			// Never store secrets.
			if ( false !== strpos( $key, 'api_key' ) || false !== strpos( $key, 'apikey' )
				|| 'key' === $key || false !== strpos( $key, 'token' ) || false !== strpos( $key, 'secret' )
				|| false !== strpos( $key, 'authorization' ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = is_string( $v ) ? self::truncate( $v, 500 ) : $v;
			} else {
				$json = wp_json_encode( $v );
				$out[ $k ] = self::truncate( is_string( $json ) ? $json : '', 800 );
			}
		}
		return $out;
	}

	protected static function truncate( $s, $len ) {
		$s = (string) $s;
		if ( strlen( $s ) <= $len ) { return $s; }
		return substr( $s, 0, $len ) . '… [truncated]';
	}

	/**
	 * Export the full log as JSON (for support/debugging).
	 */
	public static function export_json() {
		return wp_json_encode( array(
			'site'        => home_url(),
			'plugin'      => 'Verlo ' . ( defined( 'VERLO_VERSION' ) ? VERLO_VERSION : '' ),
			'exported_at' => gmdate( 'c' ),
			'events'      => self::all(),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
