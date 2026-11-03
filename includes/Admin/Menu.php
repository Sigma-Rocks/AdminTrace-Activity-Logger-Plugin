<?php
/**
 * Admin menu and privileged actions.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Email_Log;
use SigmaRocks\AdvanceHistory\Watch;
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
		add_filter( 'plugin_action_links_' . plugin_basename( SRAH_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_post_srah_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_srah_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_srah_delete', array( __CLASS__, 'delete_selected' ) );
		add_action( 'admin_post_srah_delete_all', array( __CLASS__, 'delete_all' ) );
		add_action( 'admin_post_srah_email_delete', array( __CLASS__, 'delete_emails' ) );
		add_action( 'admin_post_srah_email_delete_all', array( __CLASS__, 'delete_all_emails' ) );
		add_action( 'admin_post_srah_email_test', array( __CLASS__, 'email_test' ) );
		add_action( 'admin_post_srah_email_resend', array( __CLASS__, 'resend_email' ) );
		add_action( 'wp_ajax_srah_email_preview', array( Email_Log_Screen::class, 'ajax_preview' ) );
		add_action( 'admin_post_srah_prune', array( __CLASS__, 'prune' ) );
		add_action( 'admin_post_srah_note', array( __CLASS__, 'note' ) );
		add_action( 'admin_post_srah_test_email', array( __CLASS__, 'test_email' ) );
		add_action( 'admin_post_srah_watch_now', array( __CLASS__, 'watch_now' ) );
		add_action( 'admin_post_srah_dismiss_welcome', array( __CLASS__, 'dismiss_welcome' ) );
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
			add_submenu_page( 'index.php', __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ), __( 'AdminTrace', 'admintrace-activity-logger' ), Capabilities::VIEW, 'admintrace-activity-logger', $view );
			self::extra_pages( 'index.php' );
			add_submenu_page( 'index.php', __( 'AdminTrace Activity Logger settings', 'admintrace-activity-logger' ), __( 'AdminTrace settings', 'admintrace-activity-logger' ), Capabilities::MANAGE, 'admintrace-activity-logger-settings', $settings );
			return;
		}

		if ( 'tools' === $location ) {
			add_management_page( __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ), __( 'AdminTrace', 'admintrace-activity-logger' ), Capabilities::VIEW, 'admintrace-activity-logger', $view );
			self::extra_pages( 'tools.php' );
			add_management_page( __( 'AdminTrace Activity Logger settings', 'admintrace-activity-logger' ), __( 'AdminTrace settings', 'admintrace-activity-logger' ), Capabilities::MANAGE, 'admintrace-activity-logger-settings', $settings );
			return;
		}

		add_menu_page( __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ), __( 'AdminTrace', 'admintrace-activity-logger' ), Capabilities::VIEW, 'admintrace-activity-logger', $view, SRAH_URL . 'assets/img/admintrace-mark.png', 58 );
		add_submenu_page( 'admintrace-activity-logger', __( 'Activity journal', 'admintrace-activity-logger' ), __( 'Activity journal', 'admintrace-activity-logger' ), Capabilities::VIEW, 'admintrace-activity-logger', $view );
		self::extra_pages( 'admintrace-activity-logger' );
		add_submenu_page( 'admintrace-activity-logger', __( 'Settings', 'admintrace-activity-logger' ), __( 'Settings', 'admintrace-activity-logger' ), Capabilities::MANAGE, 'admintrace-activity-logger-settings', $settings );
	}

	/**
	 * Pages for events, files, and display.
	 *
	 * @param string $parent_slug Parent menu slug.
	 */
	private static function extra_pages( $parent_slug ) {
		$pages = array(
			array( __( 'Events', 'admintrace-activity-logger' ), 'admintrace-activity-logger-events', array( Events_Screen::class, 'render' ) ),
			array( __( 'Files and database', 'admintrace-activity-logger' ), 'admintrace-activity-logger-files', array( Files_Screen::class, 'render' ) ),
			array( __( 'Display', 'admintrace-activity-logger' ), 'admintrace-activity-logger-display', array( Display_Screen::class, 'render' ) ),
			array( __( 'Email log', 'admintrace-activity-logger' ), Email_Log::PAGE, array( Email_Log_Screen::class, 'render' ) ),
		);
		foreach ( $pages as $page ) {
			add_submenu_page( $parent_slug, $page[0], $page[0], Capabilities::MANAGE, $page[1], $page[2] );
		}
	}

	/**
	 * Run the file and table scan immediately.
	 */
	public static function watch_now() {
		self::authorize( Capabilities::MANAGE, 'srah_watch_now' );
		$result = Watch::run();
		$type   = in_array( 'error', $result, true ) ? 'warning' : 'success';
		Notices::redirect( $type, Watch::note( $result ), 'admintrace-activity-logger-files' );
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function links( $links ) {
		if ( current_user_can( Capabilities::VIEW ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=admintrace-activity-logger' ) ) . '">' . esc_html__( 'Journal', 'admintrace-activity-logger' ) . '</a>' );
		}
		$links[] = '<a href="' . esc_url( SRAH_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'admintrace-activity-logger' ) . '</a>';
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
		if ( get_user_meta( $user_id, 'srah_dismiss_welcome', true ) ) {
			return;
		}
		if ( ! get_transient( 'srah_welcome_' . $user_id ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=srah_dismiss_welcome' ), 'srah_dismiss_welcome' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'AdminTrace Activity Logger is recording activity.', 'admintrace-activity-logger' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=admintrace-activity-logger' ) ) . '">' . esc_html__( 'Open the journal', 'admintrace-activity-logger' ) . '</a> · <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'admintrace-activity-logger' ) . '</a></p></div>';
	}

	/**
	 * Dismiss the welcome notice.
	 */
	public static function dismiss_welcome() {
		self::authorize( Capabilities::VIEW, 'srah_dismiss_welcome' );
		update_user_meta( get_current_user_id(), 'srah_dismiss_welcome', 1 );
		delete_transient( 'srah_welcome_' . get_current_user_id() );
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		self::authorize( Capabilities::MANAGE, 'srah_save_settings' );
		$before = Settings::all();
		$clean  = Settings::sanitize( Settings_Screen::input_from_request(), $before );
		Settings::update( $clean );
		// Saving another section must not push the next digest back.
		if ( $before['email_digest'] !== $clean['email_digest'] ) {
			Notifications::reschedule();
		} else {
			Notifications::ensure_scheduled();
		}

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
				'summary'  => __( 'Updated AdminTrace Activity Logger settings.', 'admintrace-activity-logger' ),
				'category' => 'settings',
				'action'   => 'settings.srah',
				'severity' => 'notice',
				'logger'   => 'settings',
				'context'  => array( 'changed' => $changed ),
			)
		);
		$return = ( 'email_log' === ( isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : '' ) ) ? Email_Log::PAGE : 'admintrace-activity-logger-settings'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
		Notices::redirect( 'success', __( 'Settings saved.', 'admintrace-activity-logger' ), $return );
	}

	/**
	 * Download the current filtered journal.
	 */
	public static function export() {
		self::authorize( Capabilities::EXPORT, 'srah_export' );
		$filters = Timeline_Screen::filters();
		$args    = Access::constrain( Timeline_Screen::query_args( $filters ) );
		$format  = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() verified the nonce.
		Export::send( $format, $args );
	}

	/**
	 * Delete selected events.
	 */
	public static function delete_selected() {
		self::authorize( Capabilities::DELETE, 'srah_delete' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
		$raw = isset( $_POST['event_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['event_ids'] ) ) : array();
		// A combined card posts its row IDs as one comma-separated value.
		$deleted = Retention::delete_ids( wp_parse_id_list( implode( ',', $raw ) ) );
		if ( ! $deleted ) {
			Notices::redirect( 'warning', __( 'Select at least one event to delete.', 'admintrace-activity-logger' ) );
		}
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				_n( 'Deleted %d event.', 'Deleted %d events.', $deleted, 'admintrace-activity-logger' ),
				$deleted
			)
		);
	}

	/**
	 * Send one message so the email log can show it.
	 */
	public static function email_test() {
		self::authorize( Capabilities::MANAGE, 'srah_email_test' );
		if ( ! Email_Log::enabled() ) {
			Notices::redirect( 'warning', __( 'Turn the email log on, then send the test again.', 'admintrace-activity-logger' ), Email_Log::PAGE );
		}

		Email_Log::ensure_table();
		$to      = (string) get_option( 'admin_email' );
		$subject = __( 'AdminTrace email log test', 'admintrace-activity-logger' );
		$sent    = wp_mail(
			$to,
			$subject,
			self::test_message(),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		$saved   = Email_Log::search(
			array(
				's'        => $subject,
				'per_page' => 1,
			)
		);

		if ( empty( $saved['total'] ) ) {
			Notices::redirect( 'error', __( 'The test email was not saved. Reload this page and try again.', 'admintrace-activity-logger' ), Email_Log::PAGE );
		}

		if ( $sent ) {
			Notices::redirect(
				'success',
				sprintf(
					/* translators: %s: email address. */
					__( 'Test email saved in the log and sent to %s.', 'admintrace-activity-logger' ),
					$to
				),
				Email_Log::PAGE
			);
		}

		Notices::redirect(
			'warning',
			sprintf(
				/* translators: %s: email address. */
				__( 'The test email is in the log. The site mailer could not deliver it to %s, and the result column shows why.', 'admintrace-activity-logger' ),
				$to
			),
			Email_Log::PAGE
		);
	}

	/**
	 * Send a saved email again.
	 */
	public static function resend_email() {
		self::authorize( Capabilities::MANAGE, 'srah_email_resend' );
		$id  = isset( $_POST['email_id'] ) ? absint( wp_unslash( $_POST['email_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
		$row = Email_Log::find( $id );
		if ( ! is_array( $row ) ) {
			Notices::redirect( 'error', __( 'That email is not in the log.', 'admintrace-activity-logger' ), Email_Log::PAGE );
		}

		$to      = isset( $row['to_email'] ) ? (string) $row['to_email'] : '';
		$subject = isset( $row['subject'] ) ? (string) $row['subject'] : '';
		$message = isset( $row['message'] ) ? (string) $row['message'] : '';
		$files   = isset( $row['attachments'] ) ? (string) $row['attachments'] : '';
		$sent    = wp_mail( $to, $subject, $message, self::header_lines( isset( $row['headers'] ) ? (string) $row['headers'] : '' ) );

		Recorder::log(
			array(
				'summary'  => __( 'Resent a saved email.', 'admintrace-activity-logger' ),
				'category' => 'settings',
				'action'   => 'email_log.resent',
				'severity' => $sent ? 'notice' : 'warning',
				'logger'   => 'settings',
			)
		);

		if ( $sent && '' === $files ) {
			Notices::redirect(
				'success',
				sprintf(
					/* translators: %s: email recipient. */
					__( 'Email resent to %s. The new copy is in the log.', 'admintrace-activity-logger' ),
					$to
				),
				Email_Log::PAGE
			);
		}

		if ( $sent ) {
			Notices::redirect(
				'warning',
				sprintf(
					/* translators: %s: email recipient. */
					__( 'Email resent to %s. File attachments were not included, because the log only keeps file names.', 'admintrace-activity-logger' ),
					$to
				),
				Email_Log::PAGE
			);
		}

		Notices::redirect(
			'warning',
			sprintf(
				/* translators: %s: email recipient. */
				__( 'WordPress tried to resend the email to %s. The mailer reported a failure, and the new row shows the result.', 'admintrace-activity-logger' ),
				$to
			),
			Email_Log::PAGE
		);
	}

	/**
	 * Delete selected saved emails.
	 */
	public static function delete_emails() {
		self::authorize( Capabilities::DELETE, 'srah_email_delete' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
		$raw     = isset( $_POST['email_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['email_ids'] ) ) : array();
		$deleted = Email_Log::delete_ids( $raw );
		if ( ! $deleted ) {
			Notices::redirect( 'warning', __( 'Select at least one email to delete.', 'admintrace-activity-logger' ), Email_Log::PAGE, self::email_list_referer() );
		}
		Recorder::log(
			array(
				'summary'  => sprintf(
					/* translators: %d: number of deleted emails. */
					_n( 'Deleted %d saved email.', 'Deleted %d saved emails.', $deleted, 'admintrace-activity-logger' ),
					$deleted
				),
				'category' => 'settings',
				'action'   => 'email_log.deleted',
				'severity' => 'notice',
				'logger'   => 'settings',
			)
		);
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted emails. */
				_n( 'Deleted %d saved email.', 'Deleted %d saved emails.', $deleted, 'admintrace-activity-logger' ),
				$deleted
			),
			Email_Log::PAGE,
			self::email_list_referer()
		);
	}

	/**
	 * Delete every saved email on this site.
	 */
	public static function delete_all_emails() {
		self::authorize( Capabilities::DELETE, 'srah_email_delete_all' );
		if ( empty( $_POST['confirm_delete_email_log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
			Notices::redirect( 'error', __( 'Confirm deletion before clearing the email log.', 'admintrace-activity-logger' ), Email_Log::PAGE );
		}
		$deleted = Email_Log::delete_all();
		Recorder::log(
			array(
				'summary'  => sprintf(
					/* translators: %d: number of deleted emails. */
					_n( 'Cleared %d saved email.', 'Cleared %d saved emails.', $deleted, 'admintrace-activity-logger' ),
					$deleted
				),
				'category' => 'settings',
				'action'   => 'email_log.cleared',
				'severity' => 'notice',
				'logger'   => 'settings',
			)
		);
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted emails. */
				__( 'Deleted %d saved emails.', 'admintrace-activity-logger' ),
				$deleted
			),
			Email_Log::PAGE
		);
	}

	/**
	 * Keep list filters after a bulk delete, and leave a single-email view.
	 *
	 * @return bool
	 */
	private static function email_list_referer() {
		$ref = wp_get_referer();
		return ! ( is_string( $ref ) && false !== strpos( $ref, 'email=' ) );
	}

	/**
	 * Delete every event on this site.
	 */
	public static function delete_all() {
		self::authorize( Capabilities::DELETE, 'srah_delete_all' );
		if ( empty( $_POST['confirm_delete_all'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
			Notices::redirect( 'error', __( 'Confirm deletion before clearing the journal.', 'admintrace-activity-logger' ), 'admintrace-activity-logger-settings' );
		}
		$deleted = Retention::delete_all();
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				__( 'Deleted %d events.', 'admintrace-activity-logger' ),
				$deleted
			),
			'admintrace-activity-logger-settings'
		);
	}

	/**
	 * Run retention immediately.
	 */
	public static function prune() {
		self::authorize( Capabilities::DELETE, 'srah_prune' );
		$deleted = Retention::prune();
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %d: number of deleted events. */
				__( 'Removed %d events older than the retention period.', 'admintrace-activity-logger' ),
				$deleted
			),
			'admintrace-activity-logger-settings'
		);
	}

	/**
	 * Save a manual note.
	 */
	public static function note() {
		self::authorize( Capabilities::MANAGE, 'srah_note' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce.
		$summary  = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';
		$severity = isset( $_POST['severity'] ) ? sanitize_key( wp_unslash( $_POST['severity'] ) ) : 'info';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! isset( Vocabulary::severities()[ $severity ] ) ) {
			$severity = 'info';
		}
		$id = Recorder::log(
			array(
				'summary'  => $summary,
				'category' => 'journal',
				'action'   => 'journal.note',
				'severity' => $severity,
				'logger'   => 'journal',
			)
		);
		if ( ! $id ) {
			Notices::redirect( 'error', __( 'The note could not be saved.', 'admintrace-activity-logger' ) );
		}
		Notices::redirect( 'success', __( 'Note added to the journal.', 'admintrace-activity-logger' ) );
	}

	/**
	 * Send the digest to its recipient now.
	 */
	public static function test_email() {
		self::authorize( Capabilities::MANAGE, 'srah_test_email' );
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
			Notices::redirect( 'error', __( 'The test email could not be sent.', 'admintrace-activity-logger' ), 'admintrace-activity-logger-settings' );
		}
		Notices::redirect(
			'success',
			sprintf(
				/* translators: %s: email address. */
				__( 'Test digest sent to %s.', 'admintrace-activity-logger' ),
				$to
			),
			'admintrace-activity-logger-settings'
		);
	}

	/**
	 * HTML body for the email-log test message.
	 *
	 * @return string
	 */
	private static function test_message() {
		$text = esc_html__( 'This message confirms that AdminTrace is saving outgoing email and can preview the HTML.', 'admintrace-activity-logger' );
		return '<!DOCTYPE html><html><body style="margin:0;background:#f4f1ea;font-family:Georgia,serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;"><tr><td align="center" style="padding:28px 16px;">'
			. '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%;background:#ffffff;border-radius:12px;">'
			. '<tr><td style="padding:28px 32px 8px;font-size:22px;line-height:1.3;color:#1d3c34;">AdminTrace</td></tr>'
			. '<tr><td style="padding:8px 32px 28px;font-size:16px;line-height:1.5;color:#1c2430;">' . $text . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Split a stored header block into wp_mail header lines.
	 *
	 * @param string $stored Saved headers.
	 * @return string[]
	 */
	private static function header_lines( $stored ) {
		$lines   = preg_split( "/\r\n|\n|\r/", (string) $stored );
		$headers = array();
		if ( ! is_array( $lines ) ) {
			return $headers;
		}
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$headers[] = $line;
			}
		}
		return $headers;
	}

	/**
	 * Check capability and nonce.
	 *
	 * @param string $capability Capability.
	 * @param string $action     Nonce action.
	 */
	private static function authorize( $capability, $action ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'admintrace-activity-logger' ), 403 );
		}
		check_admin_referer( $action );
	}
}
