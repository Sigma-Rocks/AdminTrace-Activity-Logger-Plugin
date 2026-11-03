<?php
/**
 * Navigation menu events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

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
	 * Item titles captured before a menu screen save.
	 *
	 * @var array<int,string[]>
	 */
	private $before_items = array();

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
		return __( 'Menus', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Navigation menu creation, saves, item changes, and deletion.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'wp_create_nav_menu', 'on_create', 1 );
		$this->listen( 'wp_update_nav_menu', 'on_update', 1 );
		$this->listen( 'pre_delete_term', 'on_before_delete', 2 );
		$this->listen( 'wp_delete_nav_menu', 'on_delete', 1 );
		add_action( 'load-nav-menus.php', array( $this, 'snapshot_items' ) );
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
			__( 'Created the menu "%s".', 'admintrace-activity-logger' ),
			'notice'
		);
	}

	/**
	 * Menu saved.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public function on_update( $menu_id ) {
		$menu_id = absint( $menu_id );
		$after   = $this->item_titles( $menu_id );
		$count   = count( $after );
		$menu    = wp_get_nav_menu_object( $menu_id );
		$name    = ( $menu instanceof \WP_Term ) ? $menu->name : (string) $menu_id;
		$added   = array();
		$removed = array();
		if ( isset( $this->before_items[ $menu_id ] ) ) {
			$added   = array_values( array_diff( $after, $this->before_items[ $menu_id ] ) );
			$removed = array_values( array_diff( $this->before_items[ $menu_id ], $after ) );
		}
		$summary = sprintf(
			/* translators: 1: menu name, 2: item count. */
			__( 'Updated the menu "%1$s" (%2$d items).', 'admintrace-activity-logger' ),
			$name,
			$count
		);
		if ( $added ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: comma-separated menu item titles. */
				__( 'Added: %s.', 'admintrace-activity-logger' ),
				implode( ', ', array_slice( $added, 0, 6 ) )
			);
		}
		if ( $removed ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: comma-separated menu item titles. */
				__( 'Removed: %s.', 'admintrace-activity-logger' ),
				implode( ', ', array_slice( $removed, 0, 6 ) )
			);
		}
		$this->log(
			array(
				'summary'      => $summary,
				'category'     => 'menu',
				'action'       => 'menu.updated',
				'severity'     => 'info',
				'object_type'  => 'menu',
				'object_id'    => $menu_id,
				'object_title' => $name,
				'context'      => array(
					'added'   => array_slice( $added, 0, 8 ),
					'removed' => array_slice( $removed, 0, 8 ),
				),
			)
		);
	}

	/**
	 * Remember menu item titles before the menu screen writes them.
	 */
	public function snapshot_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only snapshot; core verifies the menu form's nonce before it saves.
		if ( ! $this->enabled() || empty( $_POST['menu'] ) || ! is_scalar( $_POST['menu'] ) ) {
			return;
		}
		$menu_id = absint( wp_unslash( $_POST['menu'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $menu_id ) {
			$this->before_items[ $menu_id ] = $this->item_titles( $menu_id );
		}
	}

	/**
	 * Visible titles on a menu.
	 *
	 * @param int $menu_id Menu ID.
	 * @return string[]
	 */
	private function item_titles( $menu_id ) {
		$items  = wp_get_nav_menu_items( $menu_id );
		$titles = array();
		if ( ! is_array( $items ) ) {
			return $titles;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->title ) ) {
				continue;
			}
			$title = Text::plain( $item->title, 80 );
			if ( '' !== $title ) {
				$titles[] = $title;
			}
		}
		return $titles;
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
					__( 'Deleted the menu "%s".', 'admintrace-activity-logger' ),
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
		$name = ( $menu instanceof \WP_Term ) ? $menu->name : (string) $menu_id;
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
