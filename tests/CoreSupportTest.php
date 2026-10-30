<?php

namespace Gatilab\CoreSupport\Abilities {
	if ( ! class_exists( Abilities::class ) ) {
		/** Stand-in for Core Support's ability registration: Site Agent checks only that it exists. */
		final class Abilities {
			public static $site_agent_stub = true;
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use SiteAgent\Abilities;
	use SiteAgent\Audit;
	use SiteAgent\Config;
	use SiteAgent\Core_Support;
	use SiteAgent\Permissions;

	final class CoreSupportTest extends TestCase {
		/** Served abilities, plus one under the same prefix that Site Agent must never serve. */
		const NAMES = array( 'core-support/get-activity', 'core-support/get-ticket', 'core-support/notify-team', 'core-support/site-agent-test-rogue' );

		protected function setUp(): void {
			if ( empty( \Gatilab\CoreSupport\Abilities\Abilities::$site_agent_stub ) ) {
				$this->markTestSkipped( 'The real Core Support plugin is active; these tests use a stand-in.' );
			}
			wp_set_current_user( 1 );
			delete_option( Audit::OPTION );
			$this->configure( array( 'core_support' => true ) );
			if ( ! WP_Ability_Categories_Registry::get_instance()->is_registered( Core_Support::CATEGORY ) ) {
				WP_Ability_Categories_Registry::get_instance()->register(
					Core_Support::CATEGORY,
					array(
						'label'       => 'Core Support',
						'description' => 'Stand-in Core Support abilities.',
					)
				);
			}
			foreach ( self::NAMES as $name ) {
				WP_Abilities_Registry::get_instance()->register(
					$name,
					array(
						'label'               => $name,
						'description'         => 'Stand-in for ' . $name . '.',
						'category'            => Core_Support::CATEGORY,
						'input_schema'        => array(
							'type'                 => 'object',
							'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
							'additionalProperties' => false,
							'default'              => array(),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function ( $input ) {
							return array( 'echo' => $input );
						},
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
					)
				);
			}
		}

		protected function tearDown(): void {
			if ( empty( \Gatilab\CoreSupport\Abilities\Abilities::$site_agent_stub ) ) {
				return;
			}
			foreach ( self::NAMES as $name ) {
				if ( wp_has_ability( $name ) ) {
					WP_Abilities_Registry::get_instance()->unregister( $name );
				}
			}
			WP_Ability_Categories_Registry::get_instance()->unregister( Core_Support::CATEGORY );
			update_option( Config::OPTION, Config::defaults(), false );
			wp_set_current_user( 0 );
		}

		private function configure( array $groups ): void {
			update_option( Config::OPTION, array_merge( Config::defaults(), array( 'enabled' => true ), $groups ), false );
		}

		private function server( string $id = 'site-agent' ) {
			return new class( $id ) {
				private $id;

				public function __construct( string $id ) {
					$this->id = $id;
				}

				public function get_server_id() {
					return $this->id;
				}
			};
		}

		public function test_only_the_listed_abilities_are_served(): void {
			$this->assertTrue( Core_Support::available() );
			$this->assertTrue( Config::group_available( 'core_support' ) );
			$this->assertSame( array( 'core-support/get-activity', 'core-support/get-ticket', 'core-support/notify-team' ), Core_Support::names() );
			$this->assertNotContains( 'core-support/site-agent-test-rogue', Core_Support::names(), 'A new ability under the prefix needs Site Agent to list it first.' );
		}

		public function test_the_switch_decides_access(): void {
			$this->assertTrue( Permissions::allowed( 'core_support' ) );
			$this->configure( array() );
			$this->assertFalse( Permissions::allowed( 'core_support' ) );
			wp_set_current_user( 0 );
			$this->configure( array( 'core_support' => true ) );
			$this->assertFalse( Permissions::allowed( 'core_support' ), 'Signed-out requests never pass.' );
		}

		public function test_tools_are_hidden_while_the_group_is_off(): void {
			$tool  = static function ( string $name ) {
				return new class( $name ) {
					private $name;

					public function __construct( string $name ) {
						$this->name = $name;
					}

					public function get( string $key ) {
						return 'name' === $key ? $this->name : null;
					}
				};
			};
			$tools = array( $tool( 'core-support-get-activity' ), $tool( 'site-agent-site-context' ) );
			$this->assertCount( 2, Abilities::visible_tools( $tools, $this->server() ) );
			$this->configure( array() );
			$visible = Abilities::visible_tools( $tools, $this->server() );
			$this->assertSame( array( 'site-agent-site-context' ), array_map( static fn( $t ) => $t->get( 'name' ), $visible ) );
		}

		public function test_calls_are_limited_and_writes_audited(): void {
			$args = array( 'id' => 12 );
			// Other servers and Site Agent's own tools pass through untouched.
			$this->assertSame( $args, Core_Support::before_call( $args, 'core-support-notify-team', null, $this->server( 'mcp-adapter-default-server' ) ) );
			$this->assertSame( $args, Core_Support::before_call( $args, 'site-agent-get-content', null, $this->server() ) );

			$this->assertSame( $args, Core_Support::before_call( $args, 'core-support-notify-team', null, $this->server() ) );
			Core_Support::after_call( array( 'sent' => array() ), $args + array( 'message' => 'Secret text' ), 'core-support-notify-team', null, $this->server() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'core-support/notify-team', end( $rows )['tool'] );
			$this->assertSame( '#12', end( $rows )['target'] );
			$this->assertTrue( end( $rows )['success'] );
			$this->assertStringNotContainsString( 'Secret text', (string) wp_json_encode( $rows ) );

			$this->configure( array() );
			$denied = Core_Support::before_call( $args, 'core-support-notify-team', null, $this->server() );
			$this->assertSame( 'site_agent_forbidden', $denied->get_error_code() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'denied', end( $rows )['error'] );
		}

		public function test_successful_polls_stay_out_of_the_audit_history(): void {
			$server = $this->server();
			Core_Support::before_call( array(), 'core-support-get-activity', null, $server );
			Core_Support::after_call( array( 'events' => array() ), array(), 'core-support-get-activity', null, $server );
			$this->assertSame( array(), get_option( Audit::OPTION, array() ) );

			Core_Support::before_call( array(), 'core-support-get-activity', null, $server );
			Core_Support::after_call( new WP_Error( 'core_support_invalid_cursor', 'Bad cursor.' ), array(), 'core-support-get-activity', null, $server );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'core_support_invalid_cursor', end( $rows )['error'], 'A failed check is still recorded.' );
		}
	}
}
