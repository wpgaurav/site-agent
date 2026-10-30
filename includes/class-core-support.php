<?php
/**
 * Core Support's own abilities, served through Site Agent's MCP server.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Core Support registers its abilities with the WordPress Abilities API under core-support/: checking
 * for new tickets, customer replies and feature requests, reading tickets, sending team alerts and
 * working the roadmap. Site Agent serves the ones listed here as direct tools (core-support-*)
 * behind the Core Support tools group, per-password limits and the audit history. Core Support
 * keeps its own capability checks.
 *
 * The list is fixed, so a later Core Support ability, or one another plugin registers under the
 * same prefix, never reaches a connection that approved this group for what it does today. None of
 * them replies to customers.
 */
final class Core_Support {
	const PREFIX = 'core-support/';
	/** Core Support's ability registration, present from the version that has abilities. */
	const ABILITIES = 'Gatilab\CoreSupport\Abilities\Abilities';
	/** The abilities served, all in Core Support's own category. */
	const SERVED = array(
		'core-support/get-activity',
		'core-support/list-tickets',
		'core-support/get-ticket',
		'core-support/notify-team',
		'core-support/get-roadmap',
		'core-support/add-roadmap-item',
		'core-support/update-roadmap-item',
	);
	/** Read-only abilities an assistant may poll; their successful calls stay out of the audit history. */
	const QUIET    = array( 'core-support/get-activity', 'core-support/list-tickets', 'core-support/get-ticket', 'core-support/get-roadmap' );
	const CATEGORY = 'core-support';

	/**
	 * Start times of tool calls in this request, keyed by tool name.
	 *
	 * @var array<string, float>
	 */
	private static $started = array();

	/** Core Support is active and registers abilities. */
	public static function available(): bool {
		return function_exists( 'wp_get_abilities' ) && class_exists( self::ABILITIES );
	}

	/**
	 * The served Core Support abilities registered on this site, in Core Support's category, sorted by name.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		if ( ! self::available() ) {
			return array();
		}
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( in_array( $ability->get_name(), self::SERVED, true ) && self::CATEGORY === $ability->get_category() ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );
		return $names;
	}

	/** Whether a tool on Site Agent's server is one of Core Support's. */
	private static function is_tool( string $tool_name, $server ): bool {
		return is_object( $server ) && method_exists( $server, 'get_server_id' ) && 'site-agent' === $server->get_server_id()
			&& 0 === strpos( $tool_name, 'core-support-' );
	}

	/** The ability behind a tool name, as the adapter derives tool names from ability names. */
	private static function ability_for_tool( string $tool_name ): string {
		return self::PREFIX . substr( $tool_name, strlen( 'core-support-' ) );
	}

	/**
	 * Enforce the Core Support tools group and per-password limits before a Core Support tool runs.
	 *
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function before_call( $args, $tool_name, $tool, $server ) {
		if ( ! is_string( $tool_name ) || ! self::is_tool( $tool_name, $server ) ) {
			return $args;
		}
		$start = microtime( true );
		if ( ! Permissions::allowed( 'core_support' ) ) {
			Audit::record( self::ability_for_tool( $tool_name ), false, $start, array( 'error' => 'denied' ) );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		self::$started[ $tool_name ] = $start;
		return $args;
	}

	/**
	 * Record Core Support tool calls in the audit history.
	 *
	 * @param mixed  $result    Tool result.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function after_call( $result, $args, $tool_name, $tool, $server ) {
		if ( ! is_string( $tool_name ) || ! self::is_tool( $tool_name, $server ) || ! isset( self::$started[ $tool_name ] ) ) {
			return $result;
		}
		$ability = self::ability_for_tool( $tool_name );
		$started = self::$started[ $tool_name ];
		unset( self::$started[ $tool_name ] );
		// A watch polls every few minutes; recording each check would push everything else out of the history.
		if ( ! is_wp_error( $result ) && in_array( $ability, self::QUIET, true ) ) {
			return $result;
		}
		$details = array(
			'target' => isset( $args['id'] ) && is_scalar( $args['id'] ) ? '#' . (int) $args['id'] : '',
			'error'  => is_wp_error( $result ) ? $result->get_error_code() : '',
		);
		Audit::record( $ability, ! is_wp_error( $result ), $started, $details );
		return $result;
	}
}
