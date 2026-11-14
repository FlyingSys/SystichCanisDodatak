# Dvije njuške (dvije-njuske.hr)

- **Site u Mostu:** `dvijenjuske` (vanjski poslužitelj 65.21.91.58, LiteSpeed)
- **WordPress / Avada:** WP 7.1.4, Avada 7.16.2, Fusion Builder 3.16.2
- **Canis:** 2.1.1, instaliran 13. 11. 2026. navečer
- **Systich dodatak:** još nije instaliran (pripremljena verzija 0.2.0)
- **Predmemorija:** LiteSpeed Cache 7.9.3, stranice proizvoda čuva 7 dana
- **Jezici:** Polylang i Polylang for WooCommerce (hrvatski i engleski)
- **Zadnja provjera:** 14. 11. 2026., samo čitanjem

## Katalog

Katalog ima 54 proizvoda (27 hrvatskih i 27 engleskih prijevoda), od toga 38 varijabilnih sa 155 varijacija. Asortiman je oprema za pse (ležajevi, jastuci, ogrlice, torbe), pa je referentni datum 10. 9. 2026. Neki engleski prijevodi imaju drugačiju cijenu od hrvatskog proizvoda (npr. „Dog Bandana” 10,00 €, a „Marama za psa” 12,00 €).

## Prikaz

Stranice koriste klasične WooCommerce predloške, pa Canis prikazuje blok na stranici proizvoda i u popisima. U Canisu je tekst već prilagođen („Cijena na dan 10.09.2026: …”), a kao cijenu prikazuje današnju redovnu cijenu (fallback_current).

## Cjenik

Digitalni cjenik je uključen i objavljen (`/cjenik/`, `/cjenik/aktualni.csv`), s adresom Mandaličina 12, Zagreb i oznakom WEB01. Cjenik od 14. 11. 2026. u 00:19 ima 196 redaka i sljedeće nedostatke:

- 14 redaka (ID 4249–4262) su **siročad**: varijacije čiji je proizvod obrisan. U cjeniku nose naziv „Waterproof cushion” i cijenu 7,30 €. Treba ih ukloniti alatom WooCommerce → Status → Alati → „Izbriši varijacije bez roditelja”.
- Redak 3097 („Portreti vaših kućnih ljubimaca”) nema cijenu.
- Dvije varijacije „Torbice za higijenske vrećice” (5131, 5133) imaju akcijsku cijenu 0,00 €.
- Svaki proizvod u cjeniku je dva puta, jednom pod hrvatskim i jednom pod engleskim nazivom, jer su prijevodi zasebni proizvodi.
- Šifra je upisana samo za 16 redaka, a marka i barkod nisu ni za jedan.
- Sve sidrene cijene imaju status fallback_current (neprovjerene).

## Sidrene cijene

Za 159 od 171 stavke proizvod nije mijenjan od prije 10. 9. 2026., pa je današnja redovna cijena vrijedila i na referentni dan (razina A). Za još 6 stavki Wayback Machine ima snimku s istom cijenom (razina B). Šest varijacija „Kragnice za pse” objavljeno je nakon referentnog datuma (razina N).
