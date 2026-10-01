<?php
/**
 * PHP change safety: compile checks before writing and loopback checks after.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Lint input is a private temporary file read only by the PHP CLI.
/** Catch PHP errors the tokenizer cannot see, and detect a site that stopped loading after a change. */
final class Health {
	const TIMEOUT        = 15;
	const BINARY_CACHE   = 'site_agent_php_binary';
	const FATAL_TYPES    = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
	const SCRAPE_PATTERN = '###### wp_scraping_result_%s:%s ######';

	/** A PHP CLI binary matching the running major.minor version, or an empty string. */
	public static function php_binary(): string {
		if ( defined( 'SITE_AGENT_PHP_BINARY' ) ) {
			$binary = (string) SITE_AGENT_PHP_BINARY;
			return is_file( $binary ) && is_executable( $binary ) ? $binary : '';
		}
		if ( 'cli' === PHP_SAPI && is_file( PHP_BINARY ) && is_executable( PHP_BINARY ) ) {
			return PHP_BINARY;
		}
		if ( ! Process::available() || '' !== (string) ini_get( 'open_basedir' ) ) {
			return '';
		}
		$cached = get_transient( self::BINARY_CACHE );
		if ( is_array( $cached ) && PHP_VERSION === ( $cached['version'] ?? '' ) ) {
			return (string) $cached['path'];
		}
		$path    = '';
		$version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( array_unique( array( PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php' ) ) as $candidate ) {
			if ( ! is_file( $candidate ) || ! is_executable( $candidate ) ) {
				continue;
			}
			// A different CLI version could reject valid syntax or accept invalid syntax.
			$probe = Process::run( array( $candidate, '-n', '-r', 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' ), 5 );
			if ( 0 === $probe['exit_code'] && trim( $probe['stdout'] ) === $version ) {
				$path = $candidate;
				break;
			}
		}
		set_transient(
			self::BINARY_CACHE,
			array(
				'version' => PHP_VERSION,
				'path'    => $path,
			),
			DAY_IN_SECONDS
		);
		return $path;
	}

	/**
	 * Compile-check PHP source without running it.
	 *
	 * @param string $code  Complete file contents.
	 * @param string $label Path shown in error messages.
	 * @return true|null|\WP_Error True when it compiles, null when no matching CLI binary exists.
	 */
	public static function lint( string $code, string $label ) {
		$binary = self::php_binary();
		if ( '' === $binary ) {
			return null;
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( 'site-agent-lint.php' );
		if ( ! $file || false === file_put_contents( $file, $code ) ) {
			return null;
		}
		try {
			$result = Process::run( array( $binary, '-n', '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $file ), 10 );
		} finally {
			wp_delete_file( $file );
		}
		if ( ! $result['started'] || $result['timed_out'] ) {
			return null;
		}
		if ( 0 === $result['exit_code'] ) {
			return true;
		}
		$output = str_replace( $file, $label, $result['stdout'] . "\n" . $result['stderr'] );
		// Greedy up to the last "in <file> on line": messages such as "previously declared in <file>:2" contain " in " too.
		if ( preg_match( '/(?:Parse|Fatal) error:\s*(.+) in ' . preg_quote( $label, '/' ) . ' on line (\d+)/', $output, $match ) ) {
			return new \WP_Error(
				'invalid_php',
				/* translators: 1: line number, 2: PHP compiler message. */
				sprintf( __( 'PHP compile error on line %1$d: %2$s. The file was not changed.', 'site-agent' ), (int) $match[2], $match[1] )
			);
		}
		return null;
	}

	/**
	 * Load the site with WordPress's edit-scrape key, as the core plugin editor does.
	 *
	 * WordPress prints the fatal error, if any, between markers at shutdown and suppresses its
	 * recovery-mode email for these requests. Scraping starts after must-use plugins load, so a
	 * server error without markers counts as fatal only for mu-plugins changes.
	 *
	 * @param string $path Changed path relative to wp-content.
	 * @return array{status: string, error?: string} Status is ok, fatal, unverified or skipped.
	 */
	public static function check( string $path ): array {
		/**
		 * Filters whether to request the site after a PHP change and revert fatal changes.
		 *
		 * @param bool   $enabled Whether to run the check.
		 * @param string $path    Changed path relative to wp-content.
		 */
		if ( ! apply_filters( 'site_agent_health_check', true, $path ) ) {
			return array( 'status' => 'skipped' );
		}
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 32, false );
		set_transient( 'scrape_key_' . $key, $nonce, 2 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 2 * MINUTE_IN_SECONDS );
		}
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		/**
		 * Filters the URLs loaded after a PHP change.
		 *
		 * @param string[] $urls Front end, admin AJAX (admin context) and the MCP route.
		 */
		$urls   = (array) apply_filters( 'site_agent_health_check_urls', array( home_url( '/' ), admin_url( 'admin-ajax.php' ), rest_url( 'site-agent/v1/mcp' ) ) );
		$result = array( 'status' => 'ok' );
		try {
			foreach ( $urls as $url ) {
				$outcome = self::probe( (string) $url, $key, $nonce, 0 === strpos( $path, 'mu-plugins/' ) );
				if ( 'fatal' === $outcome['status'] ) {
					return $outcome;
				}
				if ( 'unreachable' === $outcome['status'] ) {
					// Later loopbacks would wait for the same timeout.
					return array( 'status' => 'unverified' );
				}
				if ( 'ok' !== $outcome['status'] ) {
					$result = array( 'status' => 'unverified' );
				}
			}
		} finally {
			delete_transient( 'scrape_key_' . $key );
		}
		return $result;
	}

	/**
	 * Request one URL and read WordPress's scrape result.
	 *
	 * @return array{status: string, error?: string}
	 */
	private static function probe( string $url, string $key, string $nonce, bool $early ): array {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'wp_scrape_key'   => $key,
					'wp_scrape_nonce' => $nonce,
				),
				$url
			),
			array(
				'timeout'   => self::TIMEOUT,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unreachable' );
		}
		$body  = (string) wp_remote_retrieve_body( $response );
		$start = sprintf( self::SCRAPE_PATTERN, 'start', $key );
		$end   = sprintf( self::SCRAPE_PATTERN, 'end', $key );
		$from  = strpos( $body, $start );
		if ( false === $from ) {
			if ( $early && (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
				return array(
					'status' => 'fatal',
					'error'  => __( 'The site returned a server error before WordPress could report details.', 'site-agent' ),
				);
			}
			return array( 'status' => 'unverified' );
		}
		$json = substr( $body, $from + strlen( $start ) );
		$to   = strpos( $json, $end );
		$data = json_decode( trim( false === $to ? $json : substr( $json, 0, $to ) ), true );
		if ( true === $data ) {
			return array( 'status' => 'ok' );
		}
		if ( is_array( $data ) && isset( $data['message'], $data['type'] ) && in_array( (int) $data['type'], self::FATAL_TYPES, true ) ) {
			return array(
				'status' => 'fatal',
				/* translators: 1: PHP error message, 2: file path, 3: line number. */
				'error'  => sprintf( __( '%1$s in %2$s on line %3$d', 'site-agent' ), Errors::relative( (string) $data['message'] ), Errors::relative( (string) ( $data['file'] ?? '' ) ), (int) ( $data['line'] ?? 0 ) ),
			);
		}
		return array( 'status' => 'unverified' );
	}
}
