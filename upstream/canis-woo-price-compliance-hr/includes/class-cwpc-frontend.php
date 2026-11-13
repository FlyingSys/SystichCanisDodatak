<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Frontend {
	private $resolver;

	public function __construct( CWPC_Resolver $resolver ) {
		$this->resolver = $resolver;
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single' ), 11 );
		if ( $this->is_oceanwp_theme() ) {
			// OceanWP exposes a dedicated hook immediately after the archive product price.
			// Render there directly so there is no JavaScript repositioning/flicker.
			add_action( 'ocean_after_archive_product_price', array( $this, 'render_oceanwp_loop' ), 20 );
		} else {
			$settings = CWPC_Plugin::get_settings();
			add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_oceanwp_loop' ), max( 1, min( 999, (int) $settings['loop_priority'] ) ) );
		}
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'cwpc-frontend', CWPC_URL . 'assets/frontend.css', array(), CWPC_VERSION );
		$settings = CWPC_Plugin::get_settings();
		$size = max( 0.5, min( 2, (float) $settings['loop_font_size'] ) );
		wp_add_inline_style( 'cwpc-frontend', '.cwpc-price-compliance.cwpc-loop{font-size:' . number_format( $size, 2, '.', '' ) . 'em;}' );
		if ( is_product() ) {
			wp_enqueue_script( 'cwpc-frontend', CWPC_URL . 'assets/frontend.js', array( 'jquery' ), CWPC_VERSION, true );
		}
	}

	public function render_single() {
		$settings = CWPC_Plugin::get_settings();
		if ( empty( $settings['show_single'] ) ) {
			return;
		}
		global $product;
		if ( $product ) {
			echo $this->get_block_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function render_oceanwp_loop() {
		$settings = CWPC_Plugin::get_settings();
		if ( empty( $settings['show_loop'] ) ) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo $this->get_block_html( $product, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function get_block_html( $product, $loop = false, $extra_class = '' ) {
		$settings = CWPC_Plugin::get_settings();
		if ( ! $this->display_started( $settings ) ) {
			return '';
		}
		$anchor_html = '';
		$lowest_html = '';
		if ( ! empty( $settings['show_reference'] ) ) {
			$anchor_html = $this->get_anchor_html( $product );
		}
		if ( ! empty( $settings['show_lowest'] ) ) {
			$lowest_html = $this->get_lowest_html( $product );
		}
		if ( ! $anchor_html && ! $lowest_html ) {
			return '';
		}
		$classes = array( 'cwpc-price-compliance' );
		if ( $loop ) {
			$classes[] = 'cwpc-loop';
		}
		if ( $extra_class ) {
			$classes[] = sanitize_html_class( $extra_class );
		}

		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"><div class="cwpc-lowest-slot">' . $lowest_html . '</div><div class="cwpc-anchor-slot">' . $anchor_html . '</div></div>';
	}

	private function is_oceanwp_theme() {
		$theme = wp_get_theme();
		if ( ! $theme || ! $theme->exists() ) {
			return false;
		}

		$template   = strtolower( (string) $theme->get_template() );
		$stylesheet = strtolower( (string) $theme->get_stylesheet() );

		return 'oceanwp' === $template || 'oceanwp' === $stylesheet;
	}

	public function get_anchor_html( $product ) {
		$settings = CWPC_Plugin::get_settings();
		$template = isset( $settings['reference_label'] ) ? $settings['reference_label'] : 'Dodatna cijena na dan {date}: {price}';
		if ( $product->is_type( 'variable' ) ) {
			$range = $this->resolver->get_variable_anchor_range( $product );
			if ( ! $range ) {
				return '';
			}
			$date = 1 === count( $range['dates'] ) ? $range['dates'][0] : '';
			$price_html = $this->format_range( $range['min'], $range['max'] );
		} else {
			$snapshot = $this->resolver->get_or_create_snapshot( $product );
			if ( ! $snapshot ) {
				return '';
			}
			$date = $snapshot['reference_date'];
			$price_html = wc_price( (float) $snapshot['price'] );
		}
		$date_text = $date ? wp_date( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ) : 'ovisno o varijaciji';
		$text = str_replace( array( '{date}', '{price}' ), array( esc_html( $date_text ), $price_html ), esc_html( $template ) );
		return '<span class="cwpc-reference-price">' . wp_kses_post( $text ) . '</span>';
	}

	public function get_lowest_html( $product ) {
		$settings = CWPC_Plugin::get_settings();
		if ( ! $product->is_on_sale() || ! $this->resolver->should_show_lowest_for_product( $product ) ) {
			return '';
		}
		$template = isset( $settings['lowest_label'] ) ? $settings['lowest_label'] : 'Najniža cijena u 30 dana prije sniženja: {price}';
		$allow_partial = ! empty( $settings['show_partial_lowest'] );
		if ( $product->is_type( 'variable' ) ) {
			$range = $this->resolver->get_variable_lowest_range( $product );
			if ( ! $range || ( 'full' !== $range['coverage'] && ! $allow_partial ) ) {
				return '';
			}
			$price_html = $this->format_range( $range['min'], $range['max'] );
		} else {
			$data = $this->resolver->get_lowest_before_sale( $product );
			if ( ! $data || ( 'full' !== $data['coverage'] && ! $allow_partial ) ) {
				return '';
			}
			$price_html = wc_price( (float) $data['price'] );
		}
		$text = str_replace( '{price}', $price_html, esc_html( $template ) );
		return '<span class="cwpc-lowest-price">' . wp_kses_post( $text ) . '</span>';
	}

	private function format_range( $min, $max ) {
		if ( abs( $min - $max ) < 0.000001 ) {
			return wc_price( $min );
		}
		return wc_price( $min ) . ' – ' . wc_price( $max );
	}

	public function variation_data( $data, $product, $variation ) {
		$data['cwpc_reference_html'] = $this->get_anchor_html( $variation );
		$data['cwpc_lowest_html'] = $this->get_lowest_html( $variation );
		return $data;
	}

	private function display_started( $settings ) {
		$date = isset( $settings['display_start_date'] ) ? $settings['display_start_date'] : '';
		if ( ! $date ) {
			return true;
		}
		return wp_date( 'Y-m-d' ) >= $date;
	}
}
