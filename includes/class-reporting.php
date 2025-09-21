<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reporte básico multi-moneda.
 * Endpoint admin: Tools > (submenú) Reporte Multi-Moneda.
 * Muestra:
 *  - Totales por moneda (suma grand total en esa moneda guardada del pedido)
 *  - Conversión al equivalente base usando la tasa almacenada en el pedido.
 */
class Reporting {
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
	}

	public static function menu() {
		add_submenu_page( 'tools.php', __( 'Reporte Multi-Moneda', 'plugin-monedas' ), __( 'Reporte Multi-Moneda', 'plugin-monedas' ), 'manage_woocommerce', 'pm-multicurrency-report', [ __CLASS__, 'render_page' ] );
	}

	private static function query_orders( $from, $to ) {
		$args = [
			'limit' => -1,
			'type' => 'shop_order',
			'status' => [ 'wc-completed','wc-processing','wc-on-hold' ],
			'date_created' => $from && $to ? sprintf( '%s...%s', $from.' 00:00:00', $to.' 23:59:59' ) : '',
			'return' => 'objects',
		];
		$key = 'pm_report_' . md5( maybe_serialize( $args ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$orders = wc_get_orders( $args );
		set_transient( $key, $orders, MINUTE_IN_SECONDS * 10 );
		return $orders;
	}

	public static function render_page() {
		$base = Currency_Manager::base_currency();
		$from = isset( $_GET['pm_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pm_from'] ) ) : ''; // phpcs:ignore
		$to   = isset( $_GET['pm_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pm_to'] ) ) : ''; // phpcs:ignore
		$orders = self::query_orders( $from, $to );
		$export = isset( $_GET['pm_export_csv'] );
		$bucket = [];
		$gateway_bucket = [];
		// Métricas de autopay a partir del historial de suscripciones relacionadas (optimización futura: query directa)
		$autopay_stats = [ 'active'=>0, 'success'=>0, 'fail'=>0, 'abandon'=>0 ];
		$subs = get_posts( [ 'post_type'=>'pm_subscription', 'post_status'=>'publish', 'numberposts'=>200, 'fields'=>'ids' ] );
		foreach ( $subs as $sid ) {
			if ( get_post_meta( $sid, '_plugin_monedas_subscription_autopay_enabled', true ) ) $autopay_stats['active']++;
			$hist_raw = get_post_meta( $sid, '_plugin_monedas_subscription_rate_history', true );
			if ( $hist_raw ) {
				$data = json_decode( $hist_raw, true );
				if ( is_array( $data ) ) {
					foreach ( $data as $ev ) {
						if ( ! empty( $ev['action'] ) ) {
							if ( 'charge_success' === $ev['action'] ) $autopay_stats['success']++;
							if ( 'charge_fail' === $ev['action'] ) $autopay_stats['fail']++;
							if ( 'charge_abandon' === $ev['action'] ) $autopay_stats['abandon']++;
						}
					}
				}
			}
		}
		foreach ( $orders as $order ) {
			/** @var \WC_Order $order */
			$cur  = $order->get_meta( '_plugin_monedas_selected_currency' );
			$rate = (float) $order->get_meta( '_plugin_monedas_rate' );
			if ( ! $cur ) $cur = $order->get_currency();
			$total = (float) $order->get_total();
			$gateway = $order->get_payment_method();
			if ( ! isset( $bucket[ $cur ] ) ) {
				$bucket[ $cur ] = [ 'count' => 0, 'sum' => 0.0, 'sum_base' => 0.0 ];
			}
			if ( ! isset( $gateway_bucket[ $gateway ] ) ) {
				$gateway_bucket[ $gateway ] = [];
			}
			if ( ! isset( $gateway_bucket[ $gateway ][ $cur ] ) ) {
				$gateway_bucket[ $gateway ][ $cur ] = [ 'count'=>0, 'sum'=>0.0, 'sum_base'=>0.0 ];
			}
			$bucket[ $cur ]['count']++;
			$bucket[ $cur ]['sum'] += $total;
			$gateway_bucket[ $gateway ][ $cur ]['count']++;
			$gateway_bucket[ $gateway ][ $cur ]['sum'] += $total;
			// Si la moneda del pedido es distinta a base y tenemos rate (tasa = selected respecto base), para convertir a base dividimos.
			if ( $cur === $base ) {
				$bucket[ $cur ]['sum_base'] += $total;
				$gateway_bucket[ $gateway ][ $cur ]['sum_base'] += $total;
			} else {
				if ( $rate > 0 ) {
					$conv = $total / $rate;
					$bucket[ $cur ]['sum_base'] += $conv;
					$gateway_bucket[ $gateway ][ $cur ]['sum_base'] += $conv;
				} else {
					$bucket[ $cur ]['sum_base'] += $total; // fallback
					$gateway_bucket[ $gateway ][ $cur ]['sum_base'] += $total;
				}
			}
		}

		if ( $export ) {
			$filename = 'reporte-multimoneda-' . date('Ymd-His') . '.csv';
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename='.$filename );
			$fh = fopen('php://output','w');
			fputcsv( $fh, [ 'Scope','Gateway','Currency','Orders','TotalCurrency','TotalBase' ] );
			foreach ( $bucket as $ccur=>$data ) {
				fputcsv( $fh, [ 'GLOBAL','ALL',$ccur,$data['count'],$data['sum'],$data['sum_base'] ] );
			}
			foreach ( $gateway_bucket as $gw=>$curset ) {
				foreach ( $curset as $ccur=>$data ) {
					fputcsv( $fh, [ 'GATEWAY',$gw,$ccur,$data['count'],$data['sum'],$data['sum_base'] ] );
				}
			}
			fclose( $fh );
			exit;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Reporte Multi-Moneda', 'plugin-monedas' ) . '</h1>';
		echo '<form method="get" style="margin-bottom:15px;">';
		echo '<input type="hidden" name="page" value="pm-multicurrency-report" />';
		echo '<label>' . esc_html__( 'Desde', 'plugin-monedas' ) . ' <input type="date" name="pm_from" value="' . esc_attr( $from ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Hasta', 'plugin-monedas' ) . ' <input type="date" name="pm_to" value="' . esc_attr( $to ) . '" /></label> ';
		echo '<button class="button button-primary">' . esc_html__( 'Filtrar', 'plugin-monedas' ) . '</button> ';
		echo '<a href="'.esc_url( add_query_arg( array_merge( $_GET, [ 'pm_export_csv'=>1 ] ) ) ).'" class="button">'.esc_html__( 'Exportar CSV', 'plugin-monedas' ).'</a>';
		echo '</form>';
		// Bloque métricas autopay
		if ( isset( $autopay_stats ) ) {
			echo '<div class="card" style="padding:10px;margin-bottom:15px;max-width:680px;">';
			echo '<h2 style="margin-top:0;font-size:16px;">'.esc_html__( 'Autopago - Resumen', 'plugin-monedas' ).'</h2>';
			echo '<ul style="margin:0;list-style:disc;padding-left:18px;">';
			echo '<li>'.esc_html__( 'Suscripciones con autopago activo', 'plugin-monedas' ).': <strong>'.esc_html( $autopay_stats['active'] ).'</strong></li>';
			echo '<li>'.esc_html__( 'Cargos exitosos (histórico)', 'plugin-monedas' ).': <strong>'.esc_html( $autopay_stats['success'] ).'</strong></li>';
			echo '<li>'.esc_html__( 'Intentos fallidos (histórico)', 'plugin-monedas' ).': <strong>'.esc_html( $autopay_stats['fail'] ).'</strong></li>';
			echo '<li>'.esc_html__( 'Abandonos (sin más retries)', 'plugin-monedas' ).': <strong>'.esc_html( $autopay_stats['abandon'] ).'</strong></li>';
			echo '</ul>';
			echo '</div>';
		}
		if ( empty( $bucket ) ) {
			echo '<p>' . esc_html__( 'No hay pedidos en el rango.', 'plugin-monedas' ) . '</p>';
			echo '</div>';
			return;
		}
		echo '<h2 style="margin-top:25px;">'.esc_html__( 'Totales por Moneda', 'plugin-monedas' ).'</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Moneda', 'plugin-monedas' ) . '</th><th>' . esc_html__( 'Pedidos', 'plugin-monedas' ) . '</th><th>' . esc_html__( 'Total Moneda', 'plugin-monedas' ) . '</th><th>' . esc_html__( 'Equivalente ' . $base, 'plugin-monedas' ) . '</th></tr></thead><tbody>';
		$total_base_global = 0.0;
		foreach ( $bucket as $ccur => $data ) {
			$total_base_global += $data['sum_base'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $ccur ) . '</strong></td>';
			echo '<td>' . esc_html( $data['count'] ) . '</td>';
			echo '<td>' . esc_html( wc_price( $data['sum'], [ 'currency' => $ccur ] ) ) . '</td>';
			echo '<td>' . esc_html( wc_price( $data['sum_base'], [ 'currency' => $base ] ) ) . '</td>';
			echo '</tr>';
		}
		echo '<tfoot><tr><th colspan="3" style="text-align:right">' . esc_html__( 'Total Base', 'plugin-monedas' ) . '</th><th>' . esc_html( wc_price( $total_base_global, [ 'currency' => $base ] ) ) . '</th></tr></tfoot>';
		echo '</tbody></table>';
		// Tabla por gateway
		echo '<h2 style="margin-top:35px;">'.esc_html__( 'Totales por Pasarela y Moneda', 'plugin-monedas' ).'</h2>';
		echo '<table class="widefat striped"><thead><tr><th>'.esc_html__( 'Pasarela', 'plugin-monedas' ).'</th><th>'.esc_html__( 'Moneda', 'plugin-monedas' ).'</th><th>'.esc_html__( 'Pedidos', 'plugin-monedas' ).'</th><th>'.esc_html__( 'Total Moneda', 'plugin-monedas' ).'</th><th>'.esc_html__( 'Equivalente '.$base, 'plugin-monedas' ).'</th></tr></thead><tbody>';
		foreach ( $gateway_bucket as $gw=>$curset ) {
			foreach ( $curset as $ccur=>$data ) {
				echo '<tr>';
				echo '<td>'.esc_html( $gw ).'</td>';
				echo '<td>'.esc_html( $ccur ).'</td>';
				echo '<td>'.esc_html( $data['count'] ).'</td>';
				echo '<td>'.esc_html( wc_price( $data['sum'], [ 'currency'=>$ccur ] ) ).'</td>';
				echo '<td>'.esc_html( wc_price( $data['sum_base'], [ 'currency'=>$base ] ) ).'</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}
