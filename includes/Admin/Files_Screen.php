<?php
/**
 * File and database watch settings.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Installer;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for the disk and table scans.
 */
class Files_Screen {

	/**
	 * Render the files page.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AdminTrace Activity Logger.', 'admintrace-activity-logger' ), 403 );
		}
		$settings = Settings::all();
		$ran      = (int) get_option( 'srah_watch_ran', 0 );
		$note     = get_option( 'srah_watch_note', '' );
		$recent   = self::recent();
		?>
		<div class="wrap srah-wrap">
			<?php Notices::render(); ?>
			<header class="srah-hero">
				<div class="srah-brand">
					<?php echo wp_kses_post( Renderer::mark() ); ?>
					<div>
						<h1><?php esc_html_e( 'Files and database', 'admintrace-activity-logger' ); ?></h1>
						<p><?php esc_html_e( 'Each plugin and theme is scanned on its own when you open wp-admin, and when one is installed, activated, or deleted. Scan now checks immediately. File contents are not stored. The first scan only saves a baseline.', 'admintrace-activity-logger' ); ?></p>
					</div>
				</div>
			</header>
			<?php Screen::nav(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="srah-settings">
				<?php wp_nonce_field( 'srah_save_settings' ); ?>
				<input type="hidden" name="action" value="srah_save_settings">
				<input type="hidden" name="settings_section" value="files">
				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'What is scanned', 'admintrace-activity-logger' ); ?></h2>
						<p>
							<?php
							echo esc_html(
								$ran
									? sprintf(
										/* translators: 1: local date and time, 2: scan result. */
										__( 'Last scan: %1$s. %2$s', 'admintrace-activity-logger' ),
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ran ),
										is_string( $note ) ? $note : ''
									)
									: __( 'No scan has run yet.', 'admintrace-activity-logger' )
							);
							?>
						</p>
					</header>
					<div class="srah-toggles">
						<label class="srah-toggle"><input type="checkbox" name="file_watch" value="1" <?php checked( $settings['file_watch'] ); ?>> <span><strong><?php esc_html_e( 'Watch files on disk', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Plugins, themes, must-use plugins, wp-config.php, and .htaccess. PHP, CSS, JavaScript, and HTML templates are included. WordPress core folders are not hashed.', 'admintrace-activity-logger' ); ?></span></span></label>
						<label class="srah-toggle"><input type="checkbox" name="file_watch_uploads" value="1" <?php checked( $settings['file_watch_uploads'] ); ?>> <span><strong><?php esc_html_e( 'Include text files in uploads', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'PHP, JSON, XML, text, SVG, and HTML. JavaScript, CSS, images, and archives are skipped.', 'admintrace-activity-logger' ); ?></span></span></label>
						<label class="srah-toggle"><input type="checkbox" name="table_watch" value="1" <?php checked( $settings['table_watch'] ); ?>> <span><strong><?php esc_html_e( 'Watch database tables', 'admintrace-activity-logger' ); ?></strong><span class="description"><?php esc_html_e( 'Records when a table with this site’s prefix is created or dropped. Queries and row data are not stored.', 'admintrace-activity-logger' ); ?></span></span></label>
					</div>
				</section>
				<div class="srah-savebar">
					<?php submit_button( __( 'Save file settings', 'admintrace-activity-logger' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'srah_watch_now' ); ?>
				<input type="hidden" name="action" value="srah_watch_now">
				<?php submit_button( __( 'Scan now', 'admintrace-activity-logger' ), 'secondary', 'submit', false, array( 'id' => 'srah-scan-now' ) ); ?>
			</form>
			<?php if ( $recent ) : ?>
				<section class="srah-panel">
					<header class="srah-panel-head">
						<h2><?php esc_html_e( 'Recent file and database events', 'admintrace-activity-logger' ); ?></h2>
					</header>
					<ul class="srah-recent">
						<?php foreach ( $recent as $row ) : ?>
							<?php $when = Time::display( $row['created_gmt'], isset( $row['created_ms'] ) ? (int) $row['created_ms'] : 0 ); ?>
							<li><time datetime="<?php echo esc_attr( $when['iso'] ); ?>"><?php echo esc_html( $when['local'] ); ?></time> <?php echo esc_html( $row['summary'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Latest file and table events.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function recent() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own events table has no WordPress API; journal reads must be live.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_gmt, created_ms, summary FROM %i WHERE blog_id = %d AND action IN (%s, %s, %s, %s, %s) ORDER BY id DESC LIMIT 8',
				Installer::table(),
				get_current_blog_id(),
				'files.added',
				'files.changed',
				'files.deleted',
				'database.table_created',
				'database.table_dropped'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
