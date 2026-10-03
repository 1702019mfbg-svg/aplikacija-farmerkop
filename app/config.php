<?php
/**
 * FARMERKOP – PODEŠAVANJA
 *
 * Ovde upisujete podatke o bazi koju ste napravili u cPanel-u
 * (cPanel → MySQL® Databases). Ceo postupak je opisan u UPUTSTVO.md.
 *
 * Menjate samo tekst između navodnika. Ne brišite tačku-zarez (;) na kraju reda.
 */

// ─── 1. PODACI O BAZI ───────────────────────────────────────────────────────
// U cPanel-u ime baze i korisnika uvek počinje vašim cPanel nalogom,
// npr. "farmerk_aplikacija" i "farmerk_appuser".
define('DB_HOST', 'localhost');                 // na cPanel-u je gotovo uvek "localhost"
define('DB_NAME', 'UPISITE_IME_BAZE');
define('DB_USER', 'UPISITE_KORISNIKA_BAZE');
define('DB_PASS', 'UPISITE_LOZINKU_BAZE');

// ─── 2. INSTALACIONI KLJUČ ──────────────────────────────────────────────────
// Izmislite bilo koju reč ili broj (najmanje 8 znakova) i upišite je ovde.
// Tu istu reč ćete ukucati na stranici install.php. Tako niko drugi ne može
// da pokrene instalaciju umesto vas dok ne obrišete install.php.
define('INSTALL_KLJUC', 'PROMENI_OVO');

// ─── 3. OSTALO (nije obavezno – podrazumevane vrednosti su već dobre) ───────
// Odkomentarišite red (obrišite // na početku) samo ako želite da promenite.

// define('SESIJA_RADNIK_MIN', 60);     // radnik se odjavljuje posle toliko minuta neaktivnosti
// define('SESIJA_ADMIN_MIN', 30);      // administrator – isto
// define('MAX_POKUSAJA', 5);           // broj pogrešnih pokušaja pre zaključavanja
// define('ZAKLJUCAVANJE_MIN', 10);     // koliko minuta traje prvo zaključavanje (svako sledeće je duplo duže)
// define('BRISANJE_RADNIK_MIN', 10);   // koliko minuta radnik može da obriše svoj poslednji unos
// define('MAX_KOLICINA_UNOS', 10000);  // najveći broj komada u jednom unosu (zaštita od slučajne greške)
// define('MIN_DUZINA_SIFRE', 8);       // najmanja dužina administratorske šifre
// define('DEBUG', false);              // true samo kada tražite grešku – prikazuje tehničke poruke
