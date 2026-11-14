<?php
/**
 * Brisanje dodatka uklanja njegove postavke, stanja i zakazane zadatke.
 * Neto količine upisane na proizvode (_scd_neto_kolicina) namjerno ostaju, jer su to podaci o proizvodima;
 * izračunate vrijednosti (_scd_jedinica_mjere, _scd_cijena_jm) brišu se, jer bez dodatka ne bi bile ažurne.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'scd_settings', 'scd_catchup_state', 'scd_unit_batch_state' ) as $option ) {
	delete_option( $option );
}
foreach ( array( 'scd_product_count', 'scd_catchup_checked' ) as $transient ) {
	delete_transient( $transient );
}
foreach ( array( '_scd_jedinica_mjere', '_scd_cijena_jm', '_scd_neto_izvor' ) as $meta_key ) {
	delete_metadata( 'post', 0, $meta_key, '', true );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'scd_catchup', array(), 'scd' );
	as_unschedule_all_actions( 'scd_unit_batch', array(), 'scd' );
}
