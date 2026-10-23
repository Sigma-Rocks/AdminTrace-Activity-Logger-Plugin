<?php
/**
 * Navigation menu events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records menu creation, updates, and deletion.
 */
class Menu_Logger extends Logger {

	/**
	 * Name captured before a menu is deleted.
	 *
	 * @var string
	 */
	private $pending_name = '';

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'menu';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Menus', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Navigation menu creation, saves, and deletion.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'wp_create_nav_menu', 'on_create', 1 );
		$this->listen( 'wp_update_nav_menu', 'on_update', 1 );
		$this->listen( 'pre_delete_term', 'on_before_delete', 2 );
		$this->listen( 'wp_delete_nav_menu', 'on_delete', 1 );
	}

	/**
	 * Menu created.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function on_create( $menu_id ) {
		$this->store(
			$menu_id,
			'menu.created',
			/* translators: %s: menu name. */
			__( 'Created the menu "%s".', 'advance-history' ),
			'notice'
		);
	}

	/**
	 * Menu saved.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function on_update( $menu_id ) {
		$items = wp_get_nav_menu_items( $menu_id );
		$count = is_array( $items ) ? count( $items ) : 0;
		$menu  = wp_get_nav_menu_object( $menu_id );
		$name  = ( $menu && ! is_wp_error( $menu ) ) ? $menu->name : (string) $menu_id;
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: menu name, 2: item count. */
					__( 'Updated the menu "%1$s" (%2$d items).', 'advance-history' ),
					$name,
					$count
				),
				'category'     => 'menu',
				'action'       => 'menu.updated',
				'severity'     => 'info',
				'object_type'  => 'menu',
				'object_id'    => absint( $menu_id ),
				'object_title' => $name,
			)
		);
	}

	/**
	 * Remember a menu name before deletion.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_before_delete( $term_id, $taxonomy ) {
		if ( 'nav_menu' !== $taxonomy ) {
			return;
		}
		$term = get_term( $term_id, 'nav_menu' );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->pending_name = $term->name;
		}
	}

	/**
	 * Menu deleted.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function on_delete( $menu_id ) {
		$name = '' !== $this->pending_name ? $this->pending_name : (string) $menu_id;
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: menu name. */
					__( 'Deleted the menu "%s".', 'advance-history' ),
					$name
				),
				'category'     => 'menu',
				'action'       => 'menu.deleted',
				'severity'     => 'warning',
				'object_type'  => 'menu',
				'object_id'    => absint( $menu_id ),
				'object_title' => $name,
			)
		);
	}

	/**
	 * Store a simple menu event.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $action  Action.
	 * @param string $pattern Summary pattern.
	 * @param string $severity Severity.
	 */
	private function store( $menu_id, $action, $pattern, $severity ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		$name = ( $menu && ! is_wp_error( $menu ) ) ? $menu->name : (string) $menu_id;
		$this->log(
			array(
				'summary'      => sprintf( $pattern, $name ),
				'category'     => 'menu',
				'action'       => $action,
				'severity'     => $severity,
				'object_type'  => 'menu',
				'object_id'    => absint( $menu_id ),
				'object_title' => $name,
			)
		);
	}
}
