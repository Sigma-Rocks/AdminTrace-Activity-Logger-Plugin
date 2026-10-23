<?php
/**
 * Webhook URL checks.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts only public HTTPS webhook URLs.
 */
class Url {

	/**
	 * Return a public HTTPS URL, or an empty string.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	public static function public_https( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/[\s\x00-\x1F]/', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		if ( 'localhost' === $host || substr( $host, -6 ) === '.local' || substr( $host, -10 ) === '.localhost' ) {
			return '';
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $public ) {
				return '';
			}
		}

		return $url;
	}
}
