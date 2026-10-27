<?php
/**
 * Activity journal screen.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Admin;

use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Access;
use SigmaRocks\AdvanceHistory\Support\Groups;
use SigmaRocks\AdvanceHistory\Support\Text;
use SigmaRocks\AdvanceHistory\Support\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The main activity journal.
 */
class Timeline_Screen {

	/**
	 * Render the journal.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view the activity journal.', 'admintrace-activity-logger' ), 403 );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			$screen->add_help_tab(
				array(
					'id'      => 'srah-overview',
					'title'   => __( 'Overview', 'admintrace-activity-logger' ),
					'content' => '<p>' . esc_html__( 'AdminTrace Activity Logger lists important changes on this site. Use the filters to narrow the journal, open Details on any event, and export the current view if you are allowed to.', 'admintrace-activity-logger' ) . '</p>',
				)
			);
			$screen->add_help_tab(
				array(
					'id'      => 'srah-privacy',
					'title'   => __( 'Privacy', 'admintrace-activity-logger' ),
					'content' => '<p>' . esc_html__( 'Passwords and API keys are not stored. IP addresses are masked. Editors, when allowed to view the journal, see content activity and not security events.', 'admintrace-activity-logger' ) . '</p>',
				)
			);
		}

		$filters  = self::filters();
		$args     = Access::constrain( self::query_args( $filters ) );
		$result   = Query::search( $args );
		$manage   = current_user_can( Capabilities::MANAGE );
		$scoped   = ! $manage;
		$stats    = Query::stats( $scoped, $scoped ? Vocabulary::viewer_categories() : array() );
		$pages    = max( 1, (int) ceil( $result['total'] / $filters['per_page'] ) );
		$highlight = null;
		if ( $filters['event'] ) {
			$found = Query::search( Access::constrain( array( 'id' => $filters['event'], 'per_page' => 1 ) ) );
			$highlight = isset( $found['rows'][0] ) ? Access::present( $found['rows'][0], ! empty( $args['include_ip'] ) ) : false;
		}

		echo '<div class="wrap srah-wrap">';
		Notices::render();
		self::hero( $filters );
		self::stats( $stats, $manage );
		if ( false === $highlight ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'That event is not available.', 'admintrace-activity-logger' ) . '</p></div>';
		} elseif ( is_array( $highlight ) ) {
			echo '<div class="srah-highlight">';
			Renderer::card( $highlight, array( 'checkbox' => false ) );
			echo '</div>';
		}
		self::filter_form( $filters, $manage );
		$rows = Groups::collapse( $result['rows'] );
		if ( ! $rows ) {
			echo '<div class="srah-empty"><h2>' . esc_html__( 'Nothing recorded yet', 'admintrace-activity-logger' ) . '</h2><p>' . esc_html__( 'New changes will appear here. Existing posts and pages are noted once, when the plugin is activated.', 'admintrace-activity-logger' ) . '</p></div>';
		} elseif ( 'table' === $filters['view'] ) {
			self::table( $rows, ! empty( $args['include_ip'] ) );
		} else {
			echo '<div class="srah-timeline">';
			foreach ( $rows as $row ) {
				Renderer::card( Access::present( $row, ! empty( $args['include_ip'] ) ) );
			}
			echo '</div>';
		}
		self::pagination( $filters, $pages );
		if ( $manage ) {
			self::note_form();
		}
		echo '<p class="srah-credit"><a href="' . esc_url( 'https://sigmarocks.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'AdminTrace Activity Logger by Sigma Rocks', 'admintrace-activity-logger' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Read and sanitize the filter request.
	 *
	 * @return array
	 */
	public static function filters() {
		$settings = Settings::all();
		$get      = wp_unslash( $_GET );
		$view     = isset( $get['view'] ) && 'table' === $get['view'] ? 'table' : '';
		if ( '' === $view ) {
			$view = ( 'table' === $settings['default_view'] ) ? 'table' : 'timeline';
		}
		return array(
			'search'    => isset( $get['s'] ) ? Text::plain( $get['s'], 120 ) : '',
			'category'  => isset( $get['category'] ) ? sanitize_key( $get['category'] ) : '',
			'severity'  => isset( $get['severity'] ) ? sanitize_key( $get['severity'] ) : '',
			'initiator' => isset( $get['initiator'] ) ? sanitize_key( $get['initiator'] ) : '',
			'user_id'   => isset( $get['user_id'] ) ? absint( $get['user_id'] ) : 0,
			'after'     => isset( $get['after'] ) ? self::date_input( $get['after'] ) : '',
			'before'    => isset( $get['before'] ) ? self::date_input( $get['before'] ) : '',
			'view'      => $view,
			'paged'     => isset( $get['paged'] ) ? max( 1, absint( $get['paged'] ) ) : 1,
			'exclude'   => empty( $get['exclude_me'] ) ? 0 : 1,
			'event'     => isset( $get['event'] ) ? absint( $get['event'] ) : 0,
			'per_page'  => (int) $settings['per_page'],
		);
	}

	/**
	 * Query arguments for the current filters.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public static function query_args( array $filters ) {
		return array(
			'search'          => $filters['search'],
			'category'        => $filters['category'],
			'severity'        => $filters['severity'],
			'initiator'       => $filters['initiator'],
			'user_id'         => $filters['user_id'],
			'after'           => $filters['after'],
			'before'          => $filters['before'],
			'page'            => $filters['paged'],
			'per_page'        => $filters['per_page'],
			'exclude_user_id' => $filters['exclude'] ? get_current_user_id() : 0,
		);
	}

	/**
	 * Page header.
	 *
	 * @param array $filters Current filters.
	 */
	private static function hero( array $filters ) {
		$export = '';
		if ( current_user_can( Capabilities::EXPORT ) ) {
			$export = wp_nonce_url(
				add_query_arg(
					array_merge(
						array(
							'action' => 'srah_export',
							'format' => 'csv',
						),
						array_filter( self::query_args( $filters ) )
					),
					admin_url( 'admin-post.php' )
				),
				'srah_export'
			);
		}
		?>
		<header class="srah-hero">
			<div class="srah-brand">
				<?php echo wp_kses_post( Renderer::mark() ); ?>
				<div>
					<h1><?php esc_html_e( 'AdminTrace Activity Logger', 'admintrace-activity-logger' ); ?></h1>
					<p><?php esc_html_e( 'A journal of who changed this site, and when.', 'admintrace-activity-logger' ); ?></p>
				</div>
			</div>
			<div class="srah-hero-actions">
				<?php if ( $export ) : ?>
					<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'admintrace-activity-logger' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'format', 'json', $export ) ); ?>"><?php esc_html_e( 'Export JSON', 'admintrace-activity-logger' ); ?></a>
				<?php endif; ?>
				<div class="srah-segment" role="group" aria-label="<?php esc_attr_e( 'Journal view', 'admintrace-activity-logger' ); ?>">
					<a class="button" href="<?php echo esc_url( self::view_url( $filters, 'timeline' ) ); ?>" <?php echo 'timeline' === $filters['view'] ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Timeline', 'admintrace-activity-logger' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::view_url( $filters, 'table' ) ); ?>" <?php echo 'table' === $filters['view'] ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Table', 'admintrace-activity-logger' ); ?></a>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Summary tiles.
	 *
	 * @param array $stats  Counts.
	 * @param bool  $manage Whether security counts may be shown.
	 */
	private static function stats( array $stats, $manage ) {
		$tiles = array(
			array(
				'label' => __( 'Today', 'admintrace-activity-logger' ),
				'value' => (int) $stats['today'],
			),
			array(
				'label' => __( 'Last 7 days', 'admintrace-activity-logger' ),
				'value' => (int) $stats['week'],
			),
		);
		if ( $manage ) {
			$tiles[] = array(
				'label' => __( 'Failed logins, 24 hours', 'admintrace-activity-logger' ),
				'value' => (int) $stats['failed_logins'],
			);
		}
		echo '<section class="srah-stats" aria-label="' . esc_attr__( 'Activity summary', 'admintrace-activity-logger' ) . '">';
		foreach ( $tiles as $tile ) {
			echo '<article class="srah-stat"><p>' . esc_html( $tile['label'] ) . '</p><strong>' . esc_html( (string) $tile['value'] ) . '</strong></article>';
		}
		echo '</section>';
	}

	/**
	 * Filter form.
	 *
	 * @param array $filters Filters.
	 * @param bool  $manage  Whether the user may list accounts.
	 */
	private static function filter_form( array $filters, $manage ) {
		?>
		<form class="srah-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="admintrace-activity-logger">
			<input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>">
			<div class="srah-filter-grid">
			<p>
				<label for="srah-search"><?php esc_html_e( 'Search', 'admintrace-activity-logger' ); ?></label>
				<input id="srah-search" type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
			</p>
			<p>
				<label for="srah-category"><?php esc_html_e( 'Category', 'admintrace-activity-logger' ); ?></label>
				<select id="srah-category" name="category">
					<option value=""><?php esc_html_e( 'All categories', 'admintrace-activity-logger' ); ?></option>
					<?php foreach ( Vocabulary::categories() as $key => $label ) : ?>
						<?php if ( ! $manage && ! in_array( $key, Vocabulary::viewer_categories(), true ) ) { continue; } ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['category'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="srah-severity"><?php esc_html_e( 'Severity', 'admintrace-activity-logger' ); ?></label>
				<select id="srah-severity" name="severity">
					<option value=""><?php esc_html_e( 'All severities', 'admintrace-activity-logger' ); ?></option>
					<?php foreach ( Vocabulary::severities() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['severity'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="srah-initiator"><?php esc_html_e( 'Source', 'admintrace-activity-logger' ); ?></label>
				<select id="srah-initiator" name="initiator">
					<option value=""><?php esc_html_e( 'Any source', 'admintrace-activity-logger' ); ?></option>
					<?php foreach ( Vocabulary::initiators() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['initiator'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php if ( $manage && current_user_can( 'list_users' ) ) : ?>
				<p>
					<label for="srah-user"><?php esc_html_e( 'Account', 'admintrace-activity-logger' ); ?></label>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'user_id',
							'id'                => 'srah-user',
							'show_option_all'   => __( 'All accounts', 'admintrace-activity-logger' ),
							'selected'          => $filters['user_id'],
						)
					);
					?>
				</p>
			<?php endif; ?>
			</div>
			<div class="srah-filter-bar">
			<p>
				<label for="srah-after"><?php esc_html_e( 'From', 'admintrace-activity-logger' ); ?></label>
				<input id="srah-after" type="date" name="after" value="<?php echo esc_attr( $filters['after'] ); ?>">
			</p>
			<p>
				<label for="srah-before"><?php esc_html_e( 'To', 'admintrace-activity-logger' ); ?></label>
				<input id="srah-before" type="date" name="before" value="<?php echo esc_attr( $filters['before'] ); ?>">
			</p>
			<p class="srah-filter-check">
				<label for="srah-exclude"><input id="srah-exclude" type="checkbox" name="exclude_me" value="1" <?php checked( $filters['exclude'] ); ?>> <?php esc_html_e( 'Hide my events', 'admintrace-activity-logger' ); ?></label>
			</p>
			<p class="srah-filter-actions">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'admintrace-activity-logger' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=admintrace-activity-logger' ) ); ?>"><?php esc_html_e( 'Reset', 'admintrace-activity-logger' ); ?></a>
			</p>
			</div>
		</form>
		<?php
	}

	/**
	 * Compact table.
	 *
	 * @param array $rows Rows.
	 * @param bool  $include_ip Whether masked IPs are shown.
	 */
	private static function table( array $rows, $include_ip ) {
		echo '<table class="widefat striped srah-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Severity', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Category', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Activity', 'admintrace-activity-logger' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actor', 'admintrace-activity-logger' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row  = Access::present( $row, $include_ip );
			$when = \SigmaRocks\AdvanceHistory\Support\Time::display( $row['created_gmt'] );
			echo '<tr>';
			echo '<td><time datetime="' . esc_attr( $when['iso'] ) . '">' . esc_html( $when['primary'] ) . '</time></td>';
			echo '<td>' . esc_html( Vocabulary::severity_label( $row['severity'] ) ) . '</td>';
			echo '<td>' . esc_html( Vocabulary::category_label( $row['category'] ) ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=admintrace-activity-logger&event=' . (int) $row['id'] ) ) . '">' . esc_html( $row['summary'] ) . '</a>';
			$repeats = isset( $row['repeat_count'] ) ? (int) $row['repeat_count'] : 1;
			if ( $repeats > 1 ) {
				echo ' <span class="srah-pill srah-pill-repeat">' . esc_html(
					sprintf(
						/* translators: %d: number of times this event happened. */
						_n( '%d time', '%d times', $repeats, 'admintrace-activity-logger' ),
						$repeats
					)
				) . '</span>';
			}
			echo '</td>';
			echo '<td class="srah-actor-cell"><span class="srah-event-avatar" aria-hidden="true">' . wp_kses_post( Renderer::avatar( $row, 28 ) ) . '</span><span>' . esc_html( Renderer::actor( $row ) ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Pagination.
	 *
	 * @param array $filters Filters.
	 * @param int   $pages   Total pages.
	 */
	private static function pagination( array $filters, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		$base = self::view_url( $filters, $filters['view'] );
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
			echo '<nav class="srah-pager" aria-label="' . esc_attr__( 'Journal pages', 'admintrace-activity-logger' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * Journal note form.
	 */
	private static function note_form() {
		?>
		<details class="srah-note" id="srah-note">
			<summary><?php esc_html_e( 'Add a journal note', 'admintrace-activity-logger' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'srah_note' ); ?>
				<input type="hidden" name="action" value="srah_note">
				<p>
					<label for="srah-note-text"><?php esc_html_e( 'Note', 'admintrace-activity-logger' ); ?></label>
					<textarea id="srah-note-text" name="summary" rows="3" required maxlength="500"></textarea>
				</p>
				<p>
					<label for="srah-note-severity"><?php esc_html_e( 'Severity', 'admintrace-activity-logger' ); ?></label>
					<select id="srah-note-severity" name="severity">
						<?php foreach ( Vocabulary::severities() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save note', 'admintrace-activity-logger' ); ?></button></p>
			</form>
		</details>
		<?php
	}

	/**
	 * Keep a date input as Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function date_input( $value ) {
		$value = Text::plain( $value, 10 );
		$date  = \DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return '';
		}
		return $value;
	}

	/**
	 * URL that preserves filters and switches view.
	 *
	 * @param array  $filters Filters.
	 * @param string $view    View name.
	 * @return string
	 */
	private static function view_url( array $filters, $view ) {
		$args = array(
			'page' => 'admintrace-activity-logger',
			'view' => $view,
		);
		foreach ( array( 'search' => 's', 'category' => 'category', 'severity' => 'severity', 'initiator' => 'initiator', 'user_id' => 'user_id', 'after' => 'after', 'before' => 'before' ) as $from => $to ) {
			if ( ! empty( $filters[ $from ] ) ) {
				$args[ $to ] = $filters[ $from ];
			}
		}
		if ( ! empty( $filters['exclude'] ) ) {
			$args['exclude_me'] = 1;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
