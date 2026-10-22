<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The one stored setting: which Verlo connection this site belongs to. Kept in a single non-autoloaded option.
 * The key is never printed back to the screen; the admin page shows only its last four characters.
 */
class Verlo_Settings {

	public static function get() {
		$o = get_option( VERLO_OPT_CONNECTION, array() );
		return array_merge(
			array(
				'destination_id' => '',
				'key'            => '',
				'last_contact'   => 0,
				'last_ok'        => 0,
				'last_error'     => '',
				'last_article'   => array(),
			),
			is_array( $o ) ? $o : array()
		);
	}

	public static function update( $changes ) {
		$all = array_merge( self::get(), $changes );
		update_option( VERLO_OPT_CONNECTION, $all, false );
		return $all;
	}

	public static function is_connected() {
		$s = self::get();
		return '' !== $s['destination_id'] && '' !== $s['key'];
	}

	public static function key_hint() {
		$k = self::get()['key'];
		return strlen( $k ) >= 4 ? substr( $k, -4 ) : '';
	}

	public static function valid_destination_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	public static function note_error( $message ) {
		self::update( array( 'last_error' => mb_substr( (string) $message, 0, 300 ), 'last_contact' => time() ) );
	}
}
