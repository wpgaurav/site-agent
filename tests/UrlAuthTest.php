<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Config;
use SiteAgent\Permissions;
use SiteAgent\Url_Auth;

final class UrlAuthTest extends TestCase {
	private $uuid;
	private $password;
	private $username;
	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->username = wp_get_current_user()->user_login;
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created        = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'URL auth disposable test' ) );
		$this->password = $created[0];
		$this->uuid     = $created[1]['uuid'];
		$this->enable();
		wp_set_current_user( 0 );
	}
	protected function tearDown(): void {
		WP_Application_Passwords::delete_application_password( 1, $this->uuid );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		update_option( Config::OPTION, Config::defaults(), false );
		wp_set_current_user( 0 );
	}
	private function enable( bool $url = true ): void {
		update_option(
			Config::OPTION,
			array_merge(
				Config::defaults(),
				array(
					'enabled'  => true,
					'url_auth' => $url,
				)
			),
			false
		);
	}
	private function request( $token = null, string $route = '/site-agent/v1/mcp' ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST', $route );
		$r->set_query_params( array( 'auth' => $token ?? base64_encode( $this->username . ':' . $this->password ) ) );
		return $r;
	}
	public function test_valid_query_auth_uses_native_identity_and_preserves_disabled_groups(): void {
		$this->assertTrue( Permissions::transport( $this->request() ) );
		$this->assertSame( 1, get_current_user_id() );
		$this->assertFalse( Permissions::allowed( 'content_write' ) );
		$this->assertFalse( Permissions::allowed( 'file_write' ) );
		$this->assertFalse( Permissions::allowed( 'php_execute' ) );
	}
	public function test_query_auth_is_opt_in_and_does_not_fall_back_to_an_admin_identity(): void {
		$this->enable( false );
		wp_set_current_user( 1 );
		$this->assertFalse( Permissions::transport( $this->request() ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->enable();
		wp_set_current_user( 1 );
		$this->assertFalse( Permissions::transport( $this->request( base64_encode( $this->username . ':invalid-password' ) ) ) );
		$this->assertSame( 0, get_current_user_id() );
	}
	public function test_malformed_nonstring_and_account_password_credentials_are_rejected(): void {
		foreach ( array( '', array( 'token' ), '***', str_repeat( 'a', 2049 ), base64_encode( 'no-colon' ), base64_encode( ':password' ), base64_encode( $this->username . ':' ), base64_encode( $this->username . ":abc\0def" ) ) as $token ) {
			$this->assertFalse( Permissions::transport( $this->request( $token ) ) );
			$this->assertSame( 0, get_current_user_id() );
		}
		$login = 'site_agent_url_account';
		$id    = username_exists( $login ) ?: wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'disposable-account-password',
				'role'       => 'administrator',
			)
		);
		$this->assertFalse( Permissions::transport( $this->request( base64_encode( $login . ':disposable-account-password' ) ) ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}
	public function test_contributor_credentials_do_not_grant_administrator_access(): void {
		$login   = 'site_agent_url_contributor';
		$id      = username_exists( $login ) ?: wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'disposable',
				'role'       => 'contributor',
			)
		);
		$created = WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'URL contributor test' ) );
		$this->assertFalse( Permissions::transport( $this->request( base64_encode( $login . ':' . $created[0] ) ) ) );
		$this->assertSame( 0, get_current_user_id() );
		WP_Application_Passwords::delete_application_password( $id, $created[1]['uuid'] );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}
	public function test_revocation_and_wordpress_authentication_restrictions_apply(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
		$this->assertFalse( Permissions::transport( $this->request() ) );
		remove_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
		WP_Application_Passwords::delete_application_password( 1, $this->uuid );
		$this->assertFalse( Permissions::transport( $this->request() ) );
	}
	public function test_query_auth_is_scoped_to_mcp_and_does_not_accept_body_credentials(): void {
		$this->assertFalse( Permissions::transport( $this->request( null, '/wp/v2/users' ) ) );
		$r = new WP_REST_Request( 'POST', '/site-agent/v1/mcp' );
		$r->set_body_params( array( 'auth' => base64_encode( $this->username . ':' . $this->password ) ) );
		$this->assertFalse( Permissions::transport( $r ) );
	}
	public function test_urlsafe_base64_and_grouped_passwords_are_supported(): void {
		$encoded = rtrim( strtr( base64_encode( $this->username . ':' . $this->password ), '+/', '-_' ), '=' );
		$this->assertTrue( Permissions::transport( $this->request( $encoded ) ) );
	}
	public function test_other_authenticated_identities_are_not_overridden(): void {
		$id = username_exists( 'site_agent_url_other' ) ?: wp_insert_user(
			array(
				'user_login' => 'site_agent_url_other',
				'user_pass'  => 'disposable',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $id );
		$this->assertFalse( Permissions::transport( $this->request() ) );
		$this->assertSame( 0, get_current_user_id() );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}
	public function test_mcp_responses_are_not_cacheable_and_do_not_forward_referrers(): void {
		$response = Url_Auth::response( new WP_REST_Response( array() ), null, $this->request() );
		$this->assertSame( 'private, no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'no-referrer', $response->get_headers()['Referrer-Policy'] );
		$other = Url_Auth::response( new WP_REST_Response( array() ), null, $this->request( null, '/wp/v2/posts' ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $other->get_headers() );
	}
}
