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
	const CLIENTS       = 'site_agent_oauth_clients';
	const GRANTS        = 'site_agent_oauth_grants';
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
		add_action( 'admin_post_site_agent_oauth_revoke', array( self::class, 'revoke_action' ) );
	}

	public static function enabled(): bool {
		$config = Config::get();
		return ! Config::locked() && ! empty( $config['enabled'] ) && ! empty( $config['oauth'] );
	}

	public static function resource(): string {
		return untrailingslashit( rest_url( 'site-agent/v1/mcp' ) );
	}

	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	public static function authorization_endpoint(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
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
	 * Which metadata document a request path asks for: RFC 9728 and RFC 8414 put the well-known
	 * segment at the host root, followed by the resource or issuer path. Also answers OpenID
	 * Connect discovery paths that some clients try.
	 */
	public static function well_known_document( string $path ): string {
		$path         = '/' . ltrim( $path, '/' );
		$issuer_path  = untrailingslashit( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ) );
		$resource_url = (string) wp_parse_url( self::resource(), PHP_URL_PATH );
		$resource     = array( '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource' . $resource_url );
		$server       = array(
			'/.well-known/oauth-authorization-server' . $issuer_path,
			'/.well-known/openid-configuration' . $issuer_path,
			$issuer_path . '/.well-known/openid-configuration',
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
	 * Registered clients keyed by client ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function clients(): array {
		$clients = get_option( self::CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	public static function client( string $client_id ): ?array {
		$clients = self::clients();
		return isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
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
		$clients = self::prune_clients( self::clients() );
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			return self::error( 'invalid_client_metadata', 'Too many registered clients. Remove unused OAuth clients in Tools > Site Agent.', 429 );
		}
		$client_id             = 'sa-' . bin2hex( random_bytes( 16 ) );
		$name                  = mb_substr( sanitize_text_field( (string) ( $body['client_name'] ?? '' ) ), 0, 100 );
		$client                = array(
			'client_name'   => '' !== $name ? $name : __( 'Unnamed MCP client', 'site-agent' ),
			'redirect_uris' => array_values( array_map( 'strval', $redirects ) ),
			'created'       => time(),
			'used'          => 0,
		);
		$clients[ $client_id ] = $client;
		update_option( self::CLIENTS, $clients, false );
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
	 * Drop clients that never completed an authorization within a day, and clients unused for 90 days.
	 *
	 * @param array<string, array<string, mixed>> $clients Registered clients.
	 * @return array<string, array<string, mixed>>
	 */
	private static function prune_clients( array $clients ): array {
		$now = time();
		return array_filter(
			$clients,
			static function ( $client ) use ( $now ) {
				$used = (int) ( $client['used'] ?? 0 );
				return $used ? $used > $now - 90 * DAY_IN_SECONDS : (int) ( $client['created'] ?? 0 ) > $now - DAY_IN_SECONDS;
			}
		);
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

	/**
	 * Grants of a user, keyed by grant ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function grants( int $user_id ): array {
		$grants = get_user_meta( $user_id, self::GRANTS, true );
		return is_array( $grants ) ? $grants : array();
	}

	/**
	 * Keep live grants only, newest last, at most MAX_GRANTS.
	 *
	 * @param array<string, array<string, mixed>> $grants Grants.
	 */
	private static function save_grants( int $user_id, array $grants ): void {
		$now    = time();
		$grants = array_filter(
			$grants,
			static function ( $grant ) use ( $now ) {
				return (int) ( $grant['refresh_expires'] ?? 0 ) > $now;
			}
		);
		$grants = array_slice( $grants, -self::MAX_GRANTS, null, true );
		if ( $grants ) {
			update_user_meta( $user_id, self::GRANTS, $grants );
		} else {
			delete_user_meta( $user_id, self::GRANTS );
		}
	}

	/**
	 * Mint a new access and refresh token pair for a grant.
	 *
	 * @param array<string, mixed> $grant Grant to update.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The stored grant and the token response.
	 */
	private static function mint( int $user_id, string $grant_id, array $grant ): array {
		$access                    = 'sa_' . $user_id . '_' . $grant_id . '_' . self::random();
		$refresh                   = 'sar_' . $user_id . '_' . $grant_id . '_' . self::random();
		$grant['previous_refresh'] = $grant['refresh_hash'] ?? '';
		$grant['access_hash']      = self::hash( $access );
		$grant['access_expires']   = time() + self::ACCESS_TTL;
		$grant['refresh_hash']     = self::hash( $refresh );
		$grant['refresh_expires']  = time() + self::REFRESH_TTL;
		$scope                     = implode( ' ', array_merge( array( self::BASE_SCOPE ), $grant['groups'] ) );
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
		if ( isset( $params['resource'] ) && ( ! is_string( $params['resource'] ) || ! self::same_resource( $params['resource'] ) ) ) {
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
				'client_id' => $data['client_id'],
				'groups'    => (array) $data['groups'],
				'created'   => time(),
				'last_used' => 0,
			)
		);
		$grants                   = self::grants( $user_id );
		$grants[ $grant_id ]      = $grant;
		self::save_grants( $user_id, $grants );
		self::mark_client_used( $data['client_id'] );
		return self::json( $response );
	}

	private static function refresh( array $params ): \WP_REST_Response {
		$token  = is_string( $params['refresh_token'] ?? null ) ? $params['refresh_token'] : '';
		$where  = self::locate( $token, 'sar' );
		$grants = $where ? self::grants( $where[0] ) : array();
		$grant  = $where && isset( $grants[ $where[1] ] ) ? $grants[ $where[1] ] : null;
		if ( ! $grant || ( $params['client_id'] ?? '' ) !== $grant['client_id'] ) {
			return self::error( 'invalid_grant', 'The refresh token is invalid.' );
		}
		$hash = self::hash( $token );
		if ( '' !== ( $grant['previous_refresh'] ?? '' ) && hash_equals( $grant['previous_refresh'], $hash ) ) {
			// A rotated-out refresh token came back: treat the grant as stolen and end it.
			unset( $grants[ $where[1] ] );
			self::save_grants( $where[0], $grants );
			return self::error( 'invalid_grant', 'The refresh token was already used. Connect again.' );
		}
		if ( ! hash_equals( (string) $grant['refresh_hash'], $hash ) || (int) $grant['refresh_expires'] < time() ) {
			return self::error( 'invalid_grant', 'The refresh token is invalid or expired.' );
		}
		if ( ! user_can( $where[0], 'manage_options' ) || ( is_multisite() && ! is_super_admin( $where[0] ) ) ) {
			return self::error( 'invalid_grant', 'The approving account is no longer an administrator.' );
		}
		$scope = is_string( $params['scope'] ?? null ) ? array_filter( preg_split( '/\s+/', trim( $params['scope'] ) ) ) : array();
		if ( $scope ) {
			if ( array_diff( $scope, array_merge( array( self::BASE_SCOPE ), $grant['groups'] ) ) ) {
				return self::error( 'invalid_scope', 'A refresh can only narrow the granted scope.' );
			}
			$grant['groups'] = array_values( array_intersect( $grant['groups'], $scope ) );
		}
		list( $grant, $response ) = self::mint( $where[0], $where[1], $grant );
		$grants[ $where[1] ]      = $grant;
		self::save_grants( $where[0], $grants );
		return self::json( $response );
	}

	private static function mark_client_used( string $client_id ): void {
		$clients = self::clients();
		if ( isset( $clients[ $client_id ] ) ) {
			$clients[ $client_id ]['used'] = time();
			update_option( self::CLIENTS, $clients, false );
		}
	}

	/** RFC 7009 revocation. Always answers 200, as the RFC requires for unknown tokens. */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$where = self::locate( $token, 'sar' ) ?? self::locate( $token, 'sa' );
		if ( $where ) {
			$grants = self::grants( $where[0] );
			$grant  = $grants[ $where[1] ] ?? null;
			$hash   = self::hash( $token );
			if ( $grant && ( hash_equals( (string) $grant['access_hash'], $hash ) || hash_equals( (string) $grant['refresh_hash'], $hash ) ) ) {
				unset( $grants[ $where[1] ] );
				self::save_grants( $where[0], $grants );
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
		$token  = trim( substr( (string) $request->get_header( 'authorization' ), 7 ) );
		$where  = self::locate( $token, 'sa' );
		$grants = $where ? self::grants( $where[0] ) : array();
		$grant  = $where && isset( $grants[ $where[1] ] ) ? $grants[ $where[1] ] : null;
		if ( ! $grant || ! hash_equals( (string) $grant['access_hash'], self::hash( $token ) ) || (int) $grant['access_expires'] < time() ) {
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
		// Record use at most every five minutes, so busy sessions do not rewrite user meta on each call.
		if ( (int) ( $grant['last_used'] ?? 0 ) < time() - 300 ) {
			$grants[ $where[1] ]['last_used'] = time();
			self::save_grants( $where[0], $grants );
		}
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
		if ( 'all' === $grant_id ) {
			$grants = array();
		} else {
			unset( $grants[ $grant_id ] );
		}
		self::save_grants( get_current_user_id(), $grants );
		wp_safe_redirect( add_query_arg( 'oauth_revoked', 1, admin_url( 'tools.php?page=site-agent#site-agent-oauth' ) ) );
		exit;
	}
}
