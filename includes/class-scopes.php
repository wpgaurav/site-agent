<?php
/**
 * Per-Application-Password tool limits.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Narrow the enabled tool groups for individual Application Passwords. */
final class Scopes {
	const OPTION = 'site_agent_token_scopes';
	/** Groups a password can be limited to. Read-only content tools stay available to every administrator password. */
	const GROUPS = array( 'content_write', 'file_read', 'file_write', 'php_execute', 'cli_execute', 'bricks' );

	/**
	 * Stored limits keyed by Application Password UUID. A missing UUID means unrestricted.
	 *
	 * @return array<string, string[]>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/** UUID of the Application Password that authenticated this request, or an empty string. */
	public static function current_uuid(): string {
		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		return is_string( $uuid ) ? $uuid : '';
	}

	public static function permits( string $group ): bool {
		if ( '' === $group ) {
			return true;
		}
		$scopes = self::all();
		$uuid   = self::current_uuid();
		return '' === $uuid || ! isset( $scopes[ $uuid ] ) || in_array( $group, $scopes[ $uuid ], true );
	}

	/** Display name of the Application Password used for this request. */
	public static function current_label(): string {
		$uuid = self::current_uuid();
		if ( '' === $uuid || ! class_exists( 'WP_Application_Passwords' ) ) {
			return '';
		}
		$item = \WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
		return is_array( $item ) ? (string) $item['name'] : '';
	}

	/**
	 * Settings sanitizer. Accepts the settings form, where only the current user's listed
	 * passwords change, or an already-stored map.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, string[]>
	 */
	public static function sanitize( $input ): array {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		if ( ! isset( $input['__shown'] ) ) {
			return self::normalize( $input );
		}
		$user_id = get_current_user_id();
		foreach ( (array) $input['__shown'] as $uuid ) {
			if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid ) || ! \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) ) {
				continue;
			}
			$row = isset( $input[ $uuid ] ) && is_array( $input[ $uuid ] ) ? $input[ $uuid ] : array();
			if ( empty( $row['limited'] ) ) {
				unset( $current[ $uuid ] );
				continue;
			}
			$current[ $uuid ] = isset( $row['groups'] ) ? (array) $row['groups'] : array();
		}
		return self::normalize( $current );
	}

	/** Drop limits for a revoked password. Hooked to wp_delete_application_password. */
	public static function forget( $user_id, $item ): void {
		unset( $user_id );
		$scopes = self::all();
		if ( is_array( $item ) && isset( $item['uuid'], $scopes[ $item['uuid'] ] ) ) {
			unset( $scopes[ $item['uuid'] ] );
			update_option( self::OPTION, $scopes, false );
		}
	}

	/**
	 * Keep valid UUID keys and known groups only.
	 *
	 * @param array<mixed> $scopes Raw map.
	 * @return array<string, string[]>
	 */
	private static function normalize( array $scopes ): array {
		$clean = array();
		foreach ( $scopes as $uuid => $groups ) {
			if ( is_string( $uuid ) && wp_is_uuid( $uuid ) && is_array( $groups ) ) {
				$clean[ $uuid ] = array_values( array_intersect( self::GROUPS, $groups ) );
			}
		}
		return $clean;
	}
}
