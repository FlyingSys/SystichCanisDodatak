<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Cron {
	const HOOK = 'cwpc_integrity_scan';
	const BATCH = 300;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function ensure_scheduled() {
		self::schedule();
	}

	public function run() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		global $wpdb;
		$offset = absint( get_option( 'cwpc_integrity_offset', 0 ) );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('product','product_variation')
			 AND post_status NOT IN ('trash','auto-draft')
			 ORDER BY ID ASC LIMIT %d OFFSET %d",
			self::BATCH,
			$offset
		) );

		$tracker = CWPC_Plugin::instance()->get_tracker();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$tracker->record_product( $product, 'integrity_scan' );
			}
		}

		if ( count( $ids ) < self::BATCH ) {
			update_option( 'cwpc_integrity_offset', 0, false );
			update_option( 'cwpc_last_full_scan_gmt', current_time( 'mysql', true ), false );
		} else {
			update_option( 'cwpc_integrity_offset', $offset + self::BATCH, false );
		}
	}
}
