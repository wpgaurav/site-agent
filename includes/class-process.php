<?php
/**
 * Bounded foreground subprocesses.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- These handles are subprocess pipes, not WP_Filesystem paths.
/** Run a command without a shell, with a deadline and bounded output. */
final class Process {
	const MAX_OUTPUT = 65536;

	public static function available(): bool {
		return function_exists( 'proc_open' ) && function_exists( 'proc_get_status' );
	}

	/**
	 * Run a command given as an argument array.
	 *
	 * @param array<int, string>         $command Executable followed by its arguments.
	 * @param int                        $timeout Seconds before the process is killed.
	 * @param string|null                $cwd     Working directory.
	 * @param array<string, string>|null $env     Complete environment, or null to inherit.
	 * @return array{started: bool, stdout: string, stderr: string, exit_code: int, timed_out: bool, truncated: bool}
	 */
	public static function run( array $command, int $timeout, ?string $cwd = null, ?array $env = null ): array {
		$result = array(
			'started'   => false,
			'stdout'    => '',
			'stderr'    => '',
			'exit_code' => -1,
			'timed_out' => false,
			'truncated' => false,
		);
		if ( ! self::available() ) {
			return $result;
		}
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Argument arrays bypass the shell; callers validate every argument.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$cwd,
			$env
		);
		if ( ! is_resource( $process ) ) {
			return $result;
		}
		$result['started'] = true;
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$output   = array( '', '' );
		$deadline = microtime( true ) + max( 1, $timeout );
		try {
			do {
				self::drain( $pipes, $output, $result['truncated'], 8192 );
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					$result['exit_code'] = (int) $status['exitcode'];
					self::drain( $pipes, $output, $result['truncated'], null );
					break;
				}
				if ( microtime( true ) >= $deadline ) {
					$result['timed_out'] = true;
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
		$result['stdout'] = $output[0];
		$result['stderr'] = $output[1];
		return $result;
	}

	/**
	 * Read available output from stdout and stderr up to the shared limit.
	 *
	 * @param array<int, resource> $pipes     Process pipes.
	 * @param array<int, string>   $output    Collected stdout and stderr.
	 * @param bool                 $truncated Set when output exceeds the limit.
	 * @param int|null             $length    Bytes to read per pipe, or null for all remaining output.
	 */
	private static function drain( array $pipes, array &$output, bool &$truncated, ?int $length ): void {
		foreach ( array( 1, 2 ) as $index ) {
			$chunk                 = (string) stream_get_contents( $pipes[ $index ], $length );
			$remaining             = self::MAX_OUTPUT - strlen( $output[ $index - 1 ] );
			$output[ $index - 1 ] .= substr( $chunk, 0, max( 0, $remaining ) );
			$truncated             = $truncated || strlen( $chunk ) > $remaining;
		}
	}
}
