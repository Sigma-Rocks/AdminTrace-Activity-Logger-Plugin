<?php
/**
 * Collapses repeated events for display.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups the same trigger when it happens again within a short window.
 */
class Groups {

	/**
	 * Seconds between repeats that still belong together.
	 */
	const WINDOW = 1800;

	/**
	 * Collapse consecutive repeats into the newest row.
	 *
	 * Rows must already be newest-first. A repeat keeps its own database row.
	 *
	 * @param array $rows Event rows.
	 * @return array
	 */
	public static function collapse( array $rows ) {
		$grouped = array();
		$used    = array();
		$total   = count( $rows );

		for ( $index = 0; $index < $total; $index++ ) {
			if ( isset( $used[ $index ] ) ) {
				continue;
			}

			$bundle = array( $rows[ $index ] );
			$used[ $index ] = true;
			$tail           = self::stamp( $rows[ $index ] );

			for ( $next = $index + 1; $next < $total; $next++ ) {
				if ( isset( $used[ $next ] ) || ! self::same( $rows[ $index ], $rows[ $next ] ) ) {
					continue;
				}
				$stamp = self::stamp( $rows[ $next ] );
				if ( ! $tail || ! $stamp || ( $tail - $stamp ) > self::WINDOW ) {
					break;
				}
				$bundle[]       = $rows[ $next ];
				$used[ $next ]  = true;
				$tail           = $stamp;
			}

			$grouped[] = self::pack( $bundle );
		}

		return $grouped;
	}

	/**
	 * Whether two events are the same trigger.
	 *
	 * @param array $left  Newer row.
	 * @param array $right Older row.
	 * @return bool
	 */
	private static function same( array $left, array $right ) {
		if ( '' === (string) $left['summary'] || (string) $left['summary'] !== (string) $right['summary'] ) {
			return false;
		}

		return (string) $left['action'] === (string) $right['action']
			&& (int) $left['user_id'] === (int) $right['user_id']
			&& (string) $left['object_type'] === (string) $right['object_type']
			&& (int) $left['object_id'] === (int) $right['object_id'];
	}

	/**
	 * Unix time for a GMT row.
	 *
	 * @param array $row Event row.
	 * @return int
	 */
	private static function stamp( array $row ) {
		$gmt = isset( $row['created_gmt'] ) ? (string) $row['created_gmt'] : '';
		$time = strtotime( $gmt . ' UTC' );
		return $time ? (int) $time : 0;
	}

	/**
	 * Attach a repeat count and the times we can still show.
	 *
	 * @param array $bundle Matching rows, newest first.
	 * @return array
	 */
	private static function pack( array $bundle ) {
		$lead  = $bundle[0];
		$count = 0;
		$times = array();

		foreach ( $bundle as $row ) {
			$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
			$stored  = isset( $context['repeat_count'] ) ? (int) $context['repeat_count'] : 1;
			$count  += max( 1, $stored );

			if ( ! empty( $context['repeat_times'] ) && is_array( $context['repeat_times'] ) ) {
				foreach ( $context['repeat_times'] as $time ) {
					$times[] = (string) $time;
				}
			} else {
				$times[] = isset( $row['created_gmt'] ) ? (string) $row['created_gmt'] : '';
			}
		}

		$times = array_values( array_filter( array_unique( $times ) ) );
		$lead['repeat_count'] = $count;
		$lead['repeat_times'] = array_slice( $times, 0, 12 );

		return $lead;
	}
}
