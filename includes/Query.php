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

		$built    = self::filter_values( $args );
		$table    = $built['table'];
		$values   = $built['values'];
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

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND (%d = 0 OR id = %d) AND (%d = 0 OR category = %s) AND (%d = 0 OR category IN (%s,%s,%s,%s,%s,%s,%s,%s)) AND (%d = 0 OR severity = %s) AND (%d = 0 OR action = %s) AND (%d = 0 OR initiator = %s) AND (%d = 0 OR logger = %s) AND (%d = 0 OR user_id = %d) AND (%d = 0 OR user_id <> %d) AND (%d = 0 OR object_type = %s) AND (%d = 0 OR object_id = %d) AND (%d = 0 OR created_gmt >= %s) AND (%d = 0 OR created_gmt <= %s) AND (%d = 0 OR (summary LIKE %s OR object_title LIKE %s OR user_login LIKE %s OR user_display LIKE %s OR action LIKE %s))',
				$table,
				...$values
			)
		);

		$sort          = self::sort_flags( $order['column'], $order['direction'] );
		$list_values   = array_merge( array( $table ), $values, $sort, array( $per_page, $offset ) );
		$rows          = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND (%d = 0 OR id = %d) AND (%d = 0 OR category = %s) AND (%d = 0 OR category IN (%s,%s,%s,%s,%s,%s,%s,%s)) AND (%d = 0 OR severity = %s) AND (%d = 0 OR action = %s) AND (%d = 0 OR initiator = %s) AND (%d = 0 OR logger = %s) AND (%d = 0 OR user_id = %d) AND (%d = 0 OR user_id <> %d) AND (%d = 0 OR object_type = %s) AND (%d = 0 OR object_id = %d) AND (%d = 0 OR created_gmt >= %s) AND (%d = 0 OR created_gmt <= %s) AND (%d = 0 OR (summary LIKE %s OR object_title LIKE %s OR user_login LIKE %s OR user_display LIKE %s OR action LIKE %s)) ORDER BY CASE WHEN %d = 1 THEN id END ASC, CASE WHEN %d = 1 THEN id END DESC, CASE WHEN %d = 1 THEN severity END ASC, CASE WHEN %d = 1 THEN severity END DESC, CASE WHEN %d = 1 THEN category END ASC, CASE WHEN %d = 1 THEN category END DESC, CASE WHEN %d = 1 THEN created_gmt END ASC, CASE WHEN %d = 1 THEN created_gmt END DESC LIMIT %d OFFSET %d',
				...$list_values
			),
			ARRAY_A
		);
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

		$key    = 'srah_stats_' . get_current_blog_id() . ( $scoped ? '_view' : '_all' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table   = Installer::table();
		$blog    = get_current_blog_id();
		$now     = time();
		$local_start = function_exists( 'current_time' ) ? current_time( 'Y-m-d 00:00:00' ) : gmdate( 'Y-m-d 00:00:00' );
		$today       = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $local_start ) : gmdate( 'Y-m-d H:i:s', $now - ( $now % DAY_IN_SECONDS ) );
		$week        = gmdate( 'Y-m-d H:i:s', $now - ( 7 * DAY_IN_SECONDS ) );
		$dayago      = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );

		$scope_flag = 0;
		$scope_cats = array( '', '', '', '', '', '', '', '' );
		if ( $scoped ) {
			$scope_flag = 1;
			if ( ! $categories ) {
				$categories = array( '__none__' );
			}
			$clean = array();
			foreach ( $categories as $category ) {
				$category_key = Text::key( $category, 32, '' );
				if ( '' !== $category_key ) {
					$clean[] = $category_key;
				}
			}
			if ( ! $clean ) {
				$clean = array( '__none__' );
			}
			$scope_cats = array_pad( array_slice( $clean, 0, 8 ), 8, '' );
		}

		$today_count = self::count_since( $table, $blog, $today, $scope_flag, $scope_cats );
		$week_count  = self::count_since( $table, $blog, $week, $scope_flag, $scope_cats );
		$failed      = 0;
		if ( ! $scoped ) {
			$failed = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND created_gmt >= %s AND action = %s',
					$table,
					$blog,
					$dayago,
					'user.login_failed'
				)
			);
		}

		$cats = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT category, COUNT(*) AS total FROM %i WHERE blog_id = %d AND created_gmt >= %s AND (%d = 0 OR category IN (%s,%s,%s,%s,%s,%s,%s,%s)) GROUP BY category ORDER BY total DESC LIMIT 6',
				$table,
				$blog,
				$week,
				$scope_flag,
				$scope_cats[0],
				$scope_cats[1],
				$scope_cats[2],
				$scope_cats[3],
				$scope_cats[4],
				$scope_cats[5],
				$scope_cats[6],
				$scope_cats[7]
			),
			ARRAY_A
		);

		$users = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, user_login, user_display, COUNT(*) AS total FROM %i WHERE blog_id = %d AND created_gmt >= %s AND user_id > 0 AND (%d = 0 OR category IN (%s,%s,%s,%s,%s,%s,%s,%s)) GROUP BY user_id, user_login, user_display ORDER BY total DESC LIMIT 5',
				$table,
				$blog,
				$week,
				$scope_flag,
				$scope_cats[0],
				$scope_cats[1],
				$scope_cats[2],
				$scope_cats[3],
				$scope_cats[4],
				$scope_cats[5],
				$scope_cats[6],
				$scope_cats[7]
			),
			ARRAY_A
		);

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
	 * @param int    $scope_flag Whether the category list applies.
	 * @param array  $scope_cats Eight category slots.
	 * @return int
	 */
	private static function count_since( $table, $blog, $since, $scope_flag, array $scope_cats ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND created_gmt >= %s AND (%d = 0 OR category IN (%s,%s,%s,%s,%s,%s,%s,%s))',
				$table,
				$blog,
				$since,
				$scope_flag,
				$scope_cats[0],
				$scope_cats[1],
				$scope_cats[2],
				$scope_cats[3],
				$scope_cats[4],
				$scope_cats[5],
				$scope_cats[6],
				$scope_cats[7]
			)
		);
	}

	/**
	 * Placeholder values for the fixed event filter.
	 *
	 * @param array $args Query arguments.
	 * @return array{table:string,values:array}
	 */
	private static function filter_values( array $args ) {
		global $wpdb;

		$id       = ! empty( $args['id'] ) ? absint( $args['id'] ) : 0;
		$category = ! empty( $args['category'] ) ? Text::key( $args['category'], 32, '' ) : '';
		$in       = array( '', '', '', '', '', '', '', '' );
		$use_in   = 0;
		if ( ! empty( $args['category_in'] ) && is_array( $args['category_in'] ) ) {
			$clean = array();
			foreach ( $args['category_in'] as $cat ) {
				$key = Text::key( $cat, 32, '' );
				if ( '' !== $key ) {
					$clean[] = $key;
				}
			}
			if ( $clean ) {
				$use_in = 1;
				$in     = array_pad( array_slice( $clean, 0, 8 ), 8, '' );
			}
		}
		$severity    = ! empty( $args['severity'] ) ? Text::key( $args['severity'], 16, 'info' ) : '';
		$action      = ! empty( $args['action'] ) ? Text::key( $args['action'], 64, '' ) : '';
		$initiator   = ! empty( $args['initiator'] ) ? Text::key( $args['initiator'], 20, '' ) : '';
		$logger      = ! empty( $args['logger'] ) ? Text::key( $args['logger'], 32, '' ) : '';
		$user_id     = ! empty( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$exclude     = ! empty( $args['exclude_user_id'] ) ? absint( $args['exclude_user_id'] ) : 0;
		$object_type = ! empty( $args['object_type'] ) ? Text::key( $args['object_type'], 32, '' ) : '';
		$object_id   = ! empty( $args['object_id'] ) ? absint( $args['object_id'] ) : 0;
		$after       = ! empty( $args['after'] ) ? self::date_bound( $args['after'], false ) : '';
		$before      = ! empty( $args['before'] ) ? self::date_bound( $args['before'], true ) : '';
		$after_on    = '' !== $after ? 1 : 0;
		$before_on   = '' !== $before ? 1 : 0;
		if ( ! $after_on ) {
			$after = '1970-01-01 00:00:00';
		}
		if ( ! $before_on ) {
			$before = '1970-01-01 00:00:00';
		}
		$search      = ! empty( $args['search'] ) ? Text::plain( $args['search'], 120 ) : '';
		$like        = ( strlen( $search ) >= 2 ) ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		return array(
			'table'  => Installer::table(),
			'values' => array(
				get_current_blog_id(),
				$id ? 1 : 0,
				$id,
				'' !== $category ? 1 : 0,
				$category,
				$use_in,
				$in[0],
				$in[1],
				$in[2],
				$in[3],
				$in[4],
				$in[5],
				$in[6],
				$in[7],
				'' !== $severity ? 1 : 0,
				$severity,
				'' !== $action ? 1 : 0,
				$action,
				'' !== $initiator ? 1 : 0,
				$initiator,
				'' !== $logger ? 1 : 0,
				$logger,
				$user_id ? 1 : 0,
				$user_id,
				$exclude ? 1 : 0,
				$exclude,
				'' !== $object_type ? 1 : 0,
				$object_type,
				$object_id ? 1 : 0,
				$object_id,
				$after_on,
				$after,
				$before_on,
				$before,
				'' !== $like ? 1 : 0,
				$like,
				$like,
				$like,
				$like,
				$like,
			),
		);
	}

	/**
	 * Eight sort switches. Exactly one is enabled.
	 *
	 * @param string $column Whitelisted column.
	 * @param string $direction ASC or DESC.
	 * @return array
	 */
	private static function sort_flags( $column, $direction ) {
		$flags = array( 0, 0, 0, 0, 0, 0, 0, 0 );
		$map   = array(
			'id|ASC'           => 0,
			'id|DESC'          => 1,
			'severity|ASC'     => 2,
			'severity|DESC'    => 3,
			'category|ASC'     => 4,
			'category|DESC'    => 5,
			'created_gmt|ASC'  => 6,
			'created_gmt|DESC' => 7,
		);
		$key   = $column . '|' . $direction;
		$index = isset( $map[ $key ] ) ? $map[ $key ] : 7;
		$flags[ $index ] = 1;
		return $flags;
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
