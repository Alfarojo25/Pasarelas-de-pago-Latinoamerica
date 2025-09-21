<?php
/**
 * Plugin Name: Plugin Monedas (Local)
 * Description: Conversión de precios en WooCommerce basada en tasas definidas manualmente (sin APIs externas).
 * Version: 1.1.0
 * Author: Esteban Dubles - Alfarojo25 (GitHub)
 * Author URI: https://github.com/Alfarojo25
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
define( 'PLUGIN_MONEDAS_VERSION', '1.1.0' );

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

// Compilar .po a .mo en runtime si faltan (entorno simple sin herramientas build).
function plugin_monedas_compile_pos() {
	$lang_dir = PLUGIN_MONEDAS_PATH . 'languages/';
	if ( ! is_dir( $lang_dir ) ) return;
	// Evitar compilar repetidamente en ventana corta (lock 60s)
	if ( get_transient( 'plugin_monedas_mo_lock' ) ) return;
	set_transient( 'plugin_monedas_mo_lock', 1, 60 );
	$pos = glob( $lang_dir . 'plugin-monedas-*.po' );
	if ( empty( $pos ) ) return;
	foreach ( $pos as $po ) {
		$mo = substr( $po, 0, -3 ) . 'mo';
		if ( ! file_exists( $mo ) || filemtime( $mo ) < filemtime( $po ) ) {
			plugin_monedas_generate_mo( $po, $mo );
		}
	}
}

// Conversión simple PO -> MO (parser mínimo). NOTA: No cubre todas las complejidades de gettext, suficiente para cadenas simples aquí.
function plugin_monedas_generate_mo( $po_file, $mo_file ) {
	// Límites defensivos
	$max_size = 512 * 1024; // 512KB
	if ( filesize( $po_file ) > $max_size ) return; // demasiado grande
	$contents = file_get_contents( $po_file );
	if ( substr_count( $contents, "msgid" ) > 2000 ) return; // demasiadas entradas para este parser simple
	if ( ! is_readable( $po_file ) ) return;
	// Evitar escritura simultánea
	$lock = $mo_file . '.lock';
	$fh_lock = @fopen( $lock, 'c' );
	if ( $fh_lock ) {
		if ( ! @flock( $fh_lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $fh_lock );
			return; // otro proceso compilando
		}
	}
	$entries = [];
	$current_id = '';
	$current_str = '';
	$state = null; // 'msgid' | 'msgstr'
	$lines = explode( "\n", $contents );
	foreach ( $lines as $raw ) {
		$line = trim( $raw );
		if ( $line === '' || $line[0] === '#' ) continue;
		if ( strpos( $line, 'msgid ' ) === 0 ) {
			if ( $state === 'msgstr' && $current_id !== '' ) {
				$entries[ stripcslashes( $current_id ) ] = stripcslashes( $current_str );
			}
			$current_id = trim( substr( $line, 5 ) );
			$current_id = trim( $current_id, '"' );
			$current_str = '';
			$state = 'msgid';
			continue;
		}
		if ( strpos( $line, 'msgstr ' ) === 0 ) {
			$current_str = trim( substr( $line, 6 ) );
			$current_str = trim( $current_str, '"' );
			$state = 'msgstr';
			continue;
		}
		if ( $line[0] === '"' && substr( $line, -1 ) === '"' ) {
			$fragment = trim( $line, '"' );
			if ( $state === 'msgid' ) $current_id .= $fragment;
			elseif ( $state === 'msgstr' ) $current_str .= $fragment;
		}
	}
	if ( $state === 'msgstr' && $current_id !== '' ) {
		$entries[ stripcslashes( $current_id ) ] = stripcslashes( $current_str );
	}
	// Construir archivo MO (formato binario simple).
	$keys = array_keys( $entries );
	$values = array_values( $entries );
	$n = count( $entries );
	$offset = 28; // header (7*4 bytes)
	$ids = '';
	$strings = '';
	$tables = [];
	for ( $i = 0; $i < $n; $i++ ) {
		$id = $keys[$i];
		$val = $values[$i];
		$ids_offset = $offset;
		$id_data = $id . "\0";
		$ids_len = strlen( $id_data );
		$ids .= $id_data;
		$offset += $ids_len;
		$strings_offset = $offset;
		$str_data = $val . "\0";
		$str_len = strlen( $str_data );
		$strings .= $str_data;
		$offset += $str_len;
		$tables[] = [ $ids_len -1, $ids_offset, $str_len -1, $strings_offset ]; // length excludes null per spec.
	}
	$mo = pack( 'Iiiiiii', 0x950412de, 0, $n, 28 + $n * 16, 28 + $n * 16 + $n * 8, 0, 0 );
	// Original table
	$orig_table = '';
	$trans_table = '';
	$current_offset_ids = 28 + $n * 16 + $n * 8 + strlen( $ids ) + strlen( $strings ); // not used exactly (simplified)
	$running_id_offset = 28 + $n * 16 + $n * 8;
	$running_str_offset = $running_id_offset + strlen( $ids );
	$cursor_id = 0; $cursor_str = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		$id_len = strlen( $keys[$i] );
		$val_len = strlen( $values[$i] );
		$orig_table .= pack( 'II', $id_len, $running_id_offset + $cursor_id );
		$trans_table .= pack( 'II', $val_len, $running_str_offset + $cursor_str );
		$cursor_id += $id_len + 1;
		$cursor_str += $val_len + 1;
	}
	$data = $mo . $orig_table . $trans_table . $ids . $strings;
	// Validar tamaño final (máx 1MB)
	if ( strlen( $data ) < 1024 * 1024 ) {
		file_put_contents( $mo_file, $data );
	}
	if ( isset( $fh_lock ) && $fh_lock ) {
		@flock( $fh_lock, LOCK_UN );
		fclose( $fh_lock );
		@unlink( $lock );
	}
}

add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return; // Requiere WooCommerce.
	}
	plugin_monedas_compile_pos();
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
	// Sistema interno de suscripciones
	Plugin_Monedas\Subscriptions_Settings::init();
	Plugin_Monedas\Subscriptions::init();
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

// Enlace adicional en la lista de plugins.
add_filter( 'plugin_row_meta', function( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="https://github.com/Alfarojo25" target="_blank" rel="noopener">GitHub</a>';
	}
	return $links;
}, 10, 2 );

// Carga archivos base de clases si existen (aún no creados, se crearán luego) para evitar fallos en orden.
// Se crean placeholders si faltan.
$base_classes = [ 'settings', 'currency-manager', 'frontend', 'widget-selector', 'totals', 'real-multicurrency', 'order-currency', 'geo', 'tax-country', 'tax-exempt', 'product-prices', 'coupon-manager', 'payment-gateways', 'reporting', 'subscriptions-settings', 'subscriptions' ];
foreach ( $base_classes as $slug ) {
	$path = PLUGIN_MONEDAS_PATH . 'includes/class-' . $slug . '.php';
	if ( ! file_exists( $path ) ) {
		file_put_contents( $path, "<?php\nnamespace Plugin_Monedas;\n// Placeholder: se implementará en pasos siguientes.\nclass " . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $slug ) ) ) . " { public static function init() {} }\n" );
	}
}
