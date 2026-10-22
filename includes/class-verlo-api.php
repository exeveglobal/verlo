<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Signed calls from this site to Verlo (collecting articles, reporting results, testing the connection). */
class Verlo_Api {

	/**
	 * @return array|WP_Error  array( 'status' => int, 'data' => array )
	 */
	public static function request( $method, $path, $body = null ) {
		$s = Verlo_Settings::get();
		if ( ! Verlo_Settings::is_connected() ) {
			return new WP_Error( 'verlo_not_connected', 'Add your connection ID and key first.' );
		}
		$raw     = null === $body ? '' : wp_json_encode( $body );
		$headers = Verlo_Signature::headers( $s['key'], $raw, array(
			'X-Verlo-Destination' => $s['destination_id'],
			'Accept'              => 'application/json',
			'User-Agent'          => 'Verlo-Plugin/' . VERLO_VERSION . '; ' . home_url(),
		) );
		if ( '' !== $raw ) { $headers['Content-Type'] = 'application/json'; }

		$res = wp_remote_request(
			untrailingslashit( VERLO_API_BASE ) . $path,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => '' === $raw ? null : $raw,
			)
		);
		if ( is_wp_error( $res ) ) { return $res; }
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return array( 'status' => (int) wp_remote_retrieve_response_code( $res ), 'data' => is_array( $data ) ? $data : array() );
	}

	public static function friendly_error( $res ) {
		if ( is_wp_error( $res ) ) { return 'Could not reach Verlo (' . $res->get_error_message() . ').'; }
		if ( 401 === $res['status'] ) { return 'Verlo did not accept the connection key. Check the ID and key, or make a new key in your Verlo dashboard.'; }
		if ( 404 === $res['status'] ) { return 'Verlo could not find this connection.'; }
		return 'Verlo answered with an unexpected response (' . (int) $res['status'] . ').';
	}
}
