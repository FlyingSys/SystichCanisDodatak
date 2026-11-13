<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_DB {
	const DB_VERSION = '1.0.0';

	public static function history_table() {
		global $wpdb;
		return $wpdb->prefix . 'cwpc_price_history';
	}

	public static function snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'cwpc_price_snapshots';
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'cwpc_audit_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$history = self::history_table();
		$snapshots = self::snapshots_table();
		$audit = self::audit_table();

		$sql_history = "CREATE TABLE {$history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(191) NOT NULL DEFAULT '',
			product_type varchar(40) NOT NULL DEFAULT '',
			regular_price decimal(20,6) NOT NULL DEFAULT -1,
			sale_price decimal(20,6) NOT NULL DEFAULT -1,
			effective_price decimal(20,6) NOT NULL DEFAULT -1,
			gross_regular_price decimal(20,6) NOT NULL DEFAULT -1,
			gross_sale_price decimal(20,6) NOT NULL DEFAULT -1,
			gross_effective_price decimal(20,6) NOT NULL DEFAULT -1,
			currency varchar(10) NOT NULL DEFAULT '',
			tax_class varchar(100) NOT NULL DEFAULT '',
			is_on_sale tinyint(1) NOT NULL DEFAULT 0,
			sale_from_gmt datetime NULL,
			sale_to_gmt datetime NULL,
			source varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recorded_at_gmt datetime NOT NULL,
			signature char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY object_time (object_id, recorded_at_gmt),
			KEY parent_time (parent_id, recorded_at_gmt),
			KEY sku (sku),
			KEY signature (signature),
			KEY recorded_at (recorded_at_gmt)
		) {$charset_collate};";

		$sql_snapshots = "CREATE TABLE {$snapshots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			reference_date date NOT NULL,
			price decimal(20,6) NOT NULL,
			currency varchar(10) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT '',
			source varchar(64) NOT NULL DEFAULT '',
			locked tinyint(1) NOT NULL DEFAULT 0,
			notes text NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_reference (object_id, reference_date),
			KEY status (status),
			KEY reference_date (reference_date)
		) {$charset_collate};";

		$sql_audit = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(64) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reference_date date NULL,
			old_value longtext NULL,
			new_value longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object_id (object_id),
			KEY action (action),
			KEY created_at (created_at_gmt)
		) {$charset_collate};";

		dbDelta( $sql_history );
		dbDelta( $sql_snapshots );
		dbDelta( $sql_audit );
		update_option( 'cwpc_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'cwpc_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function audit( $action, $object_id = 0, $reference_date = null, $old_value = null, $new_value = null ) {
		global $wpdb;
		$wpdb->insert(
			self::audit_table(),
			array(
				'action'          => sanitize_key( $action ),
				'object_id'       => absint( $object_id ),
				'reference_date'  => $reference_date ? sanitize_text_field( $reference_date ) : null,
				'old_value'       => is_null( $old_value ) ? null : wp_json_encode( $old_value ),
				'new_value'       => is_null( $new_value ) ? null : wp_json_encode( $new_value ),
				'user_id'         => get_current_user_id(),
				'created_at_gmt'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
