<?php
/**
 * Media events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records uploads, edits, and deletions of attachments.
 */
class Media_Logger extends Logger {

	/**
	 * Image operations seen during this request.
	 *
	 * @var array<int,string[]>
	 */
	private $operations = array();

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'media';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Media', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Uploads, image edits, and deleted files. File contents are not stored.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'add_attachment', 'on_add', 1 );
		$this->listen( 'edit_attachment', 'on_edit', 1 );
		$this->listen( 'delete_attachment', 'on_delete', 1 );
		add_filter( 'wp_image_editor_before_change', array( $this, 'on_image_change' ), 10, 2 );
	}

	/**
	 * Attachment uploaded.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_add( $post_id ) {
		$this->log_media(
			$post_id,
			'media.uploaded',
			/* translators: %s: attachment title. */
			__( 'Uploaded "%s".', 'admintrace-activity-logger' ),
			'info'
		);
	}

	/**
	 * Attachment edited.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_edit( $post_id ) {
		$this->log_media(
			$post_id,
			'media.edited',
			/* translators: %s: attachment title. */
			__( 'Edited "%s".', 'admintrace-activity-logger' ),
			'info'
		);
	}

	/**
	 * Attachment deleted.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_delete( $post_id ) {
		$this->log_media(
			$post_id,
			'media.deleted',
			/* translators: %s: attachment title. */
			__( 'Deleted the file "%s".', 'admintrace-activity-logger' ),
			'warning'
		);
	}

	/**
	 * Remember crop, rotate, flip, or scale operations.
	 *
	 * @param mixed $image   Image editor.
	 * @param array $changes Change objects.
	 * @return mixed
	 */
	public function on_image_change( $image, $changes ) {
		try {
			$post_id = isset( $_REQUEST['postid'] ) ? absint( wp_unslash( $_REQUEST['postid'] ) ) : 0;
			if ( $post_id && 'attachment' === get_post_type( $post_id ) ) {
				$ops = array();
				foreach ( (array) $changes as $change ) {
					if ( is_object( $change ) && isset( $change->type ) ) {
						$ops[] = sanitize_key( (string) $change->type );
					}
				}
				if ( $ops ) {
					$this->operations[ $post_id ] = array_values( array_unique( $ops ) );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $image;
	}

	/**
	 * Store a media event.
	 *
	 * @param int    $post_id Attachment ID.
	 * @param string $action  Action key.
	 * @param string $pattern Summary pattern with one placeholder.
	 * @param string $severity Severity.
	 */
	private function log_media( $post_id, $action, $pattern, $severity ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$file = get_attached_file( $post_id );
		$mime = get_post_mime_type( $post_id );
		$this->log(
			array(
				'summary'      => sprintf( $pattern, $post->post_title ),
				'category'     => 'media',
				'action'       => $action,
				'severity'     => $severity,
				'object_type'  => 'attachment',
				'object_id'    => (int) $post_id,
				'object_title' => $post->post_title,
				'context'      => array(
					'filename'   => $file ? wp_basename( $file ) : '',
					'mime'       => $mime ? $mime : '',
					'operations' => isset( $this->operations[ $post_id ] ) ? $this->operations[ $post_id ] : array(),
				),
			)
		);
	}
}
