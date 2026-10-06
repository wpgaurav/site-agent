<?php
/**
 * Plugin Name: Site Agent
 * Plugin URI: https://gauravtiwari.org/product/site-agent/
 * Description: Connect an MCP client directly to WordPress with independently enabled developer tools.
 * Version: 0.3.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Gaurav Tiwari
 * Author URI: https://gauravtiwari.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-agent
 * Domain Path: /languages
 * Update URI: https://gauravtiwari.org/product/site-agent/
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

define( 'SITE_AGENT_VERSION', '0.3.0' );
define( 'SITE_AGENT_DIR', __DIR__ . '/' );
define( 'SITE_AGENT_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class_name ) {
		if ( strpos( $class_name, 'SiteAgent\\' ) !== 0 || strpos( $class_name, 'SiteAgent\\Vendor\\' ) === 0 ) {
			return;
		}
		$name = substr( $class_name, strlen( 'SiteAgent\\' ) );
		if ( strpos( $name, '\\' ) !== false ) {
			return;
		}
		$file = SITE_AGENT_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Admin::init();
		Updater::init();
		// Early, so later rest_pre_dispatch callbacks and loggers never see the URL credential.
		add_filter( 'rest_pre_dispatch', array( Url_Auth::class, 'capture' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( Url_Auth::class, 'response' ), 10, 3 );
		add_action( 'wp_delete_application_password', array( Scopes::class, 'forget' ), 10, 2 );
		if ( ! Config::get()['enabled'] || Config::locked() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$runtime = SITE_AGENT_DIR . 'runtime/autoload.php';
		if ( ! is_readable( $runtime ) ) {
			return;
		}
		require_once $runtime;
		// Keep this private, scoped adapter independent of other MCP plugins.
		add_filter( 'site_agent_mcp_adapter_create_default_server', '__return_false' );
		add_filter( 'site_agent_mcp_adapter_tools_list', array( Abilities::class, 'visible_tools' ), 10, 2 );
		// Bricks' direct tools on this server: the Bricks tools group, password limits and audit.
		add_filter( 'site_agent_mcp_adapter_pre_tool_call', array( Bricks::class, 'before_call' ), 10, 4 );
		add_filter( 'site_agent_mcp_adapter_tool_call_result', array( Bricks::class, 'after_call' ), 10, 5 );
		Vendor\WP\MCP\Plugin::instance();
		add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
		add_action( 'site_agent_mcp_adapter_init', array( Abilities::class, 'register_server' ) );
	},
	20
);

register_deactivation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		$disable = static function () {
			if ( false === get_option( Config::OPTION ) ) {
				return;
			}
			$config            = Config::get();
			$config['enabled'] = false;
			update_option( Config::OPTION, $config, true );
		};
		if ( ! is_multisite() || ! $network_wide ) {
			$disable();
			return;
		}
		// Network deactivation must not leave subsites enabled for a later reactivation.
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$disable();
			restore_current_blog();
		}
	}
);
