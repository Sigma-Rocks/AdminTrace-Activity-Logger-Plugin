<?php
/**
 * Dashboard widget.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Groups;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recent activity on the WordPress dashboard.
 */
class Dashboard_Widget {

	/**
	 * Register the widget.
	 */
	public static function register() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add' ) );
	}

	/**
	 * Add the widget when enabled.
	 */
	public static function add() {
		if ( empty( Settings::all()['dashboard_widget'] ) || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget( 'advance_history_widget', __( 'Advance History', 'advance-history' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Widget body.
	 */
	public static function render() {
		$args   = Access::constrain( array( 'per_page' => 6, 'page' => 1 ) );
		$rows   = Groups::collapse( Query::recent( 6, $args ) );
		$scoped = ! current_user_can( Capabilities::MANAGE );
		$stats  = Query::stats( $scoped, $scoped ? Vocabulary::viewer_categories() : array() );
		echo '<div class="ah-widget">';
		echo '<p class="ah-widget-counts">' . esc_html(
			sprintf(
				/* translators: 1: events today, 2: events in 7 days. */
				__( '%1$d today · %2$d in 7 days', 'advance-history' ),
				(int) $stats['today'],
				(int) $stats['week']
			)
		) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No activity yet.', 'advance-history' ) . '</p>';
		} else {
			echo '<div class="ah-timeline ah-timeline-compact">';
			foreach ( $rows as $row ) {
				Renderer::card( Access::present( $row, ! empty( $args['include_ip'] ) ), array( 'compact' => true ) );
			}
			echo '</div>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=advance-history' ) ) . '">' . esc_html__( 'Open the journal', 'advance-history' ) . '</a></p>';
		echo '</div>';
	}
}
