<?php
/**
 * Admin menu and privileged actions.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Export;
use SigmaRocks\AdvanceHistory\Notifications;
use SigmaRocks\AdvanceHistory\Recorder;
use SigmaRocks\AdvanceHistory\Retention;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers screens and handles form posts.
 */
class Menu {

	/**
	 * Register admin hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'welcome' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AH_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_post_advance_history_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_advance_history_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_advance_history_delete', array( __CLASS__, 'delete_selected' ) );
		add_action( 'admin_post_advance_history_delete_all', array( __CLASS__, 'delete_all' ) );
		add_action( 'admin_post_advance_history_prune', array( __CLASS__, 'prune' ) );
		add_action( 'admin_post_advance_history_note', array( __CLASS__, 'note' ) );
		add_action( 'admin_post_advance_history_test_email', array( __CLASS__, 'test_email' ) );
		add_action( 'admin_post_advance_history_dismiss_welcome', array( __CLASS__, 'dismiss_welcome' ) );
		Assets::register();
		Dashboard_Widget::register();
	}

	/**
	 * Add the menu in the chosen location.
	 */
	public static function menu() {
		$location = Settings::all()['menu_location'];
		$view     = array( Timeline_Screen::class, 'render' );
		$settings = array( Settings_Screen::class, 'render' );

		if ( 'dashboard' === $location ) {
			add_submenu_page( 'index.php', __( 'Advance History', 'advance-history' ), __( 'Advance History', 'advance-history' ), Capabilities::VIEW, 'advance-history', $view );
			add_submenu_page( 'index.php', __( 'Advance History settings', 'advance-history' ), __( 'History settings', 'advance-history' ), Capabilities::MANAGE, 'advance-history-settings', $settings );
			return;
		}

		if ( 'tools' === $location ) {
			add_management_page( __( 'Advance History', 'advance-history' ), __( 'Advance History', 'advance-history' ), Capabilities::VIEW, 'advance-history', $view );
			add_management_page( __( 'Advance History settings', 'advance-history' ), __( 'History settings', 'advance-history' ), Capabilities::MANAGE, 'advance-history-settings', $settings );
			return;
		}

		add_menu_page( __( 'Advance History', 'advance-history' ), __( 'Advance History', 'advance-history' ), Capabilities::VIEW, 'advance-history', $view, 'dashicons-clock', 58 );
		add_submenu_page( 'advance-history', __( 'Activity journal', 'advance-history' ), __( 'Activity journal', 'advance-history' ), Capabilities::VIEW, 'advance-history', $view );
		add_submenu_page( 'advance-history', __( 'Settings', 'advance-history' ), __( 'Settings', 'advance-history' ), Capabilities::MANAGE, 'advance-history-settings', $settings );
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function links( $links ) {
		if ( current_user_can( Capabilities::VIEW ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=advance-history' ) ) . '">' . esc_html__( 'Journal', 'advance-history' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * First-run notice.
	 */
	public static function welcome() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, 'advance_history_dismiss_welcome', true ) ) {
			return;
		}
		if ( ! get_transient( 'ah_welcome_' . $user_id ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=advance_history_dismiss_welcome' ), 'advance_history_dismiss_welcome' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Advance History is recording activity.', 'advance-history' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=advance-history' ) ) . '">' . esc_html__( 'Open the journal', 'advance-history' ) . '</a> · <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'advance-history' ) . '</a></p></div>';
	}

	/**
	 * Dismiss the welcome notice.
	 */
	public static function dismiss_welcome() {
		self::authorize( Capabilities::VIEW, 'advance_history_dismiss_welcome' );
		update_user_meta( get_current_user_id(), 'advance_history_dismiss_welcome', 1 );
		delete_transient( 'ah_welcome_' . get_current_user_id() );
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		self::authorize( Capabilities::MANAGE, 'advance_history_save_settings' );
		$before = Settings::all();
		$clean  = Settings::sanitize( Settings_Screen::input_from_request(), $before );
		Settings::update( $clean );
		Notifications::reschedule();

		$changed = array();
		foreach ( $clean as $key => $value ) {
			if ( in_array( $key, array( 'feed_token', 'webhook_url' ), true ) ) {
				if ( (string) $before[ $key ] !== (string) $value ) {
					$changed[] = $key;
				}
				continue;
			}
			if ( $before[ $key ] !== $value ) {
				$changed[] = $key;
			}
		}
		Recorder::log(
			array(
				'summary'  => __( 'Updated Advance History settings.', 'advance-history' ),
				'category' => 'settings',
				'action'   => 'settings.advance_history',
				'severity' => 'notice',
				'logger'   => 'settings',
				'context'  => array( 'changed' => $changed ),
			)
		);
		Notices::redirect( 'success', __( 'Settings saved.', 'advance-history' ), 'advance-history-settings' );
	}

	/**
	 * Download the current filtered journal.
	 */
	public static function export() {
		self::authorize( Capabilities::EXPORT, 'advance_history_export' );
		$filters = Timeline_Screen::filters();
		$args    = Access::constrain( Timeline_Screen::query_args( $filters ) );
		$format  = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'csv';
		Export::send( $format, $args );
	}

	/**
	 * Delete selected events.
	 */
	public static function delete_selected() {
		self::authorize( Capabilities::DELETE, 'advance_history_delete' );
		$ids = isset( $_POST['event_ids'] ) ? (array) wp_unslash( $_POST['event_ids'] ) : array();
		$deleted = Retention::delete_ids( $ids );
		if ( ! $deleted ) {
			Notices::redirect( 'warning', __( 'Select at least one event to delete.', 'advance-history' ) );
		}
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				__( 'Deleted %d events.', 'advance-history' ),
				$deleted
			)
		);
	}

	/**
	 * Delete every event on this site.
	 */
	public static function delete_all() {
		self::authorize( Capabilities::DELETE, 'advance_history_delete_all' );
		if ( empty( $_POST['confirm_delete_all'] ) ) {
			Notices::redirect( 'error', __( 'Confirm deletion before clearing the journal.', 'advance-history' ), 'advance-history-settings' );
		}
		$deleted = Retention::delete_all();
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				__( 'Deleted %d events.', 'advance-history' ),
				$deleted
			),
			'advance-history-settings'
		);
	}

	/**
	 * Run retention immediately.
	 */
	public static function prune() {
		self::authorize( Capabilities::DELETE, 'advance_history_prune' );
		$deleted = Retention::prune();
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				__( 'Removed %d events older than the retention period.', 'advance-history' ),
				$deleted
			),
			'advance-history-settings'
		);
	}

	/**
	 * Save a manual note.
	 */
	public static function note() {
		self::authorize( Capabilities::MANAGE, 'advance_history_note' );
		$summary  = isset( $_POST['summary'] ) ? wp_unslash( $_POST['summary'] ) : '';
		$severity = isset( $_POST['severity'] ) ? sanitize_key( wp_unslash( $_POST['severity'] ) ) : 'info';
		if ( ! isset( Vocabulary::severities()[ $severity ] ) ) {
			$severity = 'info';
		}
		$id = Recorder::log(
			array(
				'summary'   => $summary,
				'category'  => 'journal',
				'action'    => 'journal.note',
				'severity'  => $severity,
				'logger'    => 'journal',
			)
		);
		if ( ! $id ) {
			Notices::redirect( 'error', __( 'The note could not be saved.', 'advance-history' ) );
		}
		Notices::redirect( 'success', __( 'Note added to the journal.', 'advance-history' ) );
	}

	/**
	 * Send the digest to its recipient now.
	 */
	public static function test_email() {
		self::authorize( Capabilities::MANAGE, 'advance_history_test_email' );
		$settings = Settings::all();
		$previous = $settings['email_digest'];
		if ( 'off' === $previous ) {
			$settings['email_digest'] = 'weekly';
			Settings::update( $settings );
		}
		try {
			$sent = Notifications::send_digest();
		} finally {
			if ( 'off' === $previous ) {
				$settings['email_digest'] = 'off';
				Settings::update( $settings );
			}
		}
		$to = Notifications::recipient( Settings::all()['digest_email'] );
		if ( ! $sent ) {
			Notices::redirect( 'error', __( 'The test email could not be sent.', 'advance-history' ), 'advance-history-settings' );
		}
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %s: email address. */
				__( 'Test digest sent to %s.', 'advance-history' ),
				$to
			),
			'advance-history-settings'
		);
	}

	/**
	 * Check capability and nonce.
	 *
	 * @param string $capability Capability.
	 * @param string $action     Nonce action.
	 */
	private static function authorize( $capability, $action ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'advance-history' ), 403 );
		}
		check_admin_referer( $action );
	}
}
