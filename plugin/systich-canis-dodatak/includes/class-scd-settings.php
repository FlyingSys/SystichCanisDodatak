<?php
/**
 * Postavke dodatka. Canisove postavke ostaju njegove; ovdje su samo postavke dorada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SCD_Settings {
	const OPTION = 'scd_settings';

	/** Katalog veći od ovoga u načinu 'auto' dobiva brže praćenje cijena. */
	const LARGE_CATALOG = 2000;

	public static function defaults() {
		return array(
			// Prikaz Canisovih podataka u Avadinom elementu „Woo Price” (Layout Builder i Post Cards): auto, on, off.
			'avada_single'       => 'auto',
			'avada_loop'         => 'auto',
			// Cijena za jedinicu mjere iz neto količine proizvoda.
			'unit_price'         => 0,
			'unit_show_single'   => 1,
			'unit_show_loop'     => 0,
			'unit_autodetect'    => 0,
			// Kategorije (s podkategorijama) u kojima se neto količina smije prepoznati iz naziva.
			'unit_autodetect_cats' => array(),
			// Brže praćenje promjena cijena: auto (samo veliki katalog), on, off.
			'catchup'            => 'auto',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function value( $key ) {
		$s = self::get();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/** Je li tri-stanjska postavka (auto/on/off) uključena; $auto je odluka za 'auto'. */
	public static function enabled( $key, $auto = true ) {
		$v = self::value( $key );
		if ( 'on' === $v ) {
			return true;
		}
		if ( 'off' === $v ) {
			return false;
		}
		return (bool) $auto;
	}

	public static function sanitize( $input ) {
		$out = self::defaults();
		$input = is_array( $input ) ? $input : array();
		foreach ( array( 'avada_single', 'avada_loop', 'catchup' ) as $k ) {
			if ( isset( $input[ $k ] ) && in_array( $input[ $k ], array( 'auto', 'on', 'off' ), true ) ) {
				$out[ $k ] = $input[ $k ];
			}
		}
		foreach ( array( 'unit_price', 'unit_show_single', 'unit_show_loop', 'unit_autodetect' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}
		$cats = isset( $input['unit_autodetect_cats'] ) ? $input['unit_autodetect_cats'] : array();
		if ( is_string( $cats ) ) {
			$cats = preg_split( '/[^0-9]+/', $cats, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out['unit_autodetect_cats'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $cats ) ) ) );
		return $out;
	}
}
