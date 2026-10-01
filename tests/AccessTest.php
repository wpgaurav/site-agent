<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Abilities;
use SiteAgent\Audit;
use SiteAgent\Config;
use SiteAgent\Permissions;
use SiteAgent\Scopes;
use SiteAgent\Url_Auth;

final class AccessTest extends TestCase {
	private $password;
	private $uuid;
	private $username;

	protected function setUp(): void {
		wp_set_current_user( 1 );
		$this->username = wp_get_current_user()->user_login;
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created        = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => 'Scoped test client' ) );
		$this->password = $created[0];
		$this->uuid     = $created[1]['uuid'];
		update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true, 'url_auth' => true, 'content_write' => true, 'php_execute' => true ) ), false );
		delete_option( Scopes::OPTION );
		delete_option( Audit::OPTION );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		WP_Application_Passwords::delete_application_password( 1, $this->uuid );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		update_option( Config::OPTION, Config::defaults(), false );
		delete_option( Scopes::OPTION );
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		wp_set_current_user( 0 );
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/site-agent/v1/mcp' );
		$request->set_query_params( array( 'auth' => base64_encode( $this->username . ':' . $this->password ) ) );
		return $request;
	}

	public function test_url_credentials_are_removed_before_dispatch_and_still_authenticate(): void {
		$server                  = $_SERVER;
		$request                 = $this->request();
		$token                   = $request->get_query_params()['auth'];
		$_GET['auth']            = $token;
		$_REQUEST['auth']        = $token;
		$_SERVER['QUERY_STRING'] = 'rest_route=/site-agent/v1/mcp&auth=' . rawurlencode( $token ) . '&x=1';
		$_SERVER['REQUEST_URI']  = '/index.php?' . $_SERVER['QUERY_STRING'];
		Url_Auth::capture( null, null, $request );
		$this->assertArrayNotHasKey( 'auth', $request->get_query_params() );
		$this->assertArrayNotHasKey( 'auth', $_GET );
		$this->assertArrayNotHasKey( 'auth', $_REQUEST );
		$this->assertSame( 'rest_route=/site-agent/v1/mcp&x=1', $_SERVER['QUERY_STRING'] );
		$this->assertSame( '/index.php?rest_route=/site-agent/v1/mcp&x=1', $_SERVER['REQUEST_URI'] );
		$this->assertTrue( Permissions::transport( $request ) );
		$this->assertSame( 1, get_current_user_id() );
		$this->assertTrue( Url_Auth::used() );
		$_SERVER = $server;
	}

	public function test_refusals_explain_themselves_without_identities(): void {
		$request = new WP_REST_Request( 'POST', '/site-agent/v1/mcp' );
		$this->assertFalse( Permissions::transport( $request ) );
		$response = Url_Auth::response( new WP_REST_Response( array(), 401 ), null, $request );
		$this->assertSame( 'unauthenticated', $response->get_headers()['X-Site-Agent-Auth'] );
		update_option( Config::OPTION, array_merge( Config::get(), array( 'url_auth' => false ) ), false );
		$this->assertFalse( Permissions::transport( $this->request() ) );
		$this->assertSame( 'url_auth_disabled', Permissions::denial() );
	}

	public function test_password_limits_narrow_enabled_tools(): void {
		$this->assertTrue( Permissions::transport( $this->request() ) );
		$this->assertSame( $this->uuid, Scopes::current_uuid() );
		$this->assertTrue( Permissions::allowed( 'php_execute' ), 'Unlimited passwords use every enabled switch.' );
		update_option( Scopes::OPTION, array( $this->uuid => array( 'content_write', 'cli_execute' ) ), false );
		$this->assertTrue( Permissions::allowed( '' ) );
		$this->assertTrue( Permissions::allowed( 'content_write' ) );
		$this->assertFalse( Permissions::allowed( 'php_execute' ) );
		$this->assertFalse( Permissions::allowed( 'cli_execute' ), 'A limit can never enable a disabled switch.' );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'execute-php', array( 'code' => 'return 1;' ) ) );
		$row = get_option( Audit::OPTION )[0];
		$this->assertSame( 'denied', $row['error'] );
		$this->assertSame( 'Scoped test client', $row['credential'] );
		$this->assertSame( 'url', $row['via'] );
	}

	public function test_tools_list_hides_tools_a_password_cannot_use(): void {
		$this->assertTrue( Permissions::transport( $this->request() ) );
		update_option( Scopes::OPTION, array( $this->uuid => array() ), false );
		$tool   = static function ( string $name ) {
			return new class( $name ) {
				private $name;
				public function __construct( string $name ) {
					$this->name = $name;
				}
				public function get( string $field ) {
					return 'name' === $field ? $this->name : null;
				}
			};
		};
		$server = new class() {
			public function get_server_id(): string {
				return 'site-agent';
			}
		};
		$names  = array_map(
			static function ( $item ) {
				return $item->get( 'name' );
			},
			Abilities::visible_tools( array( $tool( 'site-agent-get-content' ), $tool( 'site-agent-save-content' ), $tool( 'site-agent-execute-php' ) ), $server )
		);
		$this->assertSame( array( 'site-agent-get-content' ), $names );
	}

	public function test_limits_change_only_the_current_users_listed_passwords(): void {
		wp_set_current_user( 1 );
		$other = '6f1c2b1e-3d4a-4b5c-8d9e-0f1a2b3c4d5e';
		update_option( Scopes::OPTION, array( $other => array( 'php_execute' ) ), false );
		$sanitized = Scopes::sanitize(
			array(
				'__shown'   => array( $this->uuid, $other ),
				$this->uuid => array(
					'limited' => '1',
					'groups'  => array( 'content_write', 'not-a-group' ),
				),
				$other      => array(),
			)
		);
		$this->assertSame( array( 'content_write' ), $sanitized[ $this->uuid ] );
		$this->assertSame( array( 'php_execute' ), $sanitized[ $other ], 'Passwords of other users are not changed from this form.' );
		update_option( Scopes::OPTION, $sanitized, false );
		WP_Application_Passwords::delete_application_password( 1, $this->uuid );
		$this->assertArrayNotHasKey( $this->uuid, Scopes::all(), 'Revoking a password removes its limit.' );
	}

	public function test_execution_constant_and_audit_targets(): void {
		$this->assertSame( 'plugins/a/b.php', Audit::target( array( 'path' => 'plugins/a/b.php' ) ) );
		$this->assertSame( 'wp user create', Audit::target( array( 'arguments' => array( 'user', 'create', 'name', 'mail@example.test', '--user_pass=secret' ) ) ) );
		$this->assertSame( 'cdn.example.test', Audit::target( array( 'url' => 'https://cdn.example.test/a.png?signature=secret' ) ) );
		$this->assertSame( 'post 7', Audit::target( array( 'post_id' => 7, 'content' => 'secret' ) ) );
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/guards.php' ) . ' SITE_AGENT_ALLOW_EXECUTION', $output, $status );
		$this->assertSame( 0, $status );
		$data = json_decode( implode( '', $output ), true );
		foreach ( array( 'file_write', 'php_execute', 'cli_execute' ) as $group ) {
			$this->assertFalse( $data[ $group ], $group );
		}
		$this->assertTrue( $data['file_read'] );
		$this->assertTrue( $data['content_write'] );
	}
}
