<?php
/**
 * Capabilities.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability names and grants.
 */
class Capabilities {

	const VIEW   = 'srah_view';
	const MANAGE = 'srah_manage';
	const EXPORT = 'srah_export';
	const DELETE = 'srah_delete';

	/**
	 * Register runtime grants.
	 */
	public static function register() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
	}

	/**
	 * Grant capabilities to administrators, and limited view access when enabled.
	 *
	 * @param array    $allcaps All capabilities for the user.
	 * @param string[] $caps    Requested capabilities.
	 * @param array    $args    Extra arguments.
	 * @param mixed    $user    User being checked. Core passes a WP_User; other callers may not.
	 * @return array
	 */
	public static function grant( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );

		$roles   = ( $user instanceof \WP_User ) ? (array) $user->roles : array();
		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;

		// On a single site is_super_admin() calls has_cap(), which would run this filter again.
		if ( in_array( 'administrator', $roles, true ) || ( is_multisite() && is_super_admin( $user_id ) ) ) {
			$allcaps[ self::VIEW ]   = true;
			$allcaps[ self::MANAGE ] = true;
			$allcaps[ self::EXPORT ] = true;
			$allcaps[ self::DELETE ] = true;
			return $allcaps;
		}

		$settings = Settings::all();
		if ( ! empty( $settings['editors_can_view_content'] ) && ( in_array( 'editor', $roles, true ) || in_array( 'shop_manager', $roles, true ) ) ) {
			$allcaps[ self::VIEW ] = true;
		}

		return $allcaps;
	}

	/**
	 * Persist administrator capabilities on each site.
	 */
	public static function grant_roles() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( array( self::VIEW, self::MANAGE, self::EXPORT, self::DELETE ) as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove stored capabilities.
	 */
	public static function remove_roles() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( array( self::VIEW, self::MANAGE, self::EXPORT, self::DELETE ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
