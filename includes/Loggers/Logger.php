<?php
/**
 * Logger base class.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Recorder;
use SigmaRocks\AdvanceHistory\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared behaviour for event loggers.
 */
abstract class Logger {

	/**
	 * Stable logger ID.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Settings label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Settings description.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Attach WordPress hooks.
	 */
	abstract public function register();

	/**
	 * Whether this logger is enabled.
	 *
	 * @return bool
	 */
	protected function enabled() {
		return Settings::logger_enabled( $this->id() );
	}

	/**
	 * Store an event for this logger.
	 *
	 * @param array $event Event arguments.
	 * @return int
	 */
	protected function log( array $event ) {
		try {
			if ( ! $this->enabled() ) {
				return 0;
			}
			$event['logger'] = $this->id();
			return Recorder::log( $event );
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * Listen to an action and ignore failures.
	 *
	 * @param string $hook     Hook name.
	 * @param string $method   Method on this logger.
	 * @param int    $accepted Accepted arguments.
	 * @param int    $priority Priority.
	 */
	protected function listen( $hook, $method, $accepted = 1, $priority = 10 ) {
		add_action(
			$hook,
			function () use ( $method ) {
				if ( ! $this->enabled() ) {
					return;
				}
				try {
					call_user_func_array( array( $this, $method ), func_get_args() );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			},
			$priority,
			$accepted
		);
	}
}
