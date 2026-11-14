# Botun (botun.hr)

- **Site u Mostu:** `botun` (vanjski poslužitelj, LiteSpeed)
- **WordPress / Avada:** WP 7.1.4, Avada 7.16.4, Fusion Builder 3.16.4
- **Canis:** 2.1.1, instaliran 13. 11. 2026. navečer
- **Systich dodatak:** još nije instaliran (pripremljena verzija 0.2.0)
- **Predmemorija:** LiteSpeed Cache 7.9.3, stranice proizvoda čuva 7 dana
- **Jezici:** Polylang i Polylang for WooCommerce (hrvatski i engleski)
- **Zadnja provjera:** 14. 11. 2026., samo čitanjem

## Katalog

Katalog ima 53 proizvoda (46 hrvatskih i 7 engleskih prijevoda), od toga 26 varijabilnih s ukupno 97 varijacija. Na akciji su 4 proizvoda. Asortiman je odjeća i modni dodaci, pa je referentni datum 10. 9. 2026.

Svim proizvodima 2. 10. 2026. promijenjen je URL u brojčani (npr. `/proizvod/301/`, `/proizvod/208-13/`). Stari URL-ovi preusmjeravaju na nove (301), ali brojčani URL-ovi su loši za tražilice. Glavna stranica trgovine `/trgovina/` prikazuje prazan popis proizvoda, dok kategorije (npr. `/kolekcije/haljine/`) rade.

## Prikaz

Stranica proizvoda i kategorije koriste klasične WooCommerce predloške, pa Canis prikazuje blok i na stranici proizvoda i u popisima. Blok sada glasi „Dodatna cijena na dan 10 rujna, 2026: 79,00 €”. Datum izgleda tako jer je format datuma u WordPressu „F j, Y”; za hrvatski zapis treba „j. F Y.”. Isti hrvatski tekst prikazuje se i na engleskim stranicama.

Kao sidrenu cijenu Canis sada prikazuje današnju redovnu cijenu (fallback_current), jer povijest prije instalacije ne postoji.

## Cjenik

Digitalni cjenik nije uključen (`/cjenik/` vraća 404).

## Sidrene cijene

Za 94 od 124 stavke (jednostavni proizvodi i varijacije) Wayback Machine ima snimku iz srpnja ili kolovoza 2026. s istom redovnom cijenom kao danas (razina B), pa je vrlo vjerojatno ista cijena vrijedila i 10. 9. 2026. Za jednu stavku snimka pokazuje drugu cijenu (razina C: Svečana kratka suknja Blackie, 90,00 € u kolovozu, 80,00 € danas), a za 29 stavki nema dokaza (razina D). Datumi zadnje izmjene ne pomažu, jer su svi proizvodi mijenjani 2. 10. 2026. ili kasnije.
