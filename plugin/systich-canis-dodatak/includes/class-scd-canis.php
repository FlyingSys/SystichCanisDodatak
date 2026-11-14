<?php
/**
 * Sve dodirne točke s Canisom na jednom mjestu. Ako nova verzija Canisa nešto od ovoga promijeni,
 * metode vraćaju null, a dorade koje o tome ovise tiho se isključe umjesto da sruše stranicu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Canis {
	private static $frontend = null;

	/**
	 * Canisov objekt CWPC_Frontend. Canis ga ne izlaže, pa ga nalazimo među funkcijama
	 * vezanima na woocommerce_single_product_summary, kamo ga Canis veže u konstruktoru.
	 */
	public static function frontend() {
		if ( null !== self::$frontend ) {
			return self::$frontend ? self::$frontend : null;
		}
		self::$frontend = false;
		global $wp_filter;
		if ( ! class_exists( 'CWPC_Frontend' ) || empty( $wp_filter['woocommerce_single_product_summary'] ) ) {
			return null;
		}
		foreach ( $wp_filter['woocommerce_single_product_summary']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof CWPC_Frontend ) {
					self::$frontend = $cb['function'][0];
					break 2;
				}
			}
		}
		return self::$frontend ? self::$frontend : null;
	}

	/** Ispis Canisova bloka za glavni proizvod stranice (poštuje Canisovu postavku „show_single”). */
	public static function render_single() {
		$fe = self::frontend();
		if ( $fe && method_exists( $fe, 'render_single' ) ) {
			$fe->render_single();
		}
	}

	/** Ispis Canisova bloka u popisu proizvoda (poštuje Canisovu postavku „show_loop”). */
	public static function render_loop() {
		$fe = self::frontend();
		if ( $fe && method_exists( $fe, 'render_oceanwp_loop' ) ) {
			$fe->render_oceanwp_loop();
		}
	}

	public static function tracker() {
		if ( ! class_exists( 'CWPC_Plugin' ) || ! method_exists( 'CWPC_Plugin', 'instance' ) ) {
			return null;
		}
		$plugin = CWPC_Plugin::instance();
		if ( ! method_exists( $plugin, 'get_tracker' ) ) {
			return null;
		}
		$tracker = $plugin->get_tracker();
		return ( $tracker && method_exists( $tracker, 'record_product' ) ) ? $tracker : null;
	}

	public static function history_table() {
		if ( ! class_exists( 'CWPC_DB' ) || ! method_exists( 'CWPC_DB', 'history_table' ) ) {
			return null;
		}
		return CWPC_DB::history_table();
	}

	/**
	 * Bruto cijena kako je računa Canis (s PDV-om ako se cijene unose bez PDV-a),
	 * da cijena za jedinicu mjere bude izračunata iz istog iznosa koji Canis objavljuje u cjeniku.
	 */
	public static function gross( $product, $price ) {
		$price = (float) $price;
		if ( class_exists( 'CWPC_Tracker' ) && method_exists( 'CWPC_Tracker', 'gross_base' ) ) {
			return (float) CWPC_Tracker::gross_base( $product, $price );
		}
		return (float) wc_get_price_including_tax( $product, array( 'price' => $price, 'qty' => 1 ) );
	}
}
