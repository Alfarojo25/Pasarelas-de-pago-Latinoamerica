<?php
/**
 * Plugin Name: Plugin Monedas (Local)
 * Description: Conversión de precios en WooCommerce basada en tasas definidas manualmente (sin APIs externas).
 * Version: 1.0.0
 * Author: Equipo Local
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: plugin-monedas
 * Domain Path: /languages
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Constantes básicas.
define( 'PLUGIN_MONEDAS_FILE', __FILE__ );
define( 'PLUGIN_MONEDAS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_MONEDAS_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIN_MONEDAS_VERSION', '1.0.0' );

// Carga automática sencilla (PSR-4 mínima) para nuestras clases bajo namespace Plugin_Monedas.
spl_autoload_register( function( $class ) {
	if ( strpos( $class, 'Plugin_Monedas\\' ) !== 0 ) return;
	$rel = substr( $class, strlen( 'Plugin_Monedas\\' ) );
	$rel = strtolower( str_replace( ['\\', '_' ], [ '/', '-' ], $rel ) );
	$file = PLUGIN_MONEDAS_PATH . 'includes/class-' . $rel . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
});

// Activación: crear opción si no existe.
register_activation_hook( __FILE__, function(){
	if ( ! get_option( 'plugin_monedas_rates' ) ) {
		// Formato inicial: CODIGO|símbolo|tasa respecto a moneda base (WooCommerce setting base currency).
		add_option( 'plugin_monedas_rates', "USD|$|1.00\nEUR|€|0.90" );
	}
});

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return; // Requiere WooCommerce.
	}
	// Cargar traducciones.
	load_plugin_textdomain( 'plugin-monedas', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	// Inicializar componentes.
	Plugin_Monedas\Settings::init();
	Plugin_Monedas\Currency_Manager::init();
	Plugin_Monedas\Frontend::init();
	Plugin_Monedas\Geo::init();
	Plugin_Monedas\Tax_Country::init();
	Plugin_Monedas\Tax_Exempt::init();
	Plugin_Monedas\Product_Prices::init();
	Plugin_Monedas\Coupon_Manager::init();
	Plugin_Monedas\Payment_Gateways::init();
	Plugin_Monedas\Reporting::init();
	// Widgets.
	add_action( 'widgets_init', function() { \Plugin_Monedas\Widget_Selector::register(); } );
	// Bloque Gutenberg simple.
	add_action( 'init', function() {
		if ( ! function_exists( 'register_block_type' ) ) return;
		$handle = 'plugin-monedas-block';
		$js = "(function(){const {registerBlockType}=wp.blocks;const {createElement:el,Fragment}=wp.element;const {InspectorControls}=wp.blockEditor||wp.editor;const {PanelBody,TextControl,ToggleControl}=wp.components;registerBlockType('plugin-monedas/selector',{title:'Selector Moneda (Plugin Monedas)',icon:'money-alt',category:'widgets',attributes:{title:{type:'string',default:''},hideBase:{type:'boolean',default:false}},edit:(props)=>{const {attributes:{title,hideBase},setAttributes}=props;return el(Fragment,{},el(InspectorControls,{},el(PanelBody,{title:'Opciones Selector'},el(TextControl,{label:'Título',value:title,onChange:v=>setAttributes({title:v})}),el(ToggleControl,{label:'Ocultar moneda base (visual)',checked:hideBase,onChange:v=>setAttributes({hideBase:v})}))),el('div',{}, title?el('h4',{},title):null,'[plugin_monedas_selector]'));},save:(props)=>{const {attributes:{title}}=props;return el('div',{}, title?el('h4',{},title):null,'[plugin_monedas_selector]')}});})();";
		wp_register_script( $handle, false, [ 'wp-blocks', 'wp-element' ], PLUGIN_MONEDAS_VERSION, true );
		wp_add_inline_script( $handle, $js );
		register_block_type( 'plugin-monedas/selector', [
			'editor_script' => $handle,
			'render_callback' => function() { return do_shortcode( '[plugin_monedas_selector]' ); }
		]);
	});
	$full_multi = get_option( 'plugin_monedas_full_multicurrency', 0 );
	if ( $full_multi ) {
		Plugin_Monedas\Real_Multicurrency::init();
	} elseif ( get_option( 'plugin_monedas_convert_totals', 0 ) ) {
		// Solo capa visual si no está el modo real.
		Plugin_Monedas\Totals::init();
	}
	Plugin_Monedas\Order_Currency::init();
});

// Carga archivos base de clases si existen (aún no creados, se crearán luego) para evitar fallos en orden.
// Se crean placeholders si faltan.
$base_classes = [ 'settings', 'currency-manager', 'frontend', 'widget-selector', 'totals', 'real-multicurrency', 'order-currency', 'geo', 'tax-country', 'tax-exempt', 'product-prices', 'coupon-manager', 'payment-gateways', 'reporting' ];
foreach ( $base_classes as $slug ) {
	$path = PLUGIN_MONEDAS_PATH . 'includes/class-' . $slug . '.php';
	if ( ! file_exists( $path ) ) {
		file_put_contents( $path, "<?php\nnamespace Plugin_Monedas;\n// Placeholder: se implementará en pasos siguientes.\nclass " . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $slug ) ) ) . " { public static function init() {} }\n" );
	}
}
