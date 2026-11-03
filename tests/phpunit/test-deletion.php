<?php
/**
 * Deleting events from the journal.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Admin\Timeline_Screen;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Retention;

/**
 * Individual and bulk deletion, in the UI and the handlers.
 */
class Test_Deletion extends Srah_Test_Case {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
	}

	/**
	 * Two older identical rows, which the journal shows as one card.
	 *
	 * @return int[]
	 */
	private function group() {
		$summary = 'Grouped ' . wp_generate_password( 6, false );
		$ids     = array();
		foreach ( array( 3600, 3660 ) as $age ) {
			$ids[] = $this->insert_row(
				array(
					'summary'     => $summary,
					'user_id'     => $this->admin,
					'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age ),
				)
			);
		}
		return $ids;
	}

	/**
	 * Render the journal with request parameters.
	 *
	 * @param array $get Query string.
	 * @return string
	 */
	private function render( array $get ) {
		$_GET = array_merge( array( 'page' => 'admintrace-activity-logger' ), $get );
		ob_start();
		Timeline_Screen::render();
		return (string) ob_get_clean();
	}

	public function test_delete_ids_removes_only_this_sites_rows() {
		$keep   = $this->insert_row( array() );
		$remove = $this->insert_row( array() );
		$other  = $this->insert_row( array( 'blog_id' => get_current_blog_id() + 100 ) );
		$this->assertSame( 1, Retention::delete_ids( array( $remove, $other, 'abc', 0 ) ) );
		$this->assertNull( Query::find( $remove ) );
		$this->assertIsArray( Query::find( $keep ) );
	}

	public function test_journal_shows_the_delete_form_to_administrators() {
		$ids  = $this->group();
		$html = $this->render( array( 's' => Query::find( $ids[0] )['summary'] ) );
		$this->assertStringContainsString( 'name="action" value="srah_delete"', $html );
		$this->assertStringContainsString( 'data-srah-select-all', $html );
		$this->assertStringContainsString( 'data-srah-confirm=', $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="[a-f0-9]{10}"/', $html );
		$this->assertSame( 1, substr_count( $html, 'class="srah-event-check"' ) );
		$this->assertMatchesRegularExpression( '/value="(' . $ids[0] . ',' . $ids[1] . '|' . $ids[1] . ',' . $ids[0] . ')"/', $html, 'One checkbox carries both row IDs.' );

		$table = $this->render(
			array(
				's'    => Query::find( $ids[0] )['summary'],
				'view' => 'table',
			)
		);
		$this->assertStringContainsString( 'class="check-column"', $table );
	}

	public function test_editors_do_not_see_the_delete_form() {
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		$this->insert_row( array( 'category' => 'content' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = $this->render( array() );
		$this->assertStringContainsString( 'srah-timeline', $html );
		$this->assertStringNotContainsString( 'srah_delete', $html );
		$this->assertStringNotContainsString( 'srah-event-check', $html );
	}

	public function test_deleting_a_combined_card_removes_every_row() {
		$ids    = $this->group();
		$result = $this->handle(
			array( Menu::class, 'delete_selected' ),
			array(
				'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
				'event_ids' => array( implode( ',', $ids ) ),
			)
		);
		$this->assertSame( 'redirect', $result[0] );
		$this->assertNull( Query::find( $ids[0] ) );
		$this->assertNull( Query::find( $ids[1] ) );
		$this->assertSame( 'success', get_transient( 'srah_notice_' . $this->admin )['type'] );
	}

	public function test_deleting_requires_a_nonce() {
		$ids    = $this->group();
		$result = $this->handle( array( Menu::class, 'delete_selected' ), array( 'event_ids' => array( implode( ',', $ids ) ) ) );
		$this->assertSame( 'die', $result[0] );
		$this->assertIsArray( Query::find( $ids[0] ) );
	}

	public function test_deleting_requires_the_delete_capability() {
		$ids = $this->group();
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$result = $this->handle(
			array( Menu::class, 'delete_selected' ),
			array(
				'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
				'event_ids' => array( implode( ',', $ids ) ),
			)
		);
		$this->assertSame( 'die', $result[0] );
		$this->assertIsArray( Query::find( $ids[0] ) );
	}

	public function test_nothing_selected_shows_a_warning() {
		$result = $this->handle(
			array( Menu::class, 'delete_selected' ),
			array(
				'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
				'event_ids' => array( array( 'nested' ), '' ),
			)
		);
		$this->assertSame( 'redirect', $result[0] );
		$this->assertSame( 'warning', get_transient( 'srah_notice_' . $this->admin )['type'] );
	}

	public function test_clearing_the_journal_needs_the_confirmation_box() {
		$this->insert_row( array() );
		$count  = $this->count_rows();
		$result = $this->handle( array( Menu::class, 'delete_all' ), array( '_wpnonce' => wp_create_nonce( 'srah_delete_all' ) ) );
		$this->assertSame( 'redirect', $result[0] );
		$this->assertSame( $count, $this->count_rows() );

		$this->handle(
			array( Menu::class, 'delete_all' ),
			array(
				'_wpnonce'           => wp_create_nonce( 'srah_delete_all' ),
				'confirm_delete_all' => 1,
			)
		);
		$this->assertSame( 0, (int) Query::search( array( 'unrestricted' => true ) )['total'] );
	}
}
