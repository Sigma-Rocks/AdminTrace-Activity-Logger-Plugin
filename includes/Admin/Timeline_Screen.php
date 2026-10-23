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
			wp_die( esc_html__( 'Sorry, you are not allowed to view the activity journal.', 'advance-history' ), 403 );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			$screen->add_help_tab(
				array(
					'id'      => 'ah-overview',
					'title'   => __( 'Overview', 'advance-history' ),
					'content' => '<p>' . esc_html__( 'Advance History lists important changes on this site. Use the filters to narrow the journal, open Details on any event, and export the current view if you are allowed to.', 'advance-history' ) . '</p>',
				)
			);
			$screen->add_help_tab(
				array(
					'id'      => 'ah-privacy',
					'title'   => __( 'Privacy', 'advance-history' ),
					'content' => '<p>' . esc_html__( 'Passwords and API keys are not stored. IP addresses are masked. Editors, when allowed to view the journal, see content activity and not security events.', 'advance-history' ) . '</p>',
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

		echo '<div class="wrap ah-wrap">';
		Notices::render();
		self::hero( $filters );
		self::stats( $stats, $manage );
		if ( false === $highlight ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'That event is not available.', 'advance-history' ) . '</p></div>';
		} elseif ( is_array( $highlight ) ) {
			echo '<div class="ah-highlight">';
			Renderer::card( $highlight, array( 'checkbox' => false ) );
			echo '</div>';
		}
		self::filter_form( $filters, $manage );
		$rows = Groups::collapse( $result['rows'] );
		if ( ! $rows ) {
			echo '<div class="ah-empty"><h2>' . esc_html__( 'Nothing recorded yet', 'advance-history' ) . '</h2><p>' . esc_html__( 'New changes will appear here. Existing posts and pages are noted once, when the plugin is activated.', 'advance-history' ) . '</p></div>';
		} elseif ( 'table' === $filters['view'] ) {
			self::table( $rows, ! empty( $args['include_ip'] ) );
		} else {
			echo '<div class="ah-timeline">';
			foreach ( $rows as $row ) {
				Renderer::card( Access::present( $row, ! empty( $args['include_ip'] ) ) );
			}
			echo '</div>';
		}
		self::pagination( $filters, $pages );
		if ( $manage ) {
			self::note_form();
		}
		echo '<p class="ah-credit"><a href="' . esc_url( 'https://sigmarocks.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Advance History by Sigma Rocks', 'advance-history' ) . '</a></p>';
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
							'action' => 'advance_history_export',
							'format' => 'csv',
						),
						array_filter( self::query_args( $filters ) )
					),
					admin_url( 'admin-post.php' )
				),
				'advance_history_export'
			);
		}
		?>
		<header class="ah-hero">
			<div class="ah-brand">
				<span class="ah-mark" aria-hidden="true">AH</span>
				<div>
					<h1><?php esc_html_e( 'Advance History', 'advance-history' ); ?></h1>
					<p><?php esc_html_e( 'A journal of who changed this site, and when.', 'advance-history' ); ?></p>
				</div>
			</div>
			<div class="ah-hero-actions">
				<?php if ( $export ) : ?>
					<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'advance-history' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'format', 'json', $export ) ); ?>"><?php esc_html_e( 'Export JSON', 'advance-history' ); ?></a>
				<?php endif; ?>
				<div class="ah-segment" role="group" aria-label="<?php esc_attr_e( 'Journal view', 'advance-history' ); ?>">
					<a class="button" href="<?php echo esc_url( self::view_url( $filters, 'timeline' ) ); ?>" <?php echo 'timeline' === $filters['view'] ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Timeline', 'advance-history' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::view_url( $filters, 'table' ) ); ?>" <?php echo 'table' === $filters['view'] ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Table', 'advance-history' ); ?></a>
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
				'label' => __( 'Today', 'advance-history' ),
				'value' => (int) $stats['today'],
			),
			array(
				'label' => __( 'Last 7 days', 'advance-history' ),
				'value' => (int) $stats['week'],
			),
		);
		if ( $manage ) {
			$tiles[] = array(
				'label' => __( 'Failed logins, 24 hours', 'advance-history' ),
				'value' => (int) $stats['failed_logins'],
			);
		}
		echo '<section class="ah-stats" aria-label="' . esc_attr__( 'Activity summary', 'advance-history' ) . '">';
		foreach ( $tiles as $tile ) {
			echo '<article class="ah-stat"><p>' . esc_html( $tile['label'] ) . '</p><strong>' . esc_html( (string) $tile['value'] ) . '</strong></article>';
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
		<form class="ah-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="advance-history">
			<input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>">
			<div class="ah-filter-grid">
			<p>
				<label for="ah-search"><?php esc_html_e( 'Search', 'advance-history' ); ?></label>
				<input id="ah-search" type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
			</p>
			<p>
				<label for="ah-category"><?php esc_html_e( 'Category', 'advance-history' ); ?></label>
				<select id="ah-category" name="category">
					<option value=""><?php esc_html_e( 'All categories', 'advance-history' ); ?></option>
					<?php foreach ( Vocabulary::categories() as $key => $label ) : ?>
						<?php if ( ! $manage && ! in_array( $key, Vocabulary::viewer_categories(), true ) ) { continue; } ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['category'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="ah-severity"><?php esc_html_e( 'Severity', 'advance-history' ); ?></label>
				<select id="ah-severity" name="severity">
					<option value=""><?php esc_html_e( 'All severities', 'advance-history' ); ?></option>
					<?php foreach ( Vocabulary::severities() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['severity'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="ah-initiator"><?php esc_html_e( 'Source', 'advance-history' ); ?></label>
				<select id="ah-initiator" name="initiator">
					<option value=""><?php esc_html_e( 'Any source', 'advance-history' ); ?></option>
					<?php foreach ( Vocabulary::initiators() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['initiator'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php if ( $manage && current_user_can( 'list_users' ) ) : ?>
				<p>
					<label for="ah-user"><?php esc_html_e( 'Account', 'advance-history' ); ?></label>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'user_id',
							'id'                => 'ah-user',
							'show_option_all'   => __( 'All accounts', 'advance-history' ),
							'selected'          => $filters['user_id'],
						)
					);
					?>
				</p>
			<?php endif; ?>
			</div>
			<div class="ah-filter-bar">
			<p>
				<label for="ah-after"><?php esc_html_e( 'From', 'advance-history' ); ?></label>
				<input id="ah-after" type="date" name="after" value="<?php echo esc_attr( $filters['after'] ); ?>">
			</p>
			<p>
				<label for="ah-before"><?php esc_html_e( 'To', 'advance-history' ); ?></label>
				<input id="ah-before" type="date" name="before" value="<?php echo esc_attr( $filters['before'] ); ?>">
			</p>
			<p class="ah-filter-check">
				<label for="ah-exclude"><input id="ah-exclude" type="checkbox" name="exclude_me" value="1" <?php checked( $filters['exclude'] ); ?>> <?php esc_html_e( 'Hide my events', 'advance-history' ); ?></label>
			</p>
			<p class="ah-filter-actions">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'advance-history' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=advance-history' ) ); ?>"><?php esc_html_e( 'Reset', 'advance-history' ); ?></a>
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
		echo '<table class="widefat striped ah-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'advance-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Severity', 'advance-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Category', 'advance-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Activity', 'advance-history' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actor', 'advance-history' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row  = Access::present( $row, $include_ip );
			$when = \SigmaRocks\AdvanceHistory\Support\Time::display( $row['created_gmt'] );
			echo '<tr>';
			echo '<td><time datetime="' . esc_attr( $when['iso'] ) . '">' . esc_html( $when['primary'] ) . '</time></td>';
			echo '<td>' . esc_html( Vocabulary::severity_label( $row['severity'] ) ) . '</td>';
			echo '<td>' . esc_html( Vocabulary::category_label( $row['category'] ) ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=advance-history&event=' . (int) $row['id'] ) ) . '">' . esc_html( $row['summary'] ) . '</a>';
			$repeats = isset( $row['repeat_count'] ) ? (int) $row['repeat_count'] : 1;
			if ( $repeats > 1 ) {
				echo ' <span class="ah-pill ah-pill-repeat">' . esc_html(
					sprintf(
						/* translators: %d: number of times this event happened. */
						_n( '%d time', '%d times', $repeats, 'advance-history' ),
						$repeats
					)
				) . '</span>';
			}
			echo '</td>';
			echo '<td class="ah-actor-cell"><span class="ah-event-avatar" aria-hidden="true">' . Renderer::avatar( $row, 28 ) . '</span><span>' . esc_html( Renderer::actor( $row ) ) . '</span></td>';
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
				'prev_text' => __( 'Previous', 'advance-history' ),
				'next_text' => __( 'Next', 'advance-history' ),
			)
		);
		if ( $links ) {
			echo '<nav class="ah-pager" aria-label="' . esc_attr__( 'Journal pages', 'advance-history' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * Journal note form.
	 */
	private static function note_form() {
		?>
		<details class="ah-note" id="ah-note">
			<summary><?php esc_html_e( 'Add a journal note', 'advance-history' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'advance_history_note' ); ?>
				<input type="hidden" name="action" value="advance_history_note">
				<p>
					<label for="ah-note-text"><?php esc_html_e( 'Note', 'advance-history' ); ?></label>
					<textarea id="ah-note-text" name="summary" rows="3" required maxlength="500"></textarea>
				</p>
				<p>
					<label for="ah-note-severity"><?php esc_html_e( 'Severity', 'advance-history' ); ?></label>
					<select id="ah-note-severity" name="severity">
						<?php foreach ( Vocabulary::severities() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save note', 'advance-history' ); ?></button></p>
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
			'page' => 'advance-history',
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
