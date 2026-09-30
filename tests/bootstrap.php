<?php
// Tests may mutate only an explicitly marked disposable installation.
$wordpress = getenv( 'SITE_AGENT_WP_DIR' );
if ( ! $wordpress || ! is_file( $wordpress . '/.site-agent-test-install' ) ) {
	throw new RuntimeException( 'Set SITE_AGENT_WP_DIR to a disposable WordPress installation marked with .site-agent-test-install.' );
}
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost:8943';
require $wordpress . '/wp-load.php';
if ( ! class_exists( SiteAgent\Config::class ) ) {
	require dirname( __DIR__ ) . '/site-agent.php';
}
