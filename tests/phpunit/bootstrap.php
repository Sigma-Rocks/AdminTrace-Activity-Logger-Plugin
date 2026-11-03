<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Environment:
 * - WP_PHPUNIT__DIR or WP_TESTS_DIR: the WordPress test library. Composer's wp-phpunit/wp-phpunit sets WP_PHPUNIT__DIR.
 * - SRAH_TESTS_WP_CORE_DIR: a WordPress core checkout (ABSPATH).
 * - SRAH_TESTS_DB_*: the test database. Every table in it is dropped on each run.
 * - SRAH_TESTS_WC_FILE: woocommerce.php to load. Defaults to a sibling woocommerce plugin. Set to 0 to skip.
 * - WP_MULTISITE=1: run as a multisite network.
 *
 * @package AdvanceHistory
 */

$srah_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $srah_tests_dir ) {
	$srah_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $srah_tests_dir || ! is_file( $srah_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR, or install wp-phpunit/wp-phpunit with Composer.\n" );
	exit( 1 );
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$srah_plugin_dir = dirname( __DIR__, 2 );
$srah_wc_file    = getenv( 'SRAH_TESTS_WC_FILE' );
if ( false === $srah_wc_file ) {
	$srah_wc_file = dirname( $srah_plugin_dir ) . '/woocommerce/woocommerce.php';
}
$srah_wc_file = ( '0' !== $srah_wc_file && is_file( $srah_wc_file ) ) ? $srah_wc_file : '';
define( 'SRAH_TESTS_WC_FILE', $srah_wc_file );

require_once $srah_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $srah_plugin_dir ) {
		if ( SRAH_TESTS_WC_FILE ) {
			require SRAH_TESTS_WC_FILE;
		}
		require $srah_plugin_dir . '/admintrace-activity-logger.php';
	}
);

// WooCommerce reads its own tables on init, so install them first, as WooCommerce's own tests do.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( SRAH_TESTS_WC_FILE && class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
			wp_roles();
		}
	}
);

require $srah_tests_dir . '/includes/bootstrap.php';

// Real tables are created here, before each test opens its rollback transaction.
SigmaRocks\AdvanceHistory\Installer::install();
SigmaRocks\AdvanceHistory\Capabilities::grant_roles();

require __DIR__ . '/class-srah-test-case.php';
