<?php
/**
 * Multisite network events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records site and membership changes on a multisite network.
 */
class Network_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'network';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Multisite', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Sites created, deleted, or archived, and users added to or removed from a site. Single sites never fire these events.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks on multisite only.
	 */
	public function register() {
		if ( ! is_multisite() ) {
			return;
		}
		$this->listen( 'wp_insert_site', 'on_created', 1 );
		$this->listen( 'wp_delete_site', 'on_deleted', 1 );
		$this->listen( 'wp_update_site', 'on_updated', 2 );
		$this->listen( 'add_user_to_blog', 'on_user_added', 3 );
		$this->listen( 'remove_user_from_blog', 'on_user_removed', 2 );
	}

	/**
	 * Site created.
	 *
	 * @param \WP_Site $site New site.
	 */
	public function on_created( $site ) {
		if ( ! $site instanceof \WP_Site ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: site address. */
					__( 'Created the site %s.', 'admintrace-activity-logger' ),
					$this->address( $site )
				),
				'category'     => 'network',
				'action'       => 'network.site_created',
				'severity'     => 'notice',
				'object_type'  => 'site',
				'object_id'    => (int) $site->blog_id,
				'object_title' => $this->address( $site ),
			)
		);
	}

	/**
	 * Site deleted.
	 *
	 * @param \WP_Site $site Deleted site.
	 */
	public function on_deleted( $site ) {
		if ( ! $site instanceof \WP_Site ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: site address. */
					__( 'Deleted the site %s.', 'admintrace-activity-logger' ),
					$this->address( $site )
				),
				'category'     => 'network',
				'action'       => 'network.site_deleted',
				'severity'     => 'warning',
				'object_type'  => 'site',
				'object_id'    => (int) $site->blog_id,
				'object_title' => $this->address( $site ),
			)
		);
	}

	/**
	 * Site flags changed.
	 *
	 * @param \WP_Site $new New site.
	 * @param \WP_Site $old Previous site.
	 */
	public function on_updated( $new, $old ) {
		if ( ! $new instanceof \WP_Site || ! $old instanceof \WP_Site ) {
			return;
		}
		$changes = array();
		foreach ( array( 'archived', 'deleted', 'spam' ) as $flag ) {
			if ( (string) $new->$flag === (string) $old->$flag ) {
				continue;
			}
			$changes[] = $flag . ':' . (string) $old->$flag . '>' . (string) $new->$flag;
		}
		if ( ! $changes ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: site address, 2: changed flags. */
					__( 'Updated the site %1$s (%2$s).', 'admintrace-activity-logger' ),
					$this->address( $new ),
					implode( ', ', $changes )
				),
				'category'     => 'network',
				'action'       => 'network.site_updated',
				'severity'     => 'warning',
				'object_type'  => 'site',
				'object_id'    => (int) $new->blog_id,
				'object_title' => $this->address( $new ),
				'context'      => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * User added to a site.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param int    $blog_id Site ID.
	 */
	public function on_user_added( $user_id, $role, $blog_id ) {
		$user = get_userdata( $user_id );
		$login = $user ? $user->user_login : (string) absint( $user_id );
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: username, 2: role, 3: site address. */
					__( 'Added "%1$s" to %3$s as %2$s.', 'admintrace-activity-logger' ),
					Text::plain( $login, 60 ),
					Text::plain( $role, 40 ),
					$this->site_address( $blog_id )
				),
				'category'     => 'network',
				'action'       => 'network.user_added',
				'severity'     => 'notice',
				'object_type'  => 'user',
				'object_id'    => absint( $user_id ),
				'object_title' => Text::plain( $login, 60 ),
			)
		);
	}

	/**
	 * User removed from a site.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Site ID.
	 */
	public function on_user_removed( $user_id, $blog_id ) {
		$user = get_userdata( $user_id );
		$login = $user ? $user->user_login : (string) absint( $user_id );
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: username, 2: site address. */
					__( 'Removed "%1$s" from %2$s.', 'admintrace-activity-logger' ),
					Text::plain( $login, 60 ),
					$this->site_address( $blog_id )
				),
				'category'     => 'network',
				'action'       => 'network.user_removed',
				'severity'     => 'warning',
				'object_type'  => 'user',
				'object_id'    => absint( $user_id ),
				'object_title' => Text::plain( $login, 60 ),
			)
		);
	}

	/**
	 * Public address of a site object.
	 *
	 * @param \WP_Site $site Site.
	 * @return string
	 */
	private function address( $site ) {
		$path = isset( $site->path ) ? (string) $site->path : '/';
		$domain = isset( $site->domain ) ? (string) $site->domain : '';
		return Text::plain( $domain . $path, 180 );
	}

	/**
	 * Public address of a site ID.
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	private function site_address( $blog_id ) {
		$site = function_exists( 'get_site' ) ? get_site( absint( $blog_id ) ) : null;
		if ( $site instanceof \WP_Site ) {
			return $this->address( $site );
		}
		return (string) absint( $blog_id );
	}
}
