# Systich dodatak za Canis

Ovaj repozitorij služi održavanju sidrenih (baznih) cijena na WooCommerce shopovima koji koriste plugin **Canis Woo Price Compliance HR** (Canis Liberi, GPL-2.0-or-later).

## Osnovno pravilo

Canisove datoteke nikada se ne mijenjaju. Sve dorade nalaze se u zasebnom pluginu **Systich dodatak za Canis**, koji se instalira uz Canis i nastavlja se na njegove hookove, postavke i tablice. Zahvaljujući tome nova verzija Canisa ne može pregaziti nijednu doradu, a povijest cijena koju Canis prikuplja ostaje netaknuta.

## Struktura

- `upstream/canis-woo-price-compliance-hr/` sadrži izvornu, nepromijenjenu verziju Canisa. Svaka nova verzija sprema se kao zaseban commit s oznakom `canis-<verzija>`, pa se razlike među verzijama vide naredbom `git diff canis-2.1.1 canis-<nova>`.
- `plugin/systich-canis-dodatak/` sadrži dodatak koji se instalira na shopove.
- `shopovi/` sadrži bilješke o svakom shopu: koje su dorade uključene, posebnosti teme i postavke.

## Što dodatak radi (0.3.0)

- **Prikaz u Avadinim predlošcima.** Kad su stranica proizvoda ili popisi složeni Avadinim Layout Builderom ili elementom Post Cards, Avada ne poziva hookove na koje se Canis veže, pa Canis ne prikazuje ništa. Dodatak tada Canisov blok ispisuje ispod Avadina elementa cijene (`awb_after_woo_price_content`), a u klasičnim predlošcima ne radi ništa, jer tamo blok ispisuje Canis.
- **Cijena za jedinicu mjere.** Iz neto količine proizvoda („400 ml”, „2 kg”) računa cijenu za litru ili kilogram, sprema je u meta podatke koje Canis prenosi u cjenik i prikazuje je uz cijenu. Neto količina upisuje se ručno ili se, samo u odabranim kategorijama, prepoznaje iz naziva.
- **Brže praćenje cijena.** U katalogu većem od 2.000 proizvoda svakih pet minuta pronalazi proizvode čija se cijena razlikuje od zadnjeg Canisova zapisa i predaje ih Canisu na bilježenje.
- **Cjenik u velikim katalozima.** Kad je u Canisu odabrano pokretanje „Samo server cron / WP-CLI”, dodatak uklanja Canisovu izradu cjenika na kraju web zahtjeva (u katalogu od 20.000 proizvoda ona uvijek pukne nakon 20 sekundi, a posjetitelj čeka), a katalog označava promijenjenim samo kad se promijeni meta podatak koji ulazi u cjenik. Tako izrada iz zakazanog zadatka na poslužitelju (`wp cwpc cjenik --tick`) ne propada stalno s porukom „Katalog se promijenio tijekom generiranja”.
- **Rute za Most.** Dok je Systich Most otvoren, rute `systich-canis/v1/stanje`, `sidrene`, `povijest` i `zapisnik` daju Canisove postavke, sidrene cijene, povijest cijena i zapisnik, samo za čitanje i uz Mostovu provjeru potpisa (`systich_bridge_authorize`).
- **HPOS.** Prijavljuje kompatibilnost s HPOS-om za sebe i za Canis.

## Dodirne točke s Canisom

Dodatak se oslanja samo na sljedeće dijelove Canisa; sve su skupljene u klasi `SCD_Canis`. Kad izađe nova verzija, prvo treba provjeriti jesu li se promijenile.

- konstante `CWPC_VERSION` i `CWPC_FILE`
- klasa `CWPC_Frontend` vezana na `woocommerce_single_product_summary` i njezine javne metode `render_single()` i `render_oceanwp_loop()`
- `CWPC_Plugin::instance()->get_tracker()->record_product( $product, $source )` i `CWPC_Plugin::get_settings()['tracking_enabled']`
- `CWPC_Tracker::gross_base()` i `CWPC_DB::history_table()` sa stupcima `object_id`, `regular_price`, `sale_price`
- hook `cwpc_history_changed`
- `CWPC_Plugin::instance()->get_pricelist()`, `CWPC_Pricelist::settings()` (`enabled`, `scheduler_mode`, `unit_meta`, `unit_price_meta`, `sale_name_meta`, `barcode_meta`), metode `shutdown()`, `meta_changed()` i `post_changed()` te njihovi hookovi `shutdown` i `added/updated/deleted_post_meta` s prioritetom 100
- tablice `cwpc_price_snapshots` i `cwpc_audit_log` (`CWPC_DB::snapshots_table()`, `CWPC_DB::audit_table()`), samo za čitanje
- CSS klase `cwpc-price-compliance`, `cwpc-loop`, `cwpc-lowest-price`, `cwpc-reference-price`
- postavke cjenika `unit_meta` i `unit_price_meta` (u njih se upisuju ključevi `_scd_jedinica_mjere` i `_scd_cijena_jm`)

S Avadom se dodatak oslanja na akciju `awb_after_woo_price_content` elementa „Woo Price” i na `FusionBuilder()->post_card_data['is_rendering']`.

## Postupak kod nove verzije Canisa

1. Preuzeti novu verziju s canisliberi.hr i raspakirati je u `upstream/canis-woo-price-compliance-hr/` umjesto prethodne.
2. Napraviti commit „Canis <verzija>” i oznaku `canis-<verzija>`.
3. Pregledati razlike prema prethodnoj verziji i provjeriti dodirne točke navedene gore. Ako su se promijenile, uskladiti dodatak i u njemu povećati najvišu provjerenu verziju Canisa.
4. Ažurirati Canis i dodatak na jednom shopu, provjeriti prikaz cijena i cjenik, a zatim ažurirati ostale shopove kroz Systich Most.

## Zaštita od neprovjerene verzije

Dodatak pri pokretanju uspoređuje `CWPC_VERSION` s najvišom provjerenom verzijom. Ako je Canis noviji, dodatak isključuje dorade koje zamjenjuju Canisov ispis i u administraciji prikazuje upozorenje, a dorade koje samo dodaju podatke i dalje rade. Shop tako radi s običnim Canisom dok se dodatak ne uskladi.

## Licenca

Canis je objavljen pod licencom GPL-2.0-or-later, a pod istom je licencom i dodatak. Napomena o autorstvu Canis Liberija ostaje u izvornom kodu.
