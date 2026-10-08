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
	/**
	 * Why the current request was refused at the transport, sent as a diagnostic header.
	 *
	 * @var string
	 */
	private static $denial = '';

	public static function administrator(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' )
			&& ( ! is_multisite() || is_super_admin() );
	}

	public static function transport( $request = null ): bool {
		self::$denial = '';
		if ( Config::locked() || ! Config::get()['enabled'] ) {
			return self::denied( 'disabled' );
		}
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return self::denied( 'https_required' );
		}
		if ( $request instanceof \WP_REST_Request && OAuth::supplied( $request ) ) {
			// A bearer token decides the identity on its own; it never combines with a session or password.
			return OAuth::authenticate( $request );
		}
		OAuth::reset();
		if ( $request instanceof \WP_REST_Request && Url_Auth::supplied( $request ) && ! Url_Auth::authenticate( $request ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return self::denied( 'unauthenticated' );
		}
		return self::administrator() ? true : self::denied( 'not_administrator' );
	}

	/** Record a transport refusal reason. Reasons never include credentials or identities. */
	public static function denied( string $reason ): bool {
		self::$denial = $reason;
		return false;
	}

	public static function denial(): string {
		return self::$denial;
	}

	public static function allowed( string $group = '' ): bool {
		$config = Config::get();
		if ( Config::locked() || empty( $config['enabled'] ) || ! self::administrator() ) {
			return false;
		}
		if ( '' !== $group && ( empty( $config[ $group ] ) || ! Config::group_available( $group ) ) ) {
			return false;
		}
		if ( in_array( $group, Config::CODE_GROUPS, true ) && ! current_user_can( 'edit_plugins' ) ) {
			return false;
		}
		return Scopes::permits( $group );
	}
}
