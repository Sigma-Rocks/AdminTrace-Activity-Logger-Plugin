<?php
/**
 * File and database watches.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares plugin, theme, and PHP files, and this site's database tables, with the previous scan.
 *
 * File contents are never stored. Each plugin and theme is scanned on its own, so one large
 * plugin cannot hide the others. The first scan only saves a baseline.
 */
class Watch {

	const HOOK = 'srah_watch';

	const FILE_BASELINE_VERSION = 2;

	const PER_DIRECTORY = 6000;

	const GLOBAL_MAX = 20000;

	/**
	 * Whether a scan is already queued for this request.
	 *
	 * @var bool
	 */
	private static $queued = false;

	/**
	 * Whether a scan is running.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hashes from the previous baseline, reused when a file has not been saved since the last scan.
	 *
	 * @var array<string,string>
	 */
	private static $known = array();

	/**
	 * Unix time of the previous scan. Files older than this keep their stored hash.
	 *
	 * @var int
	 */
	private static $known_since = 0;

	/**
	 * Register the daily scan and the install hooks.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'scheduled_run' ), 10, 0 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
		foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deleted_plugin', 'deleted_theme', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue' ), 20, 0 );
		}
	}

	/**
	 * Scan during wp-admin so a saved file is recorded without Scan now.
	 *
	 * The journal screens always scan. Other admin screens scan at most once a minute.
	 */
	public static function maybe_run() {
		if ( self::$running || self::$queued || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- only decides whether to scan; Scan now verifies its own nonce.
		if ( isset( $_POST['action'] ) && 'srah_watch_now' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		$ours = 'admintrace-activity-logger' === $page || 0 === strpos( $page, 'admintrace-activity-logger-' );
		$ran  = (int) get_option( 'srah_watch_ran', 0 );
		if ( ! $ours && $ran && ( time() - $ran ) < MINUTE_IN_SECONDS ) {
			return;
		}

		self::run();
	}

	/**
	 * Run one scan after this request, once plugins or themes have finished changing.
	 */
	public static function queue() {
		if ( self::$queued || self::$running ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( __CLASS__, 'scheduled_run' ), 10, 0 );
	}

	/**
	 * Hook callback for the daily event and the queued shutdown scan.
	 */
	public static function scheduled_run() {
		self::run();
	}

	/**
	 * Schedule a daily scan when one is missing.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the scan event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run the enabled scans.
	 *
	 * @return array{files:string,tables:string}
	 */
	public static function run() {
		if ( self::$running ) {
			return array(
				'files'  => 'unchanged',
				'tables' => 'unchanged',
			);
		}
		self::$running = true;

		$settings = Settings::all();
		$result   = array(
			'files'  => ! empty( $settings['file_watch'] ) ? self::files( ! empty( $settings['file_watch_uploads'] ) ) : 'off',
			'tables' => ! empty( $settings['table_watch'] ) ? self::tables() : 'off',
		);
		update_option( 'srah_watch_ran', time(), false );
		update_option( 'srah_watch_note', self::note( $result ), false );

		self::$running = false;
		return $result;
	}

	/**
	 * Plain sentence for the scan result.
	 *
	 * @param array{files:string,tables:string} $result Scan result.
	 * @return string
	 */
	public static function note( array $result ) {
		$states = array( $result['files'], $result['tables'] );
		if ( in_array( 'error', $states, true ) ) {
			return __( 'The scan could not save its result. Run it again.', 'admintrace-activity-logger' );
		}
		if ( in_array( 'changed', $states, true ) ) {
			return __( 'New file or table changes are in the journal.', 'admintrace-activity-logger' );
		}
		if ( in_array( 'baseline', $states, true ) ) {
			return __( 'A baseline was saved. The next change to a plugin, theme, or database table is recorded.', 'admintrace-activity-logger' );
		}
		return __( 'No file or table changes since the last scan.', 'admintrace-activity-logger' );
	}

	/**
	 * Compare files with the stored baseline.
	 *
	 * @param bool $include_media Whether text files in uploads are included.
	 * @return string baseline, changed, unchanged, or error.
	 */
	public static function files( $include_media = false ) {
		$previous          = self::file_baseline();
		self::$known       = is_array( $previous ) ? $previous : array();
		self::$known_since = (int) get_option( 'srah_watch_ran', 0 );
		$found             = self::collect( (bool) $include_media );
		self::$known       = array();
		self::$known_since = 0;
		if ( ! $found ) {
			return 'error';
		}
		if ( null === $previous ) {
			self::save_files( $found );
			return 'baseline';
		}

		$added   = array_diff_key( $found, $previous );
		$deleted = array_diff_key( $previous, $found );
		$changed = array();
		foreach ( $found as $path => $hash ) {
			if ( isset( $previous[ $path ] ) && $previous[ $path ] !== $hash ) {
				$changed[ $path ] = $hash;
			}
		}
		if ( ! $added && ! $deleted && ! $changed ) {
			return 'unchanged';
		}

		$saved = self::report_files( 'files.added', $added, 'warning' )
			&& self::report_files( 'files.changed', $changed, 'notice' )
			&& self::report_files( 'files.deleted', $deleted, 'warning' );
		if ( ! $saved ) {
			return 'error';
		}

		self::save_files( $found );
		return 'changed';
	}

	/**
	 * Compare this site's tables with the stored baseline.
	 *
	 * @return string baseline, changed, unchanged, or error.
	 */
	public static function tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table scan needs the live table list.
		$names = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $names ) ) {
			return 'error';
		}
		$prefix = (string) $wpdb->prefix;
		$found  = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( '' === $prefix || 0 === strpos( $name, $prefix ) ) {
				$found[ $name ] = 1;
			}
		}
		if ( ! $found ) {
			return 'error';
		}

		$previous = get_option( 'srah_table_baseline', null );
		if ( ! is_array( $previous ) ) {
			update_option( 'srah_table_baseline', $found, false );
			return 'baseline';
		}

		$created = array_keys( array_diff_key( $found, $previous ) );
		$dropped = array_keys( array_diff_key( $previous, $found ) );
		if ( ! $created && ! $dropped ) {
			return 'unchanged';
		}

		$saved = self::report_tables( 'database.table_created', $created )
			&& self::report_tables( 'database.table_dropped', $dropped );
		if ( ! $saved ) {
			return 'error';
		}

		update_option( 'srah_table_baseline', $found, false );
		return 'changed';
	}

	/**
	 * Hash plugins, themes, drop-ins, and selected root files.
	 *
	 * @param bool $include_media Whether text files in uploads are included.
	 * @return array<string,string>
	 */
	private static function collect( $include_media ) {
		$found = array();
		foreach ( array( 'wp-config.php', '.htaccess', '.user.ini' ) as $name ) {
			$path = ABSPATH . $name;
			if ( is_file( $path ) ) {
				self::add_file( $path, $found, self::GLOBAL_MAX, 'root' );
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			self::add_directory_files( WP_CONTENT_DIR, $found, self::GLOBAL_MAX, 'php' );
			foreach ( array( 'mu-plugins', 'plugins', 'themes' ) as $folder ) {
				self::scan_children( WP_CONTENT_DIR . '/' . $folder, $found );
			}
			if ( $include_media ) {
				$uploads = wp_upload_dir( null, false );
				if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
					self::walk( $uploads['basedir'], $found, min( self::GLOBAL_MAX, count( $found ) + 300 ), 'text' );
				}
			}
		}
		return $found;
	}

	/**
	 * Scan each child of a plugins or themes directory on its own budget.
	 *
	 * @param string               $dir   Parent directory.
	 * @param array<string,string> $found Path to hash.
	 */
	private static function scan_children( $dir, array &$found ) {
		if ( count( $found ) >= self::GLOBAL_MAX || ! is_dir( $dir ) ) {
			return;
		}
		$list = scandir( $dir );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $name ) {
			if ( '.' === $name || '..' === $name || count( $found ) >= self::GLOBAL_MAX ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_file( $path ) ) {
				self::add_file( $path, $found, self::GLOBAL_MAX, 'php' );
				continue;
			}
			if ( ! is_dir( $path ) || self::skip_dir( $name ) ) {
				continue;
			}
			$bucket = array();
			$limit  = min( self::PER_DIRECTORY, self::GLOBAL_MAX - count( $found ) );
			self::fill_directory( $path, $bucket, $limit );
			foreach ( $bucket as $rel => $hash ) {
				$found[ $rel ] = $hash;
			}
		}
	}

	/**
	 * Hash PHP files in one plugin or theme first, then other code files.
	 *
	 * @param string               $dir   Directory.
	 * @param array<string,string> $files Path to hash.
	 * @param int                  $limit Maximum files for this directory.
	 */
	private static function fill_directory( $dir, array &$files, $limit ) {
		self::walk( $dir, $files, $limit, 'php' );
		if ( count( $files ) < $limit ) {
			self::walk( $dir, $files, $limit, 'code' );
		}
	}

	/**
	 * Hash files that sit directly in a directory.
	 *
	 * @param string               $dir   Directory.
	 * @param array<string,string> $files Path to hash.
	 * @param int                  $limit Maximum files.
	 * @param string               $mode  File mode.
	 */
	private static function add_directory_files( $dir, array &$files, $limit, $mode ) {
		if ( count( $files ) >= $limit || ! is_dir( $dir ) ) {
			return;
		}
		$list = scandir( $dir );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $name ) {
			if ( '.' === $name || '..' === $name || count( $files ) >= $limit ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_file( $path ) ) {
				self::add_file( $path, $files, $limit, $mode );
			}
		}
	}

	/**
	 * Walk a directory breadth-first so shallow files are kept when the cap is reached.
	 *
	 * @param string               $dir   Directory.
	 * @param array<string,string> $files Path to hash.
	 * @param int                  $limit Maximum files.
	 * @param string               $mode  File mode.
	 */
	private static function walk( $dir, array &$files, $limit, $mode ) {
		if ( count( $files ) >= $limit || ! is_dir( $dir ) ) {
			return;
		}
		$queue = array( $dir );
		$seen  = array();
		$root  = realpath( $dir );
		if ( is_string( $root ) ) {
			$seen[ $root ] = true;
		}
		while ( $queue ) {
			if ( count( $files ) >= $limit ) {
				break;
			}
			$current = array_shift( $queue );
			$list    = scandir( $current );
			if ( ! is_array( $list ) ) {
				continue;
			}
			$children = array();
			foreach ( $list as $name ) {
				if ( '.' === $name || '..' === $name || count( $files ) >= $limit ) {
					continue;
				}
				$path = $current . '/' . $name;
				if ( is_dir( $path ) ) {
					if ( self::skip_dir( $name ) ) {
						continue;
					}
					$real = realpath( $path );
					if ( ! is_string( $real ) || isset( $seen[ $real ] ) ) {
						continue;
					}
					$seen[ $real ] = true;
					$children[]    = $path;
					continue;
				}
				self::add_file( $path, $files, $limit, $mode );
			}
			foreach ( $children as $child ) {
				$queue[] = $child;
			}
		}
	}

	/**
	 * Hash one file when it is in scope.
	 *
	 * @param string               $path  Absolute path.
	 * @param array<string,string> $files Path to hash.
	 * @param int                  $limit Maximum files.
	 * @param string               $mode  php, code, root, or text.
	 */
	private static function add_file( $path, array &$files, $limit, $mode ) {
		if ( count( $files ) >= $limit || ! is_file( $path ) || ! self::matches( $path, $mode ) ) {
			return;
		}
		$size = filesize( $path );
		if ( false === $size || $size > 2 * 1024 * 1024 ) {
			return;
		}
		$rel = self::relative( $path );
		if ( '' === $rel || isset( $files[ $rel ] ) ) {
			return;
		}
		$mtime = filemtime( $path );
		if ( isset( self::$known[ $rel ] ) && false !== $mtime && $mtime < self::$known_since ) {
			$files[ $rel ] = self::$known[ $rel ];
			return;
		}
		$hash = hash_file( 'sha256', $path );
		if ( ! is_string( $hash ) ) {
			return;
		}
		$files[ $rel ] = substr( $hash, 0, 16 );
	}

	/**
	 * Directory names that are caches or package folders.
	 *
	 * @param string $name Directory name.
	 * @return bool
	 */
	private static function skip_dir( $name ) {
		return in_array(
			strtolower( $name ),
			array( 'node_modules', '.git', 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'backups', 'wflogs' ),
			true
		);
	}

	/**
	 * Whether a file belongs in this pass.
	 *
	 * @param string $path Absolute path.
	 * @param string $mode php, code, root, or text.
	 * @return bool
	 */
	private static function matches( $path, $mode ) {
		$base    = strtolower( wp_basename( $path ) );
		$ext     = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$php     = in_array( $ext, array( 'php', 'phtml', 'php5' ), true );
		$special = in_array( $base, array( '.htaccess', '.user.ini', 'wp-config.php' ), true );
		if ( 'php' === $mode ) {
			return $php;
		}
		if ( 'code' === $mode ) {
			return in_array( $ext, array( 'js', 'css', 'htaccess', 'html', 'htm' ), true ) || $special;
		}
		if ( 'root' === $mode ) {
			return $php || $special;
		}
		return $php || in_array( $ext, array( 'json', 'xml', 'txt', 'svg', 'html', 'htm' ), true );
	}

	/**
	 * Path relative to the WordPress root.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative( $path ) {
		$base = wp_normalize_path( ABSPATH );
		$path = wp_normalize_path( $path );
		if ( 0 !== strpos( $path, $base ) ) {
			return '';
		}
		$rel = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) || strlen( $rel ) > 180 ) {
			return '';
		}
		return $rel;
	}

	/**
	 * Stored file hashes, or null when the baseline must be rebuilt.
	 *
	 * @return array<string,string>|null
	 */
	private static function file_baseline() {
		$stored = get_option( 'srah_file_baseline', null );
		if ( ! is_array( $stored ) || ! isset( $stored['v'], $stored['files'] ) || self::FILE_BASELINE_VERSION !== (int) $stored['v'] || ! is_array( $stored['files'] ) ) {
			return null;
		}
		return $stored['files'];
	}

	/**
	 * Save the file baseline.
	 *
	 * @param array<string,string> $found Path to hash.
	 */
	private static function save_files( array $found ) {
		update_option(
			'srah_file_baseline',
			array(
				'v'     => self::FILE_BASELINE_VERSION,
				'files' => $found,
			),
			false
		);
	}

	/**
	 * Store a limited set of file changes.
	 *
	 * @param string               $action   Event ID.
	 * @param array<string,string> $paths    Changed paths.
	 * @param string               $severity Severity.
	 * @return bool
	 */
	private static function report_files( $action, array $paths, $severity ) {
		$total = count( $paths );
		$keys  = array_keys( $paths );
		usort(
			$keys,
			static function ( $left, $right ) {
				$left_time  = is_file( ABSPATH . $left ) ? filemtime( ABSPATH . $left ) : false;
				$right_time = is_file( ABSPATH . $right ) ? filemtime( ABSPATH . $right ) : false;
				if ( $left_time === $right_time ) {
					return 0;
				}
				if ( false === $left_time ) {
					return 1;
				}
				if ( false === $right_time ) {
					return -1;
				}
				return $right_time <=> $left_time;
			}
		);
		$paths = array_slice( $keys, 0, 20 );
		if ( ! $paths ) {
			return true;
		}
		$verb = 'files.added' === $action ? __( 'Added', 'admintrace-activity-logger' ) : ( 'files.deleted' === $action ? __( 'Deleted', 'admintrace-activity-logger' ) : __( 'Changed', 'admintrace-activity-logger' ) );
		$id   = Recorder::log(
			array(
				'summary'   => sprintf(
					/* translators: 1: Added, Changed, or Deleted, 2: file count, 3: first path. */
					__( '%1$s %2$d file(s) on disk. First: %3$s.', 'admintrace-activity-logger' ),
					$verb,
					$total,
					Text::plain( $paths[0], 180 )
				),
				'category'  => 'files',
				'action'    => $action,
				'severity'  => $severity,
				'logger'    => 'files',
				'initiator' => 'cron',
				'user_id'   => 0,
				'context'   => array( 'paths' => array_map( array( Text::class, 'plain' ), $paths ) ),
			)
		);
		return self::saved( $id );
	}

	/**
	 * Store created or dropped table names.
	 *
	 * @param string   $action Event ID.
	 * @param string[] $names  Table names.
	 * @return bool
	 */
	private static function report_tables( $action, array $names ) {
		$total = count( $names );
		$names = array_slice( $names, 0, 20 );
		if ( ! $names ) {
			return true;
		}
		$clean = array();
		foreach ( $names as $name ) {
			$clean[] = Text::plain( $name, 64 );
		}
		$created = 'database.table_created' === $action;
		$id      = Recorder::log(
			array(
				'summary'   => sprintf(
					$created
						/* translators: 1: table count, 2: first table name. */
						? __( 'Created %1$d database table(s). First: %2$s.', 'admintrace-activity-logger' )
						/* translators: 1: table count, 2: first table name. */
						: __( 'Dropped %1$d database table(s). First: %2$s.', 'admintrace-activity-logger' ),
					$total,
					$clean[0]
				),
				'category'  => 'database',
				'action'    => $action,
				'severity'  => 'warning',
				'logger'    => 'database',
				'initiator' => 'cron',
				'user_id'   => 0,
				'context'   => array( 'tables' => $clean ),
			)
		);
		return self::saved( $id );
	}

	/**
	 * Whether a journal write landed, or was skipped on purpose.
	 *
	 * A database error keeps the previous baseline so the next scan can try again.
	 *
	 * @param int $id Event ID.
	 * @return bool
	 */
	private static function saved( $id ) {
		global $wpdb;

		if ( $id ) {
			return true;
		}
		return '' === (string) $wpdb->last_error;
	}
}
