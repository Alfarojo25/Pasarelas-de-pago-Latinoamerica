<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Frontend {
	public static function init() {
		add_shortcode( 'plugin_monedas_selector', [ __CLASS__, 'shortcode_selector' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets() {
		wp_register_script( 'plugin-monedas', PLUGIN_MONEDAS_URL . 'assets/js/plugin-monedas.js', [ 'jquery' ], PLUGIN_MONEDAS_VERSION, true );
		wp_localize_script( 'plugin-monedas', 'PluginMonedas', [
			'selected' => Currency_Manager::get_selected(),
			'base' => Currency_Manager::base_currency(),
			'switch_url' => remove_query_arg( 'pm_currency' ),
		]);
		wp_enqueue_script( 'plugin-monedas' );
		wp_enqueue_style( 'plugin-monedas', PLUGIN_MONEDAS_URL . 'assets/css/plugin-monedas.css', [], PLUGIN_MONEDAS_VERSION );
	}

	public static function shortcode_selector() {
		$currencies = Currency_Manager::get_available_currencies();
		$selected = Currency_Manager::get_selected();
		$hide_base = (bool) get_option( 'plugin_monedas_hide_base', 0 );
		if ( $hide_base ) {
			$base = Currency_Manager::base_currency();
			unset( $currencies[ $base ] );
			// Si la moneda seleccionada era la base y está oculta, forzar la primera disponible.
			if ( $selected === $base ) {
				$keys = array_keys( $currencies );
				if ( ! empty( $keys ) ) {
					$selected = $keys[0];
				}
			}
		}
		ob_start();
		?>
		<form class="plugin-monedas-selector" method="get">
			<?php $nonce_field = wp_create_nonce( 'pm_currency_switch' ); ?>
			<input type="hidden" name="pmc_nonce" value="<?php echo esc_attr( $nonce_field ); ?>" />
			<select name="pm_currency" onchange="this.form.submit()">
				<?php foreach ( $currencies as $code => $data ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $selected ); ?>><?php echo esc_html( $code . ' ' . $data['symbol'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php foreach ( $_GET as $k => $v ) { // Mantener otros parámetros
				if ( $k === 'pm_currency' ) continue;
				if ( is_array( $v ) ) continue; // simplificado
				?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
			<?php } ?>
		</form>
		<?php
		return ob_get_clean();
	}
}
