<?php
/**
 * Prikaz Canisovih podataka (sidrena cijena, najniža cijena u 30 dana) u Avadinim predlošcima.
 *
 * Canis se veže na woocommerce_single_product_summary i woocommerce_after_shop_loop_item_title.
 * Avadin Layout Builder i Post Cards te hookove ne pozivaju, nego cijenu ispisuju elementom
 * „Woo Price” (fusion_tb_woo_price). Taj element na kraju poziva awb_after_woo_price_content,
 * pa se Canisov blok ispisuje odmah ispod cijene, unutar istog elementa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Avada {
	/** Proizvodi za koje je blok glavnog proizvoda već ispisan na ovoj stranici. */
	private static $single_done = array();
	/** Proizvod za koji je Canis upravo ispisao blok klasičnom petljom (ista kartica, ne ispisuj ponovno). */
	private static $native_loop_id = 0;

	public static function init() {
		if ( ! defined( 'FUSION_BUILDER_VERSION' ) && ! class_exists( 'FusionBuilder' ) ) {
			return;
		}
		// Prioritet 10 je prije Canisova ispisa (11): klasični predložak već prikazuje blok.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'mark_native_single' ), 10 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'mark_native_loop' ), 1 );
		add_action( 'awb_after_woo_price_content', array( __CLASS__, 'after_price' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 20 );
	}

	public static function mark_native_single() {
		global $product;
		if ( $product instanceof WC_Product ) {
			self::$single_done[ $product->get_id() ] = true;
		}
	}

	public static function mark_native_loop() {
		global $product;
		self::$native_loop_id = $product instanceof WC_Product ? $product->get_id() : 0;
	}

	/** Je li Avada upravo iscrtava Post Card (popis proizvoda složen Layout Builderom). */
	public static function in_post_card() {
		if ( ! function_exists( 'FusionBuilder' ) ) {
			return false;
		}
		$fb = FusionBuilder();
		return is_object( $fb ) && isset( $fb->post_card_data['is_rendering'] ) && $fb->post_card_data['is_rendering'];
	}

	public static function after_price() {
		global $product;
		if ( ! $product instanceof WC_Product || is_admin() ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( self::is_main( $product ) ) {
			if ( ! SCD_Settings::enabled( 'avada_single' ) || isset( self::$single_done[ $id ] ) ) {
				return;
			}
			self::$single_done[ $id ] = true;
			SCD_Canis::render_single();
			return;
		}
		if ( self::$native_loop_id === $id ) {
			self::$native_loop_id = 0;
			return;
		}
		if ( SCD_Settings::enabled( 'avada_loop' ) ) {
			SCD_Canis::render_loop();
		}
	}

	/** Je li element cijene ispisan za glavni proizvod stranice, a ne za karticu u popisu. */
	public static function is_main( $product ) {
		return ! self::in_post_card() && is_product() && (int) $product->get_id() === (int) get_queried_object_id();
	}

	/**
	 * Avadin element cijene slaže sadržaj u redak (flex). Blok s dodatnim cijenama
	 * prelazi u novi red preko cijele širine, ispod cijene.
	 */
	public static function styles() {
		$css = '.fusion-woo-price-tb{flex-wrap:wrap}'
			. '.fusion-woo-price-tb .cwpc-price-compliance,.fusion-woo-price-tb .scd-unit-price{flex:0 0 100%;width:100%;order:20;margin-top:.35em}'
			. '.fusion-woo-price-tb .cwpc-price-compliance:not(.cwpc-loop){font-size:.85em;line-height:1.5}'
			. '.fusion-woo-price-tb .cwpc-price-compliance .cwpc-lowest-price,.fusion-woo-price-tb .cwpc-price-compliance .cwpc-reference-price{display:block}';
		if ( wp_style_is( 'cwpc-frontend', 'enqueued' ) ) {
			wp_add_inline_style( 'cwpc-frontend', $css );
		} else {
			wp_register_style( 'scd-avada', false, array(), SCD_VERSION );
			wp_enqueue_style( 'scd-avada' );
			wp_add_inline_style( 'scd-avada', $css );
		}
	}
}
