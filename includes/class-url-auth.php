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
	const ROUTE = '/site-agent/v1/mcp';

	/**
	 * Credentials moved out of request query parameters, keyed by request.
	 *
	 * @var \WeakMap|null
	 */
	private static $captured = null;

	/**
	 * Application Password UUID most recently authenticated through the URL.
	 *
	 * @var string
	 */
	private static $url_uuid = '';

	public static function is_mcp_route( \WP_REST_Request $request ): bool {
		return self::ROUTE === rtrim( $request->get_route(), '/' );
	}

	/**
	 * Move the auth parameter out of the request and superglobals before dispatch, so code that
	 * runs later (activity logs, APM, error reporters) does not record the credential.
	 *
	 * @param mixed $result  Short-circuit response.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed Unchanged short-circuit response.
	 */
	public static function capture( $result, $server, $request ) {
		unset( $server );
		if ( ! $request instanceof \WP_REST_Request || ! self::is_mcp_route( $request ) ) {
			return $result;
		}
		$query = $request->get_query_params();
		if ( ! array_key_exists( 'auth', $query ) ) {
			return $result;
		}
		self::$captured             = self::$captured ?? new \WeakMap();
		self::$captured[ $request ] = $query['auth'];
		unset( $query['auth'] );
		$request->set_query_params( $query );
		// phpcs:disable WordPress.Security.NonceVerification -- Removing a credential, not processing form input.
		unset( $_GET['auth'], $_REQUEST['auth'] );
		// phpcs:enable WordPress.Security.NonceVerification
		foreach ( array( 'QUERY_STRING', 'REQUEST_URI' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$_SERVER[ $key ] = self::without_auth( $_SERVER[ $key ], 'REQUEST_URI' === $key ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only the auth pair is removed; the remainder is left as received.
			}
		}
		return $result;
	}

	/** Whether this request supplied query credentials, including ones already captured. */
	public static function supplied( \WP_REST_Request $request ): bool {
		return array_key_exists( 'auth', $request->get_query_params() ) || ( null !== self::$captured && isset( self::$captured[ $request ] ) );
	}

	public static function authenticate( \WP_REST_Request $request ): bool {
		$config = Config::get();
		if ( Config::locked() || ! $config['enabled'] || ! $config['url_auth'] ) {
			return self::deny( 'url_auth_disabled' );
		}
		if ( ( ! is_ssl() && 'local' !== wp_get_environment_type() ) || ! self::is_mcp_route( $request ) ) {
			return self::deny( 'url_auth_rejected' );
		}
		$token = self::token( $request );
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return self::deny( 'url_auth_rejected' );
		}
		// Query decoders turn an unescaped plus into a space. The UI percent-encodes it.
		$token = strtr( str_replace( ' ', '+', $token ), '-_', '+/' );
		if ( ! preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/D', $token ) || 1 === strlen( rtrim( $token, '=' ) ) % 4 ) {
			return self::deny( 'url_auth_rejected' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode Basic credentials only; no code is evaluated.
		$decoded = base64_decode( $token, true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) || false !== strpos( $decoded, "\0" ) ) {
			return self::deny( 'url_auth_rejected' );
		}
		list( $username, $password ) = explode( ':', $decoded, 2 );
		if ( '' === $username || '' === $password ) {
			return self::deny( 'url_auth_rejected' );
		}
		// Passing null forces validation even when another mechanism set a current user.
		// Core records the authenticating password's UUID, which per-password limits read.
		$user = wp_authenticate_application_password( null, $username, $password );
		if ( ! $user instanceof \WP_User || ( get_current_user_id() && get_current_user_id() !== (int) $user->ID ) ) {
			return self::deny( 'url_auth_rejected' );
		}
		wp_set_current_user( $user->ID );
		if ( ! Permissions::administrator() ) {
			return self::deny( 'not_administrator' );
		}
		self::$url_uuid = Scopes::current_uuid();
		return true;
	}

	/** Whether this request's Application Password arrived in the URL rather than a header. */
	public static function used(): bool {
		return '' !== self::$url_uuid && Scopes::current_uuid() === self::$url_uuid;
	}

	/**
	 * The supplied credential, from the query or from capture.
	 *
	 * @return mixed
	 */
	private static function token( \WP_REST_Request $request ) {
		$query = $request->get_query_params();
		if ( array_key_exists( 'auth', $query ) ) {
			return $query['auth'];
		}
		return null !== self::$captured && isset( self::$captured[ $request ] ) ? self::$captured[ $request ] : null;
	}

	private static function deny( string $reason ): bool {
		// A rejected supplied token must not fall back to a cookie/header identity.
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_application_password_uuid'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clear the identity core recorded for a rejected login.
		return Permissions::denied( $reason );
	}

	/** Remove auth=… pairs from a query string, or from the query part of a request URI. */
	private static function without_auth( string $value, bool $uri ): string {
		$base  = '';
		$query = $value;
		if ( $uri ) {
			$position = strpos( $value, '?' );
			if ( false === $position ) {
				return $value;
			}
			$base  = substr( $value, 0, $position );
			$query = substr( $value, $position + 1 );
		}
		$pairs = array_filter(
			explode( '&', $query ),
			static function ( $pair ) {
				return 'auth' !== urldecode( explode( '=', $pair, 2 )[0] );
			}
		);
		$query = implode( '&', $pairs );
		return $uri ? $base . ( '' === $query ? '' : '?' . $query ) : $query;
	}

	public static function response( $response, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( self::is_mcp_route( $request ) && $response instanceof \WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
			if ( '' !== Permissions::denial() && $response->get_status() >= 400 ) {
				// A coarse reason (for example unauthenticated or https_required) for clients and the connection test.
				$response->header( 'X-Site-Agent-Auth', Permissions::denial() );
			}
			if ( 401 === $response->get_status() && OAuth::enabled() ) {
				// RFC 9728: tell MCP clients where to start OAuth discovery.
				$error = 'invalid_token' === Permissions::denial() ? ', error="invalid_token"' : '';
				$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . OAuth::resource_metadata_url() . '"' . $error );
			}
		}
		return $response;
	}
}
