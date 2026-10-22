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

		$content = '<p>' . esc_html__( 'Advance History stores an activity journal in the site database. Entries can include the display name and username of the account that made a change, a short description of the change, the related content title, and the time of the event. When IP recording is left at its default, only a masked address is stored: the last part of an IPv4 address is removed, and only the first half of an IPv6 address is kept. The plugin does not store passwords, application passwords, API keys, or the contents of private messages.', 'advance-history' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Events are kept for the retention period chosen in Advance History settings, then deleted. A site administrator can export or delete the journal sooner. If a webhook or email notification is enabled, the summary of an event is sent to the address the administrator configured. Advance History does not send the journal anywhere else and does not look up IP addresses with an external service.', 'advance-history' ) . '</p>';

		wp_add_privacy_policy_content( 'Advance History', wp_kses_post( $content ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['advance-history'] = array(
			'exporter_friendly_name' => __( 'Advance History', 'advance-history' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
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
		$erasers['advance-history'] = array(
			'eraser_friendly_name' => __( 'Advance History', 'advance-history' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
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
				'group_id'    => 'advance-history',
				'group_label' => __( 'Activity journal', 'advance-history' ),
				'item_id'     => 'event-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'advance-history' ),
						'value' => $row['created_gmt'],
					),
					array(
						'name'  => __( 'Activity', 'advance-history' ),
						'value' => $row['summary'],
					),
					array(
						'name'  => __( 'Category', 'advance-history' ),
						'value' => $row['category'],
					),
					array(
						'name'  => __( 'Masked network', 'advance-history' ),
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
		$table = Installer::table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE blog_id = %d AND (user_id = %d OR (user_id = 0 AND user_login = %s))",
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
}
