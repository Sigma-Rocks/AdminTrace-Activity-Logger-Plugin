<?php
/**
 * Email log screen.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Email_Log;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists saved outgoing email and the enable switch.
 */
class Email_Log_Screen {

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ), 403 );
		}

		$open = isset( $_GET['email'] ) ? absint( wp_unslash( $_GET['email'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opens the preview popup for one row.
		self::list_screen( $open );
	}

	/**
	 * Preview JSON for the email popup.
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'srah_email_preview' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ) ),
				403
			);
		}

		$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() verified the nonce.
		$row = Email_Log::find( $id );
		if ( ! is_array( $row ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That email is not in the log.', 'admintrace-activity-logger' ) ),
				404
			);
		}

		$when   = Time::display( $row['sent_gmt'] );
		$local  = isset( $when['local'] ) ? (string) $when['local'] : '';
		if ( '.000' === substr( $local, -4 ) ) {
			$local = substr( $local, 0, -4 );
		}
		$status = isset( $row['status'] ) ? (string) $row['status'] : 'pending';
		$labels = self::statuses();
		wp_send_json_success(
			array(
				'id'      => (int) $row['id'],
				'subject' => '' !== $row['subject'] ? $row['subject'] : __( '(No subject)', 'admintrace-activity-logger' ),
				'to'      => $row['to_email'],
				'when'    => $local,
				'status'  => isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['pending'],
				'error'   => $row['error_message'],
				'files'   => $row['attachments'],
				'headers' => $row['headers'],
				'sender'  => $row['user_login'],
				'html'    => self::is_html( $row['headers'], $row['message'] ),
				'message' => $row['message'],
			)
		);
	}

	/**
	 * Enable switch and the saved emails.
	 *
	 * @param int $open_id Email to preview when the page loads.
	 */
	private static function list_screen( $open_id = 0 ) {
		$settings   = Settings::all();
		$filters    = self::filters();
		$result     = Email_Log::search(
			array(
				's'        => $filters['s'],
				'status'   => $filters['status'],
				'page'     => $filters['paged'],
				'per_page' => (int) $settings['per_page'],
			)
		);
		$can_delete = current_user_can( Capabilities::DELETE );
		?>
		<div class="wrap srah-wrap">
			<?php Notices::render(); ?>
			<header class="srah-hero">
				<div class="srah-brand">
					<?php echo wp_kses_post( Renderer::mark() ); ?>
					<div>
						<h1><?php esc_html_e( 'Email log', 'admintrace-activity-logger' ); ?></h1>
						<p>
							<?php
							if ( '' !== $filters['s'] || '' !== $filters['status'] ) {
								echo esc_html(
									sprintf(
										/* translators: %d: number of matching emails. */
										_n( '%d saved email matches these filters.', '%d saved emails match these filters.', $result['total'], 'admintrace-activity-logger' ),
										$result['total']
									)
								);
							} else {
								echo esc_html(
									sprintf(
										/* translators: %d: number of saved emails. */
										_n( '%d email saved in its own table, separate from the activity journal.', '%d emails saved in their own table, separate from the activity journal.', $result['total'], 'admintrace-activity-logger' ),
										$result['total']
									)
								);
							}
							?>
						</p>
					</div>
				</div>
			</header>
			<?php Screen::nav(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="srah-settings">
				<?php wp_nonce_field( 'srah_save_settings' ); ?>
				<input type="hidden" name="action" value="srah_save_settings">
				<input type="hidden" name="settings_section" value="email_log">
				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Save outgoing email', 'admintrace-activity-logger' ); ?></h2>
						<p><?php esc_html_e( 'When this is on, WordPress keeps the recipient, subject, and body of each email it sends. Messages can include password-reset links and other private text. The activity journal does not receive those bodies.', 'admintrace-activity-logger' ); ?></p>
					</header>
					<div class="srah-toggles">
						<label class="srah-toggle"><input type="checkbox" name="email_log_enabled" value="1" <?php checked( $settings['email_log_enabled'] ); ?>> <span><strong><?php esc_html_e( 'Enable email log', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php echo esc_html( self::retention_note() ); ?></span></span></label>
					</div>
				</section>
				<div class="srah-savebar">
					<?php submit_button( __( 'Save email log', 'admintrace-activity-logger' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<form class="srah-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( Email_Log::PAGE ); ?>">
				<div class="srah-filter-grid">
					<p>
						<label for="srah-email-search"><?php esc_html_e( 'Search', 'admintrace-activity-logger' ); ?></label>
						<input id="srah-email-search" type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Recipient or subject', 'admintrace-activity-logger' ); ?>">
					</p>
					<p>
						<label for="srah-email-status"><?php esc_html_e( 'Result', 'admintrace-activity-logger' ); ?></label>
						<select id="srah-email-status" name="status">
							<option value=""><?php esc_html_e( 'Any result', 'admintrace-activity-logger' ); ?></option>
							<?php foreach ( self::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="srah-filter-actions">
						<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'admintrace-activity-logger' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Email_Log::PAGE ) ); ?>"><?php esc_html_e( 'Reset', 'admintrace-activity-logger' ); ?></a>
					</p>
				</div>
			</form>

			<?php
			if ( $can_delete && $result['rows'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'srah_email_delete' );
				echo '<input type="hidden" name="action" value="srah_email_delete">';
				echo '<div class="srah-bulk">';
				echo '<button class="button" type="submit" data-srah-confirm="' . esc_attr__( 'Delete the selected emails? This cannot be undone.', 'admintrace-activity-logger' ) . '">' . esc_html__( 'Delete selected', 'admintrace-activity-logger' ) . '</button></div>';
			}

			if ( ! $result['rows'] ) {
				echo '<div class="srah-empty"><h2>' . esc_html__( 'No emails saved', 'admintrace-activity-logger' ) . '</h2><p>';
				if ( '' !== $filters['s'] || '' !== $filters['status'] ) {
					esc_html_e( 'No saved emails match these filters.', 'admintrace-activity-logger' );
				} elseif ( empty( $settings['email_log_enabled'] ) ) {
					esc_html_e( 'Turn the email log on, then send a message from WordPress. It will appear here.', 'admintrace-activity-logger' );
				} else {
					esc_html_e( 'Emails WordPress sends from now on will appear here.', 'admintrace-activity-logger' );
				}
				echo '</p></div>';
			} else {
				echo '<div class="srah-table-wrap">';
				self::table( $result['rows'], $can_delete );
				echo '</div>';
				self::pagination( $filters, (int) $result['pages'] );
			}

			if ( $can_delete && $result['rows'] ) {
				echo '</form>';
			}
			?>

			<?php if ( $can_delete ) : ?>
				<section class="srah-panel srah-maintenance">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Check and clear', 'admintrace-activity-logger' ); ?></h2>
						<p><?php esc_html_e( 'Send a message through WordPress, then find it in the list above. Clearing removes saved emails on this site. The activity journal stays.', 'admintrace-activity-logger' ); ?></p>
					</header>
					<div class="srah-maintenance-row">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'srah_email_test' ); ?>
						<input type="hidden" name="action" value="srah_email_test">
						<?php submit_button( __( 'Send a test email', 'admintrace-activity-logger' ), 'secondary', 'submit', false ); ?>
					</form>
					<form class="srah-danger" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'srah_email_delete_all' ); ?>
						<input type="hidden" name="action" value="srah_email_delete_all">
						<label class="srah-danger-check"><input type="checkbox" name="confirm_delete_email_log" value="1" required> <span><?php esc_html_e( 'Delete every saved email on this site', 'admintrace-activity-logger' ); ?></span></label>
						<?php submit_button( __( 'Clear email log', 'admintrace-activity-logger' ), 'delete', 'submit', false ); ?>
					</form>
					</div>
				</section>
			<?php endif; ?>
			<div class="srah-modal" id="srah-email-modal" hidden<?php echo $open_id ? ' data-srah-email-open="' . esc_attr( (string) $open_id ) . '"' : ''; ?>>
				<div class="srah-modal-backdrop" data-srah-email-close></div>
				<div class="srah-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="srah-email-modal-title">
					<header class="srah-modal-head">
						<h2 id="srah-email-modal-title"><?php esc_html_e( 'Email preview', 'admintrace-activity-logger' ); ?></h2>
						<button type="button" class="srah-modal-close" data-srah-email-close aria-label="<?php esc_attr_e( 'Close', 'admintrace-activity-logger' ); ?>">&times;</button>
					</header>
					<div class="srah-modal-meta" id="srah-email-modal-meta"></div>
					<div class="srah-email-tabs" id="srah-email-tabs" role="tablist" hidden>
						<button type="button" class="srah-email-tab is-active" role="tab" aria-selected="true" data-srah-email-tab="rich"><?php esc_html_e( 'Rich email', 'admintrace-activity-logger' ); ?></button>
						<button type="button" class="srah-email-tab" role="tab" aria-selected="false" data-srah-email-tab="html"><?php esc_html_e( 'HTML', 'admintrace-activity-logger' ); ?></button>
					</div>
					<div class="srah-modal-preview" id="srah-email-modal-preview">
						<p class="srah-email-status" id="srah-email-status" hidden></p>
						<iframe class="srah-email-frame" title="<?php esc_attr_e( 'Email preview', 'admintrace-activity-logger' ); ?>" sandbox="allow-same-origin" referrerpolicy="no-referrer" hidden></iframe>
						<pre class="srah-email-plain" hidden></pre>
						<pre class="srah-email-source" hidden></pre>
					</div>
					<details class="srah-modal-headers" id="srah-email-modal-headers" hidden>
						<summary><?php esc_html_e( 'Headers', 'admintrace-activity-logger' ); ?></summary>
						<pre></pre>
					</details>
					<div class="srah-modal-foot" id="srah-email-actions" hidden>
						<p class="srah-resend-note" id="srah-email-resend-note" hidden><?php esc_html_e( 'File attachments are not sent again. Only the message is resent.', 'admintrace-activity-logger' ); ?></p>
						<div class="srah-modal-buttons">
							<form id="srah-email-resend" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-srah-resend-confirm="<?php echo esc_attr__( 'Send this email again to the same recipient?', 'admintrace-activity-logger' ); ?>">
								<?php wp_nonce_field( 'srah_email_resend' ); ?>
								<input type="hidden" name="action" value="srah_email_resend">
								<input type="hidden" name="email_id" value="">
								<?php submit_button( __( 'Resend email', 'admintrace-activity-logger' ), 'primary', 'submit', false, array( 'id' => 'srah-email-resend-button', 'data-srah-confirm' => __( 'Send this email again to the same recipient?', 'admintrace-activity-logger' ) ) ); ?>
							</form>
							<?php if ( $can_delete ) : ?>
								<form id="srah-email-delete" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'srah_email_delete' ); ?>
									<input type="hidden" name="action" value="srah_email_delete">
									<input type="hidden" name="email_ids[]" value="">
									<?php submit_button( __( 'Delete this email', 'admintrace-activity-logger' ), 'delete', 'submit', false, array( 'id' => 'srah-email-delete-button', 'data-srah-confirm' => __( 'Delete this email? This cannot be undone.', 'admintrace-activity-logger' ) ) ); ?>
								</form>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php Screen::credit(); ?>
		</div>
		<?php
	}

	/**
	 * Saved-email table.
	 *
	 * @param array $rows       Rows.
	 * @param bool  $checkboxes Whether rows can be selected.
	 */
	private static function table( array $rows, $checkboxes ) {
		echo '<table class="widefat striped srah-table srah-email-table"><thead><tr>';
		if ( $checkboxes ) {
			echo '<td class="check-column"><label><span class="screen-reader-text">' . esc_html__( 'Select all on this page', 'admintrace-activity-logger' ) . '</span><input type="checkbox" data-srah-select-all></label></td>';
		}
		echo '<th scope="col">' . esc_html__( 'When', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'To', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Subject', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Files', 'admintrace-activity-logger' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$when    = Time::display( $row['sent_gmt'] );
			$subject = '' !== $row['subject'] ? $row['subject'] : __( '(No subject)', 'admintrace-activity-logger' );
			echo '<tr>';
			if ( $checkboxes ) {
				echo '<th scope="row" class="check-column"><label><span class="screen-reader-text">' . esc_html(
					sprintf(
						/* translators: %s: email subject. */
						__( 'Select: %s', 'admintrace-activity-logger' ),
						$subject
					)
				) . '</span><input class="srah-event-check" type="checkbox" name="email_ids[]" value="' . esc_attr( (string) $row['id'] ) . '"></label></th>';
			}
			echo '<td><time datetime="' . esc_attr( $when['iso'] ) . '">' . esc_html( $when['primary'] ) . '</time></td>';
			echo '<td>' . esc_html( $row['to_email'] ) . '</td>';
			echo '<td><button type="button" class="srah-email-open" data-srah-email="' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $subject ) . '</button></td>';
			echo '<td>' . wp_kses_post( self::status_html( $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $row['attachments'] ? $row['attachments'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Page links.
	 *
	 * @param array $filters Filters.
	 * @param int   $pages   Total pages.
	 */
	private static function pagination( array $filters, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		$base  = add_query_arg(
			array(
				'page'   => Email_Log::PAGE,
				's'      => $filters['s'],
				'status' => $filters['status'],
			),
			admin_url( 'admin.php' )
		);
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $base ),
				'format'    => '',
				'current'   => $filters['paged'],
				'total'     => $pages,
				'type'      => 'plain',
				'prev_text' => __( 'Previous', 'admintrace-activity-logger' ),
				'next_text' => __( 'Next', 'admintrace-activity-logger' ),
			)
		);
		if ( $links ) {
			echo '<nav class="srah-pager" aria-label="' . esc_attr__( 'Email log pages', 'admintrace-activity-logger' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * Current list filters.
	 *
	 * @return array{s:string,status:string,paged:int}
	 */
	private static function filters() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter form.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter form.
		if ( ! isset( self::statuses()[ $status ] ) ) {
			$status = '';
		}
		return array(
			's'      => $search,
			'status' => $status,
			'paged'  => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page number.
		);
	}

	/**
	 * Result labels.
	 *
	 * @return array<string,string>
	 */
	private static function statuses() {
		return array(
			'sent'    => __( 'Sent', 'admintrace-activity-logger' ),
			'failed'  => __( 'Failed', 'admintrace-activity-logger' ),
			'pending' => __( 'Not confirmed', 'admintrace-activity-logger' ),
		);
	}

	/**
	 * Result pill.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private static function status_html( $status ) {
		$labels = self::statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['pending'];
		$class  = 'srah-pill';
		if ( 'failed' === $status ) {
			$class .= ' srah-pill-critical';
		} elseif ( 'sent' === $status ) {
			$class .= ' srah-pill-notice';
		} else {
			$class .= ' srah-pill-warning';
		}
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * How long saved emails stay.
	 *
	 * @return string
	 */
	private static function retention_note() {
		$days = (int) Settings::all()['retention_days'];
		if ( $days <= 0 ) {
			return __( 'Saved emails stay until you delete them.', 'admintrace-activity-logger' );
		}
		return sprintf(
			/* translators: %d: number of days. */
			_n( 'Saved emails follow the journal retention period: %d day.', 'Saved emails follow the journal retention period: %d days.', $days, 'admintrace-activity-logger' ),
			$days
		);
	}

	/**
	 * Whether the body should be shown as HTML.
	 *
	 * @param string $headers Message headers.
	 * @param string $message Message body.
	 * @return bool
	 */
	private static function is_html( $headers, $message ) {
		$headers = strtolower( (string) $headers );
		if ( false !== strpos( $headers, 'text/html' ) ) {
			return true;
		}
		if ( false !== strpos( $headers, 'text/plain' ) ) {
			return false;
		}
		return 1 === preg_match( '/<(html|body|table|div|p|br|span|a|img|h[1-6]|ul|ol|style|strong|em)\b/i', (string) $message );
	}
}
