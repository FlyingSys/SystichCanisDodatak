<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Tracker {
	private static $pending_meta_ids = array();

	public function __construct() {
		add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_save' ), 20, 2 );
		add_action( 'added_post_meta', array( $this, 'on_price_meta_change' ), 20, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_price_meta_change' ), 20, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_price_meta_change' ), 20, 4 );
		add_action( 'shutdown', array( $this, 'flush_pending_meta' ), 5 );
	}

	public function on_product_save( $product, $data_store ) {
		if ( ! $this->tracking_enabled() || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}
		$this->record_product( $product, 'woocommerce_save' );
	}

	public function on_price_meta_change( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( ! $this->tracking_enabled() ) {
			return;
		}
		$keys = array( '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to', '_tax_class', '_tax_status' );
		if ( in_array( $meta_key, $keys, true ) && in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
			self::$pending_meta_ids[ absint( $object_id ) ] = true;
		}
	}

	public function flush_pending_meta() {
		if ( empty( self::$pending_meta_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$ids = array_keys( self::$pending_meta_ids );
		self::$pending_meta_ids = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$this->record_product( $product, 'price_meta_change' );
			}
		}
	}

	private function tracking_enabled() {
		$settings = CWPC_Plugin::get_settings();
		return ! empty( $settings['tracking_enabled'] );
	}

	public function record_product( $product, $source = 'manual_scan', $force = false ) {
		global $wpdb;
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}
		if ( $product->is_type( 'variable' ) ) {
			return false;
		}
		$object_id = $product->get_id();
		if ( ! $object_id ) {
			return false;
		}

		$regular = $this->normalize_price( $product->get_regular_price( 'edit' ) );
		$sale = $this->normalize_price( $product->get_sale_price( 'edit' ) );
		$is_on_sale = $product->is_on_sale() ? 1 : 0;
		$effective = $is_on_sale && $sale >= 0 ? $sale : $regular;
		if ( $effective < 0 ) {
			$effective = $this->normalize_price( $product->get_price( 'edit' ) );
		}

		$gross_regular = $this->gross( $product, $regular );
		$gross_sale = $this->gross( $product, $sale );
		$gross_effective = $this->gross( $product, $effective );
		$sale_from = $product->get_date_on_sale_from( 'edit' );
		$sale_to = $product->get_date_on_sale_to( 'edit' );

		$data = array(
			'object_id'             => $object_id,
			'parent_id'             => $product->get_parent_id() ? $product->get_parent_id() : 0,
			'sku'                   => (string) $product->get_sku( 'edit' ),
			'product_type'          => (string) $product->get_type(),
			'regular_price'         => $regular,
			'sale_price'            => $sale,
			'effective_price'       => $effective,
			'gross_regular_price'   => $gross_regular,
			'gross_sale_price'      => $gross_sale,
			'gross_effective_price' => $gross_effective,
			'currency'              => get_woocommerce_currency(),
			'tax_class'             => (string) $product->get_tax_class( 'edit' ),
			'is_on_sale'            => $is_on_sale,
			'sale_from_gmt'         => $sale_from ? gmdate( 'Y-m-d H:i:s', $sale_from->getTimestamp() ) : null,
			'sale_to_gmt'           => $sale_to ? gmdate( 'Y-m-d H:i:s', $sale_to->getTimestamp() ) : null,
			'source'                => sanitize_key( $source ),
			'user_id'               => get_current_user_id(),
			'recorded_at_gmt'       => current_time( 'mysql', true ),
		);

		$signature_data = $data;
		unset( $signature_data['source'], $signature_data['user_id'], $signature_data['recorded_at_gmt'], $signature_data['sku'] );
		$data['signature'] = hash( 'sha256', wp_json_encode( $signature_data ) );

		if ( ! $force ) {
			$last_signature = $wpdb->get_var( $wpdb->prepare(
				'SELECT signature FROM ' . CWPC_DB::history_table() . ' WHERE object_id = %d ORDER BY id DESC LIMIT 1',
				$object_id
			) );
			if ( $last_signature && hash_equals( $last_signature, $data['signature'] ) ) {
				return false;
			}
		}

		$inserted = $wpdb->insert(
			CWPC_DB::history_table(),
			$data,
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( $inserted ) { do_action( 'cwpc_history_changed', $object_id ); }
		return (bool) $inserted;
	}

	private function normalize_price( $value ) {
		if ( '' === $value || null === $value ) {
			return -1;
		}
		return (float) wc_format_decimal( $value );
	}

	public static function gross_base( $product, $price ) {
		$price = (float) $price;
		if ( $price < 0 || ! is_finite( $price ) ) { return -1; }
		if ( ! wc_tax_enabled() || 'taxable' !== $product->get_tax_status( 'edit' ) || wc_prices_include_tax() ) { return $price; }
		$rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'edit' ) );
		return $price + array_sum( WC_Tax::calc_tax( $price, $rates, false ) );
	}
	private function gross( $product, $price ) { return self::gross_base( $product, $price ); }
}
