<?php
/**
 * Event recording.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Logger_Registry;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Recorder;

/**
 * Recorder rules and the loggers that feed it.
 */
class Test_Recorder extends Srah_Test_Case {

	/**
	 * Flush the content logger's buffer as shutdown would.
	 */
	private function flush_content() {
		Logger_Registry::get( 'content' )->flush();
	}

	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_an_event_is_stored_with_the_current_user() {
		$admin = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Admin',
			)
		);
		wp_set_current_user( $admin );
		$id  = srah_log(
			array(
				'summary'  => 'Imported the <b>catalog</b>.',
				'category' => 'system',
				'action'   => 'import.catalog',
				'severity' => 'notice',
			)
		);
		$row = Query::find( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'Imported the catalog.', $row['summary'] );
		$this->assertSame( $admin, (int) $row['user_id'] );
		$this->assertSame( 'Ada Admin', $row['user_display'] );
		$this->assertSame( 'administrator', $row['user_role'] );
		$this->assertSame( 'notice', $row['severity'] );
		$this->assertSame( get_current_blog_id(), (int) $row['blog_id'] );
	}

	public function test_invalid_input_is_rejected_or_normalized() {
		$this->assertSame( 0, srah_log( 'not an array' ) );
		$this->assertSame( 0, srah_log( array( 'summary' => '   ' ) ) );
		$row = Query::find(
			srah_log(
				array(
					'summary'  => 'Odd values',
					'severity' => 'EXTREME',
					'category' => 'Bad Category!!',
				)
			)
		);
		$this->assertSame( 'info', $row['severity'] );
		$this->assertSame( 'badcategory', $row['category'] );
	}

	public function test_secrets_are_removed_from_context() {
		$row = Query::find(
			srah_log(
				array(
					'summary' => 'Context check',
					'context' => array(
						'user_pass' => 'hunter2',
						'api_key'   => 'sk-live-123',
						'nested'    => array( 'client_secret' => 'abc' ),
						'count'     => 3,
					),
				)
			)
		);
		$this->assertArrayNotHasKey( 'user_pass', $row['context'] );
		$this->assertArrayNotHasKey( 'api_key', $row['context'] );
		$this->assertStringNotContainsString( 'abc', wp_json_encode( $row['context'] ) );
		$this->assertSame( 3, (int) $row['context']['count'] );
	}

	public function test_addresses_are_masked_or_omitted() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$row                    = Query::find( srah_log( array( 'summary' => 'Masked' ) ) );
		$this->assertSame( '203.0.113.0', $row['ip_masked'] );

		$this->settings( array( 'ip_mode' => 'off' ) );
		$row = Query::find( srah_log( array( 'summary' => 'No address' ) ) );
		$this->assertSame( '', $row['ip_masked'] );
	}

	public function test_disabled_actions_and_loggers_are_not_recorded() {
		$this->settings( array( 'disabled_actions' => array( 'journal.note' ) ) );
		$this->assertSame(
			0,
			srah_log(
				array(
					'summary' => 'Off',
					'action'  => 'journal.note',
				)
			)
		);

		$this->settings( array( 'disabled_loggers' => array( 'content' ) ) );
		self::factory()->post->create( array( 'post_title' => 'Quiet post' ) );
		$this->flush_content();
		$this->assertNull( $this->latest( 'content.created' ) );
	}

	public function test_excluded_roles_skip_info_but_keep_warnings() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->settings( array( 'excluded_roles' => array( 'editor' ) ) );
		$this->assertSame(
			0,
			srah_log(
				array(
					'summary' => 'Routine',
					'user_id' => $editor,
				)
			)
		);
		$this->assertGreaterThan(
			0,
			srah_log(
				array(
					'summary'  => 'Warning',
					'user_id'  => $editor,
					'severity' => 'warning',
				)
			)
		);
	}

	public function test_identical_events_within_the_window_are_merged() {
		$first  = srah_log(
			array(
				'summary' => 'Repeat me',
				'action'  => 'system.test',
			)
		);
		$second = srah_log(
			array(
				'summary' => 'Repeat me',
				'action'  => 'system.test',
			)
		);
		$this->assertSame( $first, $second );
		$this->assertSame( 2, (int) Query::find( $first )['context']['repeat_count'] );
	}

	public function test_events_in_the_same_second_keep_a_stable_order_across_pages() {
		$when = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$ids  = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $this->insert_row(
				array(
					'summary'     => 'Same second ' . $i,
					'created_gmt' => $when,
				)
			);
		}
		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$result = Query::search(
				array(
					'search'   => 'Same second',
					'per_page' => 2,
					'page'     => $page,
				)
			);
			$seen   = array_merge( $seen, array_map( 'intval', wp_list_pluck( $result['rows'], 'id' ) ) );
		}
		$this->assertSame( array_reverse( $ids ), $seen, 'Newest first, every row exactly once.' );

		$ascending = Query::search(
			array(
				'search'   => 'Same second',
				'per_page' => 6,
				'order'    => 'ASC',
			)
		);
		$this->assertSame( $ids, array_map( 'intval', wp_list_pluck( $ascending['rows'], 'id' ) ) );
	}

	public function test_filters_and_hooks() {
		$skip = static function () {
			return false;
		};
		add_filter( 'srah_should_log', $skip );
		$this->assertSame( 0, srah_log( array( 'summary' => 'Filtered out' ) ) );
		remove_filter( 'srah_should_log', $skip );

		$seen = array();
		$spy  = static function ( $id ) use ( &$seen ) {
			$seen[] = $id;
		};
		add_action( 'srah_logged', $spy );
		$id = srah_log( array( 'summary' => 'Announced' ) );
		remove_action( 'srah_logged', $spy );
		$this->assertSame( array( $id ), $seen );

		Recorder::suspend();
		$this->assertSame( 0, srah_log( array( 'summary' => 'Suspended' ) ) );
		Recorder::resume();
	}

	public function test_installing_mode_skips_events_except_network_events() {
		wp_installing( true );
		$skipped = srah_log( array( 'summary' => 'During install' ) );
		$network = srah_log(
			array(
				'summary'  => 'Network event',
				'category' => 'network',
			)
		);
		wp_installing( false );
		$this->assertSame( 0, $skipped );
		$this->assertGreaterThan( 0, $network );
	}

	public function test_creating_and_editing_a_post_are_labelled_correctly() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Launch notes',
				'post_status'  => 'publish',
				'post_content' => 'First draft.',
			)
		);
		$this->flush_content();
		$created = $this->latest( 'content.created' );
		$this->assertSame( $post_id, (int) $created['object_id'] );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Second draft with more words.',
			)
		);
		$this->flush_content();
		$updated = $this->latest( 'content.updated' );
		$this->assertIsArray( $updated, 'An edit to an existing post is an update.' );
		$this->assertSame( $post_id, (int) $updated['object_id'] );
		$this->assertSame( $created['id'], $this->latest( 'content.created' )['id'], 'No second created event.' );
	}

	public function test_saving_the_same_featured_image_records_nothing() {
		$post_id = self::factory()->post->create();
		$image   = self::factory()->attachment->create_object( 'image.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		set_post_thumbnail( $post_id, $image );
		$this->flush_content();
		$before = $this->count_rows();

		update_post_meta( $post_id, '_thumbnail_id', (int) $image );
		$this->flush_content();
		$this->assertSame( $before, $this->count_rows() );
	}

	public function test_failed_login_is_recorded_without_the_password() {
		$user = self::factory()->user->create( array( 'user_login' => 'grace' ) );
		unset( $user );
		wp_authenticate( 'grace', 'wrong-password-value' );
		$row = $this->latest( 'user.login_failed' );
		$this->assertIsArray( $row );
		$this->assertStringNotContainsString( 'wrong-password-value', wp_json_encode( $row ) );
	}
}
