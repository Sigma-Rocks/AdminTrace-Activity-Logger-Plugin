<?php
/**
 * Retention cleanup.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes events older than the configured retention period.
 */
class Retention {

	const HOOK = 'srah_prune';

	/**
	 * Register the cron callback and make sure it is scheduled.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'scheduled_prune' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Cron callback. Cron passes an empty string as the first argument, so prune() is called without one.
	 */
	public static function scheduled_prune() {
		self::prune();
	}

	/**
	 * Schedule a daily prune when one is missing.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the prune event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete expired rows in batches.
	 *
	 * A retention of 0 keeps events until someone deletes them.
	 *
	 * @param int|null $days Days to keep. Null uses the saved setting.
	 * @return int Rows deleted.
	 */
	public static function prune( $days = null ) {
		global $wpdb;

		if ( null === $days ) {
			$days = (int) Settings::all()['retention_days'];
		}
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		for ( $loop = 0; $loop < 20; $loop++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own events table has no WordPress API; journal reads must be live.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE created_gmt < %s LIMIT 2000',
					Installer::table(),
					$cutoff
				)
			);
			$deleted = (int) $wpdb->rows_affected;
			$total  += $deleted;
			if ( $deleted < 2000 ) {
				break;
			}
		}

		Email_Log::prune( $days );

		do_action( 'srah_pruned', $total, $days );
		return $total;
	}

	/**
	 * Delete every event on the current site.
	 *
	 * @return int
	 */
	public static function delete_all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own events table has no WordPress API; journal reads must be live.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE blog_id = %d',
				Installer::table(),
				get_current_blog_id()
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Delete specific events on the current site.
	 *
	 * @param int[] $ids Event IDs.
	 * @return int
	 */
	public static function delete_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$ids = array_slice( $ids, 0, 100 );
		if ( ! $ids ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own events table has no WordPress API; journal reads must be live.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE blog_id = %d AND id = %d',
					Installer::table(),
					get_current_blog_id(),
					$id
				)
			);
			$deleted += (int) $wpdb->rows_affected;
		}
		return $deleted;
	}
}
