<?php
/**
 * Admin notices.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-request admin notices stored per user.
 */
class Notices {

	/**
	 * Queue a notice and redirect.
	 *
	 * @param string $type    success, warning, or error.
	 * @param string $text    Plain text.
	 * @param string $page    Admin page slug to return to.
	 */
	public static function redirect( $type, $text, $page = 'advance-history' ) {
		set_transient(
			self::key(),
			array(
				'type' => $type,
				'text' => $text,
			),
			MINUTE_IN_SECONDS
		);

		$target = admin_url( 'admin.php?page=' . sanitize_key( $page ) );
		$ref    = wp_get_referer();
		if ( is_string( $ref ) && false !== strpos( $ref, 'page=advance-history' ) ) {
			$target = $ref;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Print a queued notice.
	 */
	public static function render() {
		$notice = get_transient( self::key() );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return;
		}
		delete_transient( self::key() );
		$class = 'notice-success';
		if ( isset( $notice['type'] ) && 'warning' === $notice['type'] ) {
			$class = 'notice-warning';
		} elseif ( isset( $notice['type'] ) && 'error' === $notice['type'] ) {
			$class = 'notice-error';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
	}

	/**
	 * Transient key for the current user.
	 *
	 * @return string
	 */
	private static function key() {
		return 'ah_notice_' . get_current_user_id();
	}
}
