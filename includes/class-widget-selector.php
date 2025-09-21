<?php
namespace Plugin_Monedas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Widget_Selector extends \WP_Widget {
	public static function register() {
		register_widget( __CLASS__ );
	}

	public function __construct() {
		parent::__construct(
			'plugin_monedas_widget_selector',
			__( 'Selector de Moneda (Plugin Monedas)', 'plugin-monedas' ),
			[ 'description' => __( 'Muestra un selector de moneda basado en tasas locales.', 'plugin-monedas' ) ]
		);
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget']; // phpcs:ignore
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title']; // phpcs:ignore
		}
		echo do_shortcode( '[plugin_monedas_selector]' ); // phpcs:ignore
		echo $args['after_widget']; // phpcs:ignore
	}

	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Título:', 'plugin-monedas' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = [];
		$instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );
		return $instance;
	}
}
