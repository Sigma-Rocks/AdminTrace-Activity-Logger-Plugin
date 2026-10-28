<?php
/**
 * Admin assets.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Settings;

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
		self::enqueue_menu_icon();

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$ours = in_array( $page, array( 'admintrace-activity-logger', 'admintrace-activity-logger-settings' ), true );
		$related = in_array( $hook, array( 'index.php', 'post.php', 'post-new.php' ), true );
		if ( ! $ours && ! $related ) {
			return;
		}
		if ( $related && ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		$style = SRAH_PATH . 'assets/css/admin.css';
		wp_enqueue_style(
			'admintrace-activity-logger-admin',
			SRAH_URL . 'assets/css/admin.css',
			array(),
			file_exists( $style ) ? (string) filemtime( $style ) : SRAH_VERSION
		);

		if ( ! $ours ) {
			return;
		}

		$script = SRAH_PATH . 'assets/js/admin.js';
		wp_enqueue_script(
			'admintrace-activity-logger-admin',
			SRAH_URL . 'assets/js/admin.js',
			array(),
			file_exists( $script ) ? (string) filemtime( $script ) : SRAH_VERSION,
			true
		);
		wp_localize_script(
			'admintrace-activity-logger-admin',
			'srahAdmin',
			array(
				'copied' => __( 'Copied', 'admintrace-activity-logger' ),
			)
		);
	}

	/**
	 * Size the top-level menu image on every admin screen.
	 *
	 * The journal stylesheet loads only on plugin screens, and the sidebar
	 * mark is visible everywhere, so these rules are a separate stylesheet.
	 */
	private static function enqueue_menu_icon() {
		if ( 'toplevel' !== Settings::all()['menu_location'] ) {
			return;
		}

		$style = SRAH_PATH . 'assets/css/menu-icon.css';
		wp_enqueue_style(
			'admintrace-activity-logger-menu-icon',
			SRAH_URL . 'assets/css/menu-icon.css',
			array(),
			file_exists( $style ) ? (string) filemtime( $style ) : SRAH_VERSION
		);
	}
}
