<?php
/**
 * WordPress core events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	/**
	 * Records completed WordPress core updates, language packs, and automatic updates.
	 */
class Core_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'core';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WordPress updates', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Completed WordPress updates, language packs, and automatic updates.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( '_core_updated_successfully', 'on_core_updated', 1 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
		$this->listen( 'automatic_updates_complete', 'on_automatic', 1 );
	}

	/**
	 * Core update finished.
	 *
	 * @param string $version New version.
	 */
	public function on_core_updated( $version ) {
		$this->log(
			array(
				'summary'   => sprintf(
					/* translators: %s: WordPress version. */
					__( 'Updated WordPress to %s.', 'admintrace-activity-logger' ),
					Text::plain( $version, 20 )
				),
				'category'  => 'core',
				'action'    => 'core.updated',
				'severity'  => 'notice',
			)
		);
	}

	/**
	 * Translation updates that pass through the upgrader.
	 *
	 * @param object $upgrader Upgrader.
	 * @param array  $extra    Hook data.
	 */
	public function on_upgrader( $upgrader, $extra ) {
		unset( $upgrader );
		if ( empty( $extra['type'] ) || 'translation' !== $extra['type'] ) {
			return;
		}
		if ( 'translation' === $extra['type'] ) {
			$count = isset( $extra['translations'] ) && is_array( $extra['translations'] ) ? count( $extra['translations'] ) : 1;
			$this->log(
				array(
					'summary'  => sprintf(
						/* translators: %d: number of language packs. */
						__( 'Updated %d language pack(s).', 'admintrace-activity-logger' ),
						$count
					),
					'category' => 'core',
					'action'   => 'core.translations',
					'severity' => 'info',
				)
			);
		}
	}

	/**
	 * Automatic update batch.
	 *
	 * @param array $results Update results.
	 */
	public function on_automatic( $results ) {
		if ( ! is_array( $results ) ) {
			return;
		}
		$ok = 0;
		$failed = 0;
		foreach ( $results as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $item ) {
				if ( is_object( $item ) && ! empty( $item->result ) && ! is_wp_error( $item->result ) ) {
					++$ok;
				} else {
					++$failed;
				}
			}
		}
		if ( 0 === $ok && 0 === $failed ) {
			return;
		}
		$this->log(
			array(
				'summary'   => sprintf(
					/* translators: 1: successful updates, 2: failed updates. */
					__( 'Automatic updates finished. %1$d succeeded and %2$d failed.', 'admintrace-activity-logger' ),
					$ok,
					$failed
				),
				'category'  => 'core',
				'action'    => 'core.automatic_updates',
				'severity'  => $failed > 0 ? 'warning' : 'notice',
				'initiator' => 'cron',
				'user_id'   => 0,
			)
		);
	}

}
