<?php
/**
 * Privacy exporter and eraser.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Privacy;
use SigmaRocks\AdvanceHistory\Query;

/**
 * Personal data tools.
 */
class Test_Privacy extends Srah_Test_Case {

	/**
	 * Subject user.
	 *
	 * @var WP_User
	 */
	private $subject;

	public function set_up() {
		parent::set_up();
		$this->subject = self::factory()->user->create_and_get(
			array(
				'user_login' => 'subject',
				'user_email' => 'subject@example.org',
			)
		);
	}

	public function test_the_tools_are_registered_with_core() {
		$this->assertArrayHasKey( 'admintrace-activity-logger', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'admintrace-activity-logger', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_returns_only_the_subjects_events() {
		$mine  = $this->insert_row(
			array(
				'user_id'   => $this->subject->ID,
				'summary'   => 'Subject activity',
				'ip_masked' => '198.51.100.0',
			)
		);
		$other = $this->insert_row( array( 'summary' => 'Someone else' ) );
		unset( $other );

		$export = Privacy::export_personal_data( 'subject@example.org' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'event-' . $mine, $export['data'][0]['item_id'] );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'Subject activity', $values );
		$this->assertContains( '198.51.100.0', $values );
	}

	public function test_export_pages_through_large_histories() {
		for ( $i = 0; $i < 55; $i++ ) {
			$this->insert_row(
				array(
					'user_id' => $this->subject->ID,
					'summary' => 'Row ' . $i,
				)
			);
		}
		$first  = Privacy::export_personal_data( 'subject@example.org', 1 );
		$second = Privacy::export_personal_data( 'subject@example.org', 2 );
		$this->assertCount( 50, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 5, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_unknown_addresses_export_and_erase_nothing() {
		$this->assertSame( array(), Privacy::export_personal_data( 'nobody@example.org' )['data'] );
		$this->assertFalse( Privacy::erase_personal_data( 'nobody@example.org' )['items_removed'] );
	}

	public function test_erase_removes_rows_by_id_and_by_failed_login_name() {
		$by_id    = $this->insert_row( array( 'user_id' => $this->subject->ID ) );
		$by_login = $this->insert_row(
			array(
				'user_id'    => 0,
				'user_login' => 'subject',
				'action'     => 'user.login_failed',
			)
		);
		$other    = $this->insert_row( array( 'user_login' => 'someone' ) );

		$result = Privacy::erase_personal_data( 'subject@example.org' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( Query::find( $by_id ) );
		$this->assertNull( Query::find( $by_login ) );
		$this->assertIsArray( Query::find( $other ) );
	}

	/**
	 * Core only accepts suggestions in wp-admin during admin_init, so only this callback runs there.
	 */
	public function test_a_privacy_policy_suggestion_is_added_on_admin_init() {
		global $wp_filter;
		$this->assertSame( 10, has_action( 'admin_init', array( Privacy::class, 'policy' ) ) );

		set_current_screen( 'dashboard' );
		$saved = isset( $wp_filter['admin_init'] ) ? $wp_filter['admin_init'] : null;
		unset( $wp_filter['admin_init'] );
		add_action( 'admin_init', array( Privacy::class, 'policy' ) );
		do_action( 'admin_init' );
		$wp_filter['admin_init'] = $saved;
		set_current_screen( 'front' );

		$names = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( 'AdminTrace Activity Logger', $names );
	}
}
