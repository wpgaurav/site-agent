<?php
/**
 * Uninstall cleanup. Runtime state is always removed; settings and history only on opt-in.
 *
 * @package SiteAgent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$site_agent_sites = is_multisite()
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );

foreach ( $site_agent_sites as $site_agent_site ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $site_agent_site );
	}
	// MCP sessions and caches are disposable and useless once the plugin is gone.
	delete_metadata( 'user', 0, 'site_agent_mcp_adapter_sessions', '', true );
	delete_metadata( 'user', 0, 'site_agent_mcp_adapter_sessions_' . (int) $site_agent_site, '', true );
	delete_transient( 'site_agent_update_metadata' );
	delete_transient( 'site_agent_php_binary' );
	$site_agent_config = get_option( 'site_agent_settings', array() );
	if ( ! empty( $site_agent_config['delete_data'] ) ) {
		delete_option( 'site_agent_settings' );
		delete_option( 'site_agent_audit' );
		delete_option( 'site_agent_license' );
		delete_option( 'site_agent_token_scopes' );
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
}
