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
 * Records core updates, translations, and available-update notices.
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
		return __( 'WordPress updates', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'WordPress core updates, language packs, and newly available updates.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( '_core_updated_successfully', 'on_core_updated', 1 );
		$this->listen( 'upgrader_process_complete', 'on_upgrader', 2 );
		$this->listen( 'automatic_updates_complete', 'on_automatic', 1 );
		$this->listen( 'set_site_transient_update_core', 'on_core_transient', 1 );
		$this->listen( 'set_site_transient_update_plugins', 'on_plugin_transient', 1 );
		$this->listen( 'set_site_transient_update_themes', 'on_theme_transient', 1 );
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
					__( 'Updated WordPress to %s.', 'advance-history' ),
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
						__( 'Updated %d language pack(s).', 'advance-history' ),
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
					__( 'Automatic updates finished. %1$d succeeded and %2$d failed.', 'advance-history' ),
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

	/**
	 * Core update availability changed.
	 *
	 * @param object $transient Update transient.
	 */
	public function on_core_transient( $transient ) {
		$version = '';
		if ( is_object( $transient ) && ! empty( $transient->updates ) && is_array( $transient->updates ) ) {
			foreach ( $transient->updates as $update ) {
				if ( is_object( $update ) && isset( $update->current ) && isset( $update->response ) && 'upgrade' === $update->response ) {
					$version = (string) $update->current;
					break;
				}
			}
		}
		$this->fingerprint(
			'core',
			$version,
			'' !== $version
				? sprintf(
					/* translators: %s: WordPress version. */
					__( 'WordPress %s is available.', 'advance-history' ),
					Text::plain( $version, 20 )
				)
				: ''
		);
	}

	/**
	 * Plugin update list changed.
	 *
	 * @param object $transient Update transient.
	 */
	public function on_plugin_transient( $transient ) {
		$names = array();
		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $file => $data ) {
				$version = is_object( $data ) && isset( $data->new_version ) ? $data->new_version : '';
				$names[] = wp_basename( (string) $file ) . '@' . $version;
			}
		}
		sort( $names );
		$shown = implode( ', ', array_slice( $names, 0, 12 ) );
		$this->fingerprint(
			'plugins',
			implode( '|', $names ),
			$names
				? sprintf(
					/* translators: 1: count, 2: plugin list. */
					__( '%1$d plugin update(s) are available: %2$s.', 'advance-history' ),
					count( $names ),
					$shown
				)
				: ''
		);
	}

	/**
	 * Theme update list changed.
	 *
	 * @param object $transient Update transient.
	 */
	public function on_theme_transient( $transient ) {
		$names = array();
		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $slug => $data ) {
				$version = is_object( $data ) && isset( $data->new_version ) ? $data->new_version : '';
				$names[] = sanitize_key( (string) $slug ) . '@' . $version;
			}
		}
		sort( $names );
		$this->fingerprint(
			'themes',
			implode( '|', $names ),
			$names
				? sprintf(
					/* translators: %d: number of theme updates. */
					__( '%d theme update(s) are available.', 'advance-history' ),
					count( $names )
				)
				: ''
		);
	}

	/**
	 * Log when an update fingerprint changes.
	 *
	 * @param string $channel Channel name.
	 * @param string $value   Fingerprint value.
	 * @param string $summary Summary. Empty values are not logged.
	 */
	private function fingerprint( $channel, $value, $summary ) {
		$stored = get_option( 'advance_history_update_fingerprint', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$hash = md5( (string) $value );
		if ( isset( $stored[ $channel ] ) && $stored[ $channel ] === $hash ) {
			return;
		}
		$stored[ $channel ] = $hash;
		update_option( 'advance_history_update_fingerprint', $stored, false );
		if ( '' === $summary || '' === $value ) {
			return;
		}
		$this->log(
			array(
				'summary'   => $summary,
				'category'  => 'core',
				'action'    => 'core.updates_available',
				'severity'  => 'info',
				'initiator' => 'cron',
				'user_id'   => 0,
			)
		);
	}
}
