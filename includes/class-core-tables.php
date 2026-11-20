<?php
/**
 * Core Tables' own abilities, served through Site Agent's MCP server.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Core Tables registers its abilities under core-tables/: finding, reading, creating, editing,
 * publishing, embedding, exporting and importing tables, and with Core Tables 1.3 managing their
 * revisions, trash, refresh sources and appearance defaults. Site Agent serves the ones listed here
 * as direct tools (core-tables-*) behind the Core Tables tools group, per-password limits and the
 * audit history. Core Tables keeps its own capability and ownership checks, and its own AI settings
 * still decide exposure: only abilities it marks public for MCP (read, and write for changes) are
 * served.
 *
 * The list is fixed, so a later ability, or one another plugin registers under the same prefix,
 * never reaches a connection that approved this group for what it does today. Names that the
 * installed Core Tables does not register are simply not served.
 */
final class Core_Tables {
	const PREFIX   = 'core-tables/';
	const CATEGORY = 'core-tables';
	/** Core Tables' ability registration. */
	const ABILITIES = 'Gatilab\CoreTables\AI\Abilities';
	/** The abilities served, all in Core Tables' own category. */
	const SERVED = array(
		// Core Tables 1.1 and later.
		'core-tables/list-published',
		'core-tables/query-published',
		'core-tables/get-draft',
		'core-tables/create-draft',
		'core-tables/save-draft',
		'core-tables/publish',
		'core-tables/create-table',
		'core-tables/export',
		'core-tables/embed-code',
		'core-tables/list-import-sources',
		'core-tables/import-from-plugin',
		// Table management.
		'core-tables/list-tables',
		'core-tables/update-table',
		'core-tables/unpublish',
		'core-tables/trash',
		'core-tables/restore',
		'core-tables/delete-permanently',
		'core-tables/list-revisions',
		'core-tables/get-revision',
		'core-tables/restore-revision',
		'core-tables/list-activity',
		// Refresh sources: Google Sheets and custom fields.
		'core-tables/get-source',
		'core-tables/preview-source',
		'core-tables/configure-source',
		'core-tables/refresh-source',
		'core-tables/pause-source',
		'core-tables/disconnect-source',
		// Site appearance defaults.
		'core-tables/get-appearance',
		'core-tables/update-appearance',
	);

	/**
	 * Start times of tool calls in this request, keyed by tool name.
	 *
	 * @var array<string, float>
	 */
	private static $started = array();

	/** Core Tables is active and registers abilities. */
	public static function available(): bool {
		return function_exists( 'wp_get_abilities' ) && class_exists( self::ABILITIES );
	}

	/**
	 * The served abilities registered on this site, in Core Tables' category and public for MCP, sorted by name.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		if ( ! self::available() ) {
			return array();
		}
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$meta = $ability->get_meta();
			if ( in_array( $ability->get_name(), self::SERVED, true ) && self::CATEGORY === $ability->get_category() && ! empty( $meta['mcp']['public'] ) ) {
				$names[] = $ability->get_name();
			}
		}
		sort( $names );
		return $names;
	}

	/** Whether a tool on Site Agent's server is one of Core Tables'. */
	private static function is_tool( string $tool_name, $server ): bool {
		return is_object( $server ) && method_exists( $server, 'get_server_id' ) && 'site-agent' === $server->get_server_id()
			&& 0 === strpos( $tool_name, 'core-tables-' );
	}

	/** The ability behind a tool name, as the adapter derives tool names from ability names. */
	private static function ability_for_tool( string $tool_name ): string {
		return self::PREFIX . substr( $tool_name, strlen( 'core-tables-' ) );
	}

	/**
	 * Enforce the Core Tables tools group and per-password limits before a Core Tables tool runs.
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
		if ( ! Permissions::allowed( 'core_tables' ) ) {
			Audit::record( self::ability_for_tool( $tool_name ), false, $start, array( 'error' => 'denied' ) );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		self::$started[ $tool_name ] = $start;
		return $args;
	}

	/**
	 * Record Core Tables tool calls in the audit history, with the dataset ID as the target.
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
		$started = self::$started[ $tool_name ];
		$ability = self::ability_for_tool( $tool_name );
		unset( self::$started[ $tool_name ] );
		// An import's id names the other plugin's table, not a Core Tables dataset.
		$dataset = 'core-tables/import-from-plugin' !== $ability && is_array( $args ) && isset( $args['id'] ) && is_scalar( $args['id'] );
		Audit::record(
			$ability,
			! is_wp_error( $result ),
			$started,
			array(
				'target' => $dataset ? 'table ' . (int) $args['id'] : '',
				'error'  => is_wp_error( $result ) ? $result->get_error_code() : '',
			)
		);
		return $result;
	}
}
