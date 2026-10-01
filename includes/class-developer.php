<?php
/**
 * Explicitly enabled execution tools. These are not a sandbox.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Explicitly enabled execution tools. These are not a sandbox. */
final class Developer {
	const MAX_OUTPUT  = 65536;
	const WP_DIE_HOOK = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );

	/**
	 * Output-buffer level and start time while PHP code runs, so a shutdown can still report.
	 *
	 * @var array{level: int, start: float}|null
	 */
	private static $guard = null;

	public static function php( array $input ) {
		$code = $input['code'];
		if ( strlen( $code ) > 65536 ) {
			return new \WP_Error( 'code_limit', __( 'PHP code must be at most 64 KiB.', 'site-agent' ) );
		}
		$valid = self::validate( $code );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$output    = '';
		$truncated = false;
		$level     = ob_get_level();
		self::guard( $level );
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
		} catch ( Halt $halt ) {
			return new \WP_Error(
				'php_wp_die',
				/* translators: %s: wp_die() message. */
				sprintf( __( 'The code called wp_die(): %s. Earlier side effects may already have occurred.', 'site-agent' ), $halt->getMessage() )
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error(
				'php_execution_failed',
				/* translators: %s: exception class, message and line. */
				sprintf( __( 'PHP execution raised %s. Earlier side effects may already have occurred.', 'site-agent' ), Errors::describe( $error ) )
			);
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_flush();
			}
			self::release();
		}
		return array(
			'output'       => $output,
			'return_value' => json_decode( $encoded, true ),
			'truncated'    => $truncated,
		);
	}

	/**
	 * Parse without executing. Clients supply statements without tags.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate( string $code ) {
		try {
			$tokens = token_get_all( '<?php ' . $code, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new \WP_Error(
				'php_syntax',
				/* translators: 1: line number, 2: PHP parser message. */
				sprintf( __( 'PHP syntax error on line %1$d: %2$s', 'site-agent' ), $error->getLine(), $error->getMessage() )
			);
		}
		foreach ( array_slice( $tokens, 1 ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( in_array( $token[0], array( T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO ), true ) ) {
				return new \WP_Error( 'php_tags', __( 'Supply PHP statements without opening or closing tags.', 'site-agent' ) );
			}
			if ( T_EXIT === $token[0] || in_array( strtolower( $token[1] ), array( '\exit', '\die' ), true ) ) {
				return new \WP_Error(
					'php_exit',
					/* translators: %d: line number. */
					sprintf( __( 'Line %d calls exit or die, which would end the MCP response. Return a value instead.', 'site-agent' ), $token[2] )
				);
			}
		}
		return true;
	}

	/** Turn wp_die() into an exception and keep a shutdown handler ready for exit() and fatal errors. */
	private static function guard( int $level ): void {
		static $registered = false;
		if ( ! $registered ) {
			register_shutdown_function( array( self::class, 'shutdown' ) );
			$registered = true;
		}
		self::$guard = array(
			'level' => $level,
			'start' => microtime( true ),
		);
		foreach ( self::WP_DIE_HOOK as $hook ) {
			add_filter( $hook, array( self::class, 'halt_handler' ), PHP_INT_MAX );
		}
	}

	private static function release(): void {
		self::$guard = null;
		foreach ( self::WP_DIE_HOOK as $hook ) {
			remove_filter( $hook, array( self::class, 'halt_handler' ), PHP_INT_MAX );
		}
	}

	/** Filter callback: replace every wp_die() handler while PHP code runs. */
	public static function halt_handler(): callable {
		return array( self::class, 'halt' );
	}

	/**
	 * Raise Halt instead of ending the request. During a fatal-error shutdown, stay silent so
	 * shutdown() reports the error as a tool result.
	 *
	 * @param mixed $message wp_die() message.
	 * @throws Halt Always, outside a fatal-error shutdown.
	 */
	public static function halt( $message = '' ): void {
		if ( self::fatal_error() ) {
			return;
		}
		if ( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}
		$text = trim( wp_strip_all_tags( is_scalar( $message ) ? (string) $message : '' ) );
		// The message becomes a tool result string, never HTML output.
		throw new Halt( '' === $text ? esc_html__( 'no message', 'site-agent' ) : esc_html( $text ) );
	}

	/**
	 * The last error, when it is fatal.
	 *
	 * @return array{type: int, message: string, file: string, line: int}|null
	 */
	private static function fatal_error(): ?array {
		$error = error_get_last();
		return is_array( $error ) && in_array( $error['type'], Health::FATAL_TYPES, true ) ? $error : null;
	}

	/** Report exit(), die() or a fatal error during execute-php as an MCP tool error. */
	public static function shutdown(): void {
		if ( null === self::$guard ) {
			return;
		}
		$guard = self::$guard;
		self::release();
		while ( ob_get_level() > $guard['level'] ) {
			ob_end_clean();
		}
		$error   = self::fatal_error();
		$message = $error
			/* translators: 1: PHP error message, 2: line number. */
			? sprintf( __( 'PHP fatal error: %1$s (line %2$d). Earlier side effects may already have occurred.', 'site-agent' ), Errors::relative( $error['message'] ), $error['line'] )
			: __( 'The PHP code ended the request before returning (exit, die or a redirect). Earlier side effects may already have occurred.', 'site-agent' );
		Audit::record( 'execute-php', false, $guard['start'], array( 'error' => $error ? 'php_fatal' : 'php_exit' ) );
		$request = json_decode( (string) \WP_REST_Server::get_raw_data(), true );
		if ( headers_sent() || ! is_array( $request ) || 'tools/call' !== ( $request['method'] ?? '' ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		echo wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => is_scalar( $request['id'] ?? null ) ? $request['id'] : null,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $message,
						),
					),
					'isError' => true,
				),
			)
		);
	}

	public static function cli_binary(): string {
		$candidates = defined( 'SITE_AGENT_WP_CLI' ) ? array( (string) SITE_AGENT_WP_CLI ) : array( '/usr/local/bin/wp', '/usr/bin/wp', '/opt/homebrew/bin/wp' );
		/**
		 * Filters the WP-CLI executables to try, in order.
		 *
		 * @param string[] $candidates Absolute paths.
		 */
		foreach ( (array) apply_filters( 'site_agent_wp_cli_binary', $candidates ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	public static function cli_timeout(): int {
		$timeout = defined( 'SITE_AGENT_WP_CLI_TIMEOUT' ) ? (int) SITE_AGENT_WP_CLI_TIMEOUT : 20;
		/**
		 * Filters the foreground WP-CLI time limit in seconds (1 to 300).
		 *
		 * @param int $timeout Seconds.
		 */
		return max( 1, min( 300, (int) apply_filters( 'site_agent_wp_cli_timeout', $timeout ) ) );
	}

	public static function cli( array $input ) {
		$binary = self::cli_binary();
		if ( '' === $binary || ! Process::available() ) {
			return new \WP_Error( 'cli_unavailable', __( 'WP-CLI and process execution must be available on the server. Set SITE_AGENT_WP_CLI to the wp executable if it is installed elsewhere.', 'site-agent' ) );
		}
		$args = $input['arguments'];
		if ( empty( $args ) || count( $args ) > 40 ) {
			return new \WP_Error( 'invalid_arguments', __( 'Supply between 1 and 40 WP-CLI arguments.', 'site-agent' ) );
		}
		foreach ( $args as $argument ) {
			// @alias arguments would load connection settings (including SSH targets) from wp-cli.yml.
			if ( ! is_string( $argument ) || false !== strpos( $argument, "\0" ) || 0 === strpos( $argument, '@' ) || preg_match( '/^--(?:path|url|user|ssh|http|require|exec|allow-root)(?:=|$)/', $argument ) ) {
				return new \WP_Error( 'protected_argument', __( 'Connection, identity, alias and bootstrap arguments cannot be overridden.', 'site-agent' ) );
			}
		}
		$command = array_merge( array( $binary, '--path=' . ABSPATH, '--url=' . home_url( '/' ), '--user=' . get_current_user_id(), '--no-color', '--skip-plugins=' . basename( dirname( SITE_AGENT_FILE ) ) ), $args );
		$result  = Process::run( $command, self::cli_timeout(), ABSPATH, self::environment() );
		if ( ! $result['started'] ) {
			return new \WP_Error( 'cli_failed', __( 'The WP-CLI process could not start.', 'site-agent' ) );
		}
		unset( $result['started'] );
		return $result;
	}

	/**
	 * Inherit the server environment, but make sure WP-CLI can find PHP and a home directory.
	 * PHP-FPM clears the environment by default, which breaks the phar's "env php" line.
	 *
	 * @return array<string, string>
	 */
	private static function environment(): array {
		$env         = getenv();
		$env         = is_array( $env ) ? $env : array();
		$php         = Health::php_binary();
		$paths       = array_merge(
			'' === $php ? array() : array( dirname( $php ) ),
			array_filter( explode( PATH_SEPARATOR, (string) ( $env['PATH'] ?? '' ) ) ),
			array( PHP_BINDIR, '/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin' )
		);
		$env['PATH'] = implode( PATH_SEPARATOR, array_values( array_unique( $paths ) ) );
		if ( empty( $env['HOME'] ) ) {
			$env['HOME'] = untrailingslashit( get_temp_dir() );
		}
		return $env;
	}
}
