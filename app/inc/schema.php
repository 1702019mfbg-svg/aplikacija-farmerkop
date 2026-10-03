<?php
/**
 * Šema baze i početni katalog Farmerkopa. Koristi je samo install.php.
 */
declare(strict_types=1);

const SCHEMA_VERZIJA = 1;

function schema_sql(): array
{
    $tabela = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        // Korisnici: administrator (korisničko ime + šifra) i radnici (ime + PIN).
        "CREATE TABLE IF NOT EXISTS korisnici (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            uloga ENUM('admin','radnik') NOT NULL,
            ime VARCHAR(60) NOT NULL,
            korisnicko_ime VARCHAR(40) NULL DEFAULT NULL,
            hes VARCHAR(255) NOT NULL,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            neuspesni_pokusaji INT UNSIGNED NOT NULL DEFAULT 0,
            zakljucan_do DATETIME NULL DEFAULT NULL,
            poslednja_prijava DATETIME NULL DEFAULT NULL,
            napravljen DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_korisnici_ime (ime),
            UNIQUE KEY uq_korisnici_korisnicko (korisnicko_ime)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS sesije (
            id VARCHAR(128) NOT NULL,
            podaci MEDIUMBLOB NOT NULL,
            poslednje INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY idx_sesije_poslednje (poslednje)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS neuspele_prijave (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            vreme DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_neuspele_ip (ip, vreme)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS podesavanja (
            kljuc VARCHAR(50) NOT NULL,
            vrednost TEXT NULL,
            PRIMARY KEY (kljuc)
        ) $tabela",

        // Katalog
        "CREATE TABLE IF NOT EXISTS kategorije (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            naziv VARCHAR(80) NOT NULL,
            redosled INT NOT NULL DEFAULT 0,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS artikli (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kategorija_id INT UNSIGNED NOT NULL,
            naziv VARCHAR(100) NOT NULL,
            oznaka VARCHAR(20) NULL DEFAULT NULL,
            naziv_varijante VARCHAR(30) NULL DEFAULT NULL,
            redosled INT NOT NULL DEFAULT 0,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_artikli_kategorija (kategorija_id),
            CONSTRAINT fk_artikli_kategorija FOREIGN KEY (kategorija_id) REFERENCES kategorije (id)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS varijante (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            artikal_id INT UNSIGNED NOT NULL,
            naziv VARCHAR(60) NOT NULL,
            redosled INT NOT NULL DEFAULT 0,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_varijante_artikal (artikal_id),
            CONSTRAINT fk_varijante_artikal FOREIGN KEY (artikal_id) REFERENCES artikli (id)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS pakovanja (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kolicina DECIMAL(8,2) NOT NULL,
            jedinica ENUM('l','kg') NOT NULL,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pakovanja (kolicina, jedinica)
        ) $tabela",

        // SKU = artikal + varijanta (0 = nema) + pakovanje; ovde je i minimum zalihe i broj komada po paleti.
        "CREATE TABLE IF NOT EXISTS sku (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            artikal_id INT UNSIGNED NOT NULL,
            varijanta_id INT UNSIGNED NOT NULL DEFAULT 0,
            pakovanje_id INT UNSIGNED NOT NULL,
            po_paleti SMALLINT UNSIGNED NULL DEFAULT NULL,
            min_zaliha INT UNSIGNED NOT NULL DEFAULT 0,
            aktivan TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sku (artikal_id, varijanta_id, pakovanje_id),
            KEY idx_sku_pakovanje (pakovanje_id),
            CONSTRAINT fk_sku_artikal FOREIGN KEY (artikal_id) REFERENCES artikli (id),
            CONSTRAINT fk_sku_pakovanje FOREIGN KEY (pakovanje_id) REFERENCES pakovanja (id)
        ) $tabela",

        "CREATE TABLE IF NOT EXISTS kupci (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            naziv VARCHAR(120) NOT NULL,
            poslednja_prodaja DATETIME NULL DEFAULT NULL,
            napravljen DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_kupci_naziv (naziv)
        ) $tabela",

        // Svi unosi: proizvodnja, prodaja, kućna prodaja (radnik) i korekcija stanja (popis).
        // kolicina je u komadima; kod korekcije može biti negativna. "palete" je samo podatak
        // da je unos urađen preko paleta (kolicina je već preračunata u komade).
        "CREATE TABLE IF NOT EXISTS unosi (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tip ENUM('proizvodnja','prodaja','kucna_prodaja','korekcija') NOT NULL,
            sku_id INT UNSIGNED NOT NULL,
            kolicina INT NOT NULL,
            palete INT UNSIGNED NULL DEFAULT NULL,
            korisnik_id INT UNSIGNED NOT NULL,
            kupac_id INT UNSIGNED NULL DEFAULT NULL,
            napomena VARCHAR(255) NULL DEFAULT NULL,
            nastalo DATETIME NOT NULL,
            uneto DATETIME NOT NULL,
            kljuc CHAR(32) NULL DEFAULT NULL,
            obrisan TINYINT(1) NOT NULL DEFAULT 0,
            obrisan_u DATETIME NULL DEFAULT NULL,
            obrisao_id INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_unosi_kljuc (kljuc),
            KEY idx_unosi_sku (sku_id, obrisan),
            KEY idx_unosi_tip (tip, nastalo),
            KEY idx_unosi_korisnik (korisnik_id, nastalo),
            KEY idx_unosi_nastalo (nastalo),
            KEY idx_unosi_kupac (kupac_id),
            CONSTRAINT fk_unosi_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
            CONSTRAINT fk_unosi_korisnik FOREIGN KEY (korisnik_id) REFERENCES korisnici (id),
            CONSTRAINT fk_unosi_kupac FOREIGN KEY (kupac_id) REFERENCES kupci (id)
        ) $tabela",

        // Dnevnik izmena: ko je, kad i šta promenio ili obrisao.
        "CREATE TABLE IF NOT EXISTS dnevnik (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            korisnik_id INT UNSIGNED NULL DEFAULT NULL,
            vreme DATETIME NOT NULL,
            akcija VARCHAR(40) NOT NULL,
            objekat VARCHAR(40) NOT NULL,
            objekat_id BIGINT UNSIGNED NULL DEFAULT NULL,
            detalji TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_dnevnik_objekat (objekat, objekat_id),
            KEY idx_dnevnik_vreme (vreme)
        ) $tabela",
    ];
}

/**
 * Početni katalog: kategorija => spisak artikala.
 * pak = [količina, jedinica, komada po paleti]; var = varijante (boje / granulacije).
 */
function pocetni_katalog(): array
{
    return [
        'Zemlja za cveće' => [
            ['naziv' => 'Humovit', 'pak' => [[5, 'l', 450], [10, 'l', 270], [25, 'l', 120], [50, 'l', 40]]],
            ['naziv' => 'Humovit premium', 'pak' => [[20, 'l', 120], [50, 'l', 40]]],
            ['naziv' => 'Floris Savacoop', 'oznaka' => 'PL', 'pak' => [[5, 'l', 450], [10, 'l', 270], [20, 'l', 120], [50, 'l', 40]]],
            ['naziv' => 'Idea', 'oznaka' => 'PL', 'pak' => [[5, 'l', 450], [10, 'l', 225], [20, 'l', 120], [25, 'l', 120]]],
            // Još nije u proizvodnji – isključen je dok se ne uključi u Podešavanjima.
            ['naziv' => 'Čmana supstrat', 'oznaka' => 'PL', 'aktivan' => 0, 'pak' => [[10, 'l', 270], [20, 'l', 120], [50, 'l', 40]]],
        ],
        'Malč' => [
            ['naziv' => 'Malč Farmerkop', 'nv' => 'Boja',
                'var' => ['Crveni', 'Braon', 'Žuti', 'Crni', 'Narandžasti', 'Zeleni', 'Neobojeni'],
                'pak' => [[50, 'l', 40]]],
            ['naziv' => 'Malč Floris Savacoop', 'oznaka' => 'PL', 'nv' => 'Boja',
                'var' => ['Crveni', 'Braon', 'Neobojeni'],
                'pak' => [[50, 'l', 40]]],
        ],
        'Dekorativni oblutak' => [
            ['naziv' => 'Beli oblutak', 'nv' => 'Granulacija',
                'var' => ['1-3 cm', '2-4 cm', '4-6 cm', '4-7 cm (krupnija)', '6-10 cm'],
                'pak' => [[20, 'kg', 50]]],
        ],
    ];
}

/** Pakovanja koja postoje od početka (admin može da dodaje nova). */
function pocetna_pakovanja(): array
{
    return [[5, 'l'], [10, 'l'], [20, 'l'], [25, 'l'], [50, 'l'], [20, 'kg']];
}

function ubaci_pocetni_katalog(): void
{
    foreach (pocetna_pakovanja() as [$kolicina, $jedinica]) {
        db_run('INSERT IGNORE INTO pakovanja (kolicina, jedinica, aktivan) VALUES (?, ?, 1)', [$kolicina, $jedinica]);
    }

    $kat_red = 0;
    foreach (pocetni_katalog() as $kategorija => $artikli) {
        $kat_red += 10;
        db_run('INSERT INTO kategorije (naziv, redosled, aktivan) VALUES (?, ?, 1)', [$kategorija, $kat_red]);
        $kategorija_id = db_id();

        $art_red = 0;
        foreach ($artikli as $a) {
            $art_red += 10;
            db_run(
                'INSERT INTO artikli (kategorija_id, naziv, oznaka, naziv_varijante, redosled, aktivan) VALUES (?, ?, ?, ?, ?, ?)',
                [$kategorija_id, $a['naziv'], $a['oznaka'] ?? null, $a['nv'] ?? null, $art_red, $a['aktivan'] ?? 1]
            );
            $artikal_id = db_id();

            $varijante = [0];
            if (!empty($a['var'])) {
                $varijante = [];
                $v_red = 0;
                foreach ($a['var'] as $naziv_varijante) {
                    $v_red += 10;
                    db_run('INSERT INTO varijante (artikal_id, naziv, redosled, aktivan) VALUES (?, ?, ?, 1)', [$artikal_id, $naziv_varijante, $v_red]);
                    $varijante[] = db_id();
                }
            }

            foreach ($a['pak'] as [$kolicina, $jedinica, $po_paleti]) {
                $pakovanje_id = (int)db_val('SELECT id FROM pakovanja WHERE kolicina = ? AND jedinica = ?', [$kolicina, $jedinica]);
                foreach ($varijante as $varijanta_id) {
                    db_run(
                        'INSERT INTO sku (artikal_id, varijanta_id, pakovanje_id, po_paleti, min_zaliha, aktivan) VALUES (?, ?, ?, ?, 0, 1)',
                        [$artikal_id, $varijanta_id, $pakovanje_id, $po_paleti]
                    );
                }
            }
        }
    }
}
