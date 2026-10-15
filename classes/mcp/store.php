<?php
namespace WPAICG\Mcp;
/**
 * Bridge registration uses Cloud custody; assistant grants have their own encrypted secrets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Store {
    public static function config(): array {
        $state = \WPAICG\Cloud\Connection::state();
        return is_array($state['mcp'] ?? null) ? $state['mcp'] : [];
    }

    public static function update_config(array $changes): void {
        \WPAICG\Cloud\Connection::update(static function (array $state) use ($changes): array {
            $state['mcp'] = array_merge(is_array($state['mcp'] ?? null) ? $state['mcp'] : [], $changes);
            return $state;
        });
    }

    /** Merge durable retry IDs with CAS so simultaneous disconnects cannot lose each other. */
    public static function update_revocations(callable $merge): array {
        global $wpdb;
        $name = 'aipkit_mcp_revoke_retry';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $before = $raw === null ? [] : maybe_unserialize($raw);
            if (!is_array($before)) { throw new \RuntimeException('mcp_storage_unreadable'); }
            $after = $merge($before);
            if ($after === $before) { return $after; }
            $changed = $raw === null ? (add_option($name, $after, '', false) ? 1 : 0)
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
                : $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize($after), $name, $raw));
            if ($changed === false) { throw new \RuntimeException('mcp_storage_unavailable'); }
            if ($changed === 1) { wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options'); return $after; }
        }
        throw new \RuntimeException('mcp_storage_busy');
    }

    public static function site_token(): string {
        $state = \WPAICG\Cloud\Connection::state();
        return is_string($state['token'] ?? null) ? $state['token'] : '';
    }

	/** Version of the MCP data-sharing disclosure (05-legal-drafts §5); a new version needs new consent. */
	const CONSENT_VERSION = 'mcp-disclosure-2026-10-09';

	public static function has_consent() {
		$config = self::config();
		return isset( $config['consent']['version'] ) && self::CONSENT_VERSION === $config['consent']['version'];
	}

	public static function record_consent( $user_id ) {
		self::update_config( array( 'consent' => array( 'version' => self::CONSENT_VERSION, 'user_id' => (int) $user_id, 'at' => time() ) ) );
	}

    public static function bridge_url(): string {
        return 'https://mcp.aipuffer.org';
    }

	/**
	 * Grants live in their own table and change only through conditional updates, so a request that
	 * finishes after a revocation can never restore the grant (review finding 1).
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aipkit_mcp_grants';
	}

	private static function row_to_grant( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row['wp_user_id']   = (int) $row['wp_user_id'];
		$row['created_at']   = (int) $row['created_at'];
		$row['last_used_at'] = $row['last_used_at'] ? (int) $row['last_used_at'] : null;
		$row['scopes']       = json_decode( (string) $row['scopes'], true );
        $row['unattended'] = Levels::unattended((string) $row['grant_id']);
		return $row;
	}

	/** @return array<string, array> grant_id => grant */
	public static function grants() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at, grant_id', ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['grant_id'] ] = self::row_to_grant( $row );
		}
		return $out;
	}

	public static function grant( $grant_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		return self::row_to_grant( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE grant_id = %s', $grant_id ), ARRAY_A ) );
	}

	/** Creates a grant; fails if the ID exists. */
	public static function insert_grant( array $g ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		return 1 === $wpdb->insert(
			self::table(),
			array(
				'grant_id'      => $g['grant_id'],
                'instance_id'   => Identity::registration()['instance_id'],
				'wp_user_id'    => (int) $g['wp_user_id'],
				'wp_user_login' => $g['wp_user_login'],
				'access_level'  => $g['access_level'],
				'profile'       => $g['profile'],
				'scopes'        => wp_json_encode( array_values( (array) $g['scopes'] ) ),
				'state'         => $g['state'],
				'connection_id' => isset( $g['connection_id'] ) ? $g['connection_id'] : '',
				'client_name'   => isset( $g['client_name'] ) ? $g['client_name'] : '',
				'client_hint'   => isset( $g['client_hint'] ) ? $g['client_hint'] : '',
				'secret_enc'    => $g['secret_enc'],
				'created_at'    => isset( $g['created_at'] ) ? (int) $g['created_at'] : time(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/** pending → active, once, bound to one connection. */
	public static function bind_pending( $grant_id, $connection_id, $client_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET state = 'active', connection_id = %s, client_name = %s WHERE grant_id = %s AND state = 'pending' AND connection_id = ''", $connection_id, $client_name, $grant_id ) );
	}

	/** Changes state; revoked is final. */
	public static function set_state( $grant_id, $state ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET state = %s WHERE grant_id = %s AND state != 'revoked'", $state, $grant_id ) );
	}

	/** Changes a connection's safety level and the scopes it carries; a revoked grant stays revoked. */
	public static function set_level( $grant_id, $level ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Atomic grant/revocation state; the table is the WordPress prefix plus a fixed suffix and external values are prepared; caching could restore revoked access.
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET access_level = %s, scopes = %s WHERE grant_id = %s AND state != 'revoked'", $level, wp_json_encode( Registry::scopes( $level ) ), $grant_id ) );
	}

	public static function grant_secret( array $grant ) {
		return self::decrypt( $grant['secret_enc'], 'grant:' . $grant['grant_id'] );
	}

	/**
	 * The host both sides sign: lowercase, default ports removed, the same as the bridge's URL.host.
	 */
	public static function canonical_host( $url = null ) {
		$parts = wp_parse_url( null === $url ? home_url() : $url );
		$host  = strtolower( isset( $parts['host'] ) ? $parts['host'] : '' );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$https = isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] );
		if ( $port && ! ( $https && 443 === $port ) && ! ( ! $https && 80 === $port ) ) {
			$host .= ':' . $port;
		}
		return $host;
	}

	private static function key( $purpose ) {
		return sodium_crypto_generichash( 'aipkit-mcp|' . get_current_blog_id() . '|' . home_url() . '|' . $purpose . '|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( $plaintext, $purpose ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1.' . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::key( $purpose ) ) );
	}

	public static function decrypt( $blob, $purpose ) {
		if ( ! is_string( $blob ) || 0 !== strpos( $blob, 'v1.' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $blob, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $purpose ) );
		return false === $plain ? '' : $plain;
	}

	public static function b64url_encode( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( $text ) {
		if ( ! is_string( $text ) || ! preg_match( '/^[A-Za-z0-9_-]*$/D', $text ) ) {
			return false;
		}
		return base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true );
	}

	/** RFC 9562 UUIDv7. */
	public static function uuidv7() {
		$ms    = (int) floor( microtime( true ) * 1000 );
		$bytes = random_bytes( 16 );
		for ( $i = 5; $i >= 0; $i-- ) {
			$bytes[ $i ] = chr( $ms & 0xff );
			$ms          = $ms >> 8;
		}
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x70 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
