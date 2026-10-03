# Farmerkop – evidencija proizvodnje, prodaje i zaliha

Web aplikacija (PWA) za firmu Farmerkop d.o.o. (zemlja za cveće, malč, dekorativni oblutak).
**PHP 8 + MySQL, bez frameworka**, radi na običnom cPanel hostingu (poddomen `app.farmerkop.rs`).

- 📘 **Postavljanje na hosting i korišćenje:** [`UPUTSTVO.md`](UPUTSTVO.md)
- 📦 **Paketi za cPanel:** [`dist/farmerkop-aplikacija.zip`](dist/farmerkop-aplikacija.zip) (prvo postavljanje) i [`dist/farmerkop-azuriranje.zip`](dist/farmerkop-azuriranje.zip) (kasnija ažuriranja, ne dira `config.php`)

## Šta aplikacija radi

| Ko | Šta |
|---|---|
| **Radnik** (ime + PIN od 4 cifre) | **Proizvodnja** i **Kućna prodaja**: artikal → boja/granulacija → pakovanje → komadi ili palete (−10/−1/+1/+10). Vidi samo svoje današnje unose; svoj poslednji unos može da obriše u roku od 10 min. Ne vidi stanje. |
| **Administrator** (šifra) | **Stanje** (crveno ispod minimuma), **Prodaja** (kupci, zabrana prodaje preko stanja), **Istorija** (filteri, ispravka/brisanje sa dnevnikom, CSV za Excel), **Radnici**, **Podešavanja** (artikli, boje/granulacije, pakovanja, paleta, minimum, popis, šifra) |

## Struktura

```
app/                  ← ceo sadržaj ovog foldera ide u koren poddomena
  index.php login.php logout.php install.php config.php
  radnik/             proizvodnja, kućna prodaja
  admin/              stanje, prodaja, istorija, unos (ispravka), izvoz (CSV), dnevnik,
                      radnici, podesavanja, artikli, artikal, pakovanja, popis, sifra
  inc/                bootstrap, db, auth, csrf, session, katalog, unosi, istorija,
                      podesavanja, izbor, layout, schema (zaštićeno .htaccess-om)
  assets/             css, js, ikone;  sw.js, manifest.webmanifest, offline.html
tests/                HTTP testovi (Python, nad pravom MariaDB), Apache test, snimci ekrana
tools/                napravi_zip.sh, napravi_ikone.php
dist/                 gotovi ZIP paketi
```

## Model podataka (važno za nove funkcije)

- **SKU** = artikal + (boja/granulacija) + pakovanje. Stanje, minimum i broj komada po paleti vezani su za SKU
  (isti artikal u istoj litraži može imati drugačiju paletu, npr. Idea 10 l = 225, Humovit 10 l = 270).
- Sve količine se čuvaju u **komadima**. **Paleta je samo način unosa** (palete × komada po paleti).
- Tabela `unosi` je jedini izvor istine: `proizvodnja`, `prodaja`, `kucna_prodaja`, `korekcija`.
  **Stanje = zbir** (prodaja oduzima). Brisanje je meko (`obrisan = 1`), a svaka izmena/brisanje ide u `dnevnik`.
- Stanje nikad ne sme pasti ispod nule; promena SKU-a zaključava njegov red (`SELECT … FOR UPDATE`),
  pa dva istovremena unosa ne mogu da probiju stanje (vidi `inc/unosi.php`).
- Artikli, varijante, pakovanja i kategorije se **ne brišu**, samo isključuju.

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
sh tools/napravi_zip.sh && python3 tests/test_06_apache.py   # ZIP pod pravim Apache serverom (.htaccess)
python3 tests/ekrani.py IZLAZ              # tokovi u pravom pregledaču + snimci (svetli/tamni režim), PWA provera
```

Testovi koriste bazu `farmerkop_test` (korisnik `fk`, lozinka `fk_test_pass`) i na početku je brišu.

## Kako dodati novu funkciju

1. Nova stranica u `admin/` ili `radnik/`; na početku `require inc/bootstrap.php` i `zahtevaj_ulogu('admin'|'radnik')`.
2. Svaka POST obrada: `csrf_proveri()`, pa obrada, pa `preusmeri()` (obrazac post → redirect).
3. SQL samo preko `db_all/db_one/db_val/db_run` sa `?` parametrima; ispis samo preko `e()`.
4. Menjate bazu? Dodajte `CREATE/ALTER` u `inc/schema.php` i napišite stranicu za nadogradnju postojeće baze
   (`install.php` se posle instalacije briše).
5. Nova stavka u donjoj navigaciji: `nav_stavke()` u `inc/layout.php`.
