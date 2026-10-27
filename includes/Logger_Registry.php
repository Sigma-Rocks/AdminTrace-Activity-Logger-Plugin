<?php
/**
 * Logger registry.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Loggers\Comment_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Commerce_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Content_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Core_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Integration_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Logger;
use SigmaRocks\AdvanceHistory\Loggers\Media_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Menu_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Plugin_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Privacy_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Security_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Settings_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Taxonomy_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Theme_Logger;
use SigmaRocks\AdvanceHistory\Loggers\User_Logger;
use SigmaRocks\AdvanceHistory\Loggers\Widget_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and registers event loggers.
 */
class Logger_Registry {

	/**
	 * Logger instances.
	 *
	 * @var Logger[]
	 */
	private static $loggers = array();

	/**
	 * Boot the built-in loggers.
	 */
	public static function boot() {
		$classes = apply_filters(
			'srah_loggers',
			array(
				User_Logger::class,
				Content_Logger::class,
				Media_Logger::class,
				Comment_Logger::class,
				Plugin_Logger::class,
				Theme_Logger::class,
				Core_Logger::class,
				Taxonomy_Logger::class,
				Settings_Logger::class,
				Menu_Logger::class,
				Widget_Logger::class,
				Privacy_Logger::class,
				Commerce_Logger::class,
				Integration_Logger::class,
				Security_Logger::class,
			)
		);

		if ( ! is_array( $classes ) ) {
			return;
		}

		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			$logger = new $class();
			if ( ! $logger instanceof Logger ) {
				continue;
			}
			self::$loggers[ $logger->id() ] = $logger;
			$logger->register();
		}
	}

	/**
	 * Registered loggers.
	 *
	 * @return Logger[]
	 */
	public static function all() {
		return self::$loggers;
	}

	/**
	 * One logger.
	 *
	 * @param string $id Logger ID.
	 * @return Logger|null
	 */
	public static function get( $id ) {
		return isset( self::$loggers[ $id ] ) ? self::$loggers[ $id ] : null;
	}
}
