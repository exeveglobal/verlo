<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Environment diagnostics for Verlo. Determines whether this site can run
 * background work (loopback requests / WP-Cron), so the plugin can:
 *   - run fully automatically where the environment allows it, and
 *   - where it does NOT, start its in-request fallback sooner AND warn the user
 *     with clear guidance (rather than silently being slow).
 *
 * The result is cached, and a lightweight self-test is performed in the
 * background so the very first check doesn't block the user.
 */
class Verlo_Env {

	const OPT_HEALTH = 'verlo_bg_health';   // cached health result
	const PROBE_TTL  = 0;                    // health is sticky until re-probed

	/**
	 * Cached background-health state.
	 * Returns: [ 'loopback' => 'ok'|'blocked'|'unknown', 'cron' => 'ok'|'disabled'|'unknown',
	 *            'checked_at' => ts, 'mode' => 'auto'|'assisted'|'unknown' ].
	 * 'mode' = auto    -> background works, no user action needed
	 *          assisted -> background blocked, the open tab drives work + we warn
	 *          unknown  -> not probed yet
	 */
	public static function health() {
		$h = get_option( self::OPT_HEALTH, array() );
		return wp_parse_args( is_array( $h ) ? $h : array(), array(
			'loopback'   => 'unknown',
			'cron'       => 'unknown',
			'checked_at' => 0,
			'mode'       => 'unknown',
		) );
	}

	public static function is_assisted() {
		return 'assisted' === self::health()['mode'];
	}

	public static function is_known() {
		return 'unknown' !== self::health()['mode'];
	}

	/**
	 * How long the poll-driven self-heal should wait before taking over the work
	 * itself. When we already know background dispatch is blocked on this site,
	 * we take over almost immediately (no point waiting). When background is
	 * known-good, we give it room. When unknown, a middle value.
	 */
	public static function self_heal_delay() {
		$mode = self::health()['mode'];
		if ( 'assisted' === $mode ) { return 6; }   // background blocked: take over fast
		if ( 'auto' === $mode )     { return 30; }  // background good: let it work
		return 15;                                   // unknown: middle ground
	}

	/**
	 * Record cron availability (cheap, synchronous, always knowable).
	 */
	public static function cron_state() {
		return ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'disabled' : 'ok';
	}

	/**
	 * Fire a one-time, non-blocking loopback self-test. The test endpoint sets a
	 * transient; if it appears shortly after, loopback works. This runs in the
	 * background and never blocks the user.
	 */
	public static function start_probe() {
		// Don't re-probe constantly; once per 6 hours unless forced.
		$h = self::health();
		if ( 'unknown' !== $h['mode'] && ( time() - (int) $h['checked_at'] ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}

		$token = wp_generate_password( 20, false );
		set_transient( 'verlo_probe_token', $token, 5 * MINUTE_IN_SECONDS );
		delete_transient( 'verlo_probe_result' );
		update_option( 'verlo_probe_started', time(), 'no' );

		$url = add_query_arg( 'action', 'verlo_bg_probe', admin_url( 'admin-post.php' ) );
		wp_remote_post( $url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => array( 'cookie' => '' ),
			'body'      => array( 'token' => $token ),
		) );
	}

	/**
	 * Loopback probe worker (admin-post, token-auth). Marks success.
	 */
	public static function probe_worker() {
		$token    = (string) ( $_POST['token'] ?? '' );
		$expected = get_transient( 'verlo_probe_token' );
		if ( ! $expected || ! hash_equals( (string) $expected, $token ) ) {
			status_header( 403 );
			exit;
		}
		set_transient( 'verlo_probe_result', 'ok', 10 * MINUTE_IN_SECONDS );
		delete_transient( 'verlo_probe_token' );
		exit;
	}

	/**
	 * Resolve the probe result into the cached health record. Called on admin
	 * page loads a short while after start_probe(); if the loopback marker never
	 * arrived, we conclude loopback is blocked on this site.
	 */
	public static function resolve_probe() {
		$started = (int) get_option( 'verlo_probe_started', 0 );
		if ( ! $started ) { return; }

		$result = get_transient( 'verlo_probe_result' );
		$cron   = self::cron_state();

		if ( 'ok' === $result ) {
			self::store( 'ok', $cron );
			delete_option( 'verlo_probe_started' );
			return;
		}

		// Give the loopback a reasonable window to report back before concluding
		// it is blocked (covers a slow server, not just a block).
		if ( ( time() - $started ) >= 25 ) {
			self::store( 'blocked', $cron );
			delete_option( 'verlo_probe_started' );
		}
	}

	protected static function store( $loopback, $cron ) {
		// Background is "auto" only if at least one async path works.
		$mode = ( 'ok' === $loopback || 'ok' === $cron ) ? 'auto' : 'assisted';
		update_option( self::OPT_HEALTH, array(
			'loopback'   => $loopback,
			'cron'       => $cron,
			'checked_at' => time(),
			'mode'       => $mode,
		), 'no' );

		if ( class_exists( 'Verlo_Log' ) ) {
			$level = ( 'assisted' === $mode ) ? Verlo_Log::WARN : Verlo_Log::INFO;
			Verlo_Log::add( $level, 'env.health', 'Background-execution health checked', array(
				'mode'     => $mode,
				'loopback' => $loopback,
				'cron'     => $cron,
			) );
		}
	}

	/**
	 * Force a fresh probe (used by the "re-check" action after the user changes
	 * a firewall setting).
	 */
	public static function reprobe() {
		delete_option( self::OPT_HEALTH );
		delete_option( 'verlo_probe_started' );
		delete_transient( 'verlo_probe_result' );
		self::start_probe();
	}
}
