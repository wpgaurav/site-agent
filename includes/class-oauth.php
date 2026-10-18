<?php
/**
 * OAuth 2.1 authorization for the MCP endpoint, following the MCP authorization specification:
 * protected resource and authorization server metadata, dynamic client registration, PKCE-only
 * authorization codes approved by a signed-in administrator, and short-lived bearer tokens.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** OAuth 2.1 authorization server and bearer authentication for the MCP route only. */
final class OAuth {
	const CLIENTS       = 'site_agent_oauth_client_';
	const GRANTS        = 'site_agent_oauth_grants'; // Legacy network-wide user meta, read only during migration.
	const GRANT_PREFIX  = 'site_agent_oauth_grant_';
	const CODE_PREFIX   = 'site_agent_oauth_code_';
	const PAGE          = 'site-agent-authorize';
	const BASE_SCOPE    = 'site-agent';
	const ACCESS_TTL    = HOUR_IN_SECONDS;
	const REFRESH_TTL   = 30 * DAY_IN_SECONDS;
	const CODE_TTL      = 10 * MINUTE_IN_SECONDS;
	const MAX_CLIENTS   = 200;
	const MAX_GRANTS    = 20;
	const MAX_REDIRECTS = 5;

	/**
	 * Grant that authenticated this request, with its client name.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $grant = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		// Before WordPress routing, so /.well-known paths never reach the 404 template.
		add_action( 'parse_request', array( self::class, 'well_known' ), 0 );
		add_action( 'admin_menu', array( self::class, 'hidden_page' ) );
		add_action( 'admin_init', array( self::class, 'authorize_page' ), 0 );
		// A client that appends its parameters with "?" sends page=site-agent-authorize?response_type=...,
		// which wp-admin refuses before admin_init.
		add_action( 'admin_page_access_denied', array( self::class, 'repair_authorize_url' ) );
		add_action( 'admin_post_site_agent_oauth_revoke', array( self::class, 'revoke_action' ) );
		add_action( 'admin_post_site_agent_oauth_remove_client', array( self::class, 'remove_client_action' ) );
	}

	public static function enabled(): bool {
		$config = Config::get();
		return ! Config::locked() && ! empty( $config['enabled'] ) && ! empty( $config['oauth'] );
	}

	public static function resource(): string {
		return untrailingslashit( rest_url( 'site-agent/v1/mcp' ) );
	}

	/**
	 * A path issuer under Site Agent's REST namespace. The site root's discovery documents can
	 * belong to another plugin's OAuth server (Rank Math's MCP server, for example), and a site
	 * has only one root issuer, so Site Agent never claims them.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url( '/' . rest_get_url_prefix() . '/site-agent/v1/oauth' ) );
	}

	/**
	 * Advertised without a query string, because some clients append their parameters with "?"
	 * whatever the endpoint already holds. It redirects to the consent screen. Without pretty
	 * permalinks REST URLs carry a query anyway, so the consent screen is advertised directly.
	 */
	public static function authorization_endpoint(): string {
		$url = rest_url( 'site-agent/v1/oauth/authorize' );
		return false === strpos( $url, '?' ) ? $url : admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * The consent screen URL carrying the client's parameters.
	 *
	 * @param array<string, mixed> $params Authorization request parameters.
	 */
	public static function consent_url( array $params ): string {
		unset( $params['page'], $params['rest_route'] );
		$params = array_filter( $params, 'is_scalar' );
		return admin_url( 'admin.php?' . http_build_query( array( 'page' => self::PAGE ) + $params, '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * Parameters of a consent URL whose client appended them with "?", or null for any other page.
	 *
	 * @param array<string, mixed> $get Query parameters.
	 * @return array<string, mixed>|null
	 */
	public static function repaired_params( array $get ): ?array {
		$page   = isset( $get['page'] ) && is_string( $get['page'] ) ? $get['page'] : '';
		$prefix = self::PAGE . '?';
		if ( 0 !== strpos( $page, $prefix ) ) {
			return null;
		}
		parse_str( substr( $page, strlen( $prefix ) ), $first );
		unset( $get['page'] );
		return array_merge( $first, $get );
	}

	/** Send a malformed consent URL to the real one. Runs where wp-admin would refuse it. */
	public static function repair_authorize_url(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only rebuilds the URL; the consent screen validates the request.
		$params = self::repaired_params( wp_unslash( $_GET ) );
		if ( null === $params ) {
			return;
		}
		nocache_headers();
		wp_safe_redirect( self::consent_url( $params ), 302, 'Site Agent' );
		exit;
	}

	/** Where clients find the protected resource metadata. Served through REST, so no server rewrite is needed. */
	public static function resource_metadata_url(): string {
		return rest_url( 'site-agent/v1/oauth/protected-resource' );
	}

	/**
	 * Scopes: the base scope for read tools, plus one per tool group that a grant may use.
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		return array_merge( array( self::BASE_SCOPE ), Scopes::GROUPS );
	}

	/**
	 * Tool groups this site currently allows, which a consent screen can offer.
	 *
	 * @return string[]
	 */
	private static function offered_groups(): array {
		$config = Config::get();
		return array_values(
			array_filter(
				Scopes::GROUPS,
				static function ( $group ) use ( $config ) {
					return ! empty( $config[ $group ] ) && Config::group_available( $group );
				}
			)
		);
	}

	public static function resource_metadata(): array {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::scopes(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'Site Agent',
		);
	}

	public static function server_metadata(): array {
		return array(
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => self::authorization_endpoint(),
			'token_endpoint'                             => rest_url( 'site-agent/v1/oauth/token' ),
			'registration_endpoint'                      => rest_url( 'site-agent/v1/oauth/register' ),
			'revocation_endpoint'                        => rest_url( 'site-agent/v1/oauth/revoke' ),
			'scopes_supported'                           => self::scopes(),
			'response_types_supported'                   => array( 'code' ),
			'response_modes_supported'                   => array( 'query' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	public static function routes(): void {
		$public = array(
			'permission_callback' => '__return_true',
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/protected-resource',
			array(
				'methods'  => 'GET',
				'callback' => array( self::class, 'rest_resource' ),
			) + $public
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/authorization-server',
			array(
				'methods'  => 'GET',
				'callback' => array( self::class, 'rest_server' ),
			) + $public
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/authorize',
			array(
				'methods'  => 'GET',
				'callback' => array( self::class, 'rest_authorize' ),
			) + $public
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/register',
			array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'register_client' ),
			) + $public
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/token',
			array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'token' ),
			) + $public
		);
		register_rest_route(
			'site-agent/v1',
			'/oauth/revoke',
			array(
				'methods'  => 'POST',
				'callback' => array( self::class, 'revoke' ),
			) + $public
		);
	}

	/** JSON response that is never cached. */
	private static function json( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	private static function error( string $code, string $description, int $status = 400 ): \WP_REST_Response {
		return self::json(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}

	public static function rest_resource(): \WP_REST_Response {
		return self::enabled() ? self::json( self::resource_metadata() ) : self::error( 'not_found', 'OAuth is not enabled on this site.', 404 );
	}

	public static function rest_server(): \WP_REST_Response {
		return self::enabled() ? self::json( self::server_metadata() ) : self::error( 'not_found', 'OAuth is not enabled on this site.', 404 );
	}

	/**
	 * The advertised authorization endpoint: redirects the browser to the consent screen with the
	 * request's parameters, which says so itself when OAuth is off.
	 */
	public static function rest_authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', self::consent_url( $request->get_query_params() ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		return $response;
	}

	/**
	 * Which metadata document a request path asks for: RFC 9728 and RFC 8414 insert the
	 * well-known segment at the host root before the resource or issuer path. Also answers the
	 * OpenID Connect discovery forms MCP clients try next, including the one appended to the
	 * issuer, which stays under the REST prefix. The bare root documents are left to others.
	 */
	public static function well_known_document( string $path ): string {
		// Canonical redirects may add a trailing slash; both forms name the same document.
		$path        = '/' . trim( $path, '/' );
		$issuer_path = untrailingslashit( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ) );
		$resource    = array( '/.well-known/oauth-protected-resource' . (string) wp_parse_url( self::resource(), PHP_URL_PATH ) );
		$server      = array(
			'/.well-known/oauth-authorization-server' . $issuer_path,
			'/.well-known/openid-configuration' . $issuer_path,
			$issuer_path . '/.well-known/openid-configuration',
			$issuer_path . '/.well-known/oauth-authorization-server',
		);
		if ( in_array( $path, $resource, true ) ) {
			return 'resource';
		}
		return in_array( $path, $server, true ) ? 'server' : '';
	}

	public static function well_known(): void {
		$uri      = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against fixed paths only.
		$path     = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$document = false !== strpos( $path, '/.well-known/' ) ? self::well_known_document( $path ) : '';
		if ( '' === $document || ! self::enabled() ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( 'resource' === $document ? self::resource_metadata() : self::server_metadata(), JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Registered clients keyed by client ID. Each client is its own option, so registrations and
	 * code exchanges never rewrite a shared list and cannot drop a client registered at the same time.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function clients(): array {
		global $wpdb;
		$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::CLIENTS ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Option names by prefix; authorization client values are always read freshly.
		$clients = array();
		foreach ( $names as $name ) {
			$client = self::client( substr( $name, strlen( self::CLIENTS ) ) );
			if ( $client ) {
				$clients[ substr( $name, strlen( self::CLIENTS ) ) ] = $client;
			}
		}
		return $clients;
	}

	public static function client( string $client_id ): ?array {
		if ( ! preg_match( '/^sa-[a-f0-9]{32}$/D', $client_id ) ) {
			return null;
		}
		// A slow option-cache refill can outlive administrator removal; authorization reads the row.
		return self::record( self::CLIENTS . $client_id );
	}

	/** Delete every registered client. */
	public static function delete_clients(): void {
		foreach ( array_keys( self::clients() ) as $client_id ) {
			delete_option( self::CLIENTS . $client_id );
		}
	}

	/**
	 * A redirect URI a public client may register: HTTPS, an HTTP loopback address for desktop
	 * clients, or a private-use scheme for native apps. Never a fragment or a script scheme.
	 */
	public static function valid_redirect( $uri ): bool {
		if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 2000 || false !== strpos( $uri, '#' ) || preg_match( '/\s/', $uri ) ) {
			return false;
		}
		$parts  = wp_parse_url( $uri );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}
		if ( 'http' === $scheme ) {
			return in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), array( '127.0.0.1', 'localhost', '[::1]', '::1' ), true );
		}
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme ) && ! in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file', 'about', 'blob', 'ftp', 'ws', 'wss' ), true );
	}

	/** RFC 7591 dynamic client registration for public clients. */
	public static function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::enabled() ) {
			return self::error( 'not_found', 'OAuth is not enabled on this site.', 404 );
		}
		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : $request->get_body_params();
		$redirects = $body['redirect_uris'] ?? null;
		if ( ! is_array( $redirects ) || ! $redirects || count( $redirects ) > self::MAX_REDIRECTS ) {
			return self::error( 'invalid_redirect_uri', 'Register between one and five redirect URIs.' );
		}
		foreach ( $redirects as $redirect ) {
			if ( ! self::valid_redirect( $redirect ) ) {
				return self::error( 'invalid_redirect_uri', 'Redirect URIs must use HTTPS, an HTTP loopback address, or a private-use scheme, without a fragment.' );
			}
		}
		$method = (string) ( $body['token_endpoint_auth_method'] ?? 'none' );
		if ( 'none' !== $method ) {
			return self::error( 'invalid_client_metadata', 'Only public clients with token_endpoint_auth_method "none" and PKCE are supported.' );
		}
		foreach ( (array) ( $body['grant_types'] ?? array( 'authorization_code' ) ) as $grant_type ) {
			if ( ! in_array( $grant_type, array( 'authorization_code', 'refresh_token' ), true ) ) {
				return self::error( 'invalid_client_metadata', 'Supported grant types are authorization_code and refresh_token.' );
			}
		}
		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			self::prune_clients( $clients );
			$clients = self::clients();
		}
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			return self::error( 'invalid_client_metadata', 'Too many registered clients. Remove unused OAuth clients in Tools > Site Agent.', 429 );
		}
		$client_id = 'sa-' . bin2hex( random_bytes( 16 ) );
		$name      = mb_substr( sanitize_text_field( (string) ( $body['client_name'] ?? '' ) ), 0, 100 );
		$client    = array(
			'client_name'   => '' !== $name ? $name : __( 'Unnamed MCP client', 'site-agent' ),
			'redirect_uris' => array_values( array_map( 'strval', $redirects ) ),
			'created'       => time(),
			'used'          => 0,
		);
		if ( ! add_option( self::CLIENTS . $client_id, $client, '', false ) ) {
			return self::error( 'temporarily_unavailable', 'The client could not be registered. Try again.', 503 );
		}
		return self::json(
			array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => $client['created'],
				'client_name'                => $client['client_name'],
				'redirect_uris'              => $client['redirect_uris'],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			),
			201
		);
	}

	/**
	 * Delete only never-approved registrations older than a day. Approved clients require explicit cleanup.
	 *
	 * @param array<string, array<string, mixed>> $clients Registered clients.
	 */
	private static function prune_clients( array $clients ): void {
		foreach ( $clients as $client_id => $client ) {
			if ( empty( $client['used'] ) && (int) ( $client['created'] ?? 0 ) <= time() - DAY_IN_SECONDS ) {
				self::compare_swap( self::CLIENTS . $client_id, $client, null );
			}
		}
	}

	/** Explicit administrator cleanup, including registrations filling the public registration limit. */
	public static function remove_client_action(): void {
		if ( ! Permissions::administrator() ) {
			wp_die( esc_html__( 'Site Agent requires site administration rights.', 'site-agent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'site_agent_oauth_remove_client' );
		$id      = isset( $_POST['client'] ) ? sanitize_key( wp_unslash( $_POST['client'] ) ) : '';
		$clients = 'unused' === $id ? self::clients() : array( $id => self::client( $id ) );
		foreach ( $clients as $client_id => $client ) {
			if ( ! $client ) {
				continue;
			}
			for ( $attempt = 0; $attempt < 8; ++$attempt ) {
				$client = self::record( self::CLIENTS . $client_id );
				if ( ! $client || ( 'unused' === $id && ! empty( $client['used'] ) ) || self::compare_swap( self::CLIENTS . $client_id, $client, null ) ) {
					break;
				}
				// Bulk cleanup preserves any registration that becomes active during cleanup.
				if ( 'unused' === $id ) {
					break;
				}
				if ( 7 === $attempt ) {
					wp_die( esc_html__( 'The connection changed. Try again.', 'site-agent' ), '', array( 'response' => 503 ) );
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'oauth_clients_removed', 1, admin_url( 'tools.php?page=site-agent#site-agent-oauth' ) ) );
		exit;
	}

	/**
	 * Parse and check an authorization request. Errors carry whether they may be returned to the redirect URI.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function authorization_request( array $params ) {
		$client_id = is_string( $params['client_id'] ?? null ) ? $params['client_id'] : '';
		$client    = self::client( $client_id );
		$redirect  = is_string( $params['redirect_uri'] ?? null ) ? $params['redirect_uri'] : '';
		if ( ! $client ) {
			return new \WP_Error( 'invalid_client', __( 'This application is not registered with Site Agent. Connect it again from the application.', 'site-agent' ) );
		}
		if ( count( $client['redirect_uris'] ) > 1 || '' !== $redirect ) {
			if ( ! in_array( $redirect, $client['redirect_uris'], true ) ) {
				return new \WP_Error( 'invalid_redirect_uri', __( 'The redirect address does not match the one this application registered.', 'site-agent' ) );
			}
		} else {
			$redirect = $client['redirect_uris'][0];
		}
		$state   = is_string( $params['state'] ?? null ) ? $params['state'] : '';
		$request = array(
			'client_id'    => $client_id,
			'client'       => $client,
			'redirect_uri' => $redirect,
			'state'        => $state,
		);
		$fail    = static function ( string $code, string $message ) use ( $request ) {
			return new \WP_Error( $code, $message, array( 'redirect' => $request ) );
		};
		if ( strlen( $state ) > 1000 ) {
			return $fail( 'invalid_request', 'The state parameter is too long.' );
		}
		if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
			return $fail( 'unsupported_response_type', 'Only the authorization code flow is supported.' );
		}
		$challenge = is_string( $params['code_challenge'] ?? null ) ? $params['code_challenge'] : '';
		if ( 'S256' !== ( $params['code_challenge_method'] ?? '' ) || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/D', $challenge ) ) {
			return $fail( 'invalid_request', 'PKCE with code_challenge_method S256 is required.' );
		}
		$resource = is_string( $params['resource'] ?? null ) ? $params['resource'] : '';
		if ( '' !== $resource && ! self::same_resource( $resource ) ) {
			return $fail( 'invalid_target', 'The resource parameter must be this site\'s Site Agent MCP endpoint.' );
		}
		$scope = is_string( $params['scope'] ?? null ) ? $params['scope'] : '';
		$asked = array_values( array_filter( preg_split( '/\s+/', trim( $scope ) ) ) );
		if ( array_diff( $asked, self::scopes() ) ) {
			return $fail( 'invalid_scope', 'Unknown scope requested.' );
		}
		return $request + array(
			'code_challenge' => $challenge,
			'resource'       => self::resource(),
			// No requested groups means "whatever the site allows", like an unrestricted Application Password.
			'groups'         => array_values( array_intersect( self::offered_groups(), $asked ? $asked : self::offered_groups() ) ),
		);
	}

	public static function same_resource( string $target ): bool {
		return untrailingslashit( strtolower( $target ) ) === strtolower( self::resource() );
	}

	/** Redirect back to the client with query parameters, including the RFC 9207 issuer. */
	private static function redirect_back( array $request, array $params ): void {
		$params['iss'] = self::issuer();
		if ( '' !== $request['state'] ) {
			$params['state'] = $request['state'];
		}
		$location = $request['redirect_uri'] . ( false === strpos( $request['redirect_uri'], '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		nocache_headers();
		header( 'Location: ' . $location, true, 302 );
		exit;
	}

	/**
	 * Register the consent screen as a hidden page, so wp-admin lets signed-in users reach
	 * admin_init, where authorize_page() answers and exits. Non-administrators get access_denied there.
	 */
	public static function hidden_page(): void {
		add_submenu_page( '', __( 'Connect to Site Agent', 'site-agent' ), '', 'read', self::PAGE, '__return_null' );
	}

	/** The consent screen at wp-admin/admin.php?page=site-agent-authorize. wp-admin has already required a login. */
	public static function authorize_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth parameters arrive from the client; the POST is nonce-checked below.
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		$params = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		if ( ! self::enabled() ) {
			self::page( __( 'OAuth is off', 'site-agent' ), '<p>' . esc_html__( 'Turn on Site Agent and OAuth connections in Tools > Site Agent, then connect again from your application.', 'site-agent' ) . '</p>' );
		}
		$request = self::authorization_request( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $request ) ) {
			$data = $request->get_error_data();
			if ( is_array( $data ) && isset( $data['redirect'] ) ) {
				self::redirect_back(
					$data['redirect'],
					array(
						'error'             => $request->get_error_code(),
						'error_description' => $request->get_error_message(),
					)
				);
			}
			self::page( __( 'Cannot connect', 'site-agent' ), '<p>' . esc_html( $request->get_error_message() ) . '</p>' );
		}
		if ( ! Permissions::administrator() ) {
			self::redirect_back(
				$request,
				array(
					'error'             => 'access_denied',
					'error_description' => 'Site Agent requires an administrator account.',
				)
			);
		}
		$nonce_action = 'site_agent_authorize_' . $request['client_id'];
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared with a fixed string only.
			check_admin_referer( $nonce_action );
			if ( empty( $_POST['approve'] ) ) {
				self::redirect_back(
					$request,
					array(
						'error'             => 'access_denied',
						'error_description' => 'The administrator declined the connection.',
					)
				);
			}
			$chosen = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['groups'] ) ) : array();
			$code   = self::issue_code( $request, array_values( array_intersect( self::offered_groups(), $chosen ) ) );
			self::redirect_back( $request, array( 'code' => $code ) );
		}
		self::consent( $request, $nonce_action );
	}

	/** Store a single-use authorization code bound to the client, redirect URI, PKCE challenge and user. */
	public static function issue_code( array $request, array $groups ): string {
		$code = self::random();
		// Approval protects a live authorization code from unused-registration cleanup.
		self::mark_client_used( (string) $request['client_id'] );
		set_transient(
			self::CODE_PREFIX . hash( 'sha256', $code ),
			array(
				'client_id'      => $request['client_id'],
				'redirect_uri'   => $request['redirect_uri'],
				'code_challenge' => $request['code_challenge'],
				'resource'       => $request['resource'],
				'groups'         => $groups,
				'user_id'        => get_current_user_id(),
				'expires'        => time() + self::CODE_TTL,
			),
			self::CODE_TTL
		);
		return $code;
	}

	private static function consent( array $request, string $nonce_action ): void {
		$labels = array(
			'content_write' => __( 'Create drafts and edit posts, pages, terms and media', 'site-agent' ),
			'file_read'     => __( 'Read plugin and theme source files', 'site-agent' ),
			'file_write'    => __( 'Change plugin and theme source files', 'site-agent' ),
			'php_execute'   => __( 'Run PHP with full server privileges', 'site-agent' ),
			'cli_execute'   => __( 'Run WP-CLI commands', 'site-agent' ),
			'bricks'        => __( 'Use Bricks Builder tools', 'site-agent' ),
		);
		$host   = (string) wp_parse_url( $request['redirect_uri'], PHP_URL_HOST );
		$body   = '<p>' . sprintf(
			/* translators: 1: application name, 2: site name. */
			esc_html__( '%1$s wants to connect to %2$s through Site Agent as you.', 'site-agent' ),
			'<strong>' . esc_html( $request['client']['client_name'] ) . '</strong>',
			'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
		) . '</p>';
		$body .= '<p class="sa-muted">' . esc_html(
			sprintf(
				/* translators: %s: redirect address host or scheme. */
				__( 'After you decide, you return to %s. Only approve applications you started connecting yourself.', 'site-agent' ),
				'' !== $host ? $host : (string) wp_parse_url( $request['redirect_uri'], PHP_URL_SCHEME ) . ':'
			)
		) . '</p>';
		$body   .= '<form method="post">' . wp_nonce_field( $nonce_action, '_wpnonce', true, false );
		$body   .= '<fieldset><legend>' . esc_html__( 'It can always read site details and content. Allow it to also:', 'site-agent' ) . '</legend>';
		$offered = self::offered_groups();
		foreach ( $offered as $group ) {
			$body .= '<label><input type="checkbox" name="groups[]" value="' . esc_attr( $group ) . '"' . checked( in_array( $group, $request['groups'], true ), true, false ) . '> ' . esc_html( $labels[ $group ] ?? $group ) . '</label>';
		}
		if ( ! $offered ) {
			$body .= '<p class="sa-muted">' . esc_html__( 'No other tool groups are turned on in Tools > Site Agent.', 'site-agent' ) . '</p>';
		}
		$body .= '</fieldset><p class="sa-muted">' . esc_html__( 'Access lasts until you revoke it in Tools > Site Agent, or for 30 days without use.', 'site-agent' ) . '</p>';
		$body .= '<div class="sa-actions"><button type="submit" name="approve" value="1" class="sa-primary">' . esc_html__( 'Allow', 'site-agent' ) . '</button><button type="submit" name="approve" value="">' . esc_html__( 'Deny', 'site-agent' ) . '</button></div></form>';
		self::page( __( 'Connect to Site Agent', 'site-agent' ), $body );
	}

	/** A small standalone page, outside the admin chrome, then exit. */
	private static function page( string $title, string $body ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;background:#f0f0f1;color:#1d2327;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}main{max-width:460px;margin:8vh auto;padding:28px;background:#fff;border:1px solid #dcdcde;border-radius:8px}h1{font-size:21px;margin:0 0 14px}fieldset{border:0;padding:0;margin:18px 0}legend{font-weight:600;margin-bottom:8px}label{display:flex;gap:8px;align-items:flex-start;margin:8px 0}input{margin-top:4px}.sa-muted{color:#50575e;font-size:13px}.sa-actions{display:flex;gap:10px;margin-top:20px}button{font:inherit;padding:9px 18px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer}button.sa-primary{background:#2271b1;color:#fff}@media(max-width:520px){main{margin:0;border:0;border-radius:0;min-height:100vh}}</style>';
		echo '</head><body><main><h1>' . esc_html( $title ) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body is assembled from escaped parts above.
		exit;
	}

	private static function random(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of random bytes for a token.
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'secure_auth' ) );
	}

	/** Site-local option name. User meta is network-global and cannot hold unbound grants. */
	private static function grant_key( int $user_id, string $grant_id ): string {
		return self::GRANT_PREFIX . $user_id . '_' . $grant_id;
	}

	/** Read the database value, bypassing potentially stale persistent option caches. */
	private static function record( string $key ): ?array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh read is required for the compare-and-swap protocol.
		$value = null === $value ? null : maybe_unserialize( $value );
		return is_array( $value ) ? $value : null;
	}

	/** Insert only: add_option() may update a duplicate row after a concurrent cache miss. */
	private static function insert_record( string $key, array $value ): bool {
		global $wpdb;
		$inserted = 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, maybe_serialize( $value ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The unique option_name must reject duplicates without replacing an existing tombstone.
		if ( $inserted ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return $inserted;
	}

	/** The option row is the serialization point; an outdated request cannot overwrite a new row. */
	private static function compare_swap( string $key, array $before, ?array $after ): bool {
		global $wpdb;
		if ( null === $after ) {
			$query = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, maybe_serialize( $before ) );
		} else {
			$query = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $after ), $key, maybe_serialize( $before ) );
		}
		$changed = 1 === $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Both query branches prepare their arguments; the expected-value mutation must be atomic.
		if ( $changed ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return $changed;
	}

	private static function bound( array $grant ): bool {
		return ( $grant['resource'] ?? '' ) === self::resource() && (int) ( $grant['blog_id'] ?? 0 ) === get_current_blog_id();
	}

	/** Import a legacy grant only where its randomly registered client exists locally. */
	private static function grant_record( int $user_id, string $grant_id ): ?array {
		if ( $user_id < 1 || ! preg_match( '/^[a-f0-9]{16}$/D', $grant_id ) ) {
			return null;
		}
		$key   = self::grant_key( $user_id, $grant_id );
		$grant = self::record( $key );
		if ( null !== $grant ) {
			return self::bound( $grant ) ? $grant : null;
		}
		$legacy = get_user_meta( $user_id, self::GRANTS, true );
		$grant  = is_array( $legacy ) ? ( $legacy[ $grant_id ] ?? null ) : null;
		if ( ! is_array( $grant ) || ! self::client( (string) ( $grant['client_id'] ?? '' ) ) || (int) ( $grant['refresh_expires'] ?? 0 ) <= time() || ( isset( $grant['resource'] ) && $grant['resource'] !== self::resource() ) ) {
			return null;
		}
		$grant['resource'] = self::resource();
		$grant['blog_id']  = get_current_blog_id();
		// Insert-only semantics prevent simultaneous imports from replacing a revocation.
		self::insert_record( $key, $grant );
		$grant = self::record( $key );
		return $grant && self::bound( $grant ) ? $grant : null;
	}

	/**
	 * Live grants for this user and resource. Legacy user meta is preserved for other sites.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function grants( int $user_id ): array {
		global $wpdb;
		$legacy = get_user_meta( $user_id, self::GRANTS, true );
		foreach ( is_array( $legacy ) ? array_keys( $legacy ) : array() as $grant_id ) {
			self::grant_record( $user_id, (string) $grant_id );
		}
		$prefix = self::GRANT_PREFIX . $user_id . '_';
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Site-local grant names; grant values are read freshly for CAS.
		$grants = array();
		foreach ( $names as $name ) {
			$grant = self::record( $name );
			if ( ! $grant || ! self::bound( $grant ) ) {
				continue;
			}
			if ( (int) ( $grant['refresh_expires'] ?? 0 ) <= time() ) {
				// A revoked legacy import stays as a tombstone until its legacy token expires.
				self::compare_swap( $name, $grant, null );
			} elseif ( empty( $grant['revoked'] ) ) {
				$grants[ substr( $name, strlen( $prefix ) ) ] = $grant;
			}
		}
		return $grants;
	}

	/** End one family without deleting its legacy-import tombstone or touching another site. */
	private static function revoke_grant( int $user_id, string $grant_id ): bool {
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$grant = self::grant_record( $user_id, $grant_id );
			if ( ! $grant || ! empty( $grant['revoked'] ) ) {
				return true;
			}
			$revoked            = $grant;
			$revoked['revoked'] = true;
			if ( self::compare_swap( self::grant_key( $user_id, $grant_id ), $grant, $revoked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mint a new access and refresh token pair for a grant.
	 *
	 * @param array<string, mixed> $grant Grant to update.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The stored grant and the token response.
	 */
	private static function mint( int $user_id, string $grant_id, array $grant ): array {
		$access = 'sa_' . $user_id . '_' . $grant_id . '_' . self::random();
		if ( empty( $grant['refresh_family'] ) ) {
			// Only legacy opaque tokens require retained hashes; all new ancestors authenticate themselves.
			$grant['legacy_refresh_hashes'] = array_values( array_filter( array( $grant['refresh_hash'] ?? '', $grant['previous_refresh'] ?? '' ) ) );
			$grant['refresh_family']        = self::random();
			$grant['refresh_generation']    = -1;
		}
		++$grant['refresh_generation'];
		$refresh                  = 'sar_' . $user_id . '_' . $grant_id . '_' . self::refresh_suffix( $grant, $grant['refresh_generation'] );
		$grant['access_hash']     = self::hash( $access );
		$grant['access_expires']  = time() + self::ACCESS_TTL;
		$grant['refresh_hash']    = self::hash( $refresh );
		$grant['refresh_expires'] = time() + self::REFRESH_TTL;
		unset( $grant['previous_refresh'] );
		$scope = implode( ' ', array_merge( array( self::BASE_SCOPE ), $grant['groups'] ) );
		return array(
			$grant,
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => $scope,
			),
		);
	}

	/** Fixed-size family authentication recognizes every ancestor without an ever-growing hash list. */
	private static function refresh_suffix( array $grant, int $generation ): string {
		$counter   = sprintf( '%08x', $generation );
		$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'site-agent-refresh:' . $grant['refresh_family'] . ':' . $counter, wp_salt( 'secure_auth' ), true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url of a 210-bit refresh-family authenticator.
		return $counter . substr( $signature, 0, 35 );
	}

	private static function refresh_replayed( array $grant, string $token ): bool {
		$hash = self::hash( $token );
		foreach ( array_merge( $grant['legacy_refresh_hashes'] ?? array(), array( $grant['previous_refresh'] ?? '' ) ) as $old ) {
			if ( '' !== $old && hash_equals( $old, $hash ) ) {
				return true;
			}
		}
		$suffix = substr( $token, -43 );
		if ( empty( $grant['refresh_family'] ) || ! preg_match( '/^[a-f0-9]{8}$/D', substr( $suffix, 0, 8 ) ) ) {
			return false;
		}
		$generation = (int) hexdec( substr( $suffix, 0, 8 ) );
		return $generation < (int) $grant['refresh_generation'] && hash_equals( self::refresh_suffix( $grant, $generation ), $suffix );
	}

	/**
	 * Split a token into its user and grant, or null.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private static function locate( string $token, string $prefix ): ?array {
		if ( ! preg_match( '/^' . $prefix . '_(\d{1,20})_([a-f0-9]{16})_[A-Za-z0-9_-]{43}$/D', $token, $match ) ) {
			return null;
		}
		return array( (int) $match[1], $match[2] );
	}

	/** RFC 6749 token endpoint: authorization_code with PKCE, and rotating refresh_token. */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::enabled() ) {
			return self::error( 'not_found', 'OAuth is not enabled on this site.', 404 );
		}
		$params = $request->get_params();
		$type   = (string) ( $params['grant_type'] ?? '' );
		if ( 'authorization_code' === $type ) {
			return self::exchange_code( $params );
		}
		if ( 'refresh_token' === $type ) {
			return self::refresh( $params );
		}
		return self::error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	private static function exchange_code( array $params ): \WP_REST_Response {
		$code = is_string( $params['code'] ?? null ) ? $params['code'] : '';
		$key  = self::CODE_PREFIX . hash( 'sha256', $code );
		$data = '' !== $code ? get_transient( $key ) : false;
		// Single use: whoever deletes the code first wins.
		if ( ! is_array( $data ) || ! delete_transient( $key ) || (int) $data['expires'] < time() ) {
			return self::error( 'invalid_grant', 'The authorization code is invalid, expired or already used.' );
		}
		if ( ( $params['client_id'] ?? '' ) !== $data['client_id'] || ! self::client( $data['client_id'] ) ) {
			return self::error( 'invalid_grant', 'The authorization code was issued to another client.' );
		}
		if ( isset( $params['redirect_uri'] ) && $params['redirect_uri'] !== $data['redirect_uri'] ) {
			return self::error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
		}
		$verifier = is_string( $params['code_verifier'] ?? null ) ? $params['code_verifier'] : '';
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256 challenge.
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $verifier ) || ! hash_equals( $data['code_challenge'], $computed ) ) {
			return self::error( 'invalid_grant', 'PKCE verification failed.' );
		}
		if ( ( $data['resource'] ?? '' ) !== self::resource() || ( isset( $params['resource'] ) && ( ! is_string( $params['resource'] ) || ! self::same_resource( $params['resource'] ) ) ) ) {
			return self::error( 'invalid_target', 'The resource must be this site\'s Site Agent MCP endpoint.' );
		}
		$user_id = (int) $data['user_id'];
		if ( ! user_can( $user_id, 'manage_options' ) || ( is_multisite() && ! is_super_admin( $user_id ) ) ) {
			return self::error( 'invalid_grant', 'The approving account is no longer an administrator.' );
		}
		$grant_id                 = bin2hex( random_bytes( 8 ) );
		list( $grant, $response ) = self::mint(
			$user_id,
			$grant_id,
			array(
				'resource'  => self::resource(),
				'blog_id'   => get_current_blog_id(),
				'client_id' => $data['client_id'],
				'groups'    => (array) $data['groups'],
				'created'   => time(),
				'last_used' => 0,
			)
		);
		$grants                   = self::grants( $user_id );
		if ( count( $grants ) >= self::MAX_GRANTS ) {
			return self::error( 'invalid_grant', 'Too many active connections. Revoke an unused connection in Tools > Site Agent.' );
		}
		if ( ! self::insert_record( self::grant_key( $user_id, $grant_id ), $grant ) ) {
			return self::error( 'temporarily_unavailable', 'The connection could not be saved. Connect again.', 503 );
		}
		self::mark_client_used( $data['client_id'] );
		return self::json( $response );
	}

	private static function refresh( array $params ): \WP_REST_Response {
		$token = is_string( $params['refresh_token'] ?? null ) ? $params['refresh_token'] : '';
		$where = self::locate( $token, 'sar' );
		if ( ! $where ) {
			return self::error( 'invalid_grant', 'The refresh token is invalid.' );
		}
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$before = self::grant_record( $where[0], $where[1] );
			if ( ! $before || ! empty( $before['revoked'] ) || ! self::client( (string) $before['client_id'] ) || ( $params['client_id'] ?? '' ) !== $before['client_id'] ) {
				return self::error( 'invalid_grant', 'The refresh token is invalid.' );
			}
			if ( self::refresh_replayed( $before, $token ) ) {
				if ( ! self::revoke_grant( $where[0], $where[1] ) ) {
					return self::error( 'temporarily_unavailable', 'The connection changed. Try again.', 503 );
				}
				return self::error( 'invalid_grant', 'The refresh token was already used. Connect again.' );
			}
			if ( ! hash_equals( (string) $before['refresh_hash'], self::hash( $token ) ) || (int) $before['refresh_expires'] <= time() ) {
				return self::error( 'invalid_grant', 'The refresh token is invalid or expired.' );
			}
			if ( ! user_can( $where[0], 'manage_options' ) || ( is_multisite() && ! is_super_admin( $where[0] ) ) ) {
				return self::error( 'invalid_grant', 'The approving account is no longer an administrator.' );
			}
			if ( isset( $params['resource'] ) && ( ! is_string( $params['resource'] ) || ! self::same_resource( $params['resource'] ) ) ) {
				return self::error( 'invalid_target', 'The resource must be this site\'s Site Agent MCP endpoint.' );
			}
			// Never wrap the authenticated counter and accidentally make an old ancestor current again.
			if ( (int) ( $before['refresh_generation'] ?? -1 ) >= 0xffffffff ) {
				return self::error( 'invalid_grant', 'Reconnect this application to renew its token family.' );
			}
			$grant = $before;
			$scope = is_string( $params['scope'] ?? null ) ? array_filter( preg_split( '/\s+/', trim( $params['scope'] ) ) ) : array();
			if ( $scope ) {
				if ( array_diff( $scope, array_merge( array( self::BASE_SCOPE ), $grant['groups'] ) ) ) {
					return self::error( 'invalid_scope', 'A refresh can only narrow the granted scope.' );
				}
				$grant['groups'] = array_values( array_intersect( $grant['groups'], $scope ) );
			}
			list( $grant, $response ) = self::mint( $where[0], $where[1], $grant );
			if ( self::compare_swap( self::grant_key( $where[0], $where[1] ), $before, $grant ) ) {
				self::mark_client_used( $grant['client_id'] );
				return self::json( $response );
			}
		}
		return self::error( 'temporarily_unavailable', 'The connection changed. Try again.', 503 );
	}

	private static function mark_client_used( string $client_id ): void {
		$key = self::CLIENTS . $client_id;
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$client = self::record( $key );
			if ( ! $client || (int) ( $client['used'] ?? 0 ) >= time() - 300 ) {
				return;
			}
			$used         = $client;
			$used['used'] = time();
			if ( self::compare_swap( $key, $client, $used ) ) {
				return;
			}
		}
	}

	/** RFC 7009 revocation. Unknown tokens still answer 200. */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$where = self::locate( $token, 'sar' ) ?? self::locate( $token, 'sa' );
		if ( $where ) {
			$grant = self::grant_record( $where[0], $where[1] );
			$hash  = self::hash( $token );
			if ( $grant && empty( $grant['revoked'] ) && ( hash_equals( (string) $grant['access_hash'], $hash ) || hash_equals( (string) $grant['refresh_hash'], $hash ) || self::refresh_replayed( $grant, $token ) ) && ! self::revoke_grant( $where[0], $where[1] ) ) {
				return self::error( 'temporarily_unavailable', 'The connection changed. Try again.', 503 );
			}
		}
		return self::json( array() );
	}

	/** Whether this request carries a bearer token. */
	public static function supplied( \WP_REST_Request $request ): bool {
		return 0 === stripos( (string) $request->get_header( 'authorization' ), 'Bearer ' );
	}

	/** Authenticate a bearer token on the MCP route. A rejected token never falls back to another identity. */
	public static function authenticate( \WP_REST_Request $request ): bool {
		self::$grant = null;
		if ( ! self::enabled() ) {
			return self::deny( 'oauth_disabled' );
		}
		if ( ( ! is_ssl() && 'local' !== wp_get_environment_type() ) || ! Url_Auth::is_mcp_route( $request ) || Url_Auth::supplied( $request ) ) {
			return self::deny( 'oauth_rejected' );
		}
		$token = trim( substr( (string) $request->get_header( 'authorization' ), 7 ) );
		$where = self::locate( $token, 'sa' );
		if ( ! $where ) {
			return self::deny( 'invalid_token' );
		}
		$grant = null;
		for ( $attempt = 0; $attempt < 8; ++$attempt ) {
			$grant = self::grant_record( $where[0], $where[1] );
			if ( ! $grant || ! empty( $grant['revoked'] ) || ! self::client( (string) $grant['client_id'] ) || ! hash_equals( (string) $grant['access_hash'], self::hash( $token ) ) || (int) $grant['access_expires'] <= time() ) {
				return self::deny( 'invalid_token' );
			}
			if ( (int) ( $grant['last_used'] ?? 0 ) >= time() - 300 ) {
				break;
			}
			$used              = $grant;
			$used['last_used'] = time();
			if ( self::compare_swap( self::grant_key( $where[0], $where[1] ), $grant, $used ) ) {
				break;
			}
			$grant = null;
		}
		if ( ! $grant ) {
			return self::deny( 'invalid_token' );
		}
		wp_set_current_user( $where[0] );
		if ( ! Permissions::administrator() ) {
			return self::deny( 'not_administrator' );
		}
		$client      = self::client( (string) $grant['client_id'] );
		self::$grant = array(
			'id'          => $where[1],
			'client_id'   => (string) $grant['client_id'],
			'client_name' => $client ? (string) $client['client_name'] : __( 'Removed client', 'site-agent' ),
			'groups'      => (array) $grant['groups'],
		);
		self::mark_client_used( (string) $grant['client_id'] );
		return true;
	}

	private static function deny( string $reason ): bool {
		self::$grant = null;
		wp_set_current_user( 0 );
		return Permissions::denied( $reason );
	}

	/**
	 * The grant that authenticated this request, or null for other authentication.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$grant;
	}

	/** Test helper: forget the request's grant. */
	public static function reset(): void {
		self::$grant = null;
	}

	/** Revoke one of the current user's grants from Tools > Site Agent. */
	public static function revoke_action(): void {
		if ( ! Permissions::administrator() ) {
			wp_die( esc_html__( 'Site Agent requires site administration rights.', 'site-agent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'site_agent_oauth_revoke' );
		$grant_id = isset( $_POST['grant'] ) ? sanitize_key( wp_unslash( $_POST['grant'] ) ) : '';
		$grants   = self::grants( get_current_user_id() );
		foreach ( array_keys( $grants ) as $id ) {
			if ( ( 'all' === $grant_id || $id === $grant_id ) && ! self::revoke_grant( get_current_user_id(), $id ) ) {
				wp_die( esc_html__( 'The connection changed. Try again.', 'site-agent' ), '', array( 'response' => 503 ) );
			}
		}
		wp_safe_redirect( add_query_arg( 'oauth_revoked', 1, admin_url( 'tools.php?page=site-agent#site-agent-oauth' ) ) );
		exit;
	}
}
