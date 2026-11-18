<?php
/**
 * Plugin Name: AdminTrace Activity Logger
 * Description: WordPress activity log and audit trail for content, users, plugins, themes, orders, settings, an optional email log, and Google reCAPTCHA v3. If you find an issue, or want another feature, please open a support ticket.
 * Version: 1.0.6
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Sigma Rocks
 * Author URI: https://sigmarocks.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: admintrace-activity-logger
 * Domain Path: /languages
 *
 * @package AdvanceHistory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SRAH_VERSION', '1.0.6' );
define( 'SRAH_DB_VERSION', '4' );
define( 'SRAH_FILE', __FILE__ );
define( 'SRAH_PATH', plugin_dir_path( __FILE__ ) );
define( 'SRAH_URL', plugin_dir_url( __FILE__ ) );
define( 'SRAH_DONATE_URL', 'https://www.paypal.com/paypalme/sigmarocks' );

require_once SRAH_PATH . 'includes/Autoloader.php';

SigmaRocks\AdvanceHistory\Autoloader::register();

register_activation_hook( __FILE__, array( 'SigmaRocks\\AdvanceHistory\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SigmaRocks\\AdvanceHistory\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		SigmaRocks\AdvanceHistory\Plugin::boot();
	},
	20
);

/**
 * Record an activity event.
 *
 * Logging failures return 0 and never interrupt the caller.
 *
 * @param array $event Event arguments.
 * @return int Event ID, or 0 when the event was not stored.
 */
function srah_log( $event ) {
	if ( ! is_array( $event ) ) {
		return 0;
	}

	return SigmaRocks\AdvanceHistory\Recorder::log( $event );
}
