<?php
/**
 * Bricks Builder's own abilities, served through Site Agent's MCP server.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks 2.4+ registers its builder abilities with the WordPress Abilities API and adds a few of
 * them to the official MCP Adapter's default server. Site Agent serves the same abilities on its own
 * server, behind the Bricks tools group, per-password limits and the audit history. Bricks keeps its
 * own switches, deny-list and permission checks.
 */
final class Bricks {
	const MANAGER = 'Bricks\Abilities\Manager';
	const PREFIX  = 'bricks/';
	const NAME    = '^bricks/[a-z0-9]+(-[a-z0-9]+)*$';
	/** Orientation guide, served as a direct tool beside Bricks' own fast path. */
	const GUIDE = 'bricks/start-here';
	/** Bricks abilities that run PHP, so they also need Site Agent's PHP execution group. */
	const CODE_ABILITIES = array( 'bricks/execute-php' );

	/**
	 * Start times of direct Bricks tool calls in this request, keyed by tool name.
	 *
	 * @var array<string, float>
	 */
	private static $started = array();

	/** Bricks has registered its abilities: Bricks 2.4+ with its AI/MCP switch on. */
	public static function available(): bool {
		$manager = self::MANAGER;
		return function_exists( 'wp_has_ability' ) && class_exists( $manager ) && method_exists( $manager, 'is_enabled' ) && $manager::is_enabled();
	}

	private static function disabled( string $name ): bool {
		$manager = self::MANAGER;
		return class_exists( $manager ) && method_exists( $manager, 'is_ability_disabled' ) && $manager::is_ability_disabled( $name );
	}

	/** Whether a Bricks ability is registered and not switched off in Bricks > Settings > AI. */
	private static function usable( $name ): bool {
		return is_string( $name ) && 0 === strpos( $name, self::PREFIX ) && wp_has_ability( $name ) && ! self::disabled( $name );
	}

	/**
	 * Every usable Bricks ability, sorted by name.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		if ( ! self::available() ) {
			return array();
		}
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( self::usable( $ability->get_name() ) ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Abilities served as direct MCP tools: Bricks' own fast path (which honors the
	 * `bricks/abilities/named_tools` filter) plus the start-here guide. Code abilities stay
	 * behind run-bricks-ability, where the PHP execution group is checked.
	 *
	 * @return string[]
	 */
	public static function direct(): array {
		if ( ! self::available() ) {
			return array();
		}
		$manager = self::MANAGER;
		$names   = method_exists( $manager, 'get_named_tool_abilities' ) ? (array) $manager::get_named_tool_abilities() : array();
		$names[] = self::GUIDE;
		return array_values(
			array_filter(
				array_unique( $names ),
				static function ( $name ) {
					return self::usable( $name ) && ! in_array( $name, self::CODE_ABILITIES, true );
				}
			)
		);
	}

	/** MCP tool name for an ability, as the adapter derives it. */
	public static function tool_name( string $ability ): string {
		return str_replace( '/', '-', $ability );
	}

	public static function version(): string {
		return defined( 'BRICKS_VERSION' ) ? (string) BRICKS_VERSION : '';
	}

	/** First sentence of a description, for compact listings. */
	private static function summary( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return preg_match( '/^(.+?[.!?])(\s|$)/', $text, $match ) ? $match[1] : $text;
	}

	/** Readonly, destructive and idempotent hints from the ability's metadata. */
	private static function annotations( \WP_Ability $ability ): array {
		$meta  = $ability->get_meta();
		$hints = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return array(
			'readonly'    => ! empty( $hints['readonly'] ),
			'destructive' => ! empty( $hints['destructive'] ),
		);
	}

	/**
	 * Load a usable ability or explain why it cannot run here.
	 *
	 * @return \WP_Ability|\WP_Error
	 */
	private static function ability( string $name ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'bricks_unavailable', __( 'Bricks abilities are not active on this site. They need Bricks 2.4 or later with AI/MCP enabled in Bricks > Settings > AI.', 'site-agent' ) );
		}
		if ( ! self::usable( $name ) ) {
			/* translators: %s: ability name. */
			return new \WP_Error( 'bricks_ability_unavailable', sprintf( __( '%s is not a registered Bricks ability, or it is disabled in Bricks > Settings > AI. Call bricks-abilities for the list.', 'site-agent' ), $name ) );
		}
		return wp_get_ability( $name );
	}

	public static function listing( array $input ) {
		if ( isset( $input['ability_name'] ) ) {
			$ability = self::ability( $input['ability_name'] );
			if ( is_wp_error( $ability ) ) {
				return $ability;
			}
			$name   = $ability->get_name();
			$direct = in_array( $name, self::direct(), true );
			return array(
				'version'   => self::version(),
				'abilities' => array(
					array(
						'name'          => $name,
						'label'         => $ability->get_label(),
						'description'   => $ability->get_description(),
						'direct_tool'   => $direct ? self::tool_name( $name ) : '',
						'input_schema'  => (object) $ability->get_input_schema(),
						'output_schema' => (object) $ability->get_output_schema(),
					) + self::annotations( $ability ),
				),
			);
		}
		if ( ! self::available() ) {
			return self::ability( '' );
		}
		$search    = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$direct    = self::direct();
		$abilities = array();
		foreach ( self::names() as $name ) {
			$ability = wp_get_ability( $name );
			$text    = $ability->get_description();
			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $ability->get_label() . ' ' . $text ), $search ) ) {
				continue;
			}
			$abilities[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => self::summary( $text ),
				'direct_tool' => in_array( $name, $direct, true ) ? self::tool_name( $name ) : '',
			) + self::annotations( $ability );
		}
		return array(
			'version'   => self::version(),
			'abilities' => $abilities,
		);
	}

	public static function run( array $input ) {
		$ability = self::ability( $input['ability_name'] );
		if ( is_wp_error( $ability ) ) {
			return $ability;
		}
		$name = $ability->get_name();
		if ( in_array( $name, self::CODE_ABILITIES, true ) && ! Permissions::allowed( 'php_execute' ) ) {
			/* translators: %s: ability name. */
			return new \WP_Error( 'bricks_code_ability', sprintf( __( '%s runs PHP, so it also needs PHP execution enabled for this connection in Site Agent.', 'site-agent' ), $name ) );
		}
		$parameters = $ability->normalize_input( $input['parameters'] ?? null );
		$valid      = $ability->validate_input( $parameters );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		// execute() repeats this check but replaces Bricks' reason with a generic message.
		$permission = $ability->check_permissions( $parameters );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$result = $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ability_name' => $name,
			'result'       => $result,
		);
	}

	/** Whether a tool on Site Agent's server is one of Bricks' direct tools. */
	private static function is_direct_tool( string $tool_name, $server ): bool {
		return is_object( $server ) && method_exists( $server, 'get_server_id' ) && 'site-agent' === $server->get_server_id()
			&& 0 === strpos( $tool_name, 'bricks-' );
	}

	/** The ability behind one of Bricks' direct tools on Site Agent's server. */
	private static function ability_for_tool( string $tool_name ): string {
		foreach ( self::direct() as $name ) {
			if ( self::tool_name( $name ) === $tool_name ) {
				return $name;
			}
		}
		return $tool_name;
	}

	/**
	 * Enforce the Bricks tools group and per-password limits on Bricks' direct tools. Bricks has
	 * already checked its own permission by the time this filter runs.
	 *
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function before_call( $args, $tool_name, $tool, $server ) {
		if ( ! is_string( $tool_name ) || ! self::is_direct_tool( $tool_name, $server ) ) {
			return $args;
		}
		$start = microtime( true );
		if ( ! Permissions::allowed( 'bricks' ) ) {
			Audit::record( self::ability_for_tool( $tool_name ), false, $start, array( 'error' => 'denied' ) );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		self::$started[ $tool_name ] = $start;
		return $args;
	}

	/**
	 * Record Bricks' direct tool calls in the audit history.
	 *
	 * @param mixed  $result    Tool result.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function after_call( $result, $args, $tool_name, $tool, $server ) {
		if ( ! is_string( $tool_name ) || ! self::is_direct_tool( $tool_name, $server ) || ! isset( self::$started[ $tool_name ] ) ) {
			return $result;
		}
		$details = array(
			'target' => Audit::target( is_array( $args ) ? $args : array() ),
			'error'  => is_wp_error( $result ) ? $result->get_error_code() : '',
		);
		Audit::record( self::ability_for_tool( $tool_name ), ! is_wp_error( $result ), self::$started[ $tool_name ], $details );
		unset( self::$started[ $tool_name ] );
		return $result;
	}
}
