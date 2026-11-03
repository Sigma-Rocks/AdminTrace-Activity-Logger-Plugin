<?php
/**
 * Separate log of email WordPress sends.
 *
 * Rows live in their own table. The activity journal never receives the message body.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Ip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures wp_mail() when the email log is enabled.
 */
class Email_Log {

	const PAGE = 'admintrace-activity-logger-email';

	/**
	 * Open wp_mail() calls, newest last. Zero means that call was not stored.
	 *
	 * @var int[]
	 */
	private static $stack = array();

	/**
	 * Re-entry guard.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Whether a missing-table repair has already been tried during this request.
	 *
	 * @var bool
	 */
	private static $retried = false;

	/**
	 * Drop in-request pairing between emails.
	 *
	 * Rows already stored are kept. Tests use this so one process cannot
	 * attach a result to an email from an earlier case.
	 */
	public static function reset_request_state() {
		self::$stack   = array();
		self::$busy    = false;
		self::$retried = false;
	}

	/**
	 * Register capture hooks.
	 */
	public static function register() {
		self::ensure_table();
		add_filter( 'wp_mail', array( __CLASS__, 'capture' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'note_short_circuit' ), PHP_INT_MAX, 2 );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'mark_sent' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mark_failed' ) );
	}

	/**
	 * Table for a site.
	 *
	 * @param int|null $blog_id Site ID, or null for the current site.
	 * @return string
	 */
	public static function table( $blog_id = null ) {
		global $wpdb;
		$prefix = null === $blog_id ? $wpdb->prefix : $wpdb->get_blog_prefix( (int) $blog_id );
		return $prefix . 'srah_email_log';
	}

	/**
	 * Whether new emails are stored.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( Settings::all()['email_log_enabled'] );
	}

	/**
	 * Create the email log table.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_gmt datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip_masked varchar(45) NOT NULL DEFAULT '',
			to_email text NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			attachments text NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			error_message text NOT NULL,
			PRIMARY KEY  (id),
			KEY sent_gmt (sent_gmt),
			KEY status_sent (status, sent_gmt),
			KEY blog_sent (blog_id, sent_gmt)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Create the email table when an upgrade recorded the schema version without it.
	 *
	 * @return bool Whether the table exists afterwards.
	 */
	public static function ensure_table() {
		$flag = 'srah_email_table';
		if ( (string) get_option( $flag ) === (string) SRAH_DB_VERSION ) {
			return true;
		}

		self::install();
		if ( ! self::table_exists() ) {
			return false;
		}

		update_option( $flag, SRAH_DB_VERSION, true );
		return true;
	}

	/**
	 * Whether this site's email table is present.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one existence check for the plugin's own table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
		return $found === self::table();
	}

	/**
	 * Store one outgoing email, then let WordPress send it.
	 *
	 * @param mixed $mail wp_mail arguments.
	 * @return mixed
	 */
	public static function capture( $mail ) {
		if ( ! is_array( $mail ) || self::$busy ) {
			return $mail;
		}

		if ( ! self::enabled() ) {
			self::$stack[] = 0;
			return $mail;
		}

		self::$busy = true;
		try {
			$id = self::store( $mail );
		} catch ( \Throwable $e ) {
			unset( $e );
			$id = 0;
		}
		self::$busy = false;

		self::$stack[] = (int) $id;
		return $mail;
	}

	/**
	 * Record the result when another plugin stops wp_mail() early.
	 *
	 * @param mixed $pre  Short-circuit value.
	 * @param array $atts Mail arguments.
	 * @return mixed
	 */
	public static function note_short_circuit( $pre, $atts ) {
		unset( $atts );
		if ( null === $pre ) {
			return $pre;
		}

		if ( false === $pre ) {
			self::finish( 'failed', __( 'WordPress stopped this email before the mailer ran.', 'admintrace-activity-logger' ) );
		} else {
			self::finish( 'sent', '' );
		}

		return $pre;
	}

	/**
	 * Mark the newest open row as sent.
	 *
	 * @param mixed $mail_data Mail arguments after sending.
	 */
	public static function mark_sent( $mail_data ) {
		unset( $mail_data );
		self::finish( 'sent', '' );
	}

	/**
	 * Mark the newest open row as failed.
	 *
	 * @param mixed $error Failure from wp_mail().
	 */
	public static function mark_failed( $error ) {
		$message = ( $error instanceof \WP_Error ) ? $error->get_error_message() : '';
		self::finish( 'failed', $message );
	}

	/**
	 * Delete emails older than the journal retention period.
	 *
	 * @param int $days Days to keep. Zero keeps every row.
	 * @return int
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE sent_gmt < %s',
				self::table(),
				$cutoff
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * List saved emails for the current site.
	 *
	 * @param array $args s, status, page, per_page.
	 * @return array{rows:array<int,array>,total:int,pages:int}
	 */
	public static function search( array $args ) {
		global $wpdb;

		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 25;
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where, $params ) = self::filters( $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is placeholders only; values are passed to prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, ...array_merge( array( self::table() ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, sent_gmt, user_id, user_login, to_email, subject, attachments, status, error_message FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				...array_merge( array( self::table() ), $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * One saved email on the current site.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND id = %d',
				self::table(),
				get_current_blog_id(),
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete specific rows on the current site.
	 *
	 * @param int[] $ids Row IDs.
	 * @return int
	 */
	public static function delete_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$ids = array_slice( $ids, 0, 100 );
		if ( ! $ids ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE blog_id = %d AND id = %d',
					self::table(),
					get_current_blog_id(),
					$id
				)
			);
			$deleted += (int) $wpdb->rows_affected;
		}

		return $deleted;
	}

	/**
	 * Delete every saved email on the current site.
	 *
	 * @return int
	 */
	public static function delete_all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE blog_id = %d',
				self::table(),
				get_current_blog_id()
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Emails addressed to one person, for the personal-data exporter.
	 *
	 * @param string $email    Email address.
	 * @param int    $page     Page number.
	 * @param int    $per_page Page size.
	 * @return array{rows:array<int,array>,done:bool}
	 */
	public static function for_address( $email, $page, $per_page ) {
		global $wpdb;

		list( $match, $params ) = self::address_clause( $email );
		if ( '0 = 1' === $match ) {
			return array(
				'rows' => array(),
				'done' => true,
			);
		}

		$page     = max( 1, (int) $page );
		$per_page = min( 50, max( 1, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $match is placeholders only; values are passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, sent_gmt, to_email, subject, message, status FROM %i WHERE blog_id = %d AND ' . $match . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				...array_merge( array( self::table(), get_current_blog_id() ), $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = is_array( $rows ) ? $rows : array();

		return array(
			'rows' => $rows,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Remove saved emails addressed to one person.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	public static function delete_for_address( $email ) {
		global $wpdb;

		list( $match, $params ) = self::address_clause( $email );
		if ( '0 = 1' === $match ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $match is placeholders only; values are passed to prepare().
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE blog_id = %d AND ' . $match,
				...array_merge( array( self::table(), get_current_blog_id() ), $params )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Remove the sender identity from emails this account sent to other people.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function anonymize_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_id = 0, user_login = %s WHERE blog_id = %d AND user_id = %d',
				self::table(),
				'',
				get_current_blog_id(),
				$user_id
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Insert a row and return its ID.
	 *
	 * @param array $mail wp_mail arguments.
	 * @return int
	 */
	private static function store( array $mail ) {
		$mail = wp_parse_args(
			$mail,
			array(
				'to'          => '',
				'subject'     => '',
				'message'     => '',
				'headers'     => '',
				'attachments' => array(),
				'embeds'      => array(),
			)
		);

		$settings = Settings::all();
		$user     = wp_get_current_user();
		$ip       = '';
		$cron     = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$cli      = defined( 'WP_CLI' ) && WP_CLI;
		if ( 'off' !== $settings['ip_mode'] && ! $cron && ! $cli ) {
			$ip = Ip::from_request( $_SERVER, ! empty( $settings['trust_proxy'] ) );
		}

		$files = array_merge( self::names( $mail['attachments'] ), self::names( $mail['embeds'] ) );
		$row   = array(
			'blog_id'       => get_current_blog_id(),
			'sent_gmt'      => current_time( 'mysql', true ),
			'user_id'       => ( $user instanceof \WP_User ) ? (int) $user->ID : 0,
			'user_login'    => ( $user instanceof \WP_User ) ? sanitize_user( $user->user_login, true ) : '',
			'ip_masked'     => $ip,
			'to_email'      => self::clip( self::list_text( $mail['to'] ), 2000 ),
			'subject'       => self::clip( self::list_text( $mail['subject'] ), 500 ),
			'message'       => self::clip( is_scalar( $mail['message'] ) ? (string) $mail['message'] : '', 200000 ),
			'headers'       => self::clip( self::list_text( $mail['headers'], "\n" ), 10000 ),
			'attachments'   => self::clip( implode( ', ', $files ), 2000 ),
			'status'        => 'pending',
			'error_message' => '',
		);

		$row = apply_filters( 'srah_email_log_entry', $row, $mail );
		if ( ! is_array( $row ) ) {
			return 0;
		}

		return self::insert_row(
			array(
				'blog_id'       => isset( $row['blog_id'] ) ? absint( $row['blog_id'] ) : get_current_blog_id(),
				'sent_gmt'      => self::datetime( isset( $row['sent_gmt'] ) ? $row['sent_gmt'] : '' ),
				'user_id'       => isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0,
				'user_login'    => self::clip( isset( $row['user_login'] ) ? (string) $row['user_login'] : '', 60 ),
				'ip_masked'     => self::clip( isset( $row['ip_masked'] ) ? (string) $row['ip_masked'] : '', 45 ),
				'to_email'      => self::clip( isset( $row['to_email'] ) ? (string) $row['to_email'] : '', 2000 ),
				'subject'       => self::clip( isset( $row['subject'] ) ? (string) $row['subject'] : '', 500 ),
				'message'       => self::clip( isset( $row['message'] ) ? (string) $row['message'] : '', 200000 ),
				'headers'       => self::clip( isset( $row['headers'] ) ? (string) $row['headers'] : '', 10000 ),
				'attachments'   => self::clip( isset( $row['attachments'] ) ? (string) $row['attachments'] : '', 2000 ),
				'status'        => 'pending',
				'error_message' => '',
			)
		);
	}

	/**
	 * Insert one row, creating the table first when it is missing.
	 *
	 * @param array $data Column values.
	 * @return int
	 */
	private static function insert_row( array $data ) {
		global $wpdb;

		self::ensure_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own email table has no WordPress API.
		$ok = $wpdb->insert(
			self::table(),
			$data,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( $ok ) {
			return (int) $wpdb->insert_id;
		}

		$missing = false !== stripos( (string) $wpdb->last_error, 'exist' );
		if ( $missing && ! self::$retried ) {
			self::$retried = true;
			delete_option( 'srah_email_table' );
			self::ensure_table();
			return self::insert_row( $data );
		}

		return 0;
	}

	/**
	 * Update the result of one open row.
	 *
	 * @param string $status sent or failed.
	 * @param string $error  Failure text.
	 */
	private static function finish( $status, $error ) {
		global $wpdb;

		if ( ! self::$stack ) {
			return;
		}
		$id = (int) array_pop( self::$stack );
		if ( ! $id ) {
			return;
		}
		if ( ! in_array( $status, array( 'sent', 'failed' ), true ) ) {
			$status = 'failed';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own email table has no WordPress API.
		$wpdb->update(
			self::table(),
			array(
				'status'        => $status,
				'error_message' => self::clip( sanitize_text_field( (string) $error ), 1000 ),
			),
			array(
				'id'      => (int) $id,
				'blog_id' => get_current_blog_id(),
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * WHERE clause for the list screen.
	 *
	 * @param array $args Filter arguments.
	 * @return array{0:string,1:array}
	 */
	private static function filters( array $args ) {
		global $wpdb;

		$where  = array( 'blog_id = %d' );
		$params = array( get_current_blog_id() );
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		if ( in_array( $status, array( 'sent', 'failed', 'pending' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$search = isset( $args['s'] ) ? trim( (string) $args['s'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(to_email LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Match a recipient address stored alone, in a list, or as Name <email>.
	 *
	 * @param string $email Email address.
	 * @return array{0:string,1:array}
	 */
	private static function address_clause( $email ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return array( '0 = 1', array() );
		}

		$safe = $wpdb->esc_like( $email );
		$sql  = '(to_email = %s OR to_email LIKE %s OR to_email LIKE %s OR to_email LIKE %s OR to_email LIKE %s OR to_email LIKE %s OR to_email LIKE %s OR to_email LIKE %s)';

		return array(
			$sql,
			array(
				$email,
				$safe . ',%',
				'%, ' . $safe,
				'%,' . $safe,
				'%, ' . $safe . ',%',
				'%,' . $safe . ',%',
				'%< ' . $safe . '>%',
				'%<' . $safe . '>%',
			),
		);
	}

	/**
	 * File names only, so server paths are not stored.
	 *
	 * @param mixed $files Attachment or embed value.
	 * @return string[]
	 */
	private static function names( $files ) {
		if ( is_string( $files ) ) {
			$files = preg_split( '/[\r\n,]+/', $files );
		}
		if ( ! is_array( $files ) ) {
			return array();
		}

		$names = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$file = trim( $file );
			if ( '' === $file ) {
				continue;
			}
			$names[] = wp_basename( $file );
			if ( count( $names ) >= 20 ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * Flatten a mail field that may be a string or a list.
	 *
	 * @param mixed  $value     Field value.
	 * @param string $separator Join string.
	 * @return string
	 */
	private static function list_text( $value, $separator = ', ' ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$text = trim( (string) $item );
					if ( '' !== $text ) {
						$parts[] = $text;
					}
				}
			}
			return implode( $separator, $parts );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Keep a MySQL datetime, or use now.
	 *
	 * @param mixed $value Candidate datetime.
	 * @return string
	 */
	private static function datetime( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return current_time( 'mysql', true );
	}

	/**
	 * Limit stored text by bytes.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max );
	}
}
