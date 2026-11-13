<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Plugin {
	private static $instance = null;
	private $tracker;
	private $resolver;
	private $frontend;
	private $admin;
	private $cron;
	private $pricelist;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		CWPC_DB::maybe_upgrade();
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
			return;
		}
		$this->resolver = new CWPC_Resolver();
		$this->tracker = new CWPC_Tracker();
		$this->frontend = new CWPC_Frontend( $this->resolver );
		$this->admin = new CWPC_Admin( $this->resolver, $this->tracker );
		$this->cron = new CWPC_Cron();
		$this->pricelist = new CWPC_Pricelist( $this->resolver );
	}

	public static function activate() {
		CWPC_DB::install();
		if ( ! get_option( 'cwpc_settings' ) ) {
			add_option( 'cwpc_settings', self::defaults(), '', false );
		}
		if ( ! get_option( 'cwpc_installed_at_gmt' ) ) {
			add_option( 'cwpc_installed_at_gmt', current_time( 'mysql', true ), '', false );
		}
		update_option( 'cwpc_needs_initial_scan', 1, false );
		CWPC_Cron::schedule();
	}

	public static function deactivate() {
		CWPC_Cron::unschedule();
		wp_clear_scheduled_hook( CWPC_Pricelist::HOOK );
	}

	public static function defaults() {
		return array(
			'tracking_enabled'    => 1,
			'show_lowest'         => 1,
			'show_partial_lowest' => 0,
			'lowest_label'        => 'Najniža cijena u 30 dana prije sniženja: {price}',
			'show_reference'      => 1,
			'reference_date'      => '2026-09-10',
			'display_start_date'  => '',
			'reference_label'     => 'Dodatna cijena na dan {date}: {price}',
			'reference_fallback'  => 'current',
			'show_single'         => 1,
			'show_loop'           => 1,
			'loop_font_size'      => 0.7,
			'loop_priority'       => 11,
			'category_rules'      => array(),
			'remove_on_uninstall' => 0,
		);
	}

	public static function get_settings() {
		$saved = get_option( 'cwpc_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public function get_pricelist() {
		return $this->pricelist;
	}

	public function get_tracker() {
		return $this->tracker;
	}

	public function get_resolver() {
		return $this->resolver;
	}

	public function woocommerce_required_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p><strong>Canis Woo Price Compliance HR</strong> zahtijeva aktivan WooCommerce.</p></div>';
		}
	}
}
