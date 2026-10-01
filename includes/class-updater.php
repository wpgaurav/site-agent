<?php
/**
 * FluentCart updates through native WordPress upgrader hooks.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Hashing extracted package files on local disk.
/** Refresh protected package URLs before downloads and verify extracted identity. */
final class Updater {
	const CACHE = 'site_agent_update_metadata';
	/** The plugin's Update URI host. WordPress calls update_plugins_{host} once per update check. */
	const HOST = 'gauravtiwari.org';
	/** Seconds to remember a failed update request so an unreachable store cannot stall admin pages. */
	const FAILURE_TTL = 900;
	/**
	 * Base64 Ed25519 public key that release packages must be signed with (bin/release-key.php).
	 * Empty disables signature enforcement. Once set, bin/build.sh refuses to build unsigned packages.
	 */
	const PUBLIC_KEY = '';
	const SIGNATURE  = 'signature.json';

	/**
	 * Guard against recursive metadata requests.
	 *
	 * @var bool
	 */
	private static $fetching = false;

	public static function init(): void {
		add_filter( 'update_plugins_' . self::HOST, array( self::class, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( self::class, 'source' ), 10, 4 );
		add_action( 'delete_site_transient_update_plugins', array( self::class, 'clear_cache' ) );
	}

	public static function clear_cache(): void {
		delete_transient( self::CACHE );
	}

	public static function package_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] ?? '' );
		return 'gauravtiwari.org' === $host || 1 === preg_match( '/^gauravtiwari-org-fluentcart\.[a-f0-9]{32}\.r2\.cloudflarestorage\.com$/D', $host );
	}

	public static function metadata( bool $force = false ) {
		$credentials = License::credentials();
		if ( ! $credentials || self::$fetching ) {
			return null;
		}
		$identity = hash( 'sha256', home_url( '/' ) . wp_json_encode( $credentials ) . SITE_AGENT_VERSION );
		$cached   = get_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) && ( $cached['identity'] ?? '' ) === $identity ) {
			if ( isset( $cached['error'] ) ) {
				return new \WP_Error( (string) $cached['error'], __( 'The update server could not be reached recently. WordPress will try again later.', 'site-agent' ) );
			}
			return $cached['data'];
		}
		self::$fetching = true;
		try {
			$response = License::request( 'get_license_version', $credentials );
			if ( ! is_wp_error( $response ) ) {
				$version = (string) ( $response['new_version'] ?? '' );
				if ( 'site-agent' !== ( $response['slug'] ?? '' ) || ( isset( $response['product_id'] ) && License::PRODUCT_ID !== (int) $response['product_id'] ) || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version ) ) {
					$response = new \WP_Error( 'site_agent_update_identity', __( 'The update metadata does not identify a Site Agent release.', 'site-agent' ) );
				}
			}
			if ( is_wp_error( $response ) ) {
				set_transient(
					self::CACHE,
					array(
						'identity' => $identity,
						'error'    => $response->get_error_code(),
					),
					self::FAILURE_TTL
				);
				return $response;
			}
			// Keep only expected metadata. Never cache echoed credentials from a server.
			$data            = array_intersect_key( $response, array_flip( array( 'new_version', 'slug', 'product_id', 'requires', 'requires_php', 'tested', 'sections', 'icons', 'banners', 'license_status' ) ) );
			$package         = (string) ( $response['package'] ?? $response['download_link'] ?? '' );
			$data['package'] = 'valid' === ( $response['license_status'] ?? '' ) && self::package_allowed( $package ) ? $package : '';
			set_transient(
				self::CACHE,
				array(
					'identity' => $identity,
					'data'     => $data,
				),
				3 * HOUR_IN_SECONDS
			);
			return $data;
		} finally {
			self::$fetching = false;
		}
	}

	/**
	 * Answer WordPress's update check for this plugin's Update URI host.
	 *
	 * Without a licensed package the installed version is reported, so WordPress never queues an
	 * automatic update that cannot download.
	 *
	 * @param mixed  $update      Update data from another handler, or false.
	 * @param array  $plugin_data Plugin headers.
	 * @param string $plugin_file Plugin basename.
	 * @return mixed
	 */
	public static function update( $update, $plugin_data, $plugin_file ) {
		unset( $plugin_data );
		if ( plugin_basename( SITE_AGENT_FILE ) !== $plugin_file ) {
			return $update;
		}
		$data = self::metadata();
		if ( ! $data || is_wp_error( $data ) ) {
			return $update;
		}
		$offered = '' !== $data['package'] && version_compare( $data['new_version'], SITE_AGENT_VERSION, '>' );
		return array(
			'slug'         => 'site-agent',
			'version'      => $offered ? $data['new_version'] : SITE_AGENT_VERSION,
			'url'          => License::PRODUCT,
			'package'      => $offered ? $data['package'] : '',
			'requires'     => $data['requires'] ?? '6.9',
			'requires_php' => $data['requires_php'] ?? '8.0',
			'tested'       => $data['tested'] ?? '',
			'icons'        => $data['icons'] ?? array(),
			'banners'      => $data['banners'] ?? array(),
		);
	}

	public static function information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || 'site-agent' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$data = self::metadata();
		if ( ! $data || is_wp_error( $data ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Site Agent',
			'slug'          => 'site-agent',
			'version'       => $data['new_version'],
			'author'        => 'Gaurav Tiwari',
			'homepage'      => License::PRODUCT,
			'requires'      => $data['requires'] ?? '6.9',
			'requires_php'  => $data['requires_php'] ?? '8.0',
			'download_link' => $data['package'],
			'sections'      => array(
				'description' => wp_kses_post( $data['sections']['description'] ?? '' ),
				'changelog'   => wp_kses_post( $data['sections']['changelog'] ?? '' ),
			),
			'icons'         => $data['icons'] ?? array(),
			'banners'       => $data['banners'] ?? array(),
		);
	}

	public static function download( $reply, string $package, $upgrader, array $extra ) {
		if ( false !== $reply || ( $extra['plugin'] ?? '' ) !== plugin_basename( SITE_AGENT_FILE ) ) {
			return $reply;
		}
		$data = self::metadata( true );
		if ( ! $data || is_wp_error( $data ) || empty( $data['package'] ) || ! version_compare( $data['new_version'], SITE_AGENT_VERSION, '>' ) ) {
			return new \WP_Error( 'site_agent_update_unavailable', __( 'A licensed Site Agent update is not available. Check the update license and try again.', 'site-agent' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $data['package'], 120 );
		return is_wp_error( $file ) ? new \WP_Error( 'site_agent_update_download', __( 'The Site Agent update could not be downloaded. Try again.', 'site-agent' ) ) : $file;
	}

	public static function source( $source, string $remote, $upgrader, array $extra ) {
		if ( ( $extra['plugin'] ?? '' ) !== plugin_basename( SITE_AGENT_FILE ) || ! is_string( $source ) ) {
			return $source;
		}
		$main = trailingslashit( $source ) . 'site-agent.php';
		$data = self::metadata();
		if ( ! is_file( $main ) || ! $data || is_wp_error( $data ) ) {
			return new \WP_Error( 'site_agent_update_package', __( 'The update does not contain a valid Site Agent plugin.', 'site-agent' ) );
		}
		$header = get_file_data(
			$main,
			array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
			)
		);
		if ( 'Site Agent' !== $header['name'] || $header['version'] !== $data['new_version'] || ! version_compare( $header['version'], SITE_AGENT_VERSION, '>' ) ) {
			return new \WP_Error( 'site_agent_update_package', __( 'The extracted package does not match the offered Site Agent release.', 'site-agent' ) );
		}
		if ( '' !== self::PUBLIC_KEY ) {
			$verified = self::verify_package( $source, $header['version'], self::PUBLIC_KEY );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		}
		return $source;
	}

	/**
	 * The signed message: a format line, the version, then "sha256  path" lines sorted by path.
	 * bin/sign-package.php builds the same message.
	 *
	 * @param string                $version Release version.
	 * @param array<string, string> $files   SHA-256 hashes keyed by relative path.
	 */
	public static function package_message( string $version, array $files ): string {
		ksort( $files, SORT_STRING );
		$lines = array( 'site-agent-package-v1', $version );
		foreach ( $files as $path => $hash ) {
			$lines[] = $hash . '  ' . $path;
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Verify that an extracted package is exactly the set of files signed for this version.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_package( string $directory, string $version, string $public_key ) {
		$fail     = new \WP_Error( 'site_agent_update_signature', __( 'The update package signature is missing or invalid, so it was not installed.', 'site-agent' ) );
		$manifest = trailingslashit( $directory ) . self::SIGNATURE;
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! is_file( $manifest ) || filesize( $manifest ) > 1048576 ) {
			return $fail;
		}
		$data = json_decode( (string) file_get_contents( $manifest ), true );
		if ( ! is_array( $data ) || ( $data['version'] ?? '' ) !== $version || ! isset( $data['files'], $data['signature'] ) || ! is_array( $data['files'] ) || ! is_string( $data['signature'] ) ) {
			return $fail;
		}
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a signature and public key, never code.
		$signature = base64_decode( $data['signature'], true );
		$key       = base64_decode( $public_key, true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_string( $signature ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || ! is_string( $key ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return $fail;
		}
		if ( ! sodium_crypto_sign_verify_detached( $signature, self::package_message( $version, $data['files'] ), $key ) ) {
			return $fail;
		}
		$found = array();
		$root  = strlen( trailingslashit( $directory ) );
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), $root ) );
			if ( $item->isLink() ) {
				return $fail;
			}
			if ( $item->isFile() && self::SIGNATURE !== $relative ) {
				$found[ $relative ] = hash_file( 'sha256', $item->getPathname() );
			}
		}
		ksort( $found, SORT_STRING );
		$expected = $data['files'];
		ksort( $expected, SORT_STRING );
		return $found === $expected ? true : $fail;
	}
}
