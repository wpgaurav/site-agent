<?php
/**
 * Optional query credential authentication restricted to the MCP route.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Validate native Application Passwords without retaining their plaintext. */
final class Url_Auth {
	public static function authenticate( \WP_REST_Request $request ): bool {
		$config = Config::get();
		if ( Config::locked() || ! $config['enabled'] || ! $config['url_auth'] || ( ! is_ssl() && 'local' !== wp_get_environment_type() ) || '/site-agent/v1/mcp' !== rtrim( $request->get_route(), '/' ) ) {
			return self::deny();
		}
		$query = $request->get_query_params();
		$token = $query['auth'] ?? null;
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return self::deny();
		}
		// Query decoders turn an unescaped plus into a space. The UI percent-encodes it.
		$token = strtr( str_replace( ' ', '+', $token ), '-_', '+/' );
		if ( ! preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/D', $token ) || 1 === strlen( rtrim( $token, '=' ) ) % 4 ) {
			return self::deny();
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode Basic credentials only; no code is evaluated.
		$decoded = base64_decode( $token, true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) || false !== strpos( $decoded, "\0" ) ) {
			return self::deny();
		}
		list( $username, $password ) = explode( ':', $decoded, 2 );
		if ( '' === $username || '' === $password ) {
			return self::deny();
		}
		// Passing null forces validation even when another mechanism set a current user.
		$user = wp_authenticate_application_password( null, $username, $password );
		if ( ! $user instanceof \WP_User || ( get_current_user_id() && get_current_user_id() !== (int) $user->ID ) ) {
			return self::deny();
		}
		wp_set_current_user( $user->ID );
		if ( ! Permissions::administrator() ) {
			return self::deny();
		}
		return true;
	}

	private static function deny(): bool {
		// A rejected supplied token must not fall back to a cookie/header identity.
		wp_set_current_user( 0 );
		return false;
	}

	public static function response( $response, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( '/site-agent/v1/mcp' === rtrim( $request->get_route(), '/' ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
		}
		return $response;
	}
}
