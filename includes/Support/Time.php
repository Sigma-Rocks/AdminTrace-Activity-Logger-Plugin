<?php
/**
 * Time formatting.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats stored GMT timestamps for the admin.
 */
class Time {

	/**
	 * Display values for a GMT datetime.
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 * @param int    $ms  Milliseconds, 0–999.
	 * @return array{iso:string,local:string,relative:string,primary:string}
	 */
	public static function display( $gmt, $ms = 0 ) {
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( ! $timestamp ) {
			return array(
				'iso'      => '',
				'local'    => '',
				'relative' => '',
				'primary'  => '',
			);
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$local  = function_exists( 'wp_date' ) ? wp_date( $format, $timestamp ) : gmdate( 'Y-m-d H:i:s', $timestamp );
		$ms     = max( 0, min( 999, (int) $ms ) );
		$fraction = sprintf( '.%03d', $ms );
		$local   .= $fraction;
		$diff   = human_time_diff( $timestamp, time() );
		/* translators: %s: amount of time, such as "3 hours". */
		$relative = sprintf( __( '%s ago', 'admintrace-activity-logger' ), $diff );
		$primary  = ( time() - $timestamp ) > ( 7 * DAY_IN_SECONDS ) ? $local : $relative;

		return array(
			'iso'      => gmdate( 'Y-m-d\TH:i:s', $timestamp ) . $fraction . 'Z',
			'local'    => $local,
			'relative' => $relative,
			'primary'  => $primary,
		);
	}
}
