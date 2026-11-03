<?php
/**
 * WordPress test suite configuration, read from the environment.
 *
 * The suite drops and recreates every table in SRAH_TESTS_DB_NAME on each run.
 * Point it at a database used for nothing else.
 *
 * @package AdvanceHistory
 */

$srah_env = static function ( $name, $fallback ) {
	$value = getenv( $name );
	return false === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $srah_env( 'SRAH_TESTS_WP_CORE_DIR', '/tmp/wordpress/' ), '/' ) . '/' );

define( 'DB_NAME', $srah_env( 'SRAH_TESTS_DB_NAME', 'srah_phpunit' ) );
define( 'DB_USER', $srah_env( 'SRAH_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $srah_env( 'SRAH_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', $srah_env( 'SRAH_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test suite reads this global.

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'AdminTrace Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
