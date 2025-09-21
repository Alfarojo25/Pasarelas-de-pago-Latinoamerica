<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Subscriptions_Settings {
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_tab' ], 90 );
		add_action( 'woocommerce_settings_tabs_plugin_monedas_subscriptions', [ __CLASS__, 'render' ] );
		add_action( 'woocommerce_update_options_plugin_monedas_subscriptions', [ __CLASS__, 'save' ] );
	}

	public static function add_tab( $tabs ) {
		$tabs['plugin_monedas_subscriptions'] = __( 'Suscripciones Monedas', 'plugin-monedas' );
		return $tabs;
	}

	public static function get_fields() {
		// Recopilar monedas configuradas en el plugin (option plugin_monedas_rates)
		$rates_raw = get_option( 'plugin_monedas_rates', '' );
		$currency_options = [];
		if ( $rates_raw ) {
			foreach ( preg_split( '/\r?\n/', $rates_raw ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( count( $parts ) === 3 && preg_match( '/^[A-Z]{3,6}$/', $parts[0] ) ) {
					$currency_options[ $parts[0] ] = $parts[0];
				}
			}
		}
		$fields = [
			'plugin_monedas_subscriptions_section_title' => [
				'name' => __( 'Suscripciones y Pasarelas', 'plugin-monedas' ),
				'type' => 'title',
				'desc' => __( 'Control de captura de pasarela y campos adicionales para renovaciones automáticas.', 'plugin-monedas' ),
				'id'   => 'plugin_monedas_subscriptions_section_title'
			],
			'plugin_monedas_subscriptions_autopay_enable' => [
				'name' => __( 'Autopago (tokens) habilitado', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_autopay_enable',
				'esc_html__' => '',
				'desc' => __( 'Permite almacenar token (PayPal Billing Agreement / Mercado Pago card_id) y ejecutar cobro automático en renovaciones.', 'plugin-monedas' ),
				'default' => 'no'
			],
			'plugin_monedas_subscriptions_autopay_retry_delay' => [
				'name' => __( 'Delay reintento (horas)', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_autopay_retry_delay',
				'custom_attributes' => [ 'min'=>1, 'max'=>72 ],
				'default' => 24,
				'css' => 'width:80px;',
				'desc' => __( 'Tiempo tras un fallo antes del único reintento automático.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_enable' => [
				'name' => __( 'Activar integración suscripciones', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_enable',
				'desc' => __( 'Habilita la lógica de retención de pasarela y moneda en renovaciones.', 'plugin-monedas' ),
				'default' => 'no'
			],
			'plugin_monedas_subscriptions_store_fields' => [
				'name' => __( 'Campos checkout a guardar', 'plugin-monedas' ),
				'type' => 'textarea',
				'id'   => 'plugin_monedas_subscriptions_store_fields',
				'css'  => 'min-width:400px;height:100px;',
				'placeholder' => "billing_first_name\nbilling_last_name\nbilling_email",
				'desc' => __( 'Lista de meta keys (uno por línea) que se copiarán a renovaciones si faltan o requieren sincronización.', 'plugin-monedas' )
			],
			// Sustitución de política legacy por nueva taxonomía (locked/dynamic/manual_override)
			'plugin_monedas_subscriptions_rate_policy' => [
				'name' => __( 'Política de tasa (nuevas suscripciones)', 'plugin-monedas' ),
				'type' => 'select',
				'id'   => 'plugin_monedas_subscriptions_rate_policy',
				'options' => [
					'locked' => __( 'Locked: Congelar tasa inicial', 'plugin-monedas' ),
					'dynamic' => __( 'Dynamic: Actualizar en cada renovación', 'plugin-monedas' ),
					'manual_override' => __( 'Manual Override: Programar o aprobar cambios', 'plugin-monedas' ),
				],
				'default' => 'locked',
				'css' => 'min-width:260px;',
				'desc' => __( 'Define la política por defecto para nuevas suscripciones multi-base.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_history_max' => [
				'name' => __( 'Máx entradas historial tasa', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_history_max',
				'custom_attributes' => [ 'min' => 1, 'max' => 200 ],
				'default' => 20,
				'css' => 'width:80px;',
				'desc' => __( 'Se recortan las más antiguas al superar este número.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_retention_days' => [
				'name' => __( 'Retención suscripciones obsoletas (días)', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_retention_days',
				'custom_attributes' => [ 'min' => 15, 'max' => 365 ],
				'default' => 60,
				'css' => 'width:80px;',
				'desc' => __( 'Suscripciones canceladas o sin pago se purgan pasado este periodo.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_quick_cancel' => [
				'name' => __( 'Cancelación rápida usuario', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_quick_cancel',
				'desc' => __( 'Permite cancelar escribiendo el usuario en la vista de la suscripción.', 'plugin-monedas' ),
				'default' => 'yes'
			],
			'plugin_monedas_subscriptions_checkout_optin' => [
				'name' => __( 'Checkbox opt‑in en checkout', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_checkout_optin',
				'desc' => __( 'Muestra un checkbox debajo de las pasarelas para crear suscripción automática si el usuario está autenticado.', 'plugin-monedas' ),
				'default' => 'yes'
			],
			'plugin_monedas_subscriptions_optin_product' => [
				'name' => __( 'Opt‑in en página de producto', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_optin_product',
				'desc' => __( 'Mostrar checkbox antes de agregar al carrito.', 'plugin-monedas' ),
				'default' => 'yes'
			],
			'plugin_monedas_subscriptions_optin_cart' => [
				'name' => __( 'Opt‑in en carrito', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_optin_cart',
				'desc' => __( 'Mostrar fila para convertir el carrito completo en suscripción.', 'plugin-monedas' ),
				'default' => 'yes'
			],
			'plugin_monedas_subscriptions_optin_post' => [
				'name' => __( 'Opt‑in post‑compra (gracias)', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_optin_post',
				'desc' => __( 'Permite crear una suscripción basada en el pedido desde la página de agradecimiento.', 'plugin-monedas' ),
				'default' => 'yes'
			],
			'plugin_monedas_subscriptions_intervals' => [
				'name' => __( 'Intervalos disponibles (días)', 'plugin-monedas' ),
				'type' => 'text',
				'id'   => 'plugin_monedas_subscriptions_intervals',
				'desc' => __( 'Lista separada por comas. Ej: 7,30,90. Deben ser enteros positivos.', 'plugin-monedas' ),
				'default' => '30'
			],
			'plugin_monedas_subscriptions_default_interval' => [
				'name' => __( 'Intervalo por defecto (días)', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_default_interval',
				'custom_attributes' => [ 'min'=>1, 'max'=>365 ],
				'desc' => __( 'Debe existir dentro de la lista de intervalos disponibles.', 'plugin-monedas' ),
				'default' => 30
			],
			// NUEVA sección multi-base
			'plugin_monedas_subscriptions_multibase_title' => [
				'name' => __( 'Multi-Base', 'plugin-monedas' ),
				'type' => 'title',
				'desc' => __( 'Configuración específica para suscripciones con moneda base individual.', 'plugin-monedas' ),
				'id'   => 'plugin_monedas_subscriptions_multibase_title'
			],
			'plugin_monedas_subscriptions_allowed_base_currencies' => [
				'name' => __( 'Monedas base permitidas', 'plugin-monedas' ),
				'type' => 'multiselect',
				'id'   => 'plugin_monedas_subscriptions_allowed_base_currencies',
				'options' => $currency_options,
				'class' => 'wc-enhanced-select',
				'css' => 'min-width:300px;',
				'custom_attributes' => [ 'data-placeholder' => __( 'Seleccionar monedas', 'plugin-monedas' ) ],
				'desc' => __( 'Si se deja vacío se asume que cualquier moneda activa del plugin puede ser base.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_rate_precision' => [
				'name' => __( 'Precisión almacenaje tasa', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_rate_precision',
				'custom_attributes' => [ 'min'=>4, 'max'=>12 ],
				'default' => 10,
				'css' => 'width:80px;',
				'desc' => __( 'Número de decimales al normalizar una tasa al guardarla.', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_front_notice' => [
				'name' => __( 'Aviso moneda base frontend', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_front_notice',
				'desc' => __( 'Mostrar aviso textual indicando la moneda base al usuario cuando opta por suscripción.', 'plugin-monedas' ),
				'default' => 'no'
			],
			'plugin_monedas_subscriptions_multibase_section_end' => [
				'type' => 'sectionend',
				'id'   => 'plugin_monedas_subscriptions_multibase_section_end'
			],
			'plugin_monedas_subscriptions_section_end' => [
				'type' => 'sectionend',
				'id'   => 'plugin_monedas_subscriptions_section_end'
			],
			'plugin_monedas_subscriptions_autopay_abandon_title' => [
				'name' => __( 'Autopago – Abandono', 'plugin-monedas' ),
				'type' => 'title',
				'desc' => __( 'Notificaciones diferidas cuando el autopago ya no reintentará más.', 'plugin-monedas' ),
				'id' => 'plugin_monedas_subscriptions_autopay_abandon_title'
			],
			'plugin_monedas_subscriptions_autopay_abandon_enable' => [
				'name' => __( 'Email abandono habilitado', 'plugin-monedas' ),
				'type' => 'checkbox',
				'id'   => 'plugin_monedas_subscriptions_autopay_abandon_enable',
				'desc' => __( 'Enviar un email diferido tras abandono del autopago.', 'plugin-monedas' ),
				'default' => 'no'
			],
			'plugin_monedas_subscriptions_autopay_abandon_delay' => [
				'name' => __( 'Delay email abandono (horas)', 'plugin-monedas' ),
				'type' => 'number',
				'id'   => 'plugin_monedas_subscriptions_autopay_abandon_delay',
				'custom_attributes' => [ 'min'=>1, 'max'=>168 ],
				'default' => 6,
				'css' => 'width:80px;',
				'desc' => __( 'Horas tras marcar abandono para enviar aviso (usuario + admin).', 'plugin-monedas' )
			],
			'plugin_monedas_subscriptions_autopay_abandon_section_end' => [ 'type'=>'sectionend', 'id'=>'plugin_monedas_subscriptions_autopay_abandon_section_end' ],
		];
		return $fields;
	}

	public static function render() {
		woocommerce_admin_fields( self::get_fields() );
	}

	public static function save() {
		woocommerce_update_options( self::get_fields() );
		// Sanitización adicional específica
		if ( isset( $_POST['plugin_monedas_subscriptions_allowed_base_currencies'] ) ) {
			$vals = (array) $_POST['plugin_monedas_subscriptions_allowed_base_currencies'];
			$clean = [];
			foreach ( $vals as $v ) {
				$v = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $v ) );
				if ( preg_match( '/^[A-Z]{3,6}$/', $v ) ) $clean[] = $v;
			}
			update_option( 'plugin_monedas_subscriptions_allowed_base_currencies', array_unique( $clean ) );
		} else {
			delete_option( 'plugin_monedas_subscriptions_allowed_base_currencies' );
		}
		$prec = (int) get_option( 'plugin_monedas_subscriptions_rate_precision', 10 );
		if ( $prec < 4 || $prec > 12 ) update_option( 'plugin_monedas_subscriptions_rate_precision', 10 );
	}
}
