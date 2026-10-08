<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Config;
use SiteAgent\OAuth;
use SiteAgent\Permissions;
use SiteAgent\Scopes;
use SiteAgent\Url_Auth;

final class OAuthTest extends TestCase {
	private $users = array();

	protected function setUp(): void {
		$this->config( true );
		OAuth::delete_clients();
		delete_user_meta( 1, OAuth::GRANTS );
		OAuth::reset();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		update_option( Config::OPTION, Config::defaults(), false );
		OAuth::delete_clients();
		delete_user_meta( 1, OAuth::GRANTS );
		foreach ( $this->users as $user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		}
		OAuth::reset();
		wp_set_current_user( 0 );
	}

	private function config( bool $oauth, array $extra = array() ): void {
		update_option(
			Config::OPTION,
			array_merge(
				Config::defaults(),
				array(
					'enabled' => true,
					'oauth'   => $oauth,
				),
				$extra
			),
			false
		);
	}

	private function rest( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function register( array $redirects = array( 'https://client.example/callback' ) ): string {
		$response = $this->rest(
			'POST',
			'/site-agent/v1/oauth/register',
			array(
				'redirect_uris' => $redirects,
				'client_name'   => 'Test client',
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['client_id'];
	}

	private function verifier(): array {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array( $verifier, $challenge );
	}

	/** Run the consent step as an administrator and return the code. */
	private function code( string $client_id, string $challenge, array $groups = array(), string $scope = '' ): string {
		wp_set_current_user( 1 );
		$request = OAuth::authorization_request(
			array(
				'client_id'             => $client_id,
				'redirect_uri'          => 'https://client.example/callback',
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'state'                 => 'xyz',
				'resource'              => OAuth::resource(),
				'scope'                 => $scope,
			)
		);
		$this->assertIsArray( $request, is_wp_error( $request ) ? $request->get_error_message() : '' );
		$code = OAuth::issue_code( $request, $groups );
		wp_set_current_user( 0 );
		return $code;
	}

	private function tokens( string $client_id, string $code, string $verifier ): WP_REST_Response {
		return $this->rest(
			'POST',
			'/site-agent/v1/oauth/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'redirect_uri'  => 'https://client.example/callback',
				'code_verifier' => $verifier,
				'resource'      => OAuth::resource(),
			)
		);
	}

	private function mcp( string $token, string $route = '/site-agent/v1/mcp' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		return $request;
	}

	public function test_metadata_is_served_only_when_oauth_is_on(): void {
		$this->config( false );
		$this->assertSame( 404, $this->rest( 'GET', '/site-agent/v1/oauth/protected-resource' )->get_status() );
		$this->assertSame( 404, $this->rest( 'POST', '/site-agent/v1/oauth/register', array( 'redirect_uris' => array( 'https://a.example/cb' ) ) )->get_status() );
		$this->config( true );
		$resource = $this->rest( 'GET', '/site-agent/v1/oauth/protected-resource' )->get_data();
		$this->assertSame( OAuth::resource(), $resource['resource'] );
		$this->assertSame( array( OAuth::issuer() ), $resource['authorization_servers'] );
		$server = $this->rest( 'GET', '/site-agent/v1/oauth/authorization-server' )->get_data();
		$this->assertSame( array( 'S256' ), $server['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $server['token_endpoint_auth_methods_supported'] );
		$this->assertSame( OAuth::authorization_endpoint(), $server['authorization_endpoint'] );
		$this->assertContains( 'php_execute', $server['scopes_supported'] );
	}

	public function test_authorization_endpoint_survives_clients_that_append_with_a_question_mark(): void {
		$structure = get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '' );
		$this->assertStringContainsString( 'page=site-agent-authorize', OAuth::authorization_endpoint(), 'Without pretty permalinks the consent screen is advertised directly.' );
		update_option( 'permalink_structure', '/%postname%/' );
		$endpoint = OAuth::authorization_endpoint();
		$expected = rest_url( 'site-agent/v1/oauth/authorize' );
		update_option( 'permalink_structure', $structure );
		$this->assertSame( $expected, $endpoint );
		$this->assertStringNotContainsString( '?', $endpoint );

		$client              = $this->register();
		list( , $challenge ) = $this->verifier();
		$params              = array(
			'response_type'         => 'code',
			'client_id'             => $client,
			'redirect_uri'          => 'https://client.example/callback',
			'state'                 => 'a&b=c',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'resource'              => OAuth::resource(),
			'scope'                 => 'site-agent content_write',
		);
		$response = $this->rest( 'GET', '/site-agent/v1/oauth/authorize', $params );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$location = $response->get_headers()['Location'];
		$this->assertStringStartsWith( admin_url( 'admin.php?page=site-agent-authorize&' ), $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( array( 'page' => OAuth::PAGE ) + $params, $query );

		// What PHP makes of admin.php?page=site-agent-authorize?response_type=code&client_id=...
		parse_str( 'page=' . OAuth::PAGE . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), $malformed );
		$this->assertSame( OAuth::PAGE . '?response_type=code', $malformed['page'] );
		$repaired = OAuth::repaired_params( $malformed );
		$this->assertSame( $params, $repaired );
		wp_set_current_user( 1 );
		$this->assertIsArray( OAuth::authorization_request( $repaired ) );
		parse_str( (string) wp_parse_url( OAuth::consent_url( $repaired ), PHP_URL_QUERY ), $fixed );
		$this->assertSame( array( 'page' => OAuth::PAGE ) + $params, $fixed );
		$this->assertNull( OAuth::repaired_params( array( 'page' => OAuth::PAGE ) + $params ) );
		$this->assertNull( OAuth::repaired_params( array( 'page' => 'site-agent' ) ) );
		$this->assertNull( OAuth::repaired_params( array() ) );
	}

	public function test_well_known_paths_map_to_metadata(): void {
		$resource_path = (string) wp_parse_url( OAuth::resource(), PHP_URL_PATH );
		$issuer_path   = (string) wp_parse_url( OAuth::issuer(), PHP_URL_PATH );
		$this->assertSame( untrailingslashit( home_url( '/' . rest_get_url_prefix() . '/site-agent/v1/oauth' ) ), OAuth::issuer() );
		$this->assertSame( 'resource', OAuth::well_known_document( '/.well-known/oauth-protected-resource' . $resource_path ) );
		$this->assertSame( 'resource', OAuth::well_known_document( '/.well-known/oauth-protected-resource' . $resource_path . '/' ) );
		$this->assertSame( 'server', OAuth::well_known_document( '/.well-known/oauth-authorization-server' . $issuer_path ) );
		$this->assertSame( 'server', OAuth::well_known_document( '/.well-known/openid-configuration' . $issuer_path ) );
		$this->assertSame( 'server', OAuth::well_known_document( $issuer_path . '/.well-known/openid-configuration' ) );
		// The site root's documents may belong to another plugin's OAuth server.
		$this->assertSame( '', OAuth::well_known_document( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( '', OAuth::well_known_document( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( '', OAuth::well_known_document( '/.well-known/oauth-authorization-server/' ) );
		$this->assertSame( '', OAuth::well_known_document( '/.well-known/openid-configuration' ) );
		$this->assertSame( '', OAuth::well_known_document( '/.well-known/acme-challenge/x' ) );
	}

	public function test_registration_accepts_only_safe_public_clients(): void {
		foreach ( array( 'https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:33418/callback', 'http://localhost/cb', 'cursor://anysphere.cursor-retrieval/oauth/callback' ) as $uri ) {
			$this->assertTrue( OAuth::valid_redirect( $uri ), $uri );
		}
		foreach ( array( 'http://evil.example/cb', 'javascript:alert(1)', 'data:text/html,x', 'https://a.example/cb#frag', 'https://', '' ) as $uri ) {
			$this->assertFalse( OAuth::valid_redirect( $uri ), $uri );
		}
		$this->assertSame( 400, $this->rest( 'POST', '/site-agent/v1/oauth/register', array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) )->get_status() );
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/site-agent/v1/oauth/register',
				array(
					'redirect_uris'              => array( 'https://a.example/cb' ),
					'token_endpoint_auth_method' => 'client_secret_basic',
				)
			)->get_status()
		);
		$this->assertStringStartsWith( 'sa-', $this->register() );
	}

	public function test_clients_are_stored_separately_and_stale_ones_are_pruned_at_the_limit(): void {
		$first  = $this->register();
		$second = $this->register();
		$this->assertSame( array( $first, $second ), array_values( array_intersect( array( $first, $second ), array_keys( OAuth::clients() ) ) ) );
		$this->assertIsArray( get_option( OAuth::CLIENTS . $first ) );
		$this->assertNull( OAuth::client( '../' . $first ) );
		$stale            = OAuth::client( $first );
		$stale['created'] = time() - 2 * DAY_IN_SECONDS;
		update_option( OAuth::CLIENTS . $first, $stale, false );
		for ( $i = count( OAuth::clients() ); $i < OAuth::MAX_CLIENTS; $i++ ) {
			add_option(
				OAuth::CLIENTS . 'sa-' . bin2hex( random_bytes( 16 ) ),
				array(
					'client_name'   => 'Filler',
					'redirect_uris' => array( 'https://a.example/cb' ),
					'created'       => time(),
					'used'          => time(),
				),
				'',
				false
			);
		}
		$this->assertStringStartsWith( 'sa-', $this->register(), 'At the limit, a stale unused client is pruned to make room.' );
		$this->assertNull( OAuth::client( $first ) );
		$this->assertNotNull( OAuth::client( $second ) );
		$this->assertSame( 429, $this->rest( 'POST', '/site-agent/v1/oauth/register', array( 'redirect_uris' => array( 'https://client.example/callback' ) ) )->get_status(), 'With no stale clients, registration stops at the limit.' );
	}

	public function test_authorization_requests_need_a_registered_redirect_pkce_and_this_resource(): void {
		$client              = $this->register();
		list( , $challenge ) = $this->verifier();
		$base                = array(
			'client_id'             => $client,
			'redirect_uri'          => 'https://client.example/callback',
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		$this->assertSame( 'invalid_client', OAuth::authorization_request( array( 'client_id' => 'sa-unknown' ) + $base )->get_error_code() );
		$wrong = OAuth::authorization_request( array( 'redirect_uri' => 'https://attacker.example/cb' ) + $base );
		$this->assertSame( 'invalid_redirect_uri', $wrong->get_error_code() );
		$this->assertArrayNotHasKey( 'redirect', (array) $wrong->get_error_data(), 'A mismatched redirect is never used to report errors.' );
		$this->assertSame( 'invalid_request', OAuth::authorization_request( array( 'code_challenge_method' => 'plain' ) + $base )->get_error_code() );
		$this->assertSame( 'invalid_target', OAuth::authorization_request( array( 'resource' => 'https://other.example/mcp' ) + $base )->get_error_code() );
		$this->assertSame( 'invalid_scope', OAuth::authorization_request( array( 'scope' => 'admin' ) + $base )->get_error_code() );
		$this->assertIsArray( OAuth::authorization_request( $base ) );
	}

	public function test_full_flow_scopes_refresh_rotation_and_revocation(): void {
		$this->config(
			true,
			array(
				'php_execute'   => true,
				'content_write' => true,
			)
		);
		$client                       = $this->register();
		list( $verifier, $challenge ) = $this->verifier();
		$code                         = $this->code( $client, $challenge, array( 'php_execute' ) );

		$this->assertSame( 400, $this->tokens( $client, $code, str_repeat( 'a', 43 ) )->get_status(), 'A wrong verifier is refused.' );
		$code     = $this->code( $client, $challenge, array( 'php_execute' ) );
		$response = $this->tokens( $client, $code, $verifier );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$tokens = $response->get_data();
		$this->assertSame( 'Bearer', $tokens['token_type'] );
		$this->assertSame( 'site-agent php_execute', $tokens['scope'] );
		$this->assertSame( 400, $this->tokens( $client, $code, $verifier )->get_status(), 'Codes are single use.' );

		$stored = wp_json_encode( OAuth::grants( 1 ) );
		$this->assertStringNotContainsString( $tokens['access_token'], $stored, 'Only token hashes are stored.' );

		$this->assertTrue( Permissions::transport( $this->mcp( $tokens['access_token'] ) ) );
		$this->assertSame( 1, get_current_user_id() );
		$this->assertTrue( Permissions::allowed( 'php_execute' ) );
		$this->assertFalse( Permissions::allowed( 'content_write' ), 'Groups the administrator did not approve stay closed.' );
		$this->assertSame( 'OAuth: Test client', Scopes::current_label() );

		$this->assertFalse( Permissions::transport( $this->mcp( $tokens['access_token'], '/wp/v2/users' ) ), 'Tokens only work on the MCP route.' );
		$this->assertSame( 0, get_current_user_id() );

		$refreshed = $this->rest(
			'POST',
			'/site-agent/v1/oauth/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $client,
			)
		)->get_data();
		$this->assertNotSame( $tokens['access_token'], $refreshed['access_token'] );
		$this->assertFalse( Permissions::transport( $this->mcp( $tokens['access_token'] ) ), 'The previous access token stops working.' );
		$this->assertTrue( Permissions::transport( $this->mcp( $refreshed['access_token'] ) ) );

		$reuse = $this->rest(
			'POST',
			'/site-agent/v1/oauth/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $client,
			)
		);
		$this->assertSame( 400, $reuse->get_status() );
		$this->assertSame( array(), OAuth::grants( 1 ), 'Reusing a rotated refresh token ends the grant.' );
		$this->assertFalse( Permissions::transport( $this->mcp( $refreshed['access_token'] ) ) );

		$tokens = $this->tokens( $client, $this->code( $client, $challenge ), $verifier )->get_data();
		$this->assertTrue( Permissions::transport( $this->mcp( $tokens['access_token'] ) ) );
		$this->assertSame( 200, $this->rest( 'POST', '/site-agent/v1/oauth/revoke', array( 'token' => $tokens['refresh_token'] ) )->get_status() );
		$this->assertFalse( Permissions::transport( $this->mcp( $tokens['access_token'] ) ), 'Revocation ends access.' );
	}

	public function test_bearer_tokens_are_refused_when_oauth_is_off_expired_or_mixed_with_url_auth(): void {
		$client                       = $this->register();
		list( $verifier, $challenge ) = $this->verifier();
		$tokens                       = $this->tokens( $client, $this->code( $client, $challenge ), $verifier )->get_data();

		$this->config( false );
		$this->assertFalse( Permissions::transport( $this->mcp( $tokens['access_token'] ) ) );
		$this->assertSame( 'oauth_disabled', Permissions::denial() );
		$this->config( true );

		$mixed = $this->mcp( $tokens['access_token'] );
		$mixed->set_query_params( array( 'auth' => base64_encode( 'admin:x' ) ) );
		$this->assertFalse( Permissions::transport( $mixed ) );

		$grants                          = OAuth::grants( 1 );
		$id                              = array_key_first( $grants );
		$grants[ $id ]['access_expires'] = time() - 1;
		update_user_meta( 1, OAuth::GRANTS, $grants );
		$this->assertFalse( Permissions::transport( $this->mcp( $tokens['access_token'] ) ) );
		$this->assertSame( 'invalid_token', Permissions::denial() );
		$this->assertFalse( Permissions::transport( $this->mcp( 'sa_1_0123456789abcdef_' . str_repeat( 'x', 43 ) ) ) );
	}

	public function test_a_demoted_approver_cannot_use_or_redeem_grants(): void {
		$editor                       = wp_insert_user(
			array(
				'user_login' => 'oauth-editor-' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		$this->users[]                = $editor;
		$client                       = $this->register();
		list( $verifier, $challenge ) = $this->verifier();
		wp_set_current_user( $editor );
		$request = OAuth::authorization_request(
			array(
				'client_id'             => $client,
				'redirect_uri'          => 'https://client.example/callback',
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			)
		);
		$code    = OAuth::issue_code( $request, array() );
		wp_set_current_user( 0 );
		( new WP_User( $editor ) )->set_role( 'editor' );
		$this->assertSame( 400, $this->tokens( $client, $code, $verifier )->get_status() );
	}

	public function test_oauth_is_on_by_default_and_connections_report_their_method(): void {
		$this->assertTrue( Config::defaults()['oauth'] );
		update_option( Config::OPTION, array( 'enabled' => true ), false );
		$this->assertTrue( Config::get()['oauth'], 'A site that never chose gets OAuth on.' );
		update_option(
			Config::OPTION,
			array(
				'enabled' => true,
				'oauth'   => false,
			),
			false
		);
		$this->assertFalse( Config::get()['oauth'], 'An explicit choice to turn it off is kept.' );
		$this->config( true );

		wp_set_current_user( 1 );
		$this->assertSame( array( 'method' => 'session' ), Scopes::connection() );
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
		$password                                     = Scopes::connection();
		$this->assertSame( 'application_password', $password['method'] );
		$this->assertStringContainsString( 'OAuth', $password['warning'] );
		$GLOBALS['wp_rest_application_password_uuid'] = null;

		$client                       = $this->register();
		list( $verifier, $challenge ) = $this->verifier();
		$tokens                       = $this->tokens( $client, $this->code( $client, $challenge ), $verifier )->get_data();
		$this->assertTrue( Permissions::transport( $this->mcp( $tokens['access_token'] ) ) );
		$this->assertSame(
			array(
				'method' => 'oauth',
				'client' => 'Test client',
			),
			Scopes::connection()
		);
		$context = SiteAgent\Content::context();
		$this->assertSame( 'oauth', $context['connection']['method'] );
		$this->assertTrue( rest_validate_value_from_schema( $context, SiteAgent\Abilities::definitions()['site-context']['output'], 'output' ) );
	}

	public function test_unauthenticated_mcp_responses_point_to_oauth_discovery(): void {
		Permissions::denied( 'unauthenticated' );
		$response = Url_Auth::response( new WP_REST_Response( array(), 401 ), null, new WP_REST_Request( 'POST', '/site-agent/v1/mcp' ) );
		$this->assertStringContainsString( 'resource_metadata="' . OAuth::resource_metadata_url() . '"', $response->get_headers()['WWW-Authenticate'] );
		$this->config( false );
		$response = Url_Auth::response( new WP_REST_Response( array(), 401 ), null, new WP_REST_Request( 'POST', '/site-agent/v1/mcp' ) );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->get_headers() );
	}
}
