<?php
/**
 * Theme events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records theme switches, installs, updates, and deletion.
 */
class Theme_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'theme';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Themes', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Theme switches, installs, updates, deletion, and auto-update toggles.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'switch_theme', 'on_switch', 3 );
		$this->listen( 'deleted_theme', 'on_deleted', 2 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
		$this->listen( 'update_option_auto_update_themes', 'on_auto_update', 2 );
		$this->listen( 'update_site_option_auto_update_themes', 'on_site_auto_update', 3 );
	}

	/**
	 * Theme switched.
	 *
	 * @param string        $new_name  New theme name.
	 * @param \WP_Theme|mixed $new_theme New theme.
	 * @param \WP_Theme|mixed $old_theme Previous theme.
	 */
	public function on_switch( $new_name, $new_theme, $old_theme ) {
		$new_label = ( $new_theme instanceof \WP_Theme ) ? $new_theme->get( 'Name' ) : (string) $new_name;
		$old_label = ( $old_theme instanceof \WP_Theme ) ? $old_theme->get( 'Name' ) : '';
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: new theme, 2: previous theme. */
					__( 'Switched the theme to "%1$s" from "%2$s".', 'admintrace-activity-logger' ),
					$new_label,
					'' !== $old_label ? $old_label : __( 'none', 'admintrace-activity-logger' )
				),
				'category'     => 'theme',
				'action'       => 'theme.switched',
				'severity'     => 'notice',
				'object_type'  => 'theme',
				'object_title' => $new_label,
			)
		);
	}

	/**
	 * Theme deleted.
	 *
	 * @param string $stylesheet Stylesheet slug.
	 * @param bool   $deleted    Whether deletion succeeded.
	 */
	public function on_deleted( $stylesheet, $deleted ) {
		if ( ! $deleted ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: theme stylesheet. */
					__( 'Deleted the theme "%s".', 'admintrace-activity-logger' ),
					Text::plain( $stylesheet, 80 )
				),
				'category'     => 'theme',
				'action'       => 'theme.deleted',
				'severity'     => 'warning',
				'object_type'  => 'theme',
				'object_title' => Text::plain( $stylesheet, 80 ),
			)
		);
	}

	/**
	 * Auto-update list changed on this site.
	 *
	 * @param mixed $old Previous theme stylesheets.
	 * @param mixed $new New theme stylesheets.
	 */
	public function on_auto_update( $old, $new ) {
		$this->diff_auto_updates( $old, $new );
	}

	/**
	 * Auto-update list changed for the network.
	 *
	 * @param mixed $option Ignored option name.
	 * @param mixed $new    New theme stylesheets.
	 * @param mixed $old    Previous theme stylesheets.
	 */
	public function on_site_auto_update( $option, $new, $old ) {
		unset( $option );
		$this->diff_auto_updates( $old, $new );
	}

	/**
	 * Record themes whose auto-update flag flipped.
	 *
	 * @param mixed $old Previous stylesheets.
	 * @param mixed $new New stylesheets.
	 */
	private function diff_auto_updates( $old, $new ) {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		$enabled  = array_values( array_diff( $new, $old ) );
		$disabled = array_values( array_diff( $old, $new ) );
		if ( count( $enabled ) + count( $disabled ) > 12 ) {
			$this->log(
				array(
					'summary'  => sprintf(
						/* translators: 1: enabled count, 2: disabled count. */
						__( 'Changed theme auto-updates: %1$d enabled and %2$d disabled.', 'admintrace-activity-logger' ),
						count( $enabled ),
						count( $disabled )
					),
					'category' => 'theme',
					'action'   => 'theme.auto_update',
					'severity' => 'notice',
				)
			);
			return;
		}
		foreach ( $enabled as $stylesheet ) {
			$this->log_auto_update( $stylesheet, true );
		}
		foreach ( $disabled as $stylesheet ) {
			$this->log_auto_update( $stylesheet, false );
		}
	}

	/**
	 * Record one theme auto-update change.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @param bool   $enabled    Whether auto-update was turned on.
	 */
	private function log_auto_update( $stylesheet, $enabled ) {
		$theme = wp_get_theme( $stylesheet );
		$name  = $theme->exists() ? $theme->get( 'Name' ) : Text::plain( $stylesheet, 80 );
		$this->log(
			array(
				'summary'      => $enabled
					? sprintf(
						/* translators: %s: theme name. */
						__( 'Turned on automatic updates for the theme "%s".', 'admintrace-activity-logger' ),
						$name
					)
					: sprintf(
						/* translators: %s: theme name. */
						__( 'Turned off automatic updates for the theme "%s".', 'admintrace-activity-logger' ),
						$name
					),
				'category'     => 'theme',
				'action'       => 'theme.auto_update',
				'severity'     => 'notice',
				'object_type'  => 'theme',
				'object_title' => $name,
				'context'      => array(
					'stylesheet' => Text::plain( $stylesheet, 80 ),
					'enabled'    => (bool) $enabled,
				),
			)
		);
	}

	/**
	 * Theme install or update.
	 *
	 * @param object $upgrader Upgrader.
	 * @param array  $extra    Hook data.
	 */
	public function on_upgrader( $upgrader, $extra ) {
		unset( $upgrader );
		if ( empty( $extra['type'] ) || 'theme' !== $extra['type'] ) {
			return;
		}
		$action = isset( $extra['action'] ) ? (string) $extra['action'] : 'update';
		$themes = array();
		if ( ! empty( $extra['themes'] ) && is_array( $extra['themes'] ) ) {
			$themes = $extra['themes'];
		} elseif ( ! empty( $extra['theme'] ) && is_string( $extra['theme'] ) ) {
			$themes = array( $extra['theme'] );
		}
		foreach ( $themes as $stylesheet ) {
			$theme = wp_get_theme( $stylesheet );
			$name  = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
			$this->log(
				array(
					'summary'      => sprintf(
						'install' === $action
							/* translators: %s: theme name. */
							? __( 'Installed the theme "%s".', 'admintrace-activity-logger' )
							/* translators: %s: theme name. */
							: __( 'Updated the theme "%s".', 'admintrace-activity-logger' ),
						$name
					),
					'category'     => 'theme',
					'action'       => 'install' === $action ? 'theme.installed' : 'theme.updated',
					'severity'     => 'notice',
					'object_type'  => 'theme',
					'object_title' => $name,
				)
			);
		}
	}
}
