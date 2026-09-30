<?php
/**
 * Plugin Name: Site Agent
 * Plugin URI: https://gauravtiwari.org/product/site-agent/
 * Description: Connect an MCP client directly to WordPress with independently enabled developer tools.
 * Version: 0.1.2
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

define( 'SITE_AGENT_VERSION', '0.1.2' );
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
		Vendor\WP\MCP\Plugin::instance();
		add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );
		add_action( 'site_agent_mcp_adapter_init', array( Abilities::class, 'register_server' ) );
	},
	20
);

register_deactivation_hook(
	__FILE__,
	static function () {
		$config            = Config::get();
		$config['enabled'] = false;
		update_option( Config::OPTION, $config, false );
	}
);
