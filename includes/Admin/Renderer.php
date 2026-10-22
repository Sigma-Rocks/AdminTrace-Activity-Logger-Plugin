<?php
/**
 * Event markup.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

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
			<div class="ah-event-mark" aria-hidden="true"></div>
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
		$changes = isset( $context['changes'] ) && is_array( $context['changes'] ) ? $context['changes'] : array();
		unset( $context['changes'] );
		?>
		<details class="ah-details">
			<summary><?php esc_html_e( 'Details', 'advance-history' ); ?></summary>
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
			<?php if ( $changes ) : ?>
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
						<?php foreach ( $changes as $change ) : ?>
							<?php if ( ! is_array( $change ) ) { continue; } ?>
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
