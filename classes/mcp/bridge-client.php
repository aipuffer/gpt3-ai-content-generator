<?php
namespace WPAICG\Mcp;
use WP_Error;
/**
 * Site → bridge calls: registration, pairing, pairing status and revocation (contracts §3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BridgeClient {
    /** The existing site credential and callback signature authenticate a bounded browser inspection. */
    public static function inspect_page(string $url, string $viewport, string $operation_id) {
        return self::request('POST', '/site/v1/inspect', ['url' => $url, 'viewport' => $viewport, 'operation_id' => $operation_id], 20);
    }
	/**
	 * The site's Ed25519 key pair for signing requests to the bridge, created once; the secret key is stored
	 * encrypted. Registration sends the public key; the Cloud site token authorizes replacing it.
	 */
	/** Cached for the request, so signing an answer never needs the database (core edit contract §2.2 T3). */
	private static $keypair = [];

	/** Loads the signing keypair before any mutation (quarantine-safe completion). */
	public static function preload() {
		self::callback_keypair();
	}

	private static function callback_keypair() {
		 $cache = hash('sha256', serialize([get_current_blog_id(), home_url(), Store::config()['callback_secret'] ?? '', wp_salt('auth')]));
        if (!isset(self::$keypair[$cache])) { self::$keypair[$cache] = self::load_keypair(); }
        return self::$keypair[$cache];
	}

	private static function load_keypair() {
		$config = Store::config();
		if ( ! empty( $config['callback_secret'] ) ) {
			$secret = Store::decrypt( $config['callback_secret'], 'site-callback-key' );
			if ( is_string( $secret ) && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $secret ) ) {
				return array( 'secret' => $secret, 'public' => sodium_crypto_sign_publickey_from_secretkey( $secret ) );
			}
		}
        throw new \RuntimeException('mcp_callback_unavailable');
	}

	/** Signs an executor answer with the site's registered key (authenticated outcomes). */
	public static function sign_outcome( $canonical ) {
		return Store::b64url_encode( sodium_crypto_sign_detached( $canonical, self::callback_keypair()['secret'] ) );
	}

	private static function request( $method, $path, $body = null, $timeout = 15, $answers = array() ) {
		$token = Store::site_token();
		if ( '' === $token ) {
			return new WP_Error( 'no_token', 'Connect AI Puffer Cloud first.' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
            'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 131072,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		// Signed callbacks: everything after registration carries time, nonce and a signature over the exact
		// method, path and query, body hash and site ID.
		$config = Store::config();
		if ( '/site/v1/register' !== $path && ! empty( $config['site_id'] ) ) {
			$time      = time();
			$nonce     = Store::b64url_encode( random_bytes( 18 ) );
			$canonical = implode( "\n", array( 'aipkit-site-v1', strtoupper( $method ), $path, (string) $time, $nonce, hash( 'sha256', isset( $args['body'] ) ? $args['body'] : '' ), (string) $config['site_id'] ) );
			$args['headers']['X-AIPKit-Site-Time']      = (string) $time;
			$args['headers']['X-AIPKit-Site-Nonce']     = $nonce;
			$args['headers']['X-AIPKit-Site-Signature'] = Store::b64url_encode( sodium_crypto_sign_detached( $canonical, self::callback_keypair()['secret'] ) );
		}
		$res = wp_remote_request( Store::bridge_url() . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$code = wp_remote_retrieve_response_code( $res );
		// A caller may read specific refusals itself (a stale level change answers 409 with the current level).
		if ( in_array( $code, $answers, true ) && is_array( $data ) ) {
			return array( '_status' => $code ) + $data;
		}
		if ( $code !== 200 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'Bridge returned HTTP ' . $code . '.';
            // Preserve only bounded inspection errors; other bridge routes keep their existing error contract.
            $inspection_code = is_array($data) ? ($data['error']['code'] ?? null) : null;
            if ($path === '/site/v1/inspect' && in_array($inspection_code, ['inspection_timeout', 'inspection_unavailable', 'inspection_disabled', 'inspection_account_limit', 'inspection_busy', 'inspection_capacity', 'invalid_inspection_url', 'inspection_not_active', 'invalid_input'], true)) {
                return new WP_Error($inspection_code, $message);
            }
			return new WP_Error( 'bridge_error', $message );
		}
		return $data;
	}

	/**
	 * Registers with the bridge. With $expect (account_id, site_id), a registration that would bind this site to a
	 * different identity is refused and nothing is stored: renewal never silently rebinds (review round 31).
	 */
	public static function register( $expect = null ) {
		if ( ! Store::has_consent() ) {
			return new WP_Error( 'consent_required', 'Agree to the site management disclosure first.' );
		}
        $reservation = Identity::reserve();
        $token_hash = hash('sha256', Store::site_token());
        $config = Store::config();
        if (empty($config['callback_secret'])) {
            $pair = sodium_crypto_sign_keypair();
            Store::update_config(['callback_secret' => Store::encrypt(sodium_crypto_sign_secretkey($pair), 'site-callback-key')]);
        }
		$data = self::request(
			'POST',
			'/site/v1/register',
			array(
				'registration_instance' => $reservation['instance_id'],
				'registration_epoch' => $reservation['epoch'],
				'site_url'       => home_url(),
				'plugin_version' => WPAICG_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'rest_base'      => rest_url(),
				'fallback_urls'  => array(
					home_url( '/' ) . '?rest_route=/aipkit/v1/mcp/execute',
					admin_url( 'admin-ajax.php?action=aipkit_mcp_execute' ),
				),
				// Every later request to the bridge is signed with this key (signed callbacks).
				'callback_public_key' => Store::b64url_encode( self::callback_keypair()['public'] ),
				// What this plugin version can run: the bridge offers assistants only these operations.
				'contract'       => Registry::CONTRACT,
				'operations'     => Registry::report(),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( is_array( $expect ) && ( (string) $data['account_id'] !== (string) $expect['account_id'] || (string) $data['site_id'] !== (string) $expect['site_id'] ) ) {
			return new WP_Error( 'identity_changed', 'The Cloud connection now belongs to a different account or site. Save the Cloud connection again in wp-admin to review it.' );
		}
        foreach (['bridge_site_key', 'account_id', 'site_id', 'mcp_url'] as $field) {
            if (!is_string($data[$field] ?? null) || $data[$field] === '') { return new WP_Error('invalid_registration', 'Invalid bridge registration.'); }
        }
        if (!is_array($data['signing_keys'] ?? null) || !$data['signing_keys']) { return new WP_Error('invalid_registration', 'Invalid bridge signing keys.'); }
        if ($data['mcp_url'] !== Store::bridge_url() . '/mcp') { return new WP_Error('invalid_registration', 'Invalid bridge address.'); }
        foreach ($data['signing_keys'] as $key) {
            if (!is_array($key) || !is_string($key['key_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key['key_id'])
                || !is_string($key['public_key'] ?? null) || strlen((string) Store::b64url_decode($key['public_key'])) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return new WP_Error('invalid_registration', 'Invalid bridge signing keys.');
            }
        }
        \WPAICG\Cloud\Connection::update(static function (array $state) use ($reservation, $token_hash, $data): array {
            if (Identity::registration() !== $reservation || empty($reservation['enabled'])
                || !hash_equals($token_hash, hash('sha256', (string) ($state['token'] ?? '')))) {
                throw new \RuntimeException('mcp_registration_superseded');
            }
            $state['mcp'] = array_merge($state['mcp'] ?? [], [
                'bridge_site_key' => $data['bridge_site_key'], 'account_id' => $data['account_id'],
                'site_id' => $data['site_id'], 'signing_keys' => $data['signing_keys'],
                'mcp_url' => $data['mcp_url'], 'registered_at' => time(),
                'instance_id' => $reservation['instance_id'], 'registered_token_hash' => $token_hash,
                'reported' => Registry::fingerprint(),
            ]);
            return $state;
        });
        // A plain, autoloaded copy of what was reported: every request can compare it cheaply after an update.
        update_option(Registry::REPORTED_OPTION, Registry::fingerprint(), true);
		return $data;
	}

	/**
	 * Creates a pending grant and returns the bridge's connect URL. The assistant acts as the current user, or as
	 * $acting_user when an administrator chose another account (Settings checks who may choose).
	 */
	public static function pair( $access_level, $client_hint, $acting_user = null ) {
		if ( ! Store::has_consent() ) {
			return new WP_Error( 'consent_required', 'Agree to the site management disclosure first.' );
		}
		if (!Identity::active() || !\WPAICG\Cloud\Connection::allowed()) { return new WP_Error('permission_denied', 'Manage website access in Settings.'); }
        $registration = Identity::registration();
        $token_hash = hash('sha256', Store::site_token());
        $user  = $acting_user instanceof \WP_User ? $acting_user : wp_get_current_user();
		$grant = array(
			'grant_id'      => 'gr_' . Store::uuidv7(),
			'wp_user_id'    => (int) $user->ID,
			'wp_user_login' => $user->user_login,
			'access_level'  => $access_level,
			'profile'       => 'full',
			'scopes'        => self::scopes( $access_level ),
			'state'         => 'pending',
			'connection_id' => '',
			'client_name'   => '',
			'client_hint'   => $client_hint,
			'created_at'    => time(),
		);
		$secret              = random_bytes( 32 );
		$grant['secret_enc'] = Store::encrypt( $secret, 'grant:' . $grant['grant_id'] );
		if ( ! Store::insert_grant( $grant ) ) {
			return new WP_Error( 'storage', 'Could not store the new grant.' );
		}
        try { $authorized = Levels::authorize_new($grant['grant_id'], $access_level); }
        catch (\Throwable $error) { $authorized = false; }
        if (!$authorized) {
            Store::set_state($grant['grant_id'], 'revoked');
            Levels::forget($grant['grant_id']);
            return new WP_Error('storage', 'Could not store the assistant approval policy.');
        }
		$data = self::request(
			'POST',
			'/site/v1/pairing',
			array(
				'grant_id'      => $grant['grant_id'],
				'grant_secret'  => Store::b64url_encode( $secret ),
				'wp_user_id'    => $grant['wp_user_id'],
				'wp_user_login' => $grant['wp_user_login'],
				'access_level'  => $access_level,
				'profile'       => 'full',
				'client_hint'   => $client_hint,
			)
		);
		if ( is_wp_error( $data ) ) {
			Store::set_state( $grant['grant_id'], 'revoked' );
            Levels::forget($grant['grant_id']);
			return $data;
		}
        $url = $data['connect_url'] ?? '';
        $parts = is_string($url) ? wp_parse_url($url) : false;
        parse_str($parts['query'] ?? '', $query);
        $ticket = $query['ticket'] ?? '';
        $valid_url = is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === wp_parse_url(Store::bridge_url(), PHP_URL_HOST)
            && ($parts['path'] ?? '') === '/connect' && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['port']) && !isset($parts['fragment']) && count($query) === 1
            && is_string($ticket) && preg_match('/^[a-f0-9-]{36}\.[A-Za-z0-9_-]{32}$/D', $ticket)
            && substr($ticket, 0, 36) === Store::config()['account_id'];
        if (!$valid_url || Identity::registration() !== $registration || !Identity::active()
            || !hash_equals($token_hash, hash('sha256', Store::site_token()))
            || (Store::grant($grant['grant_id'])['state'] ?? '') !== 'pending') {
            Store::set_state($grant['grant_id'], 'revoked');
            return new WP_Error('pairing_superseded', 'The connection changed. Start again in Settings.');
        }
		set_transient( 'aipkit_mcp_connect_' . $grant['grant_id'], $data['connect_url'], 600 );

		return array(
			'grant_id'    => $grant['grant_id'],
			'connect_url' => $data['connect_url'],
            'client_hint' => $client_hint,
		);
	}

	public static function status( $grant_id ) {
		$grant = Store::grant( $grant_id );
		if ( ! $grant ) {
			return new WP_Error( 'not_found', 'Unknown grant.' );
		}
		$data = self::request( 'GET', '/site/v1/pairing/status?grant_id=' . rawurlencode( $grant_id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
        if (!is_string($data['state'] ?? null) || !in_array($data['state'], ['pending','binding','active','paused','revoked'], true)
            || ($data['grant_id'] ?? null) !== $grant_id) { return new WP_Error('invalid_status', 'Invalid connection status.'); }
        if ('active' === $data['state'] && (!is_string($data['connection_id'] ?? null) || $data['connection_id'] === '' || !is_string($data['client_name'] ?? null))) { return new WP_Error('invalid_status', 'Invalid connection status.'); }
		if ( 'active' === $data['state'] && 'pending' === $grant['state'] ) {
			Store::bind_pending( $grant_id, (string) $data['connection_id'], sanitize_text_field( (string) $data['client_name'] ) );

			$grant = Store::grant( $grant_id );
		}
        if (in_array($data['state'], ['active','paused'], true)) {
            global $wpdb;
            $grants_table = Schema::table('grants');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditional grant update must see revocation immediately; Schema allowlists the table and all values are prepared.
            $wpdb->query($wpdb->prepare("UPDATE $grants_table SET state = %s WHERE grant_id = %s AND state IN ('active','paused')", $data['state'], $grant_id));
            $grant = Store::grant($grant_id);
        }
        if ('revoked' === $data['state']) { Store::set_state($grant_id, 'revoked'); $grant = Store::grant($grant_id); }
		return array(
			'state'       => $grant['state'],
			'client_name' => $grant['client_name'],
		);
	}

	/** Local revocation takes effect on the next call even if the bridge can't be reached. */
	public static function revoke( $grant_id, $timeout = 3 ) {
		$grant = Store::grant( $grant_id );
		if ( ! $grant ) {
			return new WP_Error( 'not_found', 'Unknown grant.' );
		}
        if (!Store::set_state($grant_id, 'revoked')) { return new WP_Error('mcp_storage_unavailable', 'Website access could not be revoked locally.'); }
        $reply = self::request('POST', '/site/v1/grants/revoke', ['grant_id' => $grant_id], $timeout);
        if (is_wp_error($reply)) { return $reply; }
        if (($reply['ok'] ?? null) !== true || ($reply['grant_id'] ?? null) !== $grant_id || ($reply['state'] ?? null) !== 'revoked') {
            return new WP_Error('invalid_revocation', 'Bridge revocation was not confirmed.');
        }
        return $reply;
	}

    /** A manual, content-free round trip; no registration or grants are created. */
    public static function readiness(array $body) {
        return self::request('POST', '/site/v1/readiness', $body, 30);
    }

	public static function site_status() {
		return self::request( 'GET', '/site/v1/status' );
	}

    public static function scopes($level) {
        return Registry::scopes((string) $level);
    }

    /**
     * Sends one numbered safety-level change (Levels). Returns the confirmation, ['stale' => true, …] when the
     * bridge already holds a newer change, WP_Error 'revoked' for a disconnected grant, or another WP_Error.
     */
    public static function set_level($grant_id, $level, $revision) {
        $reply = self::request('POST', '/site/v1/grants/level', ['grant_id' => $grant_id, 'access_level' => $level, 'level_revision' => (int) $revision], 15, [409]);
        if (is_wp_error($reply)) { return $reply; }
        if (($reply['_status'] ?? 200) === 409) {
            $code = $reply['error']['code'] ?? '';
            if ($code === 'stale_level' && is_string($reply['access_level'] ?? null) && is_int($reply['level_revision'] ?? null)) {
                return ['stale' => true, 'access_level' => $reply['access_level'], 'level_revision' => $reply['level_revision']];
            }
            return new WP_Error($code === 'revoked' ? 'revoked' : 'invalid_level', 'The bridge refused the safety level change.');
        }
        if (($reply['ok'] ?? null) !== true || ($reply['grant_id'] ?? null) !== $grant_id || ($reply['access_level'] ?? null) !== $level
            || ($reply['level_revision'] ?? null) !== (int) $revision) {
            return new WP_Error('invalid_level', 'The bridge did not confirm the new safety level.');
        }
        return $reply;
    }
}
