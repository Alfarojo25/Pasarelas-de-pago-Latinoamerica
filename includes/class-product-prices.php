<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Product_Prices {
	const META_KEY = '_pm_currency_prices'; // array: currency => ['regular'=>x,'sale'=>y]

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save' ], 20, 2 );
		add_action( 'woocommerce_product_after_variable_attributes', [ __CLASS__, 'variation_fields' ], 30, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation' ], 20, 2 );
	}

	public static function add_box() {
		add_meta_box( 'pm_prices', __( 'Precios por Moneda (Plugin Monedas)', 'plugin-monedas' ), [ __CLASS__, 'render_box' ], 'product', 'normal', 'high' );
	}

	private static function get_currencies() {
		return Currency_Manager::get_available_currencies();
	}

	public static function render_box( $post ) { // phpcs:ignore
		$stored = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $stored ) ) $stored = [];
		$currencies = self::get_currencies();
		$base = Currency_Manager::base_currency();
		echo '<p class="description">' . esc_html__( 'Si estableces un precio aquí para una moneda, se usará en lugar de la conversión automática de la tasa.', 'plugin-monedas' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Moneda', 'plugin-monedas' ) . '</th><th>' . esc_html__( 'Regular', 'plugin-monedas' ) . '</th><th>' . esc_html__( 'Oferta', 'plugin-monedas' ) . '</th></tr></thead><tbody>';
		foreach ( $currencies as $code => $data ) {
			if ( $code === $base ) continue; // base usa el precio nativo del producto
			$reg = isset( $stored[ $code ]['regular'] ) ? $stored[ $code ]['regular'] : '';
			$sale = isset( $stored[ $code ]['sale'] ) ? $stored[ $code ]['sale'] : '';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $code ) . '</strong></td>';
			echo '<td><input type="text" name="pm_price[' . esc_attr( $code ) . '][regular]" value="' . esc_attr( $reg ) . '" placeholder="" /></td>';
			echo '<td><input type="text" name="pm_price[' . esc_attr( $code ) . '][sale]" value="' . esc_attr( $sale ) . '" placeholder="" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		wp_nonce_field( 'pm_save_prices', 'pm_prices_nonce' );
	}

	public static function save( $post_id, $post ) { // phpcs:ignore
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! isset( $_POST['pm_prices_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_prices_nonce'] ) ), 'pm_save_prices' ) ) return; // phpcs:ignore
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( isset( $_POST['pm_price'] ) && is_array( $_POST['pm_price'] ) ) { // phpcs:ignore
			$out = [];
			foreach ( $_POST['pm_price'] as $code => $prices ) { // phpcs:ignore
				$code = strtoupper( preg_replace( '/[^A-Z]/', '', $code ) );
				if ( $code === '' ) continue;
				$reg = isset( $prices['regular'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $prices['regular'] ) ) ) : '';
				$sale = isset( $prices['sale'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $prices['sale'] ) ) ) : '';
				if ( $reg !== '' && ! is_numeric( $reg ) ) continue; // salta valores no numéricos
				if ( $sale !== '' && ! is_numeric( $sale ) ) $sale = '';
				if ( $reg === '' && $sale === '' ) continue;
				$out[ $code ] = [ 'regular' => $reg, 'sale' => $sale ];
			}
			if ( ! empty( $out ) ) {
				update_post_meta( $post_id, self::META_KEY, $out );
			} else {
				delete_post_meta( $post_id, self::META_KEY );
			}
		}
	}

	// Variaciones
	public static function variation_fields( $loop, $variation_data, $variation ) { // phpcs:ignore
		$stored = get_post_meta( $variation->ID, self::META_KEY, true );
		if ( ! is_array( $stored ) ) $stored = [];
		$currencies = self::get_currencies();
		$base = Currency_Manager::base_currency();
		echo '<div class="form-row form-row-full"><strong>' . esc_html__( 'Precios por moneda', 'plugin-monedas' ) . '</strong></div>';
		foreach ( $currencies as $code => $data ) {
			if ( $code === $base ) continue;
			$reg = isset( $stored[ $code ]['regular'] ) ? $stored[ $code ]['regular'] : '';
			$sale = isset( $stored[ $code ]['sale'] ) ? $stored[ $code ]['sale'] : '';
			echo '<p class="form-row form-row-first"><label>' . esc_html( $code . ' ' . __( 'Regular', 'plugin-monedas' ) ) . '<input type="text" name="pm_var_price[' . esc_attr( $variation->ID ) . '][' . esc_attr( $code ) . '][regular]" value="' . esc_attr( $reg ) . '" /></label></p>';
			echo '<p class="form-row form-row-last"><label>' . esc_html( $code . ' ' . __( 'Oferta', 'plugin-monedas' ) ) . '<input type="text" name="pm_var_price[' . esc_attr( $variation->ID ) . '][' . esc_attr( $code ) . '][sale]" value="' . esc_attr( $sale ) . '" /></label></p>';
		}
	}

	public static function save_variation( $variation_id, $i ) { // phpcs:ignore
		if ( isset( $_POST['pm_var_price'][ $variation_id ] ) ) { // phpcs:ignore
			$set = $_POST['pm_var_price'][ $variation_id ]; // phpcs:ignore
			$out = [];
			foreach ( $set as $code => $prices ) {
				$code = strtoupper( preg_replace( '/[^A-Z]/', '', $code ) );
				$reg = isset( $prices['regular'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $prices['regular'] ) ) ) : '';
				$sale = isset( $prices['sale'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $prices['sale'] ) ) ) : '';
				if ( $reg !== '' && ! is_numeric( $reg ) ) continue;
				if ( $sale !== '' && ! is_numeric( $sale ) ) $sale = '';
				if ( $reg === '' && $sale === '' ) continue;
				$out[ $code ] = [ 'regular' => $reg, 'sale' => $sale ];
			}
			if ( ! empty( $out ) ) {
				update_post_meta( $variation_id, self::META_KEY, $out );
			} else {
				delete_post_meta( $variation_id, self::META_KEY );
			}
		}
	}

	public static function get_price_for( $product_id, $currency, $type = 'regular' ) {
		static $cache = [];
		if ( ! isset( $cache[ $product_id ] ) ) {
			$cache[ $product_id ] = get_post_meta( $product_id, self::META_KEY, true );
		}
		$meta = $cache[ $product_id ];
		if ( isset( $meta[ $currency ][ $type ] ) && $meta[ $currency ][ $type ] !== '' ) {
			return (float) $meta[ $currency ][ $type ];
		}
		return null;
	}
}
