# Agroprodaja (agroprodaja.com)

- **Site u Mostu:** `agroprodaja` (poslužitelj 192.168.0.52, IIS, PHP 8.4)
- **WordPress / Avada:** WP 7.1.4, Avada 7.16.4, Fusion Builder 3.16.4
- **Canis:** 2.1.1, instaliran 13. 11. 2026. navečer
- **Systich dodatak:** još nije instaliran (pripremljena verzija 0.2.0)
- **Predmemorija:** WP Super Cache
- **Zadnja provjera:** 14. 11. 2026., samo čitanjem (javni Store API, javne stranice, Most)

## Katalog

Katalog ima 21.972 jednostavna proizvoda, bez varijacija. Nijedan proizvod nije na akciji, pa se najniža cijena u 30 dana zasad nigdje ne prikazuje. Za 358 proizvoda cijena je 0,00 €, a nazivi su im većinom na poljskom (npr. „Złączka prosta wtykowa…”). Ti bi proizvodi u cjeniku bili objavljeni s cijenom 0,00 €, pa treba odlučiti trebaju li uopće biti objavljeni.

Proizvodi su razvrstani u dva usporedna stabla kategorija (npr. „TEGER > Kemija” i „Kemija”), pa isti proizvod često ima dvije kategorije istog imena.

## Prikaz

Stranica proizvoda i popisi proizvoda složeni su Avadinim Layout Builderom i elementima Post Cards. Avada tada ne poziva hookove na koje se Canis veže, pa Canis **ne prikazuje ništa** ni na stranici proizvoda ni u kategorijama. To rješava prikaz u Avadinim predlošcima u dodatku 0.2.0.

Na shopu je aktivan i stariji dodatak `woocommerce-lowest-price-main` (najniža cijena prije akcije). Kako nijedan proizvod nije na akciji, zasad se ne vidi. Kad Canis proradi, stari dodatak treba isključiti, jer bi se uz akcije najniža cijena prikazivala dva puta.

## Cjenik

Digitalni cjenik u Canisu je uključen, ali **nije objavljen**: `/cjenik/aktualni.csv` vraća 503. Canis pri izradi iz web zahtjeva prekida nakon 20 sekundi, a katalog od 22.000 proizvoda u to ne stane. Cjenik se zato mora izrađivati naredbom `wp cwpc cjenik --tick` iz zakazanog zadatka na poslužitelju (Windows Task Scheduler, svakih 5 minuta), a u Canisu način rasporeda treba ostaviti na „Automatski” ili prebaciti na „samo server cron”.

## Praćenje cijena

Cijene se mijenjaju svaki dan. Vlastiti dodatak oko 03:00 (WP cron `ap_api_refresh`, dnevno) preuzima podatke dobavljača Rol-Mar u tablicu `ap_api_data` i upisuje cijene izravno u bazu, bez promjene datuma izmjene proizvoda. Redovna cijena jednaka je dobavljačevoj cijeni u eurima pomnoženoj faktorom marže (najčešće 1,75, 2, 2,25, 2,5, 3,125 ili 5). Dobavljač cijene vodi u zlotima i preračunava ih srednjim tečajem NBP-a od prethodnog dana, pa se svaki dan pomakne cijena za trećinu kataloga (13. na 14. 11. za 7.274 proizvoda, za oko 0,12 %).

Canisovo kontrolno skeniranje obrađuje 300 proizvoda na sat, pa jedan prolaz kroz katalog traje oko 73 sata, a za to se vrijeme cijena promijeni tri puta. Bez bržeg praćenja iz dodatka 0.2.0 Canisova povijest cijena ne bi bila točna.

## Sidrene cijene

Referentni datum za ovaj asortiman je 10. 9. 2026. Proizvodi u kategorijama Kemija i Sredstva za čišćenje možda spadaju u skupinu s datumom 2. 5. 2025. (proizvodi za kućanstvo); to treba odlučiti.

Noćni prijedlog prema datumu zadnje izmjene bio je pogrešan, jer uvoz ne mijenja taj datum. Sidrene cijene izračunate su iz backupa baze od 11. 9. 2026. (23:13), u kojem su cijene po tečaju NBP-a od 10. 9. (4,3180). Na 10. 9. vrijedio je tečaj 4,3171, pa su cijene proizvoda koji prate tečaj preračunate preko cijene u zlotima i provjerene prema cijenama od 13. i 14. 11. Postupak i brojke opisani su u izvještaju (odjeljak *Agroprodaja: sidrene cijene iz backupa baze*), a za svaki proizvod u datoteci `agroprodaja_sidrene_iz_backupa_pregled.csv`.

- 17.544 proizvoda imaju jednoznačnu sidrenu cijenu (razina R), od toga je 1.643 ispravljeno za tečaj;
- 3.724 proizvoda imaju dvije moguće vrijednosti zbog zaokruživanja kod dobavljača (razina R~);
- 354 proizvoda u backupu nemaju cijenu ili imaju 0,00 €, 348 ih je objavljeno nakon backupa, a 2 su mijenjana u administraciji 11. 9.

Za uvoz u Canis pripremljeno je 21.268 cijena u 11 datoteka `agroprodaja_canis_uvoz_iz_backupa_NN.csv`.

## Cijena za jedinicu mjere

U kategorijama Kemija (11117, 11120), Kemikalije (9152), Sredstva za čišćenje (8914) i Ulje (10232) ima 180 proizvoda koji se prodaju po volumenu ili masi (sprejevi, sredstva za čišćenje, ulja, masti). Za 174 proizvoda količina je prepoznata iz naziva (143 u litrama, 31 u kilogramima), a 6 treba upisati ručno. U ostatku kataloga oznake poput „12L” (serija hidrauličke spojnice) ili „800 g” (masa čekića) nisu neto količina, pa prepoznavanje iz naziva smije raditi samo u navedenim kategorijama.
