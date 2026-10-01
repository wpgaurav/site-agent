<?php
/**
 * Caller-facing error details.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Describe failures precisely, with paths relative to the installation. */
final class Errors {
	/** Strip installation roots so paths read like tool paths (plugins/…, themes/…). */
	public static function relative( string $text ): string {
		$roots = array();
		foreach ( array( WP_CONTENT_DIR, realpath( WP_CONTENT_DIR ), ABSPATH, realpath( ABSPATH ) ) as $root ) {
			if ( is_string( $root ) && '' !== $root ) {
				$roots[] = trailingslashit( $root );
			}
		}
		// Longest first so wp-content paths keep their plugins/ or themes/ prefix.
		usort(
			$roots,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return str_replace( array_unique( $roots ), '', $text );
	}

	/** The caller already controls the code that failed, so the full message helps and hides nothing. */
	public static function describe( \Throwable $error ): string {
		$file  = $error->getFile();
		$where = false !== strpos( $file, "eval()'d code" )
			? sprintf( 'line %d', $error->getLine() )
			: sprintf( '%s:%d', self::relative( $file ), $error->getLine() );
		return sprintf( '%1$s: %2$s (%3$s)', get_class( $error ), self::relative( $error->getMessage() ), $where );
	}
}
