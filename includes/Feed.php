<?php
/**
 * Private RSS feed.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional token-protected feed of recent activity.
 *
 * The token is a secret. Server access logs may store the feed URL.
 */
class Feed {

	/**
	 * Register the front-end listener.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	/**
	 * Render the feed when a valid token is presented.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET['advance_history_feed'] ) ) {
			return;
		}

		$settings = Settings::all();
		$token    = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';
		$stored   = (string) $settings['feed_token'];
		$valid    = ! empty( $settings['feed_enabled'] ) && '' !== $stored && '' !== $token && hash_equals( $stored, $token );

		if ( ! $valid ) {
			status_header( 404 );
			exit;
		}

		$result = Query::search(
			array(
				'per_page'       => 50,
				'page'           => 1,
				'unrestricted'   => true,
				'internal_limit' => true,
			)
		);

		nocache_headers();
		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>';
		echo '<rss version="2.0"><channel>';
		echo '<title>' . esc_html( sprintf( __( '%s activity', 'advance-history' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ) . '</title>';
		echo '<link>' . esc_url( home_url( '/' ) ) . '</link>';
		echo '<description>' . esc_html__( 'Recent activity recorded by Advance History.', 'advance-history' ) . '</description>';

		foreach ( $result['rows'] as $row ) {
			echo '<item>';
			echo '<title>' . esc_html( Text::plain( $row['summary'], 180 ) ) . '</title>';
			echo '<pubDate>' . esc_html( gmdate( 'r', strtotime( $row['created_gmt'] . ' UTC' ) ) ) . '</pubDate>';
			echo '<category>' . esc_html( $row['category'] ) . '</category>';
			echo '<description>' . esc_html( $row['severity'] . ' · ' . $row['user_display'] ) . '</description>';
			echo '</item>';
		}

		echo '</channel></rss>';
		exit;
	}

	/**
	 * Feed URL for the settings screen.
	 *
	 * @return string
	 */
	public static function url() {
		$settings = Settings::all();
		if ( '' === $settings['feed_token'] ) {
			return '';
		}
		return add_query_arg(
			array(
				'advance_history_feed' => 1,
				'token'                => $settings['feed_token'],
			),
			home_url( '/' )
		);
	}
}
