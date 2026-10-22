<?php
/**
 * Settings.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes plugin settings.
 */
class Settings {

	const OPTION = 'advance_history_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'retention_days'             => 90,
			'per_page'                   => 25,
			'ip_mode'                    => 'masked',
			'trust_proxy'                => 0,
			'log_failed_logins'          => 1,
			'editors_can_view_content'   => 0,
			'excluded_roles'             => array(),
			'excluded_user_ids'          => array(),
			'disabled_loggers'           => array(),
			'dashboard_widget'           => 1,
			'admin_bar'                  => 1,
			'email_digest'               => 'off',
			'digest_email'               => '',
			'alert_failed_logins'        => 1,
			'alert_threshold'            => 5,
			'alert_email'                => '',
			'webhook_url'                => '',
			'webhook_min_severity'       => 'off',
			'feed_enabled'               => 0,
			'feed_token'                 => '',
			'delete_data_on_uninstall'   => 1,
			'menu_location'              => 'toplevel',
			'default_view'               => 'timeline',
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		self::$cache = array_merge( self::defaults(), $saved );
		return self::$cache;
	}

	/**
	 * Clear the settings cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whether a logger is enabled.
	 *
	 * @param string $id Logger ID.
	 * @return bool
	 */
	public static function logger_enabled( $id ) {
		$disabled = self::all()['disabled_loggers'];
		if ( ! is_array( $disabled ) ) {
			return true;
		}
		return ! in_array( $id, $disabled, true );
	}

	/**
	 * Sanitize a settings payload.
	 *
	 * @param mixed $input   Submitted values.
	 * @param array $current Current settings, used to preserve secrets.
	 * @return array
	 */
	public static function sanitize( $input, $current = array() ) {
		$defaults = self::defaults();
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $input ) ) {
			return array_merge( $defaults, $current );
		}

		$clean = $defaults;

		$days = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : $defaults['retention_days'];
		$clean['retention_days'] = min( 3650, max( 0, $days ) );

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 25;
		$clean['per_page'] = in_array( $per_page, array( 10, 25, 50, 100 ), true ) ? $per_page : 25;

		$clean['ip_mode']     = ( isset( $input['ip_mode'] ) && 'off' === $input['ip_mode'] ) ? 'off' : 'masked';
		$clean['trust_proxy'] = empty( $input['trust_proxy'] ) ? 0 : 1;
		$clean['log_failed_logins'] = empty( $input['log_failed_logins'] ) ? 0 : 1;
		$clean['editors_can_view_content'] = empty( $input['editors_can_view_content'] ) ? 0 : 1;
		$clean['dashboard_widget'] = empty( $input['dashboard_widget'] ) ? 0 : 1;
		$clean['admin_bar'] = empty( $input['admin_bar'] ) ? 0 : 1;
		$clean['alert_failed_logins'] = empty( $input['alert_failed_logins'] ) ? 0 : 1;
		$clean['delete_data_on_uninstall'] = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;
		$clean['feed_enabled'] = empty( $input['feed_enabled'] ) ? 0 : 1;

		$threshold = isset( $input['alert_threshold'] ) ? (int) $input['alert_threshold'] : 5;
		$clean['alert_threshold'] = min( 50, max( 3, $threshold ) );

		$digest = isset( $input['email_digest'] ) ? (string) $input['email_digest'] : 'off';
		$clean['email_digest'] = in_array( $digest, array( 'off', 'daily', 'weekly' ), true ) ? $digest : 'off';

		$location = isset( $input['menu_location'] ) ? (string) $input['menu_location'] : 'toplevel';
		$clean['menu_location'] = in_array( $location, array( 'toplevel', 'dashboard', 'tools' ), true ) ? $location : 'toplevel';

		$view = isset( $input['default_view'] ) ? (string) $input['default_view'] : 'timeline';
		$clean['default_view'] = ( 'table' === $view ) ? 'table' : 'timeline';

		$severity = isset( $input['webhook_min_severity'] ) ? (string) $input['webhook_min_severity'] : 'off';
		$clean['webhook_min_severity'] = in_array( $severity, array( 'off', 'info', 'notice', 'warning', 'critical' ), true ) ? $severity : 'off';

		$clean['digest_email'] = self::email_or_blank( isset( $input['digest_email'] ) ? $input['digest_email'] : '' );
		$clean['alert_email']  = self::email_or_blank( isset( $input['alert_email'] ) ? $input['alert_email'] : '' );

		$webhook = isset( $input['webhook_url'] ) ? (string) $input['webhook_url'] : '';
		if ( function_exists( 'esc_url_raw' ) ) {
			$webhook = esc_url_raw( $webhook );
		}
		$clean['webhook_url'] = Url::public_https( $webhook );

		$clean['excluded_roles']    = self::key_list( isset( $input['excluded_roles'] ) ? $input['excluded_roles'] : array(), 20 );
		$clean['excluded_user_ids'] = self::id_list( isset( $input['excluded_user_ids'] ) ? $input['excluded_user_ids'] : array() );
		$clean['disabled_loggers']  = self::key_list( isset( $input['disabled_loggers'] ) ? $input['disabled_loggers'] : array(), 40 );

		$token = isset( $current['feed_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $current['feed_token'] ) : '';
		if ( ! empty( $input['regenerate_feed_token'] ) || ( $clean['feed_enabled'] && '' === $token ) ) {
			$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false, false ) : bin2hex( random_bytes( 16 ) );
		}
		$clean['feed_token'] = $token;

		return $clean;
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array $clean Sanitized settings.
	 */
	public static function update( array $clean ) {
		update_option( self::OPTION, $clean, true );
		self::flush();
	}

	/**
	 * Sanitize an email or return empty.
	 *
	 * @param mixed $email Raw email.
	 * @return string
	 */
	private static function email_or_blank( $email ) {
		$email = sanitize_email( (string) $email );
		if ( function_exists( 'is_email' ) && ! is_email( $email ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Sanitize a list of keys.
	 *
	 * @param mixed $values Raw values or a comma-separated string.
	 * @param int   $max    Maximum items.
	 * @return string[]
	 */
	private static function key_list( $values, $max ) {
		if ( is_string( $values ) ) {
			$values = explode( ',', $values );
		}
		if ( ! is_array( $values ) ) {
			return array();
		}
		$list = array();
		foreach ( $values as $value ) {
			$key = sanitize_key( (string) $value );
			if ( '' === $key ) {
				continue;
			}
			$list[] = $key;
			if ( count( $list ) >= $max ) {
				break;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Sanitize a list of user IDs.
	 *
	 * @param mixed $values Raw values or a comma-separated string.
	 * @return int[]
	 */
	private static function id_list( $values ) {
		if ( is_string( $values ) ) {
			$values = explode( ',', $values );
		}
		if ( ! is_array( $values ) ) {
			return array();
		}
		$ids = array();
		foreach ( $values as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= 100 ) {
				break;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
