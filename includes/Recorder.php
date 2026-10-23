<?php
/**
 * Event recorder.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Context;
use SigmaRocks\AdvanceHistory\Support\Ip;
use SigmaRocks\AdvanceHistory\Support\Text;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and stores activity events.
 */
class Recorder {

	/**
	 * Re-entry guard.
	 *
	 * @var bool
	 */
	private static $inside = false;

	/**
	 * Suspend counter.
	 *
	 * @var int
	 */
	private static $suspended = 0;

	/**
	 * Whether a missing-table repair has already been attempted.
	 *
	 * @var bool
	 */
	private static $retried = false;

	/**
	 * Stop recording for an internal operation.
	 */
	public static function suspend() {
		++self::$suspended;
	}

	/**
	 * Resume recording.
	 */
	public static function resume() {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	/**
	 * Store an event.
	 *
	 * @param array $event Event arguments.
	 * @return int
	 */
	public static function log( array $event ) {
		if ( self::$suspended > 0 || self::$inside ) {
			return 0;
		}
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return 0;
		}

		self::$inside = true;
		try {
			return self::write( $event );
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		} finally {
			self::$inside = false;
		}
	}

	/**
	 * Detect who initiated the current request.
	 *
	 * @return string
	 */
	public static function initiator() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return 'anonymous';
		}
		return 'user';
	}

	/**
	 * Persist a validated event.
	 *
	 * @param array $event Raw event.
	 * @return int
	 */
	private static function write( array $event ) {
		global $wpdb;

		$allowed = apply_filters( 'advance_history_should_log', true, $event );
		if ( ! $allowed ) {
			return 0;
		}

		$filtered = apply_filters( 'advance_history_event', $event );
		if ( ! is_array( $filtered ) ) {
			return 0;
		}
		$event = $filtered;

		$severity = isset( $event['severity'] ) ? Text::key( $event['severity'], 16, 'info' ) : 'info';
		if ( ! isset( Vocabulary::severities()[ $severity ] ) ) {
			$severity = 'info';
		}

		$category = isset( $event['category'] ) ? Text::key( $event['category'], 32, 'system' ) : 'system';
		$action   = isset( $event['action'] ) ? Text::key( $event['action'], 64, 'custom.event' ) : 'custom.event';
		$logger   = isset( $event['logger'] ) ? Text::key( $event['logger'], 32, 'api' ) : 'api';
		$summary  = Text::plain( isset( $event['summary'] ) ? $event['summary'] : '', 500 );
		if ( '' === $summary ) {
			return 0;
		}

		$initiator = isset( $event['initiator'] ) ? Text::key( $event['initiator'], 20, '' ) : '';
		if ( ! isset( Vocabulary::initiators()[ $initiator ] ) ) {
			$initiator = self::initiator();
		}

		if ( array_key_exists( 'user_id', $event ) ) {
			$user_id = absint( $event['user_id'] );
		} elseif ( in_array( $initiator, array( 'cron', 'cli', 'system', 'anonymous' ), true ) ) {
			$user_id = 0;
		} else {
			$user_id = get_current_user_id();
		}

		$user_login   = Text::plain( isset( $event['user_login'] ) ? $event['user_login'] : '', 60 );
		$user_display = Text::plain( isset( $event['user_display'] ) ? $event['user_display'] : '', 191 );
		if ( $user_id > 0 && ( '' === $user_login || '' === $user_display ) ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				if ( '' === $user_login ) {
					$user_login = Text::plain( $user->user_login, 60 );
				}
				if ( '' === $user_display ) {
					$user_display = Text::plain( $user->display_name, 191 );
				}
			}
		}

		if ( self::excluded( $user_id, $severity, $category ) ) {
			return 0;
		}

		$settings = Settings::all();
		$ip       = '';
		if ( 'off' !== $settings['ip_mode'] && ! in_array( $initiator, array( 'cron', 'cli', 'system' ), true ) ) {
			if ( isset( $event['ip_masked'] ) ) {
				$ip = Ip::mask( $event['ip_masked'] );
			} else {
				$ip = Ip::from_request( $_SERVER, ! empty( $settings['trust_proxy'] ) );
			}
		}

		$created = isset( $event['created_gmt'] ) ? (string) $event['created_gmt'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created ) ) {
			$created = gmdate( 'Y-m-d H:i:s' );
		}

		$context = Context::clean( isset( $event['context'] ) ? $event['context'] : array() );
		$encoded = wp_json_encode( $context );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}

		$row = array(
			'blog_id'      => get_current_blog_id(),
			'created_gmt'  => $created,
			'user_id'      => $user_id,
			'user_login'   => $user_login,
			'user_display' => $user_display,
			'initiator'    => $initiator,
			'ip_masked'    => $ip,
			'severity'     => $severity,
			'category'     => $category,
			'logger'       => $logger,
			'action'       => $action,
			'object_type'  => Text::key( isset( $event['object_type'] ) ? $event['object_type'] : '', 32, '' ),
			'object_id'    => isset( $event['object_id'] ) ? absint( $event['object_id'] ) : 0,
			'object_title' => Text::plain( isset( $event['object_title'] ) ? $event['object_title'] : '', 191 ),
			'summary'      => $summary,
			'context'      => $encoded,
		);

		$absorbed = self::absorb( $row );
		if ( $absorbed ) {
			return $absorbed;
		}

		$inserted = self::insert( $row );
		if ( ! $inserted ) {
			return 0;
		}

		$event_id = (int) $wpdb->insert_id;
		do_action( 'advance_history_logged', $event_id, $row );

		return $event_id;
	}

	/**
	 * Fold a repeat into the latest matching row from the last 30 minutes.
	 *
	 * @param array $row Prepared database row.
	 * @return int Existing event ID, or 0 when a new row is required.
	 */
	private static function absorb( array $row ) {
		global $wpdb;

		$created = strtotime( $row['created_gmt'] . ' UTC' );
		if ( ! $created || abs( time() - $created ) > 120 ) {
			return 0;
		}

		$table    = Installer::table();
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, context, created_gmt FROM {$table} WHERE blog_id = %d AND action = %s AND summary = %s AND user_id = %d AND object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				$row['blog_id'],
				$row['action'],
				$row['summary'],
				$row['user_id'],
				$row['object_type'],
				$row['object_id']
			),
			ARRAY_A
		);
		if ( ! is_array( $existing ) ) {
			return 0;
		}

		$previous = json_decode( (string) $existing['context'], true );
		if ( ! is_array( $previous ) ) {
			$previous = array();
		}
		$incoming = json_decode( (string) $row['context'], true );
		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}
		$first = isset( $previous['first_gmt'] ) ? strtotime( $previous['first_gmt'] . ' UTC' ) : strtotime( $existing['created_gmt'] . ' UTC' );
		if ( ! $first || ( $created - $first ) > 1800 || ( $created - $first ) < 0 ) {
			return 0;
		}

		$times   = isset( $previous['repeat_times'] ) && is_array( $previous['repeat_times'] ) ? $previous['repeat_times'] : array( $existing['created_gmt'] );
		$times[] = $row['created_gmt'];
		$context = $incoming;
		$context['repeat_count'] = ( isset( $previous['repeat_count'] ) ? (int) $previous['repeat_count'] : 1 ) + 1;
		$context['first_gmt']    = gmdate( 'Y-m-d H:i:s', $first );
		$context['last_gmt']     = $row['created_gmt'];
		$context['repeat_times'] = array_slice( $times, -12 );
		$clean                   = Context::clean( $context );
		$encoded                 = wp_json_encode( $clean );
		if ( ! is_string( $encoded ) ) {
			return 0;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'created_gmt' => $row['created_gmt'],
				'ip_masked'   => $row['ip_masked'],
				'context'     => $encoded,
			),
			array(
				'id'      => (int) $existing['id'],
				'blog_id' => (int) $row['blog_id'],
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);

		return false === $updated ? 0 : (int) $existing['id'];
	}

	/**
	 * Insert a row, repairing a missing table once.
	 *
	 * @param array $row Database row.
	 * @return bool
	 */
	private static function insert( array $row ) {
		global $wpdb;

		$ok = $wpdb->insert(
			Installer::table(),
			$row,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false !== $ok ) {
			return true;
		}

		if ( self::$retried || false === stripos( (string) $wpdb->last_error, 'ah_events' ) ) {
			return false;
		}

		self::$retried = true;
		Installer::install();

		$ok = $wpdb->insert(
			Installer::table(),
			$row,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Whether this actor is excluded from routine info events.
	 *
	 * Exclusions never apply to security events or to anything above info.
	 *
	 * @param int    $user_id  Actor ID.
	 * @param string $severity Severity.
	 * @param string $category Category.
	 * @return bool
	 */
	private static function excluded( $user_id, $severity, $category ) {
		if ( 'info' !== $severity || 'security' === $category || $user_id < 1 ) {
			return false;
		}

		$settings = Settings::all();
		$ids      = isset( $settings['excluded_user_ids'] ) && is_array( $settings['excluded_user_ids'] ) ? $settings['excluded_user_ids'] : array();
		if ( in_array( (int) $user_id, array_map( 'intval', $ids ), true ) ) {
			return true;
		}

		$roles = isset( $settings['excluded_roles'] ) && is_array( $settings['excluded_roles'] ) ? $settings['excluded_roles'] : array();
		if ( ! $roles ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return (bool) array_intersect( $roles, (array) $user->roles );
	}
}
