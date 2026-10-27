<?php
/**
 * Widget events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records sidebar widget assignment changes.
 */
class Widget_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'widget';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Widgets', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Widgets added to or removed from sidebars.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'update_option_sidebars_widgets', 'on_sidebars', 3 );
	}

	/**
	 * Sidebar assignments changed.
	 *
	 * @param mixed $old Previous value.
	 * @param mixed $new New value.
	 * @param string $option Option name.
	 */
	public function on_sidebars( $old, $new, $option = '' ) {
		unset( $option );
		$before  = $this->flat( $old );
		$after   = $this->flat( $new );
		$added   = array_values( array_diff( $after, $before ) );
		$removed = array_values( array_diff( $before, $after ) );
		if ( ! $added && ! $removed ) {
			return;
		}
		$this->log(
			array(
				'summary'  => __( 'Updated sidebar widgets.', 'admintrace-activity-logger' ),
				'category' => 'widget',
				'action'   => 'widget.updated',
				'severity' => 'info',
				'context'  => array(
					'added'   => array_slice( $added, 0, 20 ),
					'removed' => array_slice( $removed, 0, 20 ),
				),
			)
		);
	}

	/**
	 * Flatten sidebar assignments.
	 *
	 * @param mixed $widgets Widget option.
	 * @return string[]
	 */
	private function flat( $widgets ) {
		$out = array();
		if ( ! is_array( $widgets ) ) {
			return $out;
		}
		foreach ( $widgets as $sidebar => $items ) {
			if ( 'array_version' === $sidebar || ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( is_string( $item ) ) {
					$out[] = sanitize_key( (string) $sidebar ) . ':' . sanitize_key( $item );
				}
			}
		}
		sort( $out );
		return $out;
	}
}
