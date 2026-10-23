<?php
/**
 * Plugin Name: Advance History
 * Description: A readable activity journal for WordPress. See who changed content, users, plugins, orders, and settings.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Sigma Rocks
 * Author URI: https://sigmarocks.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: advance-history
 * Domain Path: /languages
 *
 * @package AdvanceHistory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AH_VERSION', '1.0.0' );
define( 'AH_DB_VERSION', '1' );
define( 'AH_FILE', __FILE__ );
define( 'AH_PATH', plugin_dir_path( __FILE__ ) );
define( 'AH_URL', plugin_dir_url( __FILE__ ) );

require_once AH_PATH . 'includes/Autoloader.php';

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
function advance_history_log( $event ) {
	if ( ! is_array( $event ) ) {
		return 0;
	}

	return SigmaRocks\AdvanceHistory\Recorder::log( $event );
}
