<?php
/**
 * Regression coverage for native WordPress content contracts.
 *
 * @package SiteAgent
 */

use PHPUnit\Framework\TestCase;
use SiteAgent\Abilities;
use SiteAgent\Audit;
use SiteAgent\Config;
use SiteAgent\Vendor\WP\MCP\Core\McpRequestContext;
use SiteAgent\Vendor\WP\MCP\Core\McpServer;
use SiteAgent\Vendor\WP\MCP\Domain\Tools\McpTool;
use SiteAgent\Vendor\WP\MCP\Handlers\Tools\ToolsHandler;
use SiteAgent\Vendor\WP\McpSchema\Record\CallToolRequest;
use SiteAgent\Vendor\WP\McpSchema\Schemas;

/** Verify staged edits, metadata permissions and complete-save hooks. */
final class ContentRegressionTest extends TestCase {
	/**
	 * Created post IDs.
	 *
	 * @var int[]
	 */
	private $posts = array();

	/**
	 * Created tag IDs.
	 *
	 * @var int[]
	 */
	private $terms = array();

	/**
	 * Registered test keys.
	 *
	 * @var string[]
	 */
	private $meta_keys = array();

	/**
	 * Temporary hooks.
	 *
	 * @var array[]
	 */
	private $filters = array();

	/**
	 * Original access configuration.
	 *
	 * @var mixed
	 */
	private $config;

	/**
	 * Original audit log.
	 *
	 * @var mixed
	 */
	private $audit;

	/**
	 * Original current user.
	 *
	 * @var int
	 */
	private $user;

	/**
	 * Original featured-image support.
	 *
	 * @var bool
	 */
	private $thumbnail_support;

	protected function setUp(): void {
		$this->config            = get_option( Config::OPTION );
		$this->audit             = get_option( Audit::OPTION );
		$this->user              = get_current_user_id();
		$this->thumbnail_support = post_type_supports( 'post', 'thumbnail' );
		wp_set_current_user( 1 );
		update_option(
			Config::OPTION,
			array_merge(
				Config::defaults(),
				array(
					'enabled'       => true,
					'content_write' => true,
					'audit_enabled' => false,
				)
			),
			false
		);
		$this->filter(
			'wp_insert_post',
			function ( $id, $post, $update ) {
				if ( ! $update ) {
					$this->posts[] = $id;
				}
			},
			3
		);
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		foreach ( array_unique( $this->posts ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( array_unique( $this->terms ) as $id ) {
			wp_delete_term( $id, 'post_tag' );
		}
		foreach ( $this->meta_keys as $key ) {
			unregister_post_meta( 'post', $key );
		}
		foreach ( array(
			Config::OPTION => $this->config,
			Audit::OPTION  => $this->audit,
		) as $name => $value ) {
			if ( false === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		if ( ! $this->thumbnail_support ) {
			remove_post_type_support( 'post', 'thumbnail' );
		}
		wp_set_current_user( $this->user );
	}

	private function filter( string $name, callable $callback, int $arguments = 1 ): void {
		add_filter( $name, $callback, 10, $arguments );
		$this->filters[] = array( $name, $callback, 10 );
	}

	private function register_meta( string $key, array $args ): void {
		register_post_meta(
			'post',
			$key,
			$args + array(
				'single'        => true,
				'show_in_rest'  => true,
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		$this->meta_keys[] = $key;
	}

	private function save( array $input ) {
		$result = Abilities::execute( 'save-content', $input );
		$id     = is_wp_error( $result ) ? (int) ( $result->get_error_data()['post_id'] ?? 0 ) : $result['id'];
		if ( $id ) {
			$this->posts[] = $id;
		}
		return $result;
	}

	private function draft(): array {
		$result = $this->save(
			array(
				'title'   => 'Content regression fixture',
				'content' => 'Original content',
				'excerpt' => 'Original excerpt',
			)
		);
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_partial_autosaves_preserve_live_fields_and_previous_staged_edits(): void {
		$live = $this->save(
			array(
				'title'   => 'Original title',
				'content' => 'Original content',
				'excerpt' => 'Original excerpt',
				'status'  => 'publish',
			)
		);
		$this->assertIsArray( $live );
		$first = $this->save(
			array(
				'post_id'                 => $live['id'],
				'expected_content_sha256' => $live['content_sha256'],
				'title'                   => 'Staged title',
			)
		);
		$this->assertIsArray( $first );
		$read = Abilities::execute(
			'get-content',
			array(
				'post_id'          => $live['id'],
				'autosave_content' => true,
			)
		);
		$this->assertSame( 'Original content', $read['autosave']['content'] );
		$this->assertSame( 'Original excerpt', $read['autosave']['excerpt'] );
		$second = $this->save(
			array(
				'post_id'                 => $live['id'],
				'expected_content_sha256' => $live['content_sha256'],
				'excerpt'                 => 'Staged excerpt',
			)
		);
		$this->assertIsArray( $second );
		$read = Abilities::execute(
			'get-content',
			array(
				'post_id'          => $live['id'],
				'autosave_content' => true,
			)
		);
		$this->assertSame( 'Staged title', $read['autosave']['title'] );
		$this->assertSame( 'Original content', $read['autosave']['content'] );
		$this->assertSame( 'Staged excerpt', $read['autosave']['excerpt'] );
		$this->assertSame( $live['content_sha256'], $read['content_sha256'] );
		$this->assertSame( 'Original title', $read['title'] );
		$this->assertSame( 'Original excerpt', $read['excerpt'] );
	}

	public function test_same_second_title_and_content_stages_are_reported_as_working_autosaves(): void {
		$live = $this->save(
			array(
				'title'   => 'Live title',
				'content' => 'Live content',
				'excerpt' => 'Live excerpt',
				'status'  => 'publish',
			)
		);
		$this->assertIsArray( $live );
		$this->filter(
			'wp_insert_post_data',
			static function ( $data ) use ( $live ) {
				if ( 'revision' === $data['post_type'] && (int) $data['post_parent'] === $live['id'] ) {
					$data['post_modified_gmt'] = $live['modified_gmt'];
					$data['post_modified']     = get_date_from_gmt( $live['modified_gmt'] );
				}
				return $data;
			}
		);
		$title = $this->save(
			array(
				'post_id'                 => $live['id'],
				'expected_content_sha256' => $live['content_sha256'],
				'title'                   => 'Working title',
			)
		);
		$this->assertIsArray( $title );
		$this->assertSame( $live['modified_gmt'], $title['autosave']['modified_gmt'] );
		$this->assertTrue( $title['autosave']['newer'], 'A title-only stage must be discoverable within the same second as the live save.' );
		$content = $this->save(
			array(
				'post_id'                 => $live['id'],
				'expected_content_sha256' => $live['content_sha256'],
				'content'                 => 'Working content',
			)
		);
		$this->assertIsArray( $content );
		$this->assertSame( $live['modified_gmt'], $content['autosave']['modified_gmt'] );
		$this->assertTrue( $content['autosave']['newer'] );
		$read = Abilities::execute(
			'get-content',
			array(
				'post_id'          => $live['id'],
				'autosave_content' => true,
			)
		);
		$this->assertTrue( $read['autosave']['newer'] );
		$this->assertSame( 'Working title', $read['autosave']['title'] );
		$this->assertSame( 'Working content', $read['autosave']['content'] );
		$this->assertSame( 'Live excerpt', $read['autosave']['excerpt'] );
	}

	public function test_partial_staging_after_live_changes_uses_the_new_live_base(): void {
		$live = $this->save(
			array(
				'title'   => 'Original title',
				'content' => 'Original content',
				'excerpt' => 'Original excerpt',
				'status'  => 'publish',
			)
		);
		$this->assertIsArray( $live );
		$this->filter(
			'wp_insert_post_data',
			static function ( $data ) use ( $live ) {
				if ( 'revision' === $data['post_type'] && (int) $data['post_parent'] === $live['id'] ) {
					$data['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );
					$data['post_modified']     = get_date_from_gmt( $data['post_modified_gmt'] );
				}
				return $data;
			}
		);
		$this->assertIsArray(
			$this->save(
				array(
					'post_id'                 => $live['id'],
					'expected_content_sha256' => $live['content_sha256'],
					'title'                   => 'Outdated staged title',
				)
			)
		);
		$this->assertIsArray(
			$this->save(
				array(
					'post_id'                 => $live['id'],
					'expected_content_sha256' => $live['content_sha256'],
					'title'                   => 'Current live title',
					'excerpt'                 => 'Current live excerpt',
					'status'                  => 'publish',
				)
			)
		);
		$stale = Abilities::execute(
			'get-content',
			array(
				'post_id'          => $live['id'],
				'autosave_content' => true,
			)
		);
		$this->assertGreaterThan( $stale['modified_gmt'], $stale['autosave']['modified_gmt'] );
		$this->assertFalse( $stale['autosave']['newer'], 'A tracked autosave with a stale live base is not the working copy even if its timestamp is later.' );
		$this->assertIsArray(
			$this->save(
				array(
					'post_id'                 => $live['id'],
					'expected_content_sha256' => $live['content_sha256'],
					'content'                 => 'New staged content',
				)
			)
		);
		$read = Abilities::execute(
			'get-content',
			array(
				'post_id'          => $live['id'],
				'autosave_content' => true,
			)
		);
		$this->assertSame( 'Current live title', $read['autosave']['title'] );
		$this->assertSame( 'Current live excerpt', $read['autosave']['excerpt'] );
		$this->assertSame( 'New staged content', $read['autosave']['content'] );
		$this->assertFalse( metadata_exists( 'post', $live['id'], '_site_agent_stage_base_sha256' ), 'The autosave base must belong to the revision, not its parent.' );
	}

	public function test_invalid_registered_meta_is_rejected_before_creating_or_editing_posts(): void {
		$this->register_meta(
			'site_agent_regression_integer',
			array(
				'type'         => 'integer',
				'show_in_rest' => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 2,
						'maximum' => 5,
					),
				),
			)
		);
		$this->register_meta(
			'site_agent_regression_choice',
			array(
				'show_in_rest' => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'alpha', 'beta' ),
					),
				),
			)
		);
		$draft = $this->draft();
		foreach ( array( array( 'site_agent_regression_integer', 'invalid' ), array( 'site_agent_regression_integer', 1 ), array( 'site_agent_regression_integer', 6 ), array( 'site_agent_regression_choice', 'other' ) ) as $case ) {
			$result = $this->save(
				array(
					'post_id'                 => $draft['id'],
					'expected_content_sha256' => $draft['content_sha256'],
					'content'                 => 'Must not be saved',
					'meta'                    => array( $case[0] => $case[1] ),
				)
			);
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'Original content', get_post( $draft['id'] )->post_content );
			$this->assertFalse( metadata_exists( 'post', $draft['id'], $case[0] ) );
		}
		$title  = 'Invalid meta must not create ' . wp_generate_uuid4();
		$result = $this->save(
			array(
				'title' => $title,
				'meta'  => array( 'site_agent_regression_integer' => 1 ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'title'       => $title,
					'post_status' => 'any',
				)
			)
		);
	}

	public function test_registered_meta_permissions_block_writes_and_deletions_before_post_changes(): void {
		$key = '_site_agent_regression_denied';
		$this->register_meta( $key, array( 'auth_callback' => '__return_false' ) );
		$draft = $this->draft();
		update_post_meta( $draft['id'], $key, 'Protected original' );
		foreach ( array( 'Unauthorized overwrite', '' ) as $value ) {
			$result = $this->save(
				array(
					'post_id'                 => $draft['id'],
					'expected_content_sha256' => $draft['content_sha256'],
					'content'                 => 'Must not be saved',
					'meta'                    => array( $key => $value ),
				)
			);
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'Original content', get_post( $draft['id'] )->post_content );
			$this->assertSame( 'Protected original', get_post_meta( $draft['id'], $key, true ) );
		}
	}

	public function test_delete_meta_uses_the_native_delete_capability(): void {
		$key = 'site_agent_regression_delete_denied';
		$this->register_meta(
			$key,
			array(
				'auth_callback' => static function ( $allowed, $meta_key, $id, $user, $cap ) {
					return 'delete_post_meta' !== $cap;
				},
			)
		);
		$draft = $this->save(
			array(
				'title'   => 'Delete permission fixture',
				'content' => 'Original content',
				'meta'    => array( $key => 'Keep this value' ),
			)
		);
		$this->assertIsArray( $draft );
		$result = $this->save(
			array(
				'post_id'                 => $draft['id'],
				'expected_content_sha256' => $draft['content_sha256'],
				'content'                 => 'Must not be saved',
				'meta'                    => array( $key => '' ),
			)
		);
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( 'Original content', get_post( $draft['id'] )->post_content );
		$this->assertSame( 'Keep this value', get_post_meta( $draft['id'], $key, true ) );
	}

	public function test_denied_registered_meta_keeps_new_live_posts_in_draft_and_reports_retry_identity(): void {
		$key = '_site_agent_regression_new_denied';
		$this->register_meta( $key, array( 'auth_callback' => '__return_false' ) );
		$result = $this->save(
			array(
				'title'   => 'Denied live meta fixture',
				'content' => 'Saved as draft',
				'status'  => 'publish',
				'meta'    => array( $key => 'Unauthorized value' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'partially_saved', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 'rest_cannot_update', $data['cause'] );
		$this->assertSame( 'draft', $data['post']['status'] );
		$this->assertFalse( metadata_exists( 'post', $data['post_id'], $key ) );
		$retry = $this->save(
			array(
				'post_id'                 => $data['post_id'],
				'expected_content_sha256' => $data['post']['content_sha256'],
				'status'                  => 'publish',
			)
		);
		$this->assertIsArray( $retry );
		$this->assertSame( $data['post_id'], $retry['id'] );
		$this->assertSame( 'publish', $retry['status'] );
	}

	public function test_registered_meta_uses_rest_coercion_and_native_meta_sanitization(): void {
		$this->register_meta( 'site_agent_regression_boolean', array( 'type' => 'boolean' ) );
		$this->register_meta(
			'site_agent_regression_alias',
			array(
				'show_in_rest'      => array( 'name' => 'regression_alias' ),
				'sanitize_callback' => static function ( $value ) {
					return strtoupper( $value );
				},
			)
		);
		$saved = $this->save(
			array(
				'title' => 'Native meta sanitization',
				'meta'  => array(
					'site_agent_regression_boolean' => 'false',
					'site_agent_regression_alias'   => 'a \\ b',
				),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertSame( '', get_post_meta( $saved['id'], 'site_agent_regression_boolean', true ) );
		$this->assertSame( 'A \\ B', get_post_meta( $saved['id'], 'site_agent_regression_alias', true ) );
		$deleted = $this->save(
			array(
				'post_id'                 => $saved['id'],
				'expected_content_sha256' => $saved['content_sha256'],
				'meta'                    => array( 'site_agent_regression_boolean' => '' ),
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertFalse( metadata_exists( 'post', $saved['id'], 'site_agent_regression_boolean' ) );
	}

	public function test_explicit_unregistered_meta_allowlist_keeps_write_and_delete_behavior(): void {
		$key = '_site_agent_regression_custom';
		$this->filter(
			'site_agent_post_meta_keys',
			static function ( $keys ) use ( $key ) {
				$keys[] = $key;
				return $keys;
			}
		);
		$saved = $this->save(
			array(
				'title' => 'Allowlisted meta',
				'meta'  => array( $key => 'Custom \\ value' ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertSame( 'Custom \\ value', get_post_meta( $saved['id'], $key, true ) );
		$deleted = $this->save(
			array(
				'post_id'                 => $saved['id'],
				'expected_content_sha256' => $saved['content_sha256'],
				'meta'                    => array( $key => '' ),
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertFalse( metadata_exists( 'post', $saved['id'], $key ) );
	}

	public function test_explicit_filter_does_not_bypass_registered_meta_auth_or_types(): void {
		$key = '_site_agent_regression_filtered_registered';
		$this->register_meta(
			$key,
			array(
				'show_in_rest'  => false,
				'type'          => 'integer',
				'auth_callback' => '__return_false',
			)
		);
		$this->filter(
			'site_agent_post_meta_keys',
			static function ( $keys ) use ( $key ) {
				$keys[] = $key;
				return $keys;
			}
		);
		$draft = $this->draft();
		foreach ( array( 2, 'invalid' ) as $value ) {
			$result = $this->save(
				array(
					'post_id'                 => $draft['id'],
					'expected_content_sha256' => $draft['content_sha256'],
					'content'                 => 'Must not be saved',
					'meta'                    => array( $key => $value ),
				)
			);
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'Original content', get_post( $draft['id'] )->post_content );
			$this->assertFalse( metadata_exists( 'post', $draft['id'], $key ) );
		}
	}

	/**
	 * Complete-save hooks and revisions run outside an autosave request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_after_insert_hook_sees_final_meta_terms_and_featured_media_on_create_and_update(): void {
		$key = 'site_agent_regression_hook';
		$this->register_meta( $key, array() );
		add_post_type_support( 'post', 'thumbnail' );
		$attachment    = wp_insert_attachment(
			array(
				'post_title'     => 'Content hook image',
				'post_mime_type' => 'image/png',
				'guid'           => 'https://example.org/content-hook.png',
			)
		);
		$this->posts[] = $attachment;
		update_attached_file( $attachment, 'content-hook.png' );
		update_post_meta(
			$attachment,
			'_wp_attachment_metadata',
			array(
				'width'  => 1,
				'height' => 1,
				'file'   => 'content-hook.png',
			)
		);
		$seen = array();
		$this->filter(
			'wp_after_insert_post',
			static function ( $id, $post, $update, $before ) use ( $key, &$seen ) {
				if ( 'post' === $post->post_type && 'Content hook fixture' === $post->post_title ) {
					$seen[] = array( get_post_meta( $id, $key, true ), wp_get_object_terms( $id, 'post_tag', array( 'fields' => 'names' ) ), get_post_thumbnail_id( $id ), $update, $before ? $before->post_status : null );
				}
			},
			4
		);
		$label = 'content-hook-' . wp_generate_uuid4();
		$saved = $this->save(
			array(
				'title'          => 'Content hook fixture',
				'content'        => 'Hook body',
				'meta'           => array( $key => 'first' ),
				'terms'          => array( 'post_tag' => array( $label ) ),
				'featured_media' => $attachment,
			)
		);
		$this->assertIsArray( $saved );
		$this->terms = array_merge( $this->terms, $saved['terms']->post_tag );
		$updated     = $this->save(
			array(
				'post_id'                 => $saved['id'],
				'expected_content_sha256' => $saved['content_sha256'],
				'status'                  => 'publish',
				'content'                 => 'Published hook body',
				'meta'                    => array( $key => 'second' ),
				'terms'                   => array( 'post_tag' => array() ),
				'featured_media'          => 0,
			)
		);
		$this->assertIsArray( $updated );
		$this->assertSame( array( array( 'first', array( $label ), $attachment, false, null ), array( 'second', array(), 0, true, 'draft' ) ), $seen );
		$this->assertNotEmpty( wp_get_post_revisions( $saved['id'] ), 'Deferring the complete-save hook must retain native revisions.' );
	}

	public function test_duplicate_new_term_labels_and_term_exists_races_are_resolved(): void {
		$label = 'duplicate-content-term-' . wp_generate_uuid4();
		$saved = $this->save(
			array(
				'title' => 'Duplicate terms fixture',
				'terms' => array( 'post_tag' => array( $label, $label, strtoupper( $label ) ) ),
			)
		);
		$term  = get_term_by( 'name', $label, 'post_tag' );
		if ( $term ) {
			$this->terms[] = $term->term_id;
		}
		$this->assertIsArray( $saved );
		$this->assertCount( 1, $saved['terms']->post_tag );
	}

	public function test_term_label_deduplication_preserves_numeric_names_distinct_from_ids(): void {
		$term = wp_insert_term( 'Numeric ID fixture ' . wp_generate_uuid4(), 'post_tag' );
		$this->assertIsArray( $term );
		$this->terms[] = $term['term_id'];
		$saved         = $this->save(
			array(
				'title' => 'Numeric term fixture',
				'terms' => array( 'post_tag' => array( (int) $term['term_id'], (string) $term['term_id'] ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->terms = array_merge( $this->terms, $saved['terms']->post_tag );
		$this->assertCount( 2, $saved['terms']->post_tag );
		$this->assertContains( (int) $term['term_id'], $saved['terms']->post_tag );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'name', (string) $term['term_id'], 'post_tag' ) );
	}

	public function test_new_term_labels_preserve_literal_backslashes(): void {
		$label = 'Content-' . wp_generate_uuid4() . '-C:\\Tools';
		$saved = $this->save(
			array(
				'title' => 'Literal term fixture',
				'terms' => array( 'post_tag' => array( $label ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->terms = array_merge( $this->terms, $saved['terms']->post_tag );
		$this->assertSame( array( $label ), wp_get_object_terms( $saved['id'], 'post_tag', array( 'fields' => 'names' ) ) );
	}

	public function test_partial_save_recovery_instructions_survive_the_actual_mcp_adapter(): void {
		require_once SITE_AGENT_DIR . 'runtime/autoload.php';
		$label = 'mcp-failed-content-term-' . wp_generate_uuid4();
		$this->filter(
			'pre_insert_term',
			static function ( $term ) use ( $label ) {
				return $label === wp_unslash( $term ) ? new WP_Error( 'fixture_term_failure', 'Term creation rejected.' ) : $term;
			}
		);
		$tool = McpTool::fromArray(
			array(
				'name'        => 'site-agent-content-regression',
				'handler'     => function ( $input ) {
					return $this->save( $input ); },
				'permission'  => '__return_true',
				'inputSchema' => Abilities::schema( Abilities::definitions()['save-content'] ),
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server   = new McpServer( 'content-regression', 'site-agent-content-regression/v1', 'mcp', 'Content regression', 'Local test only', '1.0.0', array(), null, null, array( $tool ) );
		$schema   = Schemas::create()->forVersion( Schemas::V2025_11_25 );
		$request  = $schema->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'site-agent-content-regression',
					'arguments' => array(
						'title'   => 'MCP partial save fixture',
						'content' => 'Saved draft body',
						'terms'   => array( 'post_tag' => array( $label ) ),
					),
				),
			)
		);
		$response = ( new ToolsHandler( $server ) )->call_tool( $request, new McpRequestContext( $schema, new stdClass(), null, 'test' ) );
		$wire     = json_decode( wp_json_encode( $response ), true );
		$this->assertTrue( $wire['isError'] );
		$this->assertArrayNotHasKey( 'structuredContent', $wire, 'The official adapter exposes only the error message, not WP_Error data.' );
		$this->assertSame( 'text', $wire['content'][0]['type'] );
		$message = $wire['content'][0]['text'];
		$this->assertMatchesRegularExpression( '/post_id ([0-9]+)/', $message );
		preg_match( '/post_id ([0-9]+)/', $message, $matches );
		$id = (int) $matches[1];
		$this->assertContains( $id, $this->posts );
		$this->assertStringContainsString( 'get-content', $message );
		$this->assertStringContainsString( 'before retrying', $message );
		$this->assertSame( 'Saved draft body', get_post( $id )->post_content );
	}

	public function test_partial_save_errors_return_saved_post_identity_and_state_for_safe_retry(): void {
		$label = 'failed-content-term-' . wp_generate_uuid4();
		$this->filter(
			'pre_insert_term',
			static function ( $term ) use ( $label ) {
				return $label === wp_unslash( $term ) ? new WP_Error( 'fixture_term_failure', 'Term creation rejected.' ) : $term;
			}
		);
		$result = $this->save(
			array(
				'title'   => 'Partial save fixture',
				'content' => 'Content already saved',
				'terms'   => array( 'post_tag' => array( $label ) ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'partially_saved', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertNotEmpty( $data['post_id'] ?? null );
		$this->assertSame( $data['post_id'], $data['post']['id'] );
		$this->assertSame( 'Content already saved', $data['post']['content'] );
		$this->assertSame( hash( 'sha256', 'Content already saved' ), $data['post']['content_sha256'] );
		$this->assertSame( array(), $data['post']['terms']->post_tag );
		$this->assertSame( 'fixture_term_failure', $data['cause'] );
	}
}
