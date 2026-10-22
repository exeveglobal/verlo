<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pull delivery. Many sites cannot receive calls from outside (firewalls, basic auth, staging, private networks), so
 * this site also asks Verlo every few minutes whether anything is waiting. Everything it receives is checked and
 * published exactly as a pushed article would be, and the result is reported back so Verlo can show it.
 * WordPress runs its schedule when the site gets visits; "Check now" on the settings page does it immediately.
 */
class Verlo_Poller {

	const INTERVAL = 'verlo_five_minutes';
	const LOCK     = 'verlo_poll_lock';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( VERLO_HOOK_POLL, array( __CLASS__, 'run' ) );
		// Self-heal: if the schedule was lost (a migration, a cleaned cron table), put it back.
		if ( Verlo_Settings::is_connected() && ! wp_next_scheduled( VERLO_HOOK_POLL ) ) {
			wp_schedule_event( time() + 60, self::INTERVAL, VERLO_HOOK_POLL );
		}
	}

	public static function schedules( $s ) {
		$s[ self::INTERVAL ] = array( 'interval' => 300, 'display' => 'Every five minutes (Verlo)' );
		return $s;
	}

	public static function activate() {
		if ( ! wp_next_scheduled( VERLO_HOOK_POLL ) ) {
			wp_schedule_event( time() + 60, self::INTERVAL, VERLO_HOOK_POLL );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( VERLO_HOOK_POLL );
	}

	/** @return array  array( 'ok' => bool, 'message' => string, 'published' => int ) */
	public static function run() {
		if ( ! Verlo_Settings::is_connected() ) { return array( 'ok' => false, 'message' => 'Not connected.', 'published' => 0 ); }
		if ( get_transient( self::LOCK ) ) { return array( 'ok' => true, 'message' => 'Another check is already running.', 'published' => 0 ); }
		set_transient( self::LOCK, 1, 120 );

		try {
			$res = Verlo_Api::request( 'GET', '/v1/connect/pending' );
			if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
				$msg = Verlo_Api::friendly_error( $res );
				Verlo_Settings::note_error( $msg );
				return array( 'ok' => false, 'message' => $msg, 'published' => 0 );
			}
			Verlo_Settings::update( array( 'last_contact' => time(), 'last_ok' => time(), 'last_error' => '' ) );

			$items = isset( $res['data']['items'] ) && is_array( $res['data']['items'] ) ? $res['data']['items'] : array();
			$done  = 0;
			foreach ( array_slice( $items, 0, 5 ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['attempt_id'] ) ) { continue; }
				$key    = isset( $item['idempotency_key'] ) ? (string) $item['idempotency_key'] : '';
				$result = Verlo_Publisher::publish( $item, $key );
				if ( is_wp_error( $result ) ) {
					Verlo_Api::request( 'POST', '/v1/connect/result', array( 'attempt_id' => $item['attempt_id'], 'ok' => false, 'error' => mb_substr( $result->get_error_message(), 0, 280 ) ) );
					Verlo_Settings::note_error( $result->get_error_message() );
					continue;
				}
				$report = Verlo_Api::request( 'POST', '/v1/connect/result', array(
					'attempt_id'  => $item['attempt_id'],
					'ok'          => true,
					'external_id' => $result['external_id'],
					'url'         => $result['url'],
				) );
				if ( ! is_wp_error( $report ) && 200 === $report['status'] ) {
					$done++;
					Verlo_Settings::update( array( 'last_article' => array( 'title' => sanitize_text_field( (string) ( $item['article']['title'] ?? '' ) ), 'at' => time(), 'status' => $result['status'] ) ) );
				}
			}
			return array( 'ok' => true, 'message' => $done ? sprintf( '%d article(s) received.', $done ) : 'Nothing waiting.', 'published' => $done );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/** "Send test": confirms this site can reach Verlo and that the key is accepted. */
	public static function test() {
		$res = Verlo_Api::request( 'POST', '/v1/connect/ping', new stdClass() );
		if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
			$msg = Verlo_Api::friendly_error( $res );
			Verlo_Settings::note_error( $msg );
			return array( 'ok' => false, 'message' => $msg );
		}
		Verlo_Settings::update( array( 'last_contact' => time(), 'last_ok' => time(), 'last_error' => '' ) );
		return array( 'ok' => true, 'message' => 'Connected. Verlo accepted this site\'s key.' );
	}
}
