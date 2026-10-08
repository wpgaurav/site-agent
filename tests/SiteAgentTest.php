<?php
use PHPUnit\Framework\TestCase;
use SiteAgent\Abilities;
use SiteAgent\Audit;
use SiteAgent\Config;
use SiteAgent\Files;
use SiteAgent\Permissions;

final class SiteAgentTest extends TestCase {
	private $directory;
	protected function setUp(): void {
		wp_set_current_user( 1 );
		update_option( Config::OPTION, Config::defaults(), false );
		delete_option( Audit::OPTION );
		$this->directory = WP_CONTENT_DIR . '/themes/site-agent-test';
		wp_mkdir_p( $this->directory );
	}
	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/*' ) ?: array() as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $this->directory );
		update_option( Config::OPTION, Config::defaults(), false );
		wp_set_current_user( 0 );
	}
	private function enable( array $groups = array() ): void {
		update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true ), array_fill_keys( $groups, true ) ), false );
	}
	public function test_every_access_path_is_disabled_on_install(): void {
		$this->assertFalse( Permissions::allowed() );
		$this->assertSame( array(), Abilities::enabled_definitions() );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'execute-php', array( 'code' => 'return 1;' ) ) );
	}
	public function test_opt_in_groups_are_independent_and_revocable(): void {
		$this->enable( array( 'file_read' ) );
		$this->assertTrue( Permissions::allowed( 'file_read' ) );
		$this->assertFalse( Permissions::allowed( 'php_execute' ) );
		$this->assertFalse( Permissions::allowed( 'file_write' ) );
		$this->assertCount( 9, Abilities::enabled_definitions() );
		update_option( Config::OPTION, Config::defaults(), false );
		$this->assertFalse( Permissions::allowed( 'file_read' ) );
	}
	public function test_contributors_cannot_smuggle_post_permissions(): void {
		$this->enable( array( 'php_execute', 'file_read', 'content_write' ) );
		$user = get_user_by( 'login', 'site_agent_contributor' );
		$id   = $user ? $user->ID : wp_insert_user(
			array(
				'user_login' => 'site_agent_contributor',
				'user_pass'  => 'test-only',
				'role'       => 'contributor',
			)
		);
		wp_set_current_user( $id );
		$this->assertFalse( Permissions::allowed() );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'execute-php',
				array(
					'code'    => 'return 1;',
					'post_id' => 1,
				)
			)
		);
	}
	public function test_schemas_reject_unknown_and_oversized_arguments(): void {
		$this->enable( array( 'php_execute' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'execute-php',
				array(
					'code'    => 'return 1;',
					'post_id' => 1,
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'execute-php', array( 'code' => str_repeat( ' ', 65537 ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'site-context', array( 'unknown' => true ) ) );
	}
	public function test_php_captures_output_return_and_errors(): void {
		$this->enable( array( 'php_execute' ) );
		$result = Abilities::execute( 'execute-php', array( 'code' => 'echo "captured"; return array("answer" => 42);' ) );
		$this->assertSame( 'captured', $result['output'] );
		$this->assertSame( array( 'answer' => 42 ), $result['return_value'] );
		foreach ( array( 'return (', 'throw new RuntimeException("private error");', '?>leak' ) as $code ) {
			$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'execute-php', array( 'code' => $code ) ) );
		}
	}
	public function test_php_output_is_bounded(): void {
		$this->enable( array( 'php_execute' ) );
		$result = Abilities::execute( 'execute-php', array( 'code' => 'echo str_repeat("x", 100000); return null;' ) );
		$this->assertSame( 65536, strlen( $result['output'] ) );
		$this->assertTrue( $result['truncated'] );
	}
	public function test_files_block_traversal_hidden_credentials_and_symlinks(): void {
		foreach ( array( '../wp-config.php', 'plugins/../themes', 'themes/.env', 'themes/site-agent-test/wp-config.php', 'themes/site-agent-test/auth.json', 'themes/site-agent-test/credentials.json', 'themes/site-agent-test/secrets.yml', 'themes/site-agent-test/server.pem', 'uploads', '/etc/passwd', 'themes/site-agent-test/../../wp-config.php' ) as $path ) {
			$this->assertInstanceOf( WP_Error::class, Files::resolve( $path, true ), $path );
		}
		// Ordinary build and source files that merely mention configuration or credentials stay accessible.
		foreach ( array( 'themes/site-agent-test/tailwind.config.js', 'themes/site-agent-test/vite.config.js', 'themes/site-agent-test/class-credentials-form.php' ) as $path ) {
			$this->assertIsString( Files::resolve( $path, true ), $path );
		}
		symlink( ABSPATH . 'wp-config.php', $this->directory . '/linked.php' );
		$this->assertInstanceOf( WP_Error::class, Files::resolve( 'themes/site-agent-test/linked.php' ) );
	}
	public function test_file_writes_require_current_hash_and_valid_php(): void {
		$this->enable( array( 'file_read', 'file_write' ) );
		$path    = 'themes/site-agent-test/example.php';
		$created = Abilities::execute(
			'write-file',
			array(
				'path'            => $path,
				'content'         => '<?php return 1;',
				'expected_sha256' => 'new',
			)
		);
		$this->assertIsArray( $created );
		$read = Abilities::execute( 'read-file', array( 'path' => $path ) );
		$this->assertSame( '<?php return 1;', $read['content'] );
		foreach ( array( array( '<?php return 2;', str_repeat( '0', 64 ) ), array( '<?php return (;', $read['sha256'] ) ) as $attempt ) {
			$this->assertInstanceOf(
				WP_Error::class,
				Abilities::execute(
					'write-file',
					array(
						'path'            => $path,
						'content'         => $attempt[0],
						'expected_sha256' => $attempt[1],
					)
				)
			);
		}
		$this->assertSame( $read['content'], file_get_contents( $this->directory . '/example.php' ) );
		$updated = Abilities::execute(
			'write-file',
			array(
				'path'            => $path,
				'content'         => '<?php return 2;',
				'expected_sha256' => $read['sha256'],
			)
		);
		$this->assertSame( hash( 'sha256', '<?php return 2;' ), $updated['sha256'] );
	}
	public function test_invalid_new_php_does_not_leave_a_file(): void {
		$this->enable( array( 'file_write' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'write-file',
				array(
					'path'            => 'themes/site-agent-test/bad.php',
					'content'         => '<?php broken (',
					'expected_sha256' => 'new',
				)
			)
		);
		$this->assertFileDoesNotExist( $this->directory . '/bad.php' );
	}
	public function test_source_editing_cannot_rewrite_site_agent(): void {
		$this->enable( array( 'file_write' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'write-file',
				array(
					'path'            => 'plugins/site-agent/site-agent.php',
					'content'         => '<?php echo 1;',
					'expected_sha256' => 'new',
				)
			)
		);
	}
	public function test_content_defaults_to_draft_and_preserves_blocks(): void {
		$this->enable( array( 'content_write' ) );
		$markup  = '<!-- wp:paragraph --><p>Preserve \\ content.</p><!-- /wp:paragraph -->';
		$created = Abilities::execute(
			'save-content',
			array(
				'title'   => 'Site Agent fixture',
				'content' => $markup,
			)
		);
		$this->assertSame( 'draft', $created['status'] );
		$this->assertSame( $markup, $created['content'] );
		$this->assertInstanceOf(
			WP_Error::class,
			Abilities::execute(
				'save-content',
				array(
					'post_id'                 => $created['id'],
					'content'                 => 'stale',
					'expected_content_sha256' => str_repeat( '0', 64 ),
				)
			)
		);
		$updated = Abilities::execute(
			'save-content',
			array(
				'post_id'                 => $created['id'],
				'title'                   => 'Updated',
				'expected_content_sha256' => $created['content_sha256'],
			)
		);
		$this->assertSame( $markup, $updated['content'] );
		wp_delete_post( $created['id'], true );
	}
	public function test_audit_keeps_metadata_without_code_or_output(): void {
		$this->enable( array( 'php_execute' ) );
		Abilities::execute( 'execute-php', array( 'code' => 'return "secret-test-value";' ) );
		$rows = get_option( Audit::OPTION );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'execute-php', $rows[0]['tool'] );
		$this->assertStringNotContainsString( 'secret-test-value', wp_json_encode( $rows ) );
		for ( $i = 0; $i < 110; $i++ ) {
			Audit::record( 'site-context', true, microtime( true ) );
		}
		$this->assertCount( 100, get_option( Audit::OPTION ) );
	}
	public function test_cli_rejects_connection_overrides_before_execution(): void {
		$this->enable( array( 'cli_execute' ) );
		$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'run-wp-cli', array( 'arguments' => array( '--path=/etc', 'core', 'version' ) ) ) );
	}
	public function test_emergency_and_wordpress_file_guards(): void {
		foreach ( array( 'SITE_AGENT_DISABLED', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ) as $flag ) {
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/guards.php' ) . ' ' . escapeshellarg( $flag ), $output, $status );
			$this->assertSame( 0, $status );
			$data = json_decode( implode( '', $output ), true );
			foreach ( array( 'file_read', 'file_write', 'php_execute', 'cli_execute' ) as $group ) {
				$this->assertFalse( $data[ $group ], $flag . ' must block ' . $group );
			}
			$this->assertSame( 'SITE_AGENT_DISABLED' !== $flag, $data['read'] );
		}
	}
}
