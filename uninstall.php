<?php
/**
 * Opt-in uninstall cleanup.
 *
 * @package SiteAgent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$site_agent_config = get_option( 'site_agent_settings', array() );
if ( empty( $site_agent_config['delete_data'] ) ) {
	return;
}
delete_option( 'site_agent_settings' );
delete_option( 'site_agent_audit' );
delete_option( 'site_agent_license' );
delete_transient( 'site_agent_update_metadata' );
