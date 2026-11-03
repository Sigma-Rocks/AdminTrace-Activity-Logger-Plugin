<?php
/**
 * Multisite behaviour.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Installer;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Retention;

/**
 * Per-site journals and network events.
 *
 * @group ms-required
 */
class Test_Multisite extends Srah_Test_Case {

	/**
	 * Whether a table exists, including the suite's temporary tables.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$found = false !== $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$wpdb->suppress_errors( false );
		return $found;
	}

	public function test_a_new_site_gets_its_own_journal_and_schedule() {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertTrue( $this->table_exists( Installer::table() ) );
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::DELETE ) );
		restore_current_blog();
	}

	public function test_site_journals_are_separate() {
		$main_row = $this->insert_row( array( 'summary' => 'Main site row' ) );
		$site     = self::factory()->blog->create();
		switch_to_blog( $site );
		$sub_row = srah_log( array( 'summary' => 'Subsite row' ) );
		$this->assertNull( Query::find( $main_row ), 'The subsite cannot read the main journal.' );
		$this->assertSame( $site, (int) Query::find( $sub_row )['blog_id'] );
		restore_current_blog();
		$this->assertIsArray( Query::find( $main_row ) );
	}

	public function test_site_creation_and_deletion_are_logged_on_the_main_site() {
		$site = self::factory()->blog->create( array( 'title' => 'Branch office' ) );
		$this->assertIsArray( $this->latest( 'network.site_created' ) );

		wp_delete_site( $site );
		$deleted = $this->latest( 'network.site_deleted' );
		$this->assertIsArray( $deleted );
		$this->assertSame( get_main_site_id(), (int) $deleted['blog_id'] );
	}

	public function test_a_deleted_sites_table_is_dropped_with_it() {
		global $wpdb;
		$site   = self::factory()->blog->create();
		$tables = apply_filters( 'wpmu_drop_tables', array(), $site );
		$this->assertContains( $wpdb->get_blog_prefix( $site ) . 'srah_events', $tables );
	}

	public function test_network_activation_installs_every_site() {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Installer::table() ) );
		$this->assertFalse( $this->table_exists( Installer::table() ) );
		restore_current_blog();

		Installer::activate( true );
		switch_to_blog( $site );
		$this->assertTrue( $this->table_exists( Installer::table() ) );
		restore_current_blog();
	}

	public function test_super_admins_hold_every_capability_on_subsites() {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		foreach ( array( Capabilities::VIEW, Capabilities::MANAGE, Capabilities::EXPORT, Capabilities::DELETE ) as $cap ) {
			$this->assertTrue( user_can( $super, $cap ), $cap );
		}
		restore_current_blog();
	}
}
