<?php
/**
 * Event markup.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Support\Diff;
use SigmaRocks\AdvanceHistory\Support\Time;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders events for the journal, dashboard, and editor panel.
 */
class Renderer {

	/**
	 * Actor label.
	 *
	 * @param array $row Event row.
	 * @return string
	 */
	public static function actor( array $row ) {
		if ( ! empty( $row['user_id'] ) ) {
			$name = '' !== $row['user_display'] ? $row['user_display'] : $row['user_login'];
			return $name;
		}
		$initiators = Vocabulary::initiators();
		$key        = isset( $row['initiator'] ) ? $row['initiator'] : 'anonymous';
		return isset( $initiators[ $key ] ) ? $initiators[ $key ] : $key;
	}

	/**
	 * Profile image for the person who caused the event.
	 *
	 * @param array $row  Event row.
	 * @param int   $size Image size in pixels.
	 * @return string
	 */
	public static function avatar( array $row, $size = 36 ) {
		if ( ! function_exists( 'get_avatar' ) ) {
			return '';
		}

		$size    = max( 16, min( 96, (int) $size ) );
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $user_id < 1 && ! empty( $row['user_login'] ) && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'login', $row['user_login'] );
			if ( $user instanceof \WP_User ) {
				$user_id = (int) $user->ID;
			}
		}

		$args = array(
			'class'         => 'ah-avatar',
			'force_display' => true,
		);
		if ( $user_id < 1 ) {
			$args['force_default'] = true;
			$user_id               = 'advance-history@invalid';
		}

		return get_avatar( $user_id, $size, '', '', $args );
	}

	/**
	 * One timeline card.
	 *
	 * @param array $row  Event row.
	 * @param array $args Display arguments.
	 */
	public static function card( array $row, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'checkbox' => false,
				'compact'  => false,
			)
		);
		$severity = isset( $row['severity'] ) ? $row['severity'] : 'info';
		$category = isset( $row['category'] ) ? $row['category'] : 'system';
		$when     = Time::display( isset( $row['created_gmt'] ) ? $row['created_gmt'] : '' );
		$id       = isset( $row['id'] ) ? (int) $row['id'] : 0;
		?>
		<article class="ah-event ah-severity-<?php echo esc_attr( $severity ); ?>" id="ah-event-<?php echo esc_attr( (string) $id ); ?>">
			<div class="ah-event-avatar" aria-hidden="true"><?php echo self::avatar( $row, empty( $args['compact'] ) ? 36 : 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() escapes. ?></div>
			<div class="ah-event-body">
				<header class="ah-event-top">
					<?php if ( $args['checkbox'] && $id ) : ?>
						<label class="ah-check">
							<input class="ah-event-check" type="checkbox" name="event_ids[]" value="<?php echo esc_attr( (string) $id ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Select event', 'advance-history' ); ?></span>
						</label>
					<?php endif; ?>
					<span class="ah-pill"><?php echo esc_html( Vocabulary::category_label( $category ) ); ?></span>
					<span class="ah-pill ah-pill-<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( Vocabulary::severity_label( $severity ) ); ?></span>
					<?php $repeats = isset( $row['repeat_count'] ) ? (int) $row['repeat_count'] : 1; ?>
					<?php if ( $repeats > 1 ) : ?>
						<span class="ah-pill ah-pill-repeat">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of times this event happened. */
									_n( '%d time', '%d times', $repeats, 'advance-history' ),
									$repeats
								)
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $row['initiator'] ) && 'user' !== $row['initiator'] && ! empty( $row['user_id'] ) ) : ?>
						<span class="ah-pill"><?php echo esc_html( Vocabulary::initiators()[ $row['initiator'] ] ?? $row['initiator'] ); ?></span>
					<?php endif; ?>
					<time datetime="<?php echo esc_attr( $when['iso'] ); ?>" title="<?php echo esc_attr( $when['local'] ); ?>"><?php echo esc_html( $when['primary'] ); ?></time>
				</header>
				<h3 class="ah-event-summary" id="ah-summary-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $row['summary'] ); ?></h3>
				<p class="ah-event-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: person or system that performed the action. */
							__( 'By %s', 'advance-history' ),
							self::actor( $row )
						)
					);
					?>
					<?php if ( ! empty( $row['object_title'] ) ) : ?>
						<span aria-hidden="true"> · </span>
						<?php echo esc_html( $row['object_title'] ); ?>
					<?php endif; ?>
					<?php
					$link = self::object_link( $row );
					if ( $link ) :
						?>
						<span aria-hidden="true"> · </span>
						<a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open', 'advance-history' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $row['ip_masked'] ) ) : ?>
						<span aria-hidden="true"> · </span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: masked IP address. */
								__( 'Network %s', 'advance-history' ),
								$row['ip_masked']
							)
						);
						?>
					<?php endif; ?>
				</p>
				<?php if ( empty( $args['compact'] ) ) : ?>
					<?php self::details( $row ); ?>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Detail disclosure.
	 *
	 * @param array $row Event row.
	 */
	private static function details( array $row ) {
		$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
		$changes     = isset( $context['changes'] ) && is_array( $context['changes'] ) ? $context['changes'] : array();
		$revision_id = isset( $context['revision_id'] ) ? absint( $context['revision_id'] ) : 0;
		$post_id     = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
		unset( $context['changes'], $context['repeat_count'], $context['repeat_times'], $context['first_gmt'], $context['last_gmt'] );
		$has_patch   = false;
		foreach ( $changes as $change ) {
			if ( self::change_lines( $change, $post_id, $revision_id ) ) {
				$has_patch = true;
				break;
			}
		}
		$repeats = isset( $row['repeat_count'] ) ? (int) $row['repeat_count'] : 1;
		$times   = isset( $row['repeat_times'] ) && is_array( $row['repeat_times'] ) ? $row['repeat_times'] : array();
		?>
		<details class="ah-details"<?php echo $has_patch ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Details', 'advance-history' ); ?></summary>
			<?php if ( $repeats > 1 ) : ?>
				<p class="ah-event-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of repeated events. */
							_n( 'Combined %d matching event.', 'Combined %d matching events.', $repeats, 'advance-history' ),
							$repeats
						)
					);
					?>
				</p>
				<?php if ( count( $times ) > 1 ) : ?>
					<ul class="ah-repeat-times">
						<?php foreach ( $times as $gmt ) : ?>
							<li><time datetime="<?php echo esc_attr( Time::display( $gmt )['iso'] ); ?>"><?php echo esc_html( Time::display( $gmt )['local'] ); ?></time></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
			<dl class="ah-facts">
				<div>
					<dt><?php esc_html_e( 'When', 'advance-history' ); ?></dt>
					<dd><?php echo esc_html( Time::display( $row['created_gmt'] )['local'] ); ?> UTC <?php echo esc_html( $row['created_gmt'] ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Action', 'advance-history' ); ?></dt>
					<dd><code><?php echo esc_html( $row['action'] ); ?></code></dd>
				</div>
				<?php if ( ! empty( $row['user_login'] ) ) : ?>
					<div>
						<dt><?php esc_html_e( 'Username', 'advance-history' ); ?></dt>
						<dd><?php echo esc_html( $row['user_login'] ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>
			<?php
			$plain_changes = array();
			$patches       = array();
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) ) {
					continue;
				}
				$lines = self::change_lines( $change, $post_id, $revision_id );
				if ( $lines ) {
					$patches[] = array(
						'lines' => $lines,
						'field' => isset( $change['field'] ) ? (string) $change['field'] : '',
					);
					continue;
				}
				$plain_changes[] = $change;
			}
			?>
			<?php if ( $plain_changes ) : ?>
				<table class="ah-changes">
					<caption><?php esc_html_e( 'What changed', 'advance-history' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Field', 'advance-history' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Before', 'advance-history' ); ?></th>
							<th scope="col"><?php esc_html_e( 'After', 'advance-history' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plain_changes as $change ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( isset( $change['field'] ) ? (string) $change['field'] : '' ); ?></th>
								<td><?php self::change_value( $change, 'from' ); ?></td>
								<td><?php self::change_value( $change, 'to' ); ?></td>
							</tr>
							<?php if ( ! empty( $change['added'] ) || ! empty( $change['removed'] ) ) : ?>
								<tr>
									<td colspan="3">
										<?php if ( ! empty( $change['removed'] ) && is_array( $change['removed'] ) ) : ?>
											<p><span class="ah-diff-label"><?php esc_html_e( 'Removed words', 'advance-history' ); ?></span> <?php echo esc_html( implode( ' ', $change['removed'] ) ); ?></p>
										<?php endif; ?>
										<?php if ( ! empty( $change['added'] ) && is_array( $change['added'] ) ) : ?>
											<p><span class="ah-diff-label"><?php esc_html_e( 'Added words', 'advance-history' ); ?></span> <?php echo esc_html( implode( ' ', $change['added'] ) ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php foreach ( $patches as $patch ) : ?>
				<?php self::patch( $patch['lines'], $patch['field'] ); ?>
			<?php endforeach; ?>
			<?php if ( $context ) : ?>
				<dl class="ah-facts">
					<?php foreach ( $context as $key => $value ) : ?>
						<?php if ( is_array( $value ) || is_object( $value ) ) { $value = wp_json_encode( $value ); } ?>
						<div>
							<dt><?php echo esc_html( (string) $key ); ?></dt>
							<dd><?php echo esc_html( (string) $value ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( ! empty( $row['id'] ) ) : ?>
				<p>
					<button type="button" class="button-link" data-ah-copy="ah-summary-<?php echo esc_attr( (string) $row['id'] ); ?>">
						<?php esc_html_e( 'Copy summary', 'advance-history' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * A before/after cell, with a thumbnail when the value is an attachment.
	 *
	 * @param array  $change Change row.
	 * @param string $side   from or to.
	 */
	private static function change_value( array $change, $side ) {
		$attachment_key = $side . '_attachment';
		$attachment_id  = isset( $change[ $attachment_key ] ) ? absint( $change[ $attachment_key ] ) : 0;
		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			echo wp_get_attachment_image( $attachment_id, array( 64, 64 ), false, array( 'class' => 'ah-thumb' ) );
		}
		echo esc_html( isset( $change[ $side ] ) ? (string) $change[ $side ] : '' );
	}

	/**
	 * Stored line diff, or one rebuilt from the post's revisions.
	 *
	 * @param mixed $change      Change row.
	 * @param int   $post_id     Post ID.
	 * @param int   $revision_id Revision recorded with the event.
	 * @return array
	 */
	private static function change_lines( $change, $post_id, $revision_id ) {
		if ( ! is_array( $change ) ) {
			return array();
		}
		if ( ! empty( $change['lines'] ) && is_array( $change['lines'] ) ) {
			return $change['lines'];
		}
		$has_words = ( ! empty( $change['added'] ) && is_array( $change['added'] ) ) || ( ! empty( $change['removed'] ) && is_array( $change['removed'] ) );
		if ( ! $has_words || ! $post_id || ! $revision_id ) {
			return array();
		}
		return self::revision_hunks( $post_id, $revision_id );
	}

	/**
	 * Diff the recorded revision against the revision just before it.
	 *
	 * On current WordPress the revision saved after an update matches the new text.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id Revision ID stored on the event.
	 * @return array
	 */
	private static function revision_hunks( $post_id, $revision_id ) {
		static $cache = array();
		$key = $post_id . ':' . $revision_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$cache[ $key ] = array();
		if ( ! function_exists( 'wp_get_post_revisions' ) ) {
			return $cache[ $key ];
		}

		$current = get_post( $revision_id );
		if ( ! $current instanceof \WP_Post ) {
			return $cache[ $key ];
		}
		if ( $current->post_parent ) {
			$post_id = (int) $current->post_parent;
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $revisions ) || ! isset( $revisions[ $revision_id ] ) ) {
			return $cache[ $key ];
		}

		$before = null;
		foreach ( $revisions as $revision ) {
			if ( (int) $revision->ID === (int) $revision_id ) {
				break;
			}
			$before = $revision;
		}
		if ( ! $before instanceof \WP_Post ) {
			return $cache[ $key ];
		}

		$cache[ $key ] = Diff::hunks( $before->post_content, $current->post_content );
		return $cache[ $key ];
	}

	/**
	 * GitHub-style added and removed lines.
	 *
	 * @param array  $lines Encoded diff rows.
	 * @param string $field Field label.
	 */
	private static function patch( array $lines, $field ) {
		if ( '' === $field ) {
			$field = __( 'Content', 'advance-history' );
		}
		?>
		<div class="ah-patch">
			<div class="ah-patch-file"><?php echo esc_html( $field ); ?></div>
			<div class="ah-patch-body">
				<?php foreach ( $lines as $line ) : ?>
					<?php
					if ( ! is_scalar( $line ) ) {
						continue;
					}
					$decoded = Diff::decode( (string) $line );
					$op      = $decoded[0];
					$text    = $decoded[1];
					if ( 'note' === $op && 'gap' === $text ) :
						?>
						<div class="ah-patch-gap"><span class="screen-reader-text"><?php esc_html_e( 'Unchanged lines omitted', 'advance-history' ); ?></span><span aria-hidden="true">···</span></div>
						<?php
						continue;
					endif;
					if ( 'note' === $op ) :
						?>
						<p class="ah-patch-note"><?php esc_html_e( 'More of this edit was left out.', 'advance-history' ); ?></p>
						<?php
						continue;
					endif;
					if ( 'del' === $op ) {
						$class = 'ah-patch-del';
						$sign  = '−';
						$label = __( 'Removed line', 'advance-history' );
					} elseif ( 'add' === $op ) {
						$class = 'ah-patch-add';
						$sign  = '+';
						$label = __( 'Added line', 'advance-history' );
					} else {
						$class = 'ah-patch-ctx';
						$sign  = ' ';
						$label = __( 'Unchanged line', 'advance-history' );
					}
					?>
					<div class="ah-patch-line <?php echo esc_attr( $class ); ?>">
						<span class="ah-patch-gutter" aria-hidden="true"><?php echo esc_html( $sign ); ?></span>
						<span class="ah-patch-text"><span class="screen-reader-text"><?php echo esc_html( $label ); ?> </span><?php echo esc_html( $text ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Admin link for a known object.
	 *
	 * @param array $row Event row.
	 * @return string
	 */
	private static function object_link( array $row ) {
		$type = isset( $row['object_type'] ) ? $row['object_type'] : '';
		$id   = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		if ( 'content' === $type && $id && current_user_can( 'edit_post', $id ) ) {
			$link = get_edit_post_link( $id, 'raw' );
			return is_string( $link ) ? $link : '';
		}
		if ( 'attachment' === $type && $id && current_user_can( 'upload_files' ) ) {
			$link = get_edit_post_link( $id, 'raw' );
			return is_string( $link ) ? $link : '';
		}
		if ( 'user' === $type && $id && current_user_can( 'edit_user', $id ) ) {
			return get_edit_user_link( $id );
		}
		if ( ! empty( $row['context']['revision_id'] ) && ! empty( $row['object_id'] ) && current_user_can( 'edit_post', (int) $row['object_id'] ) ) {
			return admin_url( 'revision.php?revision=' . absint( $row['context']['revision_id'] ) );
		}
		return '';
	}
}
