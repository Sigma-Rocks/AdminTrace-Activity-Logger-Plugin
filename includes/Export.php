<?php
/**
 * CSV and JSON export.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Csv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds downloadable copies of the journal.
 */
class Export {

	/**
	 * Collect rows for an export.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function collect( array $args ) {
		$args['page']           = 1;
		$args['per_page']       = 5000;
		$args['internal_limit'] = true;
		$result                 = Query::search( $args );
		$include_ip             = ! empty( $args['include_ip'] );
		$rows                   = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = Access::present( $row, $include_ip );
		}
		return $rows;
	}

	/**
	 * Send an export and stop the request.
	 *
	 * @param string $format csv or json.
	 * @param array  $args   Query arguments.
	 */
	public static function send( $format, array $args ) {
		$format = ( 'json' === $format ) ? 'json' : 'csv';
		$rows   = self::collect( $args );

		Recorder::log(
			array(
				'summary'   => sprintf(
					/* translators: 1: file format, 2: number of rows. */
					__( 'Exported the activity journal as %1$s (%2$d rows).', 'advance-history' ),
					strtoupper( $format ),
					count( $rows )
				),
				'category'  => 'privacy',
				'action'    => 'privacy.export',
				'severity'  => 'notice',
				'logger'    => 'export',
				'initiator' => 'user',
			)
		);

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		$filename = 'advance-history-' . gmdate( 'Ymd-His' ) . '.' . $format;

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' );
		$headers = array( 'id', 'created_gmt', 'user_login', 'user_display', 'initiator', 'ip_masked', 'severity', 'category', 'action', 'object_type', 'object_id', 'object_title', 'summary', 'context' );
		fputcsv( $out, $headers, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			$context = isset( $row['context'] ) ? wp_json_encode( $row['context'] ) : '';
			$line    = array(
				isset( $row['id'] ) ? $row['id'] : '',
				isset( $row['created_gmt'] ) ? $row['created_gmt'] : '',
				isset( $row['user_login'] ) ? $row['user_login'] : '',
				isset( $row['user_display'] ) ? $row['user_display'] : '',
				isset( $row['initiator'] ) ? $row['initiator'] : '',
				isset( $row['ip_masked'] ) ? $row['ip_masked'] : '',
				isset( $row['severity'] ) ? $row['severity'] : '',
				isset( $row['category'] ) ? $row['category'] : '',
				isset( $row['action'] ) ? $row['action'] : '',
				isset( $row['object_type'] ) ? $row['object_type'] : '',
				isset( $row['object_id'] ) ? $row['object_id'] : '',
				isset( $row['object_title'] ) ? $row['object_title'] : '',
				isset( $row['summary'] ) ? $row['summary'] : '',
				$context,
			);
			fputcsv( $out, array_map( array( Csv::class, 'cell' ), $line ), ',', '"', '\\' );
		}
		fclose( $out );
		exit;
	}
}
