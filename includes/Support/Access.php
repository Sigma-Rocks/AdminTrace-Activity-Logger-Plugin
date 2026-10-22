<?php
/**
 * Read-scope rules.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

use SigmaRocks\AdvanceHistory\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limits which events a user is allowed to read.
 */
class Access {

	/**
	 * Apply the current user's scope to a query.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function constrain( array $args ) {
		if ( ! empty( $args['unrestricted'] ) ) {
			$args['include_ip'] = true;
			return $args;
		}

		if ( current_user_can( Capabilities::MANAGE ) ) {
			$args['include_ip'] = true;
			return $args;
		}

		$args['include_ip'] = false;
		$allowed            = Vocabulary::viewer_categories();

		if ( ! empty( $args['category'] ) ) {
			if ( ! in_array( $args['category'], $allowed, true ) ) {
				$args['force_empty'] = true;
			}
		} else {
			$args['category_in'] = $allowed;
		}

		return $args;
	}

	/**
	 * Prepare one row for output.
	 *
	 * @param array $row        Database row.
	 * @param bool  $include_ip Whether masked IP data may be included.
	 * @return array
	 */
	public static function present( array $row, $include_ip ) {
		if ( empty( $include_ip ) ) {
			$row['ip_masked'] = '';
			if ( isset( $row['context'] ) && is_array( $row['context'] ) ) {
				unset( $row['context']['ip'], $row['context']['user_agent'] );
			}
		}
		return $row;
	}
}
