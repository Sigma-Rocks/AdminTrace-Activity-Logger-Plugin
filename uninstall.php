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
function srah_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'srah_settings', null );
	if ( ! is_array( $settings ) ) {
		$legacy   = get_option( 'advance_history_settings', array() );
		$settings = is_array( $legacy ) ? $legacy : array();
	}
	$delete = ! isset( $settings['delete_data_on_uninstall'] ) || ! empty( $settings['delete_data_on_uninstall'] );

	wp_clear_scheduled_hook( 'srah_prune' );
	wp_clear_scheduled_hook( 'srah_digest' );
	wp_clear_scheduled_hook( 'advance_history_prune' );
	wp_clear_scheduled_hook( 'advance_history_digest' );

	if ( ! $delete ) {
		return;
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}srah_events" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ah_events" );

	foreach (
		array(
			'srah_settings',
			'srah_db_version',
			'srah_backfilled',
			'srah_update_fingerprint',
			'srah_fail_window',
			'srah_legacy_prefix_done',
			'advance_history_settings',
			'advance_history_db_version',
			'advance_history_backfilled',
			'advance_history_update_fingerprint',
			'advance_history_fail_window',
		) as $option
	) {
		delete_option( $option );
	}

	$names = array(
		$wpdb->esc_like( '_transient_srah_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_srah_' ) . '%',
		$wpdb->esc_like( '_transient_ah_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ah_' ) . '%',
	);
	foreach ( $names as $like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	$role = get_role( 'administrator' );
	if ( $role ) {
		foreach (
			array(
				'srah_view',
				'srah_manage',
				'srah_export',
				'srah_delete',
				'view_advance_history',
				'manage_advance_history',
				'export_advance_history',
				'delete_advance_history',
			) as $cap
		) {
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
		srah_uninstall_site();
		restore_current_blog();
	}
} else {
	srah_uninstall_site();
}

delete_metadata( 'user', 0, 'srah_dismiss_welcome', '', true );
delete_metadata( 'user', 0, 'srah_view', '', true );
delete_metadata( 'user', 0, 'advance_history_dismiss_welcome', '', true );
delete_metadata( 'user', 0, 'advance_history_view', '', true );
