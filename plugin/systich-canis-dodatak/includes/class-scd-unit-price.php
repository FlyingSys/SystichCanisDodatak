<?php
/**
 * Cijena za jedinicu mjere (€/kg, €/l).
 *
 * Proizvod (ili varijacija) ima neto količinu u polju „Neto količina” (meta _scd_neto_kolicina, npr. „400 ml”).
 * Iz nje i prodajne cijene dodatak računa:
 *   _scd_jedinica_mjere  osnovna jedinica (kg ili l)
 *   _scd_cijena_jm       bruto cijena za tu jedinicu, s dvije decimale
 * U Canisovim postavkama cjenika ta se dva ključa upišu kao „Jedinica mjere — meta ključ” i
 * „Bruto cijena za jedinicu mjere — meta ključ”, pa ih Canis prenosi u cjenik.
 * Dodatak cijenu za jedinicu mjere prikazuje i na stranici proizvoda.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Unit_Price {
	const META_QTY    = '_scd_neto_kolicina';
	const META_SOURCE = '_scd_neto_izvor';
	const META_UNIT   = '_scd_jedinica_mjere';
	const META_PRICE  = '_scd_cijena_jm';
	const BATCH_HOOK  = 'scd_unit_batch';
	const BATCH_SIZE  = 200;

	/** Jedinice koje dodatak prepoznaje: oznaka => array( osnovna jedinica, faktor do osnovne ). */
	private static $units = array(
		'kg'    => array( 'kg', 1 ),
		'g'     => array( 'kg', 0.001 ),
		'gr'    => array( 'kg', 0.001 ),
		'mg'    => array( 'kg', 0.000001 ),
		'l'     => array( 'l', 1 ),
		'lit'   => array( 'l', 1 ),
		'litra' => array( 'l', 1 ),
		'litre' => array( 'l', 1 ),
		'ml'    => array( 'l', 0.001 ),
		'cl'    => array( 'l', 0.01 ),
		'dl'    => array( 'l', 0.1 ),
	);

	private static $saving = false;
	private static $single_done = array();
	/** Zadnji proizvod za koji je ispis u popisu već napravljen; isti proizvod dvaput zaredom je ista kartica. */
	private static $last_loop_id = 0;

	public static function init() {
		// Polja u administraciji postoje uvijek, da se neto količina može upisati i prije uključivanja izračuna.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'field_simple' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'field_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );

		if ( ! SCD_Settings::value( 'unit_price' ) ) {
			return;
		}
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'on_product_save' ), 30 );
		add_action( 'cwpc_history_changed', array( __CLASS__, 'on_history_changed' ) );

		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single_native' ), 12 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_loop_native' ), 12 );
		add_action( 'awb_after_woo_price_content', array( __CLASS__, 'render_avada' ), 11 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 20, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'variation_script' ), 30 );
	}

	/* ---------- Izračun ---------- */

	/**
	 * Neto količina iz teksta („400 ml”, „1,5 L”, „5kg”). Vraća null ako u tekstu nema količine
	 * ili ih ima više različitih, jer se tada ne zna koja vrijedi.
	 *
	 * @return array|null array( 'base' => količina u osnovnoj jedinici, 'unit' => 'kg'|'l', 'label' => „400 ml” )
	 */
	public static function parse( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		// Množenje („2 x 500 ml”, „6×0,5 l”) znači više pakiranja; takav naziv upisuje se ručno.
		if ( preg_match( '/\d\s*[x×*]\s*\d/iu', $text ) ) {
			return null;
		}
		$re   = '/(?<![\d.,\/x×*])(\d+(?:[.,]\d+)?)\s*(kg|mg|gr|g|litra|litre|lit|ml|cl|dl|l)(?![\p{L}\d\/²³])/iu';
		if ( ! preg_match_all( $re, $text, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$found = array();
		foreach ( $m as $match ) {
			$number = $match[1];
			// Točka s tri znamenke iza („1.000 ml”) je razdjelnik tisućica, a zarez je decimalni.
			if ( preg_match( '/^[1-9]\d{0,2}\.\d{3}$/', $number ) ) {
				$number = str_replace( '.', '', $number );
			}
			$amount = (float) str_replace( ',', '.', $number );
			$sign   = strtolower( $match[2] );
			if ( $amount <= 0 || ! isset( self::$units[ $sign ] ) ) {
				continue;
			}
			list( $unit, $factor ) = self::$units[ $sign ];
			$base = round( $amount * $factor, 9 );
			if ( $base <= 0 ) {
				continue;
			}
			$key  = $unit . ':' . $base;
			$found[ $key ] = array(
				'base'  => $base,
				'unit'  => $unit,
				'label' => str_replace( '.', ',', rtrim( rtrim( number_format( $amount, 3, '.', '' ), '0' ), '.' ) ) . ' ' . $sign,
			);
		}
		return 1 === count( $found ) ? reset( $found ) : null;
	}

	/** Neto količina proizvoda: vlastita, a za varijaciju bez vlastite ona roditelja. */
	public static function quantity_text( $product ) {
		$qty = (string) $product->get_meta( self::META_QTY, true, 'edit' );
		if ( '' === $qty && $product->get_parent_id() ) {
			$qty = (string) get_post_meta( $product->get_parent_id(), self::META_QTY, true );
		}
		return trim( $qty );
	}

	/**
	 * Prepoznavanje iz naziva radi samo u odabranim kategorijama, jer u nazivima drugih proizvoda
	 * oznake poput „12L” (serija spojnice) ili „800 g” (masa čekića) nisu neto količina za prodaju.
	 */
	private static function in_autodetect_categories( $product ) {
		$allowed = (array) SCD_Settings::value( 'unit_autodetect_cats' );
		if ( ! $allowed ) {
			return false;
		}
		$post_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$terms   = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term_id ) {
			$chain = array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			if ( array_intersect( $chain, array_map( 'intval', $allowed ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Prodajna bruto cijena kako je objavljuje Canis: akcijska dok traje akcija, inače redovna. */
	private static function selling_gross( $product ) {
		$price = $product->is_on_sale( 'edit' ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
		if ( '' === $price || null === $price ) {
			$price = $product->get_price( 'edit' );
		}
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return null;
		}
		return SCD_Canis::gross( $product, (float) $price );
	}

	/**
	 * Izračuna i spremi jedinicu i cijenu za jedinicu mjere jednog proizvoda ili varijacije.
	 * Varijabilni proizvod nema vlastitu cijenu, pa se računa za svaku njegovu varijaciju.
	 */
	public static function compute( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					self::compute( $child );
				}
			}
			return;
		}
		$id  = $product->get_id();
		$qty = self::quantity_text( $product );

		if ( '' === $qty && SCD_Settings::value( 'unit_autodetect' ) && '' === (string) get_post_meta( $id, self::META_SOURCE, true ) && self::in_autodetect_categories( $product ) ) {
			$guess = self::parse( $product->get_name() );
			if ( $guess ) {
				$qty = $guess['label'];
				update_post_meta( $id, self::META_QTY, $qty );
				update_post_meta( $id, self::META_SOURCE, 'naziv' );
			} else {
				// Zapamti da naziv nema jednoznačnu količinu, da se ne provjerava pri svakom spremanju.
				update_post_meta( $id, self::META_SOURCE, 'nema' );
			}
		}

		$parsed = '' === $qty ? null : self::parse( $qty );
		$gross  = $parsed ? self::selling_gross( $product ) : null;
		if ( ! $parsed || null === $gross || $gross < 0 ) {
			if ( metadata_exists( 'post', $id, self::META_UNIT ) ) {
				delete_post_meta( $id, self::META_UNIT );
			}
			if ( metadata_exists( 'post', $id, self::META_PRICE ) ) {
				delete_post_meta( $id, self::META_PRICE );
			}
			return;
		}
		$unit_price = number_format( $gross / $parsed['base'], 2, '.', '' );
		if ( get_post_meta( $id, self::META_UNIT, true ) !== $parsed['unit'] ) {
			update_post_meta( $id, self::META_UNIT, $parsed['unit'] );
		}
		if ( get_post_meta( $id, self::META_PRICE, true ) !== $unit_price ) {
			update_post_meta( $id, self::META_PRICE, $unit_price );
		}
	}

	public static function on_product_save( $product ) {
		if ( self::$saving ) {
			return;
		}
		self::$saving = true;
		self::compute( $product );
		self::$saving = false;
	}

	/** Canis je zabilježio novu cijenu (i kad je promjena došla mimo spremanja proizvoda). */
	public static function on_history_changed( $object_id ) {
		if ( self::$saving ) {
			return;
		}
		$product = wc_get_product( $object_id );
		if ( $product ) {
			self::$saving = true;
			self::compute( $product );
			self::$saving = false;
		}
	}

	/* ---------- Skupni izračun za cijeli katalog ---------- */

	public static function start_batch() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		as_unschedule_all_actions( self::BATCH_HOOK, array(), 'scd' );
		// Oznaka „u nazivu nema količine” vrijedi za prethodne postavke; novi prolaz provjerava ponovno.
		delete_metadata( 'post', 0, self::META_SOURCE, 'nema', true );
		update_option( 'scd_unit_batch_state', array( 'started' => time(), 'last_id' => 0, 'done' => 0, 'finished' => 0 ), false );
		as_enqueue_async_action( self::BATCH_HOOK, array( 0 ), 'scd' );
		return true;
	}

	public static function run_batch( $after_id = 0 ) {
		global $wpdb;
		$after_id = absint( $after_id );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d",
				$after_id,
				self::BATCH_SIZE
			)
		);
		self::$saving = true;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && ! $product->is_type( 'variable' ) ) {
				self::compute( $product );
			}
		}
		self::$saving = false;
		$state            = get_option( 'scd_unit_batch_state', array() );
		$state['last_id'] = $ids ? (int) end( $ids ) : $after_id;
		$state['done']    = (int) ( isset( $state['done'] ) ? $state['done'] : 0 ) + count( $ids );
		if ( count( $ids ) === self::BATCH_SIZE && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( $state['last_id'] ), 'scd' );
		} else {
			$state['finished'] = time();
		}
		update_option( 'scd_unit_batch_state', $state, false );
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array(), 'scd' );
		}
	}

	/* ---------- Prikaz ---------- */

	public static function html( $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return '';
		}
		$unit  = (string) get_post_meta( $product->get_id(), self::META_UNIT, true );
		$price = (string) get_post_meta( $product->get_id(), self::META_PRICE, true );
		if ( '' === $unit || '' === $price || ! is_numeric( $price ) ) {
			return '';
		}
		return '<span class="scd-unit-price-text">Cijena za 1 ' . esc_html( $unit ) . ': ' . wc_price( (float) $price ) . '</span>';
	}

	/** Ispis ispod cijene; za varijabilni proizvod na stranici proizvoda prazan spremnik koji puni skripta. */
	public static function render_for( $product, $loop ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $loop ) {
			if ( ! SCD_Settings::value( 'unit_show_loop' ) || self::$last_loop_id === $product->get_id() ) {
				return;
			}
			self::$last_loop_id = $product->get_id();
		}
		if ( ! $loop ) {
			if ( ! SCD_Settings::value( 'unit_show_single' ) || isset( self::$single_done[ $product->get_id() ] ) ) {
				return;
			}
			self::$single_done[ $product->get_id() ] = true;
			if ( $product->is_type( 'variable' ) ) {
				echo '<div class="scd-unit-price scd-unit-price-variable"></div>';
				return;
			}
		}
		$html = self::html( $product );
		if ( '' !== $html ) {
			echo '<div class="scd-unit-price' . ( $loop ? ' scd-unit-price-loop' : '' ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function render_single_native() {
		global $product;
		self::render_for( $product, false );
	}

	public static function render_loop_native() {
		global $product;
		self::render_for( $product, true );
	}

	public static function render_avada() {
		global $product;
		if ( $product instanceof WC_Product && ! is_admin() ) {
			self::render_for( $product, ! SCD_Avada::is_main( $product ) );
		}
	}

	public static function variation_data( $data, $product, $variation ) {
		$data['scd_unit_html'] = self::html( $variation );
		return $data;
	}

	public static function variation_script() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! SCD_Settings::value( 'unit_show_single' ) ) {
			return;
		}
		?>
<script>
(function($){
	if (!$) { return; }
	$(function(){
		var $slot = $('.scd-unit-price-variable').first();
		if (!$slot.length) { return; }
		$('.variations_form').on('found_variation', function(e, v){
			$slot.html(v && v.scd_unit_html ? v.scd_unit_html : '');
		}).on('reset_data hide_variation', function(){ $slot.html(''); });
	});
})(window.jQuery);
</script>
		<?php
	}

	/* ---------- Administracija ---------- */

	public static function field_simple() {
		echo '<div class="options_group show_if_simple show_if_variable">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_QTY,
				'label'       => 'Neto količina',
				'placeholder' => 'npr. 400 ml, 1 l, 500 g, 2 kg',
				'desc_tip'    => true,
				'description' => 'Upišite neto količinu ako se proizvod prodaje u pakiranju po masi ili volumenu. Iz nje se računa cijena za kilogram ili litru koja se prikazuje uz cijenu i objavljuje u cjeniku. Prazno znači da se cijena za jedinicu mjere ne računa. Kod varijabilnog proizvoda vrijedi za sve varijacije koje nemaju vlastitu količinu.',
			)
		);
		echo '</div>';
	}

	public static function save_simple( $product ) {
		if ( ! isset( $_POST[ self::META_QTY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce je provjerio nonce obrasca proizvoda.
			return;
		}
		$value = sanitize_text_field( wp_unslash( $_POST[ self::META_QTY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::store_quantity( $product, $value );
	}

	public static function field_variation( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_QTY . '_' . $loop,
				'name'          => self::META_QTY . '[' . $loop . ']',
				'value'         => get_post_meta( $variation->ID, self::META_QTY, true ),
				'label'         => 'Neto količina',
				'placeholder'   => 'prazno: kao na proizvodu',
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => 'Neto količina ove varijacije, npr. 400 ml ili 1 kg. Prazno znači da vrijedi količina upisana na proizvodu.',
			)
		);
	}

	public static function save_variation( $variation, $i ) {
		if ( ! isset( $_POST[ self::META_QTY ] ) || ! is_array( $_POST[ self::META_QTY ] ) || ! isset( $_POST[ self::META_QTY ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$value = sanitize_text_field( wp_unslash( $_POST[ self::META_QTY ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::store_quantity( $variation, $value );
	}

	/** Ručni upis ima prednost pred prepoznavanjem iz naziva; prazan upis briše i oznaku izvora. */
	private static function store_quantity( $product, $value ) {
		$old = (string) $product->get_meta( self::META_QTY, true, 'edit' );
		if ( $old === $value ) {
			return;
		}
		if ( '' === $value ) {
			$product->delete_meta_data( self::META_QTY );
			$product->update_meta_data( self::META_SOURCE, 'rucno' );
		} else {
			$product->update_meta_data( self::META_QTY, $value );
			$product->update_meta_data( self::META_SOURCE, 'rucno' );
		}
	}
}
