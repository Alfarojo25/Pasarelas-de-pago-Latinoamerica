<?php
/**
 * Basic tests for Plugin Monedas core conversion.
 */
class Test_Currency_Basic extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		// Ensure plugin classes loaded.
		if ( ! class_exists( 'Plugin_Monedas\\Currency_Manager' ) ) {
			require_once dirname( __FILE__, 2 ) . '/plugin-monedas.php';
		}
		Plugin_Monedas\Currency_Manager::load_rates();
	}

	public function test_base_currency_rate_is_one() {
		$base = get_option( 'woocommerce_currency', 'USD' );
		$this->assertEquals( 1.0, Plugin_Monedas\Currency_Manager::get_rate( $base ) );
	}

	public function test_conversion_amount() {
		// Add a fake rate via option.
		update_option( 'plugin_monedas_rates', "EUR|€|0.50" );
		Plugin_Monedas\Currency_Manager::load_rates();
		// Force selected currency by simulating cookie.
		$_COOKIE['pm_currency'] = 'EUR';
		Plugin_Monedas\Currency_Manager::detect_selection();
		$converted = Plugin_Monedas\Currency_Manager::convert_amount( 100 );
		// 100 * 0.5 = 50 (before rounding adjustments)
		$this->assertEquals( 50.0, $converted );
	}
}
