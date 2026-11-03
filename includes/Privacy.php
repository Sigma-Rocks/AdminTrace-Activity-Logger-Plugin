<?php
/**
 * Privacy tools.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy policy text, personal-data export, and erasure.
 */
class Privacy {

	/**
	 * Register privacy hooks.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Suggested privacy-policy text.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'AdminTrace Activity Logger stores an activity journal in the site database. Entries can include the display name and username of the account that made a change, a short description of the change, the related content title, and the time of the event. A post edit can also include a short line-by-line preview of the changed text. The full post is not copied into the journal. When IP recording is left at its default, only a masked address is stored: the last part of an IPv4 address is removed, and only the first half of an IPv6 address is kept. The plugin does not store passwords, application passwords, or API keys.', 'admintrace-activity-logger' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When an administrator turns the email log on, a separate table stores the recipient, subject, headers, and body of email the site sends. That log stays off until then. It uses the same retention period as the journal. A privacy erase removes messages addressed to the person who asked, and removes their username from emails sent during their visit.', 'admintrace-activity-logger' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Events are kept for the retention period chosen in AdminTrace Activity Logger settings, then deleted. A site administrator can export or delete the journal sooner. If a webhook or email notification is enabled, the summary of an event is sent to the address the administrator configured. AdminTrace Activity Logger does not send the journal anywhere else and does not look up IP addresses with an external service.', 'admintrace-activity-logger' ) . '</p>';

		wp_add_privacy_policy_content( 'AdminTrace Activity Logger', wp_kses_post( $content ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['admintrace-activity-logger']       = array(
			'exporter_friendly_name' => __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		$exporters['admintrace-activity-logger-email'] = array(
			'exporter_friendly_name' => __( 'AdminTrace email log', 'admintrace-activity-logger' ),
			'callback'               => array( __CLASS__, 'export_email_log' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['admintrace-activity-logger']       = array(
			'eraser_friendly_name' => __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		$erasers['admintrace-activity-logger-email'] = array(
			'eraser_friendly_name' => __( 'AdminTrace email log', 'admintrace-activity-logger' ),
			'callback'             => array( __CLASS__, 'erase_email_log' ),
		);
		return $erasers;
	}

	/**
	 * Export events tied to an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$page = max( 1, (int) $page );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$result = Query::search(
			array(
				'user_id'        => (int) $user->ID,
				'page'           => $page,
				'per_page'       => 50,
				'unrestricted'   => true,
				'internal_limit' => true,
			)
		);

		$data = array();
		foreach ( $result['rows'] as $row ) {
			$data[] = array(
				'group_id'    => 'admintrace-activity-logger',
				'group_label' => __( 'Activity journal', 'admintrace-activity-logger' ),
				'item_id'     => 'event-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'admintrace-activity-logger' ),
						'value' => $row['created_gmt'],
					),
					array(
						'name'  => __( 'Activity', 'admintrace-activity-logger' ),
						'value' => $row['summary'],
					),
					array(
						'name'  => __( 'Category', 'admintrace-activity-logger' ),
						'value' => $row['category'],
					),
					array(
						'name'  => __( 'Masked network', 'admintrace-activity-logger' ),
						'value' => $row['ip_masked'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $result['rows'] ) < 50,
		);
	}

	/**
	 * Delete events tied to an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own events table has no WordPress API; journal reads must be live.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE blog_id = %d AND (user_id = %d OR (user_id = 0 AND user_login = %s))',
				Installer::table(),
				get_current_blog_id(),
				(int) $user->ID,
				$user->user_login
			)
		);

		return array(
			'items_removed'  => (int) $wpdb->rows_affected > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Export saved emails addressed to this person.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_email_log( $email, $page = 1 ) {
		$result = Email_Log::for_address( $email, $page, 20 );
		$data   = array();
		foreach ( $result['rows'] as $row ) {
			$data[] = array(
				'group_id'    => 'admintrace-email-log',
				'group_label' => __( 'Email log', 'admintrace-activity-logger' ),
				'item_id'     => 'email-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'admintrace-activity-logger' ),
						'value' => $row['sent_gmt'],
					),
					array(
						'name'  => __( 'To', 'admintrace-activity-logger' ),
						'value' => $row['to_email'],
					),
					array(
						'name'  => __( 'Subject', 'admintrace-activity-logger' ),
						'value' => $row['subject'],
					),
					array(
						'name'  => __( 'Result', 'admintrace-activity-logger' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Message', 'admintrace-activity-logger' ),
						'value' => $row['message'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => ! empty( $result['done'] ),
		);
	}

	/**
	 * Delete saved emails addressed to this person.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function erase_email_log( $email, $page = 1 ) {
		unset( $page );
		$removed = Email_Log::delete_for_address( $email );
		$user    = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$removed += Email_Log::anonymize_user( (int) $user->ID );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
