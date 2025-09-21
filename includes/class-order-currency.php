<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Order_Currency {
	public static function init() {
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'set_order_currency_meta' ], 20, 2 );
	}

	public static function set_order_currency_meta( $order, $data ) { // phpcs:ignore
		$selected = Currency_Manager::get_selected();
		$base = Currency_Manager::base_currency();
		$rate = Currency_Manager::get_rate();
		$order->update_meta_data( '_plugin_monedas_selected_currency', $selected );
		$order->update_meta_data( '_plugin_monedas_base_currency', $base );
		$order->update_meta_data( '_plugin_monedas_rate', $rate );
		// Si multi real activo y moneda distinta, sobreescribir get_currency() guardando currency propia.
		if ( get_option( 'plugin_monedas_full_multicurrency', 0 ) && $selected !== $base ) {
			$order->set_currency( $selected );
		}
	}
}
