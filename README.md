# Systich dodatak za Canis

Ovaj repozitorij služi održavanju sidrenih (baznih) cijena na WooCommerce shopovima koji koriste plugin **Canis Woo Price Compliance HR** (Canis Liberi, GPL-2.0-or-later).

## Osnovno pravilo

Canisove datoteke nikada se ne mijenjaju. Sve dorade nalaze se u zasebnom pluginu **Systich dodatak za Canis**, koji se instalira uz Canis i nastavlja se na njegove hookove, postavke i tablice. Zahvaljujući tome nova verzija Canisa ne može pregaziti nijednu doradu, a povijest cijena koju Canis prikuplja ostaje netaknuta.

## Struktura

- `upstream/canis-woo-price-compliance-hr/` sadrži izvornu, nepromijenjenu verziju Canisa. Svaka nova verzija sprema se kao zaseban commit s oznakom `canis-<verzija>`, pa se razlike među verzijama vide naredbom `git diff canis-2.1.1 canis-<nova>`.
- `plugin/systich-canis-dodatak/` sadrži dodatak koji se instalira na shopove.
- `shopovi/` sadrži bilješke o svakom shopu: koje su dorade uključene, posebnosti teme i postavke.

## Dodirne točke s Canisom

Dodatak se oslanja samo na sljedeće dijelove Canisa. Kad izađe nova verzija, prvo treba provjeriti jesu li se oni promijenili.

- konstante `CWPC_VERSION` i `CWPC_FILE`
- hookove `cwpc_lowest_price_days`, `cwpc_history_changed` i `cwpc_snapshot_changed`
- WordPress i WooCommerce hookove na koje se Canis veže u klasi `CWPC_Frontend`
- postavke cjenika `unit_meta` i `unit_price_meta`
- tablice `{prefix}cwpc_price_history`, `{prefix}cwpc_price_snapshots` i `{prefix}cwpc_audit_log`
- mapu s cjenicima `wp-content/uploads/cwpc-pricelists/`

## Postupak kod nove verzije Canisa

1. Preuzeti novu verziju s canisliberi.hr i raspakirati je u `upstream/canis-woo-price-compliance-hr/` umjesto prethodne.
2. Napraviti commit „Canis <verzija>” i oznaku `canis-<verzija>`.
3. Pregledati razlike prema prethodnoj verziji i provjeriti dodirne točke navedene gore. Ako su se promijenile, uskladiti dodatak i u njemu povećati najvišu provjerenu verziju Canisa.
4. Ažurirati Canis i dodatak na jednom shopu, provjeriti prikaz cijena i cjenik, a zatim ažurirati ostale shopove kroz Systich Most.

## Zaštita od neprovjerene verzije

Dodatak pri pokretanju uspoređuje `CWPC_VERSION` s najvišom provjerenom verzijom. Ako je Canis noviji, dodatak isključuje dorade koje zamjenjuju Canisov ispis i u administraciji prikazuje upozorenje, a dorade koje samo dodaju podatke i dalje rade. Shop tako radi s običnim Canisom dok se dodatak ne uskladi.

## Licenca

Canis je objavljen pod licencom GPL-2.0-or-later, a pod istom je licencom i dodatak. Napomena o autorstvu Canis Liberija ostaje u izvornom kodu.
