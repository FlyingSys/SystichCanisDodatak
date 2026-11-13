<?php
/** Public product price lists. No customer/order data is exported. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CWPC_Pricelist {
	const HOOK = 'cwpc_pricelist_tick';
	const OPTION = 'cwpc_pricelist_settings';
	const STATE = 'cwpc_pricelist_state';
	private $resolver;
	private $building = false;
	private $changed = false;

	public function __construct( CWPC_Resolver $resolver ) {
		$this->resolver = $resolver;
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		add_shortcode( 'cwpc_cjenik', array( $this, 'shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_action( 'admin_post_cwpc_pricelist_save', array( $this, 'save' ) );
		add_action( 'admin_post_cwpc_pricelist_generate', array( $this, 'generate_action' ) );
		add_action( 'admin_post_cwpc_pricelist_archive', array( $this, 'archive_action' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ) );
		foreach ( array( 'woocommerce_after_product_object_save', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_scheduled_sales', 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted', 'cwpc_snapshot_changed', 'cwpc_history_changed', 'cwpc_integrity_scan' ) as $hook ) {
			add_action( $hook, array( $this, 'dirty' ), 100 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), 100, 4 );
		}
		add_action( 'save_post', array( $this, 'post_changed' ), 100 );
		add_action( 'deleted_post', array( $this, 'deleted_post' ), 100, 2 );
		add_action( 'set_object_terms', array( $this, 'post_changed' ), 100 );
		add_action( 'edited_term', array( $this, 'dirty' ), 100 );
		add_action( 'delete_term', array( $this, 'dirty' ), 100 );
		add_action( 'updated_option', array( $this, 'option_changed' ), 100, 3 );
		add_action( 'shutdown', array( $this, 'shutdown' ), 100 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cwpc cjenik', array( $this, 'cli' ) );
		}
	}

	public static function defaults() {
		return array( 'enabled' => 0, 'store_type' => 'Web trgovina', 'address' => '', 'store_id' => 'WEB01', 'time' => '06:30', 'retention' => 30, 'scheduler_mode' => 'auto', 'brand_taxonomy' => 'product_brand', 'barcode_meta' => '', 'unit_meta' => '', 'unit_price_meta' => '', 'sale_name_meta' => '', 'sale_name' => 'Akcijska prodaja', 'accepted' => 0 );
	}
	public static function settings() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}
	public static function legal_text() {
		return 'Plugin je tehnički alat i ne predstavlja pravni savjet niti jamstvo potpune zakonske usklađenosti. Korištenjem plugina korisnik prihvaća odgovornost za točnost i verifikaciju podataka, konfiguraciju, pravodobnu objavu cjenika, nadzor rada i usklađenost svojeg poslovanja s važećim propisima. Propisi se mogu mijenjati. U najvećem opsegu dopuštenom mjerodavnim pravom isključuje se odgovornost razvojnog programera za štetu povezanu s propustima u usklađivanju, netočnim podacima, konfiguracijom ili radom infrastrukture. Ova napomena ne isključuje niti ograničava odgovornost koju prema prisilnim propisima nije dopušteno isključiti ili ograničiti.';
	}
	public static function now() { return new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Zagreb' ) ); }
	public function schedules( $schedules ) {
		$schedules['cwpc_five_minutes'] = array( 'interval' => 300, 'display' => 'CWPC: svakih pet minuta' );
		return $schedules;
	}
	public function init() {
		$s = self::settings();
		$mode = $s['scheduler_mode'] ?? 'auto';
		if ( empty( $s['enabled'] ) || 'server' === $mode ) {
			if ( wp_next_scheduled( self::HOOK ) ) { wp_clear_scheduled_hook( self::HOOK ); }
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, 'cwpc_five_minutes', self::HOOK );
		}
	}
	public function dirty() {
		if ( $this->building ) { return; }
		$this->changed = true;
		// A new token for every mutation lets an in-flight builder detect concurrent writes.
		update_option( 'cwpc_pricelist_revision', wp_generate_uuid4(), false );
	}
	public function meta_changed( $meta_id, $id, $key, $value ) { $this->post_changed( $id ); }
	public function post_changed( $id ) {
		if ( in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) { $this->dirty(); }
	}
	public function deleted_post( $id, $post ) {
		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) { $this->dirty(); }
	}
	public function option_changed( $name, $old, $new ) {
		if ( 'cwpc_settings' === $name || 0 === strpos( $name, 'woocommerce_' ) ) { $this->dirty(); }
	}
	public function shutdown() {
		if ( $this->changed && ! empty( self::settings()['enabled'] ) ) { $this->generate(); }
	}
	private function revision() {
		// Bypass per-request option caches while detecting another writer.
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cwpc_pricelist_revision' ) );
	}
	public function tick() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$is_cli = defined( 'WP_CLI' ) && WP_CLI;
		$mode = $s['scheduler_mode'] ?? 'auto';
		if ( ! $is_cli && 'server' === $mode ) { return; }
		if ( ! $is_cli && 'auto' === $mode && (int) get_option( 'cwpc_pricelist_cli_heartbeat', 0 ) > time() - 900 ) { return; }
		if ( ! $is_cli ) { update_option( 'cwpc_pricelist_heartbeat', time(), false ); }
		$state = get_option( self::STATE, array() );
		$now = self::now();
		$daily = $now->format( 'H:i' ) >= $s['time'] && ( ( $state['daily_date'] ?? '' ) !== $now->format( 'Y-m-d' ) || empty( $state['daily_file'] ) );
		$boundary = ! empty( $state['next_sale'] ) && time() >= (int) $state['next_sale'];
		if ( $daily || $boundary || empty( $state['current_file'] ) || ( $state['revision'] ?? '' ) !== $this->revision() || (int) ( $state['generated'] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
			$this->generate( $daily );
		}
	}
	public function cli( $args, $assoc ) {
		if ( empty( self::settings()['enabled'] ) ) { WP_CLI::error( 'Digitalni cjenik nije uključen.' ); }
		update_option( 'cwpc_pricelist_cli_heartbeat', time(), false );
		if ( isset( $assoc['tick'] ) ) { $this->tick(); }
		else {
			$result = $this->generate( isset( $assoc['archive'] ) );
			if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
		}
		if ( get_option( 'cwpc_pricelist_error' ) ) { WP_CLI::error( get_option( 'cwpc_pricelist_error' ) ); }
		WP_CLI::success( 'Cjenik provjeren / objavljen.' );
	}
	private function directory() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) { throw new RuntimeException( 'Mapa za cjenike nije dostupna.' ); }
		$dir = trailingslashit( $upload['basedir'] ) . 'cwpc-pricelists';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { throw new RuntimeException( 'Nije moguće izraditi mapu cjenika.' ); }
		if ( ! is_writable( $dir ) ) { throw new RuntimeException( 'Mapa cjenika nije zapisiva.' ); }
		// Files in this folder contain public product data only. No uploaded code is stored here.
		if ( ! file_exists( $dir . '/index.html' ) ) { file_put_contents( $dir . '/index.html', '' ); }
		return $dir;
	}
	public static function headers() {
		return array( 'naziv', 'sifra', 'marka', 'jedinica_mjere', 'cijena_za_jedinicu_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'sidrena_cijena', 'barkod', 'dostupnost', 'referentni_datum', 'status_sidrene_cijene', 'valuta', 'id_proizvoda' );
	}
	public static function csv_cell( $value ) {
		$value = (string) $value;
		// Quoting alone does not stop Excel/LibreOffice formula execution.
		if ( preg_match( '/^[\s\x00-\x1F]*[=+@-]/u', $value ) || preg_match( '/^[\x09\x0D\x0A]/', $value ) ) { return "'" . $value; }
		return $value;
	}
	private function meta( $product, $key, $inherit = true ) {
		if ( ! $key ) { return ''; }
		$value = $product->get_meta( $key, true, 'edit' );
		if ( '' === $value && $inherit && $product->get_parent_id() ) { $value = get_post_meta( $product->get_parent_id(), $key, true ); }
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
	private function money( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ? number_format( (float) $value, wc_get_price_decimals(), '.', '' ) : '';
	}
	public function public_product( $product ) {
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status( 'edit' ) || $product->is_type( array( 'variable', 'grouped' ) ) ) { return false; }
		$id = $product->get_parent_id() ?: $product->get_id();
		$post = get_post( $id );
		return $post && 'publish' === $post->post_status && '' === $post->post_password;
	}
	public function row( $product, $create_snapshot = true ) {
		if ( ! $this->public_product( $product ) ) { return null; }
		$s = self::settings();
		$regular = $product->get_regular_price( 'edit' );
		$sale = $product->get_sale_price( 'edit' );
		$on_sale = $product->is_on_sale( 'edit' );
		$price = $on_sale ? $sale : $regular;
		if ( '' === $price ) { $price = $product->get_price( 'edit' ); }
		$gross = '' === $price ? '' : CWPC_Tracker::gross_base( $product, (float) $price );
		if ( $create_snapshot ) { $snapshot = $this->resolver->get_or_create_snapshot( $product ); }
		else {
			global $wpdb;
			$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CWPC_DB::snapshots_table() . ' WHERE object_id = %d AND reference_date = %s', $product->get_id(), $this->resolver->get_reference_date( $product ) ), ARRAY_A );
		}
		$brands = taxonomy_exists( $s['brand_taxonomy'] ) ? wp_get_post_terms( $product->get_parent_id() ?: $product->get_id(), $s['brand_taxonomy'], array( 'fields' => 'names' ) ) : array();
		$brand = is_wp_error( $brands ) ? '' : implode( ', ', $brands );
		$barcode = $s['barcode_meta'] ? $this->meta( $product, $s['barcode_meta'], false ) : ( method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id( 'edit' ) : '' );
		$sale_name = $this->meta( $product, $s['sale_name_meta'] );
		$special = $on_sale || '' !== $sale_name;
		$values = array( $product->get_name(), $product->get_sku( 'edit' ), $brand, $this->meta( $product, $s['unit_meta'] ), $this->money( $this->meta( $product, $s['unit_price_meta'] ) ), $this->money( $gross ), $special ? 'da' : 'ne', $special ? ( $sale_name ?: $s['sale_name'] ) : '', $snapshot ? $this->money( $snapshot['price'] ) : '', $barcode, $product->is_in_stock() ? 'dostupno' : 'nedostupno', $this->resolver->get_reference_date( $product ), $snapshot ? $snapshot['status'] : 'nedostaje', get_woocommerce_currency(), $product->get_id() );
		return array_combine( self::headers(), $values );
	}
	private function ids( $after, $limit ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC LIMIT %d", $after, $limit ) );
	}
	private function archive_name( $s, $sequence, DateTimeImmutable $now ) {
		$parts = array();
		foreach ( array( $s['store_type'], $s['address'], $s['store_id'] ) as $part ) { $parts[] = substr( sanitize_title( $part ), 0, 60 ); }
		return implode( '_', $parts ) . '_' . sprintf( '%06d', $sequence ) . '_' . $now->format( 'Ymd-His' ) . '.csv';
	}
	private function archive_info_for_date( $dir, $date ) {
		$target = str_replace( '-', '', $date );
		$found = array();
		foreach ( glob( $dir . '/*.csv' ) ?: array() as $path ) {
			$file = basename( $path );
			if ( ! preg_match( '/^[a-z0-9%_-]+_\\d{6,}_(\\d{8})-(\\d{6})\\.csv$/D', $file, $m ) || $m[1] !== $target || is_link( $path ) ) { continue; }
			$dt = DateTimeImmutable::createFromFormat( '!Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone( 'Europe/Zagreb' ) );
			$found[] = array( 'file' => $file, 'timestamp' => $dt ? $dt->getTimestamp() : (int) filemtime( $path ) );
		}
		if ( ! $found ) { return null; }
		usort( $found, function( $a, $b ) { return $a['timestamp'] <=> $b['timestamp']; } );
		return $found[0];
	}
	private function publish_archive_from_tmp( $tmp, $dir, $s, DateTimeImmutable $now ) {
		$sequence = (int) get_option( 'cwpc_pricelist_sequence', 0 ) + 1;
		$file = $this->archive_name( $s, $sequence, $now );
		$stage = $dir . '/archive-' . wp_generate_uuid4() . '.tmp';
		if ( ! copy( $tmp, $stage ) || ! rename( $stage, $dir . '/' . $file ) ) {
			if ( file_exists( $stage ) ) { unlink( $stage ); }
			throw new RuntimeException( 'Atomska objava arhivskog CSV cjenika nije uspjela.' );
		}
		update_option( 'cwpc_pricelist_sequence', $sequence, false );
		return $file;
	}
	private function replace_current_from_tmp( $tmp, $dir ) {
		$stage = $dir . '/current-' . wp_generate_uuid4() . '.tmp';
		if ( ! copy( $tmp, $stage ) ) { throw new RuntimeException( 'Izrada aktualnog CSV cjenika nije uspjela.' ); }
		$current = $dir . '/aktualni.csv';
		if ( ! @rename( $stage, $current ) ) {
			if ( file_exists( $current ) && ! @unlink( $current ) ) { if ( file_exists( $stage ) ) { unlink( $stage ); } throw new RuntimeException( 'Zamjena aktualnog CSV cjenika nije uspjela.' ); }
			if ( ! @rename( $stage, $current ) ) { if ( file_exists( $stage ) ) { unlink( $stage ); } throw new RuntimeException( 'Zamjena aktualnog CSV cjenika nije uspjela.' ); }
		}
		return 'aktualni.csv';
	}
	public function generate( $force_daily = false ) {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return new WP_Error( 'disabled', 'Digitalni cjenik nije uključen.' ); }
		$lock = null; $fh = null; $tmp = ''; $locked = false;
		try {
			if ( ! $s['address'] || ! $s['store_id'] || ! $s['store_type'] ) { throw new RuntimeException( 'Unesite oblik objekta, adresu i oznaku objekta.' ); }
			$dir = $this->directory();
			$lock = @fopen( $dir . '/generation.lock', 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { return new WP_Error( 'busy', 'Generiranje je već u tijeku.' ); }
			$locked = true; $this->building = true;
			$revision = $this->revision();
			foreach ( array( self::STATE, self::OPTION, 'cwpc_pricelist_sequence', 'cwpc_settings' ) as $option ) { wp_cache_delete( $option, 'options' ); }
			$s = self::settings();
			if ( empty( $s['enabled'] ) ) { return new WP_Error( 'disabled', 'Digitalni cjenik nije uključen.' ); }
			$previous = get_option( self::STATE, array() );
			$started = microtime( true );
			$tmp = $dir . '/build-' . wp_generate_uuid4() . '.tmp';
			$fh = @fopen( $tmp, 'xb' );
			if ( ! $fh ) { throw new RuntimeException( 'Nije moguće otvoriti privremenu datoteku.' ); }
			if ( false === fputcsv( $fh, self::headers(), ',', '"', '' ) ) { throw new RuntimeException( 'Zapis CSV zaglavlja nije uspio.' ); }
			$last = 0; $rows = 0; $warnings = array(); $next_sale = 0;
			do {
				$ids = $this->ids( $last, 200 );
				foreach ( $ids as $id ) {
					$last = (int) $id;
					$product = wc_get_product( $id );
					$row = $this->row( $product );
					if ( ! $row ) { continue; }
					foreach ( array( 'sifra', 'marka', 'barkod', 'maloprodajna_cijena', 'sidrena_cijena' ) as $field ) {
						if ( '' === (string) $row[ $field ] ) { $warnings[ $field ] = ( $warnings[ $field ] ?? 0 ) + 1; }
					}
					if ( '' !== $row['jedinica_mjere'] && '' === $row['cijena_za_jedinicu_mjere'] ) { $warnings['cijena_za_jedinicu_mjere'] = ( $warnings['cijena_za_jedinicu_mjere'] ?? 0 ) + 1; }
					if ( 'da' === $row['posebni_oblik_prodaje'] && '' === $row['naziv_posebnog_oblika_prodaje'] ) { $warnings['naziv_posebnog_oblika_prodaje'] = ( $warnings['naziv_posebnog_oblika_prodaje'] ?? 0 ) + 1; }
					if ( 0 === strpos( $row['status_sidrene_cijene'], 'fallback_' ) ) { $warnings['neverificirana_sidrena'] = ( $warnings['neverificirana_sidrena'] ?? 0 ) + 1; }
					if ( false === fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), array_values( $row ) ), ',', '"', '' ) ) { throw new RuntimeException( 'Zapis CSV retka nije uspio (provjerite prostor na disku).' ); }
					$rows++;
					foreach ( array( $product->get_date_on_sale_from( 'edit' ), $product->get_date_on_sale_to( 'edit' ) ) as $index => $date ) {
						$ts = $date ? $date->getTimestamp() + $index : 0;
						if ( $ts > time() && ( ! $next_sale || $ts < $next_sale ) ) { $next_sale = $ts; }
					}
					if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && microtime( true ) - $started > 20 ) { throw new RuntimeException( 'Katalog premašuje web vremenski limit. Generirajte naredbom wp cwpc cjenik putem server crona.' ); }
				}
			} while ( count( $ids ) === 200 );
			if ( ! fflush( $fh ) ) { throw new RuntimeException( 'Spremanje CSV datoteke nije uspjelo.' ); }
			fclose( $fh ); $fh = null;
			if ( $revision !== $this->revision() ) { throw new RuntimeException( 'Katalog se promijenio tijekom generiranja. Cjenik će se ponovno izraditi.' ); }
			$now = self::now();
			$today = $now->format( 'Y-m-d' );
			$hash = hash_file( 'sha256', $tmp );
			$identity = hash( 'sha256', wp_json_encode( array( $s['store_type'], $s['address'], $s['store_id'] ) ) );
			$current_path = $dir . '/aktualni.csv';
			$current_changed = ( $previous['identity'] ?? '' ) !== $identity || ! is_file( $current_path ) || ( $previous['hash'] ?? '' ) !== $hash;
			$current_file = 'aktualni.csv';
			$current_published = (int) ( $previous['published'] ?? 0 );
			if ( $current_changed ) {
				$current_file = $this->replace_current_from_tmp( $tmp, $dir );
				$current_published = time();
			}

			$existing_daily = $this->archive_info_for_date( $dir, $today );
			$daily_due = ( $force_daily || $now->format( 'H:i' ) >= $s['time'] ) && ! $existing_daily;
			$daily_file = $existing_daily['file'] ?? ( $previous['daily_file'] ?? '' );
			$daily_published = (int) ( $existing_daily['timestamp'] ?? ( $previous['daily_published'] ?? 0 ) );
			$daily_date = $existing_daily ? $today : ( ( $previous['daily_date'] ?? '' ) === $today ? '' : ( $previous['daily_date'] ?? '' ) );
			if ( $daily_due ) {
				$daily_file = $this->publish_archive_from_tmp( $tmp, $dir, $s, $now );
				$daily_published = time();
				$daily_date = $today;
			}

			$latest_archive = $daily_due ? $daily_file : ( $previous['file'] ?? $daily_file );
			$state = array(
				'identity' => $identity,
				'file' => $latest_archive,
				'current_file' => $current_file,
				'hash' => $hash,
				'generated' => time(),
				'published' => $current_published ?: time(),
				'revision' => $revision,
				'rows' => $rows,
				'warnings' => $warnings,
				'next_sale' => $next_sale,
				'daily_date' => $daily_date,
				'daily_file' => $daily_file,
				'daily_published' => $daily_published,
			);
			update_option( self::STATE, $state, false );
			delete_option( 'cwpc_pricelist_error' );
			$this->changed = false;
			$this->cleanup( $dir, $current_file, max( 30, (int) $s['retention'] ) );
			return $state;
		} catch ( Throwable $e ) {
			$message = sanitize_text_field( $e->getMessage() );
			update_option( 'cwpc_pricelist_error', $message, false );
			return new WP_Error( 'generation_failed', $message );
		} finally {
			if ( is_resource( $fh ) ) { fclose( $fh ); }
			if ( $tmp && file_exists( $tmp ) ) { unlink( $tmp ); }
			if ( is_resource( $lock ) ) { if ( $locked ) { flock( $lock, LOCK_UN ); } fclose( $lock ); }
			$this->building = false;
		}
	}

	private function cleanup( $dir, $current, $days ) {
		foreach ( glob( $dir . '/*.csv' ) ?: array() as $path ) {
			if ( basename( $path ) !== $current && preg_match( '/^[a-z0-9%_-]+_\d{6,}_\d{8}-\d{6}\.csv$/D', basename( $path ) ) && ! is_link( $path ) && filemtime( $path ) < time() - ( $days * DAY_IN_SECONDS ) ) { unlink( $path ); }
		}
		foreach ( array_merge( glob( $dir . '/build-*.tmp' ) ?: array(), glob( $dir . '/archive-*.tmp' ) ?: array(), glob( $dir . '/current-*.tmp' ) ?: array() ) as $path ) {
			if ( ! is_link( $path ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) { unlink( $path ); }
		}
	}

	public function url( $kind = 'index', $file = '' ) {
		if ( get_option( 'permalink_structure' ) ) {
			$path = 'index' === $kind ? '/cjenik/' : ( 'current' === $kind ? '/cjenik/aktualni.csv' : '/cjenik/arhiva/' . rawurlencode( $file ) );
			return home_url( $path );
		}
		return add_query_arg( array( 'cwpc_cjenik' => $kind, 'datoteka' => $file ), home_url( '/' ) );
	}
	private function requested_route() {
		if ( isset( $_GET['cwpc_cjenik'] ) && is_string( $_GET['cwpc_cjenik'] ) ) {
			return array( sanitize_key( wp_unslash( $_GET['cwpc_cjenik'] ) ), isset( $_GET['datoteka'] ) && is_string( $_GET['datoteka'] ) ? wp_unslash( $_GET['datoteka'] ) : '' );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = substr( (string) $uri, strlen( $base ) );
		if ( preg_match( '#^/cjenik/?$#', $path ) ) { return array( 'index', '' ); }
		if ( '/cjenik/aktualni.csv' === $path ) { return array( 'current', '' ); }
		if ( preg_match( '#^/cjenik/arhiva/([^/]+)$#', $path, $match ) ) { return array( 'archive', rawurldecode( $match[1] ) ); }
		return null;
	}
	public function route() {
		$route = $this->requested_route();
		if ( ! $route ) { return; }
		// When the module is disabled, CWPC releases all public pricelist routes.
		// Existing CSV files/state are kept on disk for a possible later re-enable,
		// but CWPC no longer serves /cjenik/, current or archive URLs.
		if ( empty( self::settings()['enabled'] ) ) {
			// Plain-permalink CWPC URLs use a query parameter on the home URL; return
			// a real 404 instead of accidentally showing the home page. Pretty URLs
			// are released back to WordPress, so an existing /cjenik/ page can reappear.
			if ( isset( $_GET['cwpc_cjenik'] ) ) { $this->fail( 404, 'Digitalni cjenik nije uključen.' ); }
			return;
		}
		// A GET cannot trigger catalog generation, SQL mutation, or arbitrary file reads.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { header( 'Allow: GET, HEAD' ); $this->fail( 405, 'Dopušten je samo GET ili HEAD.' ); }
		list( $kind, $file ) = $route;
		// Published archives are served only while the Digitalni cjenik module is enabled.
		if ( 'index' === $kind ) {
			status_header( 200 );
			if ( 'HEAD' === $method ) { exit; }
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Digitalni cjenik</title><style>body{font:16px/1.6 system-ui,sans-serif;max-width:960px;margin:40px auto;padding:0 20px;color:#20252c}a{color:#175e9e}li{margin:8px 0}table{border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}</style></head><body>';
			echo $this->shortcode(); // Escaped inside shortcode().
			echo '</body></html>'; exit;
		}
		$state = get_option( self::STATE, array() );
		$legacy_current = false;
		if ( 'current' === $kind ) {
			if ( ( empty( $state['current_file'] ) && empty( $state['file'] ) ) || ( $state['revision'] ?? '' ) !== $this->revision() || ( ! empty( $state['next_sale'] ) && time() >= (int) $state['next_sale'] ) || (int) ( $state['generated'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				header( 'Retry-After: 60' ); $this->fail( 503, 'Cjenik čeka osvježavanje. Aktualni podaci dostupni su kroz javni API povezan na stranici cjenika.' );
			}
			$legacy_current = empty( $state['current_file'] ) && ! empty( $state['file'] );
			$file = $legacy_current ? $state['file'] : 'aktualni.csv';
		} elseif ( 'archive' !== $kind ) { $this->fail( 404, 'Datoteka nije pronađena.' ); }
		// Only the stable current file or immutable generated archive names are allowed.
		if ( 'current' === $kind && ! $legacy_current ) {
			if ( 'aktualni.csv' !== $file ) { $this->fail( 404, 'Datoteka nije pronađena.' ); }
		} elseif ( ! preg_match( '/^[a-z0-9%_-]+_\d{6,}_\d{8}-\d{6}\.csv$/D', $file ) || basename( $file ) !== $file ) { $this->fail( 404, 'Datoteka nije pronađena.' ); }
		$upload = wp_upload_dir();
		$dir = trailingslashit( $upload['basedir'] ) . 'cwpc-pricelists';
		$path = $dir . '/' . $file;
		if ( ! is_file( $path ) || is_link( $path ) || realpath( dirname( $path ) ) !== realpath( $dir ) ) { $this->fail( 404, 'Datoteka nije pronađena.' ); }
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) { $this->fail( 503, 'Datoteka trenutačno nije čitljiva.' ); }
		status_header( 200 );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( 'current' === $kind ? 'aktualni.csv' : $file ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $path ) ) . ' GMT' );
		if ( 'HEAD' !== $method ) { fpassthru( $fh ); }
		fclose( $fh ); exit;
	}
	private function fail( $status, $message ) {
		status_header( $status ); header( 'Content-Type: text/plain; charset=utf-8' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { echo esc_html( $message ); }
		exit;
	}
	public function shortcode() {
		if ( empty( self::settings()['enabled'] ) ) { return ''; }
		$state = get_option( self::STATE, array() );
		$upload = wp_upload_dir();
		$paths = array_values( array_filter( glob( trailingslashit( $upload['basedir'] ) . 'cwpc-pricelists/*.csv' ) ?: array(), function( $path ) { return preg_match( '/^[a-z0-9%_-]+_\d{6,}_\d{8}-\d{6}\.csv$/D', basename( $path ) ) && ! is_link( $path ); } ) );
		usort( $paths, function( $a, $b ) { return filemtime( $b ) <=> filemtime( $a ); } );
		$html = '<section class="cwpc-digital-pricelist"><h1>Digitalni cjenik</h1>';
		if ( ( ! empty( $state['current_file'] ) || ! empty( $state['file'] ) ) && ! empty( self::settings()['enabled'] ) ) {
			$html .= '<p><a href="' . esc_url( $this->url( 'current' ) ) . '">Aktualni cjenik — CSV</a></p><p>Aktualni cjenik osvježen: ' . esc_html( wp_date( 'd.m.Y. H:i:s', (int) $state['published'], new DateTimeZone( 'Europe/Zagreb' ) ) ) . ' (Europe/Zagreb).</p>';
			if ( ( $state['revision'] ?? '' ) !== $this->revision() || ( ! empty( $state['next_sale'] ) && time() >= $state['next_sale'] ) || (int) $state['generated'] < time() - DAY_IN_SECONDS ) { $html .= '<p>CSV čeka osvježavanje; za aktualne cijene koristite javni API.</p>'; }
			$html .= '<p><a href="' . esc_url( rest_url( 'cwpc/v1/cjenik' ) ) . '">Aktualni podaci — javni API</a></p>';
		} else { $html .= '<p>Aktualni cjenik trenutačno nije objavljen.</p>'; }
		$page = isset( $_GET['cwpc_archive_page'] ) && is_scalar( $_GET['cwpc_archive_page'] ) ? max( 1, min( 1000000, absint( $_GET['cwpc_archive_page'] ) ) ) : 1;
		$total_pages = max( 1, (int) ceil( count( $paths ) / 100 ) );
		$page = min( $page, $total_pages );
		$html .= '<h2>Arhiva objavljenih cjenika</h2><ul>';
		foreach ( array_slice( $paths, ( $page - 1 ) * 100, 100 ) as $path ) {
			$file = basename( $path );
			if ( ! preg_match( '/^[a-z0-9%_-]+_\d{6,}_\d{8}-\d{6}\.csv$/D', $file ) || is_link( $path ) ) { continue; }
			$html .= '<li><a href="' . esc_url( $this->url( 'archive', $file ) ) . '">' . esc_html( $file ) . '</a></li>';
		}
		$html .= '</ul><nav aria-label="Arhiva cjenika">';
		if ( $page > 1 ) { $html .= '<a href="' . esc_url( add_query_arg( 'cwpc_archive_page', $page - 1, $this->url() ) ) . '">Novije objave</a> '; }
		if ( $page < $total_pages ) { $html .= '<a href="' . esc_url( add_query_arg( 'cwpc_archive_page', $page + 1, $this->url() ) ) . '">Starije objave</a>'; }
		return $html . '</nav></section>';
	}
	public function rest_routes() {
		// Do not register a public API route while the module is disabled.
		if ( empty( self::settings()['enabled'] ) ) { return; }
		register_rest_route( 'cwpc/v1', '/cjenik', array(
			'methods' => 'GET', 'callback' => array( $this, 'live' ), 'permission_callback' => '__return_true',
			'args' => array(
				'after' => array( 'default' => 0, 'validate_callback' => function( $v ) { return is_scalar( $v ) && ctype_digit( (string) $v ) && (float) $v <= PHP_INT_MAX; }, 'sanitize_callback' => 'absint' ),
				'limit' => array( 'default' => 100, 'validate_callback' => function( $v ) { return is_scalar( $v ) && ctype_digit( (string) $v ) && (int) $v >= 1 && (int) $v <= 100; }, 'sanitize_callback' => 'absint' ),
			),
		) );
	}
	public function live( $request ) {
		if ( empty( self::settings()['enabled'] ) ) { return new WP_Error( 'disabled', 'Cjenik nije uključen.', array( 'status' => 503 ) ); }
		$after = (int) $request['after']; $limit = (int) $request['limit'];
		$ids = $this->ids( $after, $limit ); $rows = array(); $last = $after;
		foreach ( $ids as $id ) {
			$last = (int) $id;
			$row = $this->row( wc_get_product( $id ), false );
			if ( $row ) { $rows[] = $row; }
		}
		$more = count( $ids ) === $limit;
		$response = new WP_REST_Response( array( 'generated_at' => self::now()->format( DATE_ATOM ), 'currency' => get_woocommerce_currency(), 'products' => $rows, 'next_after' => $more ? $last : null, 'next' => $more ? add_query_arg( array( 'after' => $last, 'limit' => $limit ), rest_url( 'cwpc/v1/cjenik' ) ) : null ), 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
	private function authorize( $action ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Nedovoljna ovlaštenja ili neispravna metoda.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}
	private function posted( $key ) { return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; }
	public function save() {
		$this->authorize( 'cwpc_pricelist_save' );
		$old = self::settings(); $new = $old;
		foreach ( array( 'store_type', 'address', 'store_id', 'sale_name' ) as $key ) { $new[ $key ] = substr( $this->posted( $key ), 0, 180 ); }
		foreach ( array( 'brand_taxonomy', 'barcode_meta', 'unit_meta', 'unit_price_meta', 'sale_name_meta' ) as $key ) {
			$value = $this->posted( $key );
			if ( '' !== $value && ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $value ) ) { wp_die( 'Neispravan naziv taxonomy/meta polja.' ); }
			$new[ $key ] = $value;
		}
		$time = $this->posted( 'time' );
		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $time ) || $time > '07:30' ) { wp_die( 'Vrijeme objave mora biti između 00:00 i 07:30 (Europe/Zagreb).' ); }
		$new['time'] = $time;
		$new['retention'] = max( 30, min( 365, absint( $this->posted( 'retention' ) ) ) );
		$mode = $this->posted( 'scheduler_mode' );
		$new['scheduler_mode'] = in_array( $mode, array( 'auto', 'wp', 'server' ), true ) ? $mode : 'auto';
		$new['enabled'] = isset( $_POST['enabled'] ) ? 1 : 0;
		if ( isset( $_POST['accepted'] ) ) {
			$new['accepted'] = time(); $new['accepted_user'] = get_current_user_id(); $new['accepted_version'] = CWPC_VERSION;
		}
		if ( $new['enabled'] && ( ! $new['accepted'] || ! $new['address'] || ! $new['store_id'] || ! $new['store_type'] ) ) { wp_die( 'Unesite podatke objekta i potvrdite pravnu napomenu prije uključivanja digitalnog cjenika.' ); }
		update_option( self::OPTION, $new, false );
		CWPC_DB::audit( 'pricelist_settings_updated', 0, null, $old, $new );
		if ( $new !== $old ) { $this->dirty(); }
		if ( empty( $new['enabled'] ) ) { wp_clear_scheduled_hook( self::HOOK ); }
		else { $this->init(); $this->generate( false ); }
		wp_safe_redirect( admin_url( 'admin.php?page=cwpc-price-compliance&tab=digital&cwpc_saved=1' ) ); exit;
	}
	public function generate_action() {
		$this->authorize( 'cwpc_pricelist_generate' );
		$result = $this->generate( false );
		if ( is_wp_error( $result ) ) { update_option( 'cwpc_pricelist_error', $result->get_error_message(), false ); }
		wp_safe_redirect( admin_url( 'admin.php?page=cwpc-price-compliance&tab=digital&cwpc_current=1' ) ); exit;
	}
	public function archive_action() {
		$this->authorize( 'cwpc_pricelist_archive' );
		$result = $this->generate( true );
		if ( is_wp_error( $result ) ) { update_option( 'cwpc_pricelist_error', $result->get_error_message(), false ); }
		wp_safe_redirect( admin_url( 'admin.php?page=cwpc-price-compliance&tab=digital&cwpc_archive=1' ) ); exit;
	}
	public function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$state = get_option( self::STATE, array() ); $now = self::now();
		$missing = $now->format( 'H:i' ) >= '07:30' && ( $state['daily_date'] ?? '' ) !== $now->format( 'Y-m-d' );
		$late = false;
		if ( ( $state['daily_date'] ?? '' ) === $now->format( 'Y-m-d' ) && ! empty( $state['daily_published'] ) ) {
			$daily_time = wp_date( 'H:i:s', (int) $state['daily_published'], new DateTimeZone( 'Europe/Zagreb' ) );
			$late = $daily_time >= '08:00:00';
		}
		$error = get_option( 'cwpc_pricelist_error', '' );
		if ( $missing || $late || $error || ! empty( $state['warnings'] ) ) {
			$message = $missing ? 'Današnji arhivski cjenik nije potvrđen. Rok je 08:00 (Europe/Zagreb). ' : ( $late ? 'Današnji arhivski cjenik objavljen je nakon 08:00. ' : '' );
			echo '<div class="notice notice-warning"><p><strong>Digitalni cjenik:</strong> ' . esc_html( $message ) . esc_html( $error ?: ( ! empty( $state['warnings'] ) ? 'Postoje nepotpuni ili neverificirani podaci.' : '' ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=cwpc-price-compliance&tab=digital' ) ) . '">Provjerite status.</a></p></div>';
		}
	}
	public function dashboard() {
		if ( current_user_can( 'manage_woocommerce' ) ) { wp_add_dashboard_widget( 'cwpc_pricelist', 'Digitalni cjenik — status', array( $this, 'status' ) ); }
	}
	public function status() {
		$s = self::settings(); $state = get_option( self::STATE, array() );
		$now = self::now(); $cli = (int) get_option( 'cwpc_pricelist_cli_heartbeat', 0 );
		$heartbeat = (int) get_option( 'cwpc_pricelist_heartbeat', 0 );
		$error = get_option( 'cwpc_pricelist_error', '' );
		$daily = 'Nije izrađen';
		if ( ( $state['daily_date'] ?? '' ) === $now->format( 'Y-m-d' ) ) {
			$daily_ts = (int) ( $state['daily_published'] ?? 0 );
			if ( $daily_ts ) {
				$daily_time = wp_date( 'H:i:s', $daily_ts, new DateTimeZone( 'Europe/Zagreb' ) );
				$daily = 'Izrađen u ' . $daily_time . ( $daily_time < '08:00:00' ? '' : ' — nakon roka 08:00' );
			} else { $daily = 'Izrađen (vrijeme stare verzije nije zabilježeno)'; }
		}
		$modes = array( 'auto' => 'Automatski (server CLI ima prednost, WP-Cron je fallback)', 'wp' => 'Samo WP-Cron', 'server' => 'Samo server cron / WP-CLI' );
		$mode = $s['scheduler_mode'] ?? 'auto';
		$wp_next = wp_next_scheduled( self::HOOK );
		$cli_status = $cli ? 'Zadnje izvršavanje: ' . wp_date( 'd.m.Y. H:i:s', $cli, new DateTimeZone( 'Europe/Zagreb' ) ) : 'Nije zabilježeno';
		$rows = array(
			'Modul' => $s['enabled'] ? 'Uključen' : 'Nije uključen',
			'Današnji arhivski cjenik' => $daily,
			'Rok / plan' => '08:00 / ' . $s['time'] . ' Europe/Zagreb',
			'Aktualni CSV osvježen' => ! empty( $state['published'] ) ? wp_date( 'd.m.Y. H:i:s', $state['published'], new DateTimeZone( 'Europe/Zagreb' ) ) : 'Nema',
			'Broj izvezenih redaka' => (string) ( $state['rows'] ?? 0 ),
			'Arhiva' => 'Jedna automatska dnevna objava; čuvanje najmanje ' . max( 30, (int) $s['retention'] ) . ' dana',
			'Način rasporeda' => $modes[ $mode ] ?? $modes['auto'],
			'WP-Cron zadatak' => 'server' === $mode ? 'Isključen za CWPC' : ( $wp_next ? 'Zakazan' : 'Nije zakazan' ),
			'Server cron (CLI)' => $cli_status,
			'Javni API' => $s['enabled'] ? 'Uključen; dohvat do 100 stavki po zahtjevu' : 'Isključen',
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( $error ) { echo '<p class="notice notice-error inline">' . esc_html( $error ) . '</p>'; }
		if ( ! empty( $state['warnings'] ) ) {
			echo '<p><strong>Potrebna provjera podataka (broj redaka):</strong></p><ul>';
			foreach ( $state['warnings'] as $field => $count ) { echo '<li>' . esc_html( $field ) . ': ' . esc_html( $count ) . '</li>'; }
			echo '</ul>';
		}
		echo '<p>Status izvršavanja nije jamstvo pravne usklađenosti ni potvrda da je server cron trajno konfiguriran.</p>';
	}

	public function render_admin() {
		$s = self::settings();
		echo '<h2>Digitalni cjenik (CSV)</h2>';
		$this->status();
		if ( ! empty( $s['enabled'] ) ) {
			echo '<p><a target="_blank" rel="noopener" href="' . esc_url( $this->url() ) . '">Javna stranica cjenika</a> · <a href="' . esc_url( $this->url( 'current' ) ) . '">Aktualni CSV</a> · <a href="' . esc_url( rest_url( 'cwpc/v1/cjenik' ) ) . '">Aktualni API</a></p>';
		} else {
			echo '<p><strong>Digitalni cjenik je isključen.</strong> CWPC javne rute, aktualni CSV, arhivske poveznice i API nisu aktivni. Postojeće datoteke i stanje ostaju sačuvani za ponovno uključivanje.</p>';
		}
		echo '<p><strong>Način objave:</strong> <code>aktualni.csv</code> automatski se zamjenjuje nakon relevantnih promjena kataloga. Arhiva dobiva najviše jednu automatsku dnevnu objavu, prema zadanom vremenu i najkasnije prije zakonskog roka. Postojeće stare arhivske datoteke nadogradnja ne briše prije isteka zadržavanja.</p>';
		echo '<p>Dok je uključen, modul zauzima adresu <code>/cjenik/</code>. Ako ondje već postoji stranica, njezin sadržaj ostaje spremljen, ali javni prikaz preuzima modul; nakon isključivanja CWPC ponovno oslobađa tu adresu. Shortcode <code>[cwpc_cjenik]</code> možete postaviti na drugu stranicu. Dodajte poveznicu u izbornik ili podnožje trgovine.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cwpc_pricelist_save' );
		echo '<input type="hidden" name="action" value="cwpc_pricelist_save"><table class="form-table"><tr><th>Automatska objava</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( $s['enabled'], 1, false ) . '> Uključi digitalni cjenik</label></td></tr>';
		$fields = array( 'store_type' => 'Oblik prodajnog objekta', 'address' => 'Adresa prodajnog objekta', 'store_id' => 'Oznaka objekta', 'time' => 'Vrijeme dnevne arhivske objave (Europe/Zagreb)', 'retention' => 'Čuvanje arhive u danima (najmanje 30)', 'brand_taxonomy' => 'Taxonomy marke', 'barcode_meta' => 'Barkod — meta ključ (prazno: WooCommerce GTIN)', 'unit_meta' => 'Jedinica mjere — meta ključ', 'unit_price_meta' => 'Bruto cijena za jedinicu mjere — meta ključ', 'sale_name_meta' => 'Naziv posebnog oblika prodaje — meta ključ', 'sale_name' => 'Zadani naziv posebnog oblika prodaje' );
		foreach ( $fields as $key => $label ) {
			$type = 'time' === $key ? 'time' : ( 'retention' === $key ? 'number' : 'text' );
			$bounds = 'retention' === $key ? ' min="30" max="365"' : ( 'time' === $key ? ' max="07:30"' : ' maxlength="180"' );
			echo '<tr><th><label for="cwpc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="cwpc-' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '"' . $bounds . '></td></tr>';
		}
		$mode = $s['scheduler_mode'] ?? 'auto';
		echo '<tr><th><label for="cwpc-scheduler-mode">Pokretanje rasporeda</label></th><td><select id="cwpc-scheduler-mode" name="scheduler_mode">';
		foreach ( array( 'auto' => 'Automatski — server CLI ima prednost, WP-Cron je fallback', 'wp' => 'Samo WP-Cron', 'server' => 'Samo server cron / WP-CLI' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><p class="description">Ako ste pouzdano postavili server cron, odaberite „Samo server cron / WP-CLI”; CWPC tada neće imati vlastiti WP-Cron raspored. Aktualni CSV se i dalje automatski osvježava nakon promjena kroz WooCommerce/WordPress hookove.</p></td></tr>';
		echo '</table><p>Broj pohrane automatski raste samo kod nove arhivske objave. Valuta i porezi koriste osnovicu trgovine; provjerite porezne postavke. Izvoz obuhvaća javne objavljene proizvode i varijacije, uključujući proizvode skrivene samo iz kataloga. Nacrti, privatni proizvodi, proizvodi zaštićeni lozinkom i isključene varijacije ne izvoze se.</p><p>Mapirana meta polja postaju javna: odaberite isključivo podatke proizvoda. Jedinična cijena mora biti aktualan bruto iznos. Nedostajući barkod, marka ili šifra ostaju prazni i prijavljuju se; plugin ih ne izmišlja.</p><p>' . esc_html( self::legal_text() ) . '</p>';
		echo '<label><input type="checkbox" name="accepted" value="1" ' . checked( ! empty( $s['accepted'] ), true, false ) . '> Pročitao/la sam i prihvaćam pravnu napomenu.</label>';
		submit_button( 'Spremi postavke cjenika' ); echo '</form>';

		echo '<h3>Ručno osvježavanje</h3><p>„Osvježi aktualni cjenik” ne stvara dodatnu arhivsku datoteku, osim ako je već došlo vrijeme dnevne objave i današnja arhiva još ne postoji.</p>';
		echo '<form style="display:inline-block;margin-right:10px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'cwpc_pricelist_generate' );
		echo '<input type="hidden" name="action" value="cwpc_pricelist_generate">'; submit_button( 'Osvježi aktualni cjenik', 'secondary', 'submit', false ); echo '</form>';
		echo '<form style="display:inline-block" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'cwpc_pricelist_archive' );
		echo '<input type="hidden" name="action" value="cwpc_pricelist_archive">'; submit_button( 'Objavi današnji arhivski cjenik', 'secondary', 'submit', false ); echo '</form><p class="description">Ako arhivska objava za današnji datum već postoji, drugi klik neće napraviti duplikat.</p>';

		echo '<h3>Server cron</h3><p>Za pouzdan dnevni arhivski snapshot možete postaviti hosting cron u vrijeme prije 08:00, npr. u 07:00:</p><pre>0 7 * * * /usr/local/bin/wp --path=/putanja/do/wordpressa cwpc cjenik --tick</pre><p>Ako odaberete „Samo server cron / WP-CLI”, vlastiti CWPC WP-Cron se uklanja. Ako želite češću provjeru zakazanih akcija bez prometa, server <code>--tick</code> možete pokretati i češće; to više ne stvara dodatne arhivske datoteke istoga dana. Standardni WooCommerce/WordPress cron za ostale zadatke ostaje zasebna stvar.</p><p>Izuzmite <code>/cjenik/</code>, <code>?cwpc_cjenik=</code> i <code>/wp-json/cwpc/v1/cjenik</code> iz page/CDN cachea. Omogućite automatizirani dohvat bez prijave i CAPTCHA provjera. Javni GET ne izrađuje datoteke; API čita ograničenu stranicu aktualnih podataka.</p>';
	}

}
