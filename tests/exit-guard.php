<?php
// Runs PHP that ends the request (exit or a fatal error) in a separate process and prints the audit result.
$root = getenv( 'SITE_AGENT_WP_DIR' );
if ( ! $root || ! is_file( $root . '/.site-agent-test-install' ) || empty( $argv[1] ) ) {
	exit( 1 );
}
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost:8943';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $root . '/wp-load.php';
wp_set_current_user( 1 );
$saved = SiteAgent\Config::get();
update_option( SiteAgent\Config::OPTION, array_merge( SiteAgent\Config::defaults(), array( 'enabled' => true, 'php_execute' => true ) ), false );
delete_option( SiteAgent\Audit::OPTION );
register_shutdown_function(
	static function () use ( $saved ) {
		// Registered during shutdown, so it runs after Site Agent's own shutdown handler.
		register_shutdown_function(
			static function () use ( $saved ) {
				$rows = (array) get_option( SiteAgent\Audit::OPTION, array() );
				$last = end( $rows );
				update_option( SiteAgent\Config::OPTION, $saved, false );
				echo 'AUDIT=' . ( is_array( $last ) ? $last['error'] : 'none' );
			}
		);
	}
);
SiteAgent\Abilities::execute( 'execute-php', array( 'code' => base64_decode( $argv[1] ) ) );
