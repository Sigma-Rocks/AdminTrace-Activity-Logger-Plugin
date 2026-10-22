<?php
/**
 * User and authentication events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records account, login, and role changes.
 */
class User_Logger extends Logger {

	/**
	 * User IDs that completed a second factor during this request.
	 *
	 * @var array<int,bool>
	 */
	private $two_factor = array();

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'user';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Users and logins', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Logins, logouts, failed logins, profile changes, roles, and application passwords.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'wp_login', 'on_login', 2 );
		$this->listen( 'wp_logout', 'on_logout', 1 );
		$this->listen( 'wp_login_failed', 'on_failed', 2 );
		$this->listen( 'user_register', 'on_register', 1 );
		$this->listen( 'profile_update', 'on_profile', 2 );
		$this->listen( 'delete_user', 'on_delete', 2 );
		$this->listen( 'set_user_role', 'on_set_role', 3 );
		$this->listen( 'add_user_role', 'on_add_role', 2 );
		$this->listen( 'remove_user_role', 'on_remove_role', 2 );
		$this->listen( 'retrieve_password', 'on_reset_request', 1 );
		$this->listen( 'after_password_reset', 'on_password_reset', 1 );
		$this->listen( 'wp_create_application_password', 'on_app_password_created', 2 );
		$this->listen( 'wp_delete_application_password', 'on_app_password_deleted', 2 );
		$this->listen( 'application_password_failed_authentication', 'on_app_password_failed', 1 );
		$this->listen( 'application_password_did_authenticate', 'on_app_password_login', 2 );
		$this->listen( 'two_factor_user_authenticated', 'on_two_factor', 1 );
	}

	/**
	 * Successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User.
	 */
	public function on_login( $user_login, $user ) {
		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		$context = array();
		if ( $user_id && ! empty( $this->two_factor[ $user_id ] ) ) {
			$context['two_factor'] = true;
		}
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Logged in as "%s".', 'advance-history' ),
					Text::plain( $user_login, 60 )
				),
				'category'    => 'security',
				'action'      => 'user.login',
				'severity'    => 'notice',
				'user_id'     => $user_id,
				'user_login'  => $user_login,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'context'     => $context,
			)
		);
	}

	/**
	 * Logout.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_logout( $user_id ) {
		$this->log(
			array(
				'summary'     => __( 'Logged out.', 'advance-history' ),
				'category'    => 'user',
				'action'      => 'user.logout',
				'severity'    => 'info',
				'user_id'     => absint( $user_id ),
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
			)
		);
	}

	/**
	 * Failed password login.
	 *
	 * @param string    $username Attempted username.
	 * @param \WP_Error $error    Authentication error.
	 */
	public function on_failed( $username, $error ) {
		if ( empty( Settings::all()['log_failed_logins'] ) ) {
			return;
		}

		$username = Text::plain( $username, 60 );
		$code     = 'unknown';
		if ( $error instanceof \WP_Error ) {
			$codes = $error->get_error_codes();
			if ( in_array( 'incorrect_password', $codes, true ) ) {
				$code = 'bad_password';
			} elseif ( in_array( 'invalid_username', $codes, true ) || in_array( 'invalid_email', $codes, true ) ) {
				$code = 'unknown_user';
			}
		}

		if ( 'bad_password' === $code ) {
			$summary = sprintf(
				/* translators: %s: username. */
				__( 'Failed login for "%s" because the password was incorrect.', 'advance-history' ),
				$username
			);
		} elseif ( 'unknown_user' === $code ) {
			$summary = sprintf(
				/* translators: %s: username. */
				__( 'Failed login for an unknown username "%s".', 'advance-history' ),
				$username
			);
		} else {
			$summary = __( 'Failed login attempt.', 'advance-history' );
		}

		$hit  = $this->count_failure();
		$skip = $this->throttled( $username );
		if ( ! $skip ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? Text::plain( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 180 ) : '';
			$this->log(
				array(
					'summary'    => $summary,
					'category'   => 'security',
					'action'     => 'user.login_failed',
					'severity'   => 'warning',
					'user_id'    => 0,
					'user_login' => $username,
					'initiator'  => 'anonymous',
					'context'    => array(
						'reason'     => $code,
						'user_agent' => $agent,
					),
				)
			);
		}

		if ( $hit > 0 ) {
			$this->log(
				array(
					'summary'   => sprintf(
						/* translators: %d: number of failed logins. */
						__( 'Failed login threshold reached: %d attempts in 15 minutes.', 'advance-history' ),
						$hit
					),
					'category'  => 'security',
					'action'    => 'security.login_threshold',
					'severity'  => 'critical',
					'user_id'   => 0,
					'initiator' => 'system',
				)
			);
		}
	}

	/**
	 * New account.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_register( $user_id ) {
		$user = get_userdata( $user_id );
		$role = ( $user && ! empty( $user->roles ) ) ? implode( ', ', $user->roles ) : '';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Created the account "%s".', 'advance-history' ),
					$user ? $user->user_login : (string) $user_id
				),
				'category'    => 'user',
				'action'      => 'user.created',
				'severity'    => ( false !== strpos( $role, 'administrator' ) ) ? 'warning' : 'notice',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
				'context'     => array( 'role' => $role ),
			)
		);
	}

	/**
	 * Profile field changes. Passwords are recorded only as "updated".
	 *
	 * @param int      $user_id User ID.
	 * @param \WP_User $old     Previous user.
	 */
	public function on_profile( $user_id, $old ) {
		$new = get_userdata( $user_id );
		if ( ! $new instanceof \WP_User || ! $old instanceof \WP_User ) {
			return;
		}

		$fields = array(
			'user_email'    => __( 'Email', 'advance-history' ),
			'display_name'  => __( 'Display name', 'advance-history' ),
			'user_url'      => __( 'Website', 'advance-history' ),
			'user_nicename' => __( 'Nicename', 'advance-history' ),
		);
		$changes = array();
		foreach ( $fields as $key => $label ) {
			$from = (string) $old->$key;
			$to   = (string) $new->$key;
			if ( $from !== $to ) {
				$changes[] = array(
					'field' => $label,
					'from'  => Text::plain( $from, 180 ),
					'to'    => Text::plain( $to, 180 ),
				);
			}
		}

		if ( (string) $old->user_pass !== (string) $new->user_pass ) {
			$changes[] = array(
				'field' => __( 'Password', 'advance-history' ),
				'from'  => '••••',
				'to'    => __( 'Updated', 'advance-history' ),
			);
		}

		if ( ! $changes ) {
			return;
		}

		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Updated the profile for "%s".', 'advance-history' ),
					$new->user_login
				),
				'category'    => 'user',
				'action'      => 'user.updated',
				'severity'    => 'notice',
				'object_type' => 'user',
				'object_id'   => (int) $user_id,
				'context'     => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * Account deletion.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $reassign Reassign target.
	 */
	public function on_delete( $user_id, $reassign ) {
		$user = get_userdata( $user_id );
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Deleted the account "%s".', 'advance-history' ),
					$user ? $user->user_login : (string) $user_id
				),
				'category'    => 'user',
				'action'      => 'user.deleted',
				'severity'    => 'warning',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
				'object_title'=> $user ? $user->user_login : '',
				'context'     => array(
					'reassign' => absint( $reassign ),
				),
			)
		);
	}

	/**
	 * Primary role change.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 */
	public function on_set_role( $user_id, $role, $old_roles ) {
		$this->log_role_change( $user_id, array( (string) $role ), (array) $old_roles );
	}

	/**
	 * Added role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public function on_add_role( $user_id, $role ) {
		$this->log_role_change( $user_id, array( (string) $role ), array() );
	}

	/**
	 * Removed role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public function on_remove_role( $user_id, $role ) {
		$this->log_role_change( $user_id, array(), array( (string) $role ) );
	}

	/**
	 * Password reset requested. The reset key is never stored.
	 *
	 * @param string $user_login Username.
	 */
	public function on_reset_request( $user_login ) {
		$this->log(
			array(
				'summary'    => sprintf(
					/* translators: %s: username. */
					__( 'Password reset requested for "%s".', 'advance-history' ),
					Text::plain( $user_login, 60 )
				),
				'category'   => 'security',
				'action'     => 'user.password_reset_requested',
				'severity'   => 'notice',
				'user_login' => $user_login,
			)
		);
	}

	/**
	 * Password reset completed. The new password is not accepted by this callback.
	 *
	 * @param \WP_User $user User.
	 */
	public function on_password_reset( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Password reset completed for "%s".', 'advance-history' ),
					$user->user_login
				),
				'category'    => 'security',
				'action'      => 'user.password_reset',
				'severity'    => 'warning',
				'user_id'     => (int) $user->ID,
				'object_type' => 'user',
				'object_id'   => (int) $user->ID,
			)
		);
	}

	/**
	 * Application password created. The password itself is not received.
	 *
	 * @param int   $user_id User ID.
	 * @param array $item    Password metadata.
	 */
	public function on_app_password_created( $user_id, $item ) {
		$name = is_array( $item ) && isset( $item['name'] ) ? $item['name'] : '';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: application password name. */
					__( 'Created an application password named "%s".', 'advance-history' ),
					Text::plain( $name, 80 )
				),
				'category'    => 'security',
				'action'      => 'user.app_password_created',
				'severity'    => 'notice',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
			)
		);
	}

	/**
	 * Application password deleted.
	 *
	 * @param int   $user_id User ID.
	 * @param array $item    Password metadata.
	 */
	public function on_app_password_deleted( $user_id, $item ) {
		$name = is_array( $item ) && isset( $item['name'] ) ? $item['name'] : '';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: application password name. */
					__( 'Deleted the application password "%s".', 'advance-history' ),
					Text::plain( $name, 80 )
				),
				'category'    => 'security',
				'action'      => 'user.app_password_deleted',
				'severity'    => 'warning',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
			)
		);
	}

	/**
	 * Failed application-password authentication.
	 *
	 * @param \WP_Error $error Error.
	 */
	public function on_app_password_failed( $error ) {
		unset( $error );
		if ( $this->throttled( 'application-password' ) ) {
			return;
		}
		$this->log(
			array(
				'summary'   => __( 'An application password failed to authenticate.', 'advance-history' ),
				'category'  => 'security',
				'action'    => 'user.login_failed',
				'severity'  => 'warning',
				'user_id'   => 0,
				'initiator' => 'anonymous',
			)
		);
	}

	/**
	 * Successful application-password authentication.
	 *
	 * @param \WP_User $user User.
	 * @param array    $item Password metadata.
	 */
	public function on_app_password_login( $user, $item ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$name = is_array( $item ) && isset( $item['name'] ) ? $item['name'] : '';
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: 1: username, 2: application password name. */
					__( '"%1$s" authenticated with the application password "%2$s".', 'advance-history' ),
					$user->user_login,
					Text::plain( $name, 80 )
				),
				'category'    => 'security',
				'action'      => 'user.login',
				'severity'    => 'notice',
				'user_id'     => (int) $user->ID,
				'object_type' => 'user',
				'object_id'   => (int) $user->ID,
			)
		);
	}

	/**
	 * Remember that a second factor was accepted.
	 *
	 * @param \WP_User $user User.
	 */
	public function on_two_factor( $user ) {
		if ( $user instanceof \WP_User ) {
			$this->two_factor[ (int) $user->ID ] = true;
		}
	}

	/**
	 * Record a role change.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $new     New roles.
	 * @param string[] $old     Old roles.
	 */
	private function log_role_change( $user_id, array $new, array $old ) {
		$user     = get_userdata( $user_id );
		$login    = $user ? $user->user_login : (string) $user_id;
		$serious  = in_array( 'administrator', $new, true ) || in_array( 'administrator', $old, true );
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %s: username. */
					__( 'Changed roles for "%s".', 'advance-history' ),
					$login
				),
				'category'    => 'user',
				'action'      => 'user.role_changed',
				'severity'    => $serious ? 'warning' : 'notice',
				'object_type' => 'user',
				'object_id'   => absint( $user_id ),
				'context'     => array(
					'changes' => array(
						array(
							'field' => __( 'Roles', 'advance-history' ),
							'from'  => implode( ', ', array_map( 'sanitize_key', $old ) ),
							'to'    => implode( ', ', array_map( 'sanitize_key', $new ) ),
						),
					),
				),
			)
		);
	}

	/**
	 * Count failures in a 15-minute window.
	 *
	 * @return int Threshold count when it has just been reached, otherwise 0.
	 */
	private function count_failure() {
		$state = get_option( 'advance_history_fail_window', array() );
		if ( ! is_array( $state ) || empty( $state['start'] ) || (int) $state['start'] < time() - ( 15 * MINUTE_IN_SECONDS ) ) {
			$state = array(
				'start'   => time(),
				'count'   => 0,
				'alerted' => 0,
			);
		}
		$state['count'] = (int) $state['count'] + 1;
		$hit            = 0;
		$threshold      = (int) Settings::all()['alert_threshold'];
		if ( $state['count'] >= $threshold && empty( $state['alerted'] ) ) {
			$state['alerted'] = 1;
			$hit              = (int) $state['count'];
		}
		update_option( 'advance_history_fail_window', $state, false );
		return $hit;
	}

	/**
	 * Group repeated failures so a flood cannot fill the journal.
	 *
	 * @param string $bucket Username or other bucket.
	 * @return bool True when this attempt should not create its own row.
	 */
	private function throttled( $bucket ) {
		$key   = 'ah_fl_' . md5( strtolower( $bucket ) );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		if ( $count < 8 ) {
			return false;
		}
		if ( 0 === ( $count % 25 ) ) {
			$this->log(
				array(
					'summary'   => sprintf(
						/* translators: 1: attempt count, 2: username. */
						__( 'Grouped %1$d additional failed logins for "%2$s".', 'advance-history' ),
						$count,
						Text::plain( $bucket, 60 )
					),
					'category'  => 'security',
					'action'    => 'user.login_failed_grouped',
					'severity'  => 'warning',
					'user_id'   => 0,
					'initiator' => 'anonymous',
				)
			);
		}
		return true;
	}
}
