<?php
/**
 * Site-bound, encrypted FluentCart update credentials.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** License activation controls update delivery, never developer functionality. */
final class License {
	const PRODUCT_ID = 1180328;
	const STORE      = 'https://gauravtiwari.org/';
	const PRODUCT    = 'https://gauravtiwari.org/product/site-agent/';
	const OPTION     = 'site_agent_license';

	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function encrypt( array $credentials ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'Sodium is unavailable.' );
		}
		$key   = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . self::OPTION, true );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding encrypted credentials for option storage, never executable code.
		return base64_encode( $nonce . sodium_crypto_secretbox( wp_json_encode( $credentials ), $nonce, $key ) );
	}

	public static function credentials(): array {
		$state = self::state();
		if ( empty( $state['secret'] ) || ( $state['site'] ?? '' ) !== home_url( '/' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return array();
		}
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding ciphertext before authenticated decryption, never code execution.
			$blob = base64_decode( $state['secret'], true );
			if ( false === $blob || strlen( $blob ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return array();
			}
			$key   = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . self::OPTION, true );
			$data  = sodium_crypto_secretbox_open( substr( $blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
			$value = false === $data ? null : json_decode( $data, true );
			return is_array( $value ) && ! empty( $value['license_key'] ) && ! empty( $value['activation_hash'] ) ? $value : array();
		} catch ( \Throwable $error ) {
			return array();
		}
	}

	public static function request( string $action, array $credentials ) {
		if ( ! in_array( $action, array( 'activate_license', 'check_license', 'deactivate_license', 'get_license_version' ), true ) ) {
			return new \WP_Error( 'site_agent_license_action', __( 'Unsupported license operation.', 'site-agent' ) );
		}
		$body     = array_intersect_key( $credentials, array_flip( array( 'license_key', 'activation_hash' ) ) );
		$body    += array(
			'item_id'          => self::PRODUCT_ID,
			'site_url'         => home_url( '/' ),
			'current_version'  => SITE_AGENT_VERSION,
			'platform_version' => get_bloginfo( 'version' ),
			'server_version'   => PHP_VERSION,
		);
		$response = wp_remote_post(
			self::STORE . '?fluent-cart=' . $action,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 262144,
				'headers'             => array( 'Accept' => 'application/json' ),
				'body'                => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'site_agent_license_connection', __( 'The update server could not be reached. Try again.', 'site-agent' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || $status < 200 || $status >= 300 || false === ( $data['success'] ?? true ) ) {
			return new \WP_Error( 'site_agent_license_response', __( 'The license request was rejected. Check the license key and available activations.', 'site-agent' ) );
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		return $data;
	}

	public static function activate( string $key ) {
		if ( self::state() ) {
			return new \WP_Error( 'site_agent_existing_license', __( 'Disconnect the existing license before activating another.', 'site-agent' ) );
		}
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 256 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			return new \WP_Error( 'site_agent_invalid_key', __( 'Enter a valid license key.', 'site-agent' ) );
		}
		try {
			// Verify local encryption before consuming an activation at the store.
			self::encrypt( array( 'license_key' => $key ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'site_agent_encryption', __( 'Sodium encryption must be available before activating an update license.', 'site-agent' ) );
		}
		$data = self::request( 'activate_license', array( 'license_key' => $key ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'valid' !== ( $data['status'] ?? '' ) || (int) ( $data['product_id'] ?? 0 ) !== self::PRODUCT_ID || empty( $data['activation_hash'] ) ) {
			return new \WP_Error( 'site_agent_wrong_license', __( 'This license is not valid for Site Agent.', 'site-agent' ) );
		}
		self::save(
			$data,
			array(
				'license_key'     => $key,
				'activation_hash' => (string) $data['activation_hash'],
			)
		);
		return true;
	}

	public static function check() {
		$credentials = self::credentials();
		if ( ! $credentials ) {
			return new \WP_Error( 'site_agent_license_missing', __( 'Activate an update license for this site first.', 'site-agent' ) );
		}
		$data = self::request( 'check_license', $credentials );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$valid          = 'valid' === ( $data['status'] ?? '' ) && (int) ( $data['product_id'] ?? 0 ) === self::PRODUCT_ID;
		$data['status'] = $valid ? 'valid' : 'invalid';
		self::save( $data, $credentials );
		return $valid ? true : new \WP_Error( 'site_agent_license_invalid', __( 'The update license is inactive. Site Agent tools remain available.', 'site-agent' ) );
	}

	public static function disconnect() {
		$credentials = self::credentials();
		if ( $credentials ) {
			$data = self::request( 'deactivate_license', $credentials );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( 'deactivated' !== ( $data['status'] ?? '' ) ) {
				return new \WP_Error( 'site_agent_disconnect_failed', __( 'The license could not be disconnected. Try again.', 'site-agent' ) );
			}
		}
		delete_option( self::OPTION );
		Updater::clear_cache();
		delete_site_transient( 'update_plugins' );
		return true;
	}

	private static function save( array $data, array $credentials ): void {
		update_option(
			self::OPTION,
			array(
				'secret'     => self::encrypt( $credentials ),
				'site'       => home_url( '/' ),
				'status'     => $data['status'],
				'suffix'     => substr( $credentials['license_key'], -4 ),
				'plan'       => sanitize_text_field( (string) ( $data['variation_title'] ?? '' ) ),
				'expires'    => sanitize_text_field( (string) ( $data['expiration_date'] ?? '' ) ),
				'checked_at' => time(),
			),
			false
		);
		Updater::clear_cache();
		delete_site_transient( 'update_plugins' );
	}
}
