<?php

namespace Gatilab\CoreTables\AI {
	if ( ! class_exists( Abilities::class ) ) {
		/** Stand-in for Core Tables' ability registration: Site Agent checks only that it exists. */
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
	use SiteAgent\Core_Tables;
	use SiteAgent\Permissions;

	final class CoreTablesTest extends TestCase {
		/** A served ability opened to MCP, one kept closed, and one Site Agent never serves. */
		const OPEN   = 'core-tables/get-appearance';
		const CLOSED = 'core-tables/get-revision';
		const ROGUE  = 'core-tables/site-agent-test-rogue';

		/**
		 * Abilities this test registered.
		 *
		 * @var string[]
		 */
		private $registered = array();

		/** @var bool */
		private $category = false;

		protected function setUp(): void {
			wp_set_current_user( 1 );
			delete_option( Audit::OPTION );
			$this->configure( array( 'core_tables' => true ) );
			if ( ! WP_Ability_Categories_Registry::get_instance()->is_registered( Core_Tables::CATEGORY ) ) {
				WP_Ability_Categories_Registry::get_instance()->register(
					Core_Tables::CATEGORY,
					array(
						'label'       => 'Core Tables',
						'description' => 'Stand-in Core Tables abilities.',
					)
				);
				$this->category = true;
			}
			foreach ( array(
				self::OPEN   => true,
				self::CLOSED => false,
				self::ROGUE  => true,
			) as $name => $public ) {
				if ( wp_has_ability( $name ) ) {
					continue;
				}
				WP_Abilities_Registry::get_instance()->register(
					$name,
					array(
						'label'               => $name,
						'description'         => 'Stand-in for ' . $name . '.',
						'category'            => Core_Tables::CATEGORY,
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function () {
							return array( 'values' => array() );
						},
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
						'meta'                => array( 'mcp' => array( 'public' => $public ) ),
					)
				);
				$this->registered[] = $name;
			}
		}

		protected function tearDown(): void {
			foreach ( $this->registered as $name ) {
				WP_Abilities_Registry::get_instance()->unregister( $name );
			}
			if ( $this->category ) {
				WP_Ability_Categories_Registry::get_instance()->unregister( Core_Tables::CATEGORY );
			}
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

		public function test_only_listed_abilities_core_tables_opens_to_mcp_are_served(): void {
			$this->assertTrue( Core_Tables::available() );
			$this->assertTrue( Config::group_available( 'core_tables' ) );
			$names = Core_Tables::names();
			foreach ( wp_get_abilities() as $ability ) {
				if ( Core_Tables::CATEGORY !== $ability->get_category() ) {
					continue;
				}
				$meta     = $ability->get_meta();
				$expected = in_array( $ability->get_name(), Core_Tables::SERVED, true ) && ! empty( $meta['mcp']['public'] );
				$this->assertSame( $expected, in_array( $ability->get_name(), $names, true ), $ability->get_name() );
			}
			$this->assertNotContains( self::ROGUE, $names, 'A new ability under the prefix needs Site Agent to list it first.' );
			if ( in_array( self::OPEN, $this->registered, true ) ) {
				$this->assertContains( self::OPEN, $names );
			}
			if ( in_array( self::CLOSED, $this->registered, true ) ) {
				$this->assertNotContains( self::CLOSED, $names, 'Core Tables\' own AI settings decide exposure.' );
			}
		}

		public function test_the_switch_decides_access_and_visibility(): void {
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
			$tools = array( $tool( 'core-tables-get-appearance' ), $tool( 'site-agent-site-context' ) );
			$this->assertTrue( Permissions::allowed( 'core_tables' ) );
			$this->assertCount( 2, Abilities::visible_tools( $tools, $this->server() ) );
			$this->configure( array() );
			$this->assertFalse( Permissions::allowed( 'core_tables' ) );
			$visible = Abilities::visible_tools( $tools, $this->server() );
			$this->assertSame( array( 'site-agent-site-context' ), array_map( static fn( $t ) => $t->get( 'name' ), $visible ) );
			wp_set_current_user( 0 );
			$this->configure( array( 'core_tables' => true ) );
			$this->assertFalse( Permissions::allowed( 'core_tables' ), 'Signed-out requests never pass.' );
		}

		public function test_calls_are_limited_and_audited_by_table(): void {
			$args = array(
				'id'               => 12,
				'expected_version' => 3,
			);
			$this->assertSame( $args, Core_Tables::before_call( $args, 'core-tables-trash', null, $this->server( 'mcp-adapter-default-server' ) ) );
			$this->assertSame( $args, Core_Tables::before_call( $args, 'site-agent-get-content', null, $this->server() ) );

			$this->assertSame( $args, Core_Tables::before_call( $args, 'core-tables-trash', null, $this->server() ) );
			Core_Tables::after_call( array( 'trashed' => true ), $args + array( 'name' => 'Private name' ), 'core-tables-trash', null, $this->server() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'core-tables/trash', end( $rows )['tool'] );
			$this->assertSame( 'table 12', end( $rows )['target'] );
			$this->assertStringNotContainsString( 'Private name', (string) wp_json_encode( $rows ) );

			$import = array(
				'source' => 'tablepress',
				'id'     => '7',
			);
			Core_Tables::before_call( $import, 'core-tables-import-from-plugin', null, $this->server() );
			Core_Tables::after_call( array( 'id' => 40 ), $import, 'core-tables-import-from-plugin', null, $this->server() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( '', end( $rows )['target'], 'An import\'s id is the other plugin\'s table.' );

			$this->configure( array() );
			$denied = Core_Tables::before_call( $args, 'core-tables-trash', null, $this->server() );
			$this->assertSame( 'site_agent_forbidden', $denied->get_error_code() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'denied', end( $rows )['error'] );
		}
	}
}
