<?php
/**
 * Authorization independent of tool arguments.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Authorization independent of tool arguments. */
final class Permissions {
	public static function administrator(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' )
			&& ( ! is_multisite() || is_super_admin() );
	}

	public static function transport( $request = null ): bool {
		if ( Config::locked() || ! Config::get()['enabled'] || ( ! is_ssl() && 'local' !== wp_get_environment_type() ) ) {
			return false;
		}
		if ( $request instanceof \WP_REST_Request && array_key_exists( 'auth', $request->get_query_params() ) ) {
			if ( ! Url_Auth::authenticate( $request ) ) {
				return false;
			}
		}
		return self::allowed();
	}

	public static function allowed( string $group = '' ): bool {
		$config = Config::get();
		if ( Config::locked() || empty( $config['enabled'] ) || ! self::administrator() ) {
			return false;
		}
		if ( '' !== $group && empty( $config[ $group ] ) ) {
			return false;
		}
		if ( in_array( $group, array( 'file_read', 'file_write', 'php_execute', 'cli_execute' ), true ) ) {
			if ( Config::code_locked() || ! current_user_can( 'edit_plugins' ) ) {
				return false;
			}
		}
		return true;
	}
}
