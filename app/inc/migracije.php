<?php
/**
 * Nadogradnja baze. Pokreće se sama pri prvom otvaranju aplikacije posle ažuriranja fajlova,
 * pa ne treba nikakav ručni korak. Svaka verzija se izvršava najviše jednom, a podaci se ne diraju.
 *
 * Verzija 1: prva verzija aplikacije.
 * Verzija 2: paketi (komada u paketu), tabela za pamćenje uparivanja pri uvozu stanja,
 *            početne vrednosti za Humovit i Ideu, naziv "Cmana".
 * Verzija 3: spisak za utovar – težina jednog komada, spiskovi i stavke spiska (i "ostalo"),
 *            nove vrste kretanja zaliha (utovar, povrat) i veza unosa sa spiskom;
 *            novi artikli: crni i crveni oblutak, rizla (bela, crna, crvena), perlit, keramzit.
 * Verzija 4: okvirne težine komada (zemlja, malč, perlit, keramzit) i 50 komada po paleti za rizlu.
 */
declare(strict_types=1);

const SCHEMA_VERZIJA = 4;

function kolona_postoji(string $tabela, string $kolona): bool
{
    return (int)db_val(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$tabela, $kolona]
    ) > 0;
}

const DDL_UVOZ_MAPIRANJE = 'CREATE TABLE IF NOT EXISTS uvoz_mapiranje (
    kljuc VARCHAR(190) NOT NULL,
    sku_id INT UNSIGNED NOT NULL,
    napravljen DATETIME NOT NULL,
    PRIMARY KEY (kljuc),
    KEY idx_uvoz_sku (sku_id),
    CONSTRAINT fk_uvoz_sku FOREIGN KEY (sku_id) REFERENCES sku (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/** Spisak za utovar: kupac, datum, vozilo, vozač i status (stanje se skida tek kad je "utovareno"). */
const DDL_SPISKOVI = "CREATE TABLE IF NOT EXISTS spiskovi (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kupac_id INT UNSIGNED NOT NULL,
    datum DATE NOT NULL,
    vozilo VARCHAR(60) NULL DEFAULT NULL,
    vozac VARCHAR(60) NULL DEFAULT NULL,
    napomena VARCHAR(255) NULL DEFAULT NULL,
    status ENUM('nacrt','za_utovar','utovareno','otkazano') NOT NULL DEFAULT 'nacrt',
    napravio_id INT UNSIGNED NOT NULL,
    napravljen DATETIME NOT NULL,
    izmenjen DATETIME NOT NULL,
    utovario_id INT UNSIGNED NULL DEFAULT NULL,
    utovareno_u DATETIME NULL DEFAULT NULL,
    otkazao_id INT UNSIGNED NULL DEFAULT NULL,
    otkazano_u DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_spiskovi_status (status, datum),
    KEY idx_spiskovi_kupac (kupac_id),
    CONSTRAINT fk_spiskovi_kupac FOREIGN KEY (kupac_id) REFERENCES kupci (id),
    CONSTRAINT fk_spiskovi_napravio FOREIGN KEY (napravio_id) REFERENCES korisnici (id),
    CONSTRAINT fk_spiskovi_utovario FOREIGN KEY (utovario_id) REFERENCES korisnici (id),
    CONSTRAINT fk_spiskovi_otkazao FOREIGN KEY (otkazao_id) REFERENCES korisnici (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/**
 * Stavka spiska. Artikal iz aplikacije (sku_id): plan u komadima (kolicina) i kako je uneto
 * (palete + paketi + komadi). "Ostalo" (sku_id = NULL): roba koja se ne vodi u aplikaciji –
 * naziv, količina, jedinica i težina jedne jedinice upisuju se ručno i ne diraju stanje.
 * "utovareno" se popunjava pri potvrdi utovara (stvarno utovarena količina).
 */
const DDL_SPISAK_STAVKE = 'CREATE TABLE IF NOT EXISTS spisak_stavke (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    spisak_id INT UNSIGNED NOT NULL,
    sku_id INT UNSIGNED NULL DEFAULT NULL,
    naziv VARCHAR(120) NULL DEFAULT NULL,
    jedinica VARCHAR(20) NULL DEFAULT NULL,
    tezina_kg DECIMAL(9,2) NULL DEFAULT NULL,
    kolicina INT UNSIGNED NOT NULL,
    palete INT UNSIGNED NOT NULL DEFAULT 0,
    paketi INT UNSIGNED NOT NULL DEFAULT 0,
    komadi INT UNSIGNED NOT NULL DEFAULT 0,
    utovareno INT UNSIGNED NULL DEFAULT NULL,
    redosled INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stavka (spisak_id, sku_id),
    KEY idx_stavke_sku (sku_id),
    CONSTRAINT fk_stavke_spisak FOREIGN KEY (spisak_id) REFERENCES spiskovi (id),
    CONSTRAINT fk_stavke_sku FOREIGN KEY (sku_id) REFERENCES sku (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

const TIPOVI_UNOSA_SQL = "ENUM('proizvodnja','prodaja','kucna_prodaja','korekcija','utovar','povrat')";

function ogranicenje_postoji(string $tabela, string $ime): bool
{
    return (int)db_val(
        'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
        [$tabela, $ime]
    ) > 0;
}

function indeks_postoji(string $tabela, string $ime): bool
{
    return (int)db_val(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
        [$tabela, $ime]
    ) > 0;
}

function migracija_2(): void
{
    if (!kolona_postoji('sku', 'po_paketu')) {
        db()->exec('ALTER TABLE sku ADD COLUMN po_paketu SMALLINT UNSIGNED NULL DEFAULT NULL AFTER po_paleti');
    }
    if (!kolona_postoji('unosi', 'paketi')) {
        db()->exec('ALTER TABLE unosi ADD COLUMN paketi INT UNSIGNED NULL DEFAULT NULL AFTER palete');
    }
    db()->exec(DDL_UVOZ_MAPIRANJE);

    // Koliko komada ide u paket (transportno pakovanje): Humovit i Idea, 5 l i 10 l.
    foreach ([['Humovit', 5, 10], ['Humovit', 10, 6], ['Idea', 5, 10], ['Idea', 10, 5]] as [$artikal, $litara, $u_paketu]) {
        db_run(
            "UPDATE sku s JOIN artikli a ON a.id = s.artikal_id JOIN pakovanja p ON p.id = s.pakovanje_id
             SET s.po_paketu = ? WHERE a.naziv = ? AND p.kolicina = ? AND p.jedinica = 'l' AND s.po_paketu IS NULL",
            [$u_paketu, $artikal, $litara]
        );
    }

    // Naziv je "Cmana" (bez kvačice).
    db_run("UPDATE artikli SET naziv = 'Cmana supstrat' WHERE BINARY naziv = BINARY 'Čmana supstrat'");
}

/**
 * Artikli dodati u verziji 3 (kategorija => artikli, isti oblik kao početni katalog u schema.php).
 * pak = [količina, jedinica, komada po paleti, (komada u paketu)]; var = boje / granulacije.
 */
function katalog_dodaci_v3(): array
{
    $granulacije = ['1-3 cm', '2-4 cm', '4-6 cm', '4-7 cm (krupnija)', '6-10 cm'];
    return [
        'Dekorativni oblutak' => [
            ['naziv' => 'Crni oblutak', 'nv' => 'Granulacija', 'var' => $granulacije, 'pak' => [[20, 'kg', 50]]],
            ['naziv' => 'Crveni oblutak', 'nv' => 'Granulacija', 'var' => $granulacije, 'pak' => [[20, 'kg', 50]]],
        ],
        'Dekorativna rizla' => [
            ['naziv' => 'Bela rizla', 'nv' => 'Granulacija', 'var' => ['Sitnija', 'Krupnija'], 'pak' => [[20, 'kg', null]]],
            ['naziv' => 'Crna rizla', 'nv' => 'Granulacija', 'var' => ['Sitnija', 'Krupnija'], 'pak' => [[20, 'kg', null]]],
            ['naziv' => 'Crvena rizla', 'nv' => 'Granulacija', 'var' => ['Sitnija', 'Krupnija'], 'pak' => [[20, 'kg', null]]],
        ],
        'Perlit' => [
            ['naziv' => 'Perlit', 'pak' => [[5, 'l', null, 20], [100, 'l', null]]],
        ],
        'Keramzit' => [
            ['naziv' => 'Keramzit', 'pak' => [[5, 'l', null, 8], [35, 'l', null]]],
        ],
    ];
}

/** Ubacuje jedan artikal sa varijantama i pakovanjima (pakovanje se pravi ako ne postoji). */
function ubaci_artikal(int $kategorija_id, array $a, int $redosled): void
{
    db_run(
        'INSERT INTO artikli (kategorija_id, naziv, oznaka, naziv_varijante, redosled, aktivan) VALUES (?, ?, ?, ?, ?, ?)',
        [$kategorija_id, $a['naziv'], $a['oznaka'] ?? null, $a['nv'] ?? null, $redosled, $a['aktivan'] ?? 1]
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

    foreach ($a['pak'] as $pak) {
        [$kolicina, $jedinica, $po_paleti] = $pak;
        $po_paketu = $pak[3] ?? null;
        db_run('INSERT IGNORE INTO pakovanja (kolicina, jedinica, aktivan) VALUES (?, ?, 1)', [$kolicina, $jedinica]);
        $pakovanje_id = (int)db_val('SELECT id FROM pakovanja WHERE kolicina = ? AND jedinica = ?', [$kolicina, $jedinica]);
        foreach ($varijante as $varijanta_id) {
            db_run(
                'INSERT INTO sku (artikal_id, varijanta_id, pakovanje_id, po_paleti, po_paketu, min_zaliha, aktivan) VALUES (?, ?, ?, ?, ?, 0, 1)',
                [$artikal_id, $varijanta_id, $pakovanje_id, $po_paleti, $po_paketu]
            );
        }
    }
}

/** Dodaje nove artikle u postojeću bazu; artikal koji već postoji (isti naziv) se preskače. */
function ubaci_dodatke_v3(): void
{
    // Nova pakovanja (35 l, 100 l) prave se uvek, i kad je artikal već ručno dodat – da mogu da se izaberu.
    foreach (katalog_dodaci_v3() as $artikli) {
        foreach ($artikli as $a) {
            foreach ($a['pak'] as $pak) {
                db_run('INSERT IGNORE INTO pakovanja (kolicina, jedinica, aktivan) VALUES (?, ?, 1)', [$pak[0], $pak[1]]);
            }
        }
    }
    $kat_red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM kategorije');
    foreach (katalog_dodaci_v3() as $kategorija => $artikli) {
        $kid = db_val('SELECT id FROM kategorije WHERE naziv = ?', [$kategorija]);
        if ($kid === null) {
            $kat_red += 10;
            db_run('INSERT INTO kategorije (naziv, redosled, aktivan) VALUES (?, ?, 1)', [$kategorija, $kat_red]);
            $kid = db_id();
        }
        $art_red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM artikli WHERE kategorija_id = ?', [(int)$kid]);
        foreach ($artikli as $a) {
            if ((int)db_val('SELECT COUNT(*) FROM artikli WHERE naziv = ?', [$a['naziv']]) > 0) {
                continue;
            }
            $art_red += 10;
            ubaci_artikal((int)$kid, $a, $art_red);
        }
    }
}

function migracija_3(): void
{
    if (!kolona_postoji('sku', 'tezina_kg')) {
        db()->exec('ALTER TABLE sku ADD COLUMN tezina_kg DECIMAL(7,2) NULL DEFAULT NULL AFTER po_paketu');
    }
    db()->exec(DDL_SPISKOVI);
    db()->exec(DDL_SPISAK_STAVKE);
    // Nove vrste se dodaju na kraj spiska, pa postojeći unosi ostaju potpuno isti.
    db()->exec('ALTER TABLE unosi MODIFY tip ' . TIPOVI_UNOSA_SQL . ' NOT NULL');
    if (!kolona_postoji('unosi', 'spisak_id')) {
        db()->exec('ALTER TABLE unosi ADD COLUMN spisak_id INT UNSIGNED NULL DEFAULT NULL AFTER kupac_id');
    }
    if (!indeks_postoji('unosi', 'idx_unosi_spisak')) {
        db()->exec('ALTER TABLE unosi ADD KEY idx_unosi_spisak (spisak_id)');
    }
    if (!ogranicenje_postoji('unosi', 'fk_unosi_spisak')) {
        db()->exec('ALTER TABLE unosi ADD CONSTRAINT fk_unosi_spisak FOREIGN KEY (spisak_id) REFERENCES spiskovi (id)');
    }
    db_trans('ubaci_dodatke_v3');
}

/**
 * Okvirne težine (kg po litru) po podacima vlasnika: zemlja 5 l ≈ 1,8 kg, malč 50 l ≈ 15 kg,
 * perlit 100 l ≈ 7 kg, keramzit 35 l ≈ 7 kg. Upisuje se samo gde težina još nije upisana.
 */
function postavi_tezine_v4(): void
{
    $po_kategoriji = ['Zemlja za cveće' => 0.36, 'Malč' => 0.3];
    foreach ($po_kategoriji as $kategorija => $kg_po_litru) {
        db_run(
            "UPDATE sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id JOIN pakovanja p ON p.id = s.pakovanje_id
             SET s.tezina_kg = ROUND(p.kolicina * ?, 2) WHERE k.naziv = ? AND p.jedinica = 'l' AND s.tezina_kg IS NULL",
            [$kg_po_litru, $kategorija]
        );
    }
    foreach (['Perlit' => 0.07, 'Keramzit' => 0.2] as $artikal => $kg_po_litru) {
        db_run(
            "UPDATE sku s JOIN artikli a ON a.id = s.artikal_id JOIN pakovanja p ON p.id = s.pakovanje_id
             SET s.tezina_kg = ROUND(p.kolicina * ?, 2) WHERE a.naziv = ? AND p.jedinica = 'l' AND s.tezina_kg IS NULL",
            [$kg_po_litru, $artikal]
        );
    }
    db_run("UPDATE sku s JOIN artikli a ON a.id = s.artikal_id SET s.po_paleti = 50
            WHERE a.naziv IN ('Bela rizla', 'Crna rizla', 'Crvena rizla') AND s.po_paleti IS NULL");
}

function migracija_4(): void
{
    db_trans('postavi_tezine_v4');
}

/** Spisak nadogradnji po verzijama. */
function migracije(): array
{
    return [2 => 'migracija_2', 3 => 'migracija_3', 4 => 'migracija_4'];
}

/** Izvršava nadogradnje koje još nisu urađene. Bezbedno za istovremene zahteve (zaključavanje). */
function migriraj_ako_treba(): void
{
    try {
        $verzija = db_val("SELECT vrednost FROM podesavanja WHERE kljuc = 'schema_verzija'");
    } catch (PDOException $e) {
        return;     // baza nije instalirana ili nema veze – to prijavljuje ostatak aplikacije
    }
    if ((int)($verzija ?? 1) >= SCHEMA_VERZIJA) {
        return;
    }

    if ((int)db_val("SELECT GET_LOCK('farmerkop_migracija', 20)") !== 1) {
        return;
    }
    try {
        $trenutna = (int)(db_val("SELECT vrednost FROM podesavanja WHERE kljuc = 'schema_verzija'") ?? 1);
        foreach (migracije() as $broj => $funkcija) {
            if ($broj > $trenutna) {
                $funkcija();
                db_run("REPLACE INTO podesavanja (kljuc, vrednost) VALUES ('schema_verzija', ?)", [(string)$broj]);
                error_log('[Farmerkop] nadogradnja baze na verziju ' . $broj);
            }
        }
    } finally {
        db_run("DO RELEASE_LOCK('farmerkop_migracija')");
    }
}
