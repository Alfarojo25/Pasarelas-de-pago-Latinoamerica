<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Real_Multicurrency {
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'recalculate_cart_prices' ], 20 );
	}

	public static function recalculate_cart_prices( $cart ) { // phpcs:ignore
		if ( is_admin() && ! wp_doing_ajax() ) return;
		if ( ! $cart || did_action( 'plugin_monedas_real_multicurrency_applied' ) ) return;
		$selected = Currency_Manager::get_selected();
		$base = Currency_Manager::base_currency();
		if ( $selected === $base ) return;
		$rate = Currency_Manager::get_rate();
		if ( $rate === 1.0 ) return;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! isset( $item['data'] ) || ! is_object( $item['data'] ) ) continue;
			$product = $item['data'];
			// Guardar precios base una sola vez.
			if ( ! isset( $item['plugin_monedas_base_prices'] ) ) {
				$item['plugin_monedas_base_prices'] = [
					'price' => $product->get_price( 'edit' ),
					'regular_price' => $product->get_regular_price( 'edit' ),
					'sale_price' => $product->get_sale_price( 'edit' ),
				];
			}
			$base_prices = $item['plugin_monedas_base_prices'];
			$converted_price = Currency_Manager::after_math( (float) $base_prices['price'] * $rate ); // using internal rounding
			$converted_regular = Currency_Manager::after_math( (float) $base_prices['regular_price'] * $rate );
			$converted_sale = $base_prices['sale_price'] !== '' ? Currency_Manager::after_math( (float) $base_prices['sale_price'] * $rate ) : '';
			$product->set_price( $converted_price );
			if ( $converted_regular ) $product->set_regular_price( $converted_regular );
			if ( $converted_sale !== '' ) $product->set_sale_price( $converted_sale );
		}
		do_action( 'plugin_monedas_real_multicurrency_applied' );
	}
}
