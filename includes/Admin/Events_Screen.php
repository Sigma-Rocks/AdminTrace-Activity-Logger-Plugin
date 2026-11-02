<?php
/**
 * Per-event switches.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns individual event IDs on or off.
 */
class Events_Screen {

	/**
	 * Render the events page.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ), 403 );
		}
		$disabled = (array) Settings::all()['disabled_actions'];
		?>
		<div class="wrap srah-wrap">
			<?php Notices::render(); ?>
			<header class="srah-hero">
				<div class="srah-brand">
					<?php echo wp_kses_post( Renderer::mark() ); ?>
					<div>
						<h1><?php esc_html_e( 'Events', 'admintrace-activity-logger' ); ?></h1>
						<p><?php esc_html_e( 'Turn individual event IDs off. Existing journal rows stay. Recorders on the Settings page still turn a whole group off.', 'admintrace-activity-logger' ); ?></p>
					</div>
				</div>
			</header>
			<?php Screen::nav(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="srah-settings">
				<?php wp_nonce_field( 'srah_save_settings' ); ?>
				<input type="hidden" name="action" value="srah_save_settings">
				<input type="hidden" name="settings_section" value="events">
				<?php foreach ( Events::groups() as $group => $actions ) : ?>
					<section class="srah-panel">
						<header class="srah-panel-head">
							<h2><?php echo esc_html( $group ); ?></h2>
						</header>
						<div class="srah-logger-grid">
							<?php foreach ( $actions as $id => $label ) : ?>
								<label class="srah-logger">
									<input type="checkbox" name="actions[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( ! in_array( $id, $disabled, true ) ); ?>>
									<span>
										<strong><?php echo esc_html( $label ); ?></strong>
										<span class="description"><code><?php echo esc_html( $id ); ?></code></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
				<div class="srah-savebar">
					<?php submit_button( __( 'Save events', 'admintrace-activity-logger' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
