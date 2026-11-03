<?php
/**
 * Regression, REST, and form-handler checks. CLI only.
 *
 * Loads a WordPress site (SRAH_WP_LOAD, or the site this plugin sits in),
 * runs as temporary accounts, and removes every row, post, and account it
 * created. Settings are restored at the end. Nothing older than ten years is
 * pruned, and the journal is never cleared.
 *
 * @package AdvanceHistory
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$wp_load = getenv( 'SRAH_WP_LOAD' ) ? getenv( 'SRAH_WP_LOAD' ) : dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	echo "SKIP WordPress bootstrap not found\n";
	exit( 0 );
}

require $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! function_exists( 'srah_log' ) ) {
	require WP_PLUGIN_DIR . '/admintrace-activity-logger/admintrace-activity-logger.php';
}

use SigmaRocks\AdvanceHistory\Admin\Menu;
use SigmaRocks\AdvanceHistory\Admin\Timeline_Screen;
use SigmaRocks\AdvanceHistory\Capabilities;
use SigmaRocks\AdvanceHistory\Export;
use SigmaRocks\AdvanceHistory\Installer;
use SigmaRocks\AdvanceHistory\Logger_Registry;
use SigmaRocks\AdvanceHistory\Notifications;
use SigmaRocks\AdvanceHistory\Plugin;
use SigmaRocks\AdvanceHistory\Privacy;
use SigmaRocks\AdvanceHistory\Query;
use SigmaRocks\AdvanceHistory\Settings;
use SigmaRocks\AdvanceHistory\Support\Access;

Plugin::boot();
Installer::install();
do_action( 'rest_api_init', rest_get_server() );

/**
 * Thrown in place of a redirect so a handler does not exit.
 */
class Srah_Test_Redirect extends Exception {}

/**
 * Thrown in place of wp_die().
 */
class Srah_Test_Die extends Exception {}

$failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $ok      Condition.
 * @param string $message Message.
 */
function srah_reg( $ok, $message ) {
	global $failures;
	if ( $ok ) {
		echo "ok  {$message}\n";
		return;
	}
	++$failures;
	echo "FAIL {$message}\n";
}

/**
 * Run an admin-post handler and report how it ended.
 *
 * @param callable $handler Handler.
 * @param array    $post    POST fields.
 * @return array{0:string,1:string} redirect, die, or return, plus detail.
 */
function srah_run_handler( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		call_user_func( $handler );
	} catch ( Srah_Test_Redirect $e ) {
		return array( 'redirect', $e->getMessage() );
	} catch ( Srah_Test_Die $e ) {
		return array( 'die', $e->getMessage() );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return array( 'return', '' );
}

/**
 * Latest event matching an action, created by this run.
 *
 * @param string $action Event ID.
 * @param array  $extra  Extra query arguments.
 * @return array|null
 */
function srah_latest( $action, array $extra = array() ) {
	$result = Query::search(
		array_merge(
			array(
				'action'       => $action,
				'per_page'     => 1,
				'unrestricted' => true,
			),
			$extra
		)
	);
	return isset( $result['rows'][0] ) ? $result['rows'][0] : null;
}

/**
 * Dispatch a REST request.
 *
 * @param string $method HTTP method.
 * @param string $path   Route below the namespace.
 * @param array  $params Parameters.
 * @return WP_REST_Response
 */
function srah_rest( $method, $path, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/admintrace-activity-logger/v1' . $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

global $wpdb;
$table    = Installer::table();
$start_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
$original = Settings::all();
$token    = 'srahreg' . strtolower( wp_generate_password( 8, false, false ) );
$users    = array();
$posts    = array();
$captured = array();

$quiet                             = $original;
$quiet['alert_failed_logins']      = 0;
$quiet['webhook_url']              = '';
$quiet['webhook_min_severity']     = 'off';
$quiet['email_digest']             = 'off';
$quiet['retention_days']           = 3650;
$quiet['editors_can_view_content'] = 0;
$quiet['excluded_roles']           = array();
$quiet['excluded_user_ids']        = array();
$quiet['disabled_actions']         = array();
$quiet['disabled_loggers']         = array();
$quiet['ip_mode']                  = 'masked';
Settings::update( $quiet );

$srah_cleanup = static function () use ( &$posts, &$users, $original, $table, $start_id ) {
	static $done = false;
	global $wpdb;
	if ( $done ) {
		return;
	}
	$done = true;
	do_action( 'shutdown' );
	remove_all_actions( 'shutdown' );
	wp_set_current_user( 0 );
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $users as $user_id ) {
		if ( $user_id ) {
			wp_delete_user( $user_id );
		}
	}
	Settings::update( $original );
	SigmaRocks\AdvanceHistory\Notifications::ensure_scheduled();
	delete_transient( 'srah_fail_logins' );
	$like = $wpdb->esc_like( '_transient_srah_stats_' ) . '%';
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $table, $start_id ) );
};
register_shutdown_function( $srah_cleanup );

add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new Srah_Test_Redirect( (string) $location );
	},
	PHP_INT_MAX
);
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
			throw new Srah_Test_Die( $code . ' ' . wp_strip_all_tags( is_string( $message ) ? $message : '' ) );
		};
	}
);
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$captured ) {
		$captured[] = array(
			'url'  => $url,
			'body' => isset( $args['body'] ) ? $args['body'] : '',
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

try {
	foreach ( array( 'administrator', 'editor' ) as $role ) {
		$id = wp_insert_user(
			array(
				'user_login' => $token . '-' . $role,
				'user_email' => $token . '-' . $role . '@example.com',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			)
		);
		srah_reg( ! is_wp_error( $id ), "temporary {$role} account created" );
		$users[ $role ] = is_wp_error( $id ) ? 0 : (int) $id;
	}
	$admin  = $users['administrator'];
	$editor = $users['editor'];

	// A capability check for a non-administrator must not recurse.
	$depth = 0;
	$guard = static function ( $allcaps ) use ( &$depth ) {
		++$depth;
		if ( $depth > 50 ) {
			throw new RuntimeException( 'user_has_cap recursion' );
		}
		return $allcaps;
	};
	add_filter( 'user_has_cap', $guard, 1 );
	try {
		$editor_can = user_can( $editor, 'edit_posts' ) && ! user_can( $editor, Capabilities::VIEW );
	} catch ( RuntimeException $e ) {
		$editor_can = false;
	}
	remove_filter( 'user_has_cap', $guard, 1 );
	srah_reg( $editor_can && $depth < 10, 'an editor capability check finishes without recursion' );

	wp_set_current_user( $admin );

	// Scheduled retention.
	$old = srah_log(
		array(
			'summary'     => 'Old event ' . $token,
			'category'    => 'system',
			'action'      => 'system.test',
			'created_gmt' => '2010-01-01 00:00:00',
		)
	);
	srah_reg( $old > 0, 'an event older than the retention period can be stored' );
	do_action( 'srah_prune' );
	srah_reg( null === Query::find( $old ), 'the scheduled prune hook deletes events older than the retention period' );

	$quiet['retention_days'] = 0;
	Settings::update( $quiet );
	$kept = srah_log(
		array(
			'summary'     => 'Kept event ' . $token,
			'category'    => 'system',
			'action'      => 'system.test',
			'created_gmt' => '2010-01-01 00:00:00',
		)
	);
	do_action( 'srah_prune' );
	srah_reg( null !== Query::find( $kept ), 'a retention of 0 keeps old events when the scheduled prune runs' );
	$quiet['retention_days'] = 3650;
	Settings::update( $quiet );
	do_action( 'srah_prune' );

	// Webhook payload.
	$quiet['webhook_url']          = 'https://hooks.example.com/srah';
	$quiet['webhook_min_severity'] = 'notice';
	Settings::update( $quiet );
	$captured = array();
	$hooked   = srah_log(
		array(
			'summary'  => 'Webhook event ' . $token,
			'category' => 'system',
			'action'   => 'system.test',
			'severity' => 'warning',
		)
	);
	srah_log(
		array(
			'summary'  => 'Quiet webhook event ' . $token,
			'category' => 'system',
			'action'   => 'system.test',
			'severity' => 'info',
		)
	);
	$payload = isset( $captured[0]['body'] ) ? json_decode( $captured[0]['body'], true ) : array();
	srah_reg( 1 === count( $captured ), 'the webhook is sent only for events at or above the chosen severity' );
	srah_reg( is_array( $payload ) && isset( $payload['id'] ) && (int) $payload['id'] === $hooked && $hooked > 0, 'the webhook payload carries the stored event ID' );
	srah_reg( is_array( $payload ) && ! isset( $payload['ip'] ) && ! isset( $payload['ip_masked'] ), 'the webhook payload has no network address' );
	$quiet['webhook_url']          = '';
	$quiet['webhook_min_severity'] = 'off';
	Settings::update( $quiet );

	// Export link keeps the journal filters.
	$mine  = srah_log(
		array(
			'summary'  => 'Export match ' . $token,
			'category' => 'system',
			'action'   => 'system.test',
			'user_id'  => $editor,
		)
	);
	$other = srah_log(
		array(
			'summary'  => 'Export other ' . $token,
			'category' => 'system',
			'action'   => 'system.test',
			'user_id'  => $admin,
		)
	);
	$_GET  = array(
		'page'       => 'admintrace-activity-logger',
		's'          => 'Export',
		'exclude_me' => '1',
		'category'   => 'system',
	);
	ob_start();
	Timeline_Screen::render();
	$html = ob_get_clean();
	$href = '';
	if ( preg_match( '/href="([^"]*action=srah_export[^"]*)"/', $html, $match ) ) {
		$href = html_entity_decode( $match[1], ENT_QUOTES );
	}
	srah_reg( '' !== $href, 'the journal shows an export link' );
	$query = array();
	parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
	$_GET     = $query;
	$_REQUEST = $query;
	$filters  = Timeline_Screen::filters();
	srah_reg( 'Export' === $filters['search'], 'the export link keeps the search text' );
	srah_reg( 1 === $filters['exclude'], 'the export link keeps "Hide my events"' );
	srah_reg( 'system' === $filters['category'], 'the export link keeps the category' );
	$exported = Export::collect( Access::constrain( Timeline_Screen::query_args( $filters ) ) );
	$ids      = array_map( 'intval', wp_list_pluck( $exported, 'id' ) );
	srah_reg( in_array( $mine, $ids, true ) && ! in_array( $other, $ids, true ), 'an export from that link contains only the filtered rows' );
	$_GET     = array();
	$_REQUEST = array();

	// Theme and plugin installs.
	$theme_slug = '';
	foreach ( wp_get_themes() as $slug => $theme ) {
		$theme_slug = $slug;
		break;
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$upgrader         = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
	$upgrader->result = array( 'destination_name' => $theme_slug );
	do_action(
		'upgrader_process_complete',
		$upgrader,
		array(
			'type'   => 'theme',
			'action' => 'install',
		)
	);
	$installed = srah_latest( 'theme.installed', array( 'after' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
	srah_reg( is_array( $installed ) && wp_get_theme( $theme_slug )->get( 'Name' ) === $installed['object_title'], 'a theme install is recorded with the theme name' );

	$plugin_dir  = '';
	$plugin_name = '';
	foreach ( get_plugins() as $file => $data ) {
		if ( false !== strpos( $file, '/' ) && 0 !== strpos( $file, 'admintrace-activity-logger/' ) ) {
			$plugin_dir  = dirname( $file );
			$plugin_name = $data['Name'];
			break;
		}
	}
	if ( '' !== $plugin_dir ) {
		$warnings = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$warnings ) {
				$warnings[] = $errstr;
				return true;
			}
		);
		$upgrader         = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$upgrader->result = array( 'destination_name' => $plugin_dir );
		do_action(
			'upgrader_process_complete',
			$upgrader,
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);
		restore_error_handler();
		$installed = srah_latest( 'plugin.installed', array( 'after' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		srah_reg( is_array( $installed ) && $plugin_name === $installed['object_title'], 'a plugin install is recorded with the plugin name' );
		srah_reg( is_array( $installed ) && false === strpos( $installed['summary'], 'unknown version' ), 'a plugin install is recorded with the plugin version' );
		srah_reg( ! $warnings, 'a plugin install raises no PHP warnings' );
	}

	// Meta updates that do not change the stored value.
	$content = Logger_Registry::get( 'content' );
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Meta check ' . $token,
			'post_status' => 'draft',
			'post_type'   => 'post',
		)
	);
	$posts[] = (int) $post_id;
	add_post_meta( $post_id, '_thumbnail_id', '77' );
	$content->flush();
	// Repeats fold into one row, so compare the stored row state rather than a count.
	$latest_content = static function () use ( $wpdb, $table, $post_id ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, action, context FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1', $table, 'content', $post_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 0, '', array() );
		}
		$context = json_decode( (string) $row['context'], true );
		return array( (int) $row['id'], $row['action'], is_array( $context ) ? $context : array() );
	};
	$before         = $latest_content();
	update_post_meta( $post_id, '_thumbnail_id', 77 );
	$content->flush();
	srah_reg( $before === $latest_content(), 'saving the same featured image as an integer records nothing' );
	update_post_meta( $post_id, '_thumbnail_id', 78 );
	$content->flush();
	$after   = $latest_content();
	$changes = isset( $after[2]['changes'] ) ? $after[2]['changes'] : array();
	$moved   = $changes && 77 === (int) $changes[0]['from_attachment'] && 78 === (int) $changes[0]['to_attachment'];
	srah_reg( $moved, 'changing the featured image records the old and new image' );
	srah_reg( 'content.updated' === $after[1], 'a meta-only change to an existing post is recorded as an update, not a creation' );

	// Stats cache scope.
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_srah_stats_' ) . '%' ) ) as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
	srah_log(
		array(
			'summary'  => 'Commerce stat ' . $token,
			'category' => 'commerce',
			'action'   => 'system.test',
		)
	);
	Query::stats( true, array( 'content' ) );
	$shop = Query::stats( true, array( 'content', 'commerce' ) );
	$cats = wp_list_pluck( $shop['categories'], 'category' );
	srah_reg( in_array( 'commerce', $cats, true ), 'journal counts for one scope are not reused for a different scope' );

	// Per-request work once the schema is current.
	Installer::maybe_upgrade();
	$queries = $wpdb->num_queries;
	Installer::maybe_upgrade();
	srah_reg( $queries === $wpdb->num_queries, 'an up-to-date site runs no schema queries on each request' );

	// REST permissions and responses.
	wp_set_current_user( 0 );
	$response = srah_rest( 'GET', '/events' );
	srah_reg( 401 === $response->get_status(), 'REST: a visitor cannot list events' );
	$response = srah_rest( 'POST', '/events', array( 'summary' => 'Visitor ' . $token ) );
	srah_reg( 401 === $response->get_status(), 'REST: a visitor cannot create events' );

	wp_set_current_user( $editor );
	$response = srah_rest( 'GET', '/events' );
	srah_reg( 403 === $response->get_status(), 'REST: an editor cannot list events by default' );

	$quiet['editors_can_view_content'] = 1;
	Settings::update( $quiet );
	$content_id = srah_log(
		array(
			'summary'  => 'Content event ' . $token,
			'category' => 'content',
			'action'   => 'system.test',
		)
	);
	$secure_id  = srah_log(
		array(
			'summary'  => 'Security event ' . $token,
			'category' => 'security',
			'action'   => 'system.test',
			'severity' => 'warning',
		)
	);
	$response   = srah_rest(
		'GET',
		'/events',
		array(
			'search'   => $token,
			'per_page' => 50,
		)
	);
	$rows       = $response->get_data();
	$seen       = is_array( $rows ) ? array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) : array();
	srah_reg( 200 === $response->get_status() && in_array( $content_id, $seen, true ), 'REST: an allowed editor sees content events' );
	srah_reg( ! in_array( $secure_id, $seen, true ), 'REST: an allowed editor does not see security events' );
	$ips = is_array( $rows ) ? array_filter( wp_list_pluck( $rows, 'ip_masked' ) ) : array();
	srah_reg( ! $ips, 'REST: an editor does not receive network addresses' );
	$response = srah_rest( 'GET', '/events/' . $secure_id );
	srah_reg( 404 === $response->get_status(), 'REST: an editor cannot open a security event by ID' );
	$response = srah_rest( 'GET', '/events', array( 'category' => 'security' ) );
	srah_reg( 200 === $response->get_status() && array() === $response->get_data(), 'REST: an editor filtering by security gets nothing' );
	$response = srah_rest( 'POST', '/events', array( 'summary' => 'Editor ' . $token ) );
	srah_reg( 403 === $response->get_status(), 'REST: an editor cannot create events' );
	$response = srah_rest( 'DELETE', '/events/' . $content_id );
	srah_reg( 403 === $response->get_status(), 'REST: an editor cannot delete events' );
	$response = srah_rest( 'GET', '/stats' );
	$data     = $response->get_data();
	srah_reg( 200 === $response->get_status() && 0 === (int) $data['failed_logins'], 'REST: editor stats leave out failed logins' );
	$quiet['editors_can_view_content'] = 0;
	Settings::update( $quiet );

	wp_set_current_user( $admin );
	$response = srah_rest(
		'GET',
		'/events',
		array(
			'search'   => $token,
			'per_page' => 2,
		)
	);
	$headers  = $response->get_headers();
	srah_reg( 200 === $response->get_status() && 2 === count( $response->get_data() ), 'REST: an administrator lists events with per_page' );
	srah_reg( isset( $headers['X-WP-Total'] ) && is_string( $headers['X-WP-Total'] ) && (int) $headers['X-WP-Total'] > 2, 'REST: X-WP-Total is sent as a string' );
	srah_reg( isset( $headers['X-WP-TotalPages'] ) && (int) ceil( (int) $headers['X-WP-Total'] / 2 ) === (int) $headers['X-WP-TotalPages'], 'REST: X-WP-TotalPages matches the total' );
	$response = srah_rest(
		'POST',
		'/events',
		array(
			'summary'  => '<b>Made</b> by REST ' . $token,
			'severity' => 'bogus',
		)
	);
	$made     = (int) ( isset( $response->get_data()['id'] ) ? $response->get_data()['id'] : 0 );
	$row      = $made ? Query::find( $made ) : null;
	srah_reg( 200 === $response->get_status() && is_array( $row ), 'REST: an administrator creates an event' );
	srah_reg( is_array( $row ) && 'info' === $row['severity'] && false === strpos( $row['summary'], '<b>' ) && $admin === (int) $row['user_id'], 'REST: a created event is cleaned and owned by the caller' );
	$response = srah_rest( 'POST', '/events', array( 'summary' => '' ) );
	srah_reg( $response->get_status() >= 400, 'REST: an empty summary is rejected' );
	$response = srah_rest( 'GET', '/events/' . $made );
	srah_reg( 200 === $response->get_status(), 'REST: an administrator opens one event' );
	$response = srah_rest( 'GET', '/events/999999999' );
	srah_reg( 404 === $response->get_status(), 'REST: a missing event is 404' );
	$response = srah_rest( 'DELETE', '/events/' . $made );
	srah_reg( 200 === $response->get_status() && null === Query::find( $made ), 'REST: an administrator deletes one event' );
	$response = srah_rest( 'DELETE', '/events/' . $made );
	srah_reg( 404 === $response->get_status(), 'REST: deleting it again is 404' );

	// admin-post handlers.
	wp_set_current_user( $editor );
	$result = srah_run_handler( array( Menu::class, 'note' ), array( 'summary' => 'Editor note ' . $token ) );
	srah_reg( 'die' === $result[0] && 0 === strpos( $result[1], '403' ), 'form: an editor cannot add a journal note' );

	wp_set_current_user( $admin );
	$result = srah_run_handler( array( Menu::class, 'note' ), array( 'summary' => 'No nonce ' . $token ) );
	srah_reg( 'die' === $result[0], 'form: a note without a nonce is refused' );
	$result = srah_run_handler(
		array( Menu::class, 'note' ),
		array(
			'_wpnonce' => wp_create_nonce( 'srah_note' ),
			'summary'  => 'Admin note <i>' . $token . '</i>',
			'severity' => 'nonsense',
		)
	);
	$note   = srah_latest( 'journal.note', array( 'search' => $token ) );
	srah_reg( 'redirect' === $result[0] && is_array( $note ) && 'info' === $note['severity'] && false === strpos( $note['summary'], '<i>' ), 'form: an administrator adds a cleaned journal note' );
	$result = srah_run_handler(
		array( Menu::class, 'note' ),
		array(
			'_wpnonce' => wp_create_nonce( 'srah_note' ),
			'summary'  => '   ',
		)
	);
	srah_reg( 'redirect' === $result[0] && false !== strpos( (string) get_transient( 'srah_notice_' . $admin )['type'], 'error' ), 'form: an empty note shows an error' );
	delete_transient( 'srah_notice_' . $admin );

	$result = srah_run_handler(
		array( Menu::class, 'save_settings' ),
		array(
			'_wpnonce'         => wp_create_nonce( 'srah_save_settings' ),
			'settings_section' => 'general',
			'retention_days'   => '-5',
			'per_page'         => '13',
			'webhook_url'      => 'http://example.com/hook',
			'email_digest'     => 'hourly',
			'ip_mode'          => 'full',
			'loggers'          => array_keys( Logger_Registry::all() ),
		)
	);
	Settings::flush();
	$saved = Settings::all();
	srah_reg( 'redirect' === $result[0], 'form: settings save redirects' );
	srah_reg( 0 === $saved['retention_days'] && 25 === $saved['per_page'] && '' === $saved['webhook_url'] && 'off' === $saved['email_digest'] && 'masked' === $saved['ip_mode'], 'form: saved settings are sanitized' );
	srah_reg( array() === $saved['disabled_loggers'], 'form: checked recorders stay enabled' );
	Settings::update( $quiet );

	$result = srah_run_handler(
		array( Menu::class, 'save_settings' ),
		array(
			'_wpnonce'         => wp_create_nonce( 'srah_save_settings' ),
			'settings_section' => 'events',
			'actions'          => array( 'user.login' ),
		)
	);
	Settings::flush();
	srah_reg( in_array( 'journal.note', Settings::all()['disabled_actions'], true ) && ! in_array( 'user.login', Settings::all()['disabled_actions'], true ), 'form: the Events screen saves unchecked IDs as disabled' );
	srah_reg(
		0 === srah_log(
			array(
				'summary' => 'Disabled ' . $token,
				'action'  => 'journal.note',
			)
		),
		'a disabled event ID is not recorded'
	);

	$digest_quiet                 = $quiet;
	$digest_quiet['email_digest'] = 'daily';
	Settings::update( $digest_quiet );
	Notifications::unschedule();
	$digest_next = time() + 5 * HOUR_IN_SECONDS;
	wp_schedule_event( $digest_next, 'daily', Notifications::DIGEST );
	srah_run_handler(
		array( Menu::class, 'save_settings' ),
		array(
			'_wpnonce'         => wp_create_nonce( 'srah_save_settings' ),
			'settings_section' => 'events',
			'actions'          => array( 'user.login' ),
		)
	);
	srah_reg( wp_next_scheduled( Notifications::DIGEST ) === $digest_next, 'form: saving another settings section keeps the digest schedule' );
	Settings::update( $quiet );
	Notifications::ensure_scheduled();
	srah_reg( false === wp_next_scheduled( Notifications::DIGEST ), 'turning the digest off clears its schedule' );

	$count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	$result = srah_run_handler( array( Menu::class, 'delete_all' ), array( '_wpnonce' => wp_create_nonce( 'srah_delete_all' ) ) );
	srah_reg( 'redirect' === $result[0] && $count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'form: clearing the journal without the confirmation box deletes nothing' );
	delete_transient( 'srah_notice_' . $admin );

	$result = srah_run_handler(
		array( Menu::class, 'delete_selected' ),
		array(
			'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
			'event_ids' => array( (string) $content_id, 'abc' ),
		)
	);
	srah_reg( 'redirect' === $result[0] && null === Query::find( $content_id ) && null !== Query::find( $secure_id ), 'form: deleting selected events removes only those IDs' );
	delete_transient( 'srah_notice_' . $admin );

	// Individual deletion from the journal: two older identical rows collapse into one card.
	$group_ids = array();
	foreach ( array( 3600, 3660 ) as $age ) {
		$wpdb->insert(
			$table,
			array(
				'blog_id'     => get_current_blog_id(),
				'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age ),
				'user_id'     => $admin,
				'severity'    => 'info',
				'category'    => 'journal',
				'action'      => 'journal.note',
				'summary'     => 'Group ' . $token,
				'context'     => '{}',
			)
		);
		$group_ids[] = (int) $wpdb->insert_id;
	}
	$_GET = array(
		'page' => 'admintrace-activity-logger',
		's'    => 'Group ' . $token,
	);
	ob_start();
	Timeline_Screen::render();
	$html = (string) ob_get_clean();
	srah_reg( false !== strpos( $html, 'name="action" value="srah_delete"' ) && false !== strpos( $html, 'data-srah-confirm=' ) && false !== strpos( $html, 'data-srah-select-all' ), 'journal: an administrator sees the delete form with select all and a confirmation' );
	srah_reg( 1 === substr_count( $html, 'class="srah-event-check"' ) && false !== strpos( $html, 'value="' . $group_ids[0] . ',' . $group_ids[1] . '"' ), 'journal: a collapsed card selects every row it stands for' );
	$_GET['view'] = 'table';
	ob_start();
	Timeline_Screen::render();
	$html = (string) ob_get_clean();
	srah_reg( false !== strpos( $html, 'class="check-column"' ) && false !== strpos( $html, 'value="' . $group_ids[0] . ',' . $group_ids[1] . '"' ), 'journal: the table view has selection checkboxes' );

	wp_set_current_user( $editor );
	$quiet['editors_can_view_content'] = 1;
	Settings::update( $quiet );
	$_GET = array( 'page' => 'admintrace-activity-logger' );
	ob_start();
	Timeline_Screen::render();
	$html = (string) ob_get_clean();
	srah_reg( false === strpos( $html, 'srah_delete' ) && false === strpos( $html, 'srah-event-check' ), 'journal: an editor sees no delete form or checkboxes' );
	$quiet['editors_can_view_content'] = 0;
	Settings::update( $quiet );
	$_GET   = array();
	$result = srah_run_handler(
		array( Menu::class, 'delete_selected' ),
		array(
			'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
			'event_ids' => array( implode( ',', $group_ids ) ),
		)
	);
	srah_reg( 'die' === $result[0] && 0 === strpos( $result[1], '403' ) && null !== Query::find( $group_ids[0] ), 'form: an editor cannot delete events' );

	wp_set_current_user( $admin );
	$result = srah_run_handler( array( Menu::class, 'delete_selected' ), array( 'event_ids' => array( implode( ',', $group_ids ) ) ) );
	srah_reg( 'die' === $result[0] && null !== Query::find( $group_ids[0] ), 'form: deleting without a nonce is refused' );
	$result = srah_run_handler(
		array( Menu::class, 'delete_selected' ),
		array(
			'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
			'event_ids' => array( implode( ',', $group_ids ) ),
		)
	);
	srah_reg( 'redirect' === $result[0] && null === Query::find( $group_ids[0] ) && null === Query::find( $group_ids[1] ), 'form: deleting a collapsed card removes all of its rows' );
	$result = srah_run_handler(
		array( Menu::class, 'delete_selected' ),
		array(
			'_wpnonce'  => wp_create_nonce( 'srah_delete' ),
			'event_ids' => array(),
		)
	);
	$notice = get_transient( 'srah_notice_' . $admin );
	srah_reg( 'redirect' === $result[0] && is_array( $notice ) && 'warning' === $notice['type'], 'form: deleting with nothing selected shows a warning' );
	delete_transient( 'srah_notice_' . $admin );

	$result = srah_run_handler( array( Menu::class, 'test_email' ), array( '_wpnonce' => wp_create_nonce( 'srah_test_email' ) ) );
	Settings::flush();
	srah_reg( 'redirect' === $result[0] && 'off' === Settings::all()['email_digest'], 'form: a test digest is sent and the digest stays off' );

	$result = srah_run_handler( array( Menu::class, 'prune' ), array( '_wpnonce' => wp_create_nonce( 'srah_prune' ) ) );
	srah_reg( 'redirect' === $result[0], 'form: apply retention now runs' );
	delete_transient( 'srah_notice_' . $admin );

	// Recorder edge cases.
	srah_reg( 0 === srah_log( array( 'summary' => '' ) ), 'an empty summary is not stored' );
	srah_reg( 0 === srah_log( 'not an array' ), 'srah_log() ignores a non-array' );
	$weird = srah_log(
		array(
			'summary'  => 'Weird ' . $token,
			'severity' => 'EXTREME',
			'category' => 'Bad Category!!',
			'context'  => array( 'blob' => str_repeat( 'x', 30000 ) ),
		)
	);
	$row   = Query::find( $weird );
	srah_reg( is_array( $row ) && 'info' === $row['severity'] && 'badcategory' === $row['category'], 'severity and category are normalized' );
	srah_reg(
		0 < srah_log(
			array(
				'summary'  => 'Network category ' . $token,
				'category' => 'network',
				'action'   => 'system.test',
			)
		),
		'a network category event can be stored on any site type'
	);
	$first  = srah_log(
		array(
			'summary' => 'Repeat ' . $token,
			'action'  => 'system.test',
		)
	);
	$second = srah_log(
		array(
			'summary' => 'Repeat ' . $token,
			'action'  => 'system.test',
		)
	);
	$row    = Query::find( $first );
	srah_reg( $first > 0 && $first === $second && 2 === (int) $row['context']['repeat_count'], 'a repeat within the window updates the earlier row' );

	$quiet['excluded_roles'] = array( 'editor' );
	Settings::update( $quiet );
	srah_reg(
		0 === srah_log(
			array(
				'summary' => 'Excluded ' . $token,
				'user_id' => $editor,
			)
		),
		'info events from an excluded role are skipped'
	);
	srah_reg(
		srah_log(
			array(
				'summary'  => 'Excluded warning ' . $token,
				'user_id'  => $editor,
				'severity' => 'warning',
			)
		) > 0,
		'warnings from an excluded role are still stored'
	);
	$quiet['excluded_roles'] = array();
	$quiet['ip_mode']        = 'off';
	Settings::update( $quiet );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
	$row                    = Query::find( srah_log( array( 'summary' => 'No IP ' . $token ) ) );
	srah_reg( is_array( $row ) && '' === $row['ip_masked'], 'no address is stored when addresses are off' );
	$quiet['ip_mode'] = 'masked';
	Settings::update( $quiet );
	$row = Query::find( srah_log( array( 'summary' => 'Masked IP ' . $token ) ) );
	srah_reg( is_array( $row ) && '203.0.113.0' === $row['ip_masked'], 'the request address is stored masked' );
	unset( $_SERVER['REMOTE_ADDR'] );

	// Privacy exporter and eraser.
	srah_log(
		array(
			'summary' => 'Private ' . $token,
			'user_id' => $editor,
		)
	);
	$export = Privacy::export_personal_data( $token . '-editor@example.com', 1 );
	srah_reg( ! empty( $export['data'] ) && true === $export['done'], 'privacy export returns the account events' );
	$erase = Privacy::erase_personal_data( $token . '-editor@example.com', 1 );
	$left  = Query::search(
		array(
			'user_id'      => $editor,
			'unrestricted' => true,
		)
	);
	srah_reg( true === $erase['items_removed'] && 0 === $left['total'], 'privacy erasure removes the account events' );
	$none = Privacy::export_personal_data( 'nobody-' . $token . '@example.com', 1 );
	srah_reg( array() === $none['data'] && true === $none['done'], 'privacy export for an unknown address is empty' );

	// Multisite: site creation and deletion through the same core calls as Network Admin.
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$_SERVER['REMOTE_ADDR'] = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
		$network                = get_network();
		$path                   = '/' . $token . '/';
		$site_id                = wpmu_create_blog( $network->domain, $network->path . ltrim( $path, '/' ), 'Site ' . $token, $admin );
		$created                = is_int( $site_id ) ? srah_latest( 'network.site_created' ) : null;
		srah_reg( is_array( $created ) && (int) $created['object_id'] === $site_id, 'multisite: creating a site is recorded' );
		$site_table = $wpdb->get_blog_prefix( (int) $site_id ) . 'srah_events';
		srah_reg( $site_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_table ) ), 'multisite: a new site gets its own journal table' );
		// Core leaves installing mode on after wpmu_create_blog(); a later request starts without it.
		wp_installing( false );
		if ( is_int( $site_id ) ) {
			wpmu_delete_blog( $site_id, true );
		}
		$deleted = srah_latest( 'network.site_deleted' );
		srah_reg( is_array( $deleted ) && (int) $deleted['object_id'] === $site_id, 'multisite: deleting a site is recorded in the main journal' );
		srah_reg( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_table ) ), 'multisite: deleting a site drops its journal table' );
	}
} catch ( Throwable $e ) {
	srah_reg( false, 'regression exception: ' . get_class( $e ) . ' ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	$srah_cleanup();
}

echo 'peak memory ' . round( memory_get_peak_usage() / 1048576, 1 ) . " MB\n";
echo $failures ? "{$failures} regression checks failed\n" : "all regression checks passed\n";
exit( $failures ? 1 : 0 );
