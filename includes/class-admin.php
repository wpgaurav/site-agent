<?php
/**
 * Settings and client connection instructions.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Settings and client connection instructions. */
final class Admin {
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_site_agent_license', array( self::class, 'license_action' ) );
		add_filter(
			'option_page_capability_site_agent',
			static function () {
				return is_multisite() ? 'manage_network_options' : 'manage_options';
			}
		);
	}

	public static function menu(): void {
		add_management_page( __( 'Site Agent', 'site-agent' ), __( 'Site Agent', 'site-agent' ), is_multisite() ? 'manage_network_options' : 'manage_options', 'site-agent', array( self::class, 'render' ) );
	}

	public static function settings(): void {
		register_setting(
			'site_agent',
			Config::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Config::class, 'sanitize' ),
				'default'           => Config::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	public static function render(): void {
		if ( ! Permissions::administrator() ) {
			wp_die( esc_html__( 'Site Agent requires site administration rights, or super administrator rights on multisite.', 'site-agent' ) );
		}
		$config     = Config::get();
		$controls   = array(
			'enabled'       => array( __( 'Enable Site Agent', 'site-agent' ), __( 'Allow authenticated administrators to connect and use the tools selected below.', 'site-agent' ) ),
			'content_write' => array( __( 'Content writes', 'site-agent' ), __( 'Create drafts and edit posts or pages. Publishing requires an explicit tool argument.', 'site-agent' ) ),
			'file_read'     => array( __( 'Source inspection', 'site-agent' ), __( 'Read plugin and theme source files. Source files can contain sensitive data.', 'site-agent' ) ),
			'file_write'    => array( __( 'Source editing', 'site-agent' ), __( 'Create or overwrite plugin and theme files, including PHP. Incorrect code can break the site.', 'site-agent' ) ),
			'php_execute'   => array( __( 'PHP execution', 'site-agent' ), __( 'Run PHP inside WordPress with the server process privileges. This is not a sandbox and can modify files, the database, and Site Agent itself.', 'site-agent' ) ),
			'cli_execute'   => array( __( 'WP-CLI execution', 'site-agent' ), __( 'Run foreground WP-CLI commands with a 20-second limit. This grants full developer access, including code execution and database changes.', 'site-agent' ) ),
			'audit_enabled' => array( __( 'Audit history', 'site-agent' ), __( 'Keep the last 100 tool calls: time, user ID, tool name, result status, and duration. Arguments, source code, outputs, IP addresses, and credentials are not logged.', 'site-agent' ) ),
			'delete_data'   => array( __( 'Delete data on uninstall', 'site-agent' ), __( 'Remove Site Agent settings and audit history when deleting the plugin.', 'site-agent' ) ),
		);
		$endpoint   = rest_url( 'site-agent/v1/mcp' );
		$connection = array(
			'mcpServers' => array(
				'site-agent' => array(
					'url'     => $endpoint,
					'headers' => array( 'Authorization' => 'Basic BASE64_OF_USERNAME_COLON_APPLICATION_PASSWORD' ),
				),
			),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Agent', 'site-agent' ); ?></h1>
			<p><?php esc_html_e( 'Connect your AI client directly to this WordPress installation. Site Agent has no hosted proxy and makes no telemetry requests.', 'site-agent' ); ?></p>
			<?php if ( Config::locked() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'SITE_AGENT_DISABLED is active. All Site Agent access is disabled.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Config::code_locked() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WordPress file modification or editing is disabled. Source inspection, source editing, PHP, and WP-CLI tools are blocked.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! function_exists( 'wp_register_ability' ) || ! is_readable( SITE_AGENT_DIR . 'runtime/autoload.php' ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Site Agent requires WordPress 6.9 or newer and the complete release ZIP with its bundled runtime.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'site_agent' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $controls as $key => $control ) : ?>
						<tr><th scope="row"><?php echo esc_html( $control[0] ); ?></th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Config::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $config[ $key ] ) ); ?>> <?php echo esc_html( $control[0] ); ?></label>
							<p class="description"><?php echo esc_html( $control[1] ); ?></p>
						</td></tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::license_panel(); ?>
			<h2><?php esc_html_e( 'Connect a client', 'site-agent' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Enable Site Agent and save the selected tools.', 'site-agent' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create a dedicated WordPress Application Password in your profile.', 'site-agent' ); ?></a></li>
				<li><?php esc_html_e( 'Use the endpoint below in a client that supports Streamable HTTP and a custom Authorization header. The header uses HTTP Basic authentication with your username and Application Password. Keep the client configuration private.', 'site-agent' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'Endpoint', 'site-agent' ); ?>:</strong> <code><?php echo esc_html( $endpoint ); ?></code></p>
			<p><?php esc_html_e( 'Remote connections require HTTPS. Plain HTTP is accepted only when WordPress identifies the installation as local. Clients that require OAuth need a compatible Application Password bridge; Site Agent does not provide OAuth.', 'site-agent' ); ?></p>
			<pre style="padding:16px;background:#fff;border:1px solid #c3c4c7;overflow:auto;"><?php echo esc_html( wp_json_encode( $connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<p><?php esc_html_e( 'Each tool switch controls that entry point. PHP execution, executable source editing, and WP-CLI can change other settings or files, so these switches are not isolation boundaries. Use a backed-up development or staging site for developer tools.', 'site-agent' ); ?></p>
			<p><?php esc_html_e( 'To revoke remote access, disable Site Agent or revoke its Application Password in your profile. For an emergency stop, set SITE_AGENT_DISABLED to true in wp-config.php. Deactivation also disables access until you enable it again.', 'site-agent' ); ?></p>
			<h2><?php esc_html_e( 'Recent tool calls', 'site-agent' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time (UTC)', 'site-agent' ); ?></th><th><?php esc_html_e( 'User ID', 'site-agent' ); ?></th><th><?php esc_html_e( 'Tool', 'site-agent' ); ?></th><th><?php esc_html_e( 'Result', 'site-agent' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_reverse( (array) get_option( Audit::OPTION, array() ) ) as $row ) : ?>
					<tr><td><?php echo esc_html( $row['time'] ); ?></td><td><?php echo esc_html( (string) $row['user_id'] ); ?></td><td><?php echo esc_html( $row['tool'] ); ?></td><td><?php echo esc_html( $row['success'] ? __( 'Success', 'site-agent' ) : __( 'Failed', 'site-agent' ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	public static function license_action(): void {
		if ( ! Permissions::administrator() ) {
			wp_die( esc_html__( 'You cannot manage the Site Agent update license.', 'site-agent' ) );
		}
		check_admin_referer( 'site_agent_license' );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'activate' === $operation ) {
			$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			$result = License::activate( $key );
		} elseif ( 'check' === $operation ) {
			$result = License::check();
		} elseif ( 'disconnect' === $operation ) {
			$result = License::disconnect();
		} else {
			$result = new \WP_Error( 'invalid_operation' );
		}
		wp_safe_redirect( add_query_arg( 'license_result', is_wp_error( $result ) ? 'failed' : 'success', admin_url( 'tools.php?page=site-agent' ) ) );
		exit;
	}

	private static function license_panel(): void {
		$state = License::state();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status from the nonce-protected redirect; it grants no permissions.
		$result = isset( $_GET['license_result'] ) ? sanitize_key( wp_unslash( $_GET['license_result'] ) ) : '';
		?>
		<section style="margin-block:32px;padding:24px;background:#fff;border:1px solid #c3c4c7;">
			<h2><?php esc_html_e( 'Automatic updates', 'site-agent' ); ?></h2>
			<p><?php esc_html_e( 'Activate the free FluentCart license from your Site Agent checkout to receive automatic updates. Plugin functionality remains available without a license.', 'site-agent' ); ?></p>
			<p><a href="<?php echo esc_url( License::PRODUCT ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a free update license', 'site-agent' ); ?></a></p>
			<?php if ( $result ) : ?>
				<div class="notice <?php echo 'success' === $result ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( 'success' === $result ? __( 'The license operation completed.', 'site-agent' ) : __( 'The license operation failed. Verify the license and server connection, then try again. Previous credentials are retained on network errors.', 'site-agent' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $state ) : ?>
				<p><strong><?php esc_html_e( 'Status', 'site-agent' ); ?>:</strong> <?php echo esc_html( 'valid' === ( $state['status'] ?? '' ) && License::credentials() ? __( 'Active', 'site-agent' ) : __( 'Activation required or inactive', 'site-agent' ) ); ?> · <strong><?php esc_html_e( 'Key ending', 'site-agent' ); ?>:</strong> <?php echo esc_html( $state['suffix'] ?? '' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="site_agent_license">
				<?php wp_nonce_field( 'site_agent_license' ); ?>
				<?php if ( ! $state ) : ?>
					<label for="site-agent-license-key"><?php esc_html_e( 'License key', 'site-agent' ); ?></label>
					<input id="site-agent-license-key" type="password" name="license_key" class="regular-text" autocomplete="off" maxlength="256" required>
					<button class="button button-primary" name="operation" value="activate"><?php esc_html_e( 'Activate license', 'site-agent' ); ?></button>
				<?php else : ?>
					<button class="button" name="operation" value="check"><?php esc_html_e( 'Check status', 'site-agent' ); ?></button>
					<button class="button" name="operation" value="disconnect"><?php esc_html_e( 'Disconnect this site', 'site-agent' ); ?></button>
				<?php endif; ?>
			</form>
			<p class="description"><?php esc_html_e( 'Activation sends the license key, site URL, plugin version, WordPress version and PHP version to gauravtiwari.org. Credentials are encrypted and bound to this site. Activated licenses are checked when WordPress checks updates.', 'site-agent' ); ?></p>
		</section>
		<?php
	}
}
