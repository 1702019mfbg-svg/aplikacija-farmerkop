<?php
/**
 * Nadogradnja baze. Pokreće se sama pri prvom otvaranju aplikacije posle ažuriranja fajlova,
 * pa ne treba nikakav ručni korak. Svaka verzija se izvršava najviše jednom, a podaci se ne diraju.
 *
 * Verzija 1: prva verzija aplikacije.
 * Verzija 2: paketi (komada u paketu), tabela za pamćenje uparivanja pri uvozu stanja,
 *            početne vrednosti za Humovit i Ideu, naziv "Cmana".
 */
declare(strict_types=1);

const SCHEMA_VERZIJA = 2;

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

/** Spisak nadogradnji po verzijama. */
function migracije(): array
{
    return [2 => 'migracija_2'];
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
