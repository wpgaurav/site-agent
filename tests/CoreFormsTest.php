<?php

namespace Core_Forms\Commerce {
	if ( ! class_exists( ClientInvoiceApi::class ) ) {
		/** Stand-in for Core Commerce's invoice abilities: Site Agent checks only that it exists. */
		final class ClientInvoiceApi {
			public static $site_agent_stub = true;
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use SiteAgent\Abilities;
	use SiteAgent\Audit;
	use SiteAgent\Config;
	use SiteAgent\Core_Commerce;
	use SiteAgent\Core_Forms;
	use SiteAgent\Permissions;

	final class CoreFormsTest extends TestCase {
		/** A Core Commerce-category ability that Site Agent must never serve. */
		const ROGUE = 'core-forms/site-agent-test-rogue';
		/** A served ability, registered as a stand-in when Core Commerce does not register it here. */
		const SERVED = 'core-forms/get-commerce-summary';

		/**
		 * Forms created by a test.
		 *
		 * @var int[]
		 */
		private $forms = array();

		/**
		 * Abilities and the category this test registered.
		 *
		 * @var string[]
		 */
		private $registered = array();

		/** @var bool */
		private $category = false;

		protected function setUp(): void {
			wp_set_current_user( 1 );
			delete_option( Audit::OPTION );
			$this->configure(
				array(
					'core_forms'    => true,
					'core_commerce' => true,
				)
			);
			if ( ! WP_Ability_Categories_Registry::get_instance()->is_registered( Core_Commerce::CATEGORY ) ) {
				WP_Ability_Categories_Registry::get_instance()->register(
					Core_Commerce::CATEGORY,
					array(
						'label'       => 'Core Commerce',
						'description' => 'Stand-in Core Commerce abilities.',
					)
				);
				$this->category = true;
			}
			foreach ( array( self::ROGUE, self::SERVED ) as $name ) {
				if ( wp_has_ability( $name ) ) {
					continue;
				}
				WP_Abilities_Registry::get_instance()->register(
					$name,
					array(
						'label'               => $name,
						'description'         => 'Stand-in for ' . $name . '.',
						'category'            => Core_Commerce::CATEGORY,
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => static function () {
							return array( 'products' => 0 );
						},
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
					)
				);
				$this->registered[] = $name;
			}
		}

		protected function tearDown(): void {
			global $wpdb;
			foreach ( $this->forms as $id ) {
				if ( Core_Forms::available() ) {
					$wpdb->delete( $wpdb->prefix . 'cf_submissions', array( 'form_id' => $id ) );
				}
				wp_delete_post( $id, true );
			}
			foreach ( $this->registered as $name ) {
				WP_Abilities_Registry::get_instance()->unregister( $name );
			}
			if ( $this->category ) {
				WP_Ability_Categories_Registry::get_instance()->unregister( Core_Commerce::CATEGORY );
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

		private function tool( string $name ) {
			return new class( $name ) {
				private $name;

				public function __construct( string $name ) {
					$this->name = $name;
				}

				public function get( string $key ) {
					return 'name' === $key ? $this->name : null;
				}
			};
		}

		private function needs_core_forms(): void {
			if ( ! Core_Forms::available() ) {
				$this->markTestSkipped( 'Core Forms 4.11 or later is not active on the test installation.' );
			}
		}

		/** Run a Site Agent tool and fail on an error. */
		private function call( string $name, array $input = array() ): array {
			$result = Abilities::execute( $name, $input );
			$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
			$this->assertIsArray( $result, $name );
			$this->assertTrue( rest_validate_value_from_schema( $result, Abilities::definitions()[ $name ]['output'], 'output' ), $name . ' output must match its declared schema.' );
			return $result;
		}

		private function create_form(): array {
			$form          = $this->call(
				'core-forms-save-form',
				array(
					'title'    => 'Site Agent test inquiry',
					'fields'   => array(
						array(
							'name'     => 'name',
							'type'     => 'text',
							'label'    => 'Name',
							'required' => true,
						),
						array(
							'name'               => 'email',
							'type'               => 'email',
							'label'              => 'Email',
							'required'           => true,
							'wrapper_attributes' => array( 'class' => 'col-half' ),
						),
						array(
							'name'    => 'topic',
							'type'    => 'select',
							'label'   => 'Topic',
							'options' => array(
								array(
									'value' => 'sales',
									'label' => 'Sales',
								),
								array(
									'value' => 'support',
									'label' => 'Support',
								),
							),
						),
						array(
							'name'       => 'files',
							'type'       => 'file',
							'label'      => 'Files',
							'attributes' => array(
								'accept'         => '.pdf,image/*',
								'multiple'       => true,
								'data-max-files' => 3,
							),
						),
						array(
							'name'       => 'details',
							'type'       => 'textarea',
							'label'      => 'Details',
							'conditions' => array(
								array(
									'action' => 'show',
									'rule'   => 'topic=support',
								),
							),
						),
					),
					'settings' => array( 'submit_button_text' => 'Send it' ) + self::wrapper_setting(),
					'messages' => array( 'success' => 'Thanks from the test.' ),
				)
			);
			$this->forms[] = $form['form_id'];
			return $form;
		}

		public function test_secrets_are_hidden_and_restored(): void {
			$stored   = array(
				'type'           => 'webhook',
				'url'            => 'https://hooks.example.test/in',
				'webhook_secret' => 'top-secret',
				'nested'         => array( 'api_key' => 'nested-secret' ),
				'subject'        => 'Visible',
				'empty_token'    => '',
			);
			$redacted = Core_Forms::redact( $stored );
			$this->assertSame( Core_Forms::STORED, $redacted['webhook_secret'] );
			$this->assertSame( Core_Forms::STORED, $redacted['nested']['api_key'] );
			$this->assertSame( 'Visible', $redacted['subject'] );
			$this->assertSame( '', $redacted['empty_token'], 'An empty secret stays visibly empty.' );
			$this->assertStringNotContainsString( 'top-secret', (string) wp_json_encode( $redacted ) );
			$this->assertStringNotContainsString( 'nested-secret', (string) wp_json_encode( $redacted ) );

			$redacted['url'] = 'https://hooks.example.test/v2';
			$restored        = Core_Forms::unredact( $redacted, $stored );
			$this->assertSame( 'top-secret', $restored['webhook_secret'] );
			$this->assertSame( 'nested-secret', $restored['nested']['api_key'] );
			$this->assertSame( 'https://hooks.example.test/v2', $restored['url'] );
			$this->assertSame( 'site_agent_secret_unknown', Core_Forms::unredact( $redacted, null )->get_error_code(), 'A placeholder never becomes a stored value.' );
		}

		public function test_only_the_listed_commerce_abilities_are_served(): void {
			$this->assertTrue( Core_Commerce::available() );
			$this->assertTrue( Config::group_available( 'core_commerce' ) );
			$names = Core_Commerce::names();
			$this->assertContains( self::SERVED, $names );
			$this->assertNotContains( self::ROGUE, $names, 'A new ability under the prefix needs Site Agent to list it first.' );
			$this->assertSame( array(), array_diff( $names, Core_Commerce::SERVED ) );
		}

		public function test_switches_decide_access_and_visibility(): void {
			$tools = array( $this->tool( 'core-forms-get-order' ), $this->tool( 'site-agent-core-forms-get-form' ), $this->tool( 'site-agent-site-context' ) );
			$this->assertTrue( Permissions::allowed( 'core_commerce' ) );
			$this->assertCount( Core_Forms::available() ? 3 : 2, Abilities::visible_tools( $tools, $this->server() ) );
			$this->configure( array() );
			$this->assertFalse( Permissions::allowed( 'core_commerce' ) );
			$this->assertFalse( Permissions::allowed( 'core_forms' ) );
			$visible = Abilities::visible_tools( $tools, $this->server() );
			$this->assertSame( array( 'site-agent-site-context' ), array_map( static fn( $t ) => $t->get( 'name' ), $visible ) );
			$this->assertSame( 'site_agent_forbidden', Abilities::execute( 'core-forms-list-forms', array() )->get_error_code() );
			wp_set_current_user( 0 );
			$this->configure(
				array(
					'core_forms'    => true,
					'core_commerce' => true,
				)
			);
			$this->assertFalse( Permissions::allowed( 'core_commerce' ), 'Signed-out requests never pass.' );
			$this->assertFalse( Permissions::allowed( 'core_forms' ) );
		}

		public function test_commerce_calls_are_limited_and_audited_by_id(): void {
			$args = array( 'license_id' => 12 );
			$this->assertSame( $args, Core_Commerce::before_call( $args, 'core-forms-reset-license-activations', null, $this->server( 'mcp-adapter-default-server' ) ) );
			$this->assertSame( $args, Core_Commerce::before_call( $args, 'site-agent-core-forms-get-form', null, $this->server() ), 'Site Agent\'s own Core Forms tools are not Core Commerce tools.' );

			$this->assertSame( $args, Core_Commerce::before_call( $args, 'core-forms-reset-license-activations', null, $this->server() ) );
			Core_Commerce::after_call( array( 'reset' => 2 ), $args + array( 'note' => 'Private note' ), 'core-forms-reset-license-activations', null, $this->server() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'core-forms/reset-license-activations', end( $rows )['tool'] );
			$this->assertSame( '#12', end( $rows )['target'] );
			$this->assertStringNotContainsString( 'Private note', (string) wp_json_encode( $rows ) );

			$this->configure( array() );
			$denied = Core_Commerce::before_call( $args, 'core-forms-reset-license-activations', null, $this->server() );
			$this->assertSame( 'site_agent_forbidden', $denied->get_error_code() );
			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'denied', end( $rows )['error'] );
		}

		/** Field wrapper classes exist from Core Forms 5.2; older versions refuse the setting. */
		private static function wrapper_setting(): array {
			return function_exists( 'cf_sanitize_class_list' ) ? array( 'field_wrapper_class' => 'mb-4 "><script> md:w-1/2' ) : array();
		}

		public function test_a_form_is_built_from_fields_with_core_forms_renderer(): void {
			$this->needs_core_forms();
			$form = $this->create_form();
			$this->assertSame( array( 'form', 'messages' ), $form['saved'] );
			$this->assertSame( 'stored', $form['schema'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $form['fingerprint'] );
			$this->assertSame( '[cf_form slug="' . $form['slug'] . '"]', $form['shortcode'] );
			$this->assertSame( array( 'name', 'email', 'topic', 'files', 'details', '_submit' ), array_column( $form['fields'], 'name' ) );
			$this->assertStringContainsString( 'name="files[]"', $form['markup'] );
			$this->assertStringContainsString( 'accept=".pdf,image/*"', $form['markup'] );
			$this->assertStringContainsString( 'data-max-files="3"', $form['markup'] );
			$this->assertStringContainsString( 'class="col-half"', $form['markup'] );
			$this->assertStringContainsString( 'data-show-if="topic=support"', $form['markup'] );
			$this->assertStringContainsString( '>Send it</button>', $form['markup'] );
			$this->assertSame( 'Thanks from the test.', $form['messages']['success'] );
			$this->assertSame( $form, array( 'saved' => $form['saved'] ) + $this->call( 'core-forms-get-form', array( 'form_id' => $form['form_id'] ) ) );
			if ( ! function_exists( 'cf_sanitize_class_list' ) ) {
				$refused = Abilities::execute(
					'core-forms-save-form',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $form['fingerprint'],
						'settings'             => array( 'field_wrapper_class' => 'mb-4 "><script>' ),
					)
				);
				$this->assertSame( 'site_agent_core_forms_version', $refused->get_error_code(), 'Core Forms before 5.2 would store the classes unsanitized.' );
				$this->assertArrayNotHasKey( 'field_wrapper_class', $this->call( 'core-forms-get-form', array( 'form_id' => $form['form_id'] ) )['settings'] );
				return;
			}
			$this->assertSame( 'mb-4 md:w-1/2', $form['settings']['field_wrapper_class'] );

			// The rendered form keeps the field wrapper classes on every wrapper.
			$html = cf_get_form( $form['form_id'] )->get_html();
			$this->assertMatchesRegularExpression( '/class="[^"]*\bmb-4\b[^"]*"[^>]*data-cf-field-name="name"/', $html );
		}

		public function test_changes_need_the_current_fingerprint(): void {
			$this->needs_core_forms();
			$form    = $this->create_form();
			$changed = $this->call(
				'core-forms-save-form',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $form['fingerprint'],
					'title'                => 'Renamed inquiry',
				)
			);
			$this->assertSame( 'Renamed inquiry', $changed['title'] );
			$this->assertNotSame( $form['fingerprint'], $changed['fingerprint'] );

			$stale = Abilities::execute(
				'core-forms-save-form',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $form['fingerprint'],
					'title'                => 'Lost update',
				)
			);
			$this->assertSame( 'cf_edit_conflict', $stale->get_error_code() );
			$this->assertSame( 'Renamed inquiry', get_the_title( $form['form_id'] ) );
			$this->assertSame(
				'site_agent_fingerprint_required',
				Abilities::execute(
					'core-forms-save-form',
					array(
						'form_id' => $form['form_id'],
						'title'   => 'No version',
					)
				)->get_error_code()
			);
			$this->assertSame(
				'site_agent_use_save_actions',
				Abilities::execute(
					'core-forms-save-form',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $changed['fingerprint'],
						'settings'             => array( 'actions' => array() ),
					)
				)->get_error_code()
			);
			$this->assertSame(
				'site_agent_message_unknown',
				Abilities::execute(
					'core-forms-save-form',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $changed['fingerprint'],
						'messages'             => array( 'not_a_message' => 'x' ),
					)
				)->get_error_code()
			);

			$rows = get_option( Audit::OPTION, array() );
			$this->assertSame( 'form ' . $form['form_id'], end( $rows )['target'] );
		}

		public function test_actions_keep_secrets_order_and_field_mappings(): void {
			$this->needs_core_forms();
			$form    = $this->create_form();
			$types   = array_column( $this->call( 'core-forms-overview' )['action_types'], 'type' );
			$secret  = in_array( 'webhook', $types, true );
			$actions = array(
				array(
					'type'         => 'email',
					'to'           => 'owner@example.test',
					'from'         => 'site@example.test',
					'subject'      => 'New [topic] inquiry from [name]',
					'message'      => '[all:label]',
					'content_type' => 'text/html',
					'headers'      => 'Reply-To: [email]',
				),
			);
			if ( $secret ) {
				$actions[] = array(
					'type'           => 'webhook',
					'url'            => 'https://hooks.example.test/in',
					'content_type'   => 'json',
					'webhook_secret' => 'top-secret',
					'webhook_key_id' => 'default',
				);
			}
			$saved = $this->call(
				'core-forms-save-actions',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $form['fingerprint'],
					'actions'              => $actions,
				)
			);
			$this->assertSame( array_column( $actions, 'type' ), array_column( $saved['actions'], 'type' ) );
			$this->assertStringStartsWith( 'action-', $saved['actions'][0]['settings']['_cf_action_id'] );
			$this->assertStringNotContainsString( 'top-secret', (string) wp_json_encode( $saved ) );

			if ( $secret ) {
				// Reorder: keep the webhook by reference, send the email back edited.
				$email            = $saved['actions'][0]['settings'];
				$email['subject'] = 'Inquiry: [topic]';
				$reordered        = $this->call(
					'core-forms-save-actions',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $saved['fingerprint'],
						'actions'              => array(
							array(
								'keep_index'       => 1,
								'keep_fingerprint' => $saved['actions'][1]['fingerprint'],
							),
							$email,
						),
					)
				);
				$this->assertSame( array( 'webhook', 'email' ), array_column( $reordered['actions'], 'type' ) );
				$this->assertSame( Core_Forms::STORED, $reordered['actions'][0]['settings']['webhook_secret'] );
				// Edit the webhook, leaving its secret as [stored].
				$hook        = $reordered['actions'][0]['settings'];
				$hook['url'] = 'https://hooks.example.test/v2';
				$edited      = $this->call(
					'core-forms-save-actions',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $reordered['fingerprint'],
						'actions'              => array(
							$hook,
							array(
								'keep_index'       => 1,
								'keep_fingerprint' => $reordered['actions'][1]['fingerprint'],
							),
						),
					)
				);
				$stored      = cf_get_form( $form['form_id'], 'draft' )->settings['actions'];
				$this->assertSame( 'https://hooks.example.test/v2', $stored[0]['url'] );
				$this->assertSame( 'top-secret', $stored[0]['webhook_secret'] );
				$this->assertSame( 'Inquiry: [topic]', $stored[1]['subject'] );
				$saved = $edited;
			}

			$this->assertSame(
				'site_agent_action_unavailable',
				Abilities::execute(
					'core-forms-save-actions',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $saved['fingerprint'],
						'actions'              => array( array( 'type' => 'site_agent_missing_integration' ) ),
					)
				)->get_error_code()
			);
			$this->assertSame(
				'cf_mapping_missing',
				Abilities::execute(
					'core-forms-save-actions',
					array(
						'form_id'              => $form['form_id'],
						'expected_fingerprint' => $saved['fingerprint'],
						'actions'              => array(
							array(
								'type'        => 'autoresponder',
								'email_field' => 'no_such_field',
								'subject'     => 'Thanks',
								'message'     => 'Hi',
							),
						),
					)
				)->get_error_code(),
				'Core Forms checks mapped field names.'
			);
			$cleared = $this->call(
				'core-forms-save-actions',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $saved['fingerprint'],
					'actions'              => array(),
				)
			);
			$this->assertSame( array(), $cleared['actions'] );
		}

		public function test_rehearsal_plans_without_storing_or_sending(): void {
			$this->needs_core_forms();
			$form    = $this->create_form();
			$saved   = $this->call(
				'core-forms-save-actions',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $form['fingerprint'],
					'actions'              => array(
						array(
							'type'       => 'email',
							'to'         => 'owner@example.test',
							'subject'    => 'New inquiry',
							'message'    => '[all:label]',
							'conditions' => array(
								array(
									'field'    => 'topic',
									'operator' => 'equals',
									'value'    => 'sales',
								),
							),
						),
					),
				)
			);
			$mails   = 0;
			$counter = static function ( $short ) use ( &$mails ) {
				++$mails;
				return $short;
			};
			add_filter( 'pre_wp_mail', $counter );
			try {
				$plan = $this->call(
					'core-forms-rehearse',
					array(
						'form_id' => $form['form_id'],
						'data'    => array(
							'name'  => 'Ada',
							'email' => 'ada@example.test',
							'topic' => 'support',
						),
					)
				);
			} finally {
				remove_filter( 'pre_wp_mail', $counter );
			}
			$this->assertTrue( $plan['valid'] );
			$this->assertSame( 'would_skip', $plan['actions'][0]['outcome'], 'The action condition does not match support.' );
			$this->assertSame( 0, $mails );
			$this->assertSame( 0, $saved['submissions']['total'] );
			$invalid = $this->call(
				'core-forms-rehearse',
				array(
					'form_id' => $form['form_id'],
					'data'    => array( 'name' => 'No email' ),
				)
			);
			$this->assertFalse( $invalid['valid'] );
		}

		public function test_entries_are_listed_read_and_triaged(): void {
			global $wpdb;
			$this->needs_core_forms();
			$form = $this->create_form();
			$wpdb->insert(
				$wpdb->prefix . 'cf_submissions',
				array(
					'form_id'      => $form['form_id'],
					'data'         => wp_json_encode(
						array(
							'name'  => 'Ada',
							'email' => 'ada@example.test',
						)
					),
					'status'       => 'new',
					'is_spam'      => 0,
					'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$entry_id = (int) $wpdb->insert_id;
			$list     = $this->call( 'core-forms-list-submissions', array( 'form_id' => $form['form_id'] ) );
			$this->assertSame( 1, $list['total'] );
			$this->assertSame( 'Ada', $list['submissions'][0]['data']['name'] );
			$this->assertSame(
				0,
				$this->call(
					'core-forms-list-submissions',
					array(
						'form_id' => $form['form_id'],
						'status'  => 'archived',
					)
				)['total']
			);
			$this->assertSame( $entry_id, $this->call( 'core-forms-get-submission', array( 'submission_id' => $entry_id ) )['id'] );
			$updated = $this->call(
				'core-forms-update-submission',
				array(
					'submission_id' => $entry_id,
					'status'        => 'read',
				)
			);
			$this->assertSame( 'read', $updated['status'] );
			$this->assertSame( 1, $this->call( 'core-forms-get-form', array( 'form_id' => $form['form_id'] ) )['submissions']['total'] );
			$rows = array_values( array_filter( get_option( Audit::OPTION, array() ), static fn( $row ) => 'core-forms-update-submission' === $row['tool'] ) );
			$this->assertSame( 'entry ' . $entry_id, $rows[0]['target'] );
		}

		public function test_forms_are_duplicated_trashed_and_block_forms_protected(): void {
			$this->needs_core_forms();
			$form          = $this->create_form();
			$copy          = $this->call( 'core-forms-duplicate-form', array( 'form_id' => $form['form_id'] ) );
			$this->forms[] = $copy['form_id'];
			$this->assertSame( $form['form_id'], $copy['source_form_id'] );
			$this->assertNotSame( $form['slug'], $copy['slug'] );
			$this->assertSame( array_column( $form['fields'], 'name' ), array_column( $copy['fields'], 'name' ) );

			$this->assertSame(
				'cf_edit_conflict',
				Abilities::execute(
					'core-forms-trash-form',
					array(
						'form_id'              => $copy['form_id'],
						'expected_fingerprint' => $form['fingerprint'],
					)
				)->get_error_code()
			);
			$trashed = $this->call(
				'core-forms-trash-form',
				array(
					'form_id'              => $copy['form_id'],
					'expected_fingerprint' => $copy['fingerprint'],
				)
			);
			$this->assertSame( 'trash', $trashed['status'] );

			$blocks        = $this->call(
				'core-forms-save-form',
				array(
					'title'  => 'Site Agent block form',
					'markup' => '<!-- wp:core-forms/field {"name":"email","type":"email"} /-->',
				)
			);
			$this->forms[] = $blocks['form_id'];
			$refused       = Abilities::execute(
				'core-forms-save-form',
				array(
					'form_id'              => $blocks['form_id'],
					'expected_fingerprint' => $blocks['fingerprint'],
					'fields'               => array(
						array(
							'name' => 'email',
							'type' => 'email',
						),
					),
				)
			);
			$this->assertSame( 'site_agent_block_form', $refused->get_error_code() );
		}

		public function test_stepped_layouts_render_and_survive_field_edits(): void {
			$this->needs_core_forms();
			$steps         = array(
				array(
					'name'  => 'name',
					'type'  => 'text',
					'label' => 'Name',
					'group' => 'About you',
				),
				array(
					'name'  => 'email',
					'type'  => 'email',
					'label' => 'Email',
					'group' => 'Contact',
				),
			);
			$form          = $this->call(
				'core-forms-save-form',
				array(
					'title'  => 'Site Agent stepped form',
					'fields' => $steps,
					'layout' => array( 'type' => 'multi-step' ),
				)
			);
			$this->forms[] = $form['form_id'];
			$this->assertSame( 'multi-step', $form['layout']['type'] );
			$this->assertStringContainsString( 'cf-steps', $form['markup'] );
			$this->assertNotContains( '_submit', array_column( $form['fields'], 'name' ), 'The step renderer adds its own submit button.' );

			$steps[]  = array(
				'name'  => 'phone',
				'type'  => 'tel',
				'label' => 'Phone',
				'group' => 'Contact',
			);
			$extended = $this->call(
				'core-forms-save-form',
				array(
					'form_id'              => $form['form_id'],
					'expected_fingerprint' => $form['fingerprint'],
					'fields'               => $steps,
				)
			);
			$this->assertSame( array( 'fields' ), $extended['saved'] );
			$this->assertSame( 'multi-step', $extended['layout']['type'], 'Sending fields alone keeps the layout.' );
			$this->assertStringContainsString( 'name="phone"', $extended['markup'] );
		}

		public function test_overview_hides_secrets_and_names_constants(): void {
			$this->needs_core_forms();
			$overview = $this->call( 'core-forms-overview' );
			$this->assertSame( CORE_FORMS_VERSION, $overview['version'] );
			$this->assertContains( 'email', array_column( $overview['action_types'], 'type' ) );
			$this->assertContains( 'file', $overview['field_types'] );
			if ( function_exists( 'cf_sanitize_class_list' ) ) {
				$this->assertArrayHasKey( 'field_wrapper_class', $overview['form_settings'] );
			}
			if ( class_exists( '\\Core_Forms\\Credentials' ) ) {
				// wp-config.php keys arrived with Core Forms 5.2.
				$this->assertContains( 'cloudflare_turnstile.secret_key', array_column( $overview['constants'], 'setting' ) );
				$this->assertContains( array( 'CORE_FORMS_LICENSE_KEY' ), array_column( $overview['constants'], 'constants' ) );
			} else {
				$this->assertSame( array(), $overview['constants'] );
			}
			foreach ( array( 'google_recaptcha', 'cloudflare_turnstile' ) as $group ) {
				$secret = $overview['settings'][ $group ]['secret_key'] ?? '';
				$this->assertContains( $secret, array( '', Core_Forms::STORED ) );
			}
		}
	}
}
