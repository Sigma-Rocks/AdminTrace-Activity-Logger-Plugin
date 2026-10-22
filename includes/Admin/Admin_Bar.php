<?php
/**
 * Admin bar shortcut.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Groups;
use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recent events in the WordPress admin bar.
 */
class Admin_Bar {

	/**
	 * Register the admin bar node.
	 */
	public static function register() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'menu' ), 80 );
	}

	/**
	 * Add nodes.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function menu( $bar ) {
		if ( empty( Settings::all()['admin_bar'] ) || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'advance-history',
				'title' => esc_html__( 'Activity', 'advance-history' ),
				'href'  => admin_url( 'admin.php?page=advance-history' ),
			)
		);

		$args = Access::constrain( array( 'per_page' => 5, 'page' => 1 ) );
		$rows = Groups::collapse( Query::recent( 8, $args ) );
		if ( ! $rows ) {
			$bar->add_node(
				array(
					'id'     => 'advance-history-empty',
					'parent' => 'advance-history',
					'title'  => esc_html__( 'No activity yet', 'advance-history' ),
				)
			);
			return;
		}

		foreach ( array_slice( $rows, 0, 5 ) as $row ) {
			$row   = Access::present( $row, ! empty( $args['include_ip'] ) );
			$title = Text::plain( $row['summary'], 80 );
			$count = isset( $row['repeat_count'] ) ? (int) $row['repeat_count'] : 1;
			if ( $count > 1 ) {
				$title .= ' (' . sprintf(
					/* translators: %d: number of times this event happened. */
					_n( '%d time', '%d times', $count, 'advance-history' ),
					$count
				) . ')';
			}
			$bar->add_node(
				array(
					'id'     => 'advance-history-' . (int) $row['id'],
					'parent' => 'advance-history',
					'title'  => esc_html( $title ),
					'href'   => admin_url( 'admin.php?page=advance-history&event=' . (int) $row['id'] ),
				)
			);
		}
	}
}
