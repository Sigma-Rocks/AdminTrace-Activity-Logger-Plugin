<?php
/**
 * Settings events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Context;
use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records a curated set of WordPress settings.
 *
 * Options whose names look like credentials are never stored, even if a
 * filter adds them to the tracked list.
 */
class Settings_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'settings';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Settings', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Important site settings. Secrets, API keys, and transients are skipped.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'updated_option', 'on_updated', 3 );
		$this->listen( 'added_option', 'on_added', 2 );
		$this->listen( 'customize_save_after', 'on_customizer', 1 );
	}

	/**
	 * Option updated.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $new    New value.
	 */
	public function on_updated( $option, $old, $new ) {
		$this->record( $option, $old, $new, false );
	}

	/**
	 * Option added.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public function on_added( $option, $value ) {
		$this->record( $option, null, $value, true );
	}

	/**
	 * Customizer saved.
	 *
	 * @param \WP_Customize_Manager $manager Customizer.
	 */
	public function on_customizer( $manager ) {
		$count = 0;
		if ( is_object( $manager ) && method_exists( $manager, 'unsanitized_post_values' ) ) {
			$values = $manager->unsanitized_post_values();
			if ( is_array( $values ) ) {
				foreach ( array_keys( $values ) as $key ) {
					if ( ! Context::is_sensitive_key( (string) $key ) ) {
						++$count;
					}
				}
			}
		}
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: %d: number of settings. */
					__( 'Saved customizer changes (%d settings).', 'advance-history' ),
					$count
				),
				'category' => 'settings',
				'action'   => 'settings.customizer',
				'severity' => 'notice',
			)
		);
	}

	/**
	 * Record one option when it is on the allowlist.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $new    New value.
	 * @param bool   $added  Whether the option was added.
	 */
	private function record( $option, $old, $new, $added ) {
		$option = (string) $option;
		if ( $this->blocked( $option ) || ! in_array( $option, $this->tracked(), true ) ) {
			return;
		}
		if ( ! $added && $old === $new ) {
			return;
		}

		$change = array(
			'field' => $option,
			'from'  => $this->display_value( $old ),
			'to'    => $this->display_value( $new ),
		);
		if ( 'site_icon' === $option ) {
			$change['from_attachment'] = absint( $old );
			$change['to_attachment']   = absint( $new );
		}

		$category = ( 0 === strpos( $option, 'woocommerce_' ) ) ? 'commerce' : 'settings';
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: %s: option name. */
					$added ? __( 'Added the setting "%s".', 'advance-history' ) : __( 'Changed the setting "%s".', 'advance-history' ),
					$option
				),
				'category'     => $category,
				'action'       => 'settings.updated',
				'severity'     => 'notice',
				'object_type'  => 'option',
				'object_title' => $option,
				'context'      => array( 'changes' => array( $change ) ),
			)
		);
	}

	/**
	 * Options that are safe and useful to record.
	 *
	 * @return string[]
	 */
	private function tracked() {
		$options = array(
			'blogname',
			'blogdescription',
			'blog_public',
			'siteurl',
			'home',
			'admin_email',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
			'users_can_register',
			'default_role',
			'permalink_structure',
			'category_base',
			'tag_base',
			'posts_per_page',
			'page_on_front',
			'page_for_posts',
			'show_on_front',
			'default_comment_status',
			'default_ping_status',
			'comment_moderation',
			'comment_registration',
			'comments_notify',
			'moderation_notify',
			'require_name_email',
			'comment_previously_approved',
			'close_comments_for_old_posts',
			'blog_charset',
			'WPLANG',
			'rss_use_excerpt',
			'uploads_use_yearmonth_folders',
			'site_icon',
			'avatar_default',
			'show_avatars',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
			'woocommerce_store_address',
			'woocommerce_store_address_2',
			'woocommerce_store_city',
			'woocommerce_default_country',
			'woocommerce_store_postcode',
			'woocommerce_currency',
			'woocommerce_currency_pos',
			'woocommerce_price_thousand_sep',
			'woocommerce_price_decimal_sep',
			'woocommerce_price_num_decimals',
			'woocommerce_allowed_countries',
			'woocommerce_enable_guest_checkout',
			'woocommerce_enable_checkout_login_reminder',
			'woocommerce_enable_signup_and_login_from_checkout',
			'woocommerce_calc_taxes',
			'woocommerce_prices_include_tax',
			'woocommerce_demo_store',
		);
		$options = apply_filters( 'advance_history_tracked_options', $options );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * Whether an option must never be recorded.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function blocked( $option ) {
		if ( 0 === strpos( $option, '_transient' ) || 0 === strpos( $option, '_site_transient' ) || 0 === strpos( $option, 'advance_history_' ) ) {
			return true;
		}
		return (bool) preg_match( '/pass|secret|token|api|key|salt|private|credential|smtp|webhook|auth|consumer|signature/i', $option );
	}

	/**
	 * A short display value that omits arrays.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function display_value( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return Text::plain( $value, 180 );
		}
		return __( '(value not shown)', 'advance-history' );
	}
}
