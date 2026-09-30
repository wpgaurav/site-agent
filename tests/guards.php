<?php
$root = getenv( 'SITE_AGENT_WP_DIR' );
if ( ! $root || ! is_file( $root . '/.site-agent-test-install' ) ) {
	exit( 1 );
}
$flag = $argv[1] ?? '';
if ( ! in_array( $flag, array( 'SITE_AGENT_DISABLED', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ), true ) ) {
	exit( 1 );
}
define( $flag, true );
require $root . '/wp-load.php';
wp_set_current_user( 1 );
$saved = SiteAgent\Config::get();
update_option( SiteAgent\Config::OPTION, array_fill_keys( array_keys( SiteAgent\Config::defaults() ), true ), false );
$result = array();
foreach ( array( '', 'content_write', 'file_read', 'file_write', 'php_execute', 'cli_execute' ) as $group ) {
	$result[ $group ?: 'read' ] = SiteAgent\Permissions::allowed( $group );
}
update_option( SiteAgent\Config::OPTION, $saved, false );
echo json_encode( $result );
