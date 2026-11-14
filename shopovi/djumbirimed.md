# Đumbir i med (djumbirimed.hr)

- **Site u Mostu:** `djumbirimed` (poslužitelj 192.168.0.52, IIS, PHP 8.4)
- **WordPress / Avada:** WP 7.1.4, Avada 7.16.4, Fusion Builder 3.16.4
- **Canis:** 2.1.1, instaliran 13. 11. 2026. navečer
- **Systich dodatak:** još nije instaliran (pripremljena verzija 0.2.0)
- **Predmemorija:** WP Super Cache
- **Zadnja provjera:** 14. 11. 2026., samo čitanjem

## Stanje

Trgovina još nije otvorena za javnost: posjetitelj umjesto proizvoda vidi stranicu „Naša trgovina je u izradi”. Katalog ima jedan proizvod, knjigu „Kuharica Poslije posla”, koja je na akciji (29,67 € umjesto 34,90 €). Stranica proizvoda složena je Avadinim Layout Builderom (vidi `Projekti\Djumbirimed-web\shop.py`), pa Canis na njoj neće ništa prikazati bez dodatka.

Format datuma je „F j, Y”, pa bi Canisov datum bio ispisan na engleski način; za hrvatski zapis treba „j. F Y.”. Digitalni cjenik nije uključen.

## Sidrene cijene

Za knjigu nema podatka o cijeni na referentni dan 10. 9. 2026. Ako knjiga tada nije bila u prodaji, sidrena cijena za nju nije potrebna; to treba provjeriti kod klijenta. Najniža cijena u 30 dana prije akcije neće se prikazivati dok Canis ne skupi 30 dana povijesti, jer je akcija počela prije instalacije.
