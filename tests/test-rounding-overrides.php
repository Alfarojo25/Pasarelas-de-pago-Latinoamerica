<?php
class Test_Rounding_Overrides extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Plugin_Monedas\\Currency_Manager' ) ) {
			require_once dirname( __FILE__, 2 ) . '/plugin-monedas.php';
		}
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'plugin_monedas_rates', "EUR|€|2" ); // duplicar
		Plugin_Monedas\Currency_Manager::load_rates();
		$_COOKIE['pm_currency'] = 'EUR';
		Plugin_Monedas\Currency_Manager::detect_selection();
	}

	public function test_cash_rounding() {
		update_option( 'plugin_monedas_cash_round_map', "EUR|0.05" );
		$raw = 10.023; // *2 = 20.046 => redondeo cash 0.05 => 20.05
		$result = Plugin_Monedas\Currency_Manager::after_math( $raw * Plugin_Monedas\Currency_Manager::get_rate() );
		$this->assertEquals( 20.05, $result );
	}

	public function test_magnitude_rounding() {
		update_option( 'plugin_monedas_cash_round_map', '' );
		update_option( 'plugin_monedas_magnitude_round_map', "EUR|10|nearest" );
		$raw = 11; // *2 =22 -> nearest 10 => 20
		$result = Plugin_Monedas\Currency_Manager::after_math( $raw * Plugin_Monedas\Currency_Manager::get_rate() );
		$this->assertEquals( 20, $result );
	}
}
