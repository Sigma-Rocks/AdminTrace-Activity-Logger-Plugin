<?php
/**
 * WordPress integration checks. CLI only.
 *
 * Loads the local site, installs the journal table, writes sample events,
 * renders the admin screen, then deletes the sample events.
 *
 * @package AdvanceHistory
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	echo "SKIP WordPress bootstrap not found\n";
	exit( 0 );
}

require $wp_load;

if ( ! function_exists( 'advance_history_log' ) ) {
	require WP_PLUGIN_DIR . '/advance-history/advance-history.php';
}

\SigmaRocks\AdvanceHistory\Plugin::boot();
\SigmaRocks\AdvanceHistory\Installer::install();

$failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $ok      Condition.
 * @param string $message Message.
 */
function ah_check( $ok, $message ) {
	global $failures;
	if ( $ok ) {
		echo "ok  {$message}\n";
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL {$message}\n" );
}

global $wpdb;
$table = \SigmaRocks\AdvanceHistory\Installer::table();
$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
ah_check( $found === $table, 'event table exists' );

$original = \SigmaRocks\AdvanceHistory\Settings::all();
$tweaked  = $original;
$tweaked['alert_failed_logins']  = 0;
$tweaked['webhook_url']          = '';
$tweaked['webhook_min_severity'] = 'off';
$tweaked['email_digest']         = 'off';
\SigmaRocks\AdvanceHistory\Settings::update( $tweaked );

$window   = get_option( 'advance_history_fail_window', null );
$token    = 'ah-test-' . wp_generate_password( 8, false, false );
$username = 'ah-test-user';
$ids      = array();

try {
	$ids[] = advance_history_log(
		array(
			'summary'   => 'Synthetic event ' . $token . ' <script>alert(1)</script>',
			'category'  => 'system',
			'action'    => 'system.test',
			'severity'  => 'info',
			'logger'    => 'test',
			'initiator' => 'system',
			'user_id'   => 0,
			'context'   => array(
				'password' => 'super-secret',
				'note'     => 'kept',
				'ip'       => '198.51.100.44',
			),
		)
	);
	ah_check( $ids[0] > 0, 'event insert returns an id' );

	$row = \SigmaRocks\AdvanceHistory\Query::find( $ids[0] );
	ah_check( is_array( $row ), 'inserted event can be read back' );
	ah_check( false === strpos( $row['summary'], '<script>' ), 'HTML is not stored in the summary' );
	ah_check( false === strpos( wp_json_encode( $row['context'] ), 'super-secret' ), 'password context is not stored' );
	ah_check( isset( $row['context']['note'] ) && 'kept' === $row['context']['note'], 'safe context is stored' );
	ah_check( isset( $row['context']['ip'] ) && '198.51.100.0' === $row['context']['ip'], 'stored context IP is masked' );

	$logger = \SigmaRocks\AdvanceHistory\Logger_Registry::get( 'user' );
	ah_check( $logger instanceof \SigmaRocks\AdvanceHistory\Loggers\User_Logger, 'user logger is registered' );
	if ( $logger ) {
		$logger->on_failed( $username, new WP_Error( 'invalid_username', 'unknown' ) );
	}
	$failed = \SigmaRocks\AdvanceHistory\Query::search(
		array(
			'action'       => 'user.login_failed',
			'search'       => $username,
			'per_page'     => 5,
			'unrestricted' => true,
		)
	);
	ah_check( $failed['total'] >= 1, 'failed login is recorded' );
	if ( ! empty( $failed['rows'][0] ) ) {
		ah_check( false === strpos( wp_json_encode( $failed['rows'][0] ), 'secret-password' ), 'failed login row has no password field' );
	}

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	ah_check( ! empty( $admins ), 'an administrator account exists for the screen render' );
	if ( ! empty( $admins ) ) {
		wp_set_current_user( (int) $admins[0] );
		if ( ! function_exists( 'wp_dropdown_users' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$_GET = array(
			'page' => 'advance-history',
			's'    => $token,
		);
		ob_start();
		\SigmaRocks\AdvanceHistory\Admin\Timeline_Screen::render();
		$html = ob_get_clean();
		ah_check( false !== strpos( $html, 'ah-wrap' ), 'journal screen renders' );
		ah_check( false !== strpos( $html, 'Synthetic event' ), 'journal screen includes the test event' );
		ah_check( false === strpos( $html, '<script>alert(1)</script>' ), 'journal screen does not print raw HTML' );

		ob_start();
		\SigmaRocks\AdvanceHistory\Admin\Settings_Screen::render();
		$settings_html = ob_get_clean();
		ah_check( false !== strpos( $settings_html, 'ah-retention' ), 'settings screen renders' );
	}
} catch ( Throwable $e ) {
	ah_check( false, 'integration exception: ' . $e->getMessage() );
} finally {
	\SigmaRocks\AdvanceHistory\Settings::update( $original );
	if ( null === $window ) {
		delete_option( 'advance_history_fail_window' );
	} else {
		update_option( 'advance_history_fail_window', $window, false );
	}
	delete_transient( 'ah_fl_' . md5( strtolower( $username ) ) );
	if ( $ids ) {
		\SigmaRocks\AdvanceHistory\Retention::delete_ids( $ids );
	}
	$like = '%' . $wpdb->esc_like( $username ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_login = %s OR summary LIKE %s", $username, $like ) );
}

echo $failures ? "{$failures} integration checks failed\n" : "all integration checks passed\n";
exit( $failures ? 1 : 0 );
