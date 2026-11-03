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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the private feed is authenticated by its secret token.
		if ( ! isset( $_GET['srah_feed'] ) ) {
			return;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! self::token_matches( $token ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		self::render();
		exit;
	}

	/**
	 * Whether the feed is on and the token is the stored secret.
	 *
	 * @param string $token Token from the request.
	 * @return bool
	 */
	public static function token_matches( $token ) {
		$settings = Settings::all();
		$stored   = (string) $settings['feed_token'];
		$token    = (string) $token;
		return ! empty( $settings['feed_enabled'] ) && '' !== $stored && '' !== $token && hash_equals( $stored, $token );
	}

	/**
	 * Print the RSS document for the 50 newest events.
	 */
	public static function render() {
		$result = Query::search(
			array(
				'per_page'       => 50,
				'page'           => 1,
				'unrestricted'   => true,
				'internal_limit' => true,
			)
		);

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>';
		echo '<rss version="2.0"><channel>';
		echo '<title>' . esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( '%s activity', 'admintrace-activity-logger' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		) . '</title>';
		echo '<link>' . esc_url( home_url( '/' ) ) . '</link>';
		echo '<description>' . esc_html__( 'Recent activity recorded by AdminTrace Activity Logger.', 'admintrace-activity-logger' ) . '</description>';

		foreach ( $result['rows'] as $row ) {
			echo '<item>';
			echo '<title>' . esc_html( Text::plain( $row['summary'], 180 ) ) . '</title>';
			echo '<pubDate>' . esc_html( gmdate( 'r', strtotime( $row['created_gmt'] . ' UTC' ) ) ) . '</pubDate>';
			echo '<category>' . esc_html( $row['category'] ) . '</category>';
			echo '<description>' . esc_html( $row['severity'] . ' · ' . $row['user_display'] ) . '</description>';
			echo '</item>';
		}

		echo '</channel></rss>';
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
				'srah_feed' => 1,
				'token'     => $settings['feed_token'],
			),
			home_url( '/' )
		);
	}
}
