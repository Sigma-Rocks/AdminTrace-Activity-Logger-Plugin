<?php
/**
 * Settings screen.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Feed;
use SigmaRocks\AdvanceHistory\Logger_Registry;
use SigmaRocks\AdvanceHistory\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings.
 */
class Settings_Screen {

	/**
	 * Render settings.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Advance History.', 'advance-history' ), 403 );
		}

		$settings = Settings::all();
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		?>
		<div class="wrap ah-wrap">
			<?php Notices::render(); ?>
			<header class="ah-hero">
				<div class="ah-brand">
					<span class="ah-mark" aria-hidden="true">AH</span>
					<div>
						<h1><?php esc_html_e( 'Advance History settings', 'advance-history' ); ?></h1>
						<p><?php esc_html_e( 'Choose what is recorded, who can read it, and how long it stays.', 'advance-history' ); ?></p>
					</div>
				</div>
			</header>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ah-settings">
				<?php wp_nonce_field( 'advance_history_save_settings' ); ?>
				<input type="hidden" name="action" value="advance_history_save_settings">

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Retention and privacy', 'advance-history' ); ?></h2>
					<p>
						<label for="ah-retention"><?php esc_html_e( 'Keep events for this many days', 'advance-history' ); ?></label>
						<input id="ah-retention" type="number" min="0" max="3650" name="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Use 0 to keep events until you delete them. A daily task removes older events.', 'advance-history' ); ?></p>
					<p>
						<label for="ah-ip"><?php esc_html_e( 'Network addresses', 'advance-history' ); ?></label>
						<select id="ah-ip" name="ip_mode">
							<option value="masked" <?php selected( $settings['ip_mode'], 'masked' ); ?>><?php esc_html_e( 'Store a masked address', 'advance-history' ); ?></option>
							<option value="off" <?php selected( $settings['ip_mode'], 'off' ); ?>><?php esc_html_e( 'Do not store addresses', 'advance-history' ); ?></option>
						</select>
					</p>
					<p class="description"><?php esc_html_e( 'Full IP addresses are never stored. Masking removes the last part of an IPv4 address and keeps only the first half of an IPv6 address. Advance History does not look addresses up with an outside service.', 'advance-history' ); ?></p>
					<p><label><input type="checkbox" name="trust_proxy" value="1" <?php checked( $settings['trust_proxy'] ); ?>> <?php esc_html_e( 'This site is behind a trusted proxy', 'advance-history' ); ?></label></p>
					<p><label><input type="checkbox" name="log_failed_logins" value="1" <?php checked( $settings['log_failed_logins'] ); ?>> <?php esc_html_e( 'Record failed logins', 'advance-history' ); ?></label></p>
					<p><label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete the journal and settings when the plugin is uninstalled', 'advance-history' ); ?></label></p>
				</section>

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Who can view', 'advance-history' ); ?></h2>
					<p><label><input type="checkbox" name="editors_can_view_content" value="1" <?php checked( $settings['editors_can_view_content'] ); ?>> <?php esc_html_e( 'Let editors and shop managers view content activity', 'advance-history' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'They cannot see security, user, plugin, or setting events, and they cannot see network addresses. Shop managers can also see commerce events.', 'advance-history' ); ?></p>
					<fieldset>
						<legend><?php esc_html_e( 'Skip routine info events from these roles', 'advance-history' ); ?></legend>
						<?php foreach ( $roles as $role => $data ) : ?>
							<label class="ah-role"><input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $settings['excluded_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $data['name'] ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Notices, warnings, and security events are still recorded.', 'advance-history' ); ?></p>
					</fieldset>
					<p>
						<label for="ah-excluded-users"><?php esc_html_e( 'Skip routine info events from these user IDs', 'advance-history' ); ?></label>
						<input id="ah-excluded-users" type="text" name="excluded_user_ids" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) $settings['excluded_user_ids'] ) ) ); ?>" class="regular-text">
					</p>
				</section>

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Recorders', 'advance-history' ); ?></h2>
					<?php foreach ( Logger_Registry::all() as $id => $logger ) : ?>
						<p><label><input type="checkbox" name="loggers[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( ! in_array( $id, (array) $settings['disabled_loggers'], true ) ); ?>> <strong><?php echo esc_html( $logger->label() ); ?></strong><br><span class="description"><?php echo esc_html( $logger->description() ); ?></span></label></p>
					<?php endforeach; ?>
				</section>

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Display', 'advance-history' ); ?></h2>
					<p>
						<label for="ah-menu"><?php esc_html_e( 'Menu location', 'advance-history' ); ?></label>
						<select id="ah-menu" name="menu_location">
							<option value="toplevel" <?php selected( $settings['menu_location'], 'toplevel' ); ?>><?php esc_html_e( 'Its own menu', 'advance-history' ); ?></option>
							<option value="dashboard" <?php selected( $settings['menu_location'], 'dashboard' ); ?>><?php esc_html_e( 'Under Dashboard', 'advance-history' ); ?></option>
							<option value="tools" <?php selected( $settings['menu_location'], 'tools' ); ?>><?php esc_html_e( 'Under Tools', 'advance-history' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ah-view"><?php esc_html_e( 'Default view', 'advance-history' ); ?></label>
						<select id="ah-view" name="default_view">
							<option value="timeline" <?php selected( $settings['default_view'], 'timeline' ); ?>><?php esc_html_e( 'Timeline', 'advance-history' ); ?></option>
							<option value="table" <?php selected( $settings['default_view'], 'table' ); ?>><?php esc_html_e( 'Table', 'advance-history' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ah-per-page"><?php esc_html_e( 'Events per page', 'advance-history' ); ?></label>
						<select id="ah-per-page" name="per_page">
							<?php foreach ( array( 10, 25, 50, 100 ) as $count ) : ?>
								<option value="<?php echo esc_attr( (string) $count ); ?>" <?php selected( (int) $settings['per_page'], $count ); ?>><?php echo esc_html( (string) $count ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><label><input type="checkbox" name="dashboard_widget" value="1" <?php checked( $settings['dashboard_widget'] ); ?>> <?php esc_html_e( 'Show the dashboard widget', 'advance-history' ); ?></label></p>
					<p><label><input type="checkbox" name="admin_bar" value="1" <?php checked( $settings['admin_bar'] ); ?>> <?php esc_html_e( 'Show recent events in the admin bar', 'advance-history' ); ?></label></p>
				</section>

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Email and webhook', 'advance-history' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Messages stay on this site unless you add an email recipient or an HTTPS webhook. Webhooks receive the summary, category, and account name. They do not receive network addresses or passwords.', 'advance-history' ); ?></p>
					<p>
						<label for="ah-digest"><?php esc_html_e( 'Email digest', 'advance-history' ); ?></label>
						<select id="ah-digest" name="email_digest">
							<option value="off" <?php selected( $settings['email_digest'], 'off' ); ?>><?php esc_html_e( 'Off', 'advance-history' ); ?></option>
							<option value="daily" <?php selected( $settings['email_digest'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'advance-history' ); ?></option>
							<option value="weekly" <?php selected( $settings['email_digest'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'advance-history' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ah-digest-email"><?php esc_html_e( 'Digest recipient', 'advance-history' ); ?></label>
						<input id="ah-digest-email" type="email" name="digest_email" value="<?php echo esc_attr( $settings['digest_email'] ); ?>" class="regular-text">
					</p>
					<p class="description"><?php esc_html_e( 'Leave the recipient empty to use the site admin email. Digests are plain text.', 'advance-history' ); ?></p>
					<p><label><input type="checkbox" name="alert_failed_logins" value="1" <?php checked( $settings['alert_failed_logins'] ); ?>> <?php esc_html_e( 'Email when failed logins pass the threshold', 'advance-history' ); ?></label></p>
					<p>
						<label for="ah-threshold"><?php esc_html_e( 'Failed logins before a critical event', 'advance-history' ); ?></label>
						<input id="ah-threshold" type="number" min="3" max="50" name="alert_threshold" value="<?php echo esc_attr( (string) $settings['alert_threshold'] ); ?>">
					</p>
					<p>
						<label for="ah-alert-email"><?php esc_html_e( 'Alert recipient', 'advance-history' ); ?></label>
						<input id="ah-alert-email" type="email" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>" class="regular-text">
					</p>
					<p>
						<label for="ah-webhook"><?php esc_html_e( 'Webhook URL', 'advance-history' ); ?></label>
						<input id="ah-webhook" type="url" name="webhook_url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" class="regular-text" placeholder="https://">
					</p>
					<p>
						<label for="ah-webhook-severity"><?php esc_html_e( 'Send the webhook for', 'advance-history' ); ?></label>
						<select id="ah-webhook-severity" name="webhook_min_severity">
							<?php
							$levels = array(
								'off'      => __( 'Do not send', 'advance-history' ),
								'info'     => __( 'Info and above', 'advance-history' ),
								'notice'   => __( 'Notice and above', 'advance-history' ),
								'warning'  => __( 'Warning and above', 'advance-history' ),
								'critical' => __( 'Critical only', 'advance-history' ),
							);
							foreach ( $levels as $key => $label ) :
								?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['webhook_min_severity'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				</section>

				<section class="ah-panel">
					<h2><?php esc_html_e( 'Private feed', 'advance-history' ); ?></h2>
					<p><label><input type="checkbox" name="feed_enabled" value="1" <?php checked( $settings['feed_enabled'] ); ?>> <?php esc_html_e( 'Enable a private RSS feed', 'advance-history' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'Anyone with the feed address can read recent summaries. The address contains a secret and may be stored in server logs. It is off until you enable it.', 'advance-history' ); ?></p>
					<?php if ( $settings['feed_token'] ) : ?>
						<p>
							<label for="ah-feed"><?php esc_html_e( 'Feed address', 'advance-history' ); ?></label>
							<input id="ah-feed" type="text" readonly class="large-text code" value="<?php echo esc_attr( Feed::url() ); ?>">
						</p>
					<?php endif; ?>
					<p><label><input type="checkbox" name="regenerate_feed_token" value="1"> <?php esc_html_e( 'Generate a new feed secret on save', 'advance-history' ); ?></label></p>
				</section>

				<?php submit_button( __( 'Save settings', 'advance-history' ) ); ?>
			</form>

			<section class="ah-panel">
				<h2><?php esc_html_e( 'Maintenance', 'advance-history' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'advance_history_test_email' ); ?>
					<input type="hidden" name="action" value="advance_history_test_email">
					<?php submit_button( __( 'Send a test digest', 'advance-history' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'advance_history_prune' ); ?>
					<input type="hidden" name="action" value="advance_history_prune">
					<?php submit_button( __( 'Delete events older than the retention period', 'advance-history' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'advance_history_delete_all' ); ?>
					<input type="hidden" name="action" value="advance_history_delete_all">
					<p><label><input type="checkbox" name="confirm_delete_all" value="1" required> <?php esc_html_e( 'I understand this permanently deletes every event on this site.', 'advance-history' ); ?></label></p>
					<?php submit_button( __( 'Delete all events', 'advance-history' ), 'delete', 'submit', false ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Build the settings payload from the current request.
	 *
	 * @return array
	 */
	public static function input_from_request() {
		$post    = wp_unslash( $_POST );
		$enabled = isset( $post['loggers'] ) && is_array( $post['loggers'] ) ? $post['loggers'] : array();
		$enabled = array_map( 'sanitize_key', $enabled );
		$disabled = array();
		foreach ( Logger_Registry::all() as $id => $logger ) {
			unset( $logger );
			if ( ! in_array( $id, $enabled, true ) ) {
				$disabled[] = $id;
			}
		}
		$post['disabled_loggers'] = $disabled;
		return $post;
	}
}
