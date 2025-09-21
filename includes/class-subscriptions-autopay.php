<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Clase base de Autopago para suscripciones.
 * No implementa llamadas reales a PayPal o Mercado Pago; provee ganchos y flujo.
 */
class Subscriptions_Autopay {
	const META_AUTOPAY_ENABLED = '_plugin_monedas_subscription_autopay_enabled';
	const META_PROVIDER        = '_plugin_monedas_subscription_autopay_provider'; // paypal|mercadopago
	const META_TOKEN_ID        = '_plugin_monedas_subscription_autopay_token';    // billing agreement id / card_id
	const META_CUSTOMER_ID     = '_plugin_monedas_subscription_autopay_customer'; // payer id / customer_id
	const META_ATTEMPTS        = '_plugin_monedas_subscription_autopay_attempts';
	const META_MAX_ATTEMPTS    = '_plugin_monedas_subscription_autopay_max';
	const META_LAST            = '_plugin_monedas_subscription_autopay_last'; // JSON {time,status,attempt,message,provider,order_id}
	const META_NEXT_RETRY      = '_plugin_monedas_subscription_autopay_next_retry'; // timestamp
	// Nueva meta para programar notificación diferida abandono
	const META_ABANDON_NOTIFY  = '_plugin_monedas_subscription_autopay_abandon_notify_scheduled'; // timestamp ejecución programada

	public static function init() {
		add_action( 'plugin_monedas_subscription_renewal_order_created', [ __CLASS__, 'maybe_charge' ], 10, 2 );
		add_action( 'plugin_monedas_subscription_autopay_retry', [ __CLASS__, 'retry_event' ], 10, 2 );
		add_action( 'plugin_monedas_subscription_autopay_abandon_notify', [ __CLASS__, 'abandon_notify_event' ], 10, 1 );
	}

	/**
	 * Marca la suscripción para usar autopago si el usuario lo consintió y existe token.
	 */
	public static function enable_for_subscription( $subscription_id, $provider, $customer_id, $token_id, $max_attempts = 2 ) {
		update_post_meta( $subscription_id, self::META_AUTOPAY_ENABLED, 1 );
		update_post_meta( $subscription_id, self::META_PROVIDER, sanitize_key( $provider ) );
		update_post_meta( $subscription_id, self::META_CUSTOMER_ID, sanitize_text_field( $customer_id ) );
		update_post_meta( $subscription_id, self::META_TOKEN_ID, sanitize_text_field( $token_id ) );
		update_post_meta( $subscription_id, self::META_ATTEMPTS, 0 );
		update_post_meta( $subscription_id, self::META_MAX_ATTEMPTS, (int) $max_attempts );
		// Si se reactiva autopago, limpiar posible programacion de abandono anterior
		delete_post_meta( $subscription_id, self::META_ABANDON_NOTIFY );
	}

	public static function disable_for_subscription( $subscription_id ) {
		delete_post_meta( $subscription_id, self::META_AUTOPAY_ENABLED );
	}

	public static function user_has_autopay( $subscription_id ) {
		return (bool) get_post_meta( $subscription_id, self::META_AUTOPAY_ENABLED, true );
	}

	public static function maybe_charge( $order_id, $subscription_id ) {
		if ( ! self::user_has_autopay( $subscription_id ) ) return; // Usuario lo desactivó.
		$provider = get_post_meta( $subscription_id, self::META_PROVIDER, true );
		$token    = get_post_meta( $subscription_id, self::META_TOKEN_ID, true );
		$customer = get_post_meta( $subscription_id, self::META_CUSTOMER_ID, true );
		if ( ! $provider || ! $token ) return; // Incompleto
		$order = wc_get_order( $order_id ); if ( ! $order ) return;
		if ( $order->get_total() <= 0 ) { $order->payment_complete(); return; }
		$attempts = (int) get_post_meta( $subscription_id, self::META_ATTEMPTS, true );
		$max      = (int) get_post_meta( $subscription_id, self::META_MAX_ATTEMPTS, true );
		if ( $attempts >= $max ) return; // ya se agotaron intentos

		$result = self::process_provider_charge( $provider, $order, [
			'customer_id' => $customer,
			'token_id'    => $token,
			'subscription_id' => $subscription_id,
			'attempt' => $attempts + 1,
		] );

		if ( $result['status'] === 'success' ) {
			$order->payment_complete( isset( $result['transaction_id'] ) ? $result['transaction_id'] : '' );
			self::append_history( $subscription_id, 'charge_success', [ 'attempt'=>$attempts+1, 'provider'=>$provider ] );
			update_post_meta( $subscription_id, self::META_LAST, wp_json_encode( [
				'time'=> time(),
				'status'=>'success',
				'attempt'=>$attempts+1,
				'provider'=>$provider,
				'order_id'=>$order_id,
				'amount'=>$order->get_total(),
				'currency'=>$order->get_currency(),
				'next_retry'=> null,
			] ) );
			delete_post_meta( $subscription_id, self::META_NEXT_RETRY );
			delete_post_meta( $subscription_id, self::META_ABANDON_NOTIFY );
			self::send_email_event( $subscription_id, 'success', $order, [ 'attempt'=>$attempts+1, 'provider'=>$provider ] );
		} else {
			$order->update_status( 'failed', 'Autopago fallo intento '.$attempts+1 );
			$attempts++;
			update_post_meta( $subscription_id, self::META_ATTEMPTS, $attempts );
			self::append_history( $subscription_id, 'charge_fail', [ 'attempt'=>$attempts, 'provider'=>$provider, 'error'=>$result['message'] ] );
			if ( $attempts < $max ) {
				$delay = (int) apply_filters( 'plugin_monedas_autopay_retry_delay', 24 * HOUR_IN_SECONDS, $subscription_id, $order_id, $attempts );
				self::schedule_retry( $order_id, $subscription_id, $delay );
				update_post_meta( $subscription_id, self::META_NEXT_RETRY, time() + $delay );
				update_post_meta( $subscription_id, self::META_LAST, wp_json_encode( [
					'time'=> time(),
					'status'=>'retry_scheduled',
					'attempt'=>$attempts,
					'provider'=>$provider,
					'order_id'=>$order_id,
					'error'=>$result['message'],
					'next_retry'=> time() + $delay,
				] ) );
				self::send_email_event( $subscription_id, 'fail', $order, [ 'attempt'=>$attempts, 'provider'=>$provider, 'message'=>$result['message'], 'retry_in'=>$delay ] );
			} else {
				self::append_history( $subscription_id, 'charge_abandon', [] );
				update_post_meta( $subscription_id, self::META_LAST, wp_json_encode( [
					'time'=> time(),
					'status'=>'abandon',
					'attempt'=>$attempts,
					'provider'=>$provider,
					'order_id'=>$order_id,
					'error'=>$result['message'],
					'next_retry'=> null,
				] ) );
				delete_post_meta( $subscription_id, self::META_NEXT_RETRY );
				self::send_email_event( $subscription_id, 'abandon', $order, [ 'attempt'=>$attempts, 'provider'=>$provider, 'message'=>$result['message'] ] );
				// Programar notificación diferida de abandono si está habilitada
				self::maybe_schedule_abandon_notification( $subscription_id );
			}
		}
	}

	protected static function schedule_retry( $order_id, $subscription_id, $delay ) {
		wp_schedule_single_event( time() + $delay, 'plugin_monedas_subscription_autopay_retry', [ $order_id, $subscription_id ] );
	}

	protected static function maybe_schedule_abandon_notification( $subscription_id ) {
		if ( get_option( 'plugin_monedas_subscriptions_autopay_abandon_enable' ) !== 'yes' ) return;
		if ( get_post_meta( $subscription_id, self::META_ABANDON_NOTIFY, true ) ) return; // ya programado
		$delay_hours = (int) get_option( 'plugin_monedas_subscriptions_autopay_abandon_delay', 6 );
		if ( $delay_hours < 1 ) $delay_hours = 1; if ( $delay_hours > 168 ) $delay_hours = 168; // 1h - 7d
		$delay = $delay_hours * HOUR_IN_SECONDS;
		$timestamp = time() + $delay;
		wp_schedule_single_event( $timestamp, 'plugin_monedas_subscription_autopay_abandon_notify', [ $subscription_id ] );
		update_post_meta( $subscription_id, self::META_ABANDON_NOTIFY, $timestamp );
		self::append_history( $subscription_id, 'abandon_notify_scheduled', [ 'in_hours'=>$delay_hours ] );
	}

	public static function abandon_notify_event( $subscription_id ) {
		$scheduled = (int) get_post_meta( $subscription_id, self::META_ABANDON_NOTIFY, true );
		if ( ! $scheduled ) return; // nada programado
		// Verificar que la suscripción sigue en estado abandono (último meta)
		$last_raw = get_post_meta( $subscription_id, self::META_LAST, true );
		$status_ok = false; $order = null; $last_order_id = 0;
		if ( $last_raw ) { $j = json_decode( $last_raw, true ); if ( is_array( $j ) && isset( $j['status'] ) && $j['status'] === 'abandon' ) { $status_ok = true; $last_order_id = isset( $j['order_id'] ) ? (int) $j['order_id'] : 0; } }
		if ( ! $status_ok ) { delete_post_meta( $subscription_id, self::META_ABANDON_NOTIFY ); return; }
		if ( $last_order_id ) { $order = wc_get_order( $last_order_id ); }
		self::send_email_event( $subscription_id, 'abandon_delayed', $order, [] );
		self::append_history( $subscription_id, 'abandon_notify_sent', [] );
		delete_post_meta( $subscription_id, self::META_ABANDON_NOTIFY );
	}

	public static function retry_event( $order_id, $subscription_id ) {
		// Crear un nuevo intento sólo si pedido sigue fallido/pending.
		$order = wc_get_order( $order_id ); if ( ! $order ) return;
		if ( ! in_array( $order->get_status(), [ 'pending','failed' ], true ) ) return;
		self::maybe_charge( $order_id, $subscription_id );
	}

	protected static function process_provider_charge( $provider, $order, $context ) {
		// Aquí se delega a proveedores específicos.
		$provider = strtolower( $provider );
		return apply_filters( 'plugin_monedas_autopay_process_charge', [
			'status' => 'error',
			'message'=> 'No provider handler',
		], $provider, $order, $context );
	}

	protected static function append_history( $sub_id, $action, $extra ) {
		$raw = get_post_meta( $sub_id, '_plugin_monedas_subscription_rate_history', true );
		$hist = [];
		if ( $raw ) { $j = json_decode( $raw, true ); if ( is_array( $j ) ) $hist = $j; }
		$hist[] = [ 'time'=>time(),'action'=>$action ] + $extra;
		$max = (int) get_option( 'plugin_monedas_subscriptions_history_max', 20 );
		if ( $max < 1 ) $max = 20;
		if ( count( $hist ) > $max ) { $hist = array_slice( $hist, -1 * $max ); }
		update_post_meta( $sub_id, '_plugin_monedas_subscription_rate_history', wp_json_encode( $hist ) );
	}

	protected static function send_email_event( $subscription_id, $status, $order, $context = [] ) {
		$enabled = apply_filters( 'plugin_monedas_autopay_email_enabled', true, $subscription_id, $status, $context );
		if ( ! $enabled ) return;
		$sub_post = get_post( $subscription_id );
		if ( ! $sub_post ) return;
		$user = get_user_by( 'id', $sub_post->post_author );
		$to = [];
		if ( $user && $user->user_email ) $to[] = $user->user_email;
		$to[] = get_option( 'admin_email' );
		$to = apply_filters( 'plugin_monedas_autopay_email_recipients', array_unique( $to ), $subscription_id, $status, $context );
		$placeholders = [
			'{subscription_id}' => $subscription_id,
			'{order_id}'        => $order ? $order->get_id() : 0,
			'{status}'          => $status,
			'{attempt}'         => isset( $context['attempt'] ) ? $context['attempt'] : '',
			'{provider}'        => isset( $context['provider'] ) ? $context['provider'] : '',
			'{total}'           => $order ? wc_price( $order->get_total(), [ 'currency'=>$order->get_currency() ] ) : '',
			'{currency}'        => $order ? $order->get_currency() : '',
			'{message}'         => isset( $context['message'] ) ? $context['message'] : '',
			'{retry_in_hours}'  => isset( $context['retry_in'] ) ? round( $context['retry_in'] / HOUR_IN_SECONDS ) : '',
		];
		$default_subject = sprintf( '[Autopago %s] Subscripción #%d', ucfirst( $status ), $subscription_id );
		$subject = apply_filters( 'plugin_monedas_autopay_email_subject', $default_subject, $subscription_id, $status, $context );
		$default_body = "Estado: {status}\nSuscripción: {subscription_id}\nPedido: {order_id}\nIntento: {attempt}\nProveedor: {provider}\nTotal: {total}\nMensaje: {message}";
		if ( $status === 'fail' && isset( $context['retry_in'] ) ) {
			$default_body .= "\nSe reintentará en {retry_in_hours} horas.";
		}
		if ( $status === 'abandon' ) {
			$default_body .= "\nNo habrá más reintentos automáticos.";
		}
		if ( $status === 'abandon_delayed' ) {
			$default_body .= "\nRecordatorio: el autopago se abandonó y requiere acción manual del usuario (actualizar método de pago o pagar renovación).";
		}
		$body = apply_filters( 'plugin_monedas_autopay_email_body', $default_body, $subscription_id, $status, $context );
		$body_f = strtr( $body, $placeholders );
		$subject_f = strtr( $subject, $placeholders );
		wp_mail( $to, $subject_f, $body_f );
		do_action( 'plugin_monedas_autopay_email_sent', $subscription_id, $status, $to, $context );
	}
}

add_action( 'init', function(){
	Subscriptions_Autopay::init();
});
