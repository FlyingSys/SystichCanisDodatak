<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWPC_Admin {
	private $resolver;
	private $tracker;
	private $page_slug = 'cwpc-price-compliance';

	public function __construct( CWPC_Resolver $resolver, CWPC_Tracker $tracker ) {
		$this->resolver = $resolver;
		$this->tracker = $tracker;

		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_cwpc_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_cwpc_export_snapshots', array( $this, 'export_snapshots' ) );
		add_action( 'admin_post_cwpc_export_history', array( $this, 'export_history' ) );
		add_action( 'admin_post_cwpc_import_snapshots', array( $this, 'import_snapshots' ) );
		add_action( 'admin_post_cwpc_import_history', array( $this, 'import_history' ) );
		add_action( 'wp_ajax_cwpc_scan_batch', array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_cwpc_snapshot_batch', array( $this, 'ajax_snapshot_batch' ) );

		add_action( 'add_meta_boxes_product', array( $this, 'add_product_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_product_metabox' ), 50, 2 );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 50, 2 );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Canis Woo Price Compliance HR',
			'Price Compliance HR',
			'manage_woocommerce',
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin_page = isset( $_GET['page'] ) && $this->page_slug === sanitize_key( wp_unslash( $_GET['page'] ) );
		$is_product = $screen && 'product' === $screen->post_type;
		if ( ! $is_plugin_page && ! $is_product ) {
			return;
		}
		wp_enqueue_style( 'cwpc-admin', CWPC_URL . 'assets/admin.css', array(), CWPC_VERSION );
		if ( $is_plugin_page ) {
			wp_enqueue_script( 'cwpc-admin', CWPC_URL . 'assets/admin.js', array( 'jquery' ), CWPC_VERSION, true );
			wp_localize_script( 'cwpc-admin', 'CWPCAdmin', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cwpc_ajax' ),
			) );
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemate dopuštenje za ovu stranicu.', 'canis-woo-price-compliance-hr' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$allowed = array( 'dashboard', 'settings', 'history', 'snapshots', 'audit', 'import-export', 'digital', 'legal' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			$tab = 'dashboard';
		}
		echo '<div class="wrap cwpc-wrap"><h1>Canis Woo Price Compliance HR</h1>';
		$this->render_notices();
		$this->tabs( $tab );
		call_user_func( array( $this, 'render_' . str_replace( '-', '_', $tab ) ) );
		echo '</div>';
	}

	private function render_digital() {
		CWPC_Plugin::instance()->get_pricelist()->render_admin();
	}

	private function render_legal() {
		echo '<h2>Pravna napomena</h2><p>' . esc_html( CWPC_Pricelist::legal_text() ) . '</p><p>Verzija: ' . esc_html( CWPC_VERSION ) . '</p><p>Odluke NN 101/2026-1212 i NN 101/2026-1213 primjenjuju se od 1.10.2026. Provjerite kategorije s referentnim datumom 2.5.2025. i proizvode s datumom 10.9.2026. Globalni datum ne zamjenjuje ispravnu klasifikaciju proizvoda.</p><p><a href="https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1212.html">Odluka o dodatnoj cijeni</a> · <a href="https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html">Odluka o objavi cjenika</a></p>';
	}

	private function tabs( $active ) {
		$tabs = array(
			'dashboard'     => 'Pregled',
			'settings'      => 'Postavke',
			'history'       => 'Povijest cijena',
			'snapshots'     => 'Referentne cijene',
			'audit'         => 'Audit',
			'import-export' => 'Import / Export',
			'digital' => 'Digitalni cjenik',
			'legal' => 'O pluginu / Pravna napomena',
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $key );
			echo '<a class="nav-tab ' . ( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private function render_notices() {
		if ( isset( $_GET['cwpc_saved'] ) ) {
			echo '<div class="notice notice-success"><p>Postavke su spremljene.</p></div>';
		}
		if ( isset( $_GET['cwpc_imported'] ) ) {
			echo '<div class="notice notice-success"><p>Uvezeno referentnih cijena: <strong>' . esc_html( absint( $_GET['cwpc_imported'] ) ) . '</strong>.</p></div>';
		}
		if ( isset( $_GET['cwpc_import_errors'] ) && absint( $_GET['cwpc_import_errors'] ) ) {
			echo '<div class="notice notice-warning"><p>Preskočeno redaka zbog greške: <strong>' . esc_html( absint( $_GET['cwpc_import_errors'] ) ) . '</strong>.</p></div>';
		}
	}

	private function render_dashboard() {
		global $wpdb;
		$history_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CWPC_DB::history_table() );
		$snapshot_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CWPC_DB::snapshots_table() );
		$verified_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CWPC_DB::snapshots_table() . " WHERE status IN ('verified_history','manual','import')" );
		$fallback_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . CWPC_DB::snapshots_table() . " WHERE status LIKE 'fallback_%'" );
		$last_scan = get_option( 'cwpc_last_full_scan_gmt', '' );
		$installed = get_option( 'cwpc_installed_at_gmt', '' );

		echo '<div class="cwpc-cards">';
		$this->card( 'Zapisa povijesti', number_format_i18n( $history_count ) );
		$this->card( 'Referentnih snapshotova', number_format_i18n( $snapshot_count ) );
		$this->card( 'Potvrđeno / zaključano', number_format_i18n( $verified_count ) );
		$this->card( 'Fallback', number_format_i18n( $fallback_count ) );
		echo '</div>';

		if ( $fallback_count ) {
			echo '<div class="notice notice-warning inline"><p><strong>Potrebna verifikacija referentnih cijena:</strong> ' . esc_html( $fallback_count ) . ' privremenih vrijednosti. Redovna aktualna cijena nije dokaz cijene na referentni datum. Provjerite i potvrdite ručnim unosom ili CSV uvozom.</p></div>';
		}
		CWPC_Plugin::instance()->get_pricelist()->status();
		if ( get_option( 'cwpc_needs_initial_scan' ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>Preporuka:</strong> nakon instalacije pokrenite početni scan kataloga kako bi se spremila početna točka za svaki proizvod i varijaciju.</p></div>';
		}
		if ( $installed && ( strtotime( $installed . ' UTC' ) + 30 * DAY_IN_SECONDS ) > time() ) {
			$coverage_date = wp_date( get_option( 'date_format' ), strtotime( $installed . ' UTC' ) + 30 * DAY_IN_SECONDS );
			echo '<div class="cwpc-warning"><strong>30-dnevna povijest još nije nužno potpuna.</strong> Bez uvoza starijih podataka, puna 30-dnevna pokrivenost proizvoda koji od instalacije nisu mijenjali cijenu očekuje se nakon ' . esc_html( $coverage_date ) . '. Plugin ne generira lažne povijesne zapise.</div>';
		}

		echo '<h2>Alati</h2><p class="cwpc-note">Integrity scan provjerava aktualne WooCommerce cijene i zapisuje samo stvarne promjene. Automatski se izvršava u batchovima jednom na sat, a ovdje ga možete pokrenuti odmah.</p>';
		echo '<p><button class="button button-primary" id="cwpc-scan-now">Skeniraj katalog sada</button> <span id="cwpc-scan-status" class="cwpc-muted"></span></p><div class="cwpc-progress" id="cwpc-scan-progress"><span></span></div>';
		echo '<p><button class="button" id="cwpc-build-snapshots">Izgradi / osvježi fallback snapshotove</button> <span id="cwpc-snapshot-status" class="cwpc-muted"></span></p><div class="cwpc-progress" id="cwpc-snapshot-progress"><span></span></div>';
		if ( $last_scan ) {
			echo '<p class="description">Zadnji puni automatski scan (GMT): ' . esc_html( $last_scan ) . '</p>';
		}
	}

	private function card( $label, $value ) {
		echo '<div class="cwpc-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
	}

	private function render_settings() {
		$settings = CWPC_Plugin::get_settings();
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwpc_save_settings' );
		echo '<input type="hidden" name="action" value="cwpc_save_settings">';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'Praćenje cijena', 'tracking_enabled', $settings, 'Vodi neograničenu povijest regularne, akcijske, efektivne i bruto cijene.' );
		$this->checkbox_row( '30-dnevni minimum', 'show_lowest', $settings, 'Prikazuje se samo kada je proizvod stvarno na akciji. Period je fiksno 30 dana; može se promijeniti samo developer filterom.' );
		$this->checkbox_row( 'Dopusti nepotpunu 30-dnevnu povijest', 'show_partial_lowest', $settings, 'Ako nema pune 30-dnevne povijesti, prikazuje dostupni minimum. Ako je praćenje počelo tek nakon početka akcije, koristi redovnu cijenu iz prvog zabilježenog akcijskog stanja kao partial fallback.' );
		$this->text_row( 'Tekst 30-dnevne cijene', 'lowest_label', $settings['lowest_label'], 'Koristite {price}.' );
		$this->checkbox_row( 'Referentna cijena', 'show_reference', $settings, 'Prikaz referentne/sidrene cijene.' );
		$this->date_row( 'Globalni referentni datum', 'reference_date', $settings['reference_date'], 'Datum cijene. Može se nadjačati po kategoriji ili proizvodu.' );
		$this->date_row( 'Početak prikaza', 'display_start_date', $settings['display_start_date'], 'Opcionalno. Prije ovog datuma referentna i 30-dnevna informacija neće se prikazivati na frontendu.' );
		$this->text_row( 'Tekst referentne cijene', 'reference_label', $settings['reference_label'], 'Koristite {date} i {price}.' );
		echo '<tr><th scope="row"><label for="reference_fallback">Ako nema podatka na datum</label></th><td><select id="reference_fallback" name="reference_fallback">';
		$options = array( 'oldest' => 'Najstariji raspoloživi podatak', 'current' => 'Trenutna regularna cijena', 'hide' => 'Ne prikazuj referentnu cijenu' );
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['reference_fallback'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">Fallback snapshot se označava u administraciji i nije zaključan kao potvrđena povijesna cijena.</p></td></tr>';
		$this->checkbox_row( 'Prikaz na proizvodu', 'show_single', $settings, 'Prikaz odmah ispod glavne cijene proizvoda.' );
		$this->checkbox_row( 'Prikaz u katalogu', 'show_loop', $settings, 'Prikaz ispod cijene na shop/category listama.' );
		echo '<tr><th><label for="loop_font_size">Veličina teksta u katalogu (em)</label></th><td><input id="loop_font_size" type="number" min="0.5" max="2" step="0.05" name="loop_font_size" value="' . esc_attr( $settings['loop_font_size'] ) . '"><p class="description">Zadano 0.7em. Provjerite čitljivost na mobitelu.</p></td></tr>';
		echo '<tr><th><label for="loop_priority">Prioritet WooCommerce hooka</label></th><td><input id="loop_priority" type="number" min="1" max="999" name="loop_priority" value="' . esc_attr( $settings['loop_priority'] ) . '"><p class="description">Zadano 11; vrijedi za druge teme. OceanWP koristi svoj native hook. Bez JS premještanja; CSS klasa: .cwpc-price-compliance.cwpc-loop.</p></td></tr>';
		$this->checkbox_row( 'Brisanje pri deinstalaciji', 'remove_on_uninstall', $settings, 'Ako je uključeno, uninstall briše sve tablice i postavke plugina. Preporuka: ostaviti isključeno radi audita.' );
		echo '</table>';

		echo '<h2>Pravila po kategorijama</h2><p>Prvo odgovarajuće pravilo u tablici ima prednost. Override na proizvodu/varijaciji ima veću prednost od kategorije.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Kategorija</th><th>Referentni datum</th><th>30-dnevni minimum</th><th></th></tr></thead><tbody id="cwpc-rules-body">';
		$rules = isset( $settings['category_rules'] ) && is_array( $settings['category_rules'] ) ? $settings['category_rules'] : array();
		foreach ( $rules as $i => $rule ) {
			$this->rule_row( $i, $rule, $categories );
		}
		echo '</tbody></table><p><button class="button" id="cwpc-add-rule">Dodaj pravilo</button></p>';
		echo '<template id="cwpc-rule-template">';
		$this->rule_row( '__INDEX__', array( 'term_id' => 0, 'date' => '2025-05-02', 'exclude_lowest' => 0 ), $categories );
		echo '</template>';
		submit_button( 'Spremi postavke' );
		echo '</form>';
		echo '<p class="description cwpc-note">' . esc_html( CWPC_Pricelist::legal_text() ) . '</p>';
	}

	private function checkbox_row( $label, $name, $settings, $description ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $settings[ $name ] ), true, false ) . '> Uključeno</label><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	private function text_row( $label, $name, $value, $description ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	private function date_row( $label, $name, $value, $description ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="date" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	private function rule_row( $index, $rule, $categories ) {
		$term_id = isset( $rule['term_id'] ) ? absint( $rule['term_id'] ) : 0;
		$date = isset( $rule['date'] ) ? $rule['date'] : '';
		$exclude_lowest = ! empty( $rule['exclude_lowest'] );
		echo '<tr class="cwpc-rule-row"><td><select name="category_rules[' . esc_attr( $index ) . '][term_id]"><option value="0">— odaberite —</option>';
		foreach ( $categories as $category ) {
			echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $term_id, $category->term_id, false ) . '>' . esc_html( $category->name ) . '</option>';
		}
		echo '</select></td><td><input type="date" name="category_rules[' . esc_attr( $index ) . '][date]" value="' . esc_attr( $date ) . '"></td><td><label><input type="checkbox" name="category_rules[' . esc_attr( $index ) . '][exclude_lowest]" value="1" ' . checked( $exclude_lowest, true, false ) . '> Isključi</label></td><td><button class="button-link-delete cwpc-remove-rule">Ukloni</button></td></tr>';
	}

	public function save_settings() {
		$this->require_admin_post( 'cwpc_save_settings' );
		$old = CWPC_Plugin::get_settings();
		$new = CWPC_Plugin::defaults();
		foreach ( array( 'tracking_enabled', 'show_lowest', 'show_partial_lowest', 'show_reference', 'show_single', 'show_loop', 'remove_on_uninstall' ) as $key ) {
			$new[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}
		$new['lowest_label'] = isset( $_POST['lowest_label'] ) ? sanitize_text_field( wp_unslash( $_POST['lowest_label'] ) ) : $old['lowest_label'];
		$new['reference_label'] = isset( $_POST['reference_label'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_label'] ) ) : $old['reference_label'];
		$new['reference_date'] = $this->sanitize_date( isset( $_POST['reference_date'] ) ? wp_unslash( $_POST['reference_date'] ) : '', '2026-09-10' );
		$new['display_start_date'] = $this->sanitize_date( isset( $_POST['display_start_date'] ) ? wp_unslash( $_POST['display_start_date'] ) : '', '' );
		$fallback = isset( $_POST['reference_fallback'] ) ? sanitize_key( wp_unslash( $_POST['reference_fallback'] ) ) : 'oldest';
		$new['reference_fallback'] = in_array( $fallback, array( 'oldest', 'current', 'hide' ), true ) ? $fallback : 'oldest';
		$new['loop_font_size'] = isset( $_POST['loop_font_size'] ) && is_numeric( $_POST['loop_font_size'] ) ? max( 0.5, min( 2, (float) $_POST['loop_font_size'] ) ) : 0.7;
		$new['loop_priority'] = isset( $_POST['loop_priority'] ) && is_scalar( $_POST['loop_priority'] ) ? max( 1, min( 999, absint( $_POST['loop_priority'] ) ) ) : 11;
		$new['category_rules'] = array();
		if ( isset( $_POST['category_rules'] ) && is_array( $_POST['category_rules'] ) ) {
			foreach ( wp_unslash( $_POST['category_rules'] ) as $rule ) {
				if ( ! is_array( $rule ) ) { continue; }
				$term_id = isset( $rule['term_id'] ) ? absint( $rule['term_id'] ) : 0;
				$date = isset( $rule['date'] ) ? $this->sanitize_date( $rule['date'], '' ) : '';
				$exclude_lowest = ! empty( $rule['exclude_lowest'] ) ? 1 : 0;
				if ( $term_id && ( $date || $exclude_lowest ) && term_exists( $term_id, 'product_cat' ) ) {
					$new['category_rules'][] = array( 'term_id' => $term_id, 'date' => $date, 'exclude_lowest' => $exclude_lowest );
				}
			}
		}
		update_option( 'cwpc_settings', $new, false );
		CWPC_DB::audit( 'settings_updated', 0, null, $old, $new );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=settings&cwpc_saved=1' ) );
		exit;
	}

	private function render_history() {
		global $wpdb;
		$table = CWPC_DB::history_table();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 50;
		$offset = ( $page - 1 ) * $per_page;
		$where = '1=1';
		if ( $search ) {
			$where = $wpdb->prepare( '(object_id = %d OR sku LIKE %s)', absint( $search ), '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY recorded_at_gmt DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( $this->page_slug ) . '"><input type="hidden" name="tab" value="history"><p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="ID ili SKU"><button class="button">Traži</button></p></form>';
		echo '<table class="widefat striped cwpc-table"><thead><tr><th>Vrijeme (GMT)</th><th>Proizvod</th><th>Regularna bruto</th><th>Akcijska bruto</th><th>Efektivna bruto</th><th>Akcija</th><th>Izvor</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['object_id'] );
			$name = $product ? $product->get_formatted_name() : '#' . $row['object_id'];
			echo '<tr><td>' . esc_html( $row['recorded_at_gmt'] ) . '</td><td><strong>' . esc_html( $name ) . '</strong><br><span class="cwpc-muted">SKU: ' . esc_html( $row['sku'] ) . ' / ID ' . esc_html( $row['object_id'] ) . '</span></td><td>' . $this->admin_price( $row['gross_regular_price'] ) . '</td><td>' . $this->admin_price( $row['gross_sale_price'] ) . '</td><td>' . $this->admin_price( $row['gross_effective_price'] ) . '</td><td>' . ( $row['is_on_sale'] ? 'Da' : 'Ne' ) . '</td><td>' . esc_html( $row['source'] ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nema zapisa.</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $page, $total, $per_page, 'history', $search );
	}

	private function render_snapshots() {
		global $wpdb;
		$table = CWPC_DB::snapshots_table();
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 50;
		$offset = ( $page - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at_gmt DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		echo '<p>Zaključani statusi <code>verified_history</code>, <code>manual</code> i <code>import</code> neće se automatski prepisati. Fallback snapshotovi mogu se osvježiti alatom na Pregledu.</p>';
		echo '<table class="widefat striped cwpc-table"><thead><tr><th>Proizvod</th><th>Datum</th><th>Cijena</th><th>Status</th><th>Izvor</th><th>Zaključano</th><th>Napomena</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['object_id'] );
			$name = $product ? $product->get_formatted_name() : '#' . $row['object_id'];
			$edit = get_edit_post_link( $row['object_id'] );
			echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $name ) . '</strong></a>' : esc_html( $name ) ) . '<br><span class="cwpc-muted">ID ' . esc_html( $row['object_id'] ) . '</span></td><td>' . esc_html( $row['reference_date'] ) . '</td><td>' . wp_kses_post( wc_price( (float) $row['price'] ) ) . '</td><td><span class="cwpc-status-' . esc_attr( $row['status'] ) . '">' . esc_html( $row['status'] ) . '</span></td><td>' . esc_html( $row['source'] ) . '</td><td>' . ( $row['locked'] ? 'Da' : 'Ne' ) . '</td><td>' . esc_html( $row['notes'] ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Nema snapshotova.</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $page, $total, $per_page, 'snapshots' );
	}

	private function render_audit() {
		global $wpdb;
		$table = CWPC_DB::audit_table();
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 50;
		$offset = ( $page - 1 ) * $per_page;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at_gmt DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		echo '<table class="widefat striped cwpc-table"><thead><tr><th>Vrijeme (GMT)</th><th>Akcija</th><th>Objekt</th><th>Datum</th><th>Korisnik</th><th>Promjena</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : false;
			$change = $this->audit_change_summary( $row );
			echo '<tr><td>' . esc_html( $row['created_at_gmt'] ) . '</td><td>' . esc_html( $row['action'] ) . '</td><td>' . esc_html( $row['object_id'] ) . '</td><td>' . esc_html( $row['reference_date'] ) . '</td><td>' . esc_html( $user ? $user->user_login : 'system' ) . '</td><td>' . esc_html( $change ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nema audit zapisa.</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $page, $total, $per_page, 'audit' );
	}

	private function audit_change_summary( $row ) {
		$old = $row['old_value'] ? json_decode( $row['old_value'], true ) : array();
		$new = $row['new_value'] ? json_decode( $row['new_value'], true ) : array();
		if ( isset( $old['price'], $new['price'] ) ) {
			return $old['price'] . ' → ' . $new['price'];
		}
		if ( 'settings_updated' === $row['action'] ) {
			return 'Promijenjene postavke';
		}
		return $row['action'];
	}

	private function render_import_export() {
		echo '<h2>Export</h2><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cwpc_export_snapshots' ), 'cwpc_export_snapshots' ) ) . '">Export referentnih cijena (CSV)</a> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cwpc_export_history' ), 'cwpc_export_history' ) ) . '">Export povijesti cijena (CSV)</a></p>';
		echo '<h2>Import referentnih cijena</h2><p>CSV mora imati zaglavlja <code>sku</code> ili <code>object_id</code>, zatim <code>reference_date</code> i <code>price</code>. <code>price</code> je konačna bruto cijena za potrošača. Opcionalno: <code>notes</code>.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwpc_import_snapshots' );
		echo '<input type="hidden" name="action" value="cwpc_import_snapshots"><input type="file" name="cwpc_csv" accept=".csv,text/csv" required> ';
		submit_button( 'Uvezi CSV', 'primary', 'submit', false );
		echo '</form>';
		echo '<div class="cwpc-warning"><strong>Import zaključava snapshot.</strong> CSV import ima status <code>import</code> i prednost pred automatskim fallbackom.</div>';

		echo '<h2>Import povijesnih bruto cijena</h2><p>Ovo je napredni alat za nadopunu povijesti iz ERP-a, backupa ili drugog pouzdanog izvora. CSV mora imati <code>sku</code> ili <code>object_id</code>, <code>recorded_at_gmt</code> (YYYY-MM-DD HH:MM:SS), <code>gross_regular_price</code> i <code>gross_effective_price</code>. Opcionalno: <code>gross_sale_price</code>, <code>is_on_sale</code>.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwpc_import_history' );
		echo '<input type="hidden" name="action" value="cwpc_import_history"><input type="file" name="cwpc_history_csv" accept=".csv,text/csv" required> ';
		submit_button( 'Uvezi povijest CSV', 'secondary', 'submit', false );
		echo '</form><p class="description">Za ispravan 30-dnevni minimum unesite početnu cijenu koja je vrijedila na početku 30-dnevnog prozora i svaku promjenu unutar prozora. Plugin ne interpolira nepoznate promjene.</p>';
	}

	private function pagination( $page, $total, $per_page, $tab, $search = '' ) {
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		$base = admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $tab . '%_%' );
		$args = array( 'base' => $base, 'format' => '&paged=%#%', 'current' => $page, 'total' => $pages, 'type' => 'plain' );
		if ( $search ) {
			$args['add_args'] = array( 's' => $search );
		}
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( $args ) ) . '</div></div>';
	}

	private function admin_price( $value ) {
		return (float) $value < 0 ? '—' : wp_kses_post( wc_price( (float) $value ) );
	}

	public function ajax_scan_batch() {
		$this->require_ajax();
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit = 100;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')" );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$this->tracker->record_product( $product, 'manual_scan' );
			}
		}
		$processed = $offset + count( $ids );
		$done = $processed >= $total || count( $ids ) < $limit;
		if ( $done ) {
			update_option( 'cwpc_needs_initial_scan', 0, false );
			update_option( 'cwpc_last_full_scan_gmt', current_time( 'mysql', true ), false );
		}
		wp_send_json_success( array( 'total' => $total, 'processed_total' => $processed, 'next_offset' => $processed, 'done' => $done, 'message' => $done ? 'Scan završen.' : sprintf( 'Obrađeno %d / %d', $processed, $total ), 'reload' => $done ) );
	}

	public function ajax_snapshot_batch() {
		$this->require_ajax();
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit = 75;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')" );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$this->resolver->get_or_create_snapshot( $product, true );
			}
		}
		$processed = $offset + count( $ids );
		$done = $processed >= $total || count( $ids ) < $limit;
		wp_send_json_success( array( 'total' => $total, 'processed_total' => $processed, 'next_offset' => $processed, 'done' => $done, 'message' => $done ? 'Snapshotovi obrađeni.' : sprintf( 'Obrađeno %d / %d', $processed, $total ), 'reload' => $done ) );
	}

	private function require_ajax() {
		check_ajax_referer( 'cwpc_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Nedovoljna ovlaštenja.' ), 403 );
		}
	}

	private function require_admin_post( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nedovoljna ovlaštenja.' );
		}
		if ( 0 !== strpos( $nonce_action, 'cwpc_export_' ) && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Dopušten je samo POST.', '', array( 'response' => 405 ) ); }
		check_admin_referer( $nonce_action );
	}

	public function add_product_metabox() {
		add_meta_box( 'cwpc-product-compliance', 'Canis Price Compliance HR', array( $this, 'product_metabox' ), 'product', 'side', 'default' );
	}

	public function product_metabox( $post ) {
		wp_nonce_field( 'cwpc_product_meta', 'cwpc_product_meta_nonce' );
		$product = wc_get_product( $post->ID );
		$override = get_post_meta( $post->ID, '_cwpc_reference_date_override', true );
		echo '<p><label for="cwpc_reference_date_override"><strong>Override referentnog datuma</strong></label><br><input style="width:100%" type="date" id="cwpc_reference_date_override" name="cwpc_reference_date_override" value="' . esc_attr( $override ) . '"></p><p class="description">Prazno = pravilo kategorije ili globalni datum.</p>';
		$lowest_override = get_post_meta( $post->ID, '_cwpc_lowest_display_override', true );
		echo '<p><label for="cwpc_lowest_display_override"><strong>30-dnevni minimum</strong></label><br><select style="width:100%" id="cwpc_lowest_display_override" name="cwpc_lowest_display_override"><option value="" ' . selected( $lowest_override, '', false ) . '>Naslijedi pravilo</option><option value="include" ' . selected( $lowest_override, 'include', false ) . '>Prikaži</option><option value="exclude" ' . selected( $lowest_override, 'exclude', false ) . '>Isključi</option></select></p>';
		if ( $product && ! $product->is_type( 'variable' ) ) {
			$date = $this->resolver->get_reference_date( $product );
			$snapshot = $this->resolver->get_or_create_snapshot( $product );
			echo '<hr><p><strong>Aktivni datum:</strong> ' . esc_html( $date ) . '</p>';
			if ( $snapshot ) {
				echo '<p><strong>Snapshot:</strong> ' . wp_kses_post( wc_price( (float) $snapshot['price'] ) ) . '<br><span class="cwpc-status-' . esc_attr( $snapshot['status'] ) . '">' . esc_html( $snapshot['status'] ) . '</span></p>';
			}
			echo '<p><label for="cwpc_manual_reference_price"><strong>Ručna bruto referentna cijena</strong></label><br><input style="width:100%" type="text" inputmode="decimal" id="cwpc_manual_reference_price" name="cwpc_manual_reference_price" value=""></p><p class="description">Ako unesete vrijednost, kreira se zaključani manual snapshot za aktivni datum.</p>';
			echo '<p><label><input type="checkbox" name="cwpc_clear_snapshot" value="1"> Obriši snapshot za aktivni datum</label></p>';
		} else {
			echo '<p class="description">Kod varijabilnog proizvoda cijene se spremaju zasebno po varijaciji. Datum zadan ovdje nasljeđuju varijacije bez vlastitog overridea.</p>';
		}
	}

	public function save_product_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['cwpc_product_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwpc_product_meta_nonce'] ) ), 'cwpc_product_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$date = isset( $_POST['cwpc_reference_date_override'] ) ? $this->sanitize_date( wp_unslash( $_POST['cwpc_reference_date_override'] ), '' ) : '';
		if ( $date ) {
			update_post_meta( $post_id, '_cwpc_reference_date_override', $date );
		} else {
			delete_post_meta( $post_id, '_cwpc_reference_date_override' );
		}
		$lowest_override = isset( $_POST['cwpc_lowest_display_override'] ) ? sanitize_key( wp_unslash( $_POST['cwpc_lowest_display_override'] ) ) : '';
		if ( in_array( $lowest_override, array( 'include', 'exclude' ), true ) ) {
			update_post_meta( $post_id, '_cwpc_lowest_display_override', $lowest_override );
		} else {
			delete_post_meta( $post_id, '_cwpc_lowest_display_override' );
		}
		$product = wc_get_product( $post_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}
		$active_date = $this->resolver->get_reference_date( $product );
		if ( isset( $_POST['cwpc_clear_snapshot'] ) ) {
			$this->delete_snapshot( $post_id, $active_date );
		}
		if ( isset( $_POST['cwpc_manual_reference_price'] ) && '' !== trim( wp_unslash( $_POST['cwpc_manual_reference_price'] ) ) ) {
			$formatted = wc_format_decimal( wp_unslash( $_POST['cwpc_manual_reference_price'] ) );
			if ( '' !== $formatted && is_numeric( $formatted ) ) {
				$price = (float) $formatted;
				if ( is_finite( $price ) && $price >= 0 ) {
					$this->resolver->upsert_snapshot( $post_id, $active_date, $price, 'manual', 'manual_admin', 1, 'Ručna administratorska vrijednost.' );
				}
			}
		}
	}

	public function variation_fields( $loop, $variation_data, $variation ) {
		$id = $variation->ID;
		$override = get_post_meta( $id, '_cwpc_reference_date_override', true );
		echo '<input type="hidden" name="cwpc_variation_meta_nonce" value="' . esc_attr( wp_create_nonce( 'cwpc_variation_meta' ) ) . '">';
		echo '<div class="form-row form-row-full"><strong>Canis Price Compliance HR</strong></div>';
		echo '<p class="form-row form-row-first"><label for="cwpc_variation_reference_date_' . esc_attr( $id ) . '">Referentni datum override</label><input type="date" class="short" id="cwpc_variation_reference_date_' . esc_attr( $id ) . '" name="cwpc_variation_reference_date[' . esc_attr( $id ) . ']" value="' . esc_attr( $override ) . '"></p>';
		echo '<p class="form-row form-row-last"><label for="cwpc_variation_manual_price_' . esc_attr( $id ) . '">Ručna bruto referentna cijena</label><input type="text" inputmode="decimal" class="short wc_input_price" id="cwpc_variation_manual_price_' . esc_attr( $id ) . '" name="cwpc_variation_manual_price[' . esc_attr( $id ) . ']" value=""><span class="description">Prazno = bez promjene; unos zaključava snapshot.</span></p>';
		$lowest_override = get_post_meta( $id, '_cwpc_lowest_display_override', true );
		echo '<p class="form-row form-row-full"><label for="cwpc_variation_lowest_override_' . esc_attr( $id ) . '">30-dnevni minimum</label><select id="cwpc_variation_lowest_override_' . esc_attr( $id ) . '" name="cwpc_variation_lowest_override[' . esc_attr( $id ) . ']"><option value="" ' . selected( $lowest_override, '', false ) . '>Naslijedi</option><option value="include" ' . selected( $lowest_override, 'include', false ) . '>Prikaži</option><option value="exclude" ' . selected( $lowest_override, 'exclude', false ) . '>Isključi</option></select></p>';
	}

	public function save_variation_fields( $variation_id, $loop ) {
		if ( ! isset( $_POST['cwpc_variation_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwpc_variation_meta_nonce'] ) ), 'cwpc_variation_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}
		if ( isset( $_POST['cwpc_variation_reference_date'][ $variation_id ] ) ) {
			$date = $this->sanitize_date( wp_unslash( $_POST['cwpc_variation_reference_date'][ $variation_id ] ), '' );
			if ( $date ) {
				update_post_meta( $variation_id, '_cwpc_reference_date_override', $date );
			} else {
				delete_post_meta( $variation_id, '_cwpc_reference_date_override' );
			}
		}
		if ( isset( $_POST['cwpc_variation_lowest_override'][ $variation_id ] ) ) {
			$lowest_override = sanitize_key( wp_unslash( $_POST['cwpc_variation_lowest_override'][ $variation_id ] ) );
			if ( in_array( $lowest_override, array( 'include', 'exclude' ), true ) ) {
				update_post_meta( $variation_id, '_cwpc_lowest_display_override', $lowest_override );
			} else {
				delete_post_meta( $variation_id, '_cwpc_lowest_display_override' );
			}
		}
		if ( isset( $_POST['cwpc_variation_manual_price'][ $variation_id ] ) && '' !== trim( wp_unslash( $_POST['cwpc_variation_manual_price'][ $variation_id ] ) ) ) {
			$product = wc_get_product( $variation_id );
			$formatted = wc_format_decimal( wp_unslash( $_POST['cwpc_variation_manual_price'][ $variation_id ] ) );
			if ( $product && '' !== $formatted && is_numeric( $formatted ) ) {
				$price = (float) $formatted;
				if ( is_finite( $price ) && $price >= 0 ) {
					$date = $this->resolver->get_reference_date( $product );
					$this->resolver->upsert_snapshot( $variation_id, $date, $price, 'manual', 'manual_admin', 1, 'Ručna administratorska vrijednost za varijaciju.' );
				}
			}
		}
	}

	private function delete_snapshot( $object_id, $date ) {
		global $wpdb;
		$table = CWPC_DB::snapshots_table();
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d AND reference_date = %s", $object_id, $date ), ARRAY_A );
		if ( $old ) {
			$wpdb->delete( $table, array( 'id' => $old['id'] ), array( '%d' ) );
			CWPC_DB::audit( 'snapshot_deleted', $object_id, $date, $old, null );
			do_action( 'cwpc_snapshot_changed', $object_id );
		}
	}

	public function export_snapshots() {
		$this->require_admin_post( 'cwpc_export_snapshots' );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . CWPC_DB::snapshots_table() . ' ORDER BY object_id, reference_date', ARRAY_A );
		$this->csv_headers( 'cwpc-reference-prices-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		$this->write_csv( $out, array( 'object_id', 'sku', 'name', 'reference_date', 'price', 'currency', 'status', 'source', 'locked', 'notes' ) );
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['object_id'] );
			$this->write_csv( $out, array( $row['object_id'], $this->csv_safe_cell( $product ? $product->get_sku() : '' ), $this->csv_safe_cell( $product ? $product->get_name() : '' ), $row['reference_date'], $row['price'], $row['currency'], $row['status'], $row['source'], $row['locked'], $this->csv_safe_cell( $row['notes'] ) ) );
		}
		fclose( $out );
		exit;
	}

	public function export_history() {
		$this->require_admin_post( 'cwpc_export_history' );
		global $wpdb;
		$this->csv_headers( 'cwpc-price-history-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		$this->write_csv( $out, array( 'id', 'object_id', 'parent_id', 'sku', 'product_type', 'regular_price', 'sale_price', 'effective_price', 'gross_regular_price', 'gross_sale_price', 'gross_effective_price', 'currency', 'tax_class', 'is_on_sale', 'sale_from_gmt', 'sale_to_gmt', 'source', 'user_id', 'recorded_at_gmt' ) );
		$last_id = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CWPC_DB::history_table() . ' WHERE id > %d ORDER BY id ASC LIMIT 1000', $last_id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$this->write_csv( $out, array(
					$row['id'], $row['object_id'], $row['parent_id'], $this->csv_safe_cell( $row['sku'] ), $row['product_type'],
					$row['regular_price'], $row['sale_price'], $row['effective_price'], $row['gross_regular_price'], $row['gross_sale_price'], $row['gross_effective_price'],
					$this->csv_safe_cell( $row['currency'] ), $this->csv_safe_cell( $row['tax_class'] ), $row['is_on_sale'], $row['sale_from_gmt'], $row['sale_to_gmt'], $row['source'], $row['user_id'], $row['recorded_at_gmt'],
				) );
			}
		} while ( count( $rows ) === 1000 );
		fclose( $out );
		exit;
	}

	private function write_csv( $out, $row ) {
		return fputcsv( $out, $row, ',', '"', '' );
	}

	private function csv_safe_cell( $value ) {
		return CWPC_Pricelist::csv_cell( $value );
	}

	private function csv_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo "\xEF\xBB\xBF";
	}

	public function import_snapshots() {
		$this->require_admin_post( 'cwpc_import_snapshots' );
		$imported = 0;
		$errors = 0;
		$fh = $this->open_csv_upload( 'cwpc_csv' );
		$header = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! $header ) {
			wp_die( 'CSV nema zaglavlje.' );
		}
		$header = array_map( function( $v ) { return sanitize_key( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v ) ); }, $header );
		if ( count( array_unique( $header ) ) !== count( $header ) || count( $header ) > 50 ) { fclose( $fh ); wp_die( 'CSV sadrži ponovljena ili previše zaglavlja.' ); }
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				$errors++;
				continue;
			}
			$data = array_combine( $header, $row );
			$object_id = ! empty( $data['object_id'] ) ? absint( $data['object_id'] ) : 0;
			if ( ! $object_id && ! empty( $data['sku'] ) ) {
				$object_id = wc_get_product_id_by_sku( sanitize_text_field( $data['sku'] ) );
			}
			$date = isset( $data['reference_date'] ) ? $this->sanitize_date( $data['reference_date'], '' ) : '';
			$price_raw = isset( $data['price'] ) ? str_replace( ',', '.', trim( $data['price'] ) ) : '';
			$product = $object_id ? wc_get_product( $object_id ) : false;
			if ( ! $product || ! $date || ! is_numeric( $price_raw ) || ! is_finite( (float) $price_raw ) || (float) $price_raw < 0 ) {
				$errors++;
				continue;
			}
			$notes = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : 'CSV import';
			if ( $product->is_type( array( 'variable', 'grouped' ) ) ) { $errors++; continue; }
			if ( $this->resolver->upsert_snapshot( $object_id, $date, (float) $price_raw, 'import', 'csv_import', 1, $notes ) ) { $imported++; } else { $errors++; }
		}
		fclose( $fh );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=import-export&cwpc_imported=' . $imported . '&cwpc_import_errors=' . $errors ) );
		exit;
	}


	public function import_history() {
		$this->require_admin_post( 'cwpc_import_history' );
		global $wpdb;
		$imported = 0;
		$errors = 0;
		$fh = $this->open_csv_upload( 'cwpc_history_csv' );
		$header = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! $header ) {
			wp_die( 'CSV nema zaglavlje.' );
		}
		$header = array_map( function( $v ) { return sanitize_key( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v ) ); }, $header );
		if ( count( array_unique( $header ) ) !== count( $header ) || count( $header ) > 50 ) { fclose( $fh ); wp_die( 'CSV sadrži ponovljena ili previše zaglavlja.' ); }
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				$errors++;
				continue;
			}
			$data = array_combine( $header, $row );
			$object_id = ! empty( $data['object_id'] ) ? absint( $data['object_id'] ) : 0;
			if ( ! $object_id && ! empty( $data['sku'] ) ) {
				$object_id = wc_get_product_id_by_sku( sanitize_text_field( $data['sku'] ) );
			}
			$product = $object_id ? wc_get_product( $object_id ) : false;
			$recorded = isset( $data['recorded_at_gmt'] ) ? sanitize_text_field( $data['recorded_at_gmt'] ) : '';
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $recorded, new DateTimeZone( 'UTC' ) );
			$gross_regular = isset( $data['gross_regular_price'] ) ? str_replace( ',', '.', trim( $data['gross_regular_price'] ) ) : '';
			$gross_effective = isset( $data['gross_effective_price'] ) ? str_replace( ',', '.', trim( $data['gross_effective_price'] ) ) : '';
			if ( ! $product || ! $dt || $dt->format( 'Y-m-d H:i:s' ) !== $recorded || $dt->getTimestamp() > time() || ! is_numeric( $gross_regular ) || ! is_numeric( $gross_effective ) || ! is_finite( (float) $gross_regular ) || ! is_finite( (float) $gross_effective ) || (float) $gross_regular < 0 || (float) $gross_effective < 0 ) {
				$errors++;
				continue;
			}
			$gross_sale = -1;
			if ( isset( $data['gross_sale_price'] ) && '' !== trim( $data['gross_sale_price'] ) ) {
				$gross_sale_raw = str_replace( ',', '.', trim( $data['gross_sale_price'] ) );
				if ( is_numeric( $gross_sale_raw ) && is_finite( (float) $gross_sale_raw ) && (float) $gross_sale_raw >= 0 ) {
					$gross_sale = (float) $gross_sale_raw;
				}
			}
			$is_on_sale = ! empty( $data['is_on_sale'] ) && in_array( strtolower( trim( (string) $data['is_on_sale'] ) ), array( '1', 'yes', 'true', 'da' ), true ) ? 1 : 0;
			$row_data = array(
				'object_id'             => $object_id,
				'parent_id'             => $product->get_parent_id() ? $product->get_parent_id() : 0,
				'sku'                   => (string) $product->get_sku( 'edit' ),
				'product_type'          => (string) $product->get_type(),
				'regular_price'         => -1,
				'sale_price'            => -1,
				'effective_price'       => -1,
				'gross_regular_price'   => (float) $gross_regular,
				'gross_sale_price'      => $gross_sale,
				'gross_effective_price' => (float) $gross_effective,
				'currency'              => get_woocommerce_currency(),
				'tax_class'             => (string) $product->get_tax_class( 'edit' ),
				'is_on_sale'            => $is_on_sale,
				'sale_from_gmt'         => null,
				'sale_to_gmt'           => null,
				'source'                => 'csv_history_import',
				'user_id'               => get_current_user_id(),
				'recorded_at_gmt'       => $recorded,
			);
			$sig_data = $row_data;
			unset( $sig_data['source'], $sig_data['user_id'], $sig_data['sku'] );
			$row_data['signature'] = hash( 'sha256', wp_json_encode( $sig_data ) );
			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . CWPC_DB::history_table() . ' WHERE object_id = %d AND recorded_at_gmt = %s AND signature = %s LIMIT 1',
				$object_id, $recorded, $row_data['signature']
			) );
			if ( $exists ) {
				continue;
			}
			$ok = $wpdb->insert(
				CWPC_DB::history_table(),
				$row_data,
				array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( $ok ) {
				do_action( 'cwpc_history_changed', $object_id );
				$imported++;
			} else {
				$errors++;
			}
		}
		fclose( $fh );
		CWPC_DB::audit( 'history_csv_import', 0, null, null, array( 'imported' => $imported, 'errors' => $errors ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=import-export&cwpc_imported=' . $imported . '&cwpc_import_errors=' . $errors ) );
		exit;
	}

	private function open_csv_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
			wp_die( 'CSV datoteka nije primljena.' );
		}
		$file = $_FILES[ $field ];
		foreach ( array( 'error', 'tmp_name', 'size', 'name' ) as $key ) {
			if ( ! isset( $file[ $key ] ) || ! is_scalar( $file[ $key ] ) ) { wp_die( 'Neispravan CSV upload.' ); }
		}
		if ( ! isset( $file['error'], $file['tmp_name'], $file['size'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_die( 'Neispravan CSV upload.' );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > 10 * MB_IN_BYTES ) {
			wp_die( 'CSV mora biti manji od 10 MB.' );
		}
		$filename = sanitize_file_name( wp_unslash( $file['name'] ) );
		if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			wp_die( 'Dopuštene su samo .csv datoteke.' );
		}
		$fh = fopen( $file['tmp_name'], 'r' );
		if ( ! $fh ) {
			wp_die( 'CSV nije moguće otvoriti.' );
		}
		return $fh;
	}

	private function sanitize_date( $date, $fallback = '' ) {
		$date = sanitize_text_field( $date );
		return $this->resolver->valid_date( $date ) ? $date : $fallback;
	}
}
