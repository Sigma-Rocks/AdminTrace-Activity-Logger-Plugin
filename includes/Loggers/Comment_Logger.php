<?php
/**
 * Comment events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records comment and note activity without storing author IP addresses.
 */
class Comment_Logger extends Logger {

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'comment';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Comments', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'New comments, moderation decisions, and deletions.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'comment_post', 'on_comment', 2 );
		$this->listen( 'transition_comment_status', 'on_transition', 3 );
	}

	/**
	 * New comment.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   Approval flag.
	 */
	public function on_comment( $comment_id, $approved ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$status = ( 'spam' === $approved ) ? 'spam' : ( ( '1' === (string) $approved || 1 === $approved ) ? 'approved' : 'unapproved' );
		$this->store( $comment, 'comment.created', $status, 'info' );
	}

	/**
	 * Status change.
	 *
	 * @param string     $new     New status.
	 * @param string     $old     Old status.
	 * @param \WP_Comment $comment Comment.
	 */
	public function on_transition( $new, $old, $comment ) {
		if ( $new === $old || ! $comment instanceof \WP_Comment ) {
			return;
		}
		$severity = in_array( $new, array( 'spam', 'trash', 'delete' ), true ) ? 'warning' : 'info';
		$this->store( $comment, 'comment.' . sanitize_key( $new ), $new, $severity, $old );
	}

	/**
	 * Store a comment event.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @param string      $action  Action.
	 * @param string      $status  Status.
	 * @param string      $severity Severity.
	 * @param string      $from    Previous status.
	 */
	private function store( $comment, $action, $status, $severity, $from = '' ) {
		$type    = $comment->comment_type ? $comment->comment_type : 'comment';
		$label   = ( 'note' === $type ) ? __( 'note', 'advance-history' ) : __( 'comment', 'advance-history' );
		$excerpt = Text::plain( $comment->comment_content, 140 );
		$summary = sprintf(
			/* translators: 1: comment or note, 2: status, 3: author name. */
			__( 'A %1$s is %2$s. Author: %3$s.', 'advance-history' ),
			$label,
			$status,
			Text::plain( $comment->comment_author, 80 )
		);
		$this->log(
			array(
				'summary'      => $summary,
				'category'     => 'comment',
				'action'       => $action,
				'severity'     => $severity,
				'object_type'  => 'comment',
				'object_id'    => (int) $comment->comment_ID,
				'object_title' => $excerpt,
				'context'      => array(
					'post_id' => (int) $comment->comment_post_ID,
					'type'    => $type,
					'status'  => $status,
					'from'    => $from,
				),
			)
		);
	}
}
