<?php
/**
 * Event catalog.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable event IDs and the labels shown on the Events screen.
 */
class Events {

	/**
	 * Groups of event ID => label.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function groups() {
		return array(
			__( 'Users and sessions', 'admintrace-activity-logger' ) => array(
				'user.login'                     => __( 'Login', 'admintrace-activity-logger' ),
				'user.logout'                    => __( 'Logout', 'admintrace-activity-logger' ),
				'user.login_failed'              => __( 'Failed login', 'admintrace-activity-logger' ),
				'user.sessions_ended'            => __( 'Other login sessions ended', 'admintrace-activity-logger' ),
				'user.created'                   => __( 'Account created', 'admintrace-activity-logger' ),
				'user.updated'                   => __( 'Profile updated', 'admintrace-activity-logger' ),
				'user.deleted'                   => __( 'Account deleted', 'admintrace-activity-logger' ),
				'user.role_changed'              => __( 'Role changed', 'admintrace-activity-logger' ),
				'user.password_reset_requested'  => __( 'Password reset requested', 'admintrace-activity-logger' ),
				'user.password_reset'            => __( 'Password reset', 'admintrace-activity-logger' ),
				'user.app_password_created'      => __( 'Application password created', 'admintrace-activity-logger' ),
				'user.app_password_deleted'      => __( 'Application password deleted', 'admintrace-activity-logger' ),
				'security.login_threshold'       => __( 'Failed login threshold', 'admintrace-activity-logger' ),
			),
			__( 'Content', 'admintrace-activity-logger' ) => array(
				'content.created'   => __( 'Content created', 'admintrace-activity-logger' ),
				'content.updated'   => __( 'Content updated', 'admintrace-activity-logger' ),
				'content.published' => __( 'Content published', 'admintrace-activity-logger' ),
				'content.trashed'   => __( 'Content trashed', 'admintrace-activity-logger' ),
				'content.restored'  => __( 'Content restored', 'admintrace-activity-logger' ),
				'content.deleted'   => __( 'Content deleted', 'admintrace-activity-logger' ),
				'taxonomy.created'  => __( 'Term created', 'admintrace-activity-logger' ),
				'taxonomy.updated'  => __( 'Term updated', 'admintrace-activity-logger' ),
				'taxonomy.deleted'  => __( 'Term deleted', 'admintrace-activity-logger' ),
				'comment.created'   => __( 'Comment or note created', 'admintrace-activity-logger' ),
				'comment.approved'  => __( 'Comment approved', 'admintrace-activity-logger' ),
				'comment.spam'      => __( 'Comment marked as spam', 'admintrace-activity-logger' ),
				'comment.trash'     => __( 'Comment trashed', 'admintrace-activity-logger' ),
			),
			__( 'Media, menus, and widgets', 'admintrace-activity-logger' ) => array(
				'media.uploaded'  => __( 'File uploaded', 'admintrace-activity-logger' ),
				'media.edited'    => __( 'File edited', 'admintrace-activity-logger' ),
				'media.replaced'  => __( 'File replaced', 'admintrace-activity-logger' ),
				'media.deleted'   => __( 'File deleted', 'admintrace-activity-logger' ),
				'menu.created'    => __( 'Menu created', 'admintrace-activity-logger' ),
				'menu.updated'    => __( 'Menu updated', 'admintrace-activity-logger' ),
				'menu.deleted'    => __( 'Menu deleted', 'admintrace-activity-logger' ),
				'widget.updated'  => __( 'Widgets updated', 'admintrace-activity-logger' ),
			),
			__( 'Plugins, themes, and WordPress', 'admintrace-activity-logger' ) => array(
				'plugin.installed'        => __( 'Plugin installed', 'admintrace-activity-logger' ),
				'plugin.updated'          => __( 'Plugin updated', 'admintrace-activity-logger' ),
				'plugin.activated'        => __( 'Plugin activated', 'admintrace-activity-logger' ),
				'plugin.deactivated'      => __( 'Plugin deactivated', 'admintrace-activity-logger' ),
				'plugin.deleted'          => __( 'Plugin deleted', 'admintrace-activity-logger' ),
				'plugin.auto_update'      => __( 'Plugin auto-update changed', 'admintrace-activity-logger' ),
				'theme.installed'         => __( 'Theme installed', 'admintrace-activity-logger' ),
				'theme.updated'           => __( 'Theme updated', 'admintrace-activity-logger' ),
				'theme.switched'          => __( 'Theme switched', 'admintrace-activity-logger' ),
				'theme.deleted'           => __( 'Theme deleted', 'admintrace-activity-logger' ),
				'theme.auto_update'       => __( 'Theme auto-update changed', 'admintrace-activity-logger' ),
				'core.updated'            => __( 'WordPress updated', 'admintrace-activity-logger' ),
				'core.translations'       => __( 'Language packs updated', 'admintrace-activity-logger' ),
				'core.automatic_updates'  => __( 'Automatic updates finished', 'admintrace-activity-logger' ),
				'core.updates_available'  => __( 'Available updates changed', 'admintrace-activity-logger' ),
				'settings.updated'        => __( 'Setting changed', 'admintrace-activity-logger' ),
				'settings.customizer'     => __( 'Customizer saved', 'admintrace-activity-logger' ),
			),
			__( 'WooCommerce, privacy, and security', 'admintrace-activity-logger' ) => array(
				'commerce.order_placed'  => __( 'Order placed', 'admintrace-activity-logger' ),
				'commerce.order_status'  => __( 'Order status changed', 'admintrace-activity-logger' ),
				'commerce.refund'        => __( 'Order refunded', 'admintrace-activity-logger' ),
				'commerce.stock'         => __( 'Stock changed', 'admintrace-activity-logger' ),
				'commerce.product_updated' => __( 'Product updated', 'admintrace-activity-logger' ),
				'privacy.export_file'    => __( 'Personal data export', 'admintrace-activity-logger' ),
				'privacy.erased'         => __( 'Personal data erased', 'admintrace-activity-logger' ),
				'privacy.confirmed'      => __( 'Privacy request confirmed', 'admintrace-activity-logger' ),
				'privacy.tools_export'   => __( 'Tools export', 'admintrace-activity-logger' ),
				'security.access_denied' => __( 'Admin screen denied', 'admintrace-activity-logger' ),
				'security.site_health'   => __( 'Site Health changed', 'admintrace-activity-logger' ),
				'security.file_edited'   => __( 'Plugin or theme file edited', 'admintrace-activity-logger' ),
			),
			__( 'Multisite', 'admintrace-activity-logger' ) => array(
				'network.site_created' => __( 'Site created', 'admintrace-activity-logger' ),
				'network.site_deleted' => __( 'Site deleted', 'admintrace-activity-logger' ),
				'network.site_updated' => __( 'Site archived, deleted, or marked as spam', 'admintrace-activity-logger' ),
				'network.user_added'   => __( 'User added to a site', 'admintrace-activity-logger' ),
				'network.user_removed' => __( 'User removed from a site', 'admintrace-activity-logger' ),
			),
			__( 'Files and database', 'admintrace-activity-logger' ) => array(
				'files.added'              => __( 'File added on disk', 'admintrace-activity-logger' ),
				'files.changed'            => __( 'File changed on disk', 'admintrace-activity-logger' ),
				'files.deleted'            => __( 'File deleted from disk', 'admintrace-activity-logger' ),
				'database.table_created'   => __( 'Database table created', 'admintrace-activity-logger' ),
				'database.table_dropped'   => __( 'Database table dropped', 'admintrace-activity-logger' ),
			),
			__( 'Other plugins and notes', 'admintrace-activity-logger' ) => array(
				'integration.user_switched'      => __( 'User switching', 'admintrace-activity-logger' ),
				'integration.acf_saved'          => __( 'ACF field group saved', 'admintrace-activity-logger' ),
				'integration.acf_deleted'        => __( 'ACF field group deleted', 'admintrace-activity-logger' ),
				'integration.duplicated'         => __( 'Post duplicated', 'admintrace-activity-logger' ),
				'integration.builder_saved'      => __( 'Beaver Builder saved', 'admintrace-activity-logger' ),
				'integration.redirect_saved'     => __( 'Redirect saved', 'admintrace-activity-logger' ),
				'integration.redirect_deleted'   => __( 'Redirect deleted', 'admintrace-activity-logger' ),
				'journal.note'                   => __( 'Manual note', 'admintrace-activity-logger' ),
				'settings.srah'                  => __( 'AdminTrace settings saved', 'admintrace-activity-logger' ),
			),
		);
	}

	/**
	 * Every catalog event ID.
	 *
	 * @return string[]
	 */
	public static function ids() {
		$ids = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group as $id => $label ) {
				unset( $label );
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Event IDs that were unchecked on the Events screen.
	 *
	 * @param mixed $enabled Submitted enabled IDs.
	 * @return string[]
	 */
	public static function disabled_from( $enabled ) {
		$known = self::ids();
		$on    = array();
		if ( is_array( $enabled ) ) {
			foreach ( $enabled as $id ) {
				$key = Text::key( $id, 64, '' );
				if ( in_array( $key, $known, true ) ) {
					$on[] = $key;
				}
			}
		}
		return array_values( array_diff( $known, array_unique( $on ) ) );
	}
}
