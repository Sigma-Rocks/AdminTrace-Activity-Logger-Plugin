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

	const DIGEST = 'advance_history_digest';

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
		add_action( self::DIGEST, array( __CLASS__, 'send_digest' ) );
		add_action( 'advance_history_logged', array( __CLASS__, 'on_logged' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Add a weekly schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['ah_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once a week (Advance History)', 'advance-history' ),
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
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'ah_weekly', self::DIGEST );
		}
	}

	/**
	 * Clear the digest event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DIGEST );
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

		$since = 'daily' === $settings['email_digest'] ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
		$after = gmdate( 'Y-m-d H:i:s', time() - $since );
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
		$lines[] = __( 'Advance History', 'advance-history' );
		$lines[] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of events. */
			__( 'Events in this period: %d', 'advance-history' ),
			(int) $result['total']
		);
		$lines[] = '';

		if ( ! $result['rows'] ) {
			$lines[] = __( 'No activity was recorded.', 'advance-history' );
		} else {
			foreach ( $result['rows'] as $row ) {
				$lines[] = '- ' . Text::plain( $row['summary'], 240 ) . ' (' . $row['created_gmt'] . ' UTC)';
			}
		}

		$lines[] = '';
		$lines[] = __( 'Open the journal:', 'advance-history' );
		$lines[] = admin_url( 'admin.php?page=advance-history' );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Activity digest', 'advance-history' ),
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
		unset( $event_id );
		if ( self::$sending || ! is_array( $row ) ) {
			return;
		}

		self::maybe_webhook( $row );

		if ( 'critical' !== $row['severity'] ) {
			return;
		}
		$settings = Settings::all();
		$action   = isset( $row['action'] ) ? $row['action'] : '';
		if ( 'security.login_threshold' === $action && empty( $settings['alert_failed_logins'] ) ) {
			return;
		}

		self::mail(
			self::recipient( $settings['alert_email'] ),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Critical activity', 'advance-history' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			Text::plain( $row['summary'], 500 ) . "\n\n" . admin_url( 'admin.php?page=advance-history' )
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

		self::mail(
			self::recipient( $settings['alert_email'] ),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Repeated failed logins', 'advance-history' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: %d: number of failed logins. */
				__( '%d failed logins were recorded in the last 15 minutes.', 'advance-history' ),
				(int) $count
			) . "\n\n" . admin_url( 'admin.php?page=advance-history&category=security' )
		);
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
