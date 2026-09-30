<?php
/** Scoped official WordPress MCP runtime. See manifest.json and bundled licenses. */
defined( 'ABSPATH' ) || exit;
spl_autoload_register(
    static function ( $class ) {
        $prefix = 'SiteAgent\\Vendor\\';
        if ( strpos( $class, $prefix ) !== 0 ) {
            return;
        }
        $file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);