<?php
/**
 * Configuration with opt-in defaults.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Configuration with opt-in defaults. */
final class Config {
	const OPTION = 'site_agent_settings';

	public static function defaults(): array {
		return array(
			'enabled'       => false,
			'url_auth'      => false,
			'content_write' => false,
			'file_read'     => false,
			'file_write'    => false,
			'php_execute'   => false,
			'cli_execute'   => false,
			'audit_enabled' => true,
			'delete_data'   => false,
		);
	}

	public static function get(): array {
		// Re-read at authorization time so disabling access revokes running CLI sessions.
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();
		foreach ( self::defaults() as $key => $default ) {
			$clean[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( true, 1, '1' ), true );
		}
		return $clean;
	}

	public static function locked(): bool {
		return defined( 'SITE_AGENT_DISABLED' ) && SITE_AGENT_DISABLED;
	}

	public static function code_locked(): bool {
		return ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
			|| ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	}
}
