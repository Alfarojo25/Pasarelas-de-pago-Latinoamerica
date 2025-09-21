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
		$bucket = [];
		foreach ( $orders as $order ) {
			/** @var \WC_Order $order */
			$cur  = $order->get_meta( '_plugin_monedas_selected_currency' );
			$rate = (float) $order->get_meta( '_plugin_monedas_rate' );
			if ( ! $cur ) $cur = $order->get_currency();
			$total = (float) $order->get_total();
			if ( ! isset( $bucket[ $cur ] ) ) {
				$bucket[ $cur ] = [ 'count' => 0, 'sum' => 0.0, 'sum_base' => 0.0 ];
			}
			$bucket[ $cur ]['count']++;
			$bucket[ $cur ]['sum'] += $total;
			// Si la moneda del pedido es distinta a base y tenemos rate (tasa = selected respecto base), para convertir a base dividimos.
			if ( $cur === $base ) {
				$bucket[ $cur ]['sum_base'] += $total;
			} else {
				if ( $rate > 0 ) {
					$bucket[ $cur ]['sum_base'] += $total / $rate;
				} else {
					$bucket[ $cur ]['sum_base'] += $total; // fallback
				}
			}
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Reporte Multi-Moneda', 'plugin-monedas' ) . '</h1>';
		echo '<form method="get" style="margin-bottom:15px;">';
		echo '<input type="hidden" name="page" value="pm-multicurrency-report" />';
		echo '<label>' . esc_html__( 'Desde', 'plugin-monedas' ) . ' <input type="date" name="pm_from" value="' . esc_attr( $from ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Hasta', 'plugin-monedas' ) . ' <input type="date" name="pm_to" value="' . esc_attr( $to ) . '" /></label> ';
		echo '<button class="button button-primary">' . esc_html__( 'Filtrar', 'plugin-monedas' ) . '</button>';
		echo '</form>';
		if ( empty( $bucket ) ) {
			echo '<p>' . esc_html__( 'No hay pedidos en el rango.', 'plugin-monedas' ) . '</p>';
			echo '</div>';
			return;
		}
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
		echo '</div>';
	}
}
