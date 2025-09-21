<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Tax_Exempt {
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'maybe_exempt' ], 1 );
	}

	private static function list_to_array( $option_name ) {
		$raw = get_option( $option_name, '' );
		$lines = preg_split( '/\r?\n/', trim( (string) $raw ) );
		$out = [];
		foreach ( $lines as $l ) {
			$l = trim( $l ); if ( $l === '' ) continue; $out[] = strtoupper( $l );
		}
		return array_unique( $out );
	}

	public static function maybe_exempt( $cart ) { // phpcs:ignore
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
		$cur = Currency_Manager::get_selected();
		$customer = function_exists( 'wc' ) ? wc()->customer : null; // phpcs:ignore
		$billing_country = $customer ? strtoupper( (string) $customer->get_billing_country() ) : '';
		$user = wp_get_current_user();
		$roles = is_a( $user, '\WP_User' ) ? $user->roles : [];
		$ex_cur = self::list_to_array( 'plugin_monedas_exempt_currencies' );
		$ex_cty = self::list_to_array( 'plugin_monedas_exempt_countries' );
		$ex_roles = array_map( 'strtoupper', self::list_to_array( 'plugin_monedas_exempt_roles' ) );
		$roles_upper = array_map( 'strtoupper', $roles );
		$match = false;
		if ( in_array( strtoupper( $cur ), $ex_cur, true ) ) $match = true;
		if ( $billing_country && in_array( $billing_country, $ex_cty, true ) ) $match = true;
		if ( array_intersect( $roles_upper, $ex_roles ) ) $match = true;
		if ( ! $match ) return;
		// Marcar exento: WooCommerce ofrece wc_tax_enabled filter o usar customer set_is_vat_exempt.
		if ( $customer && method_exists( $customer, 'set_is_vat_exempt' ) ) {
			$customer->set_is_vat_exempt( true );
		}
		add_filter( 'woocommerce_customer_taxable_address', [ __CLASS__, 'empty_tax_address' ], 99 );
		add_filter( 'woocommerce_calc_tax', '__return_empty_array', 99 );
		add_filter( 'woocommerce_cart_tax_totals', '__return_empty_array', 99 );
		add_filter( 'woocommerce_order_get_tax_totals', '__return_empty_array', 99 );
	}

	public static function empty_tax_address( $address ) {
		return [ '', '', '', '', '', '' ];
	}
}
