<?php
class Test_Coupon_Conversion extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Plugin_Monedas\\Coupon_Manager' ) ) {
			require_once dirname( __FILE__, 2 ) . '/plugin-monedas.php';
		}
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'plugin_monedas_rates', "EUR|€|2" );
		Plugin_Monedas\Currency_Manager::load_rates();
		$_COOKIE['pm_currency'] = 'EUR';
		Plugin_Monedas\Currency_Manager::detect_selection();
	}

	public function test_fixed_cart_coupon_conversion() {
		$coupon_id = wp_insert_post( [ 'post_type' => 'shop_coupon', 'post_title' => 'TEST', 'post_status' => 'publish' ] );
		$coupon = new WC_Coupon( $coupon_id );
		$coupon->set_amount( 10 ); // base USD
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->save();
		// Simular guardado (store base amount)
		Plugin_Monedas\Coupon_Manager::store_base_amount( $coupon_id, $coupon );
		$amount = $coupon->get_amount(); // Woo todavía retorna base, filtro ajustará en runtime
		$filtered = apply_filters( 'woocommerce_coupon_get_amount', $amount, $coupon );
		$this->assertEquals( 20, $filtered ); // 10 *2
	}
}
