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

	public static function record( string $tool, bool $success, float $start ): void {
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
		);
		update_option( self::OPTION, array_slice( $rows, -100 ), false );
	}
}
