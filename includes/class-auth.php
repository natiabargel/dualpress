<?php
/**
 * HMAC-SHA256 request signing and verification.
 *
 * Signing flow (sender):
 *   $payload   = json_encode($data);
 *   $timestamp = time();
 *   $sig       = Auth::sign($payload, $timestamp);
 *   // Set headers: X-Sync-Signature, X-Sync-Timestamp
 *
 * Verification flow (receiver):
 *   Auth::verify($payload, $timestamp, $received_signature);
 *
 * @package DualPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DualPress_Auth
 */
class DualPress_Auth {

	/**
	 * Maximum allowed clock skew in seconds (±5 minutes).
	 */
	const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Generate an HMAC-SHA256 signature for the given payload and timestamp.
	 *
	 * @param string $payload   Raw JSON string.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $key       Optional override secret key (uses settings by default).
	 * @return string Hex-encoded HMAC signature.
	 */
	public static function sign( $payload, $timestamp, $key = '' ) {
		if ( empty( $key ) ) {
			$key = DualPress_Settings::get_secret_key();
		}
		return hash_hmac( 'sha256', $payload . (string) $timestamp, $key );
	}

	/**
	 * Verify an incoming HMAC signature.
	 *
	 * Returns a WP_Error on failure, true on success.
	 *
	 * @param string $payload            Raw request body (JSON string).
	 * @param int    $timestamp          Timestamp from X-Sync-Timestamp header.
	 * @param string $received_signature Signature from X-Sync-Signature header.
	 * @return true|WP_Error
	 */
	public static function verify( $payload, $timestamp, $received_signature ) {
		// Replay / clock-skew check.
		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return new WP_Error(
				'dualpress_auth_expired',
				__( 'Request timestamp is outside the allowed window.', 'dualpress' ),
				array( 'status' => 403 )
			);
		}

		$expected = self::sign( $payload, $timestamp );

		if ( ! hash_equals( $expected, (string) $received_signature ) ) {
            error_log("DUALPRESS DEBUG www: expected=".$expected." received=".$received_signature." payload_len=".strlen($payload)." ts=".$timestamp);
			return new WP_Error(
				'dualpress_auth_invalid',
				__( 'Invalid HMAC signature.', 'dualpress' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Build the HTTP headers array for an outgoing sync request.
	 *
	 * @param string $payload Raw JSON payload that will be sent in the request body.
	 * @return array<string,string> Headers array suitable for wp_remote_post().
	 */
	public static function build_headers( $payload ) {
		$timestamp = time();
		$signature = self::sign( $payload, $timestamp );

		return array(
			'Content-Type'      => 'application/json',
			'X-Sync-Signature'  => $signature,
			'X-Sync-Timestamp'  => (string) $timestamp,
			'X-Sync-Source'     => DualPress_Settings::get_server_role(),
			'X-DualPress-Ver'   => DUALPRESS_VERSION,
			'Connection'        => 'keep-alive',
			'User-Agent'        => 'DualPress/' . DUALPRESS_VERSION,
		);
	}

	/**
	 * Validate an incoming WP REST request using its headers.
	 *
	 * Reads X-Sync-Signature and X-Sync-Timestamp from the request object.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return true|WP_Error
	 */
	public static function verify_request( WP_REST_Request $request ) {
		$signature = $request->get_header( 'x_sync_signature' );
		$timestamp = $request->get_header( 'x_sync_timestamp' );

		if ( empty( $signature ) || empty( $timestamp ) ) {
			return new WP_Error(
				'dualpress_auth_missing',
				__( 'Missing authentication headers.', 'dualpress' ),
				array( 'status' => 401 )
			);
		}

		// get_body() returns the raw request body.
		$payload = $request->get_body();

		return self::verify( $payload, (int) $timestamp, $signature );
	}

	/**
	 * Generate a new cryptographically secure secret key.
	 *
	 * @param int $length Byte length before hex encoding. Default 32 (= 64 hex chars).
	 * @return string Hex-encoded key.
	 */
	public static function generate_secret_key( $length = 32 ) {
		return bin2hex( random_bytes( $length ) );
	}
}
