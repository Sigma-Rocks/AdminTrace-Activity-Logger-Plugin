<?php
/**
 * Capability mapping.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Capabilities;

/**
 * Who can view, manage, export, and delete.
 */
class Test_Capabilities extends Srah_Test_Case {

	/**
	 * All plugin capabilities.
	 *
	 * @var string[]
	 */
	private $caps = array( Capabilities::VIEW, Capabilities::MANAGE, Capabilities::EXPORT, Capabilities::DELETE );

	public function test_administrator_has_every_capability() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		foreach ( $this->caps as $cap ) {
			$this->assertTrue( user_can( $admin, $cap ), $cap );
		}
	}

	public function test_editor_and_subscriber_have_none_by_default() {
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			$user = self::factory()->user->create( array( 'role' => $role ) );
			foreach ( $this->caps as $cap ) {
				$this->assertFalse( user_can( $user, $cap ), "{$role} {$cap}" );
			}
		}
	}

	public function test_editor_can_only_view_when_allowed() {
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertTrue( user_can( $editor, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $editor, Capabilities::EXPORT ) );
		$this->assertFalse( user_can( $editor, Capabilities::DELETE ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertFalse( user_can( $author, Capabilities::VIEW ) );
	}

	/**
	 * The capability filter used to call has_cap() again through is_super_admin() on single sites.
	 */
	public function test_checking_a_non_administrator_does_not_recurse() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$depth  = 0;
		$peak   = 0;
		$enter  = static function ( $allcaps ) use ( &$depth, &$peak ) {
			++$depth;
			$peak = max( $peak, $depth );
			return $allcaps;
		};
		$leave  = static function ( $allcaps ) use ( &$depth ) {
			--$depth;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $enter, -1000 );
		add_filter( 'user_has_cap', $leave, 1000 );
		$this->assertFalse( user_can( $editor, Capabilities::VIEW ) );
		remove_filter( 'user_has_cap', $enter, -1000 );
		remove_filter( 'user_has_cap', $leave, 1000 );
		$this->assertLessThanOrEqual( 2, $peak );
	}

	public function test_grant_ignores_a_non_user_argument() {
		$caps = Capabilities::grant( array( 'read' => true ), array(), array(), null );
		$this->assertSame( array( 'read' => true ), $caps );
	}

	/**
	 * Subsite administrators get the journal for their own site.
	 *
	 * @group ms-required
	 */
	public function test_site_administrator_on_a_subsite_gets_the_journal() {
		$site  = self::factory()->blog->create();
		$admin = self::factory()->user->create();
		add_user_to_blog( $site, $admin, 'administrator' );
		switch_to_blog( $site );
		$this->assertTrue( user_can( $admin, Capabilities::VIEW ) );
		$this->assertTrue( user_can( $admin, Capabilities::DELETE ) );
		restore_current_blog();
		$this->assertFalse( user_can( $admin, Capabilities::VIEW ), 'Not an administrator on the main site.' );
	}
}
