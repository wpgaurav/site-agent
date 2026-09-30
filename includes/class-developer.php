<?php
/**
 * Explicitly enabled execution tools. These are not a sandbox.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- These handles are subprocess pipes, not WP_Filesystem paths.
/** Explicitly enabled execution tools. These are not a sandbox. */
final class Developer {
	const MAX_OUTPUT = 65536;

	public static function php( array $input ) {
		$code = $input['code'];
		if ( strlen( $code ) > 65536 ) {
			return new \WP_Error( 'code_limit', __( 'PHP code must be at most 64 KiB.', 'site-agent' ) );
		}
		try {
			// Validate without executing. Clients supply PHP statements without tags.
			$tokens = token_get_all( '<?php ' . $code, TOKEN_PARSE );
			foreach ( array_slice( $tokens, 1 ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO ), true ) ) {
					return new \WP_Error( 'php_tags', __( 'Supply PHP statements without opening or closing tags.', 'site-agent' ) );
				}
			}
		} catch ( \ParseError $error ) {
			return new \WP_Error( 'php_syntax', __( 'PHP syntax validation failed.', 'site-agent' ) );
		}
		$output    = '';
		$truncated = false;
		$level     = ob_get_level();
		ob_start(
			static function ( $chunk ) use ( &$output, &$truncated ) {
				$remaining = self::MAX_OUTPUT - strlen( $output );
				$output   .= substr( $chunk, 0, max( 0, $remaining ) );
				$truncated = $truncated || strlen( $chunk ) > $remaining;
				return '';
			},
			4096
		);
		try {
			$execute = static function ( $php_code ) {
				global $wpdb;
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Intentionally privileged, opt-in developer execution; never enabled by default.
				return eval( $php_code );
			};
			$return  = $execute( $code );
			$encoded = wp_json_encode( $return );
			if ( false === $encoded || strlen( $encoded ) > self::MAX_OUTPUT ) {
				return new \WP_Error( 'return_limit', __( 'The PHP return value must be JSON-serializable and at most 64 KiB.', 'site-agent' ) );
			}
		} catch ( \Throwable $error ) {
			// Do not disclose stack traces, paths, or exception messages automatically.
			return new \WP_Error( 'php_execution_failed', __( 'PHP execution raised an error. Earlier side effects may already have occurred.', 'site-agent' ) );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_flush();
			}
		}
		return array(
			'output'       => $output,
			'return_value' => json_decode( $encoded, true ),
			'truncated'    => $truncated,
		);
	}

	public static function cli_binary(): string {
		foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp' ) as $path ) {
			if ( is_file( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	public static function cli( array $input ) {
		$binary = self::cli_binary();
		if ( '' === $binary || ! function_exists( 'proc_open' ) ) {
			return new \WP_Error( 'cli_unavailable', __( 'WP-CLI and process execution must be available on the server.', 'site-agent' ) );
		}
		$args = $input['arguments'];
		if ( empty( $args ) || count( $args ) > 40 ) {
			return new \WP_Error( 'invalid_arguments', __( 'Supply between 1 and 40 WP-CLI arguments.', 'site-agent' ) );
		}
		foreach ( $args as $argument ) {
			if ( ! is_string( $argument ) || false !== strpos( $argument, "\0" ) || preg_match( '/^--(?:path|url|user|ssh|http|require|exec|allow-root)(?:=|$)/', $argument ) ) {
				return new \WP_Error( 'protected_argument', __( 'Connection, identity, and bootstrap arguments cannot be overridden.', 'site-agent' ) );
			}
		}
		$command = array_merge( array( $binary, '--path=' . ABSPATH, '--url=' . home_url( '/' ), '--user=' . get_current_user_id(), '--no-color', '--skip-plugins=site-agent' ), $args );
		$pipes   = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Explicitly enabled foreground WP-CLI developer tool.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			ABSPATH
		);
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'cli_failed', __( 'The WP-CLI process could not start.', 'site-agent' ) );
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$output    = array( '', '' );
		$truncated = false;
		$deadline  = microtime( true ) + 20;
		$exit      = -1;
		$timed_out = false;
		try {
			do {
				foreach ( array( 1, 2 ) as $index ) {
					$chunk                 = stream_get_contents( $pipes[ $index ], 8192 );
					$remaining             = self::MAX_OUTPUT - strlen( $output[ $index - 1 ] );
					$output[ $index - 1 ] .= substr( (string) $chunk, 0, max( 0, $remaining ) );
					$truncated             = $truncated || strlen( (string) $chunk ) > $remaining;
				}
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					$exit = $status['exitcode'];
					foreach ( array( 1, 2 ) as $index ) {
						$tail                  = stream_get_contents( $pipes[ $index ] );
						$remaining             = self::MAX_OUTPUT - strlen( $output[ $index - 1 ] );
						$output[ $index - 1 ] .= substr( (string) $tail, 0, max( 0, $remaining ) );
						$truncated             = $truncated || strlen( (string) $tail ) > $remaining;
					}
					break;
				}
				if ( microtime( true ) >= $deadline ) {
					$timed_out = true;
					proc_terminate( $process, 9 );
					break;
				}
				usleep( 10000 );
			} while ( true );
		} finally {
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
		}
		return array(
			'stdout'    => $output[0],
			'stderr'    => $output[1],
			'exit_code' => $exit,
			'timed_out' => $timed_out,
			'truncated' => $truncated,
		);
	}
}
