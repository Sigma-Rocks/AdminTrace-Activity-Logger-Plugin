<?php
/**
 * Editor activity panel.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows recent events for the post being edited.
 */
class Post_Panel {

	/**
	 * Register the meta box.
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
	}

	/**
	 * Add the meta box for editable content.
	 */
	public static function add() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || '' === $screen->post_type ) {
			return;
		}
		add_meta_box(
			'admintrace-activity-logger-post',
			__( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ),
			array( __CLASS__, 'render' ),
			$screen->post_type,
			'side',
			'default'
		);
	}

	/**
	 * Meta box body.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render( $post ) {
		if ( ! $post instanceof \WP_Post || ! $post->ID ) {
			echo '<p>' . esc_html__( 'Save this item to see its journal.', 'admintrace-activity-logger' ) . '</p>';
			return;
		}
		$args = Access::constrain(
			array(
				'object_type' => 'content',
				'object_id'   => (int) $post->ID,
				'per_page'    => 8,
				'page'        => 1,
			)
		);
		$rows = Groups::collapse( Query::recent( 8, $args ) );
		echo '<div class="srah-widget">';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No journal entries for this item yet.', 'admintrace-activity-logger' ) . '</p>';
		} else {
			echo '<div class="srah-timeline srah-timeline-compact">';
			foreach ( $rows as $row ) {
				Renderer::card( Access::present( $row, ! empty( $args['include_ip'] ) ), array( 'compact' => true ) );
			}
			echo '</div>';
		}
		echo '</div>';
	}
}
