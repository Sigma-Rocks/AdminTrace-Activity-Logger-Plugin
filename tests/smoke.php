<?php
/**
 * Pure unit checks. CLI only.
 *
 * @package AdvanceHistory
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Test stub for the WordPress tag stripper.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Test stub for the WordPress URL parser.
	 *
	 * @param string $url URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

require dirname( __DIR__ ) . '/includes/Support/Text.php';
require dirname( __DIR__ ) . '/includes/Support/Ip.php';
require dirname( __DIR__ ) . '/includes/Support/Diff.php';
require dirname( __DIR__ ) . '/includes/Support/Csv.php';
require dirname( __DIR__ ) . '/includes/Support/Url.php';
require dirname( __DIR__ ) . '/includes/Support/Context.php';
require dirname( __DIR__ ) . '/includes/Support/Groups.php';
require dirname( __DIR__ ) . '/includes/Settings.php';
require dirname( __DIR__ ) . '/includes/Query.php';

use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Context;
use SigmaRocks\AdvanceHistory\Support\Groups;
use SigmaRocks\AdvanceHistory\Support\Csv;
use SigmaRocks\AdvanceHistory\Support\Diff;
use SigmaRocks\AdvanceHistory\Support\Ip;
use SigmaRocks\AdvanceHistory\Support\Url;

$failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 */
function ah_assert( $condition, $message ) {
	global $failures;
	if ( $condition ) {
		echo "ok  {$message}\n";
		return;
	}
	$failures++;
	echo "FAIL {$message}\n";
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Test stub.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Test stub.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		$email = filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
		return is_string( $email ) ? $email : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Test stub.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Test stub.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

ah_assert( '192.168.1.0' === Ip::mask( '192.168.1.55' ), 'IPv4 mask drops the last octet' );
ah_assert( '' === Ip::mask( 'not-an-ip' ), 'invalid IP is discarded' );
ah_assert( '2001:db8:85a3:0::' === Ip::mask( '2001:db8:85a3::8a2e:370:7334' ), 'IPv6 mask keeps the first half' );

$remote = Ip::from_request(
	array(
		'REMOTE_ADDR'          => '203.0.113.9',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
	),
	false
);
ah_assert( '203.0.113.0' === $remote, 'forwarded headers are ignored unless trusted' );

$clean = Context::clean(
	array(
		'password'   => 'super-secret',
		'api_key'    => 'abcd',
		'note'       => 'hello',
		'ip'         => '203.0.113.55',
		'user_agent' => 'Browser',
	)
);
ah_assert( ! isset( $clean['password'] ) && ! isset( $clean['api_key'] ), 'secrets are removed from context' );
ah_assert( isset( $clean['note'] ) && 'hello' === $clean['note'], 'ordinary context is kept' );
ah_assert( isset( $clean['ip'] ) && '203.0.113.0' === $clean['ip'], 'IP values in context are masked' );

$diff = Diff::summarize( 'The quick brown fox', 'The quick green fox' );
ah_assert( true === $diff['changed'], 'text change is detected' );
ah_assert( in_array( 'green', $diff['added'], true ), 'added word is listed' );
ah_assert( in_array( 'brown', $diff['removed'], true ), 'removed word is listed' );

$hunks = Diff::hunks( "alpha\nkeep\nremove me\nkeep end", "alpha\nkeep\nadd me\nkeep end" );
ah_assert( in_array( 'del: remove me', $hunks, true ), 'removed line is marked' );
ah_assert( in_array( 'add: add me', $hunks, true ), 'added line is marked' );
ah_assert( in_array( 'ctx: keep', $hunks, true ), 'nearby line is kept' );
ah_assert( array() === Diff::hunks( '<p>Same</p>', '<p>Same</p>' ), 'identical content has no diff' );
$markup = Diff::hunks( '<p>Hello brown</p>', '<p>Hello green</p>' );
ah_assert( in_array( 'del: Hello brown', $markup, true ) && in_array( 'add: Hello green', $markup, true ), 'paragraph markup diffs as text' );
$stored = Context::clean(
	array(
		'changes' => array(
			array(
				'field' => 'Content',
				'lines' => $hunks,
			),
		),
	)
);
ah_assert( isset( $stored['changes'][0]['lines'] ) && in_array( 'add: add me', $stored['changes'][0]['lines'], true ), 'line diff survives context cleanup' );

ah_assert( "'=1+1" === Csv::cell( '=1+1' ), 'CSV formulas are neutralized' );
ah_assert( 'safe' === Csv::cell( 'safe' ), 'plain CSV cells stay plain' );

ah_assert( '' === Url::public_https( 'http://example.com/hook' ), 'webhooks must be HTTPS' );
ah_assert( '' === Url::public_https( 'https://127.0.0.1/hook' ), 'loopback webhooks are rejected' );
ah_assert( '' === Url::public_https( 'https://user:pass@example.com/hook' ), 'webhook credentials are rejected' );
ah_assert( 'https://example.com/hook' === Url::public_https( 'https://example.com/hook' ), 'public HTTPS webhooks are accepted' );

$settings = Settings::sanitize(
	array(
		'retention_days'       => 99999,
		'per_page'             => 15,
		'ip_mode'              => 'full',
		'webhook_url'          => 'http://example.com/hook',
		'alert_threshold'      => 1,
		'email_digest'         => 'monthly',
		'disabled_loggers'     => array( 'user', '@@@' ),
		'delete_data_on_uninstall' => '1',
	),
	array( 'feed_token' => 'abc123' )
);
ah_assert( 3650 === $settings['retention_days'], 'retention is capped' );
ah_assert( 25 === $settings['per_page'], 'unknown page sizes fall back' );
ah_assert( 'masked' === $settings['ip_mode'], 'full IP storage is not a setting' );
ah_assert( '' === $settings['webhook_url'], 'insecure webhooks are dropped' );
ah_assert( 3 === $settings['alert_threshold'], 'alert threshold has a floor' );
ah_assert( 'off' === $settings['email_digest'], 'unknown digest values are rejected' );
ah_assert( array( 'user' ) === $settings['disabled_loggers'], 'logger ids are sanitized' );
ah_assert( 'abc123' === $settings['feed_token'], 'feed secret is preserved' );

$order = Query::order_parts( 'created_gmt; DROP TABLE wp_users', 'DESC; DELETE' );
ah_assert( 'created_gmt' === $order['column'] && 'DESC' === $order['direction'], 'ordering is whitelisted' );

$grouped = Groups::collapse(
	array(
		array( 'summary' => 'Failed login for "ada"', 'action' => 'user.login_failed', 'user_id' => 0, 'object_type' => '', 'object_id' => 0, 'created_gmt' => '2026-10-22 11:13:08', 'context' => array() ),
		array( 'summary' => 'Failed login for "ada"', 'action' => 'user.login_failed', 'user_id' => 0, 'object_type' => '', 'object_id' => 0, 'created_gmt' => '2026-10-22 11:13:05', 'context' => array() ),
		array( 'summary' => 'Threshold reached', 'action' => 'security.login_threshold', 'user_id' => 0, 'object_type' => '', 'object_id' => 0, 'created_gmt' => '2026-10-22 11:13:05', 'context' => array() ),
		array( 'summary' => 'Failed login for "ada"', 'action' => 'user.login_failed', 'user_id' => 0, 'object_type' => '', 'object_id' => 0, 'created_gmt' => '2026-10-22 11:10:19', 'context' => array() ),
		array( 'summary' => 'Logged in as "ada".', 'action' => 'user.login', 'user_id' => 1, 'object_type' => 'user', 'object_id' => 1, 'created_gmt' => '2026-10-22 08:06:24', 'context' => array() ),
	)
);
ah_assert( 3 === count( $grouped ), 'repeated events collapse into one row' );
ah_assert( 3 === (int) $grouped[0]['repeat_count'], 'repeat count includes the whole window' );
ah_assert( 1 === (int) $grouped[2]['repeat_count'], 'a different event stays on its own' );

echo $failures ? "{$failures} failed\n" : "all smoke checks passed\n";
exit( $failures ? 1 : 0 );
