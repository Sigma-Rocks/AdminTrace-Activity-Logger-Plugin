<?php
/**
 * Uninstall cleanup.
 *
 * The journal is removed when "Delete the journal and settings when the plugin
 * is uninstalled" is enabled. That option is on by default.
 *
 * @package AdvanceHistory
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin data for one site.
 */
function advance_history_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'advance_history_settings', array() );
	$delete   = ! is_array( $settings ) || ! isset( $settings['delete_data_on_uninstall'] ) || ! empty( $settings['delete_data_on_uninstall'] );

	wp_clear_scheduled_hook( 'advance_history_prune' );
	wp_clear_scheduled_hook( 'advance_history_digest' );

	if ( ! $delete ) {
		return;
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ah_events" );

	delete_option( 'advance_history_settings' );
	delete_option( 'advance_history_db_version' );
	delete_option( 'advance_history_backfilled' );
	delete_option( 'advance_history_update_fingerprint' );
	delete_option( 'advance_history_fail_window' );

	$names = array(
		$wpdb->esc_like( '_transient_ah_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ah_' ) . '%',
	);
	foreach ( $names as $like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	$role = get_role( 'administrator' );
	if ( $role ) {
		foreach ( array( 'view_advance_history', 'manage_advance_history', 'export_advance_history', 'delete_advance_history' ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	);
	foreach ( $sites as $site ) {
		$blog_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;
		switch_to_blog( $blog_id );
		advance_history_uninstall_site();
		restore_current_blog();
	}
} else {
	advance_history_uninstall_site();
}

delete_metadata( 'user', 0, 'advance_history_dismiss_welcome', '', true );
delete_metadata( 'user', 0, 'advance_history_view', '', true );
