<?php
/**
 * Context sanitizer.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes secrets and oversized values from event context.
 */
class Context {

	const MAX_DEPTH  = 4;
	const MAX_KEYS   = 40;
	const MAX_LIST   = 30;
	const MAX_STRING = 500;
	const MAX_BYTES  = 20000;

	/**
	 * Clean a context payload.
	 *
	 * @param mixed $data Raw context.
	 * @return array
	 */
	public static function clean( $data ) {
		$clean = self::walk( $data, 0 );
		if ( ! is_array( $clean ) ) {
			$clean = array(
				'value' => self::scalar( $clean ),
			);
		}

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $clean );
		} else {
			$json = json_encode( $clean );
		}
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return array(
				'note' => 'Omitted oversized context',
			);
		}

		return $clean;
	}

	/**
	 * Walk a value.
	 *
	 * @param mixed $data  Value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private static function walk( $data, $depth ) {
		if ( is_object( $data ) ) {
			return '[object]';
		}

		if ( ! is_array( $data ) ) {
			return self::scalar( $data );
		}

		if ( $depth >= self::MAX_DEPTH ) {
			return '[nested]';
		}

		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		if ( $is_list ) {
			$items = array();
			foreach ( array_slice( $data, 0, self::MAX_LIST ) as $item ) {
				$items[] = self::walk( $item, $depth + 1 );
			}
			return $items;
		}

		$clean = array();
		$count = 0;
		foreach ( $data as $key => $value ) {
			if ( $count >= self::MAX_KEYS ) {
				break;
			}
			$name = Text::key( $key, 40 );
			if ( '' === $name || self::is_sensitive_key( (string) $key ) ) {
				continue;
			}
			if ( self::is_ip_key( (string) $key ) && is_scalar( $value ) ) {
				$clean[ $name ] = Ip::mask( $value );
				++$count;
				continue;
			}
			$clean[ $name ] = self::walk( $value, $depth + 1 );
			++$count;
		}

		return $clean;
	}

	/**
	 * Reduce a scalar to a storable value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|int|float|string|null
	 */
	private static function scalar( $value ) {
		if ( is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			return $value;
		}
		return Text::plain( $value, self::MAX_STRING );
	}

	/**
	 * Whether a key is likely to hold a secret.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	public static function is_sensitive_key( $key ) {
		return 1 === preg_match(
			'/pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|nonce|session|private[_-]?key|client[_-]?secret|access[_-]?key|refresh|smtp|credential|card[_-]?number|cvv|ssn|php_auth_pw|user_pass/i',
			(string) $key
		);
	}

	/**
	 * Whether a key is an IP address field.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function is_ip_key( $key ) {
		return 1 === preg_match( '/(^|_)ip(_address)?$/i', (string) $key );
	}
}
