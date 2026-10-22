<?php
/**
 * Content events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Diff;
use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records create, update, and delete events for posts and custom post types.
 */
class Content_Logger extends Logger {

	/**
	 * Changes collected during the request.
	 *
	 * @var array
	 */
	private $buffer = array();

	/**
	 * Whether the buffer is being written.
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'content';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Content', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Posts, pages, custom post types, and site-editor templates.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'transition_post_status', 'on_transition', 3 );
		$this->listen( 'post_updated', 'on_post_updated', 3 );
		$this->listen( 'before_delete_post', 'on_before_delete', 2 );
		$this->listen( 'set_object_terms', 'on_terms', 6 );
		$this->listen( 'added_post_meta', 'on_meta_added', 4 );
		$this->listen( 'deleted_post_meta', 'on_meta_deleted', 4 );
		add_filter( 'update_post_metadata', array( $this, 'on_meta_update' ), 10, 4 );
		add_action( 'shutdown', array( $this, 'flush' ), 20 );
	}

	/**
	 * Remember a status transition.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( $this->skip( $post ) || 'auto-draft' === $new ) {
			return;
		}
		$this->touch( $post );
		if ( ! isset( $this->buffer[ $post->ID ]['status_from'] ) ) {
			$this->buffer[ $post->ID ]['status_from'] = (string) $old;
		}
		$this->buffer[ $post->ID ]['status_to'] = (string) $new;
	}

	/**
	 * Remember field changes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   New post.
	 * @param \WP_Post $before  Previous post.
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		unset( $post_id );
		if ( $this->skip( $after ) || ! $before instanceof \WP_Post ) {
			return;
		}
		$this->touch( $after );

		$map = array(
			'post_title'     => __( 'Title', 'advance-history' ),
			'post_name'      => __( 'Slug', 'advance-history' ),
			'post_excerpt'   => __( 'Excerpt', 'advance-history' ),
			'comment_status' => __( 'Comment status', 'advance-history' ),
			'ping_status'    => __( 'Ping status', 'advance-history' ),
			'menu_order'     => __( 'Order', 'advance-history' ),
		);
		foreach ( $map as $field => $label ) {
			$from = (string) $before->$field;
			$to   = (string) $after->$field;
			if ( $from !== $to ) {
				$this->change(
					$after->ID,
					array(
						'field' => $label,
						'from'  => Text::plain( $from, 180 ),
						'to'    => Text::plain( $to, 180 ),
					)
				);
			}
		}

		if ( (int) $before->post_author !== (int) $after->post_author ) {
			$this->change(
				$after->ID,
				array(
					'field' => __( 'Author', 'advance-history' ),
					'from'  => $this->author_name( $before->post_author ),
					'to'    => $this->author_name( $after->post_author ),
				)
			);
		}

		if ( (string) $before->post_content !== (string) $after->post_content ) {
			$diff = Diff::summarize( $before->post_content, $after->post_content );
			$this->change(
				$after->ID,
				array(
					'field'   => __( 'Content', 'advance-history' ),
					'from'    => sprintf(
						/* translators: %d: character count. */
						__( '%d characters', 'advance-history' ),
						(int) $diff['before_chars']
					),
					'to'      => sprintf(
						/* translators: %d: character count. */
						__( '%d characters', 'advance-history' ),
						(int) $diff['after_chars']
					),
					'added'   => $diff['added'],
					'removed' => $diff['removed'],
				)
			);
		}
	}

	/**
	 * Permanent deletion.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_before_delete( $post_id, $post ) {
		unset( $this->buffer[ $post_id ] );
		if ( $this->skip( $post ) ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: post type label, 2: title. */
					__( 'Permanently deleted %1$s "%2$s".', 'advance-history' ),
					$this->type_label( $post ),
					$post->post_title
				),
				'category'     => 'content',
				'action'       => 'content.deleted',
				'severity'     => 'warning',
				'object_type'  => 'content',
				'object_id'    => (int) $post->ID,
				'object_title' => $post->post_title,
				'context'      => array( 'post_type' => $post->post_type ),
			)
		);
	}

	/**
	 * Term relationship changes.
	 *
	 * @param int    $object_id Object ID.
	 * @param mixed  $terms     Terms.
	 * @param array  $tt_ids    New term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 * @param bool   $append    Whether terms were appended.
	 * @param array  $old_tt_ids Previous IDs.
	 */
	public function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $append );
		if ( 'nav_menu' === $taxonomy ) {
			return;
		}
		$post = get_post( $object_id );
		if ( $this->skip( $post ) ) {
			return;
		}
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old ) {
			return;
		}
		$tax_object = get_taxonomy( $taxonomy );
		$label      = ( $tax_object && isset( $tax_object->labels->name ) ) ? $tax_object->labels->name : $taxonomy;
		$this->touch( $post );
		$this->change(
			$post->ID,
			array(
				'field' => sprintf(
					/* translators: %s: taxonomy label. */
					__( 'Terms (%s)', 'advance-history' ),
					$label
				),
				'from'  => implode( ', ', $this->term_names( $old ) ),
				'to'    => implode( ', ', $this->term_names( $new ) ),
			)
		);
	}

	/**
	 * Capture a meta update without blocking it.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value New value.
	 * @return mixed
	 */
	public function on_meta_update( $check, $object_id, $meta_key, $meta_value ) {
		try {
			if ( $this->enabled() && ! $this->flushing && $this->track_meta_key( $meta_key ) ) {
				$post = get_post( $object_id );
				if ( ! $this->skip( $post ) ) {
					$old = get_post_meta( $object_id, $meta_key, true );
					if ( $old !== $meta_value ) {
						$this->remember_meta( $post, $meta_key, $old, $meta_value );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $check;
	}

	/**
	 * Added meta.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 */
	public function on_meta_added( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( ! $this->track_meta_key( $meta_key ) ) {
			return;
		}
		$post = get_post( $object_id );
		if ( $this->skip( $post ) ) {
			return;
		}
		$this->remember_meta( $post, $meta_key, '', $meta_value );
	}

	/**
	 * Deleted meta.
	 *
	 * @param array  $meta_ids  Meta IDs.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Value.
	 */
	public function on_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
		unset( $meta_ids );
		if ( ! $this->track_meta_key( $meta_key ) ) {
			return;
		}
		$post = get_post( $object_id );
		if ( $this->skip( $post ) ) {
			return;
		}
		$this->remember_meta( $post, $meta_key, $meta_value, '' );
	}

	/**
	 * Write one event per changed post.
	 */
	public function flush() {
		if ( $this->flushing || ! $this->enabled() || ! $this->buffer ) {
			return;
		}
		$this->flushing = true;
		$buffer         = $this->buffer;
		$this->buffer   = array();

		foreach ( $buffer as $post_id => $data ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$from     = isset( $data['status_from'] ) ? (string) $data['status_from'] : '';
			$to       = isset( $data['status_to'] ) ? (string) $data['status_to'] : $post->post_status;
			$changes  = isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : array();
			$changed  = ( '' !== $from && '' !== $to && $from !== $to );
			if ( ! $changed && ! $changes ) {
				continue;
			}
			if ( $changed ) {
				array_unshift(
					$changes,
					array(
						'field' => __( 'Status', 'advance-history' ),
						'from'  => $from,
						'to'    => $to,
					)
				);
			}

			$label = $this->type_label( $post );
			$title = $post->post_title;
			if ( in_array( $from, array( '', 'new', 'auto-draft' ), true ) ) {
				$action  = 'content.created';
				$summary = ( 'publish' === $to )
					? sprintf( __( 'Published %1$s "%2$s".', 'advance-history' ), $label, $title )
					: sprintf( __( 'Created %1$s "%2$s".', 'advance-history' ), $label, $title );
			} elseif ( 'trash' === $to ) {
				$action  = 'content.trashed';
				$summary = sprintf( __( 'Moved %1$s "%2$s" to the trash.', 'advance-history' ), $label, $title );
			} elseif ( 'trash' === $from ) {
				$action  = 'content.restored';
				$summary = sprintf( __( 'Restored %1$s "%2$s".', 'advance-history' ), $label, $title );
			} elseif ( 'publish' === $to && 'publish' !== $from ) {
				$action  = 'content.published';
				$summary = sprintf( __( 'Published %1$s "%2$s".', 'advance-history' ), $label, $title );
			} else {
				$action  = 'content.updated';
				$summary = sprintf( __( 'Updated %1$s "%2$s".', 'advance-history' ), $label, $title );
			}

			$severity = in_array( $action, array( 'content.trashed', 'content.deleted' ), true ) ? 'warning' : 'info';
			if ( 'content.published' === $action || 'content.created' === $action ) {
				$severity = 'notice';
			}

			$revisions = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 1 ) );
			$revision  = $revisions ? (int) array_key_first( $revisions ) : 0;

			$this->log(
				array(
					'summary'      => $summary,
					'category'     => 'content',
					'action'       => $action,
					'severity'     => $severity,
					'object_type'  => 'content',
					'object_id'    => (int) $post->ID,
					'object_title' => $title,
					'context'      => array(
						'post_type'   => $post->post_type,
						'changes'     => array_slice( $changes, 0, 12 ),
						'revision_id' => $revision,
					),
				)
			);
		}

		$this->flushing = false;
	}

	/**
	 * Whether a post should be ignored.
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	private function skip( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return true;
		}
		$blocked = array(
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'attachment',
			'shop_order',
			'shop_order_refund',
			'shop_order_placehold',
			'acf-field',
			'acf-field-group',
		);
		if ( in_array( $post->post_type, $blocked, true ) ) {
			return true;
		}
		$allowed = array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'wp_font_family', 'wp_font_face', 'product', 'shop_coupon' );
		$object  = get_post_type_object( $post->post_type );
		$viewable = $object && ( is_post_type_viewable( $object ) || ! empty( $object->show_ui ) );
		if ( ! $viewable && ! in_array( $post->post_type, $allowed, true ) ) {
			return true;
		}
		return (bool) apply_filters( 'advance_history_skip_post_type', false, $post->post_type, $post );
	}

	/**
	 * Start a buffer entry.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function touch( $post ) {
		if ( ! isset( $this->buffer[ $post->ID ] ) ) {
			$this->buffer[ $post->ID ] = array(
				'changes' => array(),
			);
		}
	}

	/**
	 * Append a change.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $change  Change row.
	 */
	private function change( $post_id, array $change ) {
		if ( ! isset( $this->buffer[ $post_id ] ) ) {
			$this->buffer[ $post_id ] = array( 'changes' => array() );
		}
		if ( count( $this->buffer[ $post_id ]['changes'] ) >= 12 ) {
			return;
		}
		$this->buffer[ $post_id ]['changes'][] = $change;
	}

	/**
	 * Whether a meta key is worth recording.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function track_meta_key( $key ) {
		$key = (string) $key;
		if ( in_array( $key, array( '_thumbnail_id', '_wp_page_template' ), true ) ) {
			return true;
		}
		if ( '' === $key || '_' === substr( $key, 0, 1 ) ) {
			return false;
		}
		if ( \SigmaRocks\AdvanceHistory\Support\Context::is_sensitive_key( $key ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Store a meta change.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $key  Meta key.
	 * @param mixed    $old  Old value.
	 * @param mixed    $new  New value.
	 */
	private function remember_meta( $post, $key, $old, $new ) {
		$this->touch( $post );
		if ( '_thumbnail_id' === $key ) {
			$this->change(
				$post->ID,
				array(
					'field'           => __( 'Featured image', 'advance-history' ),
					'from'            => $old ? '#' . absint( $old ) : __( 'None', 'advance-history' ),
					'to'              => $new ? '#' . absint( $new ) : __( 'None', 'advance-history' ),
					'from_attachment' => absint( $old ),
					'to_attachment'   => absint( $new ),
				)
			);
			return;
		}
		if ( '_wp_page_template' === $key ) {
			$this->change(
				$post->ID,
				array(
					'field' => __( 'Template', 'advance-history' ),
					'from'  => Text::plain( wp_basename( (string) $old ), 80 ),
					'to'    => Text::plain( wp_basename( (string) $new ), 80 ),
				)
			);
			return;
		}
		if ( is_array( $old ) || is_array( $new ) || is_object( $old ) || is_object( $new ) ) {
			$this->change(
				$post->ID,
				array(
					'field' => Text::plain( $key, 40 ),
					'from'  => __( 'Changed', 'advance-history' ),
					'to'    => __( 'Updated', 'advance-history' ),
				)
			);
			return;
		}
		$this->change(
			$post->ID,
			array(
				'field' => Text::plain( $key, 40 ),
				'from'  => Text::plain( $old, 120 ),
				'to'    => Text::plain( $new, 120 ),
			)
		);
	}

	/**
	 * Singular post-type label.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function type_label( $post ) {
		$object = get_post_type_object( $post->post_type );
		if ( $object && isset( $object->labels->singular_name ) ) {
			return $object->labels->singular_name;
		}
		return $post->post_type;
	}

	/**
	 * Author display name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function author_name( $user_id ) {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : (string) $user_id;
	}

	/**
	 * Term names for term-taxonomy IDs.
	 *
	 * @param int[] $tt_ids IDs.
	 * @return string[]
	 */
	private function term_names( array $tt_ids ) {
		$names = array();
		foreach ( array_slice( $tt_ids, 0, 15 ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}
}
