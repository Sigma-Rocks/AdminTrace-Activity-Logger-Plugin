<?php
/**
 * Email and webhook notifications.
 *
 * Mail goes to the test suite's mock mailer and HTTP requests are intercepted, so nothing leaves the machine.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Notifications;

/**
 * Digest, critical alerts, failed-login alerts, and the webhook.
 */
class Test_Notifications extends Srah_Test_Case {

	/**
	 * Intercepted HTTP requests.
	 *
	 * @var array
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * Record an outgoing request instead of sending it.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array
	 */
	public function intercept( $pre, $args, $url ) {
		unset( $pre );
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Messages captured by the mock mailer.
	 *
	 * @return array
	 */
	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_the_digest_is_off_by_default() {
		$this->assertFalse( Notifications::send_digest() );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_the_digest_lists_recent_events() {
		$this->settings(
			array(
				'email_digest' => 'daily',
				'digest_email' => 'digest@example.org',
			)
		);
		$this->insert_row( array( 'summary' => 'Digest line item' ) );
		$this->assertTrue( Notifications::send_digest() );
		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'digest@example.org', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Digest line item', $sent[0]['body'] );
		$this->assertStringContainsString( 'Activity digest', $sent[0]['subject'] );
	}

	public function test_an_invalid_recipient_falls_back_to_the_admin_email() {
		$this->assertSame( get_option( 'admin_email' ), Notifications::recipient( 'not-an-email' ) );
		$this->assertSame( 'ops@example.org', Notifications::recipient( 'ops@example.org' ) );
	}

	public function test_critical_events_are_emailed_once() {
		$this->settings( array( 'alert_email' => 'alerts@example.org' ) );
		srah_log(
			array(
				'summary'  => 'Something critical',
				'severity' => 'critical',
			)
		);
		srah_log( array( 'summary' => 'Something routine' ) );
		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'alerts@example.org', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Something critical', $sent[0]['body'] );
	}

	public function test_failed_login_alerts_follow_the_setting() {
		Notifications::alert_failed_logins( 7 );
		$this->assertCount( 0, $this->sent(), 'Off in the quiet test settings.' );

		$this->settings( array( 'alert_failed_logins' => 1 ) );
		Notifications::alert_failed_logins( 7 );
		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( '7 failed logins', $sent[0]['body'] );
	}

	public function test_the_webhook_receives_the_event_id_without_addresses() {
		$this->settings(
			array(
				'webhook_url'          => 'https://hooks.example.org/admintrace',
				'webhook_min_severity' => 'warning',
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		srah_log( array( 'summary' => 'Below the threshold' ) );
		$id = srah_log(
			array(
				'summary'  => 'Above the threshold',
				'severity' => 'warning',
			)
		);
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://hooks.example.org/admintrace', $this->requests[0]['url'] );
		$payload = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( $id, $payload['id'] );
		$this->assertSame( 'Above the threshold', $payload['summary'] );
		$this->assertStringNotContainsString( '203.0.113', $this->requests[0]['args']['body'] );
	}

	public function test_the_digest_schedule_follows_the_setting() {
		Notifications::unschedule();
		$this->settings( array( 'email_digest' => 'weekly' ) );
		Notifications::reschedule();
		$this->assertNotFalse( wp_next_scheduled( Notifications::DIGEST ) );

		$this->settings( array( 'email_digest' => 'off' ) );
		Notifications::reschedule();
		$this->assertFalse( wp_next_scheduled( Notifications::DIGEST ) );
	}

	public function test_saving_another_section_keeps_the_digest_schedule() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->settings( array( 'email_digest' => 'daily' ) );
		Notifications::reschedule();
		$before = wp_next_scheduled( Notifications::DIGEST );
		$this->assertNotFalse( $before );

		$result = $this->handle(
			array( Menu::class, 'save_settings' ),
			array(
				'_wpnonce'         => wp_create_nonce( 'srah_save_settings' ),
				'settings_section' => 'display',
				'use_gravatar'     => 1,
			)
		);
		$this->assertSame( 'redirect', $result[0] );
		$this->assertSame( $before, wp_next_scheduled( Notifications::DIGEST ), 'The next digest is not pushed back.' );
		$this->assertSame( 'daily', get_option( 'srah_settings' )['email_digest'] );
	}
}
