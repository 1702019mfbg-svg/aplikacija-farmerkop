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
 * Verzija 5: stavka spiska pamti ko ju je dodao pri utovaru (utovar šlepera na licu mesta).
 * Verzija 6: zbirne palete na spisku (više artikala na jednoj paleti) – tabela spisak_palete,
 *            stavka pripada paleti (paleta_id, 0 = posebna stavka).
 * Verzija 7: jedan utovar (kamion) za više kupaca – mesta istovara (spisak_mesta: broj mesta koji upisuje
 *            vlasnik, npr. 8, 9, 5 …, kupac nije obavezan), stavke i zbirne palete pripadaju mestu;
 *            postojeći spiskovi dobijaju Mesto 1 sa svojim kupcem.
 * Verzija 8: (kratka imena artikala na spisku – kasnije ukinuta, vidi verziju 9; sada ne radi ništa).
 * Verzija 9: na spisku se opet piše pun naziv proizvoda – kolona sku.sifra (kratka imena iz verzija 7 i 8) se uklanja.
 * Verzija 10: novi artikal "Humovit S (stara ambalaža)" – 25 l i 50 l u staroj ambalaži, vodi se posebno dok se
 *             ne potroši (posle se isključi u Podešavanjima); palete, paketi i težina kao kod Humovita.
 * Verzija 11: mešalice – radnik upisuje koliko je mešalica malča ofarbao (po boji); samo za praćenje rada,
 *             stanje se ne menja (tabela mesalice).
 * Verzija 12: obaveštenja na telefon (dnevni zbir u 20h) – tabela push_pretplate (telefoni administratora).
 */
declare(strict_types=1);

const SCHEMA_VERZIJA = 12;

/** Artikal za Humovit u staroj ambalaži (verzija 10). */
const HUMOVIT_STARA = 'Humovit S (stara ambalaža)';

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
    kupac_id INT UNSIGNED NULL DEFAULT NULL,
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
    mesto_id INT UNSIGNED NOT NULL DEFAULT 0,
    paleta_id INT UNSIGNED NOT NULL DEFAULT 0,
    sku_id INT UNSIGNED NULL DEFAULT NULL,
    naziv VARCHAR(120) NULL DEFAULT NULL,
    jedinica VARCHAR(20) NULL DEFAULT NULL,
    tezina_kg DECIMAL(9,2) NULL DEFAULT NULL,
    kolicina INT UNSIGNED NOT NULL,
    palete INT UNSIGNED NOT NULL DEFAULT 0,
    paketi INT UNSIGNED NOT NULL DEFAULT 0,
    komadi INT UNSIGNED NOT NULL DEFAULT 0,
    utovareno INT UNSIGNED NULL DEFAULT NULL,
    dodao_id INT UNSIGNED NULL DEFAULT NULL,
    redosled INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stavka_mesto (spisak_id, mesto_id, paleta_id, sku_id),
    KEY idx_stavke_sku (sku_id),
    CONSTRAINT fk_stavke_spisak FOREIGN KEY (spisak_id) REFERENCES spiskovi (id),
    CONSTRAINT fk_stavke_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
    CONSTRAINT fk_stavke_dodao FOREIGN KEY (dodao_id) REFERENCES korisnici (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/**
 * Zbirna paleta: više različitih artikala složenih na jednu paletu (npr. 5 × 5 l, 5 × 10 l, 5 × 50 l).
 * "broj" = koliko ovakvih paleta (količine stavki su za jednu paletu × broj).
 */
const DDL_SPISAK_PALETE = 'CREATE TABLE IF NOT EXISTS spisak_palete (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    spisak_id INT UNSIGNED NOT NULL,
    mesto_id INT UNSIGNED NOT NULL DEFAULT 0,
    naziv VARCHAR(60) NOT NULL,
    broj SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    redosled INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_palete_spisak (spisak_id),
    CONSTRAINT fk_palete_spisak FOREIGN KEY (spisak_id) REFERENCES spiskovi (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/**
 * Mesto istovara na utovaru (kamionu): broj mesta (kako ga piše vlasnik – 1, 2, 3 ili 8, 9, 5 …), kupac (nije
 * obavezan), adresa ili napomena; redosled = redosled na spisku (kako se tovari).
 */
const DDL_SPISAK_MESTA = 'CREATE TABLE IF NOT EXISTS spisak_mesta (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    spisak_id INT UNSIGNED NOT NULL,
    broj SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    kupac_id INT UNSIGNED NULL DEFAULT NULL,
    adresa VARCHAR(255) NULL DEFAULT NULL,
    redosled INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_mesta_spisak (spisak_id, redosled),
    KEY idx_mesta_kupac (kupac_id),
    CONSTRAINT fk_mesta_spisak FOREIGN KEY (spisak_id) REFERENCES spiskovi (id),
    CONSTRAINT fk_mesta_kupac FOREIGN KEY (kupac_id) REFERENCES kupci (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

const TIPOVI_UNOSA_SQL = "ENUM('proizvodnja','prodaja','kucna_prodaja','korekcija','utovar','povrat')";

/**
 * Mešalice malča: koliko je radnik ofarbao (broj mešalica, po boji – "Crveni", "Crni" …). Samo za praćenje rada:
 * ofarban malč se suši i još nije spakovan, pa ne ulazi u stanje (vreće se upisuju u Proizvodnji).
 */
const DDL_MESALICE = 'CREATE TABLE IF NOT EXISTS mesalice (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    korisnik_id INT UNSIGNED NOT NULL,
    boja VARCHAR(60) NOT NULL,
    broj SMALLINT UNSIGNED NOT NULL,
    nastalo DATETIME NOT NULL,
    uneto DATETIME NOT NULL,
    kljuc CHAR(32) NULL DEFAULT NULL,
    obrisan TINYINT(1) NOT NULL DEFAULT 0,
    obrisan_u DATETIME NULL DEFAULT NULL,
    obrisao_id INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mesalice_kljuc (kljuc),
    KEY idx_mesalice_nastalo (nastalo),
    KEY idx_mesalice_korisnik (korisnik_id, nastalo),
    CONSTRAINT fk_mesalice_korisnik FOREIGN KEY (korisnik_id) REFERENCES korisnici (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

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

/** "dodao_id": stavka koju je radnik dodao pri utovaru (NULL = plan administratora). */
function migracija_5(): void
{
    if (!kolona_postoji('spisak_stavke', 'dodao_id')) {
        db()->exec('ALTER TABLE spisak_stavke ADD COLUMN dodao_id INT UNSIGNED NULL DEFAULT NULL AFTER utovareno');
    }
    if (!ogranicenje_postoji('spisak_stavke', 'fk_stavke_dodao')) {
        db()->exec('ALTER TABLE spisak_stavke ADD CONSTRAINT fk_stavke_dodao FOREIGN KEY (dodao_id) REFERENCES korisnici (id)');
    }
}

function migracija_6(): void
{
    db()->exec(DDL_SPISAK_PALETE);
    if (!kolona_postoji('spisak_stavke', 'paleta_id')) {
        db()->exec('ALTER TABLE spisak_stavke ADD COLUMN paleta_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER spisak_id');
    }
    if (indeks_postoji('spisak_stavke', 'uq_stavka')) {
        // isti artikal sme da bude i posebna stavka i u zbirnoj paleti; novi ključ se dodaje pre brisanja starog
        db()->exec('ALTER TABLE spisak_stavke ADD UNIQUE KEY uq_stavka_paleta (spisak_id, paleta_id, sku_id), DROP INDEX uq_stavka');
    }
}

/** Postojeći spiskovi: njihov kupac postaje Mesto 1, a sve stavke i zbirne palete idu na to mesto. */
function prenesi_kupce_u_mesta_v7(): void
{
    db_run(
        'INSERT INTO spisak_mesta (spisak_id, broj, kupac_id, adresa, redosled)
         SELECT sp.id, 1, sp.kupac_id, NULL, 10 FROM spiskovi sp
         WHERE sp.kupac_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM spisak_mesta m WHERE m.spisak_id = sp.id)'
    );
    db_run('UPDATE spisak_stavke st JOIN spisak_mesta m ON m.spisak_id = st.spisak_id SET st.mesto_id = m.id WHERE st.mesto_id = 0');
    db_run('UPDATE spisak_palete pl JOIN spisak_mesta m ON m.spisak_id = pl.spisak_id SET pl.mesto_id = m.id WHERE pl.mesto_id = 0');
}

function migracija_7(): void
{
    db()->exec(DDL_SPISAK_MESTA);
    db()->exec('ALTER TABLE spiskovi MODIFY kupac_id INT UNSIGNED NULL DEFAULT NULL');
    if (!kolona_postoji('spisak_stavke', 'mesto_id')) {
        db()->exec('ALTER TABLE spisak_stavke ADD COLUMN mesto_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER spisak_id');
    }
    if (!kolona_postoji('spisak_palete', 'mesto_id')) {
        db()->exec('ALTER TABLE spisak_palete ADD COLUMN mesto_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER spisak_id');
    }
    if (indeks_postoji('spisak_stavke', 'uq_stavka_paleta')) {
        // isti artikal sme da ide za više kupaca na istom utovaru
        db()->exec('ALTER TABLE spisak_stavke ADD UNIQUE KEY uq_stavka_mesto (spisak_id, mesto_id, paleta_id, sku_id), DROP INDEX uq_stavka_paleta');
    }
    db_trans('prenesi_kupce_u_mesta_v7');
}

/** Verzija 8 je menjala kratka imena artikala (sku.sifra), koja su ukinuta u verziji 9 – sada ne radi ništa. */
function migracija_8(): void
{
}

/** Na spisku se opet piše pun naziv proizvoda: kratka imena (sku.sifra) iz verzija 7 i 8 se uklanjaju. */
function migracija_9(): void
{
    if (kolona_postoji('sku', 'sifra')) {
        db()->exec(indeks_postoji('sku', 'uq_sku_sifra') ? 'ALTER TABLE sku DROP INDEX uq_sku_sifra, DROP COLUMN sifra' : 'ALTER TABLE sku DROP COLUMN sifra');
    }
}

/** Humovit 25 l i 50 l u staroj ambalaži, odmah posle Humovita (samo ako ga još nema). */
function migracija_10(): void
{
    db_trans('dodaj_humovit_staru_ambalazu_v10');
}

function dodaj_humovit_staru_ambalazu_v10(): void
{
    if (db_val('SELECT id FROM artikli WHERE naziv = ?', [HUMOVIT_STARA]) !== null) {
        return;
    }
    $h = db_one("SELECT * FROM artikli WHERE naziv = 'Humovit' ORDER BY id LIMIT 1");
    if ($h === null) {
        return;
    }
    db_run('INSERT INTO artikli (kategorija_id, naziv, redosled, aktivan) VALUES (?, ?, ?, 1)', [(int)$h['kategorija_id'], HUMOVIT_STARA, (int)$h['redosled']]);
    $novi = db_id();
    foreach ([25, 50] as $litara) {
        $s = db_one(
            "SELECT s.* FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id
             WHERE s.artikal_id = ? AND s.varijanta_id = 0 AND p.jedinica = 'l' AND p.kolicina = ?",
            [(int)$h['id'], $litara]
        );
        $pak = $s !== null ? (int)$s['pakovanje_id'] : (int)db_val("SELECT id FROM pakovanja WHERE jedinica = 'l' AND kolicina = ?", [$litara]);
        if ($pak === 0) {
            continue;
        }
        db_run(
            'INSERT INTO sku (artikal_id, varijanta_id, pakovanje_id, po_paleti, po_paketu, tezina_kg, min_zaliha, aktivan) VALUES (?, 0, ?, ?, ?, ?, 0, 1)',
            [$novi, $pak, $s['po_paleti'] ?? null, $s['po_paketu'] ?? null, $s['tezina_kg'] ?? null]
        );
    }
}

/**
 * Telefoni administratora prijavljeni za obaveštenja (Web Push): adresa kod servisa za obaveštenja i ključevi za
 * šifrovanje poruke (inc/push.php). endpoint_hes = sha256 adrese (adresa je preduga za jedinstveni indeks).
 */
const DDL_PUSH_PRETPLATE = 'CREATE TABLE IF NOT EXISTS push_pretplate (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    korisnik_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(1000) NOT NULL,
    endpoint_hes CHAR(64) NOT NULL,
    p256dh VARCHAR(120) NOT NULL,
    auth VARCHAR(40) NOT NULL,
    uredjaj VARCHAR(120) NULL DEFAULT NULL,
    napravljen DATETIME NOT NULL,
    poslednje_slanje DATETIME NULL DEFAULT NULL,
    greska VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hes),
    KEY idx_push_korisnik (korisnik_id),
    CONSTRAINT fk_push_korisnik FOREIGN KEY (korisnik_id) REFERENCES korisnici (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/** Mešalice malča (samo nova tabela; postojeći podaci se ne diraju). */
function migracija_11(): void
{
    db()->exec(DDL_MESALICE);
}

/** Obaveštenja na telefon (samo nova tabela). */
function migracija_12(): void
{
    db()->exec(DDL_PUSH_PRETPLATE);
}

/** Spisak nadogradnji po verzijama. */
function migracije(): array
{
    return [2 => 'migracija_2', 3 => 'migracija_3', 4 => 'migracija_4', 5 => 'migracija_5', 6 => 'migracija_6', 7 => 'migracija_7', 8 => 'migracija_8', 9 => 'migracija_9',
            10 => 'migracija_10', 11 => 'migracija_11', 12 => 'migracija_12'];
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
