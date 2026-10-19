<?php
namespace WPAICG\Mcp;
use InvalidArgumentException;
/**
 * Verifies one bridge request (contracts §4): pinned Ed25519 signature, freshness, per-grant MAC,
 * single-use nonce, grant state, and that the body is bound to this site, grant and intent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verifier {
	const SKEW      = 300;

	private static function fail( $status, $code, $message ) {
		return array(
			'ok'      => false,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * @param string   $raw_body Exact request body bytes.
	 * @param callable $header   Returns a request header value or null.
	 * @return array
	 */
	public static function verify( $raw_body, $header ) {
		$config  = Store::config();
		$key_id  = (string) $header( 'X-AIPKit-Agent-Key' );
		$grantid = (string) $header( 'X-AIPKit-Agent-Grant' );
		$time    = (string) $header( 'X-AIPKit-Agent-Time' );
		$nonce   = (string) $header( 'X-AIPKit-Agent-Nonce' );
		$sig     = Store::b64url_decode( (string) $header( 'X-AIPKit-Agent-Signature' ) );
		$mac     = (string) $header( 'X-AIPKit-Agent-MAC' );

		if (!function_exists('sodium_crypto_sign_verify_detached') || empty( $config['site_id'] ) || !Schema::ready()) {
			return self::fail( 409, 'site_not_registered', 'This site is not registered with the bridge.' );
		}
		$public_key = null;
		foreach ( isset( $config['signing_keys'] ) ? (array) $config['signing_keys'] : array() as $key ) {
			if ( isset( $key['key_id'], $key['public_key'] ) && hash_equals( (string) $key['key_id'], $key_id ) ) {
				$public_key = Store::b64url_decode( $key['public_key'] );
			}
		}
		if ( ! $public_key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			return self::fail( 401, 'invalid_signature', 'Unknown signing key.' );
		}
		if ( ! preg_match( '/^\d{9,11}$/', $time ) || ! preg_match( '/^[A-Za-z0-9_-]{22}$/', $nonce ) || ! preg_match( '/^gr_[0-9a-f-]{36}$/', $grantid ) ) {
			return self::fail( 400, 'invalid_signature', 'Malformed authentication headers.' );
		}
		$canonical = Canonical::request( Store::canonical_host(), (int) $time, $nonce, $grantid, hash( 'sha256', $raw_body ) );
		if ( false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $canonical, $public_key ) ) {
			return self::fail( 401, 'invalid_signature', 'Signature check failed.' );
		}
		if ( abs( time() - (int) $time ) > self::SKEW ) {
			return self::fail( 401, 'stale_request', 'Request time is outside the allowed window.' );
		}
		$grant = Store::grant( $grantid );
		if ( ! $grant ) {
			return self::fail( 403, 'site_not_authorized', "This site isn't connected to this assistant. Approve it in wp-admin › Manage website." );
		}
		$secret   = Store::grant_secret( $grant );
		$expected = Store::b64url_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );
		if ( '' === $secret || ! hash_equals( $expected, $mac ) ) {
			return self::fail( 401, 'invalid_signature', 'Grant check failed.' );
		}
		// Claimed only after both authenticity checks pass, so forged requests can't fill the table.
		if ( ! Journal::nonce( $nonce ) ) {
			return self::fail( 401, 'replay', 'This request was already received.' );
		}
		$body = json_decode( $raw_body );

		if ( ! is_object( $body ) || ! isset( $body->operation_id, $body->account_id, $body->site_id, $body->connection_id, $body->acting_user, $body->tool, $body->args, $body->intent_hash ) ) {
			return self::fail( 400, 'invalid_input', 'Missing request fields.' );
		}
		if ( ! is_object( $body->args ) || ! is_int( $body->acting_user ) || ! is_string( $body->tool )
            || !is_string($body->operation_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body->operation_id)
            || !is_string($body->account_id) || !is_string($body->site_id) || !is_string($body->connection_id) || !is_string($body->intent_hash) ) {
			return self::fail( 400, 'invalid_input', 'Malformed request fields.' );
		}
		if ( ! hash_equals( (string) $config['account_id'], (string) $body->account_id ) || ! hash_equals( (string) $config['site_id'], (string) $body->site_id ) ) {
			return self::fail( 403, 'site_not_authorized', 'This request is for a different site or account.' );
		}
		if ( (int) $grant['wp_user_id'] !== $body->acting_user ) {
			return self::fail( 403, 'permission_denied', 'This request names a different WordPress user than the grant.' );
		}
		if ( ! empty( $grant['connection_id'] ) && ! hash_equals( (string) $grant['connection_id'], (string) $body->connection_id ) ) {
			return self::fail( 403, 'site_not_authorized', 'This request comes from a different connection than the grant.' );
		}
		try {
			$hash = Canonical::intent($body);
		} catch ( InvalidArgumentException $e ) {
			return self::fail( 400, 'invalid_input', 'Arguments must use plain ASCII keys and whole numbers.' );
		}
		if ( ! hash_equals( $hash, (string) $body->intent_hash ) ) {
			return self::fail( 400, 'invalid_input', 'Intent hash does not match the request.' );
		}

		// Recovery lookups stay available after revocation or pause; they never execute (review finding 5).
		$lookup = is_object( $body ) && isset( $body->tool ) && 'get_operation' === $body->tool;
        if (!$lookup && (!is_string($body->registration_instance ?? null)
            || $body->registration_instance !== (Identity::registration()['instance_id'] ?? null))) {
            return array( 'authenticated' => true ) + self::fail(403, 'site_not_authorized', 'This request belongs to a previous installation.');
        }

		if ( ( 'revoked' === $grant['state'] || !Identity::active() || ($grant['instance_id'] ?? '') !== (Identity::registration()['instance_id'] ?? '')) && ! $lookup ) {
			return array( 'authenticated' => true ) + self::fail( 403, 'site_not_authorized', "This site isn't connected to this assistant. Approve it in wp-admin › Manage website." );
		}
		if ( 'paused' === $grant['state'] && ! $lookup ) {
			return array( 'authenticated' => true ) + self::fail( 403, 'site_paused', 'AI access to this site is paused. Resume it in wp-admin › Manage website.' );
		}

		// First authenticated request for a pending grant: the bridge has bound it to this connection.
		if ( empty( $grant['connection_id'] ) ) {
			if ( $lookup ) {
				return self::fail( 403, 'site_not_authorized', 'This grant was never connected.' );
			}
			$client = isset( $body->client->name ) ? sanitize_text_field( (string) $body->client->name ) : '';
			Store::bind_pending( $grantid, (string) $body->connection_id, $client );
			$grant = Store::grant( $grantid );
			if ( ! $grant || ! hash_equals( (string) $grant['connection_id'], (string) $body->connection_id ) || 'active' !== $grant['state'] ) {
				return self::fail(403, 'site_not_authorized', 'This grant is bound to a different connection.');
			}
		}
		return array(
			'ok'    => true,
			'grant' => $grant,
			'body'  => $body,
		);
	}
}
