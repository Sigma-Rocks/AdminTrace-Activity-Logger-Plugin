<?php
/**
 * Class autoloader.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the plugin namespace onto the includes directory.
 */
class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
