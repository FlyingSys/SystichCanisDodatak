<?php
/**
 * Brže praćenje promjena cijena u velikim katalozima.
 *
 * Canis bilježi cijenu kad se proizvod spremi ili kad se promijeni cjenovni meta podatak, a sve ostalo
 * hvata kontrolnim skeniranjem od 300 proizvoda na sat. U katalogu od 20.000 proizvoda jedan prolaz traje
 * gotovo tri dana, pa bi cijena koju uvoz promijeni izravno u bazi u povijesti završila tek nakon toga.
 *
 * Dodatak svakih pet minuta jednim upitom pronalazi proizvode čija se redovna ili akcijska cijena
 * razlikuje od zadnjeg Canisova zapisa (ili koji zapis još nemaju) i za njih poziva Canisovo bilježenje.
 * Zapisi nastaju Canisovom metodom record_product, s izvorom „scd_catchup”, pa Canis ostaje vlasnik povijesti.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Catchup {
	const HOOK     = 'scd_catchup';
	const GROUP    = 'scd';
	const INTERVAL = 300;
	const LIMIT    = 400;
	const SECONDS  = 20;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
	}

	public static function active() {
		return SCD_Settings::enabled( 'catchup', self::product_count() > SCD_Settings::LARGE_CATALOG );
	}

	public static function product_count() {
		$count = get_transient( 'scd_product_count' );
		if ( false === $count ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')" );
			set_transient( 'scd_product_count', $count, DAY_IN_SECONDS );
		}
		return (int) $count;
	}

	public static function ensure_scheduled() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		// Provjera rasporeda je upit u bazu; dovoljna je jednom na sat, a ne pri svakom učitavanju stranice.
		if ( get_transient( 'scd_catchup_checked' ) ) {
			return;
		}
		set_transient( 'scd_catchup_checked', 1, HOUR_IN_SECONDS );
		$scheduled = as_has_scheduled_action( self::HOOK, array(), self::GROUP );
		if ( self::active() && ! $scheduled ) {
			as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), self::GROUP, true );
		} elseif ( ! self::active() && $scheduled ) {
			self::unschedule();
		}
	}

	public static function unschedule() {
		delete_transient( 'scd_catchup_checked' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Proizvodi i varijacije čija se cijena razlikuje od zadnjeg Canisova zapisa.
	 * Prazna cijena u Canisu je -1, pa se tako uspoređuje i ovdje.
	 */
	public static function find_out_of_sync( $limit, $after_id = 0 ) {
		global $wpdb;
		$history = SCD_Canis::history_table();
		if ( ! $history ) {
			return array();
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- imena tablica dolaze iz $wpdb i Canisa.
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_regular_price'
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_sale_price'
			LEFT JOIN {$history} h ON h.id = ( SELECT MAX( h2.id ) FROM {$history} h2 WHERE h2.object_id = p.ID )
			WHERE p.ID > %d
			  AND p.post_type IN ('product','product_variation')
			  AND p.post_status NOT IN ('trash','auto-draft')
			  AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug IN ('variable','grouped')
				WHERE tr.object_id = p.ID
			  )
			  AND (
				h.id IS NULL
				OR ABS( h.regular_price - CAST( COALESCE( NULLIF( r.meta_value, '' ), '-1' ) AS DECIMAL(20,6) ) ) > 0.000001
				OR ABS( h.sale_price - CAST( COALESCE( NULLIF( s.meta_value, '' ), '-1' ) AS DECIMAL(20,6) ) ) > 0.000001
			  )
			ORDER BY p.ID ASC
			LIMIT %d";
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $after_id, $limit ) ) );
		// phpcs:enable
	}

	public static function run() {
		if ( ! function_exists( 'wc_get_product' ) || 'provjeren' !== scd_canis_status() || ! self::active() ) {
			return;
		}
		$tracker = SCD_Canis::tracker();
		if ( ! $tracker ) {
			return;
		}
		$settings = class_exists( 'CWPC_Plugin' ) ? CWPC_Plugin::get_settings() : array();
		if ( empty( $settings['tracking_enabled'] ) ) {
			return;
		}
		// Pokazivač osigurava napredak i kad neki proizvod Canis ne želi zabilježiti (npr. oštećen zapis):
		// svaki prolaz kreće od zadnjeg obrađenog ID-a, a kad dođe do kraja kataloga, kreće ispočetka.
		$prev     = get_option( 'scd_catchup_state', array() );
		$cursor   = isset( $prev['cursor'] ) ? absint( $prev['cursor'] ) : 0;
		$started  = microtime( true );
		$ids      = self::find_out_of_sync( self::LIMIT, $cursor );
		$recorded = 0;
		$checked  = 0;
		$last     = $cursor;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$checked++;
			$last = $id;
			if ( $product && $tracker->record_product( $product, 'scd_catchup' ) ) {
				$recorded++;
			}
			if ( microtime( true ) - $started > self::SECONDS ) {
				break;
			}
		}
		$complete = $checked === count( $ids ) && count( $ids ) < self::LIMIT;
		update_option(
			'scd_catchup_state',
			array(
				'cursor'   => $complete ? 0 : $last,
				'time'     => time(),
				'found'    => count( $ids ),
				'checked'  => $checked,
				'recorded' => $recorded,
				'seconds'  => round( microtime( true ) - $started, 1 ),
			),
			false
		);
	}
}
