<?php
/**
 * CSV and JSON exports.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Export;
use SigmaRocks\AdvanceHistory\Support\Access;

/**
 * Export bodies and access rules.
 */
class Test_Export extends Srah_Test_Case {

	/**
	 * Rows an administrator would export.
	 *
	 * @param string $search Search text.
	 * @return array
	 */
	private function rows_for_admin( $search ) {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		return Export::collect( Access::constrain( array( 'search' => $search ) ) );
	}

	public function test_json_export_contains_the_rows() {
		$this->insert_row(
			array(
				'summary'   => 'Exported <b>row</b> & "quotes"',
				'ip_masked' => '198.51.100.0',
			)
		);
		$json    = Export::json( $this->rows_for_admin( 'Exported' ) );
		$decoded = json_decode( $json, true );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'Exported <b>row</b> & "quotes"', $decoded[0]['summary'] );
		$this->assertSame( '198.51.100.0', $decoded[0]['ip_masked'] );
		$this->assertStringNotContainsString( '<b>', $json, 'Tags are hex-escaped.' );
	}

	public function test_csv_export_neutralizes_formulas() {
		$this->insert_row(
			array(
				'summary'      => '=HYPERLINK("http://evil.example","click")',
				'object_title' => '+SUM(A1)',
			)
		);
		$stream = fopen( 'php://memory', 'w+' );
		Export::write_csv( $stream, $this->rows_for_admin( 'HYPERLINK' ) );
		rewind( $stream );
		$header = fgetcsv( $stream, 0, ',', '"', '\\' );
		$line   = fgetcsv( $stream, 0, ',', '"', '\\' );
		fclose( $stream );

		$this->assertSame( 'summary', $header[14] );
		$this->assertSame( "'=HYPERLINK(\"http://evil.example\",\"click\")", $line[14] );
		$this->assertSame( "'+SUM(A1)", $line[13] );
	}

	public function test_editor_exports_have_no_addresses_and_only_content() {
		$this->insert_row(
			array(
				'summary'   => 'Editor visible',
				'category'  => 'content',
				'ip_masked' => '198.51.100.0',
			)
		);
		$this->insert_row(
			array(
				'summary'  => 'Editor hidden',
				'category' => 'settings',
			)
		);
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$rows = Export::collect( Access::constrain( array( 'search' => 'Editor' ) ) );
		$this->assertSame( array( 'Editor visible' ), wp_list_pluck( $rows, 'summary' ) );
		$this->assertSame( '', $rows[0]['ip_masked'] );
	}

	public function test_the_export_handler_checks_capability_and_nonce() {
		$this->assertSame( 'die', $this->handle( array( Menu::class, 'export' ), array( 'format' => 'json' ) )[0] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame(
			'die',
			$this->handle(
				array( Menu::class, 'export' ),
				array(
					'format'   => 'json',
					'_wpnonce' => wp_create_nonce( 'srah_export' ),
				)
			)[0]
		);
	}
}
