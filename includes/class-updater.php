<?php
/**
 * FluentCart updates through native WordPress upgrader hooks.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Refresh protected package URLs before downloads and verify extracted identity. */
final class Updater {
	const CACHE = 'site_agent_update_metadata';
	/**
	 * Guard against recursive metadata requests.
	 *
	 * @var bool
	 */
	private static $fetching = false;
	/**
	 * Guard against update-transient recursion.
	 *
	 * @var bool
	 */
	private static $injecting = false;

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'updates' ) );
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
			return $cached['data'];
		}
		self::$fetching = true;
		try {
			$response = License::request( 'get_license_version', $credentials );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$version = (string) ( $response['new_version'] ?? '' );
			if ( 'site-agent' !== ( $response['slug'] ?? '' ) || ( isset( $response['product_id'] ) && License::PRODUCT_ID !== (int) $response['product_id'] ) || ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version ) ) {
				return new \WP_Error( 'site_agent_update_identity', __( 'The update metadata does not identify a Site Agent release.', 'site-agent' ) );
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

	public static function updates( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || self::$injecting ) {
			return $transient;
		}
		self::$injecting = true;
		try {
			$file = plugin_basename( SITE_AGENT_FILE );
			$data = self::metadata();
			if ( is_wp_error( $data ) ) {
				return $transient;
			}
			if ( ! $data ) {
				unset( $transient->response[ $file ], $transient->no_update[ $file ] );
				return $transient;
			}
			$entry = (object) array(
				'id'           => License::PRODUCT,
				'slug'         => 'site-agent',
				'plugin'       => $file,
				'url'          => License::PRODUCT,
				'new_version'  => $data['new_version'],
				'package'      => $data['package'],
				'requires'     => $data['requires'] ?? '6.9',
				'requires_php' => $data['requires_php'] ?? '8.0',
				'tested'       => $data['tested'] ?? '',
			);
			if ( '' !== $data['package'] && version_compare( $data['new_version'], SITE_AGENT_VERSION, '>' ) ) {
				$transient->response[ $file ] = $entry;
				unset( $transient->no_update[ $file ] );
			} else {
				$transient->no_update[ $file ] = $entry;
				unset( $transient->response[ $file ] );
			}
			return $transient;
		} finally {
			self::$injecting = false;
		}
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
		return $source;
	}
}
