<?php
/**
 * Plain-text helpers.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns arbitrary input into a single safe line of text.
 */
class Text {

	/**
	 * Collapse markup and control characters.
	 *
	 * @param mixed $text  Raw value.
	 * @param int   $limit Maximum characters.
	 * @return string
	 */
	public static function plain( $text, $limit = 500 ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );
		$limit = max( 0, (int) $limit );

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) > $limit ) {
				$text = mb_substr( $text, 0, $limit );
			}
			return $text;
		}

		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit );
		}

		return $text;
	}

	/**
	 * Keep an identifier made of letters, numbers, dots, underscores, and hyphens.
	 *
	 * @param mixed  $value Raw value.
	 * @param int    $limit Maximum length.
	 * @param string $fallback Value used when the result would be empty.
	 * @return string
	 */
	public static function key( $value, $limit = 64, $fallback = '' ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9._-]/', '', $value );
		$value = substr( (string) $value, 0, max( 1, (int) $limit ) );
		if ( '' === $value ) {
			return $fallback;
		}
		return $value;
	}
}
