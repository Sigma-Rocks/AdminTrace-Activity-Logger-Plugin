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
 * Records WordPress updates, language packs, and changes in the available-update list.
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
		return __( 'WordPress updates, language packs, automatic updates, and notices when updates become available.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( '_core_updated_successfully', 'on_core_updated', 1 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
		$this->listen( 'automatic_updates_complete', 'on_automatic', 1 );
		$this->listen( 'set_site_transient_update_plugins', 'on_plugin_updates', 1 );
		$this->listen( 'set_site_transient_update_themes', 'on_theme_updates', 1 );
		$this->listen( 'set_site_transient_update_core', 'on_core_updates', 1 );
	}

	/**
	 * Core update finished.
	 *
	 * @param string $version New version.
	 */
	public function on_core_updated( $version ) {
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %s: WordPress version. */
					__( 'Updated WordPress to %s.', 'admintrace-activity-logger' ),
					Text::plain( $version, 20 )
				),
				'category' => 'core',
				'action'   => 'core.updated',
				'severity' => 'notice',
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
		$ok     = 0;
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
		$version = $this->core_version( $results );
		$summary = '' !== $version
			? sprintf(
				/* translators: 1: WordPress version, 2: successful updates, 3: failed updates. */
				__( 'Automatic updates finished. WordPress %1$s was included. %2$d succeeded and %3$d failed.', 'admintrace-activity-logger' ),
				$version,
				$ok,
				$failed
			)
			: sprintf(
				/* translators: 1: successful updates, 2: failed updates. */
				__( 'Automatic updates finished. %1$d succeeded and %2$d failed.', 'admintrace-activity-logger' ),
				$ok,
				$failed
			);
		$this->log(
			array(
				'summary'   => $summary,
				'category'  => 'core',
				'action'    => 'core.automatic_updates',
				'severity'  => $failed > 0 ? 'warning' : 'notice',
				'initiator' => 'cron',
				'user_id'   => 0,
			)
		);
	}

	/**
	 * Plugin update list refreshed.
	 *
	 * @param mixed $value Update transient.
	 */
	public function on_plugin_updates( $value ) {
		if ( false === $value ) {
			return;
		}
		$count = ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) ? count( $value->response ) : 0;
		$this->notice_count( 'plugins', $count );
	}

	/**
	 * Theme update list refreshed.
	 *
	 * @param mixed $value Update transient.
	 */
	public function on_theme_updates( $value ) {
		if ( false === $value ) {
			return;
		}
		$count = ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) ? count( $value->response ) : 0;
		$this->notice_count( 'themes', $count );
	}

	/**
	 * Core update list refreshed.
	 *
	 * @param mixed $value Update transient.
	 */
	public function on_core_updates( $value ) {
		if ( false === $value ) {
			return;
		}
		$count = 0;
		if ( is_object( $value ) && isset( $value->updates ) && is_array( $value->updates ) ) {
			foreach ( $value->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
					++$count;
				}
			}
		}
		$this->notice_count( 'core', $count );
	}

	/**
	 * Log when the number of waiting updates changes.
	 *
	 * The first observation is stored and not logged, so activation does not
	 * invent a history of updates that were already waiting.
	 *
	 * @param string $kind  plugins, themes, or core.
	 * @param int    $count Waiting updates.
	 */
	private function notice_count( $kind, $count ) {
		$count  = max( 0, (int) $count );
		$stored = get_option( 'srah_update_fingerprint', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		if ( ! array_key_exists( $kind, $stored ) ) {
			$stored[ $kind ] = $count;
			update_option( 'srah_update_fingerprint', $stored, false );
			return;
		}
		$previous = (int) $stored[ $kind ];
		if ( $previous === $count ) {
			return;
		}
		$stored[ $kind ] = $count;
		update_option( 'srah_update_fingerprint', $stored, false );

		if ( 'themes' === $kind ) {
			$summary = sprintf(
				/* translators: 1: previous count, 2: new count. */
				__( 'Available theme updates changed from %1$d to %2$d.', 'admintrace-activity-logger' ),
				$previous,
				$count
			);
		} elseif ( 'core' === $kind ) {
			$summary = sprintf(
				/* translators: 1: previous count, 2: new count. */
				__( 'Available WordPress updates changed from %1$d to %2$d.', 'admintrace-activity-logger' ),
				$previous,
				$count
			);
		} else {
			$summary = sprintf(
				/* translators: 1: previous count, 2: new count. */
				__( 'Available plugin updates changed from %1$d to %2$d.', 'admintrace-activity-logger' ),
				$previous,
				$count
			);
		}
		$this->log(
			array(
				'summary'   => $summary,
				'category'  => 'core',
				'action'    => 'core.updates_available',
				'severity'  => 'info',
				'initiator' => 'system',
				'user_id'   => 0,
			)
		);
	}

	/**
	 * WordPress version included in an automatic update batch.
	 *
	 * @param array $results Update results.
	 * @return string
	 */
	private function core_version( $results ) {
		if ( empty( $results['core'] ) || ! is_array( $results['core'] ) ) {
			return '';
		}
		foreach ( $results['core'] as $item ) {
			$update = null;
			if ( is_array( $item ) && isset( $item['item'] ) ) {
				$update = $item['item'];
			} elseif ( is_object( $item ) && isset( $item->item ) ) {
				$update = $item->item;
			}
			if ( is_object( $update ) && ! empty( $update->version ) ) {
				return Text::plain( $update->version, 20 );
			}
		}
		return '';
	}
}
