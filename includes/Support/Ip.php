<?php
/**
 * IP masking.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Masks IP addresses before they are stored.
 *
 * Full addresses are never kept. IPv4 keeps the network and zeroes the last
 * octet. IPv6 keeps the first half of the address.
 */
class Ip {

	/**
	 * Mask a single address.
	 *
	 * @param mixed $ip Raw address.
	 * @return string Masked address, or an empty string when the input is not an IP.
	 */
	public static function mask( $ip ) {
		$ip = trim( (string) $ip );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return '';
		}

		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}

		$groups = str_split( bin2hex( $packed ), 4 );
		$shown  = array();
		foreach ( array_slice( $groups, 0, 4 ) as $group ) {
			$trimmed = ltrim( $group, '0' );
			$shown[] = '' === $trimmed ? '0' : $trimmed;
		}

		return implode( ':', $shown ) . '::';
	}

	/**
	 * Read the visitor address from server vars and mask it.
	 *
	 * REMOTE_ADDR is used unless the site owner has said this install is
	 * behind a trusted proxy. Forwarded headers are spoofable, so they stay
	 * off unless that setting is enabled.
	 *
	 * @param array $server      Server values, normally $_SERVER.
	 * @param bool  $trust_proxy Whether X-Forwarded-For may be consulted.
	 * @return string
	 */
	public static function from_request( $server, $trust_proxy = false ) {
		if ( ! is_array( $server ) ) {
			return '';
		}

		$remote = isset( $server['REMOTE_ADDR'] ) ? self::mask( $server['REMOTE_ADDR'] ) : '';
		if ( ! $trust_proxy ) {
			return $remote;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? (string) $server['HTTP_X_FORWARDED_FOR'] : '';
		if ( '' === $forwarded ) {
			return $remote;
		}

		foreach ( explode( ',', $forwarded ) as $candidate ) {
			$masked = self::mask( $candidate );
			if ( '' !== $masked ) {
				return $masked;
			}
		}

		return $remote;
	}
}
