<?php
/**
 * WooCommerce integration.
 *
 * @package AdvanceHistory
 */

use SigmaRocks\AdvanceHistory\Capabilities;

/**
 * Orders, stock, and product changes.
 */
class Test_WooCommerce extends Srah_Test_Case {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded. Set SRAH_TESTS_WC_FILE to woocommerce.php to run these tests.' );
		}
	}

	/**
	 * A saved simple product.
	 *
	 * @return WC_Product_Simple
	 */
	private function product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Blue mug' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();
		return $product;
	}

	public function test_order_status_changes_are_recorded_without_customer_details() {
		$order = wc_create_order();
		$order->set_billing_email( 'customer@example.org' );
		$order->set_billing_first_name( 'Casey' );
		$order->save();
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$row = $this->latest( 'commerce.order_status' );
		$this->assertIsArray( $row );
		$this->assertSame( $order->get_id(), (int) $row['object_id'] );
		$this->assertStringContainsString( 'processing', $row['summary'] );
		$this->assertStringContainsString( 'completed', $row['summary'] );
		$this->assertStringNotContainsString( 'customer@example.org', wp_json_encode( $row ) );
		$this->assertStringNotContainsString( 'Casey', wp_json_encode( $row ) );
	}

	public function test_refunds_are_recorded() {
		$order = wc_create_order();
		$order->set_total( '20' );
		$order->save();
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5',
			)
		);
		$row = $this->latest( 'commerce.refund' );
		$this->assertIsArray( $row );
		$this->assertSame( 'warning', $row['severity'] );
		$this->assertSame( '5', $row['context']['amount'] );
	}

	public function test_stock_and_price_changes_are_recorded() {
		$product = $this->product();
		wc_update_product_stock( $product, 2 );
		$stock = $this->latest( 'commerce.stock' );
		$this->assertIsArray( $stock );
		$this->assertStringContainsString( 'is now 2', $stock['summary'] );

		$product = wc_get_product( $product->get_id() );
		$product->set_regular_price( '12' );
		$product->save();
		$updated = $this->latest( 'commerce.product_updated' );
		$this->assertIsArray( $updated );
		$this->assertContains( 'regular_price', $updated['context']['fields'] );
	}

	public function test_shop_managers_see_commerce_only_when_allowed() {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->assertFalse( user_can( $manager, Capabilities::VIEW ) );
		$this->settings( array( 'editors_can_view_content' => 1 ) );
		$this->assertTrue( user_can( $manager, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $manager, Capabilities::DELETE ) );
	}
}
