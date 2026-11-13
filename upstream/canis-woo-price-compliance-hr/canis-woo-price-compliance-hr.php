<?php
/**
 * Plugin Name: Canis Woo Price Compliance HR
 * Plugin URI: https://canisliberi.hr/
 * Description: Praćenje povijesti cijena, najniže cijene u 30 dana i referentnih/sidrenih cijena za WooCommerce.
 * Version: 2.1.1
 * Author: Canis Liberi
 * Author URI: https://canisliberi.hr/
 * Text Domain: canis-woo-price-compliance-hr
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CWPC_VERSION', '2.1.1' );
define( 'CWPC_FILE', __FILE__ );
define( 'CWPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CWPC_URL', plugin_dir_url( __FILE__ ) );

require_once CWPC_DIR . 'includes/class-cwpc-db.php';
require_once CWPC_DIR . 'includes/class-cwpc-tracker.php';
require_once CWPC_DIR . 'includes/class-cwpc-resolver.php';
require_once CWPC_DIR . 'includes/class-cwpc-frontend.php';
require_once CWPC_DIR . 'includes/class-cwpc-admin.php';
require_once CWPC_DIR . 'includes/class-cwpc-cron.php';
require_once CWPC_DIR . 'includes/class-cwpc-pricelist.php';
require_once CWPC_DIR . 'includes/class-cwpc-plugin.php';

register_activation_hook( __FILE__, array( 'CWPC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CWPC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CWPC_Plugin', 'instance' ), 20 );
