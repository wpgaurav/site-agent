<?php
/**
 * Build an isolated runtime from the official WordPress adapter and schema.
 * Original licenses and copyright notices remain in each shipped file.
 * No source from another WordPress MCP plugin is an input to this build.
 */

$root = dirname( __DIR__ );
$lock = json_decode( file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
$packages = array_column( $lock['packages'], null, 'name' );
$output = $root . '/runtime';
$mapping = array(
	'wordpress/mcp-adapter' => array( 'includes', 'WP/MCP' ),
	'wordpress/php-mcp-schema' => array( 'src', 'WP/McpSchema' ),
);

// Namespace and adapter-owned hook/constant rewrites; WordPress APIs stay native.
$rewrites = array(
	'WP\\McpSchema' => 'SiteAgent\\Vendor\\WP\\McpSchema',
	'WP\\MCP' => 'SiteAgent\\Vendor\\WP\\MCP',
	'WP\\\\McpSchema' => 'SiteAgent\\\\Vendor\\\\WP\\\\McpSchema',
	'WP\\\\MCP' => 'SiteAgent\\\\Vendor\\\\WP\\\\MCP',
	'mcp_adapter_' => 'site_agent_mcp_adapter_',
	'wp_mcp_init' => 'site_agent_wp_mcp_init',
	'WP_MCP_' => 'SITE_AGENT_MCP_',
);

if ( is_dir( $output ) ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $output, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
} else {
	mkdir( $output, 0755, true );
}

$manifest = array();
foreach ( $mapping as $package => $paths ) {
	$source = $root . '/vendor/' . $package;
	if ( ! is_dir( $source . '/' . $paths[0] ) ) {
		throw new RuntimeException( 'Missing runtime source: ' . $package );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . '/' . $paths[0], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $entry ) {
		if ( 'php' !== $entry->getExtension() ) {
			continue;
		}
		$relative = substr( $entry->getPathname(), strlen( $source . '/' . $paths[0] . '/' ) );
		$target = $output . '/' . $paths[1] . '/' . $relative;
		if ( ! is_dir( dirname( $target ) ) ) {
			mkdir( dirname( $target ), 0755, true );
		}
		$code = strtr( file_get_contents( $entry->getPathname() ), $rewrites );
		// Give the bundled adapter a distinct CLI command as well as distinct hooks.
		if ( $package === 'wordpress/mcp-adapter' && 'Core/McpAdapter.php' === $relative ) {
			$code = str_replace( "'mcp-adapter',\n\t\t\tMcpCommand::class", "'site-agent-adapter',\n\t\t\tMcpCommand::class", $code );
		}
		file_put_contents( $target, $code );
	}
	$license = glob( $source . '/LICENSE*' );
	if ( ! $license ) {
		throw new RuntimeException( 'Missing upstream license: ' . $package );
	}
	copy( $license[0], $output . '/' . str_replace( '/', '-', $package ) . '-LICENSE' );
	$manifest[ $package ] = array( 'version' => $packages[ $package ]['version'], 'commit' => $packages[ $package ]['source']['reference'], 'source' => $packages[ $package ]['source']['url'], 'license' => $packages[ $package ]['license'] );
}

file_put_contents( $output . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $output . '/autoload.php', <<<'PHP'
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
PHP
);
echo "Built isolated official WordPress MCP runtime.\n";
