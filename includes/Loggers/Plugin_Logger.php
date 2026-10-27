<?php
/**
 * Plugin lifecycle events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records plugin installation, updates, activation, and deletion.
 */
class Plugin_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'plugin';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Plugins', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Plugin installs, updates, activation, deactivation, and deletion.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'activated_plugin', 'on_activated', 2 );
		$this->listen( 'deactivated_plugin', 'on_deactivated', 2 );
		$this->listen( 'deleted_plugin', 'on_deleted', 2 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $network Network-wide.
	 */
	public function on_activated( $plugin, $network = false ) {
		$this->log_plugin(
			$plugin,
			'plugin.activated',
			/* translators: 1: plugin name, 2: version. */
			__( 'Activated the plugin "%1$s" (%2$s).', 'admintrace-activity-logger' ),
			'notice',
			$network
		);
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $network Network-wide.
	 */
	public function on_deactivated( $plugin, $network = false ) {
		$this->log_plugin(
			$plugin,
			'plugin.deactivated',
			/* translators: 1: plugin name, 2: version. */
			__( 'Deactivated the plugin "%1$s" (%2$s).', 'admintrace-activity-logger' ),
			'notice',
			$network
		);
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $deleted Whether deletion succeeded.
	 */
	public function on_deleted( $plugin, $deleted ) {
		if ( ! $deleted ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: plugin file. */
					__( 'Deleted the plugin "%s".', 'admintrace-activity-logger' ),
					Text::plain( $plugin, 120 )
				),
				'category'     => 'plugin',
				'action'       => 'plugin.deleted',
				'severity'     => 'warning',
				'object_type'  => 'plugin',
				'object_title' => wp_basename( (string) $plugin ),
			)
		);
	}

	/**
	 * Install or update completed.
	 *
	 * @param object $upgrader Upgrader.
	 * @param array  $extra    Hook data.
	 */
	public function on_upgrader( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}
		$action = isset( $extra['action'] ) ? (string) $extra['action'] : 'update';
		$files  = array();
		if ( ! empty( $extra['plugins'] ) && is_array( $extra['plugins'] ) ) {
			$files = $extra['plugins'];
		} elseif ( ! empty( $extra['plugin'] ) && is_string( $extra['plugin'] ) ) {
			$files = array( $extra['plugin'] );
		} elseif ( is_object( $upgrader ) && ! empty( $upgrader->result['destination_name'] ) ) {
			$files = array( (string) $upgrader->result['destination_name'] );
		}
		foreach ( $files as $file ) {
			if ( 'install' === $action ) {
				/* translators: 1: plugin name, 2: version. */
				$pattern = __( 'Installed the plugin "%1$s" (%2$s).', 'admintrace-activity-logger' );
				$event   = 'plugin.installed';
			} else {
				/* translators: 1: plugin name, 2: version. */
				$pattern = __( 'Updated the plugin "%1$s" (%2$s).', 'admintrace-activity-logger' );
				$event   = 'plugin.updated';
			}
			$this->log_plugin( $file, $event, $pattern, 'notice', false );
		}
	}

	/**
	 * Log a plugin by its file.
	 *
	 * @param string $plugin Plugin file.
	 * @param string $action Action.
	 * @param string $pattern Summary pattern.
	 * @param string $severity Severity.
	 * @param bool   $network Network flag.
	 */
	private function log_plugin( $plugin, $action, $pattern, $severity, $network ) {
		$meta    = $this->plugin_meta( $plugin );
		$version = '' !== $meta['version'] ? $meta['version'] : __( 'unknown version', 'admintrace-activity-logger' );
		$this->log(
			array(
				'summary'      => sprintf( $pattern, $meta['name'], $version ),
				'category'     => 'plugin',
				'action'       => $action,
				'severity'     => $severity,
				'object_type'  => 'plugin',
				'object_title' => $meta['name'],
				'context'      => array(
					'file'    => Text::plain( $plugin, 160 ),
					'version' => $meta['version'],
					'author'  => $meta['author'],
					'network' => (bool) $network,
				),
			)
		);
	}

	/**
	 * Public plugin header fields.
	 *
	 * @param string $plugin Plugin file.
	 * @return array{name:string,version:string,author:string}
	 */
	private function plugin_meta( $plugin ) {
		$name = wp_basename( (string) $plugin );
		$meta = array(
			'name'    => $name,
			'version' => '',
			'author'  => '',
		);
		if ( ! function_exists( 'get_plugin_data' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
		$path = WP_PLUGIN_DIR . '/' . ltrim( (string) $plugin, '/' );
		if ( function_exists( 'get_plugin_data' ) && is_readable( $path ) ) {
			$data            = get_plugin_data( $path, false, false );
			$meta['name']    = ! empty( $data['Name'] ) ? $data['Name'] : $name;
			$meta['version'] = ! empty( $data['Version'] ) ? $data['Version'] : '';
			$meta['author']  = ! empty( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '';
		}
		return $meta;
	}
}
