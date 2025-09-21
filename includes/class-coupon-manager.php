<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Gestiona valores de cupones en multi-moneda.
 * Estrategia:
 *  - Se guarda el importe original (base currency) en meta _pm_coupon_base_amount cuando se crea/actualiza.
 *  - Al mostrar / aplicar el cupón si la moneda seleccionada != base se convierte dinámicamente.
 *  - Aplica a tipos: fixed_cart, fixed_product. (Percent no necesita conversión.)
 */
class Coupon_Manager {
	const META_BASE_AMOUNT = '_pm_coupon_base_amount';
	const META_BASE_TYPE   = '_pm_coupon_base_currency';

	public static function init() {
		// Guardar base al crear/actualizar.
		add_action( 'woocommerce_coupon_options_save', [ __CLASS__, 'store_base_amount' ], 10, 2 );
		// Filtros de importe para mostrar en admin y frontend.
		add_filter( 'woocommerce_coupon_get_amount', [ __CLASS__, 'filter_amount' ], 999, 2 );
		// Ajustar descripción opcional (visual) indicando conversión.
		add_filter( 'woocommerce_get_coupon_description', [ __CLASS__, 'maybe_append_conversion_note' ], 20, 2 );
	}

	public static function store_base_amount( $post_id, $coupon ) { // phpcs:ignore
		if ( ! $coupon instanceof \WC_Coupon ) return;
		$amount = $coupon->get_amount();
		$type   = $coupon->get_discount_type();
		if ( in_array( $type, [ 'fixed_cart', 'fixed_product' ], true ) ) {
			// Guardar siempre como número flotante base.
			$base_currency = Currency_Manager::base_currency();
			update_post_meta( $post_id, self::META_BASE_AMOUNT, $amount );
			update_post_meta( $post_id, self::META_BASE_TYPE, $base_currency );
		}
	}

	public static function filter_amount( $amount, $coupon ) { // phpcs:ignore
		if ( ! $coupon instanceof \WC_Coupon ) return $amount;
		$type = $coupon->get_discount_type();
		if ( ! in_array( $type, [ 'fixed_cart', 'fixed_product' ], true ) ) return $amount; // porcentajes no se tocan.
		$base_amount = get_post_meta( $coupon->get_id(), self::META_BASE_AMOUNT, true );
		$base_currency = get_post_meta( $coupon->get_id(), self::META_BASE_TYPE, true );
		if ( $base_amount === '' || $base_currency === '' ) return $amount; // si no está meta, asumir que valor ya es de la moneda actual.
		$selected = Currency_Manager::get_selected();
		if ( $selected === $base_currency ) {
			return (float) $base_amount; // usar original.
		}
		// Convertir desde la base al seleccionado usando la tasa (como si fuera un "precio").
		$converted = Currency_Manager::convert_amount( (float) $base_amount, $selected );
		return apply_filters( 'plugin_monedas_coupon_amount', $converted, $base_amount, $coupon, $selected, $base_currency );
	}

	public static function maybe_append_conversion_note( $desc, $coupon ) { // phpcs:ignore
		if ( ! $coupon instanceof \WC_Coupon ) return $desc;
		$type = $coupon->get_discount_type();
		if ( ! in_array( $type, [ 'fixed_cart', 'fixed_product' ], true ) ) return $desc;
		$base_amount = get_post_meta( $coupon->get_id(), self::META_BASE_AMOUNT, true );
		$base_currency = get_post_meta( $coupon->get_id(), self::META_BASE_TYPE, true );
		if ( $base_amount === '' || $base_currency === '' ) return $desc;
		$selected = Currency_Manager::get_selected();
		if ( $selected === $base_currency ) return $desc;
		// Añadir nota ligera.
		$note = sprintf( __( ' (Equivalente convertido desde %s %s)', 'plugin-monedas' ), $base_currency, wc_format_localized_price( $base_amount ) );
		return $desc . $note;
	}
}
