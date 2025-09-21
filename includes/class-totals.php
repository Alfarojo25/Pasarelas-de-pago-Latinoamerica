<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Totals {
	public static function init() {
		// Hooks de formato de totales visuales.
		add_filter( 'woocommerce_cart_subtotal', [ __CLASS__, 'filter_subtotal_html' ], 999, 3 );
		add_filter( 'woocommerce_cart_tax_totals', [ __CLASS__, 'filter_tax_totals' ], 999, 2 );
		add_filter( 'woocommerce_cart_totals_order_total_html', [ __CLASS__, 'filter_order_total_html' ], 999, 1 );
		add_filter( 'woocommerce_get_formatted_cart_subtotal', [ __CLASS__, 'filter_formatted_cart_subtotal' ], 999, 3 );
	}

	private static function convert_and_format( $amount_base ) {
		$converted = Currency_Manager::convert_amount( $amount_base );
		return wc_price( $converted, [ 'currency' => Currency_Manager::get_selected() ] );
	}

	public static function filter_subtotal_html( $cart_subtotal, $compound, $cart ) { // phpcs:ignore
		if ( ! $cart ) return $cart_subtotal;
		$base_subtotal = $cart->get_subtotal();
		return self::convert_and_format( $base_subtotal );
	}

	public static function filter_formatted_cart_subtotal( $subtotal, $compound, $cart ) { // phpcs:ignore
		if ( ! $cart ) return $subtotal;
		$base_subtotal = $cart->get_subtotal();
		return self::convert_and_format( $base_subtotal );
	}

	public static function filter_tax_totals( $tax_totals, $cart ) { // phpcs:ignore
		foreach ( $tax_totals as $code => $data ) {
			if ( isset( $data->amount ) ) {
				$data->formatted_amount = self::convert_and_format( $data->amount );
			}
		}
		return $tax_totals;
	}

	public static function filter_order_total_html( $value_html ) {
		$cart = WC()->cart; // phpcs:ignore
		if ( ! $cart ) return $value_html;
		$total = $cart->get_total( 'edit' ); // Raw total base.
		return '<strong>' . self::convert_and_format( (float) $total ) . '</strong>';
	}
}
