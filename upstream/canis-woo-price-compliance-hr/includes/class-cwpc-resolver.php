<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Resolver {
	public function get_reference_date( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return '';
		}
		$object_id = $product->get_id();
		$override = get_post_meta( $object_id, '_cwpc_reference_date_override', true );
		if ( $this->valid_date( $override ) ) {
			return $override;
		}

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent_override = get_post_meta( $parent_id, '_cwpc_reference_date_override', true );
			if ( $this->valid_date( $parent_override ) ) {
				return $parent_override;
			}
		}

		$settings = CWPC_Plugin::get_settings();
		$rules = isset( $settings['category_rules'] ) && is_array( $settings['category_rules'] ) ? $settings['category_rules'] : array();
		$term_object_id = $parent_id ? $parent_id : $object_id;
		$terms = wp_get_post_terms( $term_object_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) ) {
			$expanded_terms = array_map( 'absint', $terms );
			foreach ( $terms as $term_id ) {
				$expanded_terms = array_merge( $expanded_terms, array_map( 'absint', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			}
			$terms = array_values( array_unique( $expanded_terms ) );
			foreach ( $rules as $rule ) {
				$term_id = isset( $rule['term_id'] ) ? absint( $rule['term_id'] ) : 0;
				$date = isset( $rule['date'] ) ? $rule['date'] : '';
				if ( $term_id && in_array( $term_id, $terms, true ) && $this->valid_date( $date ) ) {
					return $date;
				}
			}
		}

		$global = isset( $settings['reference_date'] ) ? $settings['reference_date'] : '2026-09-10';
		return $this->valid_date( $global ) ? $global : '2026-09-10';
	}

	public function get_or_create_snapshot( $product, $rebuild_fallback = false ) {
		global $wpdb;
		if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variable' ) ) {
			return null;
		}
		$date = $this->get_reference_date( $product );
		if ( ! $date ) {
			return null;
		}
		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . CWPC_DB::snapshots_table() . ' WHERE object_id = %d AND reference_date = %s LIMIT 1',
			$product->get_id(), $date
		), ARRAY_A );

		if ( $existing && ( ! $rebuild_fallback || ! empty( $existing['locked'] ) ) ) {
			return $existing;
		}

		$resolved = $this->resolve_reference_price( $product, $date );
		if ( ! $resolved ) {
			return null;
		}
		$this->upsert_snapshot( $product->get_id(), $date, $resolved['price'], $resolved['status'], $resolved['source'], $resolved['locked'], $resolved['notes'] );
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . CWPC_DB::snapshots_table() . ' WHERE object_id = %d AND reference_date = %s LIMIT 1',
			$product->get_id(), $date
		), ARRAY_A );
	}

	public function resolve_reference_price( $product, $date ) {
		global $wpdb;
		if ( ! $this->valid_date( $date ) ) {
			return null;
		}
		$site_tz = wp_timezone();
		$cutoff_local = new DateTimeImmutable( $date . ' 23:59:59', $site_tz );
		if ( $cutoff_local->getTimestamp() > time() ) {
			return null;
		}
		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', $cutoff_local->getTimestamp() );
		$table = CWPC_DB::history_table();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE object_id = %d AND recorded_at_gmt <= %s AND gross_regular_price >= 0 ORDER BY recorded_at_gmt DESC, id DESC LIMIT 1",
			$product->get_id(), $cutoff_gmt
		), ARRAY_A );
		if ( $row ) {
			return array(
				'price'  => (float) $row['gross_regular_price'],
				'status' => 'verified_history',
				'source' => 'history_at_or_before_date',
				'locked' => 1,
				'notes'  => sprintf( 'History row #%d, %s GMT', $row['id'], $row['recorded_at_gmt'] ),
			);
		}

		$settings = CWPC_Plugin::get_settings();
		$policy = isset( $settings['reference_fallback'] ) ? $settings['reference_fallback'] : 'oldest';
		if ( 'hide' === $policy ) {
			return null;
		}
		if ( 'oldest' === $policy ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_id = %d AND gross_regular_price >= 0 ORDER BY recorded_at_gmt ASC, id ASC LIMIT 1",
				$product->get_id()
			), ARRAY_A );
			if ( $row ) {
				return array(
					'price'  => (float) $row['gross_regular_price'],
					'status' => 'fallback_oldest',
					'source' => 'oldest_available_history',
					'locked' => 0,
					'notes'  => sprintf( 'Fallback: oldest available row #%d, %s GMT', $row['id'], $row['recorded_at_gmt'] ),
				);
			}
		}

		$regular = $product->get_regular_price( 'edit' );
		if ( '' !== $regular && null !== $regular ) {
			$gross = CWPC_Tracker::gross_base( $product, (float) $regular );
			return array(
				'price'  => $gross,
				'status' => 'fallback_current',
				'source' => 'current_regular_price',
				'locked' => 0,
				'notes'  => 'Fallback: current regular price; historical reference was unavailable.',
			);
		}
		return null;
	}

	public function upsert_snapshot( $object_id, $date, $price, $status, $source, $locked = 0, $notes = '' ) {
		if ( ! $this->valid_date( $date ) || ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 ) {
			return false;
		}
		global $wpdb;
		$table = CWPC_DB::snapshots_table();
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d AND reference_date = %s", $object_id, $date ), ARRAY_A );
		$now = current_time( 'mysql', true );
		$data = array(
			'object_id'      => absint( $object_id ),
			'reference_date' => $date,
			'price'          => (float) $price,
			'currency'       => get_woocommerce_currency(),
			'status'         => sanitize_key( $status ),
			'source'         => sanitize_key( $source ),
			'locked'         => $locked ? 1 : 0,
			'notes'          => sanitize_textarea_field( $notes ),
			'updated_at_gmt' => $now,
		);
		if ( $old ) {
			if ( ! empty( $old['locked'] ) && ! in_array( $source, array( 'manual_admin', 'csv_import' ), true ) ) {
				return false;
			}
			$result = $wpdb->update( $table, $data, array( 'id' => $old['id'] ) );
		} else {
			$data['created_at_gmt'] = $now;
			$result = $wpdb->insert( $table, $data );
		}
		if ( false === $result ) { return false; }
		$new = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d AND reference_date = %s", $object_id, $date ), ARRAY_A );
		CWPC_DB::audit( $old ? 'snapshot_updated' : 'snapshot_created', $object_id, $date, $old, $new );
		do_action( 'cwpc_snapshot_changed', $object_id );
		return true;
	}

	public function get_lowest_before_sale( $product ) {
		global $wpdb;
		if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variable' ) || ! $product->is_on_sale() ) {
			return null;
		}
		$sale_start = $this->get_sale_start_gmt( $product );
		if ( ! $sale_start ) {
			return null;
		}
		$days = (int) apply_filters( 'cwpc_lowest_price_days', 30, $product );
		$start_ts = strtotime( $sale_start . ' UTC' );
		$window_start = gmdate( 'Y-m-d H:i:s', $start_ts - ( DAY_IN_SECONDS * max( 1, $days ) ) );
		$table = CWPC_DB::history_table();

		$prices = array();
		$baseline = $wpdb->get_var( $wpdb->prepare(
			"SELECT gross_effective_price FROM {$table} WHERE object_id = %d AND recorded_at_gmt <= %s AND gross_effective_price >= 0 ORDER BY recorded_at_gmt DESC, id DESC LIMIT 1",
			$product->get_id(), $window_start
		) );
		if ( null !== $baseline ) {
			$prices[] = (float) $baseline;
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT gross_effective_price FROM {$table} WHERE object_id = %d AND recorded_at_gmt > %s AND recorded_at_gmt < %s AND gross_effective_price >= 0 ORDER BY recorded_at_gmt ASC",
			$product->get_id(), $window_start, $sale_start
		) );
		foreach ( $rows as $price ) {
			$prices[] = (float) $price;
		}
		if ( empty( $prices ) ) {
			/*
			 * If tracking started only after the product was already put on sale,
			 * there is no historical effective price before sale_start. In partial
			 * mode we can still expose the regular price captured with the first
			 * observed sale state as an explicit partial-history fallback. The
			 * frontend will hide this result unless show_partial_lowest is enabled.
			 */
			$first_sale = $wpdb->get_row( $wpdb->prepare(
				"SELECT gross_regular_price, recorded_at_gmt FROM {$table} WHERE object_id = %d AND is_on_sale = 1 AND recorded_at_gmt >= %s AND gross_regular_price >= 0 ORDER BY recorded_at_gmt ASC, id ASC LIMIT 1",
				$product->get_id(), $sale_start
			), ARRAY_A );
			if ( $first_sale ) {
				return array(
					'price'        => (float) $first_sale['gross_regular_price'],
					'days'         => $days,
					'sale_start'   => $sale_start,
					'window_start' => $window_start,
					'coverage'     => 'partial',
					'source'       => 'first_observed_sale_regular_price',
				);
			}
			return null;
		}
		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(recorded_at_gmt) FROM {$table} WHERE object_id = %d", $product->get_id() ) );
		$coverage = ( $oldest && strtotime( $oldest . ' UTC' ) <= strtotime( $window_start . ' UTC' ) ) ? 'full' : 'partial';
		return array(
			'price'        => min( $prices ),
			'days'         => $days,
			'sale_start'   => $sale_start,
			'window_start' => $window_start,
			'coverage'     => $coverage,
		);
	}

	public function get_sale_start_gmt( $product ) {
		global $wpdb;
		$explicit = $product->get_date_on_sale_from( 'edit' );
		if ( $explicit && $explicit->getTimestamp() <= time() ) {
			return gmdate( 'Y-m-d H:i:s', $explicit->getTimestamp() );
		}
		$table = CWPC_DB::history_table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sale_price, is_on_sale, recorded_at_gmt FROM {$table} WHERE object_id = %d ORDER BY recorded_at_gmt DESC, id DESC LIMIT 1000",
			$product->get_id()
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return null;
		}
		$start = null;
		foreach ( $rows as $row ) {
			if ( 1 !== (int) $row['is_on_sale'] ) {
				break;
			}
			$start = $row['recorded_at_gmt'];
		}
		return $start;
	}


	public function should_show_lowest_for_product( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}
		$object_id = $product->get_id();
		$override = get_post_meta( $object_id, '_cwpc_lowest_display_override', true );
		if ( 'exclude' === $override ) {
			return false;
		}
		if ( 'include' === $override ) {
			return true;
		}
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent_override = get_post_meta( $parent_id, '_cwpc_lowest_display_override', true );
			if ( 'exclude' === $parent_override ) {
				return false;
			}
			if ( 'include' === $parent_override ) {
				return true;
			}
		}
		$settings = CWPC_Plugin::get_settings();
		$rules = isset( $settings['category_rules'] ) && is_array( $settings['category_rules'] ) ? $settings['category_rules'] : array();
		$term_object_id = $parent_id ? $parent_id : $object_id;
		$terms = wp_get_post_terms( $term_object_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return true;
		}
		$expanded_terms = array_map( 'absint', $terms );
		foreach ( $terms as $term_id ) {
			$expanded_terms = array_merge( $expanded_terms, array_map( 'absint', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
		}
		$terms = array_values( array_unique( $expanded_terms ) );
		foreach ( $rules as $rule ) {
			$term_id = isset( $rule['term_id'] ) ? absint( $rule['term_id'] ) : 0;
			if ( $term_id && in_array( $term_id, $terms, true ) ) {
				return empty( $rule['exclude_lowest'] );
			}
		}
		return true;
	}

	public function get_variable_anchor_range( $product ) {
		if ( ! is_a( $product, 'WC_Product_Variable' ) ) {
			return null;
		}
		$values = array();
		$dates = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$snapshot = $this->get_or_create_snapshot( $child );
			if ( $snapshot ) {
				$values[] = (float) $snapshot['price'];
				$dates[] = $snapshot['reference_date'];
			}
		}
		if ( empty( $values ) ) {
			return null;
		}
		return array( 'min' => min( $values ), 'max' => max( $values ), 'dates' => array_values( array_unique( $dates ) ) );
	}

	public function get_variable_lowest_range( $product ) {
		if ( ! is_a( $product, 'WC_Product_Variable' ) ) {
			return null;
		}
		$values = array();
		$coverage = 'full';
		$has_sale = false;
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || ! $child->is_on_sale() || ! $this->should_show_lowest_for_product( $child ) ) {
				continue;
			}
			$has_sale = true;
			$data = $this->get_lowest_before_sale( $child );
			if ( ! $data ) {
				$coverage = 'partial';
				continue;
			}
			if ( 'full' !== $data['coverage'] ) {
				$coverage = 'partial';
			}
			$values[] = (float) $data['price'];
		}
		if ( ! $has_sale || empty( $values ) ) {
			return null;
		}
		return array( 'min' => min( $values ), 'max' => max( $values ), 'coverage' => $coverage );
	}

	public function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
