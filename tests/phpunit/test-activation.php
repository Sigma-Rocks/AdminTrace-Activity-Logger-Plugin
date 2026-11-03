<?php
/**
 * Activation, deactivation, and upgrade.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Installer;
use SigmaRocks\AdvanceHistory\Notifications;
use SigmaRocks\AdvanceHistory\Retention;
use SigmaRocks\AdvanceHistory\Watch;

/**
 * Lifecycle tests.
 */
class Test_Activation extends Srah_Test_Case {

	/**
	 * Index names on the events table.
	 *
	 * @return string[]
	 */
	private function index_names() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Installer::table() ), ARRAY_A );
		return array_values( array_unique( wp_list_pluck( (array) $rows, 'Key_name' ) ) );
	}

	public function test_table_has_expected_columns_and_indexes() {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Installer::table() ) );
		foreach ( array( 'id', 'blog_id', 'created_gmt', 'created_ms', 'user_id', 'user_role', 'ip_masked', 'severity', 'category', 'action', 'summary', 'context' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
		$this->assertEqualsCanonicalizing(
			array( 'PRIMARY', 'created_gmt', 'user_created', 'category_action', 'severity_created', 'object_lookup', 'blog_created' ),
			$this->index_names()
		);
	}

	public function test_activate_schedules_tasks_grants_caps_and_logs() {
		Retention::unschedule();
		Watch::unschedule();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Installer::activate( false );

		$this->assertSame( SRAH_DB_VERSION, (string) get_option( 'srah_db_version' ) );
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Watch::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::DIGEST ), 'The digest is off by default.' );
		$role = get_role( 'administrator' );
		foreach ( array( Capabilities::VIEW, Capabilities::MANAGE, Capabilities::EXPORT, Capabilities::DELETE ) as $cap ) {
			$this->assertTrue( $role->has_cap( $cap ), $cap );
		}
		$row = $this->latest( 'system.activated' );
		$this->assertIsArray( $row );
		$this->assertSame( $admin, (int) $row['user_id'] );
		$this->assertSame( 1, (int) get_transient( 'srah_welcome_' . $admin ) );
	}

	public function test_activation_notes_existing_posts_once() {
		self::factory()->post->create_many( 3 );
		Installer::activate( false );
		$first = $this->count_rows();
		Installer::activate( false );
		$this->assertSame( $first, $this->count_rows(), 'Posts are not noted again, and the repeated notice is merged.' );
		$row = $this->latest( 'system.activated' );
		$this->assertSame( 2, (int) $row['context']['repeat_count'] );
	}

	public function test_deactivate_clears_schedules_and_keeps_rows() {
		Installer::activate( false );
		$this->settings( array( 'email_digest' => 'daily' ) );
		Notifications::reschedule();
		$rows = $this->count_rows();

		Installer::deactivate( false );

		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Watch::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::DIGEST ) );
		$this->assertSame( $rows + 1, $this->count_rows() );
		$this->assertIsArray( $this->latest( 'system.deactivated' ) );
	}

	public function test_upgrade_runs_when_schema_version_changes() {
		update_option( 'srah_db_version', '1' );
		Installer::maybe_upgrade();
		$this->assertSame( SRAH_DB_VERSION, (string) get_option( 'srah_db_version' ) );
	}

	public function test_legacy_table_rename_runs_once() {
		update_option( 'srah_legacy_table_done', 1 );
		$filter = static function ( $query ) {
			if ( false !== stripos( $query, 'RENAME TABLE' ) ) {
				throw new RuntimeException( 'rename attempted' );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		Installer::maybe_upgrade();
		remove_filter( 'query', $filter );
		$this->assertSame( 1, (int) get_option( 'srah_legacy_table_done' ) );
	}

	public function test_dropping_a_site_includes_its_journal_table() {
		global $wpdb;
		$tables = apply_filters( 'wpmu_drop_tables', array(), 7 );
		$this->assertContains( $wpdb->get_blog_prefix( 7 ) . 'srah_events', $tables );
	}
}
