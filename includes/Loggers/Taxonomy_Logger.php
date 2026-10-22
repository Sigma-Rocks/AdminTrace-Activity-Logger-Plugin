<?php
/**
 * Taxonomy events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

use SigmaRocks\AdvanceHistory\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records term creation, edits, and deletion.
 */
class Taxonomy_Logger extends Logger {

	/**
	 * Terms captured before an update.
	 *
	 * @var array<string,array>
	 */
	private $previous = array();

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'taxonomy';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Taxonomies', 'advance-history' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Categories, tags, and other terms.', 'advance-history' );
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		$this->listen( 'created_term', 'on_created', 4 );
		$this->listen( 'edit_terms', 'on_before_edit', 3 );
		$this->listen( 'edited_term', 'on_edited', 4 );
		$this->listen( 'delete_term', 'on_deleted', 4 );
	}

	/**
	 * Term created.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Arguments.
	 */
	public function on_created( $term_id, $tt_id, $taxonomy, $args = array() ) {
		unset( $tt_id, $args );
		if ( $this->skip( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: taxonomy label, 2: term name. */
					__( 'Created %1$s "%2$s".', 'advance-history' ),
					$this->label_for( $taxonomy ),
					$term->name
				),
				'category'     => 'taxonomy',
				'action'       => 'taxonomy.created',
				'severity'     => 'info',
				'object_type'  => 'term',
				'object_id'    => (int) $term_id,
				'object_title' => $term->name,
				'context'      => array( 'taxonomy' => $taxonomy ),
			)
		);
	}

	/**
	 * Snapshot a term before it is edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Arguments.
	 */
	public function on_before_edit( $term_id, $taxonomy, $args = array() ) {
		unset( $args );
		if ( $this->skip( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$this->previous[ $taxonomy . ':' . (int) $term_id ] = array(
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => Text::plain( $term->description, 200 ),
			'parent'      => (int) $term->parent,
		);
	}

	/**
	 * Term edited.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Arguments.
	 */
	public function on_edited( $term_id, $tt_id, $taxonomy, $args = array() ) {
		unset( $tt_id, $args );
		if ( $this->skip( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$key  = $taxonomy . ':' . (int) $term_id;
		$old  = isset( $this->previous[ $key ] ) ? $this->previous[ $key ] : array();
		$new  = array(
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => Text::plain( $term->description, 200 ),
			'parent'      => (int) $term->parent,
		);
		$changes = array();
		foreach ( array(
			'name'        => __( 'Name', 'advance-history' ),
			'slug'        => __( 'Slug', 'advance-history' ),
			'description' => __( 'Description', 'advance-history' ),
			'parent'      => __( 'Parent', 'advance-history' ),
		) as $field => $label ) {
			$from = isset( $old[ $field ] ) ? (string) $old[ $field ] : '';
			$to   = (string) $new[ $field ];
			if ( $from !== $to ) {
				$changes[] = array(
					'field' => $label,
					'from'  => $from,
					'to'    => $to,
				);
			}
		}
		if ( ! $changes ) {
			return;
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: taxonomy label, 2: term name. */
					__( 'Updated %1$s "%2$s".', 'advance-history' ),
					$this->label_for( $taxonomy ),
					$term->name
				),
				'category'     => 'taxonomy',
				'action'       => 'taxonomy.updated',
				'severity'     => 'info',
				'object_type'  => 'term',
				'object_id'    => (int) $term_id,
				'object_title' => $term->name,
				'context'      => array(
					'taxonomy' => $taxonomy,
					'changes'  => $changes,
				),
			)
		);
	}

	/**
	 * Term deleted.
	 *
	 * @param int      $term_id Term ID.
	 * @param int      $tt_id   Term taxonomy ID.
	 * @param string   $taxonomy Taxonomy.
	 * @param \WP_Term $deleted Deleted term.
	 */
	public function on_deleted( $term_id, $tt_id, $taxonomy, $deleted ) {
		unset( $tt_id );
		if ( $this->skip( $taxonomy ) ) {
			return;
		}
		$name = ( $deleted instanceof \WP_Term ) ? $deleted->name : (string) $term_id;
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: taxonomy label, 2: term name. */
					__( 'Deleted %1$s "%2$s".', 'advance-history' ),
					$this->label_for( $taxonomy ),
					$name
				),
				'category'     => 'taxonomy',
				'action'       => 'taxonomy.deleted',
				'severity'     => 'warning',
				'object_type'  => 'term',
				'object_id'    => (int) $term_id,
				'object_title' => $name,
				'context'      => array( 'taxonomy' => $taxonomy ),
			)
		);
	}

	/**
	 * Skip noisy or separately logged taxonomies.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private function skip( $taxonomy ) {
		if ( in_array( $taxonomy, array( 'nav_menu', 'post_format', 'link_category', 'wp_theme' ), true ) ) {
			return true;
		}
		$object = get_taxonomy( $taxonomy );
		if ( ! $object ) {
			return true;
		}
		return empty( $object->public ) && empty( $object->show_ui );
	}

	/**
	 * Taxonomy label.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private function label_for( $taxonomy ) {
		$object = get_taxonomy( $taxonomy );
		if ( $object && isset( $object->labels->singular_name ) ) {
			return $object->labels->singular_name;
		}
		return $taxonomy;
	}
}
