<?php
/**
 * Bounded metadata-only audit history.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Bounded metadata-only audit history. */
final class Audit {
	const OPTION = 'site_agent_audit';
	const LIMIT  = 100;

	/**
	 * Record one tool call. Targets identify what was touched (a post ID, a file path, a WP-CLI
	 * command name) and never include content, code, outputs or credential secrets.
	 *
	 * @param string                $tool    Tool name.
	 * @param bool                  $success Whether the call succeeded.
	 * @param float                 $start   Start time from microtime( true ).
	 * @param array<string, string> $details Optional target and error code.
	 */
	public static function record( string $tool, bool $success, float $start, array $details = array() ): void {
		if ( ! Config::get()['audit_enabled'] ) {
			return;
		}
		$rows   = get_option( self::OPTION, array() );
		$rows   = is_array( $rows ) ? $rows : array();
		$rows[] = array(
			'time'       => gmdate( 'c' ),
			'user_id'    => get_current_user_id(),
			'tool'       => $tool,
			'success'    => $success,
			'elapsed_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'target'     => mb_substr( (string) ( $details['target'] ?? '' ), 0, 200 ),
			'error'      => sanitize_key( (string) ( $details['error'] ?? '' ) ),
			'credential' => mb_substr( Scopes::current_label(), 0, 60 ),
			'via'        => self::via(),
		);
		update_option( self::OPTION, array_slice( $rows, -self::LIMIT ), false );
	}

	/** How the caller authenticated: an Application Password in a header or URL, or a WordPress session. */
	private static function via(): string {
		if ( '' !== Scopes::current_uuid() ) {
			return Url_Auth::used() ? 'url' : 'header';
		}
		return is_user_logged_in() ? 'session' : '';
	}

	/**
	 * Summarize tool arguments without values that could hold content or secrets.
	 *
	 * @param array<string, mixed> $input Validated tool arguments.
	 */
	public static function target( array $input ): string {
		if ( isset( $input['from'], $input['to'] ) && is_string( $input['from'] ) && is_string( $input['to'] ) ) {
			return $input['from'] . ' -> ' . $input['to'];
		}
		if ( isset( $input['ability_name'] ) && is_string( $input['ability_name'] ) ) {
			$inner = isset( $input['parameters'] ) && is_array( $input['parameters'] ) ? self::target( $input['parameters'] ) : '';
			return trim( $input['ability_name'] . ' ' . $inner );
		}
		if ( isset( $input['skill'] ) && is_string( $input['skill'] ) ) {
			return 'skill ' . $input['skill'] . '/' . ( isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : 'SKILL.md' );
		}
		if ( isset( $input['path'] ) && is_string( $input['path'] ) ) {
			return $input['path'];
		}
		if ( isset( $input['arguments'] ) && is_array( $input['arguments'] ) ) {
			// Only command words: positional values and flags can carry passwords or content.
			$words = array();
			foreach ( $input['arguments'] as $argument ) {
				if ( ! is_string( $argument ) || ! preg_match( '/^[a-z][a-z0-9-]*$/', $argument ) || count( $words ) >= 2 ) {
					break;
				}
				$words[] = $argument;
			}
			return 'wp ' . implode( ' ', $words );
		}
		foreach ( array(
			'post_id' => 'post',
			'postId'  => 'post',
			'id'      => 'attachment',
		) as $key => $label ) {
			if ( isset( $input[ $key ] ) && is_int( $input[ $key ] ) ) {
				return $label . ' ' . $input[ $key ];
			}
		}
		if ( isset( $input['url'] ) && is_string( $input['url'] ) ) {
			// Hosts only: signed URLs carry credentials in their query strings.
			return (string) wp_parse_url( $input['url'], PHP_URL_HOST );
		}
		if ( isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ) {
			return $input['taxonomy'];
		}
		return isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
	}
}
