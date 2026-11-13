<?php
/**
 * Plugin Name: Systich dodatak za Canis
 * Description: Dorade za Canis Woo Price Compliance HR (prikaz u Avadi, cijena po jedinici mjere, XML cjenik, HPOS) bez mijenjanja Canisovih datoteka.
 * Version: 0.1.0
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

define( 'SCD_VERSION', '0.1.0' );
define( 'SCD_FILE', __FILE__ );

/**
 * Najviša verzija Canisa za koju su dodirne točke provjerene.
 * Povećava se tek nakon pregleda razlika nove verzije (vidi README).
 */
define( 'SCD_CANIS_TESTED', '2.1.1' );

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
	if ( ! defined( 'CWPC_VERSION' ) ) {
		return 'nema';
	}
	return version_compare( CWPC_VERSION, SCD_CANIS_TESTED, '<=' ) ? 'provjeren' : 'neprovjeren';
}

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
				'<div class="notice notice-warning"><p>Canis je ažuriran na verziju %1$s, a Systich dodatak je provjeren do verzije %2$s. Dorade prikaza su privremeno isključene dok se dodatak ne uskladi.</p></div>',
				esc_html( CWPC_VERSION ),
				esc_html( SCD_CANIS_TESTED )
			);
		}
	}
);
