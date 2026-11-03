<?php
/**
 * Private RSS feed.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Feed;

/**
 * Token checks and feed output.
 */
class Test_Feed extends Srah_Test_Case {

	const TOKEN = 'abcDEF1234567890abcDEF1234567890';

	public function test_the_token_must_match_and_the_feed_be_enabled() {
		$this->settings( array( 'feed_token' => self::TOKEN ) );
		$this->assertFalse( Feed::token_matches( self::TOKEN ), 'Disabled feed.' );

		$this->settings(
			array(
				'feed_token'   => self::TOKEN,
				'feed_enabled' => 1,
			)
		);
		$this->assertTrue( Feed::token_matches( self::TOKEN ) );
		$this->assertFalse( Feed::token_matches( 'wrong' ) );
		$this->assertFalse( Feed::token_matches( '' ) );

		$this->settings(
			array(
				'feed_token'   => '',
				'feed_enabled' => 1,
			)
		);
		$this->assertFalse( Feed::token_matches( '' ), 'An empty stored token never matches.' );
	}

	public function test_the_feed_url_carries_the_token() {
		$this->settings( array( 'feed_token' => self::TOKEN ) );
		$this->assertStringContainsString( 'token=' . self::TOKEN, Feed::url() );
		$this->settings( array( 'feed_token' => '' ) );
		$this->assertSame( '', Feed::url() );
	}

	public function test_rendered_feed_is_valid_rss_without_addresses() {
		$this->insert_row(
			array(
				'summary'   => 'Feed item <script>alert(1)</script> & more',
				'ip_masked' => '198.51.100.0',
			)
		);
		ob_start();
		Feed::render();
		$xml = (string) ob_get_clean();

		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc, 'The feed parses as XML.' );
		$titles  = array_map( 'strval', $doc->xpath( '//item/title' ) );
		$matches = array_values( preg_grep( '/^Feed item/', $titles ) );
		$this->assertCount( 1, $matches );
		$this->assertStringEndsWith( '& more', $matches[0] );
		$this->assertStringNotContainsString( '<', $matches[0] );
		$this->assertStringNotContainsString( '198.51.100', $xml );
		$this->assertStringNotContainsString( '<script>', $xml );
	}
}
