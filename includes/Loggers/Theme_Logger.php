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
		return __( 'Themes', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Theme switches, installs, updates, and deletion.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'switch_theme', 'on_switch', 3 );
		$this->listen( 'deleted_theme', 'on_deleted', 2 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
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
					__( 'Switched the theme to "%1$s" from "%2$s".', 'advance-history' ),
					$new_label,
					'' !== $old_label ? $old_label : __( 'none', 'advance-history' )
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
					__( 'Deleted the theme "%s".', 'advance-history' ),
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
							? __( 'Installed the theme "%s".', 'advance-history' )
							/* translators: %s: theme name. */
							: __( 'Updated the theme "%s".', 'advance-history' ),
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
