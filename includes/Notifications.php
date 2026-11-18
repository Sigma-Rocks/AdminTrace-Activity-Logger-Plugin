<?php
/**
 * Email digests, alerts, and webhooks.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Text;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional notifications configured by the site owner.
 */
class Notifications {

	const DIGEST = 'srah_digest';

	/**
	 * Whether a notification is currently being sent.
	 *
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::DIGEST, array( __CLASS__, 'scheduled_digest' ), 10, 0 );
		add_action( 'srah_logged', array( __CLASS__, 'on_logged' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Add a weekly schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['srah_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once a week (AdminTrace Activity Logger)', 'admintrace-activity-logger' ),
		);
		return $schedules;
	}

	/**
	 * Keep the digest schedule aligned with settings.
	 */
	public static function ensure_scheduled() {
		$mode = Settings::all()['email_digest'];
		$next = wp_next_scheduled( self::DIGEST );
		if ( 'off' === $mode ) {
			if ( $next ) {
				self::unschedule();
			}
			return;
		}
		if ( ! $next ) {
			self::reschedule();
		}
	}

	/**
	 * Replace the digest schedule.
	 */
	public static function reschedule() {
		self::unschedule();
		$mode = Settings::all()['email_digest'];
		if ( 'daily' === $mode ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DIGEST );
		} elseif ( 'weekly' === $mode ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'srah_weekly', self::DIGEST );
		}
	}

	/**
	 * Clear the digest event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DIGEST );
	}

	/**
	 * Cron callback for the digest.
	 */
	public static function scheduled_digest() {
		self::send_digest();
	}

	/**
	 * Send the digest email.
	 *
	 * @return bool
	 */
	public static function send_digest() {
		$settings = Settings::all();
		if ( 'off' === $settings['email_digest'] ) {
			return false;
		}

		$since  = 'daily' === $settings['email_digest'] ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
		$after  = gmdate( 'Y-m-d H:i:s', time() - $since );
		$result = Query::search(
			array(
				'after'          => $after,
				'per_page'       => 15,
				'page'           => 1,
				'unrestricted'   => true,
				'internal_limit' => true,
			)
		);

		$lines   = array();
		$lines[] = __( 'AdminTrace Activity Logger', 'admintrace-activity-logger' );
		$lines[] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of events. */
			__( 'Events in this period: %d', 'admintrace-activity-logger' ),
			(int) $result['total']
		);
		$lines[] = '';

		if ( ! $result['rows'] ) {
			$lines[] = __( 'No activity was recorded.', 'admintrace-activity-logger' );
		} else {
			foreach ( $result['rows'] as $row ) {
				$lines[] = '- ' . Text::plain( $row['summary'], 240 ) . ' (' . $row['created_gmt'] . ' UTC)';
			}
		}

		$lines[] = '';
		$lines[] = __( 'Open the journal:', 'admintrace-activity-logger' );
		$lines[] = admin_url( 'admin.php?page=admintrace-activity-logger' );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Activity digest', 'admintrace-activity-logger' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return self::mail( self::recipient( $settings['digest_email'] ), $subject, implode( "\n", $lines ) );
	}

	/**
	 * React to a stored event.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $row      Stored row.
	 */
	public static function on_logged( $event_id, $row ) {
		if ( self::$sending || ! is_array( $row ) ) {
			return;
		}

		$row['id'] = (int) $event_id;
		self::maybe_webhook( $row );

		if ( 'critical' !== $row['severity'] ) {
			return;
		}
		$settings = Settings::all();
		$action   = isset( $row['action'] ) ? $row['action'] : '';
		if ( 'security.login_threshold' === $action && empty( $settings['alert_failed_logins'] ) ) {
			return;
		}

		$body = Text::plain( $row['summary'], 500 ) . "\n\n" . admin_url( 'admin.php?page=admintrace-activity-logger' );
		if ( 'security.login_threshold' === $action ) {
			$body .= self::recaptcha_tip();
		}

		self::mail(
			self::recipient( $settings['alert_email'] ),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Critical activity', 'admintrace-activity-logger' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$body
		);
	}

	/**
	 * Send a failed-login threshold alert.
	 *
	 * @param int $count Attempts in the current window.
	 */
	public static function alert_failed_logins( $count ) {
		$settings = Settings::all();
		if ( empty( $settings['alert_failed_logins'] ) ) {
			return;
		}

		$body = sprintf(
			/* translators: %d: number of failed logins. */
			__( '%d failed logins were recorded in the last 15 minutes.', 'admintrace-activity-logger' ),
			(int) $count
		);
		$body .= "\n\n" . admin_url( 'admin.php?page=admintrace-activity-logger&category=security' );
		$body .= self::recaptcha_tip();

		self::mail(
			self::recipient( $settings['alert_email'] ),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Repeated failed logins', 'admintrace-activity-logger' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$body
		);
	}

	/**
	 * Extra line for failed-login alerts when reCAPTCHA is off.
	 *
	 * @return string
	 */
	private static function recaptcha_tip() {
		if ( Recaptcha::is_active() ) {
			return '';
		}

		return "\n\n"
			. __( 'Google reCAPTCHA v3 is not enabled on this site. Enable it in AdminTrace settings to help block automated login attempts.', 'admintrace-activity-logger' )
			. "\n"
			. admin_url( 'admin.php?page=admintrace-activity-logger-settings' );
	}

	/**
	 * POST a minimal event payload to the configured webhook.
	 *
	 * The URL is chosen by a site administrator. Payloads omit IP addresses.
	 *
	 * @param array $row Stored event.
	 */
	private static function maybe_webhook( array $row ) {
		$settings = Settings::all();
		if ( '' === $settings['webhook_url'] || 'off' === $settings['webhook_min_severity'] ) {
			return;
		}
		if ( Vocabulary::severity_rank( $row['severity'] ) < Vocabulary::severity_rank( $settings['webhook_min_severity'] ) ) {
			return;
		}

		$payload = array(
			'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'created'    => isset( $row['created_gmt'] ) ? $row['created_gmt'] : '',
			'summary'    => isset( $row['summary'] ) ? $row['summary'] : '',
			'category'   => isset( $row['category'] ) ? $row['category'] : '',
			'action'     => isset( $row['action'] ) ? $row['action'] : '',
			'severity'   => isset( $row['severity'] ) ? $row['severity'] : '',
			'user_login' => isset( $row['user_login'] ) ? $row['user_login'] : '',
			'object'     => isset( $row['object_title'] ) ? $row['object_title'] : '',
			'site'       => home_url(),
		);

		wp_safe_remote_post(
			$settings['webhook_url'],
			array(
				'timeout'  => 1,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Choose a recipient.
	 *
	 * @param string $configured Configured address.
	 * @return string
	 */
	public static function recipient( $configured ) {
		$configured = sanitize_email( (string) $configured );
		if ( is_email( $configured ) ) {
			return $configured;
		}
		return sanitize_email( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Send a plain-text message.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return bool
	 */
	private static function mail( $to, $subject, $body ) {
		$to = sanitize_email( (string) $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		self::$sending = true;
		$sent          = wp_mail( $to, Text::plain( $subject, 180 ), $body );
		self::$sending = false;
		return (bool) $sent;
	}
}
