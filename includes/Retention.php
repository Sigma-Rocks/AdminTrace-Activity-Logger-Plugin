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

	const HOOK = 'advance_history_prune';

	/**
	 * Register the cron callback and make sure it is scheduled.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
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

		do_action( 'advance_history_pruned', $total, $days );
		return $total;
	}

	/**
	 * Delete every event on the current site.
	 *
	 * @return int
	 */
	public static function delete_all() {
		global $wpdb;
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
