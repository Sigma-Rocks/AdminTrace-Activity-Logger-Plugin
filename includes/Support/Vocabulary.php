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
			'security'    => __( 'Security', 'advance-history' ),
			'user'        => __( 'Users', 'advance-history' ),
			'content'     => __( 'Content', 'advance-history' ),
			'media'       => __( 'Media', 'advance-history' ),
			'comment'     => __( 'Comments', 'advance-history' ),
			'plugin'      => __( 'Plugins', 'advance-history' ),
			'theme'       => __( 'Themes', 'advance-history' ),
			'core'        => __( 'WordPress', 'advance-history' ),
			'taxonomy'    => __( 'Taxonomy', 'advance-history' ),
			'settings'    => __( 'Settings', 'advance-history' ),
			'menu'        => __( 'Menus', 'advance-history' ),
			'widget'      => __( 'Widgets', 'advance-history' ),
			'privacy'     => __( 'Privacy', 'advance-history' ),
			'commerce'    => __( 'Commerce', 'advance-history' ),
			'integration' => __( 'Integrations', 'advance-history' ),
			'journal'     => __( 'Journal', 'advance-history' ),
			'system'      => __( 'System', 'advance-history' ),
		);
	}

	/**
	 * Severity labels.
	 *
	 * @return array<string,string>
	 */
	public static function severities() {
		return array(
			'info'     => __( 'Info', 'advance-history' ),
			'notice'   => __( 'Notice', 'advance-history' ),
			'warning'  => __( 'Warning', 'advance-history' ),
			'critical' => __( 'Critical', 'advance-history' ),
		);
	}

	/**
	 * Initiator labels.
	 *
	 * @return array<string,string>
	 */
	public static function initiators() {
		return array(
			'user'      => __( 'Person', 'advance-history' ),
			'anonymous' => __( 'Visitor', 'advance-history' ),
			'cron'      => __( 'Scheduled task', 'advance-history' ),
			'cli'       => __( 'WP-CLI', 'advance-history' ),
			'xmlrpc'    => __( 'XML-RPC', 'advance-history' ),
			'rest'      => __( 'REST', 'advance-history' ),
			'system'    => __( 'Advance History', 'advance-history' ),
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
