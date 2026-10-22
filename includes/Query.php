<?php
/**
 * Event queries.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads events with whitelisted filters and ordering.
 */
class Query {

	/**
	 * Search events.
	 *
	 * @param array $args Query arguments.
	 * @return array{rows:array,total:int}
	 */
	public static function search( array $args ) {
		global $wpdb;

		if ( ! empty( $args['force_empty'] ) ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$built    = self::where( $args );
		$table    = Installer::table();
		$order    = self::order_parts(
			isset( $args['orderby'] ) ? $args['orderby'] : 'created_gmt',
			isset( $args['order'] ) ? $args['order'] : 'DESC'
		);
		$max      = ! empty( $args['internal_limit'] ) ? 5000 : 100;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 25;
		$per_page = min( $max, max( 1, $per_page ) );
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$page     = min( 10000, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$built['sql']}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $built['params'] ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$built['sql']} ORDER BY {$order['column']} {$order['direction']} LIMIT %d OFFSET %d";
		$params   = array_merge( $built['params'], array( $per_page, $offset ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['context'] = self::decode_context( isset( $row['context'] ) ? $row['context'] : '' );
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Find one event on the current site.
	 *
	 * @param int $id Event ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		$result = self::search(
			array(
				'id'       => absint( $id ),
				'per_page' => 1,
				'page'     => 1,
			)
		);
		return isset( $result['rows'][0] ) ? $result['rows'][0] : null;
	}

	/**
	 * Recent events.
	 *
	 * @param int   $limit Maximum rows.
	 * @param array $args  Extra query arguments.
	 * @return array
	 */
	public static function recent( $limit, array $args = array() ) {
		$args['per_page'] = $limit;
		$args['page']     = 1;
		$result           = self::search( $args );
		return $result['rows'];
	}

	/**
	 * Aggregate counts for the journal header.
	 *
	 * @param bool  $scoped Whether to limit categories.
	 * @param array $categories Categories to include when scoped.
	 * @return array
	 */
	public static function stats( $scoped, array $categories ) {
		global $wpdb;

		$key    = 'ah_stats_' . get_current_blog_id() . ( $scoped ? '_view' : '_all' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table  = Installer::table();
		$blog   = get_current_blog_id();
		$now         = time();
		$local_start = function_exists( 'current_time' ) ? current_time( 'Y-m-d 00:00:00' ) : gmdate( 'Y-m-d 00:00:00' );
		$today       = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $local_start ) : gmdate( 'Y-m-d H:i:s', $now - ( $now % DAY_IN_SECONDS ) );
		$week        = gmdate( 'Y-m-d H:i:s', $now - ( 7 * DAY_IN_SECONDS ) );
		$dayago      = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );

		$scope_sql    = '';
		$scope_params = array();
		if ( $scoped ) {
			if ( ! $categories ) {
				$categories = array( '__none__' );
			}
			$scope_sql    = ' AND category IN (' . implode( ',', array_fill( 0, count( $categories ), '%s' ) ) . ')';
			$scope_params = array_values( $categories );
		}

		$today_count = self::count_since( $table, $blog, $today, $scope_sql, $scope_params );
		$week_count  = self::count_since( $table, $blog, $week, $scope_sql, $scope_params );
		$failed      = 0;
		if ( ! $scoped ) {
			$failed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND created_gmt >= %s AND action = %s",
					$blog,
					$dayago,
					'user.login_failed'
				)
			);
		}

		$cat_sql    = "SELECT category, COUNT(*) AS total FROM {$table} WHERE blog_id = %d AND created_gmt >= %s {$scope_sql} GROUP BY category ORDER BY total DESC LIMIT 6";
		$cat_params = array_merge( array( $blog, $week ), $scope_params );
		$cats       = $wpdb->get_results( $wpdb->prepare( $cat_sql, $cat_params ), ARRAY_A );

		$user_sql    = "SELECT user_id, user_login, user_display, COUNT(*) AS total FROM {$table} WHERE blog_id = %d AND created_gmt >= %s AND user_id > 0 {$scope_sql} GROUP BY user_id, user_login, user_display ORDER BY total DESC LIMIT 5";
		$user_params = array_merge( array( $blog, $week ), $scope_params );
		$users       = $wpdb->get_results( $wpdb->prepare( $user_sql, $user_params ), ARRAY_A );

		$stats = array(
			'today'          => $today_count,
			'week'           => $week_count,
			'failed_logins'  => $failed,
			'categories'     => is_array( $cats ) ? $cats : array(),
			'users'          => is_array( $users ) ? $users : array(),
		);
		set_transient( $key, $stats, 2 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * Whitelist ORDER BY parts.
	 *
	 * @param string $column Requested column.
	 * @param string $direction Requested direction.
	 * @return array{column:string,direction:string}
	 */
	public static function order_parts( $column, $direction ) {
		$allowed = array( 'created_gmt', 'id', 'severity', 'category' );
		$column  = in_array( $column, $allowed, true ) ? $column : 'created_gmt';
		$dir     = ( 'ASC' === strtoupper( (string) $direction ) ) ? 'ASC' : 'DESC';
		return array(
			'column'    => $column,
			'direction' => $dir,
		);
	}

	/**
	 * Count rows since a GMT datetime.
	 *
	 * @param string $table Table name.
	 * @param int    $blog Blog ID.
	 * @param string $since GMT datetime.
	 * @param string $scope_sql Extra SQL.
	 * @param array  $scope_params Extra parameters.
	 * @return int
	 */
	private static function count_since( $table, $blog, $since, $scope_sql, array $scope_params ) {
		global $wpdb;
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND created_gmt >= %s {$scope_sql}";
		$params = array_merge( array( $blog, $since ), $scope_params );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Build a WHERE clause.
	 *
	 * @param array $args Query arguments.
	 * @return array{sql:string,params:array}
	 */
	private static function where( array $args ) {
		global $wpdb;

		$sql    = array( 'blog_id = %d' );
		$params = array( get_current_blog_id() );

		if ( ! empty( $args['id'] ) ) {
			$sql[]    = 'id = %d';
			$params[] = absint( $args['id'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$sql[]    = 'category = %s';
			$params[] = Text::key( $args['category'], 32, '' );
		}

		if ( ! empty( $args['category_in'] ) && is_array( $args['category_in'] ) ) {
			$cats = array();
			foreach ( $args['category_in'] as $cat ) {
				$key = Text::key( $cat, 32, '' );
				if ( '' !== $key ) {
					$cats[] = $key;
				}
			}
			if ( $cats ) {
				$sql[]  = 'category IN (' . implode( ',', array_fill( 0, count( $cats ), '%s' ) ) . ')';
				$params = array_merge( $params, $cats );
			}
		}

		if ( ! empty( $args['severity'] ) ) {
			$sql[]    = 'severity = %s';
			$params[] = Text::key( $args['severity'], 16, 'info' );
		}

		if ( ! empty( $args['action'] ) ) {
			$sql[]    = 'action = %s';
			$params[] = Text::key( $args['action'], 64, '' );
		}

		if ( ! empty( $args['initiator'] ) ) {
			$sql[]    = 'initiator = %s';
			$params[] = Text::key( $args['initiator'], 20, '' );
		}

		if ( ! empty( $args['logger'] ) ) {
			$sql[]    = 'logger = %s';
			$params[] = Text::key( $args['logger'], 32, '' );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$sql[]    = 'user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		if ( ! empty( $args['exclude_user_id'] ) ) {
			$sql[]    = 'user_id <> %d';
			$params[] = absint( $args['exclude_user_id'] );
		}

		if ( ! empty( $args['object_type'] ) ) {
			$sql[]    = 'object_type = %s';
			$params[] = Text::key( $args['object_type'], 32, '' );
		}

		if ( ! empty( $args['object_id'] ) ) {
			$sql[]    = 'object_id = %d';
			$params[] = absint( $args['object_id'] );
		}

		if ( ! empty( $args['after'] ) ) {
			$after = self::date_bound( $args['after'], false );
			if ( $after ) {
				$sql[]    = 'created_gmt >= %s';
				$params[] = $after;
			}
		}

		if ( ! empty( $args['before'] ) ) {
			$before = self::date_bound( $args['before'], true );
			if ( $before ) {
				$sql[]    = 'created_gmt <= %s';
				$params[] = $before;
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$search = Text::plain( $args['search'], 120 );
			if ( strlen( $search ) >= 2 ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$sql[]    = '(summary LIKE %s OR object_title LIKE %s OR user_login LIKE %s OR user_display LIKE %s OR action LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
		}

		return array(
			'sql'    => implode( ' AND ', $sql ),
			'params' => $params,
		);
	}

	/**
	 * Normalize a date bound to GMT MySQL format.
	 *
	 * @param string $value Raw date or datetime.
	 * @param bool   $end   Whether this is an inclusive end bound.
	 * @return string
	 */
	private static function date_bound( $value, $end ) {
		$value = Text::plain( $value, 19 );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$local = $value . ( $end ? ' 23:59:59' : ' 00:00:00' );
			if ( function_exists( 'get_gmt_from_date' ) ) {
				return get_gmt_from_date( $local );
			}
			return $local;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Decode stored context.
	 *
	 * @param string $json JSON string.
	 * @return array
	 */
	private static function decode_context( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
