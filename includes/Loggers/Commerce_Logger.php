<?php
/**
 * WooCommerce events.
 *
 * @package AdvanceHistory
 */

namespace SigmaRocks\AdvanceHistory\Loggers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records orders, refunds, and stock when WooCommerce is active.
 *
 * Customer email addresses are not stored.
 */
class Commerce_Logger extends Logger {

	/**
	 * Product IDs already logged for stock during this request.
	 *
	 * @var array<int,bool>
	 */
	private $stock_logged = array();

	/**
	 * Logger ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'commerce';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WooCommerce', 'admintrace-activity-logger' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Orders, refunds, stock, and product price changes when WooCommerce is active.', 'admintrace-activity-logger' );
	}

	/**
	 * Register hooks when WooCommerce is present.
	 */
	public function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$this->listen( 'woocommerce_order_status_changed', 'on_status', 4 );
		$this->listen( 'woocommerce_order_refunded', 'on_refund', 2 );
		$this->listen( 'woocommerce_product_set_stock', 'on_stock', 1 );
		$this->listen( 'woocommerce_variation_set_stock', 'on_stock', 1 );
		$this->listen( 'woocommerce_product_object_updated_props', 'on_product_props', 2 );
	}

	/**
	 * Order status changed.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order object.
	 */
	public function on_status( $order_id, $from, $to, $order ) {
		$number = $this->order_number( $order, $order_id );
		$total  = ( is_object( $order ) && method_exists( $order, 'get_total' ) ) ? $order->get_total() : '';
		$currency = ( is_object( $order ) && method_exists( $order, 'get_currency' ) ) ? $order->get_currency() : '';
		$placed = in_array( (string) $from, array( '', 'checkout-draft', 'new', 'auto-draft' ), true );
		$summary = $placed
			? sprintf(
				/* translators: 1: order number, 2: status. */
				__( 'Placed order %1$s (%2$s).', 'admintrace-activity-logger' ),
				$number,
				sanitize_key( (string) $to )
			)
			: sprintf(
				/* translators: 1: order number, 2: old status, 3: new status. */
				__( 'Order %1$s changed from %2$s to %3$s.', 'admintrace-activity-logger' ),
				$number,
				sanitize_key( (string) $from ),
				sanitize_key( (string) $to )
			);
		$this->log(
			array(
				'summary'      => $summary,
				'category'     => 'commerce',
				'action'       => $placed ? 'commerce.order_placed' : 'commerce.order_status',
				'severity'     => 'notice',
				'object_type'  => 'content',
				'object_id'    => absint( $order_id ),
				'object_title' => $number,
				'context'      => array(
					'total'    => is_scalar( $total ) ? (string) $total : '',
					'currency' => is_scalar( $currency ) ? (string) $currency : '',
				),
			)
		);
	}

	/**
	 * Order refunded.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_refund( $order_id, $refund_id ) {
		$amount = '';
		if ( function_exists( 'wc_get_order' ) ) {
			$refund = wc_get_order( $refund_id );
			if ( is_object( $refund ) && method_exists( $refund, 'get_amount' ) ) {
				$amount = (string) $refund->get_amount();
			}
		}
		$this->log(
			array(
				'summary'     => sprintf(
					/* translators: %d: order ID. */
					__( 'Refunded order %d.', 'admintrace-activity-logger' ),
					absint( $order_id )
				),
				'category'    => 'commerce',
				'action'      => 'commerce.refund',
				'severity'    => 'warning',
				'object_type' => 'content',
				'object_id'   => absint( $order_id ),
				'context'     => array( 'amount' => $amount ),
			)
		);
	}

	/**
	 * Stock changed.
	 *
	 * @param mixed $product Product.
	 */
	public function on_stock( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$id = (int) $product->get_id();
		$this->stock_logged[ $id ] = true;
		$qty = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : '';
		$name = method_exists( $product, 'get_name' ) ? $product->get_name() : (string) $id;
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: product name, 2: stock quantity. */
					__( 'Stock for "%1$s" is now %2$s.', 'admintrace-activity-logger' ),
					$name,
					is_scalar( $qty ) ? (string) $qty : __( 'unknown', 'admintrace-activity-logger' )
				),
				'category'     => 'commerce',
				'action'       => 'commerce.stock',
				'severity'     => 'info',
				'object_type'  => 'content',
				'object_id'    => $id,
				'object_title' => $name,
			)
		);
	}

	/**
	 * Product properties changed.
	 *
	 * @param mixed $product Product.
	 * @param array $props   Updated property names.
	 */
	public function on_product_props( $product, $props ) {
		if ( ! is_object( $product ) || ! is_array( $props ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$interesting = array_values( array_intersect( $props, array( 'regular_price', 'sale_price', 'price', 'sku', 'stock_status', 'catalog_visibility' ) ) );
		if ( ! $interesting ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( array( 'stock_status' ) === $interesting && ! empty( $this->stock_logged[ $id ] ) ) {
			return;
		}
		$name = method_exists( $product, 'get_name' ) ? $product->get_name() : (string) $id;
		$context = array( 'fields' => $interesting );
		if ( in_array( 'sku', $interesting, true ) && method_exists( $product, 'get_sku' ) ) {
			$context['sku'] = (string) $product->get_sku();
		}
		if ( in_array( 'price', $interesting, true ) && method_exists( $product, 'get_price' ) ) {
			$context['price'] = (string) $product->get_price();
		}
		$this->log(
			array(
				'summary'      => sprintf(
					/* translators: 1: product name, 2: field list. */
					__( 'Updated product "%1$s" (%2$s).', 'admintrace-activity-logger' ),
					$name,
					implode( ', ', $interesting )
				),
				'category'     => 'commerce',
				'action'       => 'commerce.product_updated',
				'severity'     => 'info',
				'object_type'  => 'content',
				'object_id'    => $id,
				'object_title' => $name,
				'context'      => $context,
			)
		);
	}

	/**
	 * Order number.
	 *
	 * @param mixed $order Order.
	 * @param int   $order_id Order ID.
	 * @return string
	 */
	private function order_number( $order, $order_id ) {
		if ( is_object( $order ) && method_exists( $order, 'get_order_number' ) ) {
			return (string) $order->get_order_number();
		}
		return (string) absint( $order_id );
	}
}
