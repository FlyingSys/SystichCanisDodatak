<?php
/**
 * Read-only routes for maintenance through Systich Most.
 *
 * The routes exist only while Systich Most is installed and open, and every request goes through
 * Most's own permission callback (HTTPS, allowed IP, Ed25519 signature, fresh time, unused nonce).
 * They only read Canis data: settings, anchor prices, price history and the audit log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCD_Most {

	const NS = 'systich-canis/v1';

	/** Settings keys whose values are never returned. */
	const SECRET = '/key|token|secret|pass|salt/i';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		if ( ! function_exists( 'systich_bridge_authorize' ) || ! function_exists( 'systich_bridge_is_open' ) || ! systich_bridge_is_open() ) {
			return;
		}
		$routes = array(
			'stanje'   => 'status',
			'sidrene'  => 'snapshots',
			'povijest' => 'history',
			'zapisnik' => 'audit',
		);
		foreach ( $routes as $path => $method ) {
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, $method ),
						'permission_callback' => 'systich_bridge_authorize',
					),
					'show_in_index' => false,
				)
			);
		}
	}

	/** Comma separated ids from the request, at most 5000. */
	private static function ids( WP_REST_Request $req ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'ids' ) ) ) );
		return array_slice( array_values( array_unique( $ids ) ), 0, 5000 );
	}

	private static function page( WP_REST_Request $req, $max ) {
		return array( absint( $req->get_param( 'offset' ) ), max( 1, min( $max, absint( $req->get_param( 'limit' ) ) ?: $max ) ) );
	}

	/** Versions, Canis settings without secrets, and anchor price counts per date, status and source. */
	public static function status() {
		global $wpdb;
		$settings = class_exists( 'CWPC_Plugin' ) ? CWPC_Plugin::get_settings() : get_option( 'cwpc_settings', array() );
		foreach ( (array) $settings as $k => $v ) {
			if ( preg_match( self::SECRET, (string) $k ) ) {
				$settings[ $k ] = '' === $v ? '' : '[skriveno]';
			}
		}
		$out = array(
			'dodatak'  => SCD_VERSION,
			'canis'    => defined( 'CWPC_VERSION' ) ? CWPC_VERSION : '',
			'provjera' => scd_canis_status(),
			'sada'     => wp_date( 'c' ),
			'postavke' => $settings,
			'opcije'   => array(
				'cwpc_last_full_scan_gmt' => get_option( 'cwpc_last_full_scan_gmt', '' ),
				'cwpc_installed_at_gmt'   => get_option( 'cwpc_installed_at_gmt', '' ),
				'cwpc_needs_initial_scan' => get_option( 'cwpc_needs_initial_scan', '' ),
				'cwpc_integrity_offset'   => get_option( 'cwpc_integrity_offset', '' ),
			),
			'cjenik'   => array(
				'postavke'   => get_option( 'cwpc_pricelist_settings', array() ),
				'stanje'     => get_option( 'cwpc_pricelist_state', array() ),
				'greska'     => get_option( 'cwpc_pricelist_error', '' ),
				'revizija'   => get_option( 'cwpc_pricelist_revision', '' ),
				'cli'        => wp_date( 'c', (int) get_option( 'cwpc_pricelist_cli_heartbeat', 0 ) ),
				'web'        => wp_date( 'c', (int) get_option( 'cwpc_pricelist_heartbeat', 0 ) ),
				'sustav'     => class_exists( 'SCD_Pricelist' ) && SCD_Pricelist::active(),
				'promjene'   => get_option( 'scd_pricelist_dirty_log', array() ),
				'wp_cron'    => wp_next_scheduled( 'cwpc_pricelist_tick' ) ? wp_date( 'c', wp_next_scheduled( 'cwpc_pricelist_tick' ) ) : '',
			),
		);
		if ( class_exists( 'CWPC_DB' ) ) {
			$t                    = CWPC_DB::snapshots_table();
			$out['sidrene']       = $wpdb->get_results( "SELECT reference_date, status, source, COUNT(*) AS n FROM {$t} GROUP BY reference_date, status, source ORDER BY reference_date, status, source", ARRAY_A );
			$h                    = CWPC_DB::history_table();
			$out['povijest']      = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT object_id) AS proizvoda, MIN(recorded_at_gmt) AS od, MAX(recorded_at_gmt) AS do FROM {$h}", ARRAY_A );
		}
		return $out;
	}

	/** Anchor prices: ?ids=1,2,3 or ?offset=&limit= (at most 5000), optionally &date=YYYY-MM-DD. */
	public static function snapshots( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! class_exists( 'CWPC_DB' ) ) {
			return new WP_Error( 'scd_no_canis', 'Canis nije aktivan.', array( 'status' => 409 ) );
		}
		$t     = CWPC_DB::snapshots_table();
		$where = array( '1=1' );
		$args  = array();
		$date  = (string) $req->get_param( 'date' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$where[] = 'reference_date = %s';
			$args[]  = $date;
		}
		$ids = self::ids( $req );
		if ( $ids ) {
			$where[] = 'object_id IN (' . implode( ',', $ids ) . ')';
		}
		list( $offset, $limit ) = self::page( $req, 5000 );
		$sql    = "SELECT object_id, reference_date, price, currency, status, source, locked, notes, created_at_gmt, updated_at_gmt FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY object_id, reference_date';
		$sql   .= $ids ? '' : sprintf( ' LIMIT %d, %d', $offset, $limit );
		$rows   = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		$total  = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE " . implode( ' AND ', $where ), $args ) : "SELECT COUNT(*) FROM {$t} WHERE " . implode( ' AND ', $where ) );
		return array(
			'ukupno' => $total,
			'redovi' => $rows,
		);
	}

	/** Price history of one product: ?id=, newest first, at most 500 rows. */
	public static function history( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! class_exists( 'CWPC_DB' ) ) {
			return new WP_Error( 'scd_no_canis', 'Canis nije aktivan.', array( 'status' => 409 ) );
		}
		$h = CWPC_DB::history_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, regular_price, sale_price, effective_price, gross_regular_price, gross_sale_price, gross_effective_price, is_on_sale, sale_from_gmt, sale_to_gmt, source, user_id, recorded_at_gmt FROM {$h} WHERE object_id = %d ORDER BY recorded_at_gmt DESC, id DESC LIMIT 500",
				absint( $req->get_param( 'id' ) )
			),
			ARRAY_A
		);
	}

	/** Canis audit log, newest first: ?n= (at most 500), optionally &id=. */
	public static function audit( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! class_exists( 'CWPC_DB' ) ) {
			return new WP_Error( 'scd_no_canis', 'Canis nije aktivan.', array( 'status' => 409 ) );
		}
		$a  = CWPC_DB::audit_table();
		$n  = max( 1, min( 500, absint( $req->get_param( 'n' ) ) ?: 50 ) );
		$id = absint( $req->get_param( 'id' ) );
		$sql = $id
			? $wpdb->prepare( "SELECT * FROM {$a} WHERE object_id = %d ORDER BY id DESC LIMIT %d", $id, $n )
			: $wpdb->prepare( "SELECT * FROM {$a} ORDER BY id DESC LIMIT %d", $n );
		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
