<?php
/**
 * Admin assets.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the journal stylesheet and script on relevant admin screens.
 */
class Assets {

	/**
	 * Register the enqueue hook.
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$ours = in_array( $page, array( 'advance-history', 'advance-history-settings' ), true );
		$related = in_array( $hook, array( 'index.php', 'post.php', 'post-new.php' ), true );
		if ( ! $ours && ! $related ) {
			return;
		}
		if ( $related && ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		$style = AH_PATH . 'assets/css/admin.css';
		wp_enqueue_style(
			'advance-history-admin',
			AH_URL . 'assets/css/admin.css',
			array(),
			file_exists( $style ) ? (string) filemtime( $style ) : AH_VERSION
		);

		if ( ! $ours ) {
			return;
		}

		$script = AH_PATH . 'assets/js/admin.js';
		wp_enqueue_script(
			'advance-history-admin',
			AH_URL . 'assets/js/admin.js',
			array(),
			file_exists( $script ) ? (string) filemtime( $script ) : AH_VERSION,
			true
		);
		wp_localize_script(
			'advance-history-admin',
			'advanceHistoryAdmin',
			array(
				'copied' => __( 'Copied', 'advance-history' ),
			)
		);
	}
}
