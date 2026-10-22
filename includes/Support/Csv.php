<?php
/**
 * CSV cell escaping.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents spreadsheet formula injection.
 */
class Csv {

	/**
	 * Neutralize a cell that a spreadsheet would treat as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			$value   = is_string( $encoded ) ? $encoded : '';
		} elseif ( null === $value ) {
			$value = '';
		}

		$value = (string) $value;
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
