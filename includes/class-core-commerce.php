<?php
/**
 * Core Commerce's own abilities, served through Site Agent's MCP server.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Core Commerce, the commerce part of Core Forms, registers its abilities under core-forms/ in the
 * core-forms-commerce category: the licensing catalog, plans, releases, orders, licenses, activations,
 * subscriptions and payment routing (with the Licensing module), and client invoices (with the Client
 * Invoices module). Site Agent serves the ones listed here as direct tools (core-forms-*) behind the
 * Core Commerce tools group, per-password limits and the audit history. Core Commerce keeps its own
 * capability checks, and each ability says when to ask the merchant first.
 *
 * The list is fixed, so a later ability, or one another plugin registers under the same prefix,
 * never reaches a connection that approved this group for what it does today.
 */
final class Core_Commerce {
	const PREFIX   = 'core-forms/';
	const CATEGORY = 'core-forms-commerce';
	/** The abilities served, all in Core Commerce's own category. */
	const SERVED = array(
		// Licensing and Storefront.
		'core-forms/get-commerce-summary',
		'core-forms/list-products',
		'core-forms/get-product',
		'core-forms/save-product',
		'core-forms/delete-product',
		'core-forms/list-plans',
		'core-forms/save-plan',
		'core-forms/list-releases',
		'core-forms/publish-release',
		'core-forms/list-orders',
		'core-forms/get-order',
		'core-forms/list-licenses',
		'core-forms/get-license',
		'core-forms/update-license',
		'core-forms/reset-license-activations',
		'core-forms/list-activations',
		'core-forms/deactivate-activation',
		'core-forms/list-subscriptions',
		'core-forms/update-subscription',
		'core-forms/list-downloads',
		'core-forms/list-events',
		'core-forms/generate-product-forms',
		'core-forms/generate-sample-products',
		'core-forms/get-currency-routes',
		'core-forms/update-currency-routes',
		'core-forms/get-gateway-status',
		'core-forms/resend-customer-access',
		// Client Invoices.
		'core-forms/client-invoice-context',
		'core-forms/client-invoice-lookup',
		'core-forms/list-client-invoices',
		'core-forms/get-client-invoice',
		'core-forms/create-client-invoice',
		'core-forms/update-client-invoice',
		'core-forms/issue-client-invoice',
		'core-forms/send-client-invoice',
		'core-forms/duplicate-client-invoice',
		'core-forms/reopen-client-invoice',
		'core-forms/void-client-invoice',
		'core-forms/mark-client-invoice-paid',
		'core-forms/schedule-client-invoice',
		'core-forms/unschedule-client-invoice',
		'core-forms/list-client-invoice-schedules',
	);

	/**
	 * Start times of tool calls in this request, keyed by tool name.
	 *
	 * @var array<string, float>
	 */
	private static $started = array();

	/** Core Forms is active with a commerce module that registers abilities. */
	public static function available(): bool {
		return function_exists( 'wp_get_abilities' )
			&& ( class_exists( '\Core_Forms\Licensing\LicenseAbilities' ) || class_exists( '\Core_Forms\Commerce\ClientInvoiceApi' ) );
	}

	/**
	 * The served abilities registered on this site, in Core Commerce's category, sorted by name.
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

	/** Whether a tool on Site Agent's server is one of Core Commerce's. */
	private static function is_tool( string $tool_name, $server ): bool {
		return is_object( $server ) && method_exists( $server, 'get_server_id' ) && 'site-agent' === $server->get_server_id()
			&& 0 === strpos( $tool_name, 'core-forms-' );
	}

	/** The ability behind a tool name, as the adapter derives tool names from ability names. */
	private static function ability_for_tool( string $tool_name ): string {
		return self::PREFIX . substr( $tool_name, strlen( 'core-forms-' ) );
	}

	/**
	 * Enforce the Core Commerce tools group and per-password limits before a Core Commerce tool runs.
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
		if ( ! Permissions::allowed( 'core_commerce' ) ) {
			Audit::record( self::ability_for_tool( $tool_name ), false, $start, array( 'error' => 'denied' ) );
			return new \WP_Error( 'site_agent_forbidden', __( 'This tool is disabled or you do not have permission to use it.', 'site-agent' ) );
		}
		self::$started[ $tool_name ] = $start;
		return $args;
	}

	/**
	 * Record Core Commerce tool calls in the audit history, with the record ID as the target.
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
		unset( self::$started[ $tool_name ] );
		$target = '';
		foreach ( array( 'id', 'license_id', 'product_id', 'activation_id', 'invoice_id' ) as $key ) {
			if ( is_array( $args ) && isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$target = '#' . (int) $args[ $key ];
				break;
			}
		}
		Audit::record(
			self::ability_for_tool( $tool_name ),
			! is_wp_error( $result ),
			$started,
			array(
				'target' => $target,
				'error'  => is_wp_error( $result ) ? $result->get_error_code() : '',
			)
		);
		return $result;
	}
}
