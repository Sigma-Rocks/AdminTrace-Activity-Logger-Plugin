<?php
/**
 * Shared helpers for AdminTrace tests.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Email_Log;
use SigmaRocks\AdvanceHistory\Installer;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;

/**
 * Thrown in place of a redirect so handlers do not exit.
 */
class Srah_Redirect_Exception extends Exception {}

/**
 * Base test case.
 */
abstract class Srah_Test_Case extends WP_UnitTestCase {

	/**
	 * Settings that keep tests from sending mail or webhooks.
	 *
	 * @var array
	 */
	protected $quiet = array();

	/**
	 * Reset settings and capture redirects.
	 */
	public function set_up() {
		parent::set_up();
		$this->quiet = array_merge(
			Settings::defaults(),
			array(
				'alert_failed_logins'  => 0,
				'webhook_url'          => '',
				'webhook_min_severity' => 'off',
				'email_digest'         => 'off',
				'ip_mode'              => 'masked',
			)
		);
		$this->settings( array() );
		Email_Log::reset_request_state();
		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ), PHP_INT_MAX );
		reset_phpmailer_instance();
	}

	/**
	 * Clear request globals.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Settings::flush();
		parent::tear_down();
	}

	/**
	 * Save quiet settings with overrides.
	 *
	 * @param array $overrides Settings to change.
	 */
	protected function settings( array $overrides ) {
		Settings::update( array_merge( $this->quiet, $overrides ) );
		Settings::flush();
	}

	/**
	 * Redirect filter.
	 *
	 * @param string $location Target.
	 * @throws Srah_Redirect_Exception Always.
	 */
	public function throw_redirect( $location ) {
		throw new Srah_Redirect_Exception( (string) $location );
	}

	/**
	 * Run an admin-post handler.
	 *
	 * @param callable $handler Handler.
	 * @param array    $post    POST fields.
	 * @return array{0:string,1:string} redirect or die, plus the location or message.
	 */
	protected function handle( $handler, array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			call_user_func( $handler );
		} catch ( Srah_Redirect_Exception $e ) {
			return array( 'redirect', $e->getMessage() );
		} catch ( WPDieException $e ) {
			return array( 'die', $e->getMessage() );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return array( 'return', '' );
	}

	/**
	 * Newest stored row for an action.
	 *
	 * @param string $action Event ID.
	 * @return array|null
	 */
	protected function latest( $action ) {
		$result = Query::search(
			array(
				'action'       => $action,
				'per_page'     => 1,
				'unrestricted' => true,
			)
		);
		return isset( $result['rows'][0] ) ? $result['rows'][0] : null;
	}

	/**
	 * Number of rows in this site's journal.
	 *
	 * @return int
	 */
	protected function count_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::table() ) );
	}

	/**
	 * Insert a row directly, bypassing the recorder.
	 *
	 * @param array $fields Column values.
	 * @return int
	 */
	protected function insert_row( array $fields ) {
		global $wpdb;
		$wpdb->insert(
			Installer::table(),
			array_merge(
				array(
					'blog_id'     => get_current_blog_id(),
					'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'severity'    => 'info',
					'category'    => 'journal',
					'action'      => 'journal.note',
					'summary'     => 'Row ' . wp_generate_password( 6, false ),
					'context'     => '{}',
				),
				$fields
			)
		);
		return (int) $wpdb->insert_id;
	}
}
