<?php
/**
 * Connection and environment checks for the settings screen.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Surface the setup problems users hit before anything else. */
final class Diagnostics {
	/**
	 * Server-side checks. Status is ok, warning or error.
	 *
	 * @return array<int, array{label: string, status: string, detail: string}>
	 */
	public static function checks(): array {
		$config = Config::get();
		$checks = array();

		$runtime  = function_exists( 'wp_register_ability' ) && is_readable( SITE_AGENT_DIR . 'runtime/autoload.php' );
		$checks[] = self::row(
			__( 'MCP runtime', 'site-agent' ),
			$runtime ? 'ok' : 'error',
			$runtime ? __( 'The Abilities API and bundled MCP runtime are available.', 'site-agent' ) : __( 'Site Agent requires WordPress 6.9 or newer and the complete release ZIP with its bundled runtime.', 'site-agent' )
		);

		$tools    = count( Abilities::enabled_definitions() );
		$checks[] = self::row(
			__( 'Endpoint', 'site-agent' ),
			$tools ? 'ok' : 'warning',
			$tools
				/* translators: %d: number of tools. */
				? sprintf( _n( '%d tool is enabled.', '%d tools are enabled.', $tools, 'site-agent' ), $tools )
				: __( 'Site Agent is disabled, so the MCP endpoint is not registered.', 'site-agent' )
		);

		$checks[] = self::https();

		$user = wp_get_current_user();
		if ( ! wp_is_application_passwords_available() ) {
			$checks[] = self::row( __( 'Application Passwords', 'site-agent' ), 'error', __( 'Application Passwords are turned off on this site, often by a security plugin. Header and URL authentication both need them.', 'site-agent' ) );
		} elseif ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$checks[] = self::row( __( 'Application Passwords', 'site-agent' ), 'error', __( 'Application Passwords are disabled for your account.', 'site-agent' ) );
		} else {
			$count    = count( \WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
			$checks[] = self::row(
				__( 'Application Passwords', 'site-agent' ),
				$count ? 'ok' : 'warning',
				$count
					/* translators: %d: number of Application Passwords. */
					? sprintf( _n( 'You have %d Application Password. Use the test below to confirm the server passes it to WordPress.', 'You have %d Application Passwords. Use the test below to confirm the server passes them to WordPress.', $count, 'site-agent' ), $count )
					: __( 'You have no Application Passwords yet. Create a dedicated one in your profile.', 'site-agent' )
			);
		}

		if ( $config['cli_execute'] ) {
			$binary   = Developer::cli_binary();
			$ready    = '' !== $binary && Process::available();
			$checks[] = self::row(
				__( 'WP-CLI', 'site-agent' ),
				$ready ? 'ok' : 'error',
				$ready
					/* translators: 1: executable path, 2: seconds. */
					? sprintf( __( 'Found %1$s. Commands time out after %2$d seconds.', 'site-agent' ), $binary, Developer::cli_timeout() )
					: __( 'WP-CLI or proc_open is unavailable. Define SITE_AGENT_WP_CLI in wp-config.php with the path to wp.', 'site-agent' )
			);
		}

		if ( $config['file_write'] ) {
			$php      = Health::php_binary();
			$checks[] = self::row(
				__( 'PHP compile check', 'site-agent' ),
				'' !== $php ? 'ok' : 'warning',
				'' !== $php
					/* translators: %s: executable path. */
					? sprintf( __( 'PHP files are compile-checked with %s before they are written.', 'site-agent' ), $php )
					: sprintf(
						/* translators: %s: PHP version. */
						__( 'No PHP %s command-line binary was found, so only the syntax check and the post-save site check run. Define SITE_AGENT_PHP_BINARY to enable compile checks.', 'site-agent' ),
						PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
					)
			);
		}

		if ( $config['file_read'] || $config['file_write'] ) {
			$links = self::symlinks();
			if ( $links ) {
				$checks[] = self::row(
					__( 'Symlinked folders', 'site-agent' ),
					'warning',
					/* translators: %s: comma-separated paths. */
					sprintf( __( 'File tools cannot open symlinked folders: %s.', 'site-agent' ), implode( ', ', $links ) )
				);
			}
		}

		if ( Config::execution_blocked() ) {
			$checks[] = self::row( __( 'Execution', 'site-agent' ), 'warning', __( 'SITE_AGENT_ALLOW_EXECUTION is false in wp-config.php, so source editing, PHP and WP-CLI are blocked.', 'site-agent' ) );
		}
		return $checks;
	}

	/**
	 * Whether WordPress detects HTTPS, with a proxy hint.
	 *
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function https(): array {
		$label = __( 'HTTPS', 'site-agent' );
		if ( is_ssl() ) {
			return self::row( $label, 'ok', __( 'WordPress detects HTTPS.', 'site-agent' ) );
		}
		if ( 'local' === wp_get_environment_type() ) {
			return self::row( $label, 'warning', __( 'This is a local environment, so plain HTTP is accepted.', 'site-agent' ) );
		}
		$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) : '';
		if ( 'https' === $proto ) {
			return self::row( $label, 'error', __( 'Your proxy serves HTTPS, but WordPress sees HTTP, so remote connections are refused. Add this to wp-config.php above the "stop editing" line: if ( isset( $_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \'https\' === $_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) { $_SERVER[\'HTTPS\'] = \'on\'; }', 'site-agent' ) );
		}
		return self::row( $label, 'error', __( 'WordPress does not detect HTTPS, so remote connections are refused until the site is served over HTTPS.', 'site-agent' ) );
	}

	/**
	 * Top-level symlinked plugin, theme and must-use plugin folders.
	 *
	 * @return string[]
	 */
	private static function symlinks(): array {
		$links = array();
		foreach ( Files::ROOTS as $root ) {
			$directory = WP_CONTENT_DIR . '/' . $root;
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			foreach ( (array) scandir( $directory ) as $name ) {
				if ( is_string( $name ) && '.' !== $name[0] && is_link( $directory . '/' . $name ) ) {
					$links[] = $root . '/' . $name;
				}
			}
		}
		return array_slice( $links, 0, 10 );
	}

	/**
	 * One diagnostic row.
	 *
	 * @return array{label: string, status: string, detail: string}
	 */
	private static function row( string $label, string $status, string $detail ): array {
		return array(
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
	}
}
