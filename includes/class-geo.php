<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo {
	private static $country = '';
	private static $map = [];

	public static function init() {
		if ( ! get_option( 'plugin_monedas_geo_auto', 0 ) ) return;
		add_action( 'init', [ __CLASS__, 'maybe_set_currency_from_geo' ], 1 );
	}

	private static function load_map() {
		if ( self::$map ) return;
		$raw = get_option( 'plugin_monedas_geo_map', '' );
		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		foreach ( $lines as $line ) {
			$line = trim( $line ); if ( $line === '' ) continue;
			list( $cc, $cur ) = array_map( 'trim', explode( '|', $line ) );
			$cc = strtoupper( $cc ); $cur = strtoupper( $cur );
			self::$map[ $cc ] = $cur;
		}
	}

	private static function detect_country() {
		if ( self::$country ) return self::$country;
		// Usar geolocalizador de WooCommerce si existe.
		if ( function_exists( 'wc_get_customer_default_location' ) ) {
			$loc = wc_get_customer_default_location();
			if ( is_array( $loc ) && ! empty( $loc['country'] ) ) {
				self::$country = strtoupper( $loc['country'] );
				return self::$country;
			}
		}
		// Fallback: header Cloudflare CF-IPCountry.
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) { // phpcs:ignore
			self::$country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ); // phpcs:ignore
		}
		return self::$country;
	}

	public static function maybe_set_currency_from_geo() {
		if ( isset( $_GET['pm_currency'] ) ) return; // Usuario fuerza.
		self::load_map();
		$country = self::detect_country();
		if ( ! $country || empty( self::$map[ $country ] ) ) return;
		$cur = self::$map[ $country ];
		// Verificar que moneda existe en rates o es base.
		$available = Currency_Manager::get_available_currencies();
		if ( isset( $available[ $cur ] ) ) {
			if ( empty( $_COOKIE['pm_currency'] ) ) {
				setcookie( 'pm_currency', $cur, time()+DAY_IN_SECONDS*30, COOKIEPATH, COOKIE_DOMAIN );
				$_COOKIE['pm_currency'] = $cur;
			}
		}
	}
}
