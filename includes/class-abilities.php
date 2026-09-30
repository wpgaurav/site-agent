<?php
/**
 * Official Abilities API and MCP server wiring.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Official Abilities API and MCP server wiring. */
final class Abilities {
	public static function register_category(): void {
		wp_register_ability_category(
			'site-agent',
			array(
				'label'       => __( 'Site Agent', 'site-agent' ),
				'description' => __( 'Authenticated WordPress content and developer tools.', 'site-agent' ),
			)
		);
	}

	private static function string( int $max = 1024 ): array {
		return array(
			'type'      => 'string',
			'maxLength' => $max,
		);
	}

	public static function definitions(): array {
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$paging = array(
			'limit'  => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'page'   => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'search' => self::string( 200 ),
		);
		$path   = array( 'path' => self::string() );
		return array(
			'site-context' => array( __( 'Site context', 'site-agent' ), __( 'Inspect WordPress, PHP, active plugins, theme, and available post types.', 'site-agent' ), '', array( Content::class, 'context' ), array(), array(), true ),
			'list-content' => array( __( 'List content', 'site-agent' ), __( 'Search and paginate posts in a REST-enabled post type.', 'site-agent' ), '', array( Content::class, 'listing' ), $paging + array( 'post_type' => self::string( 64 ) ), array(), true ),
			'get-content'  => array( __( 'Read content', 'site-agent' ), __( 'Read raw post content, including Gutenberg markup and the content hash.', 'site-agent' ), '', array( Content::class, 'read' ), array( 'post_id' => $id ), array( 'post_id' ), true ),
			'save-content' => array(
				__( 'Save content', 'site-agent' ),
				__( 'Create a draft or update content through WordPress. Updates require the current content hash. Publishing must be explicitly requested.', 'site-agent' ),
				'content_write',
				array( Content::class, 'save' ),
				array(
					'post_id'                 => $id,
					'post_type'               => self::string( 64 ),
					'title'                   => self::string( 2000 ),
					'content'                 => self::string( 1000000 ),
					'excerpt'                 => self::string( 100000 ),
					'status'                  => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'publish', 'private' ),
					),
					'expected_content_sha256' => self::string( 64 ),
				),
				array(),
				false,
			),
			'list-media'   => array( __( 'List media', 'site-agent' ), __( 'Find existing media library items and their alt text.', 'site-agent' ), '', array( Content::class, 'media' ), $paging, array(), true ),
			'list-files'   => array(
				__( 'List source files', 'site-agent' ),
				__( 'Browse plugin and theme directories. Hidden files, credentials, configuration paths, and symlinks are blocked.', 'site-agent' ),
				'file_read',
				array( Files::class, 'listing' ),
				$path + array(
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
					),
				),
				array( 'path' ),
				true,
			),
			'read-file'    => array( __( 'Read source file', 'site-agent' ), __( 'Read a UTF-8 plugin or theme source file up to 256 KiB and obtain its SHA-256 hash.', 'site-agent' ), 'file_read', array( Files::class, 'read' ), $path, array( 'path' ), true ),
			'write-file'   => array(
				__( 'Write source file', 'site-agent' ),
				__( 'Write a plugin or theme source file. Supply its current hash, or new for a new file. PHP syntax is checked first. This can change executable code.', 'site-agent' ),
				'file_write',
				array( Files::class, 'write' ),
				$path + array(
					'content'         => self::string( Files::MAX_BYTES ),
					'expected_sha256' => array(
						'type'    => 'string',
						'pattern' => '^(new|[a-f0-9]{64})$',
					),
				),
				array( 'path', 'content', 'expected_sha256' ),
				false,
			),
			'execute-php'  => array( __( 'Execute PHP', 'site-agent' ), __( 'Run PHP statements in the loaded WordPress request. Full server privileges; not sandboxed. No PHP tags. Echo output and a JSON return value are captured.', 'site-agent' ), 'php_execute', array( Developer::class, 'php' ), array( 'code' => self::string( 65536 ) ), array( 'code' ), false ),
			'run-wp-cli'   => array(
				__( 'Run WP-CLI', 'site-agent' ),
				__( 'Run WP-CLI with an argument array on this installation and as the authenticated user. Full developer access; foreground commands have a 20-second limit.', 'site-agent' ),
				'cli_execute',
				array( Developer::class, 'cli' ),
				array(
					'arguments' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 40,
						'items'    => self::string( 4096 ),
					),
				),
				array( 'arguments' ),
				false,
			),
		);
	}

	public static function enabled_definitions(): array {
		$config = Config::get();
		if ( ! $config['enabled'] || Config::locked() ) {
			return array();
		}
		return array_filter(
			self::definitions(),
			static function ( $definition ) use ( $config ) {
				return '' === $definition[2] || ( ! empty( $config[ $definition[2] ] ) && ( ! Config::code_locked() || 'content_write' === $definition[2] ) );
			}
		);
	}

	public static function schema( array $definition ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $definition[4],
			'required'             => $definition[5],
			'additionalProperties' => false,
		);
	}

	public static function register(): void {
		foreach ( self::enabled_definitions() as $name => $definition ) {
			wp_register_ability(
				'site-agent/' . $name,
				array(
					'label'               => $definition[0],
					'description'         => $definition[1],
					'category'            => 'site-agent',
					'input_schema'        => self::schema( $definition ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => static function () use ( $definition ) {
						return Permissions::allowed( $definition[2] ); },
					'execute_callback'    => static function ( $input = null ) use ( $name ) {
						return self::execute( $name, $input ?? array() ); },
					'meta'                => array(
						'public'       => false,
						'show_in_rest' => false,
						'mcp'          => array(
							'public' => false,
							'type'   => 'tool',
						),
						'annotations'  => array(
							'readonly'    => $definition[6],
							'destructive' => ! $definition[6],
							'idempotent'  => $definition[6],
						),
					),
				)
			);
		}
	}

	public static function register_server( $adapter ): void {
		$tools = array_map(
			static function ( $name ) {
				return 'site-agent/' . $name;
			},
			array_keys( self::enabled_definitions() )
		);
		if ( ! $tools ) {
			return;
		}
		$adapter->create_server(
			'site-agent',
			'site-agent/v1',
			'mcp',
			'Site Agent',
			'WordPress developer access through explicitly enabled tools.',
			SITE_AGENT_VERSION,
			array( Vendor\WP\MCP\Transport\HttpTransport::class ),
			Vendor\WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler::class,
			Vendor\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			array(),
			array(),
			array( Permissions::class, 'transport' )
		);
	}

	/** Recheck authorization and schemas even for direct PHP callers. */
	public static function execute( string $name, $input ) {
		$start       = microtime( true );
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $name ] ) || ! Permissions::allowed( $definitions[ $name ][2] ) ) {
			Audit::record( $name, false, $start );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		$definition = $definitions[ $name ];
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'invalid_input', __( 'Tool arguments must be an object.', 'site-agent' ) );
		}
		$validation = rest_validate_value_from_schema( $input, self::schema( $definition ), 'arguments' );
		if ( is_wp_error( $validation ) ) {
			Audit::record( $name, false, $start );
			return $validation;
		}
		try {
			$result = call_user_func( $definition[3], $input );
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'site_agent_tool_failed', __( 'The tool raised an error. Check the site before retrying a write.', 'site-agent' ) );
		}
		$success = ! is_wp_error( $result ) && ( ! isset( $result['exit_code'] ) || 0 === $result['exit_code'] );
		Audit::record( $name, $success, $start );
		return $result;
	}
}
