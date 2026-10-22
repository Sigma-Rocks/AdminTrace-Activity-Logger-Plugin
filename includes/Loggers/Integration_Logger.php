<?php
/**
 * Optional integration events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for public hooks from other plugins.
 *
 * Missing plugins simply never fire these hooks.
 */
class Integration_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'integration';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Other plugins', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Jetpack modules, user switching, ACF field groups, duplicated posts, Beaver Builder saves, and Redirection changes.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'jetpack_activate_module', 'on_jetpack_on', 2 );
		$this->listen( 'jetpack_deactivate_module', 'on_jetpack_off', 1 );
		$this->listen( 'switch_to_user', 'on_switch_to', 2 );
		$this->listen( 'switch_back_user', 'on_switch_back', 2 );
		$this->listen( 'switch_off_user', 'on_switch_off', 1 );
		$this->listen( 'acf/update_field_group', 'on_acf_save', 1 );
		$this->listen( 'acf/delete_field_group', 'on_acf_delete', 1 );
		$this->listen( 'duplicate_post_post_duplicated', 'on_duplicate', 2 );
		$this->listen( 'fl_builder_after_save_layout', 'on_beaver', 2 );
		$this->listen( 'redirection_redirect_updated', 'on_redirect', 2 );
		$this->listen( 'redirection_redirect_deleted', 'on_redirect_deleted', 1 );
	}

	/**
	 * Jetpack module activated.
	 *
	 * @param string $module Module slug.
	 * @param bool   $success Whether it succeeded.
	 */
	public function on_jetpack_on( $module, $success = true ) {
		if ( ! $success ) {
			return;
		}
		$this->module( $module, true );
	}

	/**
	 * Jetpack module deactivated.
	 *
	 * @param string $module Module slug.
	 */
	public function on_jetpack_off( $module ) {
		$this->module( $module, false );
	}

	/**
	 * Switched into another account.
	 *
	 * @param int $user_id New user ID.
	 * @param int $old_user_id Previous user ID.
	 */
	public function on_switch_to( $user_id, $old_user_id ) {
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: 1: new user ID, 2: previous user ID. */
					__( 'Switched into user %1$d from user %2$d.', 'advance-history' ),
					absint( $user_id ),
					absint( $old_user_id )
				),
				'category'    => 'user',
				'action'      => 'integration.user_switched',
				'severity'    => 'warning',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
			)
		);
	}

	/**
	 * Switched back.
	 *
	 * @param int $user_id User ID.
	 * @param int $old_user_id Previous user ID.
	 */
	public function on_switch_back( $user_id, $old_user_id ) {
		unset( $old_user_id );
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %d: user ID. */
					__( 'Switched back to user %d.', 'advance-history' ),
					absint( $user_id )
				),
				'category' => 'user',
				'action'   => 'integration.user_switched_back',
				'severity' => 'notice',
			)
		);
	}

	/**
	 * Logged out via user switching.
	 *
	 * @param int $old_user_id Previous user ID.
	 */
	public function on_switch_off( $old_user_id ) {
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %d: user ID. */
					__( 'User %d switched off.', 'advance-history' ),
					absint( $old_user_id )
				),
				'category' => 'user',
				'action'   => 'integration.user_switched_off',
				'severity' => 'notice',
			)
		);
	}

	/**
	 * ACF field group saved.
	 *
	 * @param mixed $group Field group.
	 */
	public function on_acf_save( $group ) {
		$title = is_array( $group ) && isset( $group['title'] ) ? $group['title'] : '';
		$key   = is_array( $group ) && isset( $group['key'] ) ? $group['key'] : '';
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: field group title. */
					__( 'Saved the field group "%s".', 'advance-history' ),
					Text::plain( $title, 120 )
				),
				'category'     => 'integration',
				'action'       => 'integration.acf_saved',
				'severity'     => 'info',
				'object_title' => Text::plain( $title, 120 ),
				'context'      => array( 'key' => Text::plain( $key, 40 ) ),
			)
		);
	}

	/**
	 * ACF field group deleted.
	 *
	 * @param mixed $group Field group.
	 */
	public function on_acf_delete( $group ) {
		$title = is_array( $group ) && isset( $group['title'] ) ? $group['title'] : '';
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %s: field group title. */
					__( 'Deleted the field group "%s".', 'advance-history' ),
					Text::plain( $title, 120 )
				),
				'category' => 'integration',
				'action'   => 'integration.acf_deleted',
				'severity' => 'warning',
			)
		);
	}

	/**
	 * Post duplicated.
	 *
	 * @param int $new_id New post ID.
	 * @param mixed $post Original post.
	 */
	public function on_duplicate( $new_id, $post ) {
		$title = ( $post instanceof \WP_Post ) ? $post->post_title : '';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: original title. */
					__( 'Duplicated "%s".', 'advance-history' ),
					Text::plain( $title, 120 )
				),
				'category'    => 'content',
				'action'      => 'integration.duplicated',
				'severity'    => 'info',
				'object_type' => 'content',
				'object_id'   => absint( $new_id ),
			)
		);
	}

	/**
	 * Beaver Builder layout saved.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $publish Whether it was published.
	 */
	public function on_beaver( $post_id, $publish = false ) {
		$post = get_post( $post_id );
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: title. */
					__( 'Saved a Beaver Builder layout for "%s".', 'advance-history' ),
					$post ? $post->post_title : (string) $post_id
				),
				'category'    => 'content',
				'action'      => 'integration.builder_saved',
				'severity'    => 'info',
				'object_type' => 'content',
				'object_id'   => absint( $post_id ),
				'context'     => array( 'published' => (bool) $publish ),
			)
		);
	}

	/**
	 * Redirect saved.
	 *
	 * @param int   $id Redirect ID.
	 * @param mixed $redirect Redirect object.
	 */
	public function on_redirect( $id, $redirect = null ) {
		$url = '';
		if ( is_object( $redirect ) && isset( $redirect->url ) ) {
			$url = Text::plain( $redirect->url, 180 );
		}
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %d: redirect ID. */
					__( 'Saved redirect %d.', 'advance-history' ),
					absint( $id )
				),
				'category'    => 'integration',
				'action'      => 'integration.redirect_saved',
				'severity'    => 'notice',
				'object_type' => 'redirect',
				'object_id'   => absint( $id ),
				'context'     => array( 'url' => $url ),
			)
		);
	}

	/**
	 * Redirect deleted.
	 *
	 * @param mixed $redirect Redirect.
	 */
	public function on_redirect_deleted( $redirect ) {
		$id = 0;
		if ( is_object( $redirect ) && isset( $redirect->id ) ) {
			$id = absint( $redirect->id );
		} elseif ( is_numeric( $redirect ) ) {
			$id = absint( $redirect );
		}
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %d: redirect ID. */
					__( 'Deleted redirect %d.', 'advance-history' ),
					$id
				),
				'category' => 'integration',
				'action'   => 'integration.redirect_deleted',
				'severity' => 'warning',
			)
		);
	}

	/**
	 * Log a Jetpack module change.
	 *
	 * @param string $module Module slug.
	 * @param bool   $enabled Whether it was enabled.
	 */
	private function module( $module, $enabled ) {
		$module = sanitize_key( (string) $module );
		$this->log(
			array(
				'summary'  => $enabled
					? sprintf(
						/* translators: %s: module slug. */
						__( 'Activated the Jetpack module "%s".', 'advance-history' ),
						$module
					)
					: sprintf(
						/* translators: %s: module slug. */
						__( 'Deactivated the Jetpack module "%s".', 'advance-history' ),
						$module
					),
				'category' => 'integration',
				'action'   => $enabled ? 'integration.jetpack_on' : 'integration.jetpack_off',
				'severity' => 'notice',
			)
		);
	}
}
