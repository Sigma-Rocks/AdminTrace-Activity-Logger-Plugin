<?php
/**
 * REST API.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

/**
 * The admintrace-activity-logger/v1 routes.
 */
class Test_Rest extends Srah_Test_Case {

	const BASE = '/admintrace-activity-logger/v1/events';

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
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Parameters.
	 * @return WP_REST_Response
	 */
	private function call( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( self::BASE, $routes );
		$this->assertArrayHasKey( self::BASE . '/(?P<id>\d+)', $routes );
	}

	public function test_anonymous_and_subscriber_requests_are_refused() {
		$this->assertSame( 401, $this->call( 'GET', self::BASE )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', self::BASE )->get_status() );
	}

	public function test_listing_reports_totals_in_headers() {
		wp_set_current_user( $this->admin );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->insert_row( array( 'summary' => 'Listed ' . $i ) );
		}
		$response = $this->call(
			'GET',
			self::BASE,
			array(
				'per_page' => 2,
				'search'   => 'Listed',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 3, (int) $headers['X-WP-Total'] );
		$this->assertSame( 2, (int) $headers['X-WP-TotalPages'] );
	}

	public function test_editors_only_see_content_when_allowed() {
		$this->insert_row(
			array(
				'summary'  => 'Content row',
				'category' => 'content',
			)
		);
		$this->insert_row(
			array(
				'summary'  => 'Settings row',
				'category' => 'settings',
			)
		);
		$editor = self::factory()->user->get_object_by_id( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		wp_set_current_user( $editor->ID );
		$this->assertSame( 403, $this->call( 'GET', self::BASE )->get_status() );

		$this->settings( array( 'editors_can_view_content' => 1 ) );
		$response = $this->call( 'GET', self::BASE );
		$this->assertSame( 200, $response->get_status() );
		$categories = array_unique( wp_list_pluck( $response->get_data(), 'category' ) );
		$this->assertContains( 'content', $categories );
		$this->assertNotContains( 'settings', $categories );
		$this->assertSame( array(), array_diff( $categories, Vocabulary::viewer_categories() ) );
		foreach ( $response->get_data() as $item ) {
			$this->assertSame( '', $item['ip_masked'], 'Editors never see addresses.' );
		}
	}

	public function test_creating_an_event() {
		wp_set_current_user( $this->admin );
		$response = $this->call(
			'POST',
			self::BASE,
			array(
				'summary'  => 'Deployed <script>x</script>release 4',
				'severity' => 'bogus',
				'context'  => array( 'build' => 42 ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$row = Query::find( $response->get_data()['id'] );
		$this->assertSame( 'api.event', $row['action'] );
		$this->assertSame( 'info', $row['severity'] );
		$this->assertStringNotContainsString( '<script>', $row['summary'] );
		$this->assertSame( 42, (int) $row['context']['build'] );
	}

	public function test_creating_requires_a_summary_and_the_manage_capability() {
		wp_set_current_user( $this->admin );
		$this->assertSame( 400, $this->call( 'POST', self::BASE )->get_status() );

		$this->settings( array( 'editors_can_view_content' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->call( 'POST', self::BASE, array( 'summary' => 'Nope' ) )->get_status() );
	}

	public function test_showing_and_deleting_one_event() {
		$id = $this->insert_row( array( 'summary' => 'Single' ) );
		wp_set_current_user( $this->admin );

		$shown = $this->call( 'GET', self::BASE . '/' . $id );
		$this->assertSame( 200, $shown->get_status() );
		$this->assertSame( 'Single', $shown->get_data()['summary'] );

		$deleted = $this->call( 'DELETE', self::BASE . '/' . $id );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertNull( Query::find( $id ) );
		$this->assertSame( 404, $this->call( 'GET', self::BASE . '/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', self::BASE . '/' . $id )->get_status() );
	}

	public function test_deleting_needs_the_delete_capability() {
		$id = $this->insert_row( array( 'category' => 'content' ) );
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->call( 'DELETE', self::BASE . '/' . $id )->get_status() );
		$this->assertIsArray( Query::find( $id ) );
	}

	public function test_events_from_another_site_are_not_found() {
		$id = $this->insert_row( array( 'blog_id' => get_current_blog_id() + 100 ) );
		wp_set_current_user( $this->admin );
		$this->assertSame( 404, $this->call( 'GET', self::BASE . '/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', self::BASE . '/' . $id )->get_status() );
	}
}
