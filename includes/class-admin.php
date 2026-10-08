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
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_site_agent_license', array( self::class, 'license_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITE_AGENT_FILE ), array( self::class, 'action_links' ) );
		add_filter(
			'option_page_capability_site_agent',
			static function () {
				return is_multisite() ? 'manage_network_options' : 'manage_options';
			}
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=site-agent' ) ) . '">' . esc_html__( 'Settings', 'site-agent' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( 'tools_page_site-agent' !== $hook || ! Permissions::administrator() ) {
			return;
		}
		wp_enqueue_script( 'site-agent-auth-converter', plugins_url( 'assets/admin-auth-converter.js', SITE_AGENT_FILE ), array(), SITE_AGENT_VERSION, true );
		wp_localize_script(
			'site-agent-auth-converter',
			'SiteAgentAuthConverter',
			array(
				'generated'          => __( 'Token generated. Copy the value you need into your MCP client.', 'site-agent' ),
				'required'           => __( 'Enter your WordPress username and Application Password.', 'site-agent' ),
				'username'           => __( 'A Basic authentication username cannot contain a colon.', 'site-agent' ),
				'copied'             => __( 'Copied to clipboard.', 'site-agent' ),
				'copy_failed'        => __( 'Clipboard access is unavailable. Show the token, select it and copy it manually.', 'site-agent' ),
				'cleared'            => __( 'Credentials and generated token cleared.', 'site-agent' ),
				'show'               => __( 'Show token', 'site-agent' ),
				'hide'               => __( 'Hide token', 'site-agent' ),
				'url_auth'           => (bool) Config::get()['url_auth'],
				// wp_localize_script() turns booleans into strings.
				'app_passwords'      => wp_is_application_passwords_available_for_user( wp_get_current_user() ) ? 'yes' : 'no',
				'testing'            => __( 'Testing the connection…', 'site-agent' ),
				/* translators: %d: number of tools. */
				'test_ok'            => __( 'Connected. %d tools are available to this password.', 'site-agent' ),
				'test_url_ok'        => __( 'The authenticated URL also works.', 'site-agent' ),
				'test_url_failed'    => __( 'The authenticated URL did not connect.', 'site-agent' ),
				'test_credentials'   => __( 'WordPress rejected this username or Application Password.', 'site-agent' ),
				'test_disabled'      => __( 'Application Passwords are disabled on this site or for this account.', 'site-agent' ),
				'test_stripped'      => __( 'WordPress received no credentials, so the server is removing the Authorization header. Enable URL authentication, or ask your host to pass the Authorization header to PHP.', 'site-agent' ),
				'test_administrator' => __( 'This account is not an administrator (or super administrator on multisite).', 'site-agent' ),
				'test_https'         => __( 'WordPress does not detect HTTPS, so connections are refused. See the HTTPS diagnostic above.', 'site-agent' ),
				'test_missing'       => __( 'The MCP endpoint is not registered. Enable Site Agent and at least one tool, then save.', 'site-agent' ),
				'test_network'       => __( 'The test request could not reach the endpoint.', 'site-agent' ),
				/* translators: %d: HTTP status code. */
				'test_failed'        => __( 'The connection failed with HTTP %d.', 'site-agent' ),
			)
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
		register_setting(
			'site_agent',
			Scopes::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Scopes::class, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Settings switches with labels and descriptions.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function controls(): array {
		$cli = sprintf(
			/* translators: %d: seconds. */
			__( 'Run foreground WP-CLI commands with a %d-second limit. This grants full developer access, including code execution and database changes.', 'site-agent' ),
			Developer::cli_timeout()
		);
		return array(
			'enabled'       => array( __( 'Enable Site Agent', 'site-agent' ), __( 'Allow authenticated administrators to connect and use the tools selected below.', 'site-agent' ) ),
			'url_auth'      => array( __( 'URL authentication', 'site-agent' ), __( 'Not recommended. Allow Base64-encoded username and Application Password credentials in the MCP endpoint auth query parameter. The password is only encoded, and URLs can be recorded in client history, chats and server logs. Use OAuth connections instead whenever the client supports them.', 'site-agent' ) ),
			'oauth'         => array( __( 'OAuth connections (recommended)', 'site-agent' ), __( 'On by default. Let MCP clients that support OAuth, such as Claude and ChatGPT connectors, connect by signing in here. An administrator approves each client on a consent screen and picks its tool groups. Clients get one-hour access tokens and refresh tokens that end after 30 days without use. No password is shared with the client.', 'site-agent' ) ),
			'content_write' => array( __( 'Content writes', 'site-agent' ), __( 'Create drafts, edit posts or pages, set terms, featured images and SEO fields, and import media. Edits to live posts are staged as autosaves unless a status is passed explicitly.', 'site-agent' ) ),
			'file_read'     => array( __( 'Source inspection', 'site-agent' ), __( 'Read plugin, theme and must-use plugin source files. Source files can contain sensitive data.', 'site-agent' ) ),
			'file_write'    => array( __( 'Source editing', 'site-agent' ), __( 'Create, overwrite, move or delete plugin and theme files, including PHP. PHP changes that cause a fatal error are reverted when the site can be checked.', 'site-agent' ) ),
			'php_execute'   => array( __( 'PHP execution', 'site-agent' ), __( 'Run PHP inside WordPress with the server process privileges. This is not a sandbox and can modify files, the database, and Site Agent itself.', 'site-agent' ) ),
			'cli_execute'   => array( __( 'WP-CLI execution', 'site-agent' ), $cli ),
			'bricks'        => array( __( 'Bricks tools', 'site-agent' ), __( 'Serve Bricks Builder\'s own abilities (Bricks 2.4 or later, with AI/MCP enabled in Bricks > Settings > AI): its fast-path tools directly and the rest through one dispatcher. They can change pages, templates, global classes, variables, theme styles and Bricks settings. Bricks\' own switches and permission checks still apply, and its PHP ability also needs PHP execution.', 'site-agent' ) ),
			'audit_enabled' => array( __( 'Audit history', 'site-agent' ), __( 'Keep the last 100 tool calls: time, user ID, tool, target (post ID, file path or WP-CLI command name), Application Password name, result and duration. Content, code, other arguments, outputs, IP addresses and credential secrets are not logged.', 'site-agent' ) ),
			'delete_data'   => array( __( 'Delete data on uninstall', 'site-agent' ), __( 'Remove Site Agent settings, password limits, audit history and the update license when deleting the plugin.', 'site-agent' ) ),
		);
	}

	public static function render(): void {
		if ( ! Permissions::administrator() ) {
			wp_die( esc_html__( 'Site Agent requires site administration rights, or super administrator rights on multisite.', 'site-agent' ) );
		}
		$config     = Config::get();
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
			<?php settings_errors(); ?>
			<p><?php esc_html_e( 'Connect your AI client directly to this WordPress installation. Site Agent has no hosted proxy and makes no telemetry requests.', 'site-agent' ); ?></p>
			<?php if ( Config::locked() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'SITE_AGENT_DISABLED is active. All Site Agent access is disabled.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Config::code_locked() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WordPress file modification or editing is disabled. Source inspection, source editing, PHP, and WP-CLI tools are blocked.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Config::execution_blocked() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'SITE_AGENT_ALLOW_EXECUTION is false in wp-config.php. Source editing, PHP, and WP-CLI tools are blocked on this site.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<?php self::connection_warnings( $config ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'site_agent' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( self::controls() as $key => $control ) : ?>
						<tr><th scope="row"><?php echo esc_html( $control[0] ); ?></th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( Config::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $config[ $key ] ) ); ?>> <?php echo esc_html( $control[0] ); ?></label>
							<p class="description"><?php echo esc_html( $control[1] ); ?></p>
							<?php if ( 'bricks' === $key && ! Config::group_available( $key ) ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Unavailable: Bricks abilities are not active. They need the Bricks theme 2.4 or later with AI/MCP enabled in Bricks > Settings > AI.', 'site-agent' ); ?></strong></p>
							<?php elseif ( ! Config::group_available( $key ) ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Blocked by a wp-config.php constant on this site.', 'site-agent' ); ?></strong></p>
							<?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
				</table>
				<?php self::scopes_panel(); ?>
				<?php submit_button(); ?>
			</form>
			<?php self::oauth_panel(); ?>
			<?php self::partners_panel(); ?>
			<?php self::diagnostics_panel(); ?>
			<?php self::license_panel(); ?>
			<h2><?php esc_html_e( 'Connect a client', 'site-agent' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Enable Site Agent and the tools you need, and keep OAuth connections on. Save.', 'site-agent' ); ?></li>
				<li><?php esc_html_e( 'Add the endpoint below to your MCP client, such as a Claude or ChatGPT connector, Claude Code or Cursor, with no credentials.', 'site-agent' ); ?></li>
				<li><?php esc_html_e( 'The client opens a WordPress sign-in and consent page. Check the client name, choose the tool groups it may use, and allow it.', 'site-agent' ); ?></li>
			</ol>
			<p><strong><?php esc_html_e( 'Endpoint', 'site-agent' ); ?>:</strong> <code><?php echo esc_html( $endpoint ); ?></code></p>
			<h2><?php esc_html_e( 'Application Passwords (not recommended)', 'site-agent' ); ?></h2>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Use an Application Password only for a client that cannot sign in with OAuth. It acts as your account until you revoke it, every client configuration that holds it can leak it, and a password in a URL can also end up in history, chats and server logs. OAuth tokens expire, can be limited per client, and never reveal a password.', 'site-agent' ); ?></p></div>
			<ol>
				<li><a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create a dedicated WordPress Application Password in your profile, one per client.', 'site-agent' ); ?></a></li>
				<li><?php esc_html_e( 'Use the endpoint above in a client that supports Streamable HTTP and a custom Authorization header. The header uses HTTP Basic authentication with your username and Application Password. Keep the client configuration private, and limit the password\'s tools above.', 'site-agent' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Remote connections require HTTPS. Plain HTTP is accepted only when WordPress identifies the installation as local. Clients that require OAuth can connect with the endpoint alone once OAuth connections are on: they find the sign-in page through the endpoint\'s OAuth metadata.', 'site-agent' ); ?></p>
			<section id="site-agent-url-auth-guide" aria-labelledby="site-agent-url-auth-heading" style="margin-block:24px;padding:24px;background:#fff;border:1px solid #c3c4c7;">
				<h3 id="site-agent-url-auth-heading"><?php esc_html_e( 'Connect with an authenticated URL', 'site-agent' ); ?></h3>
				<p><?php esc_html_e( 'If your MCP client cannot send a custom Authorization header, include your credentials in the endpoint URL instead.', 'site-agent' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Enable Site Agent and URL authentication above, select the tools you need, then save your settings.', 'site-agent' ); ?></li>
					<li><a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create a dedicated Application Password in your administrator profile.', 'site-agent' ); ?></a></li>
					<li><?php esc_html_e( 'Enter your WordPress username and that Application Password in the converter below. Click Generate token, then Copy authenticated endpoint.', 'site-agent' ); ?></li>
					<li><?php esc_html_e( 'Paste the copied URL into your client as a Streamable HTTP MCP endpoint. The client must retain the auth query parameter on every request; a separate Authorization header is not required.', 'site-agent' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Example format only: BASE64_TOKEN is a placeholder for the encoded username:application-password value. The copy button generates and URL-encodes it for you.', 'site-agent' ); ?></p>
				<pre style="padding:16px;background:#f6f7f7;overflow:auto;"><code><?php echo esc_html( add_query_arg( 'auth', 'BASE64_TOKEN', $endpoint ) ); ?></code></pre>
				<p><strong><?php esc_html_e( 'Keep the complete URL private.', 'site-agent' ); ?></strong> <?php esc_html_e( 'Base64 is reversible. This URL contains credentials and may appear in browser history, client configuration or server logs. Site Agent removes it from the request before other plugins run their late logging, but earlier server logs can still record it. Use a dedicated Application Password and clear the converter after copying.', 'site-agent' ); ?></p>
				<p><?php esc_html_e( 'To stop URL-based connections, turn off URL authentication and save. To revoke this credential everywhere, revoke its Application Password in your profile; existing MCP sessions cannot bypass revocation.', 'site-agent' ); ?></p>
				<p><?php esc_html_e( 'A compatible Streamable HTTP MCP client is required. Prefer OAuth connections for clients that support them: no credential appears in a URL. ChatGPT web compatibility with credential-bearing URLs has not been verified.', 'site-agent' ); ?></p>
			</section>
			<?php self::auth_converter(); ?>
			<pre id="site-agent-connection-config" style="padding:16px;background:#fff;border:1px solid #c3c4c7;overflow:auto;"><?php echo esc_html( wp_json_encode( $connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<p><?php esc_html_e( 'Each tool switch controls that entry point. PHP execution, executable source editing, and WP-CLI can change other settings or files, so these switches are not isolation boundaries. Use a backed-up development or staging site for developer tools.', 'site-agent' ); ?></p>
			<p><?php esc_html_e( 'To revoke remote access, disable Site Agent or revoke its Application Password in your profile. For an emergency stop, set SITE_AGENT_DISABLED to true in wp-config.php; set SITE_AGENT_ALLOW_EXECUTION to false to block only source editing, PHP and WP-CLI. Deactivation also disables access until you enable it again.', 'site-agent' ); ?></p>
			<?php self::audit_table(); ?>
		</div>
		<?php
	}

	/**
	 * Warnings when connections bypass OAuth: OAuth off, URL authentication on, or recent calls
	 * made with an Application Password.
	 *
	 * @param array<string, mixed> $config Settings.
	 */
	private static function connection_warnings( array $config ): void {
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		$messages = array();
		if ( empty( $config['oauth'] ) ) {
			$messages[] = __( 'OAuth connections are off, so clients can only connect with an Application Password. Turn OAuth connections on below; it is the recommended way to connect.', 'site-agent' );
		}
		if ( ! empty( $config['url_auth'] ) ) {
			$messages[] = __( 'URL authentication is on. A password in a URL is only encoded and can be recorded in client history, chats and server logs. Reconnect those clients with OAuth, then turn URL authentication off and revoke their Application Passwords.', 'site-agent' );
		}
		$since  = time() - 30 * DAY_IN_SECONDS;
		$labels = array();
		foreach ( (array) get_option( Audit::OPTION, array() ) as $row ) {
			if ( is_array( $row ) && in_array( $row['via'] ?? '', array( 'header', 'url' ), true ) && strtotime( (string) ( $row['time'] ?? '' ) ) >= $since ) {
				$labels[ (string) ( $row['credential'] ?? '' ) ] = true;
			}
		}
		if ( $labels ) {
			$names      = array_filter( array_keys( $labels ) );
			$messages[] = sprintf(
				/* translators: %s: Application Password names. */
				__( 'Tool calls in the last 30 days used Application Passwords (%s). Reconnect those clients with OAuth if they support it, then revoke the passwords in your profile.', 'site-agent' ),
				$names ? implode( ', ', $names ) : __( 'unnamed', 'site-agent' )
			);
		}
		foreach ( $messages as $message ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/** Plugins that add skills for connected agents, with links and their state on this site. */
	private static function partners_panel(): void {
		?>
		<section id="site-agent-plugin-skills" aria-labelledby="site-agent-plugin-skills-heading" style="margin-block:24px;padding:24px;background:#fff;border:1px solid #c3c4c7;">
			<h2 id="site-agent-plugin-skills-heading"><?php esc_html_e( 'Plugin skills', 'site-agent' ); ?></h2>
			<p><?php esc_html_e( 'These plugins hand connected agents a skill for their own workflows. Agents find active ones with list-skills and get-skill, with nothing else to install.', 'site-agent' ); ?></p>
			<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Plugin', 'site-agent' ); ?></th><th><?php esc_html_e( 'What agents can do', 'site-agent' ); ?></th><th><?php esc_html_e( 'On this site', 'site-agent' ); ?></th></tr></thead><tbody>
			<?php foreach ( Skills::partners() as $partner ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $partner['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $partner['name'] ); ?></a></td>
					<td><?php echo esc_html( $partner['does'] ); ?></td>
					<td>
					<?php
					if ( 'active' === $partner['state'] && $partner['skills'] ) {
						/* translators: %s: skill names. */
						echo esc_html( sprintf( __( 'Active. Skills: %s', 'site-agent' ), implode( ', ', $partner['skills'] ) ) );
					} elseif ( 'active' === $partner['state'] ) {
						esc_html_e( 'Active, without a skill yet. Update the plugin, or turn on the module that provides it.', 'site-agent' );
					} elseif ( 'installed' === $partner['state'] ) {
						echo '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Installed, not active', 'site-agent' ) . '</a>';
					} else {
						echo '<a href="' . esc_url( $partner['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get it', 'site-agent' ) . '</a>';
					}
					?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</section>
		<?php
	}

	/** OAuth discovery details and the current user's connected clients, with revoke buttons. */
	private static function oauth_panel(): void {
		if ( ! Config::get()['oauth'] ) {
			return;
		}
		$grants = OAuth::grants( get_current_user_id() );
		?>
		<section id="site-agent-oauth" aria-labelledby="site-agent-oauth-heading" style="margin-block:24px;padding:24px;background:#fff;border:1px solid #c3c4c7;">
			<h2 id="site-agent-oauth-heading"><?php esc_html_e( 'OAuth connections', 'site-agent' ); ?></h2>
			<?php if ( isset( $_GET['oauth_revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a nonce-checked action. ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Access revoked. The client must sign in again to reconnect.', 'site-agent' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Add the endpoint to an OAuth-capable MCP client without any credentials. The client opens a sign-in and consent page here. Approving creates a connection for your account with the tool groups you choose; Site Agent\'s switches above still apply.', 'site-agent' ); ?></p>
			<p><strong><?php esc_html_e( 'Endpoint', 'site-agent' ); ?>:</strong> <code><?php echo esc_html( OAuth::resource() ); ?></code><br><strong><?php esc_html_e( 'Authorization server', 'site-agent' ); ?>:</strong> <code><?php echo esc_html( OAuth::issuer() ); ?></code></p>
			<?php if ( $grants ) : ?>
				<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Client', 'site-agent' ); ?></th><th><?php esc_html_e( 'Tool groups', 'site-agent' ); ?></th><th><?php esc_html_e( 'Connected', 'site-agent' ); ?></th><th><?php esc_html_e( 'Last used', 'site-agent' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'site-agent' ); ?></span></th></tr></thead><tbody>
				<?php foreach ( array_reverse( $grants, true ) as $grant_id => $grant ) : ?>
					<?php $client = OAuth::client( (string) $grant['client_id'] ); ?>
					<tr><td><?php echo esc_html( $client ? $client['client_name'] : __( 'Removed client', 'site-agent' ) ); ?></td><td><?php echo esc_html( $grant['groups'] ? implode( ', ', $grant['groups'] ) : __( 'Read only', 'site-agent' ) ); ?></td><td><?php echo esc_html( wp_date( 'M j, Y', (int) $grant['created'] ) ); ?></td><td><?php echo esc_html( $grant['last_used'] ? wp_date( 'M j, Y H:i', (int) $grant['last_used'] ) : __( 'Not yet', 'site-agent' ) ); ?></td><td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="site_agent_oauth_revoke"><input type="hidden" name="grant" value="<?php echo esc_attr( $grant_id ); ?>"><?php wp_nonce_field( 'site_agent_oauth_revoke' ); ?><button class="button"><?php esc_html_e( 'Revoke', 'site-agent' ); ?></button></form>
					</td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php else : ?>
				<p><?php esc_html_e( 'No OAuth clients are connected to your account.', 'site-agent' ); ?></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Turning off OAuth connections stops every OAuth client at once without deleting connections. Revoking removes a connection for good.', 'site-agent' ); ?></p>
		</section>
		<?php
	}

	/** Per-Application-Password limits for the current user's passwords. */
	private static function scopes_panel(): void {
		if ( ! wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
			return;
		}
		$passwords = \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
		$scopes    = Scopes::all();
		$labels    = self::controls();
		?>
		<h2><?php esc_html_e( 'Application Password access', 'site-agent' ); ?></h2>
		<p><?php esc_html_e( 'Limit what each of your Application Passwords can do, for example content-only for a chat client and developer tools for a local coding agent. A limit can only narrow the switches above. Read-only content tools stay available to every administrator password.', 'site-agent' ); ?></p>
		<?php if ( ! $passwords ) : ?>
			<p><em><?php esc_html_e( 'You have no Application Passwords yet.', 'site-agent' ); ?></em></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped" style="max-width:960px;">
			<thead><tr><th><?php esc_html_e( 'Application Password', 'site-agent' ); ?></th><th><?php esc_html_e( 'Last used', 'site-agent' ); ?></th><th><?php esc_html_e( 'Access', 'site-agent' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $passwords as $password ) : ?>
				<?php
				$uuid    = (string) $password['uuid'];
				$name    = Scopes::OPTION . '[' . $uuid . ']';
				$limited = isset( $scopes[ $uuid ] );
				?>
				<tr>
					<td><strong><?php echo esc_html( $password['name'] ); ?></strong><input type="hidden" name="<?php echo esc_attr( Scopes::OPTION . '[__shown][]' ); ?>" value="<?php echo esc_attr( $uuid ); ?>"></td>
					<td><?php echo esc_html( $password['last_used'] ? wp_date( get_option( 'date_format' ), (int) $password['last_used'] ) : __( 'Never', 'site-agent' ) ); ?></td>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name . '[limited]' ); ?>" value="1" <?php checked( $limited ); ?>> <?php esc_html_e( 'Limit this password to:', 'site-agent' ); ?></label>
						<fieldset style="margin:6px 0 0 24px;">
							<?php foreach ( Scopes::GROUPS as $group ) : ?>
								<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="<?php echo esc_attr( $name . '[groups][]' ); ?>" value="<?php echo esc_attr( $group ); ?>" <?php checked( $limited && in_array( $group, $scopes[ $uuid ], true ) ); ?>> <?php echo esc_html( $labels[ $group ][0] ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function diagnostics_panel(): void {
		$icons = array(
			'ok'      => array( 'yes-alt', '#008a20', __( 'OK', 'site-agent' ) ),
			'warning' => array( 'warning', '#996800', __( 'Warning', 'site-agent' ) ),
			'error'   => array( 'dismiss', '#d63638', __( 'Problem', 'site-agent' ) ),
		);
		?>
		<h2><?php esc_html_e( 'Diagnostics', 'site-agent' ); ?></h2>
		<p><?php esc_html_e( 'Server checks for common connection problems. Generate a token below and use Test connection to confirm the whole path, including whether your server passes the Authorization header to WordPress.', 'site-agent' ); ?></p>
		<table class="widefat striped" style="max-width:960px;"><tbody>
			<?php foreach ( Diagnostics::checks() as $check ) : ?>
				<?php $icon = $icons[ $check['status'] ] ?? $icons['warning']; ?>
				<tr>
					<th scope="row" style="width:200px;"><span class="dashicons dashicons-<?php echo esc_attr( $icon[0] ); ?>" style="color:<?php echo esc_attr( $icon[1] ); ?>;" aria-hidden="true"></span> <?php echo esc_html( $check['label'] ); ?><span class="screen-reader-text"> (<?php echo esc_html( $icon[2] ); ?>)</span></th>
					<td><?php echo esc_html( $check['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function audit_table(): void {
		$results = array(
			'denied' => __( 'Denied', 'site-agent' ),
		);
		?>
		<h2><?php esc_html_e( 'Recent tool calls', 'site-agent' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time (UTC)', 'site-agent' ); ?></th><th><?php esc_html_e( 'User ID', 'site-agent' ); ?></th><th><?php esc_html_e( 'Tool', 'site-agent' ); ?></th><th><?php esc_html_e( 'Target', 'site-agent' ); ?></th><th><?php esc_html_e( 'Credential', 'site-agent' ); ?></th><th><?php esc_html_e( 'Result', 'site-agent' ); ?></th></tr></thead><tbody>
			<?php foreach ( array_reverse( (array) get_option( Audit::OPTION, array() ) ) as $row ) : ?>
				<?php
				if ( ! is_array( $row ) ) {
					continue;
				}
				$error      = (string) ( $row['error'] ?? '' );
				$credential = trim( ( (string) ( $row['credential'] ?? '' ) ) . ( empty( $row['via'] ) ? '' : ' (' . $row['via'] . ')' ) );
				$result     = ! empty( $row['success'] ) ? __( 'Success', 'site-agent' ) : ( $results[ $error ] ?? trim( __( 'Failed', 'site-agent' ) . ( '' === $error ? '' : ': ' . $error ) ) );
				?>
				<tr><td><?php echo esc_html( (string) ( $row['time'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $row['user_id'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $row['tool'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $row['target'] ?? '' ) ); ?></td><td><?php echo esc_html( $credential ); ?></td><td><?php echo esc_html( $result ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function auth_converter(): void {
		?>
		<section style="margin-block:24px;padding:24px;background:#fff;border:1px solid #c3c4c7;">
			<h3><?php esc_html_e( 'Create your Authorization value', 'site-agent' ); ?></h3>
			<p><?php esc_html_e( 'Enter a dedicated WordPress Application Password, not your account password. Conversion happens in this browser. Site Agent does not submit or store these credentials; Test connection sends them only to this site\'s MCP endpoint.', 'site-agent' ); ?></p>
			<form id="site-agent-auth-converter" autocomplete="off">
				<table class="form-table" role="presentation">
					<tr><th><label for="site-agent-auth-username"><?php esc_html_e( 'WordPress username', 'site-agent' ); ?></label></th><td><input id="site-agent-auth-username" type="text" value="<?php echo esc_attr( wp_get_current_user()->user_login ); ?>" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="60" required class="regular-text" style="width:100%;max-width:480px;"></td></tr>
					<tr><th><label for="site-agent-auth-password"><?php esc_html_e( 'Application Password', 'site-agent' ); ?></label></th><td><input id="site-agent-auth-password" type="password" autocomplete="new-password" spellcheck="false" maxlength="256" required class="regular-text" style="width:100%;max-width:480px;"><p class="description"><?php esc_html_e( 'You can paste the password with its spaces. The converter removes them.', 'site-agent' ); ?></p></td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate token', 'site-agent' ); ?></button> <button type="button" id="site-agent-auth-clear" class="button"><?php esc_html_e( 'Clear credentials', 'site-agent' ); ?></button></p>
				<div id="site-agent-auth-result" hidden>
					<p><label for="site-agent-auth-token"><strong><?php esc_html_e( 'Base64 token', 'site-agent' ); ?></strong></label></p>
					<input id="site-agent-auth-token" type="password" readonly autocomplete="off" spellcheck="false" class="large-text" aria-describedby="site-agent-auth-help" style="width:100%;max-width:640px;">
					<p><button type="button" id="site-agent-auth-show" class="button" aria-pressed="false"><?php esc_html_e( 'Show token', 'site-agent' ); ?></button> <button type="button" class="button" data-site-agent-copy="token"><?php esc_html_e( 'Copy Base64 token', 'site-agent' ); ?></button> <button type="button" class="button" data-site-agent-copy="authorization"><?php esc_html_e( 'Copy Authorization value', 'site-agent' ); ?></button> <button type="button" class="button" data-site-agent-copy="configuration"><?php esc_html_e( 'Copy MCP configuration', 'site-agent' ); ?></button> <button type="button" class="button" data-site-agent-copy="endpoint" <?php disabled( ! Config::get()['url_auth'] ); ?>><?php esc_html_e( 'Copy authenticated endpoint', 'site-agent' ); ?></button> <button type="button" id="site-agent-auth-test" class="button button-secondary"><?php esc_html_e( 'Test connection', 'site-agent' ); ?></button></p>
					<p id="site-agent-auth-help" class="description"><?php esc_html_e( 'The Authorization value includes the Basic prefix. The MCP configuration includes your endpoint and generated value. Base64 is reversible, so keep the token and configuration private.', 'site-agent' ); ?></p>
					<p class="description"><?php esc_html_e( 'For clients without custom headers, enable URL authentication above and save first. Copy authenticated endpoint adds the auth query parameter. This URL contains your credentials and may appear in history or logs; keep it private. A compatible Streamable HTTP MCP client is still required.', 'site-agent' ); ?></p>
				</div>
				<p id="site-agent-auth-status" role="status" aria-live="polite"></p>
				<noscript><p><?php esc_html_e( 'Enable JavaScript to use the credential converter.', 'site-agent' ); ?></p></noscript>
			</form>
		</section>
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
