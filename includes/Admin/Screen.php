<?php
/**
 * Shared admin navigation.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links between the journal and its settings pages.
 */
class Screen {

	/**
	 * Page links for someone who can manage the plugin.
	 */
	public static function nav() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		$items = array(
			'admintrace-activity-logger' => __( 'Journal', 'admintrace-activity-logger' ),
		);
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$items['admintrace-activity-logger-events']   = __( 'Events', 'admintrace-activity-logger' );
			$items['admintrace-activity-logger-files']    = __( 'Files and database', 'admintrace-activity-logger' );
			$items['admintrace-activity-logger-display']  = __( 'Display', 'admintrace-activity-logger' );
			$items['admintrace-activity-logger-settings'] = __( 'Settings', 'admintrace-activity-logger' );
		}
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- marks the current tab only.
		echo '<nav class="srah-nav" aria-label="' . esc_attr__( 'AdminTrace pages', 'admintrace-activity-logger' ) . '">';
		foreach ( $items as $slug => $label ) {
			$url = menu_page_url( $slug, false );
			if ( ! is_string( $url ) || '' === $url ) {
				$url = admin_url( 'admin.php?page=' . $slug );
			}
			echo '<a href="' . esc_url( $url ) . '"' . ( $current === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
}
