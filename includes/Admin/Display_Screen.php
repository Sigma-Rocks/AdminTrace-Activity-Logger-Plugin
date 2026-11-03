<?php
/**
 * Display settings.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile photo and related display choices.
 */
class Display_Screen {

	/**
	 * Render the display page.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ), 403 );
		}
		$settings = Settings::all();
		?>
		<div class="wrap srah-wrap">
			<?php Notices::render(); ?>
			<header class="srah-hero">
				<div class="srah-brand">
					<?php echo wp_kses_post( Renderer::mark() ); ?>
					<div>
						<h1><?php esc_html_e( 'Display', 'admintrace-activity-logger' ); ?></h1>
						<p><?php esc_html_e( 'How people are shown in the journal. Each row also stores the acting user’s role and the time to the millisecond.', 'admintrace-activity-logger' ); ?></p>
					</div>
				</div>
			</header>
			<?php Screen::nav(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="srah-settings">
				<?php wp_nonce_field( 'srah_save_settings' ); ?>
				<input type="hidden" name="action" value="srah_save_settings">
				<input type="hidden" name="settings_section" value="display">
				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Profile photos', 'admintrace-activity-logger' ); ?></h2>
						<p><?php esc_html_e( 'Initials are drawn on this site and do not contact another service.', 'admintrace-activity-logger' ); ?></p>
					</header>
					<div class="srah-toggles">
						<label class="srah-toggle"><input type="checkbox" name="use_gravatar" value="1" <?php checked( $settings['use_gravatar'] ); ?>> <span><strong><?php esc_html_e( 'Load profile photos from Gravatar', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'The journal then requests gravatar.com for accounts that have a photo. Leave this off to keep the drawn initials.', 'admintrace-activity-logger' ); ?></span></span></label>
					</div>
				</section>
				<div class="srah-savebar">
					<?php submit_button( __( 'Save display settings', 'admintrace-activity-logger' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
			<?php Screen::credit(); ?>
		</div>
		<?php
	}
}
