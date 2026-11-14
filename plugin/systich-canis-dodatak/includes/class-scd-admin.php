<?php
/**
 * Stranica dodatka u administraciji: WooCommerce → Systich dodatak za Canis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Admin {
	const SLUG = 'systich-canis-dodatak';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		// Postavke smije spremiti i voditelj trgovine, ne samo administrator.
		add_filter(
			'option_page_capability_scd_settings_group',
			function () {
				return 'manage_woocommerce';
			}
		);
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_scd_unit_batch', array( __CLASS__, 'start_unit_batch' ) );
		add_action( 'update_option_' . SCD_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . SCD_Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Systich dodatak za Canis', 'Systich dodatak za Canis', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'scd_settings_group',
			SCD_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SCD_Settings', 'sanitize' ),
				'default'           => SCD_Settings::defaults(),
			)
		);
	}

	/** Kad se uključi izračun cijene za jedinicu mjere, odmah se pokreće izračun za cijeli katalog. */
	public static function settings_changed( $old, $new ) {
		$was = is_array( $old ) && ! empty( $old['unit_price'] );
		$now = is_array( $new ) && ! empty( $new['unit_price'] );
		$auto_changed = is_array( $old ) && is_array( $new ) && (
			( empty( $old['unit_autodetect'] ) !== empty( $new['unit_autodetect'] ) )
			|| ( isset( $old['unit_autodetect_cats'], $new['unit_autodetect_cats'] ) && $old['unit_autodetect_cats'] != $new['unit_autodetect_cats'] ) // phpcs:ignore Universal.Operators.StrictComparisons
		);
		if ( $now && ( ! $was || $auto_changed ) ) {
			SCD_Unit_Price::start_batch();
		}
		delete_transient( 'scd_product_count' );
		delete_transient( 'scd_catchup_checked' );
	}

	public static function settings_added( $option, $value ) {
		self::settings_changed( array(), $value );
	}

	public static function start_unit_batch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nedovoljna ovlaštenja.' );
		}
		check_admin_referer( 'scd_unit_batch' );
		SCD_Unit_Price::start_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&scd_batch=1' ) );
		exit;
	}

	private static function select( $key, $value, $labels ) {
		echo '<select name="' . esc_attr( SCD_Settings::OPTION . '[' . $key . ']' ) . '">';
		foreach ( $labels as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function checkbox( $key, $value, $label ) {
		echo '<label><input type="checkbox" name="' . esc_attr( SCD_Settings::OPTION . '[' . $key . ']' ) . '" value="1"' . checked( (int) $value, 1, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s      = SCD_Settings::get();
		$status = scd_canis_status();
		$tri    = array(
			'auto' => 'Automatski',
			'on'   => 'Uključeno',
			'off'  => 'Isključeno',
		);
		echo '<div class="wrap"><h1>Systich dodatak za Canis</h1>';

		if ( isset( $_GET['scd_batch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Izračun cijene za jedinicu mjere pokrenut je u pozadini. Napredak se vidi niže na ovoj stranici.</p></div>';
		}

		echo '<h2>Stanje</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td>Verzija dodatka</td><td>' . esc_html( SCD_VERSION ) . '</td></tr>';
		echo '<tr><td>Canis</td><td>' . esc_html( defined( 'CWPC_VERSION' ) ? CWPC_VERSION : 'nije aktivan' ) . ' (dodatak provjeren do ' . esc_html( SCD_CANIS_TESTED ) . ')</td></tr>';
		echo '<tr><td>Avada</td><td>' . esc_html( defined( 'FUSION_BUILDER_VERSION' ) ? 'Fusion Builder ' . FUSION_BUILDER_VERSION : 'nije aktivna' ) . '</td></tr>';
		echo '<tr><td>Proizvoda i varijacija</td><td>' . esc_html( number_format_i18n( SCD_Catchup::product_count() ) ) . '</td></tr>';
		$c = get_option( 'scd_catchup_state', array() );
		echo '<tr><td>Brže praćenje cijena</td><td>' . ( SCD_Catchup::active() && 'provjeren' === $status ? 'radi' : 'ne radi' );
		if ( ! empty( $c['time'] ) ) {
			printf(
				'; zadnji prolaz %1$s: pronađeno %2$d, provjereno %3$d, zabilježeno %4$d (%5$s s)',
				esc_html( wp_date( 'd.m.Y. H:i', (int) $c['time'] ) ),
				(int) $c['found'],
				(int) $c['checked'],
				(int) $c['recorded'],
				esc_html( (string) $c['seconds'] )
			);
		}
		echo '</td></tr>';
		$b = get_option( 'scd_unit_batch_state', array() );
		if ( ! empty( $b['started'] ) ) {
			echo '<tr><td>Izračun cijene za jedinicu mjere</td><td>';
			printf(
				'pokrenut %1$s, obrađeno %2$d%3$s',
				esc_html( wp_date( 'd.m.Y. H:i', (int) $b['started'] ) ),
				(int) $b['done'],
				! empty( $b['finished'] ) ? ', završen ' . esc_html( wp_date( 'd.m.Y. H:i', (int) $b['finished'] ) ) : ', u tijeku'
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'scd_settings_group' );
		echo '<h2>Prikaz u Avadinim predlošcima</h2>';
		echo '<p>Kad je stranica proizvoda ili popis proizvoda složen Avadinim Layout Builderom, Canis ne prikazuje sidrenu ni najnižu cijenu, jer Avada ne poziva WooCommerceove hookove na koje se Canis veže. Dodatak tada Canisov blok ispisuje ispod Avadina elementa cijene. U automatskom načinu blok se ispisuje samo ako ga Canis već nije ispisao.</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Stranica proizvoda</th><td>';
		self::select( 'avada_single', $s['avada_single'], $tri );
		echo '</td></tr><tr><th>Popisi proizvoda (Post Cards)</th><td>';
		self::select( 'avada_loop', $s['avada_loop'], $tri );
		echo '</td></tr></tbody></table>';

		echo '<h2>Cijena za jedinicu mjere</h2>';
		echo '<p>Proizvodu koji se prodaje u pakiranju po masi ili volumenu upišite neto količinu (polje „Neto količina” uz cijenu, npr. 400 ml ili 2 kg). Dodatak iz nje računa cijenu za kilogram ili litru. Da bi je Canis objavio u cjeniku, u Canisovim postavkama cjenika upišite meta ključ jedinice <code>_scd_jedinica_mjere</code> i meta ključ cijene <code>_scd_cijena_jm</code>.</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Izračun</th><td>';
		self::checkbox( 'unit_price', $s['unit_price'], 'Računaj cijenu za jedinicu mjere' );
		echo '</td></tr><tr><th>Prikaz</th><td>';
		self::checkbox( 'unit_show_single', $s['unit_show_single'], 'na stranici proizvoda' );
		echo '<br>';
		self::checkbox( 'unit_show_loop', $s['unit_show_loop'], 'u popisima proizvoda' );
		echo '</td></tr><tr><th>Neto količina iz naziva</th><td>';
		self::checkbox( 'unit_autodetect', $s['unit_autodetect'], 'Popuni neto količinu iz naziva proizvoda kad nije upisana' );
		echo '<br><label>Samo u kategorijama (ID-ovi, odvojeni zarezom; vrijedi i za podkategorije): <input type="text" class="regular-text" name="' . esc_attr( SCD_Settings::OPTION . '[unit_autodetect_cats]' ) . '" value="' . esc_attr( implode( ', ', (array) $s['unit_autodetect_cats'] ) ) . '"></label>';
		echo '<p class="description">Bez upisanih kategorija prepoznavanje ne radi nigdje. Upišite samo kategorije robe koja se prodaje po masi ili volumenu (kemija, ulja, hrana), jer u nazivima rezervnih dijelova oznake poput „12L” ili „800 g” ne znače neto količinu.</p>';
		echo '<p class="description">Prepoznaju se samo jednoznačni zapisi poput „1 L”, „400 ml” ili „5 kg”. Naziv s dvije različite količine ili s množenjem („2x500 ml”) preskače se i treba ga upisati ručno. Prepoznata količina sprema se u polje proizvoda, gdje je možete ispraviti.</p>';
		echo '</td></tr></tbody></table>';

		echo '<h2>Brže praćenje cijena</h2>';
		echo '<p>Canis sam provjerava 300 proizvoda na sat. Dodatak svakih pet minuta pronalazi proizvode čija se cijena razlikuje od zadnjeg Canisova zapisa i predaje ih Canisu na bilježenje, pa promjena koju napravi uvoz izravno u bazi ulazi u povijest za nekoliko minuta. U automatskom načinu radi kad katalog ima više od ' . esc_html( number_format_i18n( SCD_Settings::LARGE_CATALOG ) ) . ' proizvoda i varijacija.</p>';
		echo '<table class="form-table"><tbody><tr><th>Brže praćenje</th><td>';
		self::select( 'catchup', $s['catchup'], $tri );
		echo '</td></tr></tbody></table>';

		submit_button( 'Spremi postavke' );
		echo '</form>';

		if ( ! empty( $s['unit_price'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="scd_unit_batch">';
			wp_nonce_field( 'scd_unit_batch' );
			submit_button( 'Ponovno izračunaj cijenu za jedinicu mjere za cijeli katalog', 'secondary' );
			echo '</form>';
		}
		echo '</div>';
	}
}
