<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibilidad básica con pasarelas:
 * - Cuando está activo el modo multi-moneda real, WooCommerce ya verá la moneda seleccionada.
 * - Cuando es solo visual (no real), forzamos a que la moneda enviada a gateways permanezca como la base
 *   para evitar rechazos, pero mostramos aviso si difiere de la seleccionada por el usuario.
 * - Opción (futura) de restringir pasarelas por moneda (placeholder simple con filtro).
 */
class Payment_Gateways {
	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'filter_gateways' ], 50 );
		add_action( 'woocommerce_review_order_after_payment', [ __CLASS__, 'maybe_notice_currency_mismatch' ] );
	}

	public static function filter_gateways( $gateways ) { // phpcs:ignore
		// Placeholder para restricción por moneda en el futuro, usando un option tipo multiline:
		// gateway_id|USD,EUR
		$map_raw = get_option( 'plugin_monedas_gateway_currency_map', '' );
		if ( ! $map_raw ) return $gateways;
		$selected = Currency_Manager::get_selected();
		$lines = preg_split( '/\r?\n/', trim( $map_raw ) );
		$rules = [];
		foreach ( $lines as $l ) {
			$l = trim( $l ); if ( $l === '' ) continue;
			$parts = array_map( 'trim', explode( '|', $l ) );
			if ( count( $parts ) !== 2 ) continue;
			list( $gid, $list ) = $parts;
			$currs = array_filter( array_map( 'trim', explode( ',', strtoupper( $list ) ) ) );
			$rules[ $gid ] = $currs;
		}
		foreach ( $gateways as $id => $g ) {
			if ( isset( $rules[ $id ] ) && ! in_array( $selected, $rules[ $id ], true ) ) {
				unset( $gateways[ $id ] );
			}
		}
		return $gateways;
	}

	public static function maybe_notice_currency_mismatch() {
		if ( get_option( 'plugin_monedas_full_multicurrency', 0 ) ) return; // real multi ya usa la seleccionada.
		$selected = Currency_Manager::get_selected();
		$base     = Currency_Manager::base_currency();
		if ( $selected !== $base ) {
			wc_print_notice( sprintf( __( 'El pago se procesará en %s. Mostramos valores en %s como referencia.', 'plugin-monedas' ), $base, $selected ), 'notice' );
		}
	}
}
