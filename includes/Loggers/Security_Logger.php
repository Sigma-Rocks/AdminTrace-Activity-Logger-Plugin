<?php
/**
 * Security-related events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records denied admin screens, accepted file-editor saves, and Site Health changes.
 */
class Security_Logger extends Logger {

	/**
	 * File hashes captured at the start of an edit request.
	 *
	 * @var array<int,array>
	 */
	private $watches = array();

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'security';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Security signals', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Denied admin screens, plugin and theme file edits, and Site Health changes.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'admin_page_access_denied', 'on_denied', 0 );
		$this->listen( 'update_option_health-check-site-status-result', 'on_health', 2 );
		add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'watch_request' ), 0 );
		add_action( 'load-plugin-editor.php', array( $this, 'watch_request' ) );
		add_action( 'load-theme-editor.php', array( $this, 'watch_request' ) );
		add_action( 'shutdown', array( $this, 'flush_file_edit' ), 30 );
	}

	/**
	 * Admin page denied.
	 */
	public function on_denied() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
		$this->log(
			array(
				'summary'  => __( 'An admin screen was denied.', 'advance-history' ),
				'category' => 'security',
				'action'   => 'security.access_denied',
				'severity' => 'warning',
				'context'  => array(
					'path' => Text::plain( is_string( $path ) ? $path : '', 180 ),
				),
			)
		);
	}

	/**
	 * Site Health counts changed.
	 *
	 * @param mixed $old Previous JSON.
	 * @param mixed $new New JSON.
	 */
	public function on_health( $old, $new ) {
		$before = $this->health_counts( $old );
		$after  = $this->health_counts( $new );
		if ( $before === $after ) {
			return;
		}
		$critical_up = $after['critical'] > $before['critical'];
		$this->log(
			array(
				'summary'  => sprintf(
					/* translators: 1: critical count, 2: recommended count. */
					__( 'Site Health changed: %1$d critical and %2$d recommended.', 'advance-history' ),
					$after['critical'],
					$after['recommended']
				),
				'category' => 'security',
				'action'   => 'security.site_health',
				'severity' => $critical_up ? 'warning' : 'info',
				'context'  => array(
					'changes' => array(
						array(
							'field' => __( 'Critical', 'advance-history' ),
							'from'  => (string) $before['critical'],
							'to'    => (string) $after['critical'],
						),
						array(
							'field' => __( 'Recommended', 'advance-history' ),
							'from'  => (string) $before['recommended'],
							'to'    => (string) $after['recommended'],
						),
					),
				),
			)
		);
	}

	/**
	 * Snapshot a plugin or theme file before WordPress writes it.
	 */
	public function watch_request() {
		if ( ! $this->enabled() || empty( $_POST ) ) {
			return;
		}
		$post = wp_unslash( $_POST );
		$file = isset( $post['file'] ) ? (string) $post['file'] : '';
		if ( '' === $file || ! function_exists( 'validate_file' ) || 0 !== validate_file( $file ) ) {
			return;
		}

		$path = '';
		$kind = '';
		$label = '';
		if ( ! empty( $post['plugin'] ) ) {
			$plugin = (string) $post['plugin'];
			if ( 0 !== validate_file( $plugin ) ) {
				return;
			}
			$path  = WP_PLUGIN_DIR . '/' . $file;
			$kind  = 'plugin';
			$label = $plugin;
		} elseif ( ! empty( $post['theme'] ) ) {
			$theme = (string) $post['theme'];
			if ( 0 !== validate_file( $theme ) ) {
				return;
			}
			$object = wp_get_theme( $theme );
			if ( ! $object->exists() ) {
				return;
			}
			$path  = $object->get_stylesheet_directory() . '/' . $file;
			$kind  = 'theme';
			$label = $theme;
		}

		if ( '' === $path || ! $this->inside_content_dir( $path ) ) {
			return;
		}

		$hash = is_readable( $path ) ? hash_file( 'sha256', $path ) : '';
		$this->watches[] = array(
			'path'  => $path,
			'file'  => $file,
			'kind'  => $kind,
			'label' => $label,
			'hash'  => $hash,
		);
	}

	/**
	 * Log a file edit only when the file hash changed.
	 */
	public function flush_file_edit() {
		if ( ! $this->enabled() || ! $this->watches ) {
			return;
		}
		foreach ( $this->watches as $watch ) {
			if ( ! is_readable( $watch['path'] ) ) {
				continue;
			}
			$hash = hash_file( 'sha256', $watch['path'] );
			if ( ! is_string( $hash ) || $hash === $watch['hash'] ) {
				continue;
			}
			$this->log(
				array(
					'summary'      => sprintf(
						/* translators: 1: plugin or theme, 2: relative file. */
						__( 'Edited %1$s file "%2$s".', 'advance-history' ),
						$watch['kind'],
						$watch['file']
					),
					'category'     => 'security',
					'action'       => 'security.file_edited',
					'severity'     => 'critical',
					'object_type'  => $watch['kind'],
					'object_title' => $watch['label'],
					'context'      => array(
						'file' => $watch['file'],
					),
				)
			);
		}
		$this->watches = array();
	}

	/**
	 * Parse Site Health JSON.
	 *
	 * @param mixed $value Stored value.
	 * @return array{critical:int,recommended:int}
	 */
	private function health_counts( $value ) {
		$decoded = is_string( $value ) ? json_decode( $value, true ) : array();
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		return array(
			'critical'    => isset( $decoded['critical'] ) ? (int) $decoded['critical'] : 0,
			'recommended' => isset( $decoded['recommended'] ) ? (int) $decoded['recommended'] : 0,
		);
	}

	/**
	 * Whether a path stays inside plugins or themes.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private function inside_content_dir( $path ) {
		$real = realpath( $path );
		if ( false === $real && file_exists( $path ) ) {
			return false;
		}
		$target = false !== $real ? $real : $path;
		$bases  = array( WP_PLUGIN_DIR, get_theme_root() );
		foreach ( $bases as $base ) {
			$base_real = realpath( $base );
			if ( false === $base_real ) {
				continue;
			}
			$prefix = rtrim( $base_real, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( 0 === strpos( $target, $prefix ) || $target === $base_real ) {
				return true;
			}
		}
		return false;
	}
}
