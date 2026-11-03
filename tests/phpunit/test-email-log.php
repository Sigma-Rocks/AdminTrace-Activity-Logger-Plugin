<?php
/**
 * Separate email log.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Email_Log;
use SigmaRocks\AdvanceHistory\Privacy;
use SigmaRocks\AdvanceHistory\Settings;

/**
 * Capture, retention, privacy, and the enable switch.
 */
class Test_Email_Log extends Srah_Test_Case {

	public function test_the_email_table_is_created_on_install() {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Email_Log::table() ) );
		foreach ( array( 'id', 'blog_id', 'sent_gmt', 'to_email', 'subject', 'message', 'headers', 'attachments', 'status', 'error_message' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	public function test_logging_stays_off_until_it_is_enabled() {
		$this->settings( array( 'email_log_enabled' => 0 ) );
		wp_mail( 'hidden@example.org', 'Hidden subject', 'Hidden body' );
		$this->assertSame( 0, Email_Log::search( array( 's' => 'Hidden subject' ) )['total'] );
	}

	public function test_enabled_logging_stores_the_message_apart_from_the_journal() {
		$this->settings( array( 'email_log_enabled' => 1 ) );
		wp_mail( 'person@example.org', 'Hello there', 'Body text', '', array( '/tmp/secret/report.pdf' ) );

		$result = Email_Log::search( array( 's' => 'Hello there' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'person@example.org', $result['rows'][0]['to_email'] );
		$this->assertSame( 'sent', $result['rows'][0]['status'] );
		$this->assertSame( 'report.pdf', $result['rows'][0]['attachments'] );

		$saved = Email_Log::find( (int) $result['rows'][0]['id'] );
		$this->assertSame( 'Body text', $saved['message'] );
		$this->assertNull( $this->latest( 'email.sent' ) );
	}

	public function test_a_short_circuit_is_stored_as_failed() {
		$this->settings( array( 'email_log_enabled' => 1 ) );
		$block = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $block );
		wp_mail( 'person@example.org', 'Blocked subject', 'Not sent' );
		remove_filter( 'pre_wp_mail', $block );

		$result = Email_Log::search( array( 's' => 'Blocked subject' ) );
		$this->assertSame( 'failed', $result['rows'][0]['status'] );
		$this->assertNotSame( '', Email_Log::find( (int) $result['rows'][0]['id'] )['error_message'] );
	}

	public function test_retention_removes_old_email_logs_only() {
		$this->settings( array( 'email_log_enabled' => 1 ) );
		wp_mail( 'old@example.org', 'Stale subject', 'Old body' );
		wp_mail( 'new@example.org', 'Fresh subject', 'New body' );

		global $wpdb;
		$wpdb->update(
			Email_Log::table(),
			array( 'sent_gmt' => '2000-01-01 00:00:00' ),
			array( 'subject' => 'Stale subject' )
		);

		Email_Log::prune( 30 );

		$this->assertSame( 0, Email_Log::search( array( 's' => 'Stale subject' ) )['total'] );
		$this->assertSame( 1, Email_Log::search( array( 's' => 'Fresh subject' ) )['total'] );
	}

	public function test_the_email_switch_does_not_reset_other_settings() {
		$this->settings(
			array(
				'email_log_enabled' => 0,
				'retention_days'    => 12,
			)
		);
		$clean = Settings::sanitize(
			array(
				'settings_section'  => 'email_log',
				'email_log_enabled' => '1',
			),
			Settings::all()
		);
		$this->assertSame( 1, (int) $clean['email_log_enabled'] );
		$this->assertSame( 12, (int) $clean['retention_days'] );

		$clean = Settings::sanitize(
			array(
				'settings_section' => 'email_log',
			),
			$clean
		);
		$this->assertSame( 0, (int) $clean['email_log_enabled'] );
		$this->assertSame( 12, (int) $clean['retention_days'] );
	}

	public function test_an_administrator_can_enable_the_log_from_its_screen() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$result = $this->handle(
			array( Menu::class, 'save_settings' ),
			array(
				'_wpnonce'          => wp_create_nonce( 'srah_save_settings' ),
				'settings_section'  => 'email_log',
				'email_log_enabled' => '1',
			)
		);

		$this->assertSame( 'redirect', $result[0] );
		$this->assertTrue( Email_Log::enabled() );
	}

	public function test_privacy_export_and_erase_follow_the_recipient() {
		$this->settings( array( 'email_log_enabled' => 1 ) );
		$person = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'inbox@example.org',
			)
		);
		wp_set_current_user( $person );

		wp_mail( 'inbox@example.org', 'For you', 'Private body' );
		wp_mail( 'other@example.org', 'For them', 'Other body' );

		$export = Privacy::export_email_log( 'inbox@example.org' );
		$this->assertCount( 1, $export['data'] );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'Private body', $values );
		$this->assertNotContains( 'Other body', $values );

		$this->assertTrue( Privacy::erase_email_log( 'inbox@example.org' )['items_removed'] );
		$this->assertSame( 0, Email_Log::search( array( 's' => 'For you' ) )['total'] );
		$kept = Email_Log::search( array( 's' => 'For them' ) );
		$this->assertSame( 1, $kept['total'] );
		$this->assertSame( 0, (int) $kept['rows'][0]['user_id'] );
	}
}
