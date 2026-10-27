<?php
/**
 * REST API.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated read and write access to the journal.
 */
class Rest_Controller {

	/**
	 * Register routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Declare routes.
	 */
	public static function routes() {
		register_rest_route(
			'admintrace-activity-logger/v1',
			'/events',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
					'args'                => self::collection_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'summary'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'severity' => array(
							'type'              => 'string',
							'default'           => 'info',
							'sanitize_callback' => 'sanitize_key',
						),
						'category' => array(
							'type'              => 'string',
							'default'           => 'journal',
							'sanitize_callback' => 'sanitize_key',
						),
						'context'  => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			'admintrace-activity-logger/v1',
			'/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'destroy' ),
					'permission_callback' => array( __CLASS__, 'can_delete' ),
				),
			)
		);

		register_rest_route(
			'admintrace-activity-logger/v1',
			'/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'stats' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
	}

	/**
	 * Collection arguments.
	 *
	 * @return array
	 */
	private static function collection_args() {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'severity' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'after'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'before'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'user'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * List events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function index( $request ) {
		$args = Access::constrain(
			array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
				'search'   => $request->get_param( 'search' ),
				'category' => $request->get_param( 'category' ),
				'severity' => $request->get_param( 'severity' ),
				'after'    => $request->get_param( 'after' ),
				'before'   => $request->get_param( 'before' ),
				'user_id'  => $request->get_param( 'user' ),
			)
		);

		try {
			$result = Query::search( $args );
		} catch ( \Throwable $e ) {
			unset( $e );
			return new \WP_Error( 'srah_query_failed', __( 'The journal could not be read.', 'admintrace-activity-logger' ), array( 'status' => 500 ) );
		}

		$rows = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = Access::present( $row, ! empty( $args['include_ip'] ) );
		}

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		return $response;
	}

	/**
	 * Show one event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function show( $request ) {
		$args = Access::constrain(
			array(
				'id'       => (int) $request['id'],
				'per_page' => 1,
			)
		);
		$result = Query::search( $args );
		if ( empty( $result['rows'][0] ) ) {
			return new \WP_Error( 'srah_not_found', __( 'That event is not available.', 'admintrace-activity-logger' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( Access::present( $result['rows'][0], ! empty( $args['include_ip'] ) ) );
	}

	/**
	 * Create a journal event.
	 *
	 * The actor is always the authenticated user. Clients cannot assign the event to someone else.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( $request ) {
		$severity = $request->get_param( 'severity' );
		if ( ! isset( Vocabulary::severities()[ $severity ] ) ) {
			$severity = 'info';
		}

		$id = Recorder::log(
			array(
				'summary'   => $request->get_param( 'summary' ),
				'severity'  => $severity,
				'category'  => $request->get_param( 'category' ) ? $request->get_param( 'category' ) : 'journal',
				'action'    => 'api.event',
				'logger'    => 'api',
				'initiator' => 'rest',
				'context'   => $request->get_param( 'context' ),
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'srah_failed', __( 'The event could not be saved.', 'admintrace-activity-logger' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * Delete one event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function destroy( $request ) {
		$deleted = Retention::delete_ids( array( (int) $request['id'] ) );
		if ( ! $deleted ) {
			return new \WP_Error( 'srah_not_found', __( 'That event is not available.', 'admintrace-activity-logger' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Summary counts.
	 *
	 * @return \WP_REST_Response
	 */
	public static function stats() {
		$scoped = ! current_user_can( Capabilities::MANAGE );
		$cats   = $scoped ? Vocabulary::viewer_categories() : array();
		return rest_ensure_response( Query::stats( $scoped, $cats ) );
	}

	/**
	 * View permission.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( Capabilities::VIEW );
	}

	/**
	 * Manage permission.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Delete permission.
	 *
	 * @return bool
	 */
	public static function can_delete() {
		return current_user_can( Capabilities::DELETE );
	}
}
