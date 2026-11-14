<?php
/**
 * Digital price list (cjenik) in large catalogs.
 *
 * Canis 2.1.1 regenerates the whole price list at the end of every web request that changed a product
 * and gives up after 20 seconds, which a catalog of 20.000+ products never fits into. Every such
 * request costs the visitor 20 seconds, holds the generation lock and records an error. It also
 * marks the catalog as changed on every product meta write (view counters, edit locks, sales
 * counters), so a server-side generation that takes minutes is almost always discarded with
 * "Katalog se promijenio tijekom generiranja".
 *
 * With the scheduler mode "Samo server cron / WP-CLI" this class:
 * - removes Canis's end-of-request generation outside WP-CLI (the server task builds the list);
 * - marks the catalog as changed only for product meta keys and WooCommerce options that appear in the price list;
 * - keeps the last 40 catalog changes (hook, key, request) in the option scd_pricelist_dirty_log for diagnosis.
 * Canis's files are not changed; the hooks are removed and replaced at runtime.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCD_Pricelist {

	/** Product meta keys that feed the price list, besides the ones named in Canis's price list settings. */
	const KEYS = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_sku',
		'_global_unique_id',
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_tax_class',
		'_tax_status',
		'_cwpc_reference_date_override',
	);

	/** WooCommerce options that change prices, taxes or the store identity in the price list. */
	const OPTIONS = '/^woocommerce_(currency|price_|prices_include_tax|calc_taxes|tax_|default_country|store_|manage_stock|hide_out_of_stock)/';

	const LOG = 'scd_pricelist_dirty_log';

	/** @var string Why the next revision change happens (meta key or option name). */
	private static $reason = '';

	/** @var object|null Canis CWPC_Pricelist instance. */
	private static $pricelist = null;

	/** @var array<string,bool> */
	private static $keys = array();

	public static function init() {
		if ( ! class_exists( 'CWPC_Plugin' ) || ! method_exists( 'CWPC_Plugin', 'instance' ) || ! class_exists( 'CWPC_Pricelist' ) ) {
			return;
		}
		$plugin = CWPC_Plugin::instance();
		if ( ! method_exists( $plugin, 'get_pricelist' ) ) {
			return;
		}
		$pricelist = $plugin->get_pricelist();
		$settings  = CWPC_Pricelist::settings();
		if ( ! $pricelist || empty( $settings['enabled'] ) || 'server' !== ( $settings['scheduler_mode'] ?? 'auto' ) ) {
			return;
		}
		self::$pricelist = $pricelist;

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			remove_action( 'shutdown', array( $pricelist, 'shutdown' ), 100 );
		}

		self::$keys = array_fill_keys( self::KEYS, true );
		foreach ( array( 'unit_meta', 'unit_price_meta', 'sale_name_meta', 'barcode_meta' ) as $k ) {
			if ( ! empty( $settings[ $k ] ) ) {
				self::$keys[ (string) $settings[ $k ] ] = true;
			}
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			if ( false !== has_action( $hook, array( $pricelist, 'meta_changed' ) ) ) {
				remove_action( $hook, array( $pricelist, 'meta_changed' ), 100 );
				add_action( $hook, array( __CLASS__, 'meta_changed' ), 100, 4 );
			}
		}
		if ( false !== has_action( 'updated_option', array( $pricelist, 'option_changed' ) ) ) {
			remove_action( 'updated_option', array( $pricelist, 'option_changed' ), 100 );
			add_action( 'updated_option', array( __CLASS__, 'option_changed' ), 100, 3 );
		}
		add_action( 'add_option_cwpc_pricelist_revision', array( __CLASS__, 'note' ) );
		add_action( 'update_option_cwpc_pricelist_revision', array( __CLASS__, 'note' ) );
	}

	/** Canis's option_changed, limited to options that appear in the price list. */
	public static function option_changed( $name, $old, $new ) {
		if ( self::$pricelist && ( 'cwpc_settings' === $name || preg_match( self::OPTIONS, (string) $name ) ) ) {
			self::$reason = 'option ' . $name;
			self::$pricelist->option_changed( $name, $old, $new );
			self::$reason = '';
		}
	}

	/** Records who changed the catalog revision; the last 40 entries are kept. */
	public static function note() {
		static $busy = false;
		if ( $busy ) {
			return;
		}
		$busy    = true;
		$filters = array_values( array_diff( $GLOBALS['wp_current_filter'] ?? array(), array( 'update_option_cwpc_pricelist_revision', 'add_option_cwpc_pricelist_revision', 'update_option', 'add_option' ) ) );
		$log     = get_option( self::LOG, array() );
		$log     = is_array( $log ) ? $log : array();
		$log[]   = array(
			't'      => gmdate( 'Y-m-d H:i:s' ),
			'hook'   => implode( ' > ', $filters ),
			'reason' => self::$reason,
			'where'  => ( defined( 'WP_CLI' ) && WP_CLI ) ? 'cli' : ( wp_doing_cron() ? 'cron' : substr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 0, 160 ) ),
		);
		update_option( self::LOG, array_slice( $log, -40 ), false );
		$busy = false;
	}

	/** Canis's meta_changed, limited to keys that appear in the price list. */
	public static function meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( isset( self::$keys[ (string) $meta_key ] ) && self::$pricelist ) {
			self::$reason = 'meta ' . $meta_key . ' #' . (int) $object_id;
			self::$pricelist->post_changed( $object_id );
			self::$reason = '';
		}
	}

	/** True when the shop builds the price list only from the server task. */
	public static function active() {
		return null !== self::$pricelist;
	}
}
