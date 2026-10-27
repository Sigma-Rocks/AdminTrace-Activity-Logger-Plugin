<?php
/**
 * Shared labels.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categories, severities, and initiator labels.
 */
class Vocabulary {

	/**
	 * Category labels.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			'security'    => __( 'Security', 'admintrace-activity-logger' ),
			'user'        => __( 'Users', 'admintrace-activity-logger' ),
			'content'     => __( 'Content', 'admintrace-activity-logger' ),
			'media'       => __( 'Media', 'admintrace-activity-logger' ),
			'comment'     => __( 'Comments', 'admintrace-activity-logger' ),
			'plugin'      => __( 'Plugins', 'admintrace-activity-logger' ),
			'theme'       => __( 'Themes', 'admintrace-activity-logger' ),
			'core'        => __( 'WordPress', 'admintrace-activity-logger' ),
			'taxonomy'    => __( 'Taxonomy', 'admintrace-activity-logger' ),
			'settings'    => __( 'Settings', 'admintrace-activity-logger' ),
			'menu'        => __( 'Menus', 'admintrace-activity-logger' ),
			'widget'      => __( 'Widgets', 'admintrace-activity-logger' ),
			'privacy'     => __( 'Privacy', 'admintrace-activity-logger' ),
			'commerce'    => __( 'Commerce', 'admintrace-activity-logger' ),
			'integration' => __( 'Integrations', 'admintrace-activity-logger' ),
			'journal'     => __( 'Journal', 'admintrace-activity-logger' ),
			'system'      => __( 'System', 'admintrace-activity-logger' ),
		);
	}

	/**
	 * Severity labels.
	 *
	 * @return array<string,string>
	 */
	public static function severities() {
		return array(
			'info'     => __( 'Info', 'admintrace-activity-logger' ),
			'notice'   => __( 'Notice', 'admintrace-activity-logger' ),
			'warning'  => __( 'Warning', 'admintrace-activity-logger' ),
			'critical' => __( 'Critical', 'admintrace-activity-logger' ),
		);
	}

	/**
	 * Initiator labels.
	 *
	 * @return array<string,string>
	 */
	public static function initiators() {
		return array(
			'user'      => __( 'Person', 'admintrace-activity-logger' ),
			'anonymous' => __( 'Visitor', 'admintrace-activity-logger' ),
			'cron'      => __( 'Scheduled task', 'admintrace-activity-logger' ),
			'cli'       => __( 'WP-CLI', 'admintrace-activity-logger' ),
			'xmlrpc'    => __( 'XML-RPC', 'admintrace-activity-logger' ),
			'rest'      => __( 'REST', 'admintrace-activity-logger' ),
			'system'    => __( 'AdminTrace', 'admintrace-activity-logger' ),
		);
	}

	/**
	 * Categories a non-manager may see.
	 *
	 * @return string[]
	 */
	public static function viewer_categories() {
		$categories = array( 'content', 'media', 'comment', 'taxonomy' );
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && in_array( 'shop_manager', (array) $user->roles, true ) ) {
				$categories[] = 'commerce';
			}
		}
		return $categories;
	}

	/**
	 * Label for a category key.
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function category_label( $key ) {
		$categories = self::categories();
		if ( isset( $categories[ $key ] ) ) {
			return $categories[ $key ];
		}
		$label = str_replace( array( '-', '_' ), ' ', (string) $key );
		return ucwords( $label );
	}

	/**
	 * Label for a severity key.
	 *
	 * @param string $key Severity key.
	 * @return string
	 */
	public static function severity_label( $key ) {
		$severities = self::severities();
		return isset( $severities[ $key ] ) ? $severities[ $key ] : self::severity_label( 'info' );
	}

	/**
	 * Numeric rank used by webhook thresholds.
	 *
	 * @param string $severity Severity key.
	 * @return int
	 */
	public static function severity_rank( $severity ) {
		$ranks = array(
			'info'     => 1,
			'notice'   => 2,
			'warning'  => 3,
			'critical' => 4,
		);
		return isset( $ranks[ $severity ] ) ? $ranks[ $severity ] : 1;
	}
}
