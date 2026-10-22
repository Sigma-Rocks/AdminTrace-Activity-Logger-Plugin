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
		return $wpdb->prefix . 'ah_events';
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
						'summary'   => __( 'Advance History was activated.', 'advance-history' ),
						'category'  => 'system',
						'action'    => 'system.activated',
						'severity'  => 'notice',
						'logger'    => 'system',
						'initiator' => 'system',
						'user_id'   => get_current_user_id(),
					)
				);
				set_transient( 'ah_welcome_' . get_current_user_id(), 1, DAY_IN_SECONDS );
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
						'summary'   => __( 'Advance History was deactivated.', 'advance-history' ),
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
		$installed = get_option( 'advance_history_db_version' );
		if ( (string) $installed === (string) AH_DB_VERSION ) {
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
		update_option( 'advance_history_db_version', AH_DB_VERSION, true );
	}

	/**
	 * Note a few existing posts so a new journal is not blank.
	 */
	private static function backfill() {
		if ( get_option( 'advance_history_backfilled' ) ) {
			return;
		}
		update_option( 'advance_history_backfilled', 1, true );

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
					'summary'     => sprintf( __( 'Noted from existing content: "%1$s" (%3$s), last edited by %2$s. This was recorded when Advance History was activated, not as a live edit.', 'advance-history' ), $post->post_title, $author ? $author : __( 'unknown author', 'advance-history' ), $post->post_status ),
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
}
