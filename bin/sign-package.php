<?php
/**
 * Sign a staged Site Agent package directory.
 *
 * Usage: SITE_AGENT_SIGNING_KEY=<base64 secret key> php bin/sign-package.php <directory> <version> [expected public key]
 *
 * Writes signature.json: the SHA-256 of every file and an Ed25519 signature over the message
 * built by site_agent_package_message(), which must match Updater::package_message().
 */

function site_agent_package_files( string $directory ): array {
	$files = array();
	$root  = strlen( rtrim( $directory, '/' ) . '/' );
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $items as $item ) {
		if ( $item->isLink() ) {
			throw new RuntimeException( 'Packages must not contain symlinks: ' . $item->getPathname() );
		}
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), $root ) );
		if ( $item->isFile() && 'signature.json' !== $relative ) {
			$files[ $relative ] = hash_file( 'sha256', $item->getPathname() );
		}
	}
	ksort( $files, SORT_STRING );
	return $files;
}

function site_agent_package_message( string $version, array $files ): string {
	ksort( $files, SORT_STRING );
	$lines = array( 'site-agent-package-v1', $version );
	foreach ( $files as $path => $hash ) {
		$lines[] = $hash . '  ' . $path;
	}
	return implode( "\n", $lines ) . "\n";
}

function site_agent_sign_package( string $directory, string $version, string $secret_key ): array {
	$files    = site_agent_package_files( $directory );
	$manifest = array(
		'format'    => 1,
		'version'   => $version,
		'files'     => $files,
		'signature' => base64_encode( sodium_crypto_sign_detached( site_agent_package_message( $version, $files ), $secret_key ) ),
	);
	file_put_contents( rtrim( $directory, '/' ) . '/signature.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	return $manifest;
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$directory = $argv[1] ?? '';
	$version   = $argv[2] ?? '';
	$secret    = base64_decode( (string) getenv( 'SITE_AGENT_SIGNING_KEY' ), true );
	if ( ! is_dir( $directory ) || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version ) ) {
		fwrite( STDERR, "Usage: SITE_AGENT_SIGNING_KEY=... php bin/sign-package.php <directory> <version> [expected public key]\n" );
		exit( 1 );
	}
	if ( ! function_exists( 'sodium_crypto_sign_detached' ) || false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
		fwrite( STDERR, "SITE_AGENT_SIGNING_KEY must be a base64 Ed25519 secret key from bin/release-key.php, and Sodium must be available.\n" );
		exit( 1 );
	}
	$public = base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) );
	if ( ! empty( $argv[3] ) && ! hash_equals( $argv[3], $public ) ) {
		fwrite( STDERR, "The signing key does not match Updater::PUBLIC_KEY.\n" );
		exit( 1 );
	}
	$manifest = site_agent_sign_package( $directory, $version, $secret );
	echo 'Signed ' . count( $manifest['files'] ) . " files for {$version}.\n";
}
