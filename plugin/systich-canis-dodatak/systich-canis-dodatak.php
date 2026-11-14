<?php
/**
 * Plugin Name: Systich dodatak za Canis
 * Description: Dorade za Canis Woo Price Compliance HR (prikaz u Avadinim predlošcima, cijena za jedinicu mjere, brže praćenje cijena i izrada cjenika u velikim katalozima, HPOS) bez mijenjanja Canisovih datoteka.
 * Version: 0.3.1
 * Author: SYSTICH
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, canis-woo-price-compliance-hr
 * Text Domain: systich-canis-dodatak
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCD_VERSION', '0.3.1' );
define( 'SCD_FILE', __FILE__ );
define( 'SCD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Najviša verzija Canisa za koju su dodirne točke provjerene.
 * Povećava se tek nakon pregleda razlika nove verzije (vidi README).
 */
define( 'SCD_CANIS_TESTED', '2.1.1' );

require_once SCD_DIR . 'includes/class-scd-settings.php';
require_once SCD_DIR . 'includes/class-scd-canis.php';
require_once SCD_DIR . 'includes/class-scd-avada.php';
require_once SCD_DIR . 'includes/class-scd-unit-price.php';
require_once SCD_DIR . 'includes/class-scd-catchup.php';
require_once SCD_DIR . 'includes/class-scd-admin.php';
require_once SCD_DIR . 'includes/class-scd-most.php';
require_once SCD_DIR . 'includes/class-scd-pricelist.php';

/**
 * Prijava kompatibilnosti s HPOS-om, za dodatak i za Canis.
 * Canis ne radi s narudžbama, ali kompatibilnost sam ne prijavljuje.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SCD_FILE, true );
		if ( defined( 'CWPC_FILE' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CWPC_FILE, true );
		}
	}
);

/**
 * Stanje Canisa: 'nema', 'provjeren' ili 'neprovjeren'.
 */
function scd_canis_status() {
	if ( ! defined( 'CWPC_VERSION' ) || ! class_exists( 'CWPC_Plugin' ) ) {
		return 'nema';
	}
	return version_compare( CWPC_VERSION, SCD_CANIS_TESTED, '<=' ) ? 'provjeren' : 'neprovjeren';
}

/*
 * Canis se pokreće na plugins_loaded s prioritetom 20, a dodatak nakon njega.
 * Dorade koje zamjenjuju ili dopunjuju Canisov ispis rade samo uz provjerenu verziju.
 * Cijena za jedinicu mjere je vlastiti podatak dodatka, pa radi i uz neprovjerenu verziju.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$status = scd_canis_status();
		SCD_Admin::init();
		SCD_Unit_Price::init();
		// Brže praćenje uvijek ima vezanu funkciju (Action Scheduler inače bilježi neuspjele zadatke),
		// a sama funkcija ne radi ništa dok verzija Canisa nije provjerena.
		SCD_Catchup::init();
		SCD_Most::init();
		if ( 'provjeren' === $status ) {
			SCD_Avada::init();
			SCD_Pricelist::init();
		}
	},
	30
);

register_deactivation_hook( __FILE__, array( 'SCD_Catchup', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'SCD_Unit_Price', 'unschedule' ) );

add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status = scd_canis_status();
		if ( 'nema' === $status ) {
			echo '<div class="notice notice-error"><p>Systich dodatak za Canis ne radi jer plugin Canis Woo Price Compliance HR nije aktivan.</p></div>';
		} elseif ( 'neprovjeren' === $status ) {
			printf(
				'<div class="notice notice-warning"><p>Canis je ažuriran na verziju %1$s, a Systich dodatak je provjeren do verzije %2$s. Prikaz u Avadinim predlošcima i brže praćenje cijena privremeno su isključeni dok se dodatak ne uskladi. Cijena za jedinicu mjere i dalje se računa.</p></div>',
				esc_html( CWPC_VERSION ),
				esc_html( SCD_CANIS_TESTED )
			);
		}
	}
);
