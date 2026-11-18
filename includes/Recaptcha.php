<?php
/**
 * Google reCAPTCHA on WordPress auth forms.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional Google reCAPTCHA v3 for login, lost password, and registration.
 */
class Recaptcha {

	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Register login-screen hooks.
	 */
	public static function register() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'login_form', array( __CLASS__, 'render' ) );
		add_action( 'lostpassword_form', array( __CLASS__, 'render' ) );
		add_action( 'register_form', array( __CLASS__, 'render' ) );
		add_filter( 'authenticate', array( __CLASS__, 'verify_authenticate' ), 100, 3 );
		add_action( 'lostpassword_post', array( __CLASS__, 'verify_lostpassword' ), 10, 1 );
		add_filter( 'registration_errors', array( __CLASS__, 'verify_registration' ), 10, 3 );
	}

	/**
	 * Whether reCAPTCHA should run (enabled and both keys set).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$settings = Settings::all();
		return ! empty( $settings['recaptcha_enabled'] )
			&& '' !== (string) $settings['recaptcha_site_key']
			&& '' !== (string) $settings['recaptcha_secret_key'];
	}

	/**
	 * Load the Google reCAPTCHA v3 script and form handler on auth screens.
	 */
	public static function enqueue() {
		if ( ! self::is_active() ) {
			return;
		}

		$site_key = (string) Settings::all()['recaptcha_site_key'];
		wp_enqueue_script(
			'srah-recaptcha',
			'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
			array(),
			null,
			true
		);

		// v3 is invisible: get a token on submit, then post the form.
		$js = '(function(){var siteKey=' . wp_json_encode( $site_key ) . ';'
			. 'function bind(form,action){if(!form){return;}var busy=false;form.addEventListener("submit",function(e){if(busy){return;}e.preventDefault();'
			. 'var input=form.querySelector(".srah-recaptcha-token");if(!input){input=document.createElement("input");input.type="hidden";input.name="g-recaptcha-response";input.className="srah-recaptcha-token";form.appendChild(input);}'
			. 'var go=function(token){input.value=token||"";if(!input.value){busy=false;window.alert("reCAPTCHA could not run. Check that your v3 keys are valid for this domain.");return;}busy=true;HTMLFormElement.prototype.submit.call(form);};'
			. 'if(typeof grecaptcha==="undefined"){go("");return;}'
			. 'grecaptcha.ready(function(){grecaptcha.execute(siteKey,{action:action}).then(go).catch(function(){go("");});});});}'
			. 'bind(document.getElementById("loginform"),"login");'
			. 'bind(document.getElementById("lostpasswordform"),"lostpassword");'
			. 'bind(document.getElementById("registerform"),"register");})();';
		wp_add_inline_script( 'srah-recaptcha', $js );

		$css = '.srah-recaptcha-note{font-size:12px;line-height:1.4;color:#646970;margin:12px 0 0;} .srah-recaptcha-note a{color:inherit;}';
		wp_register_style( 'srah-recaptcha', false, array(), SRAH_VERSION );
		wp_enqueue_style( 'srah-recaptcha' );
		wp_add_inline_style( 'srah-recaptcha', $css );
	}

	/**
	 * Print the hidden token field and Google attribution.
	 */
	public static function render() {
		if ( ! self::is_active() ) {
			return;
		}

		echo '<input type="hidden" name="g-recaptcha-response" class="srah-recaptcha-token" value="">';
		echo '<p class="srah-recaptcha-note">';
		echo wp_kses(
			sprintf(
				/* translators: 1: Google Privacy Policy URL, 2: Google Terms of Service URL */
				__( 'This site is protected by reCAPTCHA and the Google <a href="%1$s" target="_blank" rel="noopener noreferrer">Privacy Policy</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> apply.', 'admintrace-activity-logger' ),
				'https://policies.google.com/privacy',
				'https://policies.google.com/terms'
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		echo '</p>';
	}

	/**
	 * Block interactive logins when the reCAPTCHA check fails.
	 *
	 * Runs late so every login POST is checked, including wrong passwords.
	 * A failed check becomes a journal entry via wp_login_failed.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authentication result so far.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function verify_authenticate( $user, $username, $password ) {
		unset( $password );
		if ( ! self::is_active() || ! self::is_wp_login_post() ) {
			return $user;
		}
		if ( $user instanceof \WP_Error ) {
			$codes = $user->get_error_codes();
			if ( in_array( 'empty_username', $codes, true ) || in_array( 'empty_password', $codes, true ) ) {
				return $user;
			}
		}

		$result = self::verify( 'login' );
		if ( 'fail' !== $result ) {
			return $user;
		}

		return new \WP_Error(
			'srah_recaptcha',
			__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' ),
			array( 'username' => (string) $username )
		);
	}

	/**
	 * Block lost-password requests when the challenge fails.
	 *
	 * @param \WP_Error $errors Errors collected so far.
	 */
	public static function verify_lostpassword( $errors ) {
		if ( ! self::is_active() || ! is_wp_error( $errors ) ) {
			return;
		}
		if ( 'fail' !== self::verify( 'lostpassword' ) ) {
			return;
		}
		$errors->add(
			'srah_recaptcha',
			__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' )
		);
		$username = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- auth form; captcha is the gate.
		self::log_block( $username, 'lostpassword' );
	}

	/**
	 * Block registration when the challenge fails.
	 *
	 * @param \WP_Error $errors               Registration errors.
	 * @param string    $sanitized_user_login Login name.
	 * @param string    $user_email           Email.
	 * @return \WP_Error
	 */
	public static function verify_registration( $errors, $sanitized_user_login, $user_email ) {
		unset( $user_email );
		if ( ! self::is_active() || ! is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( 'fail' !== self::verify( 'register' ) ) {
			return $errors;
		}
		$errors->add(
			'srah_recaptcha',
			__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' )
		);
		self::log_block( (string) $sanitized_user_login, 'register' );
		return $errors;
	}

	/**
	 * Record a non-login form blocked by reCAPTCHA.
	 *
	 * Login blocks are logged from User_Logger via wp_login_failed.
	 *
	 * @param string $username Attempted username.
	 * @param string $form     lostpassword|register.
	 */
	private static function log_block( $username, $form ) {
		if ( empty( Settings::all()['log_failed_logins'] ) ) {
			return;
		}

		$username = Text::plain( $username, 60 );
		if ( '' === $username ) {
			$username = __( '(none)', 'admintrace-activity-logger' );
		}

		if ( 'register' === $form ) {
			$summary = sprintf(
				/* translators: %s: username. */
				__( 'Bot registration attempt for "%s" was blocked by reCAPTCHA.', 'admintrace-activity-logger' ),
				$username
			);
		} else {
			$summary = sprintf(
				/* translators: %s: username. */
				__( 'Bot password-reset attempt for "%s" was blocked by reCAPTCHA.', 'admintrace-activity-logger' ),
				$username
			);
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? Text::plain( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 180 ) : '';
		Recorder::log(
			array(
				'summary'    => $summary,
				'category'   => 'security',
				'action'     => 'security.recaptcha_blocked',
				'severity'   => 'warning',
				'user_id'    => 0,
				'user_login' => $username,
				'initiator'  => 'anonymous',
				'logger'     => 'user',
				'context'    => array(
					'reason'     => 'recaptcha',
					'form'       => $form,
					'user_agent' => $agent,
				),
			)
		);
	}

	/**
	 * Whether this is an interactive wp-login.php password POST.
	 *
	 * Skips XML-RPC, REST, CLI, and cookie/application-password flows.
	 *
	 * @return bool
	 */
	private static function is_wp_login_post() {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( empty( $_POST['log'] ) || empty( $_POST['pwd'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- auth form; verified by core.
			return false;
		}
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		return 'wp-login.php' === $pagenow;
	}

	/**
	 * Verify the submitted reCAPTCHA v3 token with Google.
	 *
	 * @param string $expected_action Action name passed to grecaptcha.execute().
	 * @return string pass|fail|skip  skip = network/config problem (do not lock users out).
	 */
	private static function verify( $expected_action ) {
		$token = isset( $_POST['g-recaptcha-response'] ) ? (string) wp_unslash( $_POST['g-recaptcha-response'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- token is opaque; verified by Google.
		$token = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
		if ( ! is_string( $token ) || '' === $token ) {
			return 'fail';
		}

		$settings = Settings::all();
		$body     = array(
			'secret'   => (string) $settings['recaptcha_secret_key'],
			'response' => $token,
		);
		$ip       = self::remote_ip();
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 15,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'skip';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return 'skip';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return 'skip';
		}

		if ( empty( $data['success'] ) ) {
			$errors = isset( $data['error-codes'] ) && is_array( $data['error-codes'] ) ? $data['error-codes'] : array();
			return self::is_config_error( $errors ) ? 'skip' : 'fail';
		}

		$action = isset( $data['action'] ) ? (string) $data['action'] : '';
		if ( $expected_action !== $action ) {
			return 'fail';
		}

		$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
		$threshold = isset( $settings['recaptcha_score'] ) ? (float) $settings['recaptcha_score'] : 0.5;
		return ( $score >= $threshold ) ? 'pass' : 'fail';
	}

	/**
	 * Whether Google returned a key/project problem rather than a bot signal.
	 *
	 * @param array $errors Google error-codes list.
	 * @return bool
	 */
	private static function is_config_error( array $errors ) {
		foreach ( $errors as $error ) {
			$error = strtolower( (string) $error );
			if ( 'invalid-input-secret' === $error || 'bad-request' === $error ) {
				return true;
			}
			if ( false !== strpos( $error, 'project' ) || false !== strpos( $error, 'not found' ) || false !== strpos( $error, 'deleted' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Visitor IP for Google verification (unmasked).
	 *
	 * @return string
	 */
	private static function remote_ip() {
		$settings = Settings::all();
		$remote   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( empty( $settings['trust_proxy'] ) ) {
			return $remote;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' === $forwarded ) {
			return $remote;
		}

		foreach ( explode( ',', $forwarded ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate && false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return $remote;
	}
}
