<?php
/**
 * Privacy request events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records privacy exports and erasures without storing archive URLs or filesystem paths.
 */
class Privacy_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'privacy';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Privacy requests', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Personal data exports, erasures, and tools exports.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'wp_privacy_personal_data_export_file_created', 'on_export_file', 4 );
		$this->listen( 'wp_privacy_personal_data_erased', 'on_erased', 1 );
		$this->listen( 'user_request_action_confirmed', 'on_confirmed', 1 );
		$this->listen( 'export_wp', 'on_tools_export', 1 );
	}

	/**
	 * Export file created. Paths and download URLs are discarded.
	 *
	 * @param string $path Archive path, discarded.
	 * @param string $url Archive URL, discarded.
	 * @param string $html HTML report path, discarded.
	 * @param int    $request_id Request ID.
	 */
	public function on_export_file( $path, $url, $html, $request_id ) {
		unset( $path, $url, $html );
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %d: request ID. */
					__( 'Created a personal data export for request %d.', 'admintrace-activity-logger' ),
					absint( $request_id )
				),
				'category'    => 'privacy',
				'action'      => 'privacy.export_file',
				'severity'    => 'notice',
				'object_type' => 'privacy_request',
				'object_id'   => absint( $request_id ),
			)
		);
	}

	/**
	 * Personal data erased.
	 *
	 * @param int $request_id Request ID.
	 */
	public function on_erased( $request_id ) {
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %d: request ID. */
					__( 'Erased personal data for request %d.', 'admintrace-activity-logger' ),
					absint( $request_id )
				),
				'category'    => 'privacy',
				'action'      => 'privacy.erased',
				'severity'    => 'warning',
				'object_type' => 'privacy_request',
				'object_id'   => absint( $request_id ),
			)
		);
	}

	/**
	 * Privacy request confirmed.
	 *
	 * @param int $request_id Request ID.
	 */
	public function on_confirmed( $request_id ) {
		$request = function_exists( 'wp_get_user_request' ) ? wp_get_user_request( $request_id ) : null;
		$action  = ( $request && isset( $request->action_name ) ) ? sanitize_key( $request->action_name ) : 'request';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: 1: request type, 2: request ID. */
					__( 'Confirmed a %1$s privacy request (%2$d).', 'admintrace-activity-logger' ),
					$action,
					absint( $request_id )
				),
				'category'    => 'privacy',
				'action'      => 'privacy.confirmed',
				'severity'    => 'notice',
				'object_type' => 'privacy_request',
				'object_id'   => absint( $request_id ),
			)
		);
	}

	/**
	 * Tools → Export.
	 *
	 * @param array $args Export arguments.
	 */
	public function on_tools_export( $args ) {
		$content = is_array( $args ) && ! empty( $args['content'] ) ? sanitize_key( $args['content'] ) : 'all';
		$status  = is_array( $args ) && ! empty( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %s: exported content type. */
					__( 'Exported WordPress content (%s).', 'admintrace-activity-logger' ),
					$content
				),
				'category' => 'privacy',
				'action'   => 'privacy.tools_export',
				'severity' => 'notice',
				'context'  => array(
					'content' => $content,
					'status'  => $status,
				),
			)
		);
	}
}
