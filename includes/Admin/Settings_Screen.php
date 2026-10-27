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
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ), 403 );
		}

		$settings = Settings::all();
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		?>
		<div class="wrap srah-wrap">
			<?php Notices::render(); ?>
			<header class="srah-hero">
				<div class="srah-brand">
					<?php echo wp_kses_post( Renderer::mark() ); ?>
					<div>
						<h1><?php esc_html_e( 'AdminTrace Activity Logger settings', 'admintrace-activity-logger' ); ?></h1>
						<p><?php esc_html_e( 'Choose what is recorded, who can read it, and how long it stays.', 'admintrace-activity-logger' ); ?></p>
					</div>
				</div>
			</header>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="srah-settings">
				<?php wp_nonce_field( 'srah_save_settings' ); ?>
				<input type="hidden" name="action" value="srah_save_settings">

				<div class="srah-settings-grid">
					<section class="srah-panel">
						<header class="srah-panel-head">
							<h2><?php esc_html_e( 'Retention and privacy', 'admintrace-activity-logger' ); ?></h2>
							<p><?php esc_html_e( 'How long events stay, and what network detail is kept.', 'admintrace-activity-logger' ); ?></p>
						</header>
						<div class="srah-fields srah-fields-2">
							<p class="srah-field">
								<label for="srah-retention"><?php esc_html_e( 'Keep events (days)', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-retention" type="number" min="0" max="3650" name="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>">
								<span class="description"><?php esc_html_e( 'Use 0 to keep events until you clear the journal. A daily task removes older events.', 'admintrace-activity-logger' ); ?></span>
							</p>
							<p class="srah-field">
								<label for="srah-ip"><?php esc_html_e( 'Network addresses', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-ip" name="ip_mode">
									<option value="masked" <?php selected( $settings['ip_mode'], 'masked' ); ?>><?php esc_html_e( 'Store a masked address', 'admintrace-activity-logger' ); ?></option>
									<option value="off" <?php selected( $settings['ip_mode'], 'off' ); ?>><?php esc_html_e( 'Do not store addresses', 'admintrace-activity-logger' ); ?></option>
								</select>
								<span class="description"><?php esc_html_e( 'Full addresses are never stored, and they are not sent to a lookup service.', 'admintrace-activity-logger' ); ?></span>
							</p>
						</div>
						<div class="srah-toggles">
							<label class="srah-toggle"><input type="checkbox" name="trust_proxy" value="1" <?php checked( $settings['trust_proxy'] ); ?>> <span><strong><?php esc_html_e( 'Trusted proxy', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Use the forwarded address only when this site sits behind a proxy you control.', 'admintrace-activity-logger' ); ?></span></span></label>
							<label class="srah-toggle"><input type="checkbox" name="log_failed_logins" value="1" <?php checked( $settings['log_failed_logins'] ); ?>> <span><strong><?php esc_html_e( 'Record failed logins', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Passwords are never stored.', 'admintrace-activity-logger' ); ?></span></span></label>
							<label class="srah-toggle"><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?>> <span><strong><?php esc_html_e( 'Remove data on uninstall', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Drop the journal and these settings when the plugin is deleted.', 'admintrace-activity-logger' ); ?></span></span></label>
						</div>
					</section>

					<section class="srah-panel">
						<header class="srah-panel-head">
							<h2><?php esc_html_e( 'Who can view', 'admintrace-activity-logger' ); ?></h2>
							<p><?php esc_html_e( 'Administrators always see the full journal.', 'admintrace-activity-logger' ); ?></p>
						</header>
						<div class="srah-toggles">
							<label class="srah-toggle"><input type="checkbox" name="editors_can_view_content" value="1" <?php checked( $settings['editors_can_view_content'] ); ?>> <span><strong><?php esc_html_e( 'Editors and shop managers', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'They see content activity only. Shop managers also see commerce events. Network addresses stay hidden.', 'admintrace-activity-logger' ); ?></span></span></label>
						</div>
						<div class="srah-fields">
							<fieldset class="srah-field">
								<legend><?php esc_html_e( 'Skip routine info events from these roles', 'admintrace-activity-logger' ); ?></legend>
								<div class="srah-roles">
									<?php foreach ( $roles as $role => $data ) : ?>
										<label class="srah-role"><input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $settings['excluded_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $data['name'] ) ); ?></label>
									<?php endforeach; ?>
								</div>
								<span class="description"><?php esc_html_e( 'Notices, warnings, and security events are still recorded.', 'admintrace-activity-logger' ); ?></span>
							</fieldset>
							<p class="srah-field">
								<label for="srah-excluded-users"><?php esc_html_e( 'Also skip these user IDs', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-excluded-users" type="text" name="excluded_user_ids" value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) $settings['excluded_user_ids'] ) ) ); ?>" placeholder="<?php esc_attr_e( 'Example: 4, 12', 'admintrace-activity-logger' ); ?>">
							</p>
						</div>
					</section>

					<section class="srah-panel">
						<header class="srah-panel-head">
							<h2><?php esc_html_e( 'Display', 'admintrace-activity-logger' ); ?></h2>
							<p><?php esc_html_e( 'Where the journal appears in WordPress.', 'admintrace-activity-logger' ); ?></p>
						</header>
						<div class="srah-fields srah-fields-3">
							<p class="srah-field">
								<label for="srah-menu"><?php esc_html_e( 'Menu location', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-menu" name="menu_location">
									<option value="toplevel" <?php selected( $settings['menu_location'], 'toplevel' ); ?>><?php esc_html_e( 'Its own menu', 'admintrace-activity-logger' ); ?></option>
									<option value="dashboard" <?php selected( $settings['menu_location'], 'dashboard' ); ?>><?php esc_html_e( 'Under Dashboard', 'admintrace-activity-logger' ); ?></option>
									<option value="tools" <?php selected( $settings['menu_location'], 'tools' ); ?>><?php esc_html_e( 'Under Tools', 'admintrace-activity-logger' ); ?></option>
								</select>
							</p>
							<p class="srah-field">
								<label for="srah-view"><?php esc_html_e( 'Default view', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-view" name="default_view">
									<option value="timeline" <?php selected( $settings['default_view'], 'timeline' ); ?>><?php esc_html_e( 'Timeline', 'admintrace-activity-logger' ); ?></option>
									<option value="table" <?php selected( $settings['default_view'], 'table' ); ?>><?php esc_html_e( 'Table', 'admintrace-activity-logger' ); ?></option>
								</select>
							</p>
							<p class="srah-field">
								<label for="srah-per-page"><?php esc_html_e( 'Events per page', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-per-page" name="per_page">
									<?php foreach ( array( 10, 25, 50, 100 ) as $count ) : ?>
										<option value="<?php echo esc_attr( (string) $count ); ?>" <?php selected( (int) $settings['per_page'], $count ); ?>><?php echo esc_html( (string) $count ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						</div>
						<div class="srah-toggles">
							<label class="srah-toggle"><input type="checkbox" name="dashboard_widget" value="1" <?php checked( $settings['dashboard_widget'] ); ?>> <span><strong><?php esc_html_e( 'Dashboard widget', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Show a short list of recent events on the Dashboard.', 'admintrace-activity-logger' ); ?></span></span></label>
							<label class="srah-toggle"><input type="checkbox" name="admin_bar" value="1" <?php checked( $settings['admin_bar'] ); ?>> <span><strong><?php esc_html_e( 'Admin bar', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Show recent events from the toolbar.', 'admintrace-activity-logger' ); ?></span></span></label>
						</div>
					</section>

					<section class="srah-panel">
						<header class="srah-panel-head">
							<h2><?php esc_html_e( 'Email and webhook', 'admintrace-activity-logger' ); ?></h2>
							<p><?php esc_html_e( 'Nothing leaves the site until you add a recipient or an HTTPS webhook.', 'admintrace-activity-logger' ); ?></p>
						</header>
						<div class="srah-fields srah-fields-2">
							<p class="srah-field">
								<label for="srah-digest"><?php esc_html_e( 'Email digest', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-digest" name="email_digest">
									<option value="off" <?php selected( $settings['email_digest'], 'off' ); ?>><?php esc_html_e( 'Off', 'admintrace-activity-logger' ); ?></option>
									<option value="daily" <?php selected( $settings['email_digest'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'admintrace-activity-logger' ); ?></option>
									<option value="weekly" <?php selected( $settings['email_digest'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'admintrace-activity-logger' ); ?></option>
								</select>
							</p>
							<p class="srah-field">
								<label for="srah-digest-email"><?php esc_html_e( 'Digest recipient', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-digest-email" type="email" name="digest_email" value="<?php echo esc_attr( $settings['digest_email'] ); ?>" placeholder="<?php esc_attr_e( 'Site admin email', 'admintrace-activity-logger' ); ?>">
							</p>
							<p class="srah-field">
								<label for="srah-threshold"><?php esc_html_e( 'Failed logins before an alert', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-threshold" type="number" min="3" max="50" name="alert_threshold" value="<?php echo esc_attr( (string) $settings['alert_threshold'] ); ?>">
							</p>
							<p class="srah-field">
								<label for="srah-alert-email"><?php esc_html_e( 'Alert recipient', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-alert-email" type="email" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>" placeholder="<?php esc_attr_e( 'Site admin email', 'admintrace-activity-logger' ); ?>">
							</p>
							<p class="srah-field">
								<label for="srah-webhook"><?php esc_html_e( 'Webhook URL', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-webhook" type="url" name="webhook_url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="https://">
							</p>
							<p class="srah-field">
								<label for="srah-webhook-severity"><?php esc_html_e( 'Send the webhook for', 'admintrace-activity-logger' ); ?></label>
								<select id="srah-webhook-severity" name="webhook_min_severity">
									<?php
									$levels = array(
										'off'      => __( 'Do not send', 'admintrace-activity-logger' ),
										'info'     => __( 'Info and above', 'admintrace-activity-logger' ),
										'notice'   => __( 'Notice and above', 'admintrace-activity-logger' ),
										'warning'  => __( 'Warning and above', 'admintrace-activity-logger' ),
										'critical' => __( 'Critical only', 'admintrace-activity-logger' ),
									);
									foreach ( $levels as $key => $label ) :
										?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['webhook_min_severity'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						</div>
						<div class="srah-toggles">
							<label class="srah-toggle"><input type="checkbox" name="alert_failed_logins" value="1" <?php checked( $settings['alert_failed_logins'] ); ?>> <span><strong><?php esc_html_e( 'Email failed-login alerts', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Sent when failed logins pass the number above. The message does not include passwords or full addresses.', 'admintrace-activity-logger' ); ?></span></span></label>
						</div>
					</section>
				</div>

				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Recorders', 'admintrace-activity-logger' ); ?></h2>
						<p><?php esc_html_e( 'Turn a recorder off to stop new events of that kind. Existing events stay in the journal.', 'admintrace-activity-logger' ); ?></p>
					</header>
					<div class="srah-logger-grid">
						<?php foreach ( Logger_Registry::all() as $id => $logger ) : ?>
							<label class="srah-logger">
								<input type="checkbox" name="loggers[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( ! in_array( $id, (array) $settings['disabled_loggers'], true ) ); ?>>
								<span>
									<strong><?php echo esc_html( $logger->label() ); ?></strong>
									<span class="description"><?php echo esc_html( $logger->description() ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Private feed', 'admintrace-activity-logger' ); ?></h2>
						<p><?php esc_html_e( 'A secret address for recent summaries. It stays off until you enable it.', 'admintrace-activity-logger' ); ?></p>
					</header>
					<div class="srah-toggles">
						<label class="srah-toggle"><input type="checkbox" name="feed_enabled" value="1" <?php checked( $settings['feed_enabled'] ); ?>> <span><strong><?php esc_html_e( 'Enable the private feed', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Anyone with the address can read summaries. Server logs may store that address.', 'admintrace-activity-logger' ); ?></span></span></label>
						<label class="srah-toggle"><input type="checkbox" name="regenerate_feed_token" value="1"> <span><strong><?php esc_html_e( 'Replace the feed secret on save', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'The previous address stops working.', 'admintrace-activity-logger' ); ?></span></span></label>
					</div>
					<?php if ( $settings['feed_token'] ) : ?>
						<div class="srah-fields">
							<p class="srah-field">
								<label for="srah-feed"><?php esc_html_e( 'Feed address', 'admintrace-activity-logger' ); ?></label>
								<input id="srah-feed" type="text" readonly value="<?php echo esc_attr( Feed::url() ); ?>">
							</p>
						</div>
					<?php endif; ?>
				</section>

				<div class="srah-savebar">
					<?php submit_button( __( 'Save settings', 'admintrace-activity-logger' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<section class="srah-panel srah-maintenance">
				<header class="srah-panel-head">
					<h2><?php esc_html_e( 'Maintenance', 'admintrace-activity-logger' ); ?></h2>
					<p><?php esc_html_e( 'These actions apply to the whole journal. Individual events cannot be deleted.', 'admintrace-activity-logger' ); ?></p>
				</header>
				<div class="srah-maintenance-row">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'srah_test_email' ); ?>
						<input type="hidden" name="action" value="srah_test_email">
						<?php submit_button( __( 'Send a test digest', 'admintrace-activity-logger' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'srah_prune' ); ?>
						<input type="hidden" name="action" value="srah_prune">
						<?php submit_button( __( 'Apply retention now', 'admintrace-activity-logger' ), 'secondary', 'submit', false ); ?>
					</form>
					<form class="srah-danger" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'srah_delete_all' ); ?>
						<input type="hidden" name="action" value="srah_delete_all">
						<label class="srah-toggle"><input type="checkbox" name="confirm_delete_all" value="1" required> <span><?php esc_html_e( 'Clear every event on this site', 'admintrace-activity-logger' ); ?></span></label>
						<?php submit_button( __( 'Clear journal', 'admintrace-activity-logger' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
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
