<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Currency_Manager {
	private static $rates = [];
	private static $selected = '';

	public static function init() {
		self::load_rates();
		add_action( 'init', [ __CLASS__, 'detect_selection' ], 5 );
		add_filter( 'woocommerce_currency_symbol', [ __CLASS__, 'currency_symbol' ], 20, 2 );
		add_filter( 'woocommerce_currency', [ __CLASS__, 'filter_currency_code' ], 999 );
		add_filter( 'option_woocommerce_price_num_decimals', [ __CLASS__, 'filter_decimals' ], 999 );
		add_filter( 'option_woocommerce_price_thousand_sep', [ __CLASS__, 'filter_thousand_sep' ], 999 );
		add_filter( 'option_woocommerce_price_decimal_sep', [ __CLASS__, 'filter_decimal_sep' ], 999 );
		add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'convert_price' ], 999, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ __CLASS__, 'convert_price' ], 999, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ __CLASS__, 'convert_price' ], 999, 2 );
		add_filter( 'woocommerce_variation_prices_price', [ __CLASS__, 'convert_raw' ], 999, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', [ __CLASS__, 'convert_raw' ], 999, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ __CLASS__, 'convert_raw' ], 999, 3 );
	}

	public static function load_rates() {
		$cached = get_transient( 'plugin_monedas_rates_cache' );
		if ( is_array( $cached ) ) { self::$rates = $cached; return; }
		self::$rates = [];
		$raw = Settings::get_rates_raw();
		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		foreach ( $lines as $line ) {
			$line = trim( $line ); if ( $line === '' ) continue;
			list( $code, $symbol, $rate ) = array_map( 'trim', explode( '|', $line ) );
			$code = strtoupper( $code );
			$rate = (float) str_replace( ',', '.', $rate );
			if ( $rate <= 0 ) continue;
			self::$rates[ $code ] = [ 'symbol' => $symbol, 'rate' => $rate ];
		}
		// Filtro para permitir alterar la lista completa de tasas.
		self::$rates = apply_filters( 'plugin_monedas_rates', self::$rates );
		set_transient( 'plugin_monedas_rates_cache', self::$rates, HOUR_IN_SECONDS );
		do_action( 'plugin_monedas_after_rates_loaded', self::$rates );
	}

	public static function detect_selection() {
		if ( isset( $_GET['pm_currency'] ) ) { // phpcs:ignore
			$valid_nonce = isset( $_GET['pmc_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['pmc_nonce'] ) ), 'pm_currency_switch' ); // phpcs:ignore
			if ( ! $valid_nonce ) {
				return; // Evita cambios sin nonce válido.
			}
			$cur = strtoupper( preg_replace( '/[^A-Z]/', '', sanitize_text_field( wp_unslash( $_GET['pm_currency'] ) ) ) ); // phpcs:ignore
			$cur = substr( $cur, 0, 10 );
			if ( isset( self::$rates[ $cur ] ) ) {
				setcookie( 'pm_currency', $cur, time() + DAY_IN_SECONDS * 30, COOKIEPATH, COOKIE_DOMAIN );
				$_COOKIE['pm_currency'] = $cur;
			}
		}
		if ( isset( $_COOKIE['pm_currency'] ) ) {
			$c = strtoupper( preg_replace( '/[^A-Z]/', '', sanitize_text_field( wp_unslash( $_COOKIE['pm_currency'] ) ) ) );
			$c = substr( $c, 0, 10 );
			if ( isset( self::$rates[ $c ] ) ) {
				self::$selected = $c;
			}
		} else {
			self::$selected = self::base_currency();
		}
	}

	public static function base_currency() {
		return get_option( 'woocommerce_currency' );
	}

	public static function get_selected() { return self::$selected ?: self::base_currency(); }

	public static function get_rate( $currency = '' ) {
		$currency = $currency ?: self::get_selected();
		if ( $currency === self::base_currency() ) return 1.0;
		return isset( self::$rates[ $currency ] ) ? (float) self::$rates[ $currency ]['rate'] : 1.0;
	}

	public static function filter_currency_code( $code ) {
		return self::get_selected();
	}

	public static function currency_symbol( $symbol, $currency ) {
		$sel = self::get_selected();
		if ( $currency === $sel && isset( self::$rates[ $sel ] ) ) {
			return self::$rates[ $sel ]['symbol'];
		}
		return $symbol;
	}

	public static function convert_price( $price, $product ) { // phpcs:ignore
		if ( $price === '' ) return $price;
		$selected = self::get_selected();
		$base = self::base_currency();
		// Overrides de precio por moneda (no aplicar si estamos en la moneda base)
		if ( $selected !== $base ) {
			$product_id = $product->get_id();
			$type = 'regular';
			// Determinar si estamos obteniendo regular o sale según el filtro invocado
			$current_filter = current_filter();
			if ( $current_filter === 'woocommerce_product_get_sale_price' ) {
				$type = 'sale';
			}
			// Si hay un precio específico para esta moneda y tipo, úsalo directamente
			if ( class_exists( '\\Plugin_Monedas\\Product_Prices' ) ) {
				$custom = Product_Prices::get_price_for( $product_id, $selected, $type );
				if ( $custom !== null ) {
					return self::after_math( (float) $custom );
				}
				// Si se solicita sale pero no hay sale, intentar regular si el filtro es sale y regular existe
				if ( $type === 'sale' && $custom === null ) {
					$fallback = Product_Prices::get_price_for( $product_id, $selected, 'regular' );
					if ( $fallback !== null ) {
						return self::after_math( (float) $fallback );
					}
				}
			}
		}
		$rate = self::get_rate();
		if ( $rate === 1.0 ) return $price;
		$converted = self::after_math( (float) $price * $rate );
		return apply_filters( 'plugin_monedas_converted_price', $converted, $price, $product, $rate, self::get_selected() );
	}

	public static function convert_raw( $price, $variation_id, $variation ) { // phpcs:ignore
		if ( $price === '' ) return $price;
		$selected = self::get_selected();
		$base = self::base_currency();
		if ( $selected !== $base ) {
			// Detectar si es regular o sale según filtro
			$type = 'regular';
			$current_filter = current_filter();
			if ( $current_filter === 'woocommerce_variation_prices_sale_price' ) {
				$type = 'sale';
			}
			if ( class_exists( '\\Plugin_Monedas\\Product_Prices' ) ) {
				$custom = Product_Prices::get_price_for( $variation_id, $selected, $type );
				if ( $custom !== null ) {
					return self::after_math( (float) $custom );
				}
				if ( $type === 'sale' && $custom === null ) {
					$fallback = Product_Prices::get_price_for( $variation_id, $selected, 'regular' );
					if ( $fallback !== null ) {
						return self::after_math( (float) $fallback );
					}
				}
			}
		}
		$rate = self::get_rate();
		if ( $rate === 1.0 ) return $price;
		$converted = self::after_math( (float) $price * $rate );
		return apply_filters( 'plugin_monedas_converted_variation_price', $converted, $price, $variation_id, $variation, $rate, self::get_selected() );
	}

	public static function after_math( $value ) {
		$mode = get_option( 'plugin_monedas_round_mode', 'none' );
		$decimals_map = self::get_decimals_map();
		$cur = self::get_selected();
		$dec = isset( $decimals_map[ $cur ] ) ? $decimals_map[ $cur ] : wc_get_price_decimals();
		switch ( $mode ) {
			case 'round': $value = round( $value, $dec ); break;
			case 'floor': $factor = pow(10,$dec); $value = floor($value * $factor)/$factor; break;
			case 'ceil': $factor = pow(10,$dec); $value = ceil($value * $factor)/$factor; break;
			case 'none': default: // no extra
		}
		// Redondeo cash por moneda (ej. 0.05, 50, etc.).
		$cash_map_raw = get_option( 'plugin_monedas_cash_round_map', '' );
		if ( $cash_map_raw ) {
			static $cash_map = null;
			if ( $cash_map === null ) {
				$cash_map = [];
				$lines = preg_split( '/\r?\n/', trim( $cash_map_raw ) );
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					list( $ccur, $inc ) = array_map( 'trim', explode( '|', $line ) );
					$inc = (float) str_replace( ',', '.', $inc );
					if ( $inc > 0 ) $cash_map[ strtoupper( $ccur ) ] = $inc;
				}
			}
			if ( isset( $cash_map[ $cur ] ) ) {
				$inc = $cash_map[ $cur ];
				if ( $inc >= 1 ) {
					$value = round( $value / $inc ) * $inc; // múltiplos enteros
				} else {
					$factor = 1 / $inc;
					$value = round( $value * $factor ) / $factor;
				}
			}
		}
		// Redondeo adicional por magnitud / modo.
		$value = self::apply_magnitude_rounding( $value, $cur );
		return $value;
	}

	private static function apply_magnitude_rounding( $amount, $currency ) {
		$map = get_option( 'plugin_monedas_magnitude_round_map', '' );
		if ( ! $map ) return $amount;
		$lines = preg_split( '/\r?\n/', trim( (string) $map ) );
		$currency = strtoupper( $currency );
		foreach ( $lines as $line ) {
			$line = trim( $line ); if ( $line === '' ) continue;
			list( $cur, $mag, $mode ) = array_map( 'trim', explode( '|', $line ) );
			if ( strtoupper( $cur ) !== $currency ) continue;
			$mag = (float) str_replace( ',', '.', $mag );
			if ( $mag <= 0 ) continue;
			if ( $mag == 1 ) {
				$ratio = 1; // direct integer rounding
			} else {
				$ratio = $mag;
			}
			$base = $amount / $ratio;
			switch ( strtolower( $mode ) ) {
				case 'up':
					$base = ceil( $base );
					break;
				case 'down':
					$base = floor( $base );
					break;
				default:
					$base = round( $base );
			}
			$amount = $base * $ratio;
			break; // solo primera coincidencia de moneda
		}
        return $amount;
	}

	public static function convert_amount( $amount, $currency = '' ) {
		if ( $currency && $currency !== self::get_selected() ) {
			// Si en el futuro soportamos conversiones entre no-base, aquí se manejaría.
		}
		$rate = self::get_rate( $currency );
		if ( $rate === 1.0 ) return $amount;
		$amount = self::after_math( (float) $amount * $rate );
		$amount = self::apply_magnitude_rounding( $amount, $currency ?: self::get_selected() );
		return $amount;
	}

    // Nota: convert_amount ahora definido arriba, eliminada versión previa inválida.

	private static function get_decimals_map() {
		$raw = get_option( 'plugin_monedas_decimals', '' );
		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line ); if ( $line === '' ) continue;
			list( $code, $dec ) = array_map( 'trim', explode( '|', $line ) );
			$code = strtoupper( $code );
			$dec = (int) $dec;
			if ( $dec < 0 || $dec > 6 ) continue;
			$out[ $code ] = $dec;
		}
		return $out;
	}

	public static function get_available_currencies() {
		$base = self::base_currency();
		$list = [ $base => [ 'symbol' => get_woocommerce_currency_symbol( $base ), 'rate' => 1 ] ];
		foreach ( self::$rates as $code => $data ) {
			$list[ $code ] = $data;
		}
		return apply_filters( 'plugin_monedas_available_currencies', $list );
	}

	public static function filter_decimals( $decimals ) {
		$map = self::get_decimals_map();
		$cur = self::get_selected();
		if ( isset( $map[ $cur ] ) ) {
			return $map[ $cur ];
		}
		return $decimals;
	}

	private static function get_format_map() {
		static $cache = null;
		if ( $cache !== null ) return $cache;
		$raw = get_option( 'plugin_monedas_format_map', '' );
		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		$cache = [];
		foreach ( $lines as $line ) {
			$line = trim( $line ); if ( $line === '' ) continue;
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) !== 3 ) continue;
			list( $cur, $th, $dec ) = $parts;
			$cache[ strtoupper( $cur ) ] = [ 'th' => $th, 'dec' => $dec ];
		}
		return $cache;
	}

	public static function filter_thousand_sep( $sep ) {
		$map = self::get_format_map();
		$cur = self::get_selected();
		if ( isset( $map[ $cur ] ) ) return $map[ $cur ]['th'];
		return $sep;
	}

	public static function filter_decimal_sep( $sep ) {
		$map = self::get_format_map();
		$cur = self::get_selected();
		if ( isset( $map[ $cur ] ) ) return $map[ $cur ]['dec'];
		return $sep;
	}
}
