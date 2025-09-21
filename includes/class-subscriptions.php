<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Subscriptions {
	public static function init() {
		if ( 'yes' !== get_option( 'plugin_monedas_subscriptions_enable', 'no' ) ) return;
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		add_action( 'woocommerce_review_order_after_payment', [ __CLASS__, 'render_checkout_optin' ], 25 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'maybe_attach_subscription' ], 20, 2 );
		add_action( 'woocommerce_after_add_to_cart_button', [ __CLASS__, 'render_product_optin' ] );
		add_action( 'woocommerce_cart_totals_after_order_total', [ __CLASS__, 'render_cart_optin' ] );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'render_post_order_optin' ], 15 );
		add_action( 'woocommerce_review_order_after_payment', [ __CLASS__, 'render_interval_selector' ], 24 );
		add_action( 'admin_post_pm_sub_approve_rate', [ __CLASS__, 'approve_pending_rate' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_quick_cancel' ] );
		add_action( 'init', [ __CLASS__, 'schedule_events' ] );
		add_action( 'plugin_monedas_subscriptions_cleanup_event', [ __CLASS__, 'cleanup_old_subscriptions' ] );
		add_action( 'plugin_monedas_subscriptions_renewal_event', [ __CLASS__, 'process_due_renewals' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_admin_metabox' ] );
		add_action( 'woocommerce_account_dashboard', [ __CLASS__, 'render_my_subscriptions_list' ] );
	}

	/* ================= CREACIÓN ================= */
	protected static function create_subscription_post( $order, $forced_interval = null ) {
        $store_currency = get_option( 'woocommerce_currency' );
        $order_currency = method_exists( $order, 'get_currency' ) ? $order->get_currency() : $store_currency;
        $allowed = (array) get_option( 'plugin_monedas_subscriptions_allowed_base_currencies', [] );
        $base_currency = $order_currency;
        if ( $allowed && ! in_array( $order_currency, $allowed, true ) ) {
			$base_currency = $store_currency;
		}
        $sub_currency = $order_currency; // visual/operativa actual
        $raw_policy = get_option( 'plugin_monedas_subscriptions_rate_policy', 'locked' );
        $legacy_map = [ 'fixed'=>'locked', 'update_renewal'=>'dynamic', 'flag_manual'=>'manual_override' ];
        $policy = isset( $legacy_map[ $raw_policy ] ) ? $legacy_map[ $raw_policy ] : $raw_policy;
        if ( ! in_array( $policy, [ 'locked','dynamic','manual_override' ], true ) ) $policy = 'locked';
        $rate_used = (float) ( $order->get_meta( '_plugin_monedas_rate_used' ) ?: 1.0 );
        $precision = (int) get_option( 'plugin_monedas_subscriptions_rate_precision', 10 );
        if ( $precision < 4 || $precision > 12 ) $precision = 10;
        $norm_rate = number_format( $rate_used, $precision, '.', '' );
        if ( $forced_interval ) {
			$interval_days = max( 1, (int) $forced_interval );
		} else {
			$interval_days = isset( $_POST['plugin_monedas_sub_interval'] ) ? max(1,(int)$_POST['plugin_monedas_sub_interval']) : (int) get_option( 'plugin_monedas_subscriptions_default_interval', 30 );
		}
		$raw_int = get_option( 'plugin_monedas_subscriptions_intervals', '30' );
		$allowed_int = array_filter( array_map( 'intval', explode( ',', $raw_int ) ) );
		if ( $allowed_int && ! in_array( $interval_days, $allowed_int, true ) ) {
			$interval_days = (int) get_option( 'plugin_monedas_subscriptions_default_interval', 30 );
		}
        $next = time() + ( $interval_days * DAY_IN_SECONDS );
        $base_amount = 0.0;
        foreach ( $order->get_items() as $item ) { $base_amount += (float) $item->get_total(); }
        if ( $order_currency !== $base_currency && $rate_used > 0 ) { $base_amount = $base_amount * $rate_used; }
        $base_amount_str = number_format( (float) $base_amount, wc_get_price_decimals(), '.', '' );
        $post_id = wp_insert_post( [ 'post_type' => 'pm_subscription', 'post_status' => 'publish', 'post_title' => 'Sub #'.$order->get_id(), 'post_author' => $order->get_user_id() ] );
        if ( $post_id ) {
            update_post_meta( $post_id, '_plugin_monedas_subscription_base_currency', $base_currency );
            update_post_meta( $post_id, '_plugin_monedas_subscription_currency', $sub_currency );
            // Guardar ambos nombres de meta para compatibilidad
            update_post_meta( $post_id, '_plugin_monedas_subscription_initial_rate', $norm_rate );
            update_post_meta( $post_id, '_plugin_monedas_subscription_rate_initial', $norm_rate );
            update_post_meta( $post_id, '_plugin_monedas_subscription_rate_policy', $policy );
            update_post_meta( $post_id, '_plugin_monedas_subscription_gateway', $order->get_payment_method() );
            update_post_meta( $post_id, '_plugin_monedas_subscription_initial_order', $order->get_id() );
            update_post_meta( $post_id, '_plugin_monedas_subscription_interval_days', $interval_days );
            update_post_meta( $post_id, '_plugin_monedas_subscription_next_renewal', $next );
            update_post_meta( $post_id, '_plugin_monedas_subscription_base_amount', $base_amount_str );
            self::append_rate_history_raw( $post_id, [ 'time'=>time(), 'action'=>'create', 'rate'=>$norm_rate, 'currency'=>$sub_currency, 'base'=>$base_currency, 'policy'=>$policy ] );
            do_action( 'plugin_monedas_subscription_created', $post_id, $order );
        }
        return $post_id;
    }

	/* ================= POLÍTICAS ================= */
	protected static function get_policy( $sub_id ) {
		$raw = get_post_meta( $sub_id, '_plugin_monedas_subscription_rate_policy', true );
		$legacy_map = [ 'fixed'=>'locked', 'update_renewal'=>'dynamic', 'flag_manual'=>'manual_override' ];
		if ( isset( $legacy_map[$raw] ) ) $raw = $legacy_map[$raw];
		if ( ! in_array( $raw, [ 'locked','dynamic','manual_override' ], true ) ) $raw = 'locked';
		return $raw;
	}

	protected static function get_initial_rate( $sub_id ) {
		$val = get_post_meta( $sub_id, '_plugin_monedas_subscription_initial_rate', true );
		if ( '' === $val ) $val = get_post_meta( $sub_id, '_plugin_monedas_subscription_rate_initial', true );
		return (float) $val;
	}

	protected static function set_initial_rate( $sub_id, $rate ) {
		update_post_meta( $sub_id, '_plugin_monedas_subscription_initial_rate', $rate );
		update_post_meta( $sub_id, '_plugin_monedas_subscription_rate_initial', $rate );
	}

	protected static function apply_policy_and_rate( $sub_id ) {
        $policy = self::get_policy( $sub_id );
        $base_currency = get_post_meta( $sub_id, '_plugin_monedas_subscription_base_currency', true );
        $sub_currency = get_post_meta( $sub_id, '_plugin_monedas_subscription_currency', true );
        $old_rate = self::get_initial_rate( $sub_id );
        $precision = (int) get_option( 'plugin_monedas_subscriptions_rate_precision', 10 );
        if ( $precision < 4 || $precision > 12 ) $precision = 10;
        if ( 'locked' === $policy ) { return $old_rate; }
        if ( 'dynamic' === $policy ) {
			$new_rate = self::get_live_rate( $base_currency, $sub_currency );
			if ( $new_rate && $new_rate > 0 && abs( $new_rate - $old_rate ) > 0.0000001 ) {
				$norm = number_format( $new_rate, $precision, '.', '' );
				self::set_initial_rate( $sub_id, $norm );
				self::append_rate_history_raw( $sub_id, [ 'time'=>time(),'action'=>'auto_update','rate'=>$norm,'prev'=>$old_rate,'currency'=>$sub_currency,'base'=>$base_currency,'policy'=>'dynamic' ] );
				return (float) $norm;
			}
			return $old_rate;
		}
        if ( 'manual_override' === $policy ) {
			$pending = get_post_meta( $sub_id, '_plugin_monedas_subscription_next_manual_rate', true );
			if ( $pending ) {
				$norm = number_format( (float)$pending, $precision, '.', '' );
				$old = $old_rate;
				self::set_initial_rate( $sub_id, $norm );
				update_post_meta( $sub_id, '_plugin_monedas_subscription_last_override_rate', $norm );
				delete_post_meta( $sub_id, '_plugin_monedas_subscription_next_manual_rate' );
				delete_post_meta( $sub_id, '_plugin_monedas_subscription_next_manual_reason' );
				self::append_rate_history_raw( $sub_id, [ 'time'=>time(),'action'=>'manual_override','rate'=>$norm,'prev'=>$old,'currency'=>$sub_currency,'base'=>$base_currency,'policy'=>'manual_override' ] );
				return (float) $norm;
			}
			return $old_rate;
		}
        return $old_rate;
    }

	/* ================= MIGRACIÓN LIGERA (placeholder futura) ================= */
	// Se podrá añadir una rutina admin para migrar claves legacy si hiciera falta.

	/* ================= RENOVACIONES ================= */
    protected static function process_due_renewals() {
        $now = time();
        $due = get_posts( [ 'post_type'=>'pm_subscription', 'post_status'=>'publish', 'numberposts'=>20, 'meta_query'=>[
            [ 'key'=>'_plugin_monedas_subscription_next_renewal', 'value'=>$now, 'compare'=>'<=' ]
        ] ] );
        foreach ( $due as $s ) {
            $sub_id = $s->ID;
            $applied_rate = self::apply_policy_and_rate( $sub_id );
            $initial_order_id = get_post_meta( $sub_id, '_plugin_monedas_subscription_initial_order', true );
            $initial = $initial_order_id ? wc_get_order( $initial_order_id ) : null;
            if ( ! $initial ) continue;
            $new_order = wc_create_order( [ 'customer_id'=>$initial->get_user_id() ] );
            foreach ( $initial->get_items() as $item ) {
                $product = $item->get_product(); if ( ! $product ) continue;
                $new_order->add_product( $product, $item->get_quantity() );
            }
            $base_currency = get_post_meta( $sub_id, '_plugin_monedas_subscription_base_currency', true );
            if ( $base_currency ) { $new_order->set_currency( $base_currency ); }
            $new_order->update_meta_data( '_plugin_monedas_parent_subscription', $sub_id );
            $new_order->update_meta_data( '_plugin_monedas_subscription_applied_rate', $applied_rate );
            $new_order->save();
			/**
			 * Hook tras crear pedido de renovación antes de intentar autopago.
			 */
			do_action( 'plugin_monedas_subscription_renewal_order_created', $new_order->get_id(), $sub_id );
            $interval = (int) get_post_meta( $sub_id, '_plugin_monedas_subscription_interval_days', true );
            update_post_meta( $sub_id, '_plugin_monedas_subscription_next_renewal', time() + ( $interval * DAY_IN_SECONDS ) );
            self::append_rate_history_raw( $sub_id, [ 'time'=>time(),'action'=>'renewal','rate'=>self::get_initial_rate( $sub_id ), 'policy'=>self::get_policy( $sub_id ) ] );
        }
    }

	/* ================= REST ================= */
	public static function register_rest_routes() {
		register_rest_route( 'plugin-monedas/v1', '/subscriptions', [
			'methods'  => 'POST',
			'callback' => [ __CLASS__, 'rest_create_subscription' ],
			'permission_callback' => function() { return is_user_logged_in(); },
		] );
		register_rest_route( 'plugin-monedas/v1', '/subscriptions/(?P<id>\\d+)', [
			'methods'  => 'DELETE',
			'callback' => [ __CLASS__, 'rest_cancel_subscription' ],
			'permission_callback' => function() { return is_user_logged_in(); },
			'args' => [ 'id'=>[ 'validate_callback'=> 'is_numeric' ] ]
		] );
		register_rest_route( 'plugin-monedas/v1', '/subscriptions/(?P<id>\\d+)/autopay', [
			'methods'  => 'PATCH',
			'callback' => [ __CLASS__, 'rest_toggle_autopay' ],
			'permission_callback' => function() { return is_user_logged_in(); },
			'args' => [
				'id' => [ 'validate_callback' => 'is_numeric' ],
				'enabled' => [ 'required' => false ]
			]
		] );
		register_rest_route( 'plugin-monedas/v1', '/subscriptions', [
			'methods'  => 'GET',
			'callback' => [ __CLASS__, 'rest_list_subscriptions' ],
			'permission_callback' => function() { return is_user_logged_in(); },
		] );
	}

	public static function rest_create_subscription( $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$interval = $request->get_param( 'interval_days' );
		$desired_base = strtoupper( (string) $request->get_param( 'base_currency' ) );
		$desired_policy = $request->get_param( 'rate_policy' );
		$order = wc_get_order( $order_id );
		if ( ! $order ) return new \WP_Error( 'invalid_order', __( 'Pedido no válido', 'plugin-monedas' ), [ 'status'=>400 ] );
		if ( (int) $order->get_user_id() !== get_current_user_id() ) return new \WP_Error( 'forbidden', __( 'No autorizado', 'plugin-monedas' ), [ 'status'=>403 ] );
		$eligible = apply_filters( 'plugin_monedas_subscription_order_eligible', true, $order );
		if ( ! $eligible ) return new \WP_Error( 'not_eligible', __( 'Pedido no elegible para suscripción', 'plugin-monedas' ), [ 'status'=>400 ] );
		$existing = get_posts( [ 'post_type'=>'pm_subscription','meta_key'=>'_plugin_monedas_subscription_initial_order','meta_value'=>$order_id,'fields'=>'ids' ] );
		if ( $existing ) return new \WP_Error( 'exists', __( 'Ya existe una suscripción para este pedido', 'plugin-monedas' ), [ 'status'=>409 ] );
		if ( $desired_policy && ! in_array( $desired_policy, [ 'locked','dynamic','manual_override' ], true ) ) { $desired_policy = ''; }
		if ( $desired_policy ) { update_option( 'plugin_monedas_subscriptions_rate_policy', $desired_policy ); }
		// Opcionalmente forzar base temporalmente durante creación (simple: alteramos currency del order runtime? preferimos confiar en create_subscription_post para allowed).
		$sub_id = self::create_subscription_post( $order, $interval ? (int) $interval : null );
		if ( ! $sub_id ) return new \WP_Error( 'creation_failed', __( 'No se pudo crear suscripción', 'plugin-monedas' ), [ 'status'=>500 ] );
		return self::format_subscription_response( $sub_id );
	}

	protected static function format_subscription_response( $sid ) {
		return [
			'id' => $sid,
			'currency' => get_post_meta( $sid, '_plugin_monedas_subscription_currency', true ),
			'base_currency' => get_post_meta( $sid, '_plugin_monedas_subscription_base_currency', true ),
			'base_amount' => get_post_meta( $sid, '_plugin_monedas_subscription_base_amount', true ),
			'rate' => self::get_initial_rate( $sid ),
			'rate_policy' => self::get_policy( $sid ),
			'interval' => (int) get_post_meta( $sid, '_plugin_monedas_subscription_interval_days', true ),
			'next_renewal' => (int) get_post_meta( $sid, '_plugin_monedas_subscription_next_renewal', true ),
			'initial_order' => (int) get_post_meta( $sid, '_plugin_monedas_subscription_initial_order', true ),
			'gateway' => get_post_meta( $sid, '_plugin_monedas_subscription_gateway', true ),
			'autopay' => (bool) get_post_meta( $sid, '_plugin_monedas_subscription_autopay_enabled', true ),
			'links' => [ 'self' => rest_url( 'plugin-monedas/v1/subscriptions/'.$sid ) ]
		];
	}

	public static function rest_cancel_subscription( $request ) {
		$sub_id = absint( $request->get_param( 'id' ) );
		$post = get_post( $sub_id );
		if ( ! $post || 'pm_subscription' !== $post->post_type ) return new \WP_Error( 'not_found', __( 'Suscripción no encontrada', 'plugin-monedas' ), [ 'status'=>404 ] );
		$owner = (int) $post->post_author;
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) return new \WP_Error( 'forbidden', __( 'No autorizado', 'plugin-monedas' ), [ 'status'=>403 ] );
		wp_update_post( [ 'ID'=>$sub_id, 'post_status'=>'pm_cancelled' ] );
		update_post_meta( $sub_id, '_plugin_monedas_subscription_status', 'cancelled' );
		return [ 'id'=>$sub_id, 'status'=>'cancelled' ];
	}

	public static function rest_list_subscriptions( $request ) {
		$user_id = get_current_user_id();
		$subs = get_posts( [ 'post_type'=>'pm_subscription', 'post_status'=>'any', 'author'=>$user_id, 'numberposts'=>100, 'fields'=>'ids' ] );
		$data = [];
		foreach ( $subs as $sid ) { $data[] = self::format_subscription_response( $sid ); }
		return $data;
	}

	public static function rest_toggle_autopay( $request ) {
		$sub_id = absint( $request->get_param( 'id' ) );
		$post = get_post( $sub_id );
		if ( ! $post || 'pm_subscription' !== $post->post_type ) return new \WP_Error( 'not_found', __( 'Suscripción no encontrada', 'plugin-monedas' ), [ 'status'=>404 ] );
		$owner = (int) $post->post_author;
		if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) return new \WP_Error( 'forbidden', __( 'No autorizado', 'plugin-monedas' ), [ 'status'=>403 ] );
		$param = $request->get_param( 'enabled' );
		$current = (bool) get_post_meta( $sub_id, '_plugin_monedas_subscription_autopay_enabled', true );
		if ( null === $param ) {
			if ( $current ) { delete_post_meta( $sub_id, '_plugin_monedas_subscription_autopay_enabled' ); $current = false; }
			else { update_post_meta( $sub_id, '_plugin_monedas_subscription_autopay_enabled', 1 ); $current = true; }
		} else {
			$want = in_array( strtolower( (string) $param ), [ '1','true','yes','on' ], true );
			if ( $want && ! $current ) { update_post_meta( $sub_id, '_plugin_monedas_subscription_autopay_enabled', 1 ); $current = true; }
			if ( ! $want && $current ) { delete_post_meta( $sub_id, '_plugin_monedas_subscription_autopay_enabled' ); $current = false; }
		}
		return [ 'id' => $sub_id, 'autopay' => $current, 'message' => $current ? __( 'Autopago activado', 'plugin-monedas' ) : __( 'Autopago desactivado', 'plugin-monedas' ) ];
	}

	/* ================= UTILidades ================= */
	protected static function append_rate_history_raw( $sub_id, $entry ) {
        $max = (int) get_option( 'plugin_monedas_subscriptions_history_max', 20 );
        if ( $max < 1 ) $max = 20;
        $raw = get_post_meta( $sub_id, '_plugin_monedas_subscription_rate_history', true );
        $hist = [];
        if ( $raw ) { $data = json_decode( $raw, true ); if ( is_array( $data ) ) $hist = $data; }
        $hist[] = $entry;
        if ( count( $hist ) > $max ) { $hist = array_slice( $hist, -1 * $max ); }
        update_post_meta( $sub_id, '_plugin_monedas_subscription_rate_history', wp_json_encode( $hist ) );
    }

	protected static function get_live_rate( $base, $cur ) {
		$rate = 1.0;
		$rates_map_raw = get_option( 'plugin_monedas_rates', '' );
		if ( $rates_map_raw ) {
			foreach ( preg_split( '/\r?\n/', $rates_map_raw ) as $line ) {
				$parts = explode( '|', trim( $line ) );
				if ( count( $parts ) === 3 && $parts[0] === $cur ) { $rate = (float) $parts[2]; break; }
			}
		}
		return (float) apply_filters( 'plugin_monedas_live_rate_for_subscription', $rate, $base, $cur );
	}

	protected static function get_config_fields() {
		$raw = get_option( 'plugin_monedas_subscriptions_store_fields', '' );
		$lines = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $raw ) ) );
		$san = [];
		foreach ( $lines as $l ) { if ( preg_match( '/^[a-z0-9_]{3,40}$/i', $l ) ) $san[] = $l; }
		return $san;
	}

	public static function render_my_subscriptions_list() {
		if ( ! is_user_logged_in() ) return;
		// Vista detalle historial autopay
		if ( isset( $_GET['pm_sub_history'] ) ) {
			$sub_id = absint( $_GET['pm_sub_history'] );
			$post = get_post( $sub_id );
			if ( $post && $post->post_type === 'pm_subscription' && (int)$post->post_author === get_current_user_id() ) {
				self::render_single_autopay_history( $sub_id );
				echo '<p><a class="button" href="'.esc_url( remove_query_arg( 'pm_sub_history' ) ).'">'.esc_html__( 'Volver a mis suscripciones','plugin-monedas' ).'</a></p>';
			}
			return; // no list
		}
		$subs = get_posts( [ 'post_type'=>'pm_subscription', 'post_status'=>'publish', 'author'=>get_current_user_id(), 'numberposts'=>50 ] );
		if ( ! $subs ) return;
		// Procesar acción toggle autopago
		if ( isset( $_GET['pm_sub_toggle_autopay'], $_GET['_pm_nonce'] ) ) {
			$sid = absint( $_GET['pm_sub_toggle_autopay'] );
			if ( $sid && wp_verify_nonce( sanitize_key( $_GET['_pm_nonce'] ), 'pm_toggle_autopay_'.$sid ) ) {
				$enabled = get_post_meta( $sid, '_plugin_monedas_subscription_autopay_enabled', true );
				if ( $enabled ) { delete_post_meta( $sid, '_plugin_monedas_subscription_autopay_enabled' ); }
				else { update_post_meta( $sid, '_plugin_monedas_subscription_autopay_enabled', 1 ); }
				wp_safe_redirect( remove_query_arg( [ 'pm_sub_toggle_autopay','_pm_nonce' ] ) );
				exit;
			}
		}
		echo '<h3>'.esc_html__( 'Mis Suscripciones', 'plugin-monedas' ).'</h3>';
		echo '<table class="shop_table"><thead><tr><th>ID</th><th>'.esc_html__( 'Moneda','plugin-monedas' ).'</th><th>'.esc_html__( 'Tasa','plugin-monedas' ).'</th><th>'.esc_html__( 'Próxima','plugin-monedas' ).'</th><th>'.esc_html__( 'Autopago','plugin-monedas' ).'</th><th>'.esc_html__( 'Acciones','plugin-monedas' ).'</th></tr></thead><tbody>';
		foreach ( $subs as $s ) {
			$rate = get_post_meta( $s->ID, '_plugin_monedas_subscription_initial_rate', true );
			$cur = get_post_meta( $s->ID, '_plugin_monedas_subscription_currency', true );
			$next = (int) get_post_meta( $s->ID, '_plugin_monedas_subscription_next_renewal', true );
			$cancel = '';
			if ( 'yes' === get_option( 'plugin_monedas_subscriptions_quick_cancel', 'yes' ) ) {
				$url = wp_nonce_url( add_query_arg( [ 'pm_sub_cancel' => $s->ID ] ), 'pm_quick_cancel_'.$s->ID, '_pm_nonce' );
				$cancel = '<a class="button" href="'.esc_url( $url ).'" onclick="return confirm(\''.esc_js__( '¿Cancelar?', 'plugin-monedas' ).'\');">'.esc_html__( 'Cancelar', 'plugin-monedas' ).'</a>';
			}
			$autopay = get_post_meta( $s->ID, '_plugin_monedas_subscription_autopay_enabled', true );
			$toggle_url = wp_nonce_url( add_query_arg( [ 'pm_sub_toggle_autopay'=>$s->ID ] ), 'pm_toggle_autopay_'.$s->ID, '_pm_nonce' );
			$autopay_label = $autopay ? '<span style="color:#2f855a;font-weight:600">'.esc_html__( 'Activo','plugin-monedas' ).'</span>' : '<span style="color:#a00;font-weight:600">'.esc_html__( 'Inactivo','plugin-monedas' ).'</span>';
			$autopay_btn = '<a class="button" style="margin-left:6px" href="'.esc_url( $toggle_url ).'">'.( $autopay ? esc_html__( 'Desactivar','plugin-monedas' ) : esc_html__( 'Activar','plugin-monedas' ) ).'</a>';
			$history_link = '<a class="button" style="margin-left:6px" href="'.esc_url( add_query_arg( [ 'pm_sub_history'=>$s->ID ] ) ).'">'.esc_html__( 'Historial Autopago','plugin-monedas' ).'</a>';
			echo '<tr><td>#'.esc_html( $s->ID ).'</td><td>'.esc_html( $cur ).'</td><td>'.esc_html( $rate ).'</td><td>'.esc_html( $next ? date_i18n( 'Y-m-d', $next ) : '-' ).'</td><td>'.$autopay_label.$autopay_btn.'</td><td>'.$cancel.$history_link.'</td></tr>';
		}
		echo '</tbody></table>';
	}

	protected static function render_single_autopay_history( $sub_id ) {
		echo '<h3>'.sprintf( esc_html__( 'Historial Autopago Suscripción #%d', 'plugin-monedas' ), $sub_id ).'</h3>';
		$raw = get_post_meta( $sub_id, '_plugin_monedas_subscription_rate_history', true );
		$hist = [];
		if ( $raw ) { $j = json_decode( $raw, true ); if ( is_array( $j ) ) $hist = $j; }
		// Filtrar sólo eventos autopay (charge_*)
		$auto_events = array_filter( $hist, function( $e ){ return isset( $e['action'] ) && strpos( $e['action'], 'charge_' ) === 0; } );
		if ( empty( $auto_events ) ) { echo '<p><em>'.esc_html__( 'Sin eventos de autopago aún.', 'plugin-monedas' ).'</em></p>'; return; }
		echo '<table class="shop_table"><thead><tr><th>'.esc_html__( 'Fecha','plugin-monedas' ).'</th><th>'.esc_html__( 'Acción','plugin-monedas' ).'</th><th>'.esc_html__( 'Intento','plugin-monedas' ).'</th><th>'.esc_html__( 'Proveedor','plugin-monedas' ).'</th><th>'.esc_html__( 'Detalle','plugin-monedas' ).'</th></tr></thead><tbody>';
		foreach ( array_reverse( $auto_events ) as $e ) {
			$time = isset( $e['time'] ) ? date_i18n( 'Y-m-d H:i', $e['time'] ) : '-';
			$action = isset( $e['action'] ) ? $e['action'] : '-';
			$attempt = isset( $e['attempt'] ) ? (int)$e['attempt'] : '-';
			$provider = isset( $e['provider'] ) ? $e['provider'] : '-';
			$detail = '';
			if ( isset( $e['error'] ) ) { $detail .= esc_html__( 'Error:','plugin-monedas' ).' '.esc_html( $e['error'] ); }
			echo '<tr><td>'.esc_html( $time ).'</td><td>'.esc_html( $action ).'</td><td>'.esc_html( $attempt ).'</td><td>'.esc_html( $provider ).'</td><td>'. $detail .'</td></tr>';
		}
		echo '</tbody></table>';
	}
}
