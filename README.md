# Farmerkop – evidencija proizvodnje, prodaje i zaliha

Web aplikacija (PWA) za firmu Farmerkop d.o.o. (zemlja za cveće, malč, dekorativni oblutak).
**PHP 8 + MySQL, bez frameworka**, radi na običnom cPanel hostingu (poddomen `app.farmerkop.rs`).

- 📘 **Postavljanje na hosting i korišćenje:** [`UPUTSTVO.md`](UPUTSTVO.md)
- 📦 **Paketi za cPanel:** [`dist/farmerkop-aplikacija.zip`](dist/farmerkop-aplikacija.zip) (prvo postavljanje) i [`dist/farmerkop-azuriranje.zip`](dist/farmerkop-azuriranje.zip) (kasnija ažuriranja, ne dira `config.php`)

## Šta aplikacija radi

| Ko | Šta |
|---|---|
| **Radnik** (ime + PIN od 4 cifre) | **Utovar** (štiklira i potvrđuje spiskove za utovar), **Proizvodnja** i **Kućna prodaja**: artikal → boja/granulacija → pakovanje → komadi, paketi ili palete (−10/−1/+1/+10). Vidi samo svoje današnje unose; svoj poslednji unos može da obriše u roku od 10 min. Ne vidi stanje. |
| **Administrator** (šifra) | **Stanje** (crveno ispod minimuma, rezervisano/slobodno), **Utovar** (spiskovi za utovar: jedan kamion, više mesta istovara, izbor artikla kucanjem („humovit“ → 5 / 10 / 25 / 50 l), više artikala odjednom, zbirne palete, **spisak sa papira** (prepisan ili izdiktiran tekst → mesta i roba); ručna prodaja za izuzetke), **Istorija** (filteri, ispravka/brisanje sa dnevnikom, CSV za Excel), **Radnici**, **Podešavanja** (artikli, boje/granulacije, pakovanja, komada u paketu i po paleti, minimum, popis u paletama/paketima/komadima, **uvoz stanja iz CSV-a / nalepljene tabele**, šifra) |

## Struktura

```
app/                  ← ceo sadržaj ovog foldera ide u koren poddomena
  index.php login.php logout.php install.php config.php
  radnik/             proizvodnja, kućna prodaja, utovar (potvrda spiska)
  admin/              stanje, prodaja, istorija, unos (ispravka), izvoz (CSV), dnevnik,
                      radnici, podesavanja, artikli, artikal, pakovanja, popis, uvoz, sifra, utovar, spisak,
                      spisak_stampa (štampa / PDF / deljenje), spisak_papir (spisak sa papira)
  inc/                bootstrap, db, auth, csrf, session, katalog, unosi, istorija,
                      podesavanja, izbor, layout, schema, migracije, uvoz, spiskovi (zaštićeno .htaccess-om)
  assets/             css, js, ikone;  sw.js, manifest.webmanifest, offline.html
tests/                HTTP testovi (Python, nad pravom MariaDB), Apache test, snimci ekrana
tools/                napravi_zip.sh, napravi_ikone.php
dist/                 gotovi ZIP paketi
```

## Model podataka (važno za nove funkcije)

- **SKU** = artikal + (boja/granulacija) + pakovanje. Stanje, minimum i broj komada po paleti vezani su za SKU
  (isti artikal u istoj litraži može imati drugačiju paletu, npr. Idea 10 l = 225, Humovit 10 l = 270).
- Sve količine se čuvaju u **komadima**. **Paleta i paket su samo način unosa** (palete × `po_paleti`, paketi × `po_paketu`;
  `unosi.palete` / `unosi.paketi` pamte šta je radnik uneo).
- Tabela `unosi` je jedini izvor istine: `proizvodnja`, `prodaja`, `kucna_prodaja`, `korekcija`,
  `utovar` (po spisku) i `povrat` (otkazan utovar). **Stanje = zbir** (prodaja i utovar oduzimaju, `TIPOVI_MINUS`). Brisanje je meko (`obrisan = 1`), a svaka izmena/brisanje ide u `dnevnik`.
- Stanje ne sme pasti ispod nule (izuzetak: potvrđen utovar – roba je već na kamionu, pa minus znači da treba popis); promena SKU-a zaključava njegov red (`SELECT … FOR UPDATE`),
  pa dva istovremena unosa ne mogu da probiju stanje (vidi `inc/unosi.php`).
- Artikli, varijante, pakovanja i kategorije se **ne brišu**, samo isključuju.
- **Šema baze se nadograđuje sama:** `inc/migracije.php` (`SCHEMA_VERZIJA`, `podesavanja.schema_verzija`) se izvršava pri prvom zahtevu
  posle ažuriranja, pod `GET_LOCK`. Nova promena šeme = nova `migracija_N()` + povećan broj verzije + isto u `schema.php` za nove instalacije.
- **Spisak za utovar** (`inc/spiskovi.php`): `spiskovi` (jedan kamion), `spisak_mesta` (mesta istovara: broj koji upisuje vlasnik,
  kupac nije obavezan, redosled), `spisak_stavke` (`mesto_id`; artikal ili „ostalo“; `paleta_id` > 0 = stavka zbirne palete,
  količina = na jednoj paleti × `spisak_palete.broj`), `spisak_palete` (`mesto_id`). Stanje se skida tek potvrdom utovara
  (`spisak_potvrdi_utovar`), na kupca mesta.
- **Izbor artikla kucanjem** (`inc/katalog.php`): `sku_pronadji()` – svaka otkucana reč mora biti u nazivu (bilo kojim redom,
  početak reči, koren od 4 slova, milimetri „40-60“ = 4-6 cm), ocena bira najbolje; `sku_iz_unosa()` uzima izabrani id ili
  jedino najbolje poklapanje. Isto računa `assets/js/spisak.js`, koji ispod polja (`polje_artikla()`) nudi pakovanja dodirom.
  Na spisku i svuda se piše pun naziv (`sku_naziv()`); kratka imena (`sku.sifra`, v7–v8) uklonjena su u v9.
- **Unos robe** (`inc/spisak_izmene.php`): više artikala odjednom (`roba_iz_posta()` → `spisak_upisi_robu()`), zbirna paleta
  (`zbirna_redovi()` → `spisak_nova_zbirna()`), sve u jednoj transakciji (`u_nacrtu()`).
- **Slikaj spisak** (`inc/slika_spiska.php`, `assets/js/slika_spiska.js`): slika (u pregledaču smanjena na 2000 px, na serveru
  GD kao rezerva) → Anthropic Messages API (`ANTHROPIC_API_KEY` u config.php, model `claude-opus-5-5`, curl bez SDK-a jer se
  aplikacija postavlja ZIP-om bez Composer-a) → tekst → `papir_procitaj()`. Slika se ne čuva.
- **Kupci po godini** (`admin/kupci.php`): zbir unosa prodaja/kućna prodaja/utovar − povrat po kupcu i SKU za godinu, CSV.
  Brisanje otkazanog spiska: `spisak_obrisi()` (utovar/povrat → obrisan = 1, spisak_id = NULL; mora da se poništava).
- **Kalkulacija** (Faza 1; `inc/kalkulacija.php` = formula bez baze, `inc/kalk_baza.php` = čitanje važećih cena,
  `admin/kalk*.php` = ekrani, meni ☰ u zaglavlju samo za admina): tabele `calc_*` iz RUČNE migracije
  `migracije/001_kalkulacija.sql` (nije u `inc/migracije.php`; aplikacija bez nje pokazuje uputstvo). Veze: recepture →
  `artikli`, prodajne cene → `sku`, troškovi po veličini → `pakovanja`. Istorija cena = novi red sa `vazi_od`; važi poslednji
  ≤ danas. Faza 2 (skidanje sirovina pri proizvodnji) se vezuje na `calc_recipes.id`.
- **Spisak sa papira** (`inc/spisak_papir.php`, `admin/spisak_papir.php`): tekst → mesta (8, (8), Mesto 8, ⑧) i roba
  (2 pal, 3 pak, 30 kom, 2 t, 1/2, „dve palete“, „a + b“ = zbirna paleta) → pregled sa izborom pakovanja → `papir_proveri()` →
  sve se upisuje odjednom.
- **Uvoz stanja** (`inc/uvoz.php`, `admin/uvoz.php`): čita CSV/tabelu, uparuje redove sa SKU-ovima po normalizovanim rečima
  (latinica/ćirilica, pakovanje, granulacija, boja), pamti ručna uparivanja u `uvoz_mapiranje`, a upisuje preko `popis_postavi()`.

## Bezbednost

Prepared statements svuda · `password_hash` za šifre i PIN-ove · CSRF žeton na svakoj formi ·
zaključavanje naloga posle 5 pogrešnih pokušaja (sve duže) + ograničenje po IP · sesije u bazi sa istekom po neaktivnosti ·
CSP, X-Frame-Options i ostala zaglavlja · escape svakog ispisa · zaštita CSV-a od Excel formula · instalacioni ključ i samobrisanje `install.php`.

## Testovi

Potrebni su PHP 8 (CLI + cgi), MariaDB/MySQL, Python 3, Node + Playwright (za ekrane), Apache (za test isporuke).

```sh
python3 tests/test_01_osnova.py            # instalacija, prijava, zaključavanje, sesija, zaglavlja
python3 tests/test_02_radnik.py            # proizvodnja, kućna prodaja, palete, brisanje u roku
python3 tests/test_03_admin_stanje_prodaja.py
python3 tests/test_04_istorija.py          # filteri, ispravka, dnevnik, CSV
python3 tests/test_05_podesavanja.py       # radnici, artikli, pakovanja, popis, šifra
python3 tests/test_07_paketi.py            # paketi, popis u pal/pak/kom, zaštite artikala
python3 tests/test_08_nadogradnja.py       # nadogradnja stare baze (v1 → v2) bez gubitka podataka
python3 tests/test_09_uvoz.py              # uvoz stanja: CSV, uparivanje, jedinice, zaštite, pamćenje
python3 tests/test_10_utovar.py            # spisak za utovar: šema v3, novi artikli, težina, utovar/povrat, rezervisano
python3 tests/test_11_spisak.py            # spisak za utovar (admin): stavke, ostalo, palete/kg, upozorenja, statusi
python3 tests/test_12_potvrda_utovara.py   # potvrda utovara: atomično, dupli klik, 4 telefona, minus, povrat, štampa,
                                           # utovar na licu mesta (više od plana, radnik dodaje artikal)
python3 tests/test_13_zbirna_paleta.py     # zbirna paleta: više artikala na paleti, broj paleta, radnik, štampa, v5 → v7
python3 tests/test_14_mesta.py             # mesta istovara: više kupaca na kamionu, pomeranje, izmene, radnik, štampa, v6 → v7
python3 tests/test_15_unos_robe.py         # v8 → v9, izbor pakovanja, više artikala odjednom, spisak sa papira
python3 tests/test_16_kalkulacija.py       # kalkulacija: ručna migracija, samo admin, cene, recepture, pregled, šta ako, Stanje isto
php tests/kalkulacija_test.php             # formula bez baze (60/40, faktor ≠ 1, nedostaje cena, zbir 100 %)
python3 tests/test_17_slika_spiska.py      # slikaj spisak: zahtev AI servisu (lažni server), smanjenje slike, greške
python3 tests/test_18_kupci_brisanje.py    # kupci po godini (prodaja + utovar − povrat, CSV), brisanje otkazanog spiska
python3 tests/test_19_humovit_stara_ambalaza.py  # v10: Humovit S (stara ambalaža) 25/50 l, unos, stanje, „humovit s 50“
sh tools/napravi_zip.sh && python3 tests/test_06_apache.py   # ZIP pod pravim Apache serverom (.htaccess)
python3 tests/ekrani.py IZLAZ              # tokovi u pravom pregledaču + snimci (svetli/tamni režim), PWA provera
```

Testovi koriste bazu `farmerkop_test` (korisnik `fk`, lozinka `fk_test_pass`) i na početku je brišu.

## Kako dodati novu funkciju

1. Nova stranica u `admin/` ili `radnik/`; na početku `require inc/bootstrap.php` i `zahtevaj_ulogu('admin'|'radnik')`.
2. Svaka POST obrada: `csrf_proveri()`, pa obrada, pa `preusmeri()` (obrazac post → redirect).
3. SQL samo preko `db_all/db_one/db_val/db_run` sa `?` parametrima; ispis samo preko `e()`.
4. Menjate bazu? Dodajte `CREATE/ALTER` u `inc/schema.php` (nova instalacija) i novu `migracija_N()` u `inc/migracije.php` (postojeća baza); `install.php` se posle instalacije briše.
5. Nova stavka u donjoj navigaciji: `nav_stavke()` u `inc/layout.php`.
