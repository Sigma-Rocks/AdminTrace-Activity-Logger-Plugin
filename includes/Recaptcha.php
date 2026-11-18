<?php
/**
 * Google reCAPTCHA on WordPress auth forms.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

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
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'verify_login' ), 20, 2 );
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

		$js = '(function(){var siteKey=' . wp_json_encode( $site_key ) . ';'
			. 'function bind(form,action){if(!form){return;}var busy=false;form.addEventListener("submit",function(e){if(busy){return;}e.preventDefault();'
			. 'var input=form.querySelector(".srah-recaptcha-token");if(!input){input=document.createElement("input");input.type="hidden";input.name="g-recaptcha-response";input.className="srah-recaptcha-token";form.appendChild(input);}'
			. 'if(typeof grecaptcha==="undefined"){busy=true;HTMLFormElement.prototype.submit.call(form);return;}'
			. 'grecaptcha.ready(function(){grecaptcha.execute(siteKey,{action:action}).then(function(token){input.value=token;busy=true;HTMLFormElement.prototype.submit.call(form);});});});}'
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
	 * Block password login when the challenge fails.
	 *
	 * @param \WP_User|\WP_Error $user     Authenticated user or error.
	 * @param string            $password Submitted password.
	 * @return \WP_User|\WP_Error
	 */
	public static function verify_login( $user, $password ) {
		unset( $password );
		if ( is_wp_error( $user ) || ! self::is_active() || ! self::is_wp_login_post() ) {
			return $user;
		}
		if ( self::request_ok( 'login' ) ) {
			return $user;
		}
		return new \WP_Error(
			'srah_recaptcha',
			__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' )
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
		if ( ! self::request_ok( 'lostpassword' ) ) {
			$errors->add(
				'srah_recaptcha',
				__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' )
			);
		}
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
		unset( $sanitized_user_login, $user_email );
		if ( ! self::is_active() || ! is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( ! self::request_ok( 'register' ) ) {
			$errors->add(
				'srah_recaptcha',
				__( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'admintrace-activity-logger' )
			);
		}
		return $errors;
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
	 * @return bool
	 */
	private static function request_ok( $expected_action ) {
		$token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- form token verified via Google API.
		if ( '' === $token ) {
			return false;
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
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}

		$action = isset( $data['action'] ) ? (string) $data['action'] : '';
		if ( $expected_action !== $action ) {
			return false;
		}

		$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
		$threshold = isset( $settings['recaptcha_score'] ) ? (float) $settings['recaptcha_score'] : 0.5;
		return $score >= $threshold;
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
