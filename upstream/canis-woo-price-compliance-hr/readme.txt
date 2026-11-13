=== Canis Woo Price Compliance HR ===
Contributors: canisliberi
Tags: woocommerce, cijene, price history, omnibus, compliance
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce plugin za povijest cijena, najnižu cijenu u 30 dana, referentne cijene, verifikaciju, audit i digitalni CSV cjenik s javnom arhivom i API-jem.

== Glavne funkcije ==

* Neograničena povijest regularne, akcijske, efektivne i bruto cijene.
* 30-dnevni minimum samo za proizvode koji su stvarno na akciji.
* Referentna cijena na globalni datum, po kategoriji ili po proizvodu/varijaciji.
* Podesivi fallback; nova instalacija zadano koristi aktualnu redovnu bruto cijenu kao neverificiranu privremenu vrijednost. Postojeća postavka se zadržava.
* Jasno označavanje fallback snapshotova u administraciji.
* Zaključani ručni i CSV-importirani snapshotovi.
* Audit promjena snapshotova i postavki.
* Simple i variable proizvodi; varijacije imaju vlastite snapshotove i povijest.
* CSV export povijesti i snapshotova te CSV import referentnih cijena.
* Periodični integrity scan uz standardne WooCommerce save/meta hookove.

== Instalacija ==

1. Instalirajte ZIP kroz Plugins > Add New > Upload Plugin.
2. Aktivirajte plugin uz aktivan WooCommerce.
3. Otvorite WooCommerce > Price Compliance HR.
4. Provjerite globalni referentni datum, početak prikaza i eventualna pravila kategorija.
5. Na Pregledu pokrenite "Skeniraj katalog sada".
6. Izgradite snapshotove i pregledajte sve stavke sa statusom fallback.

== CSV import referentnih cijena ==

Obavezna zaglavlja:
- sku ILI object_id
- reference_date (YYYY-MM-DD)
- price (bruto cijena)

Opcionalno:
- notes

Importirani snapshot je zaključan i ima prednost pred automatskim fallbackom.

== Važno ==

Plugin ne izmišlja povijest prije instalacije. Ako nema podatka za referentni datum, prema postavci može koristiti najstariji raspoloživi podatak, trenutnu regularnu cijenu ili ne prikazati cijenu. Fallback je vidljivo označen u administraciji.

Za 30-dnevni minimum plugin koristi samo stvarno evidentirane cijene. Ako nema nijednog podatka prije početka akcije, ne prikazuje izmišljenu "najnižu cijenu".

Ovaj plugin je tehnički alat i ne predstavlja pravni savjet. Pravila prikaza i klasifikaciju proizvoda treba uskladiti s važećim propisima i uputama nadležnih tijela.

== Digitalni cjenik 2.0 ==

Otvorite WooCommerce > Price Compliance HR > Digitalni cjenik. Unesite oblik,
adresu i oznaku prodajnog objekta, mapirajte polja, prihvatite pravnu napomenu
te uključite modul. Provjerite upozorenja za nepotpune/neverificirane podatke.
CSV je jedini format ove verzije; XML nije potreban za korištenje CSV izvoza.

Javna stranica: /cjenik/
Stalna adresa: /cjenik/aktualni.csv
Javni API: /wp-json/cwpc/v1/cjenik?limit=100&after=0
Za obične permalinkove koristite poveznice prikazane u backendu.

Aktualni /cjenik/aktualni.csv automatski se zamjenjuje nakon relevantnih
izmjena proizvoda, varijacija, cijena, akcija, zalihe i referentnih cijena.
Te promjene više ne stvaraju novu arhivsku datoteku.

Arhiva dobiva najviše jednu automatsku dnevnu objavu. Zadano vrijeme je 06:30
Europe/Zagreb, a backend upozorava ako dnevna arhiva nije potvrđena prije roka.
Broj pohrane raste samo kod nove arhivske objave. Postojeće arhive iz starijih
verzija ne brišu se prije isteka postavljenog roka zadržavanja (najmanje 30 dana).

Raspored može koristiti automatski način, samo WP-Cron ili samo server cron /
WP-CLI. U načinu "samo server cron" plugin uklanja vlastiti CWPC WP-Cron hook.
Primjer dnevnog server crona u 07:00:
0 7 * * * /usr/local/bin/wp --path=/putanja/do/wordpressa cwpc cjenik --tick

Gumb "Osvježi aktualni cjenik" osvježava aktualni CSV. Gumb "Objavi današnji
arhivski cjenik" osigurava današnju arhivsku objavu, ali ne radi duplikat ako
arhiva za isti datum već postoji.

Javni API čita najviše 100 stavki u jednom zahtjevu i ne generira datoteke.
Isključite page/CDN cache za javne adrese modula.

== Pravna napomena i odgovornost ==

Plugin je tehnički alat i ne predstavlja pravni savjet niti jamstvo potpune
zakonske usklađenosti. Korištenjem plugina korisnik prihvaća odgovornost za
točnost i verifikaciju podataka, konfiguraciju, pravodobnu objavu cjenika,
nadzor rada i usklađenost svojeg poslovanja s važećim propisima. Propisi se
mogu mijenjati. U najvećem opsegu dopuštenom mjerodavnim pravom isključuje se
odgovornost razvojnog programera za štetu povezanu s propustima u usklađivanju,
netočnim podacima, konfiguracijom ili radom infrastrukture. Ova napomena ne
isključuje niti ograničava odgovornost koju prema prisilnim propisima nije
dopušteno isključiti ili ograničiti.

Potvrda prihvata evidentira se uz uključivanje novog digitalnog cjenika.
Nadogradnja ne gasi postojeći prikaz cijena ako potvrda još nije dana.
Konačnu ugovornu formulaciju ograničenja odgovornosti treba pravno provjeriti
za konkretan način distribucije i ugovaranja plugina.

== Changelog ==

= 2.1.1 =
* Isključivanje modula Digitalni cjenik sada gasi CWPC javnu stranicu /cjenik/, aktualni CSV, arhivske rute i javni REST API.
* Postojeće CSV datoteke, arhiva i stanje ne brišu se pri isključivanju te ostaju spremni za eventualno ponovno uključivanje.
* Shortcode [cwpc_cjenik] ne izlaže cjenik dok je modul isključen.
* Cron, povijest cijena, 30-dnevni minimum, referentne cijene i frontend prikaz nisu mijenjani.

= 2.1.0 =
* Aktualni CSV odvojen je od arhive: /cjenik/aktualni.csv se zamjenjuje nakon relevantnih promjena bez stvaranja nove arhivske datoteke.
* Najviše jedna automatska arhivska objava dnevno; postojeća dnevna arhiva se ne duplicira.
* Zadržan je vidljiv datum/vrijeme jutarnje arhivske objave i upozorenje ako je objavljena nakon 08:00 ili nedostaje.
* Dodani načini rasporeda: Automatski, samo WP-Cron i samo server cron/WP-CLI.
* U načinu samo server cron CWPC uklanja svoj vlastiti WP-Cron raspored.
* "Osvježi aktualni cjenik" i "Objavi današnji arhivski cjenik" razdvojeni su u backendu.
* Broj pohrane raste samo kod arhivskih objava.
* Nadogradnja zadržava sve postojeće arhive; aktualni CSV dobiva novu stabilnu fizičku datoteku pri prvom osvježavanju.

= 2.0.0 =
* Potpuno uklonjeno JS premještanje u katalogu; OceanWP native hook i PHP WooCommerce fallback.
* Podesiva veličina teksta u katalogu (zadano 0.7em) i prioritet fallback hooka.
* Digitalni CSV cjenik, javna stranica, stabilna adresa i paginirani javni API.
* Dnevna objava u Europe/Zagreb, arhiva svake objave najmanje 30 dana, WP-CLI naredba.
* Provjera zaliha i zakazanih akcija; mapiranje marki, barkodova, jedinica i posebnih oblika prodaje.
* Generiranje uz zaključavanje, privremenu datoteku i atomsku objavu; detekcija konkurentnih promjena.
* Bruto iznosi prema poreznoj osnovici trgovine, ne prema prijavljenom kupcu.
* Backend status, upozorenja za nepotpune podatke i pravna napomena s evidentiranim prihvatom.
* Stroža CSV validacija, zaštita od formula u tabličnim aplikacijama i obvezan POST za izmjene.
* Čuvaju se postojeća povijest, postavke, kategorijska pravila i zaključane referentne cijene.


= 1.6.0 =
* OceanWP integracija: shop/category compliance podaci ispisuju se direktno preko `ocean_after_archive_product_price`, odmah nakon cijene.
* Na OceanWP-u više nema JavaScript premještanja compliance bloka, pa nema vidljivog skoka na sporijem serveru ili uređaju.
* Za ostale teme zadržan je univerzalni WooCommerce fallback s postojećim naknadnim pozicioniranjem.
* Single-product prikaz i logika izračuna cijena nisu mijenjani.

= 1.0.5 =
* Ispravljen položaj compliance teksta u shop/category karticama za teme koje preslaguju standardne WooCommerce loop hookove.
* Podaci se vežu uz HTML cijene, a zatim premještaju neposredno iza `.price` elementa kako bi ostali ispod cijene bez nasljeđivanja njezine boje.
* Zadržan je font-size 0.6em u katalogu; single-product prikaz nije mijenjan.

= 1.0.4 =
* Compliance tekst na shop/category i single-product prikazu koristi običnu naslijeđenu boju teksta teme, umjesto boje glavne cijene.
* Shop/category prikaz sada se ispisuje standardnim WooCommerce hookom odmah nakon cijene, kako ne bi nasljeđivao stil `.price` elementa.
* Veličina teksta u katalogu postavljena je na 0.6em.

= 1.0.3 =
* Prikaz u shop/category petlji vezan je izravno uz HTML cijene, tako da se compliance podaci prikazuju odmah ispod cijene.
* Tekst compliance podataka u katalogu smanjen je na približno 50% veličine glavne cijene.

= 1.0.2 =
* Ispravljen prikaz nepotpunog 30-dnevnog minimuma kada je prvi povijesni zapis nastao tek nakon početka akcije.
* U tom slučaju, samo kada je dopuštena nepotpuna povijest, koristi se redovna cijena zabilježena u prvom akcijskom stanju kao partial fallback.
* Sale schedule datum nije potreban za nescheduled WooCommerce akcije.


= 1.0.1 =
* Security hardening: variation nonce checks, stricter CSV upload/price validation and spreadsheet formula-injection mitigation for exported user-controlled text fields.

= 1.0.0 =
* Prvo izdanje.

== Zakonske iznimke i posebne situacije ==

U pravilima kategorija te na proizvodu/varijaciji moguće je isključiti prikaz 30-dnevnog minimuma. To služi za kategorije ili konkretne proizvode za koje se prema važećim pravilima ne primjenjuje standardni prikaz najniže cijene u 30 dana.

Kod uzastopnog dodatnog snižavanja unutar iste neprekinute akcije plugin zadržava početak originalnog razdoblja sniženja; promjena same akcijske cijene ne resetira 30-dnevni prozor.

Opcija "Dopusti nepotpunu 30-dnevnu povijest" je zadano isključena. Ako nema pune pokrivenosti razdoblja, plugin zadano ne prikazuje tvrdnju o najnižoj cijeni u 30 dana. Povijest se može nadopuniti CSV importom iz pouzdanog izvora.
