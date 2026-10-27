<?php
/**
 * Database installer.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the event table.
 */
class Installer {

	/**
	 * Event table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'srah_events';
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 */
	public static function activate( $network_wide ) {
		self::each_site(
			(bool) $network_wide,
			static function () {
				self::install();
				Capabilities::grant_roles();
				Retention::ensure_scheduled();
				Notifications::ensure_scheduled();
				self::backfill();
				Recorder::log(
					array(
						'summary'   => __( 'AdminTrace Activity Logger was activated.', 'admintrace-activity-logger' ),
						'category'  => 'system',
						'action'    => 'system.activated',
						'severity'  => 'notice',
						'logger'    => 'system',
						'initiator' => 'system',
						'user_id'   => get_current_user_id(),
					)
				);
				set_transient( 'srah_welcome_' . get_current_user_id(), 1, DAY_IN_SECONDS );
			}
		);
	}

	/**
	 * Deactivation callback.
	 *
	 * @param bool $network_wide Whether the plugin was network-deactivated.
	 */
	public static function deactivate( $network_wide ) {
		self::each_site(
			(bool) $network_wide,
			static function () {
				Recorder::log(
					array(
						'summary'   => __( 'AdminTrace Activity Logger was deactivated.', 'admintrace-activity-logger' ),
						'category'  => 'system',
						'action'    => 'system.deactivated',
						'severity'  => 'notice',
						'logger'    => 'system',
						'initiator' => 'system',
						'user_id'   => get_current_user_id(),
					)
				);
				Retention::unschedule();
				Notifications::unschedule();
			}
		);
	}

	/**
	 * Install a newly created multisite site.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ) {
		if ( ! $site instanceof \WP_Site ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install();
		Capabilities::grant_roles();
		Retention::ensure_scheduled();
		restore_current_blog();
	}

	/**
	 * Create or upgrade the table when the schema version changes.
	 */
	public static function maybe_upgrade() {
		self::migrate_legacy_storage();
		self::rename_legacy_table();
		$installed = get_option( 'srah_db_version' );
		if ( (string) $installed === (string) SRAH_DB_VERSION ) {
			return;
		}
		self::install();
		Capabilities::grant_roles();
	}

	/**
	 * Create the event table.
	 */
	public static function install() {
		global $wpdb;

		self::rename_legacy_table();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_gmt datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			user_display varchar(191) NOT NULL DEFAULT '',
			initiator varchar(20) NOT NULL DEFAULT 'user',
			ip_masked varchar(45) NOT NULL DEFAULT '',
			severity varchar(16) NOT NULL DEFAULT 'info',
			category varchar(32) NOT NULL DEFAULT 'system',
			logger varchar(32) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_title varchar(191) NOT NULL DEFAULT '',
			summary text NOT NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_gmt (created_gmt),
			KEY user_created (user_id, created_gmt),
			KEY category_action (category, action),
			KEY severity_created (severity, created_gmt),
			KEY object_lookup (object_type, object_id),
			KEY blog_created (blog_id, created_gmt)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'srah_db_version', SRAH_DB_VERSION, true );
	}

	/**
	 * Note a few existing posts so a new journal is not blank.
	 */
	private static function backfill() {
		if ( get_option( 'srah_backfilled' ) ) {
			return;
		}
		update_option( 'srah_backfilled', 1, true );

		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 10,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$author = get_the_author_meta( 'display_name', $post->post_author );
			Recorder::log(
				array(
					/* translators: 1: content title, 2: author display name, 3: status. */
					'summary'     => sprintf( __( 'Noted from existing content: "%1$s" (%3$s), last edited by %2$s. This was recorded when AdminTrace Activity Logger was activated, not as a live edit.', 'admintrace-activity-logger' ), $post->post_title, $author ? $author : __( 'unknown author', 'admintrace-activity-logger' ), $post->post_status ),
					'category'    => 'content',
					'action'      => 'content.snapshot',
					'severity'    => 'info',
					'logger'      => 'system',
					'initiator'   => 'system',
					'user_id'     => 0,
					'object_type' => 'content',
					'object_id'   => (int) $post->ID,
					'object_title'=> $post->post_title,
					'created_gmt' => $post->post_modified_gmt,
					'context'     => array(
						'post_type'              => $post->post_type,
						'recorded_on_activation' => true,
					),
				)
			);
		}
	}

	/**
	 * Run a callback for the current site or every site in a network.
	 *
	 * @param bool     $network_wide Whether to visit every site.
	 * @param callable $callback     Callback.
	 */
	public static function each_site( $network_wide, $callback ) {
		if ( $network_wide && is_multisite() ) {
			$sites = get_sites(
				array(
					'number' => 0,
					'fields' => 'ids',
				)
			);
			foreach ( $sites as $site ) {
				$blog_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;
				switch_to_blog( $blog_id );
				call_user_func( $callback );
				restore_current_blog();
			}
			return;
		}

		call_user_func( $callback );
	}

	/**
	 * Move stored keys off the earlier advance_history_ prefix.
	 *
	 * Runs once. Fresh installs only record that the move is done.
	 */
	private static function migrate_legacy_storage() {
		if ( get_option( 'srah_legacy_prefix_done' ) ) {
			return;
		}

		$options = array(
			'advance_history_settings'           => array( 'srah_settings', true ),
			'advance_history_db_version'         => array( 'srah_db_version', true ),
			'advance_history_backfilled'         => array( 'srah_backfilled', true ),
			'advance_history_update_fingerprint' => array( 'srah_update_fingerprint', false ),
			'advance_history_fail_window'        => array( 'srah_fail_window', false ),
		);
		foreach ( $options as $old => $target ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( false === get_option( $target[0], false ) ) {
				update_option( $target[0], $value, $target[1] );
			}
			delete_option( $old );
		}

		global $wpdb;
		$meta = array(
			'advance_history_dismiss_welcome' => 'srah_dismiss_welcome',
			'advance_history_view'            => 'srah_view',
		);
		foreach ( $meta as $old => $new ) {
			$wpdb->update(
				$wpdb->usermeta,
				array( 'meta_key' => $new ),
				array( 'meta_key' => $old )
			);
		}

		$role = get_role( 'administrator' );
		if ( $role ) {
			$caps = array(
				'view_advance_history'   => 'srah_view',
				'manage_advance_history' => 'srah_manage',
				'export_advance_history' => 'srah_export',
				'delete_advance_history' => 'srah_delete',
			);
			foreach ( $caps as $old => $new ) {
				if ( empty( $role->capabilities[ $old ] ) ) {
					continue;
				}
				$role->add_cap( $new );
				$role->remove_cap( $old );
			}
		}

		wp_clear_scheduled_hook( 'advance_history_prune' );
		wp_clear_scheduled_hook( 'advance_history_digest' );
		Capabilities::grant_roles();
		Retention::ensure_scheduled();
		Notifications::ensure_scheduled();

		update_option( 'srah_legacy_prefix_done', 1, true );
	}

	/**
	 * Rename the table used before the srah_ prefix.
	 */
	private static function rename_legacy_table() {
		global $wpdb;

		$table  = self::table();
		$legacy = $wpdb->prefix . 'ah_events';
		if ( $table === $legacy ) {
			return;
		}

		$has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $has_new === $table ) {
			return;
		}

		$has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy ) ) );
		if ( $has_old !== $legacy ) {
			return;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $legacy ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return;
		}

		$wpdb->query( "RENAME TABLE `{$legacy}` TO `{$table}`" );
	}
}
