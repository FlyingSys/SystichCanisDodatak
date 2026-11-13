<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'cwpc_integrity_scan' );
wp_clear_scheduled_hook( 'cwpc_pricelist_tick' );

$settings = get_option( 'cwpc_settings', array() );
if ( empty( $settings['remove_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$tables = array(
	$wpdb->prefix . 'cwpc_price_history',
	$wpdb->prefix . 'cwpc_price_snapshots',
	$wpdb->prefix . 'cwpc_audit_log',
);
foreach ( $tables as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
}

delete_option( 'cwpc_settings' );
delete_option( 'cwpc_db_version' );
delete_option( 'cwpc_installed_at_gmt' );
delete_option( 'cwpc_needs_initial_scan' );
delete_option( 'cwpc_integrity_offset' );
delete_option( 'cwpc_last_full_scan_gmt' );
delete_post_meta_by_key( '_cwpc_reference_date_override' );

delete_post_meta_by_key( '_cwpc_lowest_display_override' );
foreach ( array( 'cwpc_pricelist_settings', 'cwpc_pricelist_heartbeat', 'cwpc_pricelist_cli_heartbeat', 'cwpc_pricelist_error', 'cwpc_pricelist_revision' ) as $option ) { delete_option( $option ); }
// Published files, the sequence and state are deliberately retained for 30-day availability
// and safe reinstallation. Without the plugin use the uploads/cwpc-pricelists/ URLs.
