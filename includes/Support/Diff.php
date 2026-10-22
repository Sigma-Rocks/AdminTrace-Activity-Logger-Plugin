<?php
/**
 * Compact text change summary.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a short word-level summary without storing full content.
 */
class Diff {

	const MAX_INPUT = 20000;
	const MAX_WORDS = 12;

	/**
	 * Summarize a text change.
	 *
	 * @param string $before Previous text.
	 * @param string $after  New text.
	 * @return array{changed:bool,before_chars:int,after_chars:int,added:array,removed:array}
	 */
	public static function summarize( $before, $after ) {
		$before = self::normalize( $before );
		$after  = self::normalize( $after );

		$result = array(
			'changed'      => $before !== $after,
			'before_chars' => function_exists( 'mb_strlen' ) ? mb_strlen( $before ) : strlen( $before ),
			'after_chars'  => function_exists( 'mb_strlen' ) ? mb_strlen( $after ) : strlen( $after ),
			'added'        => array(),
			'removed'      => array(),
		);

		if ( ! $result['changed'] ) {
			return $result;
		}

		if ( strlen( $before ) > self::MAX_INPUT || strlen( $after ) > self::MAX_INPUT ) {
			return $result;
		}

		$before_counts = array_count_values( self::words( $before ) );
		$after_counts  = array_count_values( self::words( $after ) );

		$result['removed'] = self::difference( $before_counts, $after_counts );
		$result['added']   = self::difference( $after_counts, $before_counts );

		return $result;
	}

	/**
	 * Strip tags and collapse whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Split text into comparable words.
	 *
	 * @param string $text Normalized text.
	 * @return array
	 */
	private static function words( $text ) {
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/\s+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$words = array();
		foreach ( $parts as $part ) {
			$part = function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 40 ) : substr( $part, 0, 40 );
			if ( '' !== $part ) {
				$words[] = $part;
			}
		}
		return $words;
	}

	/**
	 * Words whose count dropped between two bags.
	 *
	 * @param array $from Source counts.
	 * @param array $to   Comparison counts.
	 * @return array
	 */
	private static function difference( $from, $to ) {
		$diff = array();
		foreach ( $from as $word => $count ) {
			$left = $count - ( isset( $to[ $word ] ) ? $to[ $word ] : 0 );
			while ( $left > 0 && count( $diff ) < self::MAX_WORDS ) {
				$diff[] = (string) $word;
				--$left;
			}
			if ( count( $diff ) >= self::MAX_WORDS ) {
				break;
			}
		}
		return $diff;
	}
}
