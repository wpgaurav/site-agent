<?php

namespace Bricks\Abilities {
	if ( ! class_exists( Manager::class ) ) {
		/** Stand-in for Bricks 2.4's ability manager: the switches Site Agent reads. */
		class Manager {
			public static $site_agent_stub = true;
			public static $enabled         = false;
			public static $named           = array( 'bricks/site-agent-test-read', 'bricks/site-agent-test-off' );
			public static $disabled        = array( 'bricks/site-agent-test-off' );

			public static function is_enabled(): bool {
				return self::$enabled;
			}

			public static function get_named_tool_abilities(): array {
				return array_values( array_diff( self::$named, self::$disabled ) );
			}

			public static function is_ability_disabled( string $name ): bool {
				return in_array( $name, self::$disabled, true );
			}
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use SiteAgent\Abilities;
	use SiteAgent\Audit;
	use SiteAgent\Bricks;
	use SiteAgent\Config;

	final class BricksTest extends TestCase {
		const CATEGORY  = 'site-agent-bricks-test';
		const ABILITIES = array( 'bricks/site-agent-test-read', 'bricks/site-agent-test-write', 'bricks/site-agent-test-off', 'bricks/site-agent-test-guarded', 'bricks/start-here', 'bricks/execute-php' );

		protected function setUp(): void {
			if ( empty( \Bricks\Abilities\Manager::$site_agent_stub ) ) {
				$this->markTestSkipped( 'The real Bricks theme is active; these tests use a stand-in manager.' );
			}
			\Bricks\Abilities\Manager::$enabled = true;
			wp_set_current_user( 1 );
			delete_option( Audit::OPTION );
			$this->configure( array( 'bricks' => true ) );
			if ( ! WP_Ability_Categories_Registry::get_instance()->is_registered( self::CATEGORY ) ) {
				WP_Ability_Categories_Registry::get_instance()->register(
					self::CATEGORY,
					array(
						'label'       => 'Site Agent Bricks test',
						'description' => 'Stand-in Bricks abilities.',
					)
				);
			}
			$object = array(
				'type'                 => 'object',
				'properties'           => array( 'postId' => array( 'type' => 'integer' ) ),
				'additionalProperties' => false,
				'default'              => new stdClass(),
			);
			$echo   = static function ( $input ) {
				return array( 'echo' => $input );
			};
			foreach ( self::ABILITIES as $name ) {
				$readonly = in_array( $name, array( 'bricks/site-agent-test-read', 'bricks/start-here' ), true );
				WP_Abilities_Registry::get_instance()->register(
					$name,
					array(
						'label'               => $name,
						'description'         => 'Stand-in for ' . $name . '. Second sentence.',
						'category'            => self::CATEGORY,
						'input_schema'        => $object,
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => $echo,
						'permission_callback' => 'bricks/site-agent-test-guarded' === $name
							? static function () {
								return new WP_Error( 'bricks_forbidden_builder_permission', 'Current user lacks the "design_system_access" builder permission.' );
							}
							: static function () {
								return current_user_can( 'manage_options' );
							},
						'meta'                => array( 'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly ) ),
					)
				);
			}
		}

		protected function tearDown(): void {
			if ( empty( \Bricks\Abilities\Manager::$site_agent_stub ) ) {
				return;
			}
			foreach ( self::ABILITIES as $name ) {
				if ( wp_has_ability( $name ) ) {
					WP_Abilities_Registry::get_instance()->unregister( $name );
				}
			}
			WP_Ability_Categories_Registry::get_instance()->unregister( self::CATEGORY );
			\Bricks\Abilities\Manager::$enabled = false;
			update_option( Config::OPTION, Config::defaults(), false );
			wp_set_current_user( 0 );
		}

		private function configure( array $groups ): void {
			update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true ), $groups ), false );
		}

		private function assertMatchesOutputSchema( string $tool, $result ): void {
			$this->assertIsArray( $result, $tool );
			$this->assertTrue( rest_validate_value_from_schema( $result, Abilities::definitions()[ $tool ]['output'], 'output' ), $tool . ' output must match its declared schema.' );
		}

		public function test_group_needs_bricks_abilities_and_its_own_switch(): void {
			$this->assertTrue( Config::group_available( 'bricks' ) );
			$this->assertArrayHasKey( 'run-bricks-ability', Abilities::enabled_definitions() );
			$this->configure( array() );
			$this->assertArrayNotHasKey( 'run-bricks-ability', Abilities::enabled_definitions() );
			$this->assertSame( 'site_agent_forbidden', Abilities::execute( 'bricks-abilities', array() )->get_error_code() );
			$this->configure( array( 'bricks' => true ) );
			\Bricks\Abilities\Manager::$enabled = false;
			$this->assertFalse( Config::group_available( 'bricks' ) );
			$this->assertArrayNotHasKey( 'run-bricks-ability', Abilities::enabled_definitions() );
			$this->assertSame( array(), Bricks::direct() );
		}

		public function test_direct_tools_follow_bricks_fast_path_without_code_abilities(): void {
			\Bricks\Abilities\Manager::$named[] = 'bricks/execute-php';
			try {
				$this->assertSame( array( 'bricks/site-agent-test-read', 'bricks/start-here' ), Bricks::direct() );
			} finally {
				array_pop( \Bricks\Abilities\Manager::$named );
			}
			$this->assertSame( 'bricks-site-agent-test-read', Bricks::tool_name( 'bricks/site-agent-test-read' ) );
		}

		public function test_listing_summarizes_and_describes_abilities(): void {
			$listing = Abilities::execute( 'bricks-abilities', array() );
			$this->assertMatchesOutputSchema( 'bricks-abilities', $listing );
			$names = array_column( $listing['abilities'], 'name' );
			$this->assertContains( 'bricks/site-agent-test-write', $names );
			$this->assertNotContains( 'bricks/site-agent-test-off', $names, 'Abilities switched off in Bricks stay hidden.' );
			$read = $listing['abilities'][ array_search( 'bricks/site-agent-test-read', $names, true ) ];
			$this->assertSame( 'Stand-in for bricks/site-agent-test-read.', $read['description'] );
			$this->assertSame( 'bricks-site-agent-test-read', $read['direct_tool'] );
			$this->assertTrue( $read['readonly'] );
			$this->assertArrayNotHasKey( 'input_schema', $read );

			$search = Abilities::execute( 'bricks-abilities', array( 'search' => 'TEST-WRITE' ) );
			$this->assertSame( array( 'bricks/site-agent-test-write' ), array_column( $search['abilities'], 'name' ) );

			$detail = Abilities::execute( 'bricks-abilities', array( 'ability_name' => 'bricks/site-agent-test-write' ) );
			$this->assertMatchesOutputSchema( 'bricks-abilities', $detail );
			$this->assertSame( 'Stand-in for bricks/site-agent-test-write. Second sentence.', $detail['abilities'][0]['description'] );
			$this->assertSame( '', $detail['abilities'][0]['direct_tool'] );
			$this->assertTrue( $detail['abilities'][0]['destructive'] );
			$this->assertArrayHasKey( 'postId', (array) $detail['abilities'][0]['input_schema']->properties );

			$this->assertSame( 'bricks_ability_unavailable', Abilities::execute( 'bricks-abilities', array( 'ability_name' => 'bricks/site-agent-test-off' ) )->get_error_code() );
			$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'bricks-abilities', array( 'ability_name' => 'core/get-site-info' ) ) );
		}

		public function test_run_applies_bricks_schemas_permissions_and_switches(): void {
			$run = Abilities::execute(
				'run-bricks-ability',
				array(
					'ability_name' => 'bricks/site-agent-test-write',
					'parameters'   => array( 'postId' => 5 ),
				)
			);
			$this->assertMatchesOutputSchema( 'run-bricks-ability', $run );
			$this->assertSame( array( 'echo' => array( 'postId' => 5 ) ), $run['result'] );
			// No parameters use the ability's own default.
			$this->assertSame( array(), (array) Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/site-agent-test-read' ) )['result']['echo'] );
			$invalid = Abilities::execute(
				'run-bricks-ability',
				array(
					'ability_name' => 'bricks/site-agent-test-write',
					'parameters'   => array( 'unknown' => 1 ),
				)
			);
			$this->assertSame( 'ability_invalid_input', $invalid->get_error_code() );
			// Bricks' own permission reason reaches the client instead of a generic refusal.
			$guarded = Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/site-agent-test-guarded' ) );
			$this->assertSame( 'bricks_forbidden_builder_permission', $guarded->get_error_code() );
			$this->assertSame( 'bricks_ability_unavailable', Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/site-agent-test-off' ) )->get_error_code() );
			$this->assertSame( 'bricks_ability_unavailable', Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/missing' ) )->get_error_code() );
			$this->assertInstanceOf( WP_Error::class, Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'core/get-site-info' ) ) );

			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'run-bricks-ability', $rows[0]['tool'] );
			$this->assertSame( 'bricks/site-agent-test-write post 5', $rows[0]['target'] );
		}

		public function test_bricks_php_also_needs_site_agent_php_execution(): void {
			$this->assertSame( 'bricks_code_ability', Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/execute-php' ) )->get_error_code() );
			$this->configure(
				array(
					'bricks'      => true,
					'php_execute' => true,
				)
			);
			$this->assertSame( 'bricks/execute-php', Abilities::execute( 'run-bricks-ability', array( 'ability_name' => 'bricks/execute-php' ) )['ability_name'] );
		}

		public function test_direct_tool_calls_are_limited_and_audited(): void {
			$server = new class() {
				public function get_server_id() {
					return 'site-agent';
				}
			};
			$other  = new class() {
				public function get_server_id() {
					return 'mcp-adapter-default-server';
				}
			};
			$args   = array( 'postId' => 7 );
			$this->assertSame( $args, Bricks::before_call( $args, 'bricks-site-agent-test-read', null, $server ) );
			Bricks::after_call( array( 'ok' => true ), $args, 'bricks-site-agent-test-read', null, $server );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'bricks/site-agent-test-read', end( $rows )['tool'] );
			$this->assertSame( 'post 7', end( $rows )['target'] );
			$this->assertTrue( end( $rows )['success'] );

			// Other servers and Site Agent's own tools pass through untouched.
			$this->configure( array() );
			$this->assertSame( $args, Bricks::before_call( $args, 'bricks-site-agent-test-read', null, $other ) );
			$this->assertSame( $args, Bricks::before_call( $args, 'site-agent-get-content', null, $server ) );
			$denied = Bricks::before_call( $args, 'bricks-site-agent-test-read', null, $server );
			$this->assertSame( 'site_agent_forbidden', $denied->get_error_code() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'denied', end( $rows )['error'] );
		}
	}
}
