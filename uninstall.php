<?php
/**
 * Uninstall: remove data only if the admin opted in (Settings → Data).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$scenecal_settings = get_option( 'scenecal_settings', array() );

wp_clear_scheduled_hook( 'scenecal_weekly' );
wp_clear_scheduled_hook( 'scenecal_healthcheck' );

if ( empty( $scenecal_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'scenecal_events', 'scenecal_venues', 'scenecal_runs' ) as $scenecal_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$scenecal_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
foreach ( array( 'scenecal_settings', 'scenecal_db_version', 'scenecal_tick_token', 'scenecal_lock' ) as $scenecal_option ) {
	delete_option( $scenecal_option );
}
foreach ( wp_roles()->role_objects as $scenecal_role ) {
	$scenecal_role->remove_cap( 'manage_scene_calendar' );
}
