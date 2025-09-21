<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings {
	const OPTION_RATES = 'plugin_monedas_rates';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] ); // Página propia (mantener por compatibilidad)
		add_action( 'admin_init', [ __CLASS__, 'register_setting' ] );
		add_action( 'update_option_' . self::OPTION_RATES, [ __CLASS__, 'flush_rates_cache' ], 10, 2 );
		// Integración pestaña WooCommerce.
		add_filter( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_wc_tab' ], 60 );
		add_action( 'woocommerce_settings_tabs_plugin_monedas', [ __CLASS__, 'render_wc_tab' ] );
		add_action( 'woocommerce_update_options_plugin_monedas', [ __CLASS__, 'save_wc_tab' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	public static function add_wc_tab( $tabs ) {
		$tabs['plugin_monedas'] = __( 'Monedas Local', 'plugin-monedas' );
		return $tabs;
	}

	public static function enqueue_admin_assets( $hook ) {
		// Cargar sólo en la pestaña de WooCommerce o en nuestra página propia.
		$is_wc_tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'plugin_monedas'; // phpcs:ignore
		if ( $is_wc_tab || ( isset( $_GET['page'] ) && $_GET['page'] === 'plugin-monedas' ) ) { // phpcs:ignore
			wp_enqueue_style( 'plugin-monedas-admin', PLUGIN_MONEDAS_URL . 'assets/css/admin-settings.css', [], PLUGIN_MONEDAS_VERSION );
			wp_enqueue_script( 'plugin-monedas-admin', PLUGIN_MONEDAS_URL . 'assets/js/admin-settings.js', [ 'jquery' ], PLUGIN_MONEDAS_VERSION, true );
			// Datos para la nueva tabla maestra.
			$country_currency = self::get_country_currency_map();
			$symbols = function_exists( 'get_woocommerce_currency_symbols' ) ? get_woocommerce_currency_symbols() : [];
			$active_gateways = [];
			if ( class_exists( '\\WC_Payment_Gateways' ) ) {
				$gws = WC()->payment_gateways();
				if ( $gws ) {
					foreach ( $gws->get_available_payment_gateways() as $id => $gw ) {
						$active_gateways[] = [ 'id' => sanitize_key( $id ), 'title' => wp_strip_all_tags( $gw->get_title() ) ];
					}
				}
			}
			$zero_dec = [ 'BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF' ];
			$three_dec = [ 'BHD','IQD','JOD','KWD','LYD','OMR','TND' ];
			wp_localize_script( 'plugin-monedas-admin', 'PluginMonedasData', [
				'countryCurrency' => $country_currency,
				'currencySymbols' => $symbols,
				'numberFormat' => [
					'decimal'  => wc_get_price_decimal_separator(),
					'thousand' => wc_get_price_thousand_separator(),
				],
				'zeroDecimals' => $zero_dec,
				'threeDecimals' => $three_dec,
				'gateways' => $active_gateways,
				'i18n' => [
					'Cash' => __( 'Efectivo', 'plugin-monedas' ),
					'Magnitud' => __( 'Magnitud', 'plugin-monedas' ),
					'Select' => __( 'Seleccionar', 'plugin-monedas' ),
					'Ese país ya está seleccionado.' => __( 'Ese país ya está seleccionado.', 'plugin-monedas' ),
				],
				'existing' => [
					'rates' => self::get_rates_raw(),
					'cash' => self::get_option_raw( 'plugin_monedas_cash_round_map' ),
					'mag' => self::get_option_raw( 'plugin_monedas_magnitude_round_map' ),
					'geo' => self::get_option_raw( 'plugin_monedas_geo_map' ),
					'decimals' => self::get_option_raw( 'plugin_monedas_decimals' ),
					'curCountry' => self::get_option_raw( 'plugin_monedas_currency_country_map' ),
					'exempt' => get_option( 'plugin_monedas_exempt_currencies', '' ),
					'gatewayMap' => get_option( 'plugin_monedas_gateway_currency_map', '' ),
				],
			] );
		}
	}

	private static function get_country_currency_map() {
		// Si WooCommerce está disponible, usamos su lista de países y deducimos moneda desde settings cuando sea posible.
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->countries; // ISO2 => Nombre
			$map = [];
			// Mapa manual de país -> moneda (principal) extendido. (Se podría externalizar.)
			$manual = [
				'AR'=>'ARS','BO'=>'BOB','BR'=>'BRL','CL'=>'CLP','CO'=>'COP','CR'=>'CRC','EC'=>'USD','SV'=>'USD','GT'=>'GTQ','HN'=>'HNL','MX'=>'MXN','NI'=>'NIO','PA'=>'USD','PY'=>'PYG','PE'=>'PEN','PR'=>'USD','UY'=>'UYU','VE'=>'VES',
				'US'=>'USD','CA'=>'CAD','ES'=>'EUR','FR'=>'EUR','DE'=>'EUR','IT'=>'EUR','PT'=>'EUR','JP'=>'JPY','GB'=>'GBP','CH'=>'CHF','AU'=>'AUD','NZ'=>'NZD','CN'=>'CNY','IN'=>'INR','ZA'=>'ZAR','KR'=>'KRW'
			];
			foreach ( $countries as $code => $name ) {
				$code = strtoupper( $code );
				if ( isset( $manual[ $code ] ) ) {
					$map[ $code ] = [ $manual[ $code ], $name ];
				}
			}
			return $map;
		}
		// Fallback estático.
		return [
			'AR' => [ 'ARS', __( 'Argentina', 'plugin-monedas' ) ], 'BO' => [ 'BOB', __( 'Bolivia', 'plugin-monedas' ) ], 'BR' => [ 'BRL', __( 'Brasil', 'plugin-monedas' ) ], 'CL' => [ 'CLP', __( 'Chile', 'plugin-monedas' ) ], 'CO' => [ 'COP', __( 'Colombia', 'plugin-monedas' ) ], 'CR' => [ 'CRC', __( 'Costa Rica', 'plugin-monedas' ) ], 'EC' => [ 'USD', __( 'Ecuador', 'plugin-monedas' ) ], 'SV' => [ 'USD', __( 'El Salvador', 'plugin-monedas' ) ], 'GT' => [ 'GTQ', __( 'Guatemala', 'plugin-monedas' ) ], 'HN' => [ 'HNL', __( 'Honduras', 'plugin-monedas' ) ], 'MX' => [ 'MXN', __( 'México', 'plugin-monedas' ) ], 'NI' => [ 'NIO', __( 'Nicaragua', 'plugin-monedas' ) ], 'PA' => [ 'USD', __( 'Panamá', 'plugin-monedas' ) ], 'PY' => [ 'PYG', __( 'Paraguay', 'plugin-monedas' ) ], 'PE' => [ 'PEN', __( 'Perú', 'plugin-monedas' ) ], 'PR' => [ 'USD', __( 'Puerto Rico', 'plugin-monedas' ) ], 'UY' => [ 'UYU', __( 'Uruguay', 'plugin-monedas' ) ], 'VE' => [ 'VES', __( 'Venezuela', 'plugin-monedas' ) ], 'US' => [ 'USD', __( 'Estados Unidos', 'plugin-monedas' ) ], 'CA' => [ 'CAD', __( 'Canadá', 'plugin-monedas' ) ], 'ES' => [ 'EUR', __( 'España', 'plugin-monedas' ) ], 'FR' => [ 'EUR', __( 'Francia', 'plugin-monedas' ) ], 'DE' => [ 'EUR', __( 'Alemania', 'plugin-monedas' ) ], 'IT' => [ 'EUR', __( 'Italia', 'plugin-monedas' ) ], 'PT' => [ 'EUR', __( 'Portugal', 'plugin-monedas' ) ], 'JP' => [ 'JPY', __( 'Japón', 'plugin-monedas' ) ], 'GB' => [ 'GBP', __( 'Reino Unido', 'plugin-monedas' ) ], 'CH' => [ 'CHF', __( 'Suiza', 'plugin-monedas' ) ], 'AU' => [ 'AUD', __( 'Australia', 'plugin-monedas' ) ], 'NZ' => [ 'NZD', __( 'Nueva Zelanda', 'plugin-monedas' ) ], 'CN' => [ 'CNY', __( 'China', 'plugin-monedas' ) ], 'IN' => [ 'INR', __( 'India', 'plugin-monedas' ) ], 'ZA' => [ 'ZAR', __( 'Sudáfrica', 'plugin-monedas' ) ], 'KR' => [ 'KRW', __( 'Corea del Sur', 'plugin-monedas' ) ],
		];
	}

	private static function get_option_raw( $name ) { return get_option( $name, '' ); }

	private static function fieldset_description() {
		return '<p>' . esc_html__( 'Administra tus monedas, tasas y reglas de redondeo de forma visual. Usa los botones “Añadir fila”. Al guardar se serializa a las opciones.', 'plugin-monedas' ) . '</p>';
	}

	public static function render_wc_tab() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		echo '<h2>' . esc_html__( 'Configuración Monedas Local', 'plugin-monedas' ) . '</h2>';
		echo self::fieldset_description();
		echo '<form method="post" action="admin.php?page=wc-settings&tab=plugin_monedas">';
		wp_nonce_field( 'plugin_monedas_wc_save', 'plugin_monedas_wc_nonce' );
		self::render_visual_tables();
		submit_button();
		echo '</form>';
	}

	private static function render_visual_tables() {
		$hide_base = (int) get_option( 'plugin_monedas_hide_base', 0 );
		$convert_totals = (int) get_option( 'plugin_monedas_convert_totals', 0 );
		$full_multi = (int) get_option( 'plugin_monedas_full_multicurrency', 0 );
		$geo_auto = (int) get_option( 'plugin_monedas_geo_auto', 0 );
		$force_tax = (int) get_option( 'plugin_monedas_force_tax_country', 0 );
		?>
		<div class="pm-section">
			<h3><?php esc_html_e( 'Configuración unificada (País / Moneda)', 'plugin-monedas' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Selecciona el país y la tasa. El símbolo, moneda y decimales se rellenan automáticamente. Puedes definir método de redondeo, impuestos y pasarelas permitidas. El sistema genera el resto de opciones ocultas.', 'plugin-monedas' ); ?></p>
			<table class="widefat pm-table" id="pm-table-master">
				<thead>
				<tr>
					<th><?php esc_html_e( 'País', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Moneda', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Símbolo', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Tasa', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Decimales', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Redondeo', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Parámetro', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Impuestos', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Pasarelas', 'plugin-monedas' ); ?></th>
					<th><?php esc_html_e( 'Ejemplo', 'plugin-monedas' ); ?></th>
					<th></th>
				</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p><button type="button" class="button" id="pm-master-add"><?php esc_html_e( 'Añadir fila', 'plugin-monedas' ); ?></button></p>
			<!-- Textareas ocultas legacy -->
			<textarea name="plugin_monedas_rates" id="plugin_monedas_rates" hidden></textarea>
			<textarea name="plugin_monedas_cash_round_map" id="plugin_monedas_cash_round_map" hidden></textarea>
			<textarea name="plugin_monedas_magnitude_round_map" id="plugin_monedas_magnitude_round_map" hidden></textarea>
			<textarea name="plugin_monedas_geo_map" id="plugin_monedas_geo_map" hidden></textarea>
			<textarea name="plugin_monedas_currency_country_map" id="plugin_monedas_currency_country_map" hidden></textarea>
			<textarea name="plugin_monedas_decimals" id="plugin_monedas_decimals" hidden></textarea>
			<textarea name="plugin_monedas_gateway_currency_map" id="plugin_monedas_gateway_currency_map" hidden></textarea>
			<textarea name="plugin_monedas_exempt_currencies" id="plugin_monedas_exempt_currencies" hidden></textarea>
		</div>
		<div class="pm-section">
			<h3><?php esc_html_e( 'Opciones globales', 'plugin-monedas' ); ?></h3>
			<p>
				<label><input type="checkbox" name="plugin_monedas_hide_base" value="1" <?php checked( $hide_base, 1 ); ?> /> <?php esc_html_e( 'Ocultar moneda base en selector', 'plugin-monedas' ); ?></label><br/>
				<label><input type="checkbox" name="plugin_monedas_convert_totals" value="1" <?php checked( $convert_totals, 1 ); ?> /> <?php esc_html_e( 'Convertir totales visualmente (carrito/checkout)', 'plugin-monedas' ); ?></label><br/>
				<label><input type="checkbox" name="plugin_monedas_full_multicurrency" value="1" <?php checked( $full_multi, 1 ); ?> /> <?php esc_html_e( 'Multi-moneda real (experimental)', 'plugin-monedas' ); ?></label><br/>
				<label><input type="checkbox" name="plugin_monedas_geo_auto" value="1" <?php checked( $geo_auto, 1 ); ?> /> <?php esc_html_e( 'Geolocalización automática (IP)', 'plugin-monedas' ); ?></label><br/>
				<label><input type="checkbox" name="plugin_monedas_force_tax_country" value="1" <?php checked( $force_tax, 1 ); ?> /> <?php esc_html_e( 'Forzar país fiscal según moneda', 'plugin-monedas' ); ?></label>
			</p>
		</div>
		<?php
	}

	public static function save_wc_tab() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		if ( ! isset( $_POST['plugin_monedas_wc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['plugin_monedas_wc_nonce'] ) ), 'plugin_monedas_wc_save' ) ) { // phpcs:ignore
			return;
		}
		$map_simple = [
			self::OPTION_RATES,
			'plugin_monedas_cash_round_map',
			'plugin_monedas_magnitude_round_map',
			'plugin_monedas_geo_map',
			'plugin_monedas_currency_country_map',
			'plugin_monedas_decimals',
			'plugin_monedas_format_map',
			'plugin_monedas_round_mode'
		];
		foreach ( $map_simple as $opt ) {
			if ( isset( $_POST[ $opt ] ) ) { // phpcs:ignore
				update_option( $opt, wp_unslash( $_POST[ $opt ] ) ); // Sanitiza ya en hooks register_setting (cuando se abre página propia); aquí invocamos manual flush.
			}
		}
		$bools = [ 'plugin_monedas_hide_base', 'plugin_monedas_convert_totals', 'plugin_monedas_full_multicurrency', 'plugin_monedas_geo_auto', 'plugin_monedas_force_tax_country' ];
		foreach ( $bools as $b ) {
			update_option( $b, isset( $_POST[ $b ] ) ? 1 : 0 ); // phpcs:ignore
		}
		self::flush_rates_cache();
		add_action( 'admin_notices', function(){
			printf( '<div class="updated"><p>%s</p></div>', esc_html__( 'Configuración de Monedas guardada.', 'plugin-monedas' ) );
		});
	}

	public static function register_setting() {
		register_setting( 'plugin_monedas_group', self::OPTION_RATES, [
			'type' => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_rates' ],
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_decimals', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				// Formato: CODIGO|decimales por línea.
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 2 ) continue;
					list( $code, $dec ) = $parts;
					$code = strtoupper( preg_replace( '/[^A-Z]/', '', $code ) );
					if ( $code === '' ) continue;
					$dec = (int) $dec;
					if ( $dec < 0 || $dec > 6 ) $dec = 2;
					$out[] = $code . '|' . $dec;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_round_mode', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$allowed = [ 'none', 'round', 'floor', 'ceil' ];
				$value = in_array( $value, $allowed, true ) ? $value : 'none';
				return $value;
			},
			'default' => 'none'
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_hide_base', [
			'type' => 'boolean',
			'sanitize_callback' => function( $value ) { return $value ? 1 : 0; },
			'default' => 0
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_convert_totals', [
			'type' => 'boolean',
			'sanitize_callback' => function( $value ) { return $value ? 1 : 0; },
			'default' => 0
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_full_multicurrency', [
			'type' => 'boolean',
			'sanitize_callback' => function( $value ) { return $value ? 1 : 0; },
			'default' => 0
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_geo_auto', [
			'type' => 'boolean',
			'sanitize_callback' => function( $value ) { return $value ? 1 : 0; },
			'default' => 0
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_geo_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 2 ) continue;
					list( $country, $currency ) = $parts;
					$country = strtoupper( preg_replace( '/[^A-Z]/', '', $country ) );
					$currency = strtoupper( preg_replace( '/[^A-Z]/', '', $currency ) );
					if ( strlen( $country ) !== 2 || strlen( $currency ) < 2 ) continue;
					$out[] = $country . '|' . $currency;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_format_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 3 ) continue; // CURRENCY|thousand|decimal
					list( $cur, $th, $dec ) = $parts;
					$cur = strtoupper( preg_replace( '/[^A-Z]/', '', $cur ) );
					if ( $cur === '' ) continue;
					$th = substr( $th, 0, 2 );
					$dec = substr( $dec, 0, 2 );
					$out[] = $cur . '|' . $th . '|' . $dec;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_cash_round_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 2 ) continue; // CURRENCY|increment
					list( $cur, $inc ) = $parts;
					$cur = strtoupper( preg_replace( '/[^A-Z]/', '', $cur ) );
					$inc = str_replace( ',', '.', $inc );
					if ( ! is_numeric( $inc ) || $inc <= 0 ) continue;
					$out[] = $cur . '|' . $inc;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_magnitude_round_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				$allowed_modes = [ 'nearest', 'up', 'down' ];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 3 ) continue; // MONEDA|magnitud|modo
					list( $cur, $mag, $mode ) = $parts;
					$cur = strtoupper( preg_replace( '/[^A-Z]/', '', $cur ) );
					$mag = str_replace( ',', '.', $mag );
					if ( ! is_numeric( $mag ) || $mag <= 0 ) continue;
					$mode = strtolower( $mode );
					if ( ! in_array( $mode, $allowed_modes, true ) ) continue;
					$out[] = $cur . '|' . $mag . '|' . $mode;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_exempt_currencies', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = strtoupper( preg_replace( '/[^A-Z]/', '', trim( $line ) ) );
					if ( $line === '' ) continue;
					$out[] = $line;
				}
				return implode( "\n", array_unique( $out ) );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_exempt_countries', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = strtoupper( preg_replace( '/[^A-Z]/', '', trim( $line ) ) );
					if ( strlen( $line ) !== 2 ) continue;
					$out[] = $line;
				}
				return implode( "\n", array_unique( $out ) );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_exempt_roles', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = sanitize_key( $line );
					if ( $line === '' ) continue;
					$out[] = $line;
				}
				return implode( "\n", array_unique( $out ) );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_currency_country_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$lines = preg_split( '/\r?\n/', trim( (string) $value ) );
				$out = [];
				foreach ( $lines as $line ) {
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 2 ) continue; // MONEDA|PAIS
					list( $cur, $country ) = $parts;
					$cur = strtoupper( preg_replace( '/[^A-Z]/', '', $cur ) );
					$country = strtoupper( preg_replace( '/[^A-Z]/', '', $country ) );
					if ( strlen( $cur ) < 2 || strlen( $country ) !== 2 ) continue;
					$out[] = $cur . '|' . $country;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_force_tax_country', [
			'type' => 'boolean',
			'sanitize_callback' => function( $value ) { return $value ? 1 : 0; },
			'default' => 0
		]);
		register_setting( 'plugin_monedas_group', 'plugin_monedas_gateway_currency_map', [
			'type' => 'string',
			'sanitize_callback' => function( $value ) {
				$raw = substr( (string) $value, 0, 4000 ); // límite de tamaño
				$lines = preg_split( '/\r?\n/', trim( $raw ) );
				$out = [];
				$max = 100; $count = 0;
				foreach ( $lines as $line ) {
					if ( $count >= $max ) break;
					$line = trim( $line ); if ( $line === '' ) continue;
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) !== 2 ) continue;
					list( $gid, $currs ) = $parts;
					$gid = sanitize_key( $gid ); if ( $gid === '' ) continue;
					$list = array_filter( array_map( function( $c ) { $c = strtoupper( preg_replace( '/[^A-Z]/', '', $c ) ); return $c; }, explode( ',', $currs ) ) );
					if ( empty( $list ) ) continue;
					$out[] = $gid . '|' . implode( ',', array_unique( $list ) );
					$count++;
				}
				return implode( "\n", $out );
			},
			'default' => ''
		]);
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Plugin Monedas',
			'Plugin Monedas',
			'manage_woocommerce',
			'plugin-monedas',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function sanitize_rates( $input ) {
		$lines = preg_split( '/\r?\n/', trim( $input ) );
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) !== 3 ) continue;
			list( $code, $symbol, $rate ) = $parts;
			$code = strtoupper( preg_replace( '/[^A-Z]/', '', $code ) );
			$symbol = wp_kses_post( $symbol );
			$rate = str_replace( ',', '.', $rate );
			if ( ! is_numeric( $rate ) ) continue;
			$rate = (float) $rate;
			if ( $rate <= 0 ) continue;
			$out[] = $code . '|' . $symbol . '|' . $rate;
		}
		return implode( "\n", $out );
	}

	public static function flush_rates_cache() {
		delete_transient( 'plugin_monedas_rates_cache' );
	}

	public static function get_rates_raw() {
		return get_option( self::OPTION_RATES, '' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Plugin Monedas - Tasas Manuales', 'plugin-monedas' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'plugin_monedas_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="plugin_monedas_magnitude_round_map"><?php esc_html_e( 'Redondeo por magnitud', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="plugin_monedas_magnitude_round_map" id="plugin_monedas_magnitude_round_map" rows="6" cols="40" class="code" placeholder="CLP|1000|nearest\nARS|100|down\nEUR|0.1|nearest\nUSD|1|up"><?php echo esc_textarea( get_option( 'plugin_monedas_magnitude_round_map', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Formato: MONEDA|magnitud|modo. Modo: nearest (más cercano), up (superior), down (inferior). Se aplica tras redondeo cash.', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
						<th scope="row"><label for="plugin_monedas_cash_round_map"><?php esc_html_e( 'Redondeo cash por moneda', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="plugin_monedas_cash_round_map" id="plugin_monedas_cash_round_map" rows="6" cols="40" class="code" placeholder="CLP|50\nCHF|0.05\nJPY|1"></textarea>
							<p class="description"><?php esc_html_e( 'Formato: MONEDA|incremento (ej. 0.05, 50). Se aplica después del redondeo decimal.', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
						<th scope="row"><label for="plugin_monedas_geo_auto"><?php esc_html_e( 'Geolocalización automática', 'plugin-monedas' ); ?></label></th>
						<td>
							<label><input type="checkbox" name="plugin_monedas_geo_auto" id="plugin_monedas_geo_auto" value="1" <?php checked( get_option( 'plugin_monedas_geo_auto', 0 ), 1 ); ?> /> <?php esc_html_e( 'Detectar país (IP) y elegir moneda según mapa país->moneda.', 'plugin-monedas' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_geo_map"><?php esc_html_e( 'Mapa país->moneda', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="plugin_monedas_geo_map" id="plugin_monedas_geo_map" rows="8" cols="40" class="code" placeholder="US|USD\nCA|CAD\nMX|MXN\nAR|ARS\nCL|CLP\nCO|COP\nPE|PEN\nBR|BRL\nVE|VES\nUY|UYU\nPY|PYG\nBO|BOB\nEC|USD\nCR|CRC\nPA|USD\nDO|DOP\nGT|GTQ\nSV|USD\nHN|HNL\nNI|NIO\nPR|USD\nES|EUR\nPE|PEN\n"><?php echo esc_textarea( get_option( 'plugin_monedas_geo_map', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Formato: CC|MONEDA (dos letras país ISO-3166-1 alfa-2). Una por línea.', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_format_map"><?php esc_html_e( 'Formato separadores por moneda', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="plugin_monedas_format_map" id="plugin_monedas_format_map" rows="6" cols="40" class="code" placeholder="USD|,|.\nEUR|.|,\nMXN|,|.\nBRL|.|,"><?php echo esc_textarea( get_option( 'plugin_monedas_format_map', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Formato: MONEDA|miles|decimal. Prioriza sobre ajustes globales de WooCommerce.', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
						<th scope="row"><label for="plugin_monedas_full_multicurrency"><?php esc_html_e( 'Multi-moneda real (experimental)', 'plugin-monedas' ); ?></label></th>
						<td>
							<label><input type="checkbox" name="plugin_monedas_full_multicurrency" id="plugin_monedas_full_multicurrency" value="1" <?php checked( get_option( 'plugin_monedas_full_multicurrency', 0 ), 1 ); ?> /> <?php esc_html_e( 'Recalcula precios en el carrito y guarda pedidos en la moneda seleccionada.', 'plugin-monedas' ); ?></label>
							<p class="description"><?php esc_html_e( 'Desactiva la conversión solo visual de totales. Requiere revisión fiscal (impuestos y redondeos).', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_convert_totals"><?php esc_html_e( 'Convertir totales (carrito/checkout)', 'plugin-monedas' ); ?></label></th>
						<td>
							<label><input type="checkbox" name="plugin_monedas_convert_totals" id="plugin_monedas_convert_totals" value="1" <?php checked( get_option( 'plugin_monedas_convert_totals', 0 ), 1 ); ?> /> <?php esc_html_e( 'Mostrar subtotales, impuestos y total en moneda seleccionada (solo visual).', 'plugin-monedas' ); ?></label>
							<p class="description"><?php esc_html_e( 'Los cálculos internos permanecen en la moneda base. Usar solo si se entiende la diferencia.', 'plugin-monedas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_hide_base"><?php esc_html_e( 'Ocultar moneda base', 'plugin-monedas' ); ?></label></th>
						<td>
							<label><input type="checkbox" name="plugin_monedas_hide_base" id="plugin_monedas_hide_base" value="1" <?php checked( get_option( 'plugin_monedas_hide_base', 0 ), 1 ); ?> /> <?php esc_html_e( 'No mostrar la moneda base en el selector', 'plugin-monedas' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_rates"><?php esc_html_e( 'Tasas', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_RATES ); ?>" id="plugin_monedas_rates" rows="10" cols="60" class="large-text code" placeholder="USD|$|1.00\nEUR|€|0.90\nMXN|$|17.50"><?php echo esc_textarea( self::get_rates_raw() ); ?></textarea>
							<p class="description">Formato: CODIGO|símbolo|tasa respecto a moneda base de WooCommerce. Una por línea.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_decimals"><?php esc_html_e( 'Decimales por moneda', 'plugin-monedas' ); ?></label></th>
						<td>
							<textarea name="plugin_monedas_decimals" id="plugin_monedas_decimals" rows="6" cols="40" class="code" placeholder="USD|2\nEUR|2\nMXN|2"><?php echo esc_textarea( get_option( 'plugin_monedas_decimals', '' ) ); ?></textarea>
							<p class="description">Formato: CODIGO|decimales (0-6). Si se omite, usa los decimales globales.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="plugin_monedas_round_mode"><?php esc_html_e( 'Modo de redondeo', 'plugin-monedas' ); ?></label></th>
						<td>
							<select name="plugin_monedas_round_mode" id="plugin_monedas_round_mode">
								<?php $mode = get_option( 'plugin_monedas_round_mode', 'none' ); ?>
								<option value="none" <?php selected( $mode, 'none' ); ?>><?php esc_html_e( 'Sin redondeo extra', 'plugin-monedas' ); ?></option>
								<option value="round" <?php selected( $mode, 'round' ); ?>><?php esc_html_e( 'round()', 'plugin-monedas' ); ?></option>
								<option value="floor" <?php selected( $mode, 'floor' ); ?>><?php esc_html_e( 'floor()', 'plugin-monedas' ); ?></option>
								<option value="ceil" <?php selected( $mode, 'ceil' ); ?>><?php esc_html_e( 'ceil()', 'plugin-monedas' ); ?></option>
							</select>
							<p class="description">Aplicado tras conversión y antes de formato de precio.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
