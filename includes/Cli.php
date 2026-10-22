<?php
/**
 * WP-CLI commands.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command line access to the journal.
 */
class Cli {

	/**
	 * Register the command.
	 */
	public static function register() {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'advance-history', __CLASS__ );
	}

	/**
	 * List recent events.
	 *
	 * ## OPTIONS
	 *
	 * [--category=<category>]
	 * : Category key.
	 *
	 * [--user=<id>]
	 * : User ID.
	 *
	 * [--since=<datetime>]
	 * : GMT datetime or Y-m-d date.
	 *
	 * [--search=<text>]
	 * : Search text.
	 *
	 * [--limit=<number>]
	 * : Maximum rows. Default 20.
	 *
	 * [--format=<format>]
	 * : table, json, or csv. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );
		$this->assert_allowed( Capabilities::VIEW );

		$query = $this->query_args( $assoc_args );
		$query['per_page'] = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$result = Query::search( $query );
		$rows   = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = Access::present( $row, ! empty( $query['include_ip'] ) );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'created_gmt', 'severity', 'category', 'user_login', 'summary' ) );
	}

	/**
	 * Delete events older than a number of days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Override the saved retention period for this run. Use 0 to skip.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function prune( $args, $assoc_args ) {
		unset( $args );
		$this->assert_allowed( Capabilities::DELETE );

		if ( isset( $assoc_args['days'] ) ) {
			$days = max( 0, (int) $assoc_args['days'] );
			if ( 0 === $days ) {
				\WP_CLI::log( 'Retention is unlimited; nothing was deleted.' );
				return;
			}
			$deleted = Retention::prune( $days );
			\WP_CLI::success( "Deleted {$deleted} events." );
			return;
		}

		$deleted = Retention::prune();
		\WP_CLI::success( "Deleted {$deleted} events." );
	}

	/**
	 * Add a journal note.
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : Note text.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function note( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->assert_allowed( Capabilities::MANAGE );
		$message = isset( $args[0] ) ? $args[0] : '';
		$id      = Recorder::log(
			array(
				'summary'   => $message,
				'category'  => 'journal',
				'action'    => 'journal.note',
				'severity'  => 'info',
				'logger'    => 'journal',
				'initiator' => 'cli',
			)
		);
		if ( ! $id ) {
			\WP_CLI::error( 'The note could not be saved.' );
		}
		\WP_CLI::success( "Saved note {$id}." );
	}

	/**
	 * Build a scoped query.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return array
	 */
	private function query_args( array $assoc_args ) {
		$args = array(
			'category' => isset( $assoc_args['category'] ) ? $assoc_args['category'] : '',
			'user_id'  => isset( $assoc_args['user'] ) ? (int) $assoc_args['user'] : 0,
			'after'    => isset( $assoc_args['since'] ) ? $assoc_args['since'] : '',
			'search'   => isset( $assoc_args['search'] ) ? $assoc_args['search'] : '',
			'page'     => 1,
		);

		if ( 0 === get_current_user_id() || current_user_can( Capabilities::MANAGE ) ) {
			$args['unrestricted'] = true;
			$args['include_ip']   = true;
			return $args;
		}

		return Access::constrain( $args );
	}

	/**
	 * Stop when the current user cannot run a command.
	 *
	 * A shell session with no WordPress user is allowed because it already
	 * has database access. An explicit --user without the capability is denied.
	 *
	 * @param string $capability Capability name.
	 */
	private function assert_allowed( $capability ) {
		if ( 0 === get_current_user_id() ) {
			return;
		}
		if ( ! current_user_can( $capability ) ) {
			\WP_CLI::error( 'You do not have permission to use Advance History.' );
		}
	}
}
