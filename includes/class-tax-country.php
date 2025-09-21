<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Tax_Country {
	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_force_country' ], 20 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'maybe_force_country_session' ], 5 );
	}

	private static function map() {
		static $map = null;
		if ( $map !== null ) return $map;
		$raw = get_option( 'plugin_monedas_currency_country_map', '' );
		$map = [];
		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		foreach ( $lines as $l ) {
			$l = trim( $l ); if ( $l === '' ) continue;
			$parts = array_map( 'trim', explode( '|', $l ) );
			if ( count( $parts ) !== 2 ) continue;
			list( $cur, $country ) = $parts;
			$cur = strtoupper( preg_replace( '/[^A-Z]/', '', $cur ) );
			$country = strtoupper( preg_replace( '/[^A-Z]/', '', $country ) );
			if ( strlen( $country ) !== 2 ) continue;
			$map[ $cur ] = $country;
		}
		return $map;
	}

	public static function maybe_force_country() {
		if ( ! get_option( 'plugin_monedas_force_tax_country', 0 ) ) return;
		if ( ! class_exists( '\WC_Customer' ) ) return;
		$sel = Currency_Manager::get_selected();
		$map = self::map();
		if ( isset( $map[ $sel ] ) ) {
			$country = $map[ $sel ];
			$customer = wc()->customer; // phpcs:ignore
			if ( $customer ) {
				$customer->set_billing_country( $country );
				$customer->set_shipping_country( $country );
				// Asegura re-cálculo impuestos.
				WC()->customer->save();
			}
		}
	}

	public static function maybe_force_country_session( $cart ) { // phpcs:ignore
		if ( ! get_option( 'plugin_monedas_force_tax_country', 0 ) ) return;
		$sel = Currency_Manager::get_selected();
		$map = self::map();
		if ( isset( $map[ $sel ] ) ) {
			$country = $map[ $sel ];
			$customer = wc()->customer; // phpcs:ignore
			if ( $customer && $customer->get_billing_country() !== $country ) {
				$customer->set_billing_country( $country );
				$customer->set_shipping_country( $country );
				WC()->customer->save();
			}
		}
	}
}
