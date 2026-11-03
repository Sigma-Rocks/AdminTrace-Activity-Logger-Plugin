<?php
/**
 * Plugin bootstrap.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Admin\Admin_Bar;
use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Admin\Post_Panel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin services after other plugins have loaded.
 */
class Plugin {

	/**
	 * Boot services once.
	 */
	public static function boot() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		Installer::maybe_upgrade();
		add_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ) );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_site_table' ), 10, 2 );
		Capabilities::register();
		Retention::register();
		Email_Log::register();
		Watch::register();
		Notifications::register();
		Privacy::register();
		Feed::register();
		Rest_Controller::register();
		Logger_Registry::boot();

		if ( is_admin() ) {
			Menu::register();
		}

		Admin_Bar::register();
		Post_Panel::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
	}
}
