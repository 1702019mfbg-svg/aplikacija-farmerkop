<?php
/**
 * Upisivanje i brisanje unosa (proizvodnja, prodaja, kućna prodaja, korekcija).
 *
 * Pravila koja važe svuda:
 *  - stanje nikad ne sme da postane negativno (prodaja veća od stanja se odbija);
 *  - promena jednog SKU-a zaključava njegov red u bazi, pa dva istovremena unosa ne mogu
 *    zajedno da "probiju" stanje;
 *  - brisanje je "meko": unos ostaje u bazi označen kao obrisan, a u dnevnik se upisuje ko i kad.
 */
declare(strict_types=1);

/** Uticaj unosa na stanje: prodaja oduzima, ostalo dodaje (korekcija može biti i negativna). */
function unos_efekat(string $tip, int $kolicina): int
{
    return in_array($tip, ['prodaja', 'kucna_prodaja'], true) ? -$kolicina : $kolicina;
}

function je_prodaja(string $tip): bool
{
    return in_array($tip, ['prodaja', 'kucna_prodaja'], true);
}

/** Zaključava red SKU-a do kraja transakcije. */
function sku_zakljucaj(int $sku_id): void
{
    db_run('SELECT id FROM sku WHERE id = ? FOR UPDATE', [$sku_id]);
}

/**
 * Dodaje unos.
 * Vraća ['ok' => true, 'id' => n, 'duplikat' => bool]
 *    ili ['ok' => false, 'nedovoljno' => true, 'stanje' => n]   (prodaja veća od stanja)
 *    ili ['ok' => false, 'greska' => tekst].
 *
 * $kljuc je jednokratni ključ forme: ako se ista forma pošalje dvaput (dupli dodir, "nazad" u
 * pregledaču), drugi put se ne upisuje ništa novo.
 */
function unos_dodaj(
    string $tip,
    int $sku_id,
    int $kolicina,
    ?int $palete,
    int $korisnik_id,
    ?int $kupac_id = null,
    ?string $napomena = null,
    ?string $kljuc = null,
    ?string $nastalo = null
): array {
    if (!array_key_exists($tip, TIPOVI_UNOSA)) {
        return ['ok' => false, 'greska' => 'Nepoznata vrsta unosa.'];
    }
    if ($kolicina === 0 || ($kolicina < 0 && $tip !== 'korekcija')) {
        return ['ok' => false, 'greska' => 'Količina mora biti veća od nule.'];
    }
    if ($napomena !== null) {
        $napomena = mb_substr(trim($napomena), 0, 255);
        if ($napomena === '') {
            $napomena = null;
        }
    }
    if ($kljuc !== null && !preg_match('/^[0-9a-f]{32}$/', $kljuc)) {
        $kljuc = null;
    }

    if ($kljuc !== null) {
        $postoji = db_val('SELECT id FROM unosi WHERE kljuc = ?', [$kljuc]);
        if ($postoji !== null) {
            return ['ok' => true, 'id' => (int)$postoji, 'duplikat' => true];
        }
    }

    try {
        return db_trans(static function () use ($tip, $sku_id, $kolicina, $palete, $korisnik_id, $kupac_id, $napomena, $kljuc, $nastalo): array {
            sku_zakljucaj($sku_id);
            $stanje = stanje_sku($sku_id);
            if (je_prodaja($tip) && $kolicina > $stanje) {
                return ['ok' => false, 'nedovoljno' => true, 'stanje' => $stanje];
            }
            if ($tip === 'korekcija' && $stanje + $kolicina < 0) {
                return ['ok' => false, 'greska' => 'Stanje ne može biti manje od nule.'];
            }
            $sad = sada();
            db_run(
                'INSERT INTO unosi (tip, sku_id, kolicina, palete, korisnik_id, kupac_id, napomena, nastalo, uneto, kljuc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tip, $sku_id, $kolicina, $palete, $korisnik_id, $kupac_id, $napomena, $nastalo ?? $sad, $sad, $kljuc]
            );
            return ['ok' => true, 'id' => db_id(), 'duplikat' => false];
        });
    } catch (PDOException $e) {
        // Istovremeno slanje iste forme: drugi upis je odbijen jedinstvenim ključem – to je u redu.
        if ($kljuc !== null && (int)($e->errorInfo[1] ?? 0) === 1062) {
            $postoji = db_val('SELECT id FROM unosi WHERE kljuc = ?', [$kljuc]);
            if ($postoji !== null) {
                return ['ok' => true, 'id' => (int)$postoji, 'duplikat' => true];
            }
        }
        throw $e;
    }
}

/** Opis unosa za dnevnik izmena. */
function unos_snimak(array $u): array
{
    $sku = sku_podaci((int)$u['sku_id']);
    return [
        'tip'      => $u['tip'],
        'artikal'  => $sku ? sku_naziv($sku) : ('SKU ' . $u['sku_id']),
        'sku_id'   => (int)$u['sku_id'],
        'kolicina' => (int)$u['kolicina'],
        'palete'   => $u['palete'] === null ? null : (int)$u['palete'],
        'kupac_id' => $u['kupac_id'] === null ? null : (int)$u['kupac_id'],
        'napomena' => $u['napomena'],
        'nastalo'  => $u['nastalo'],
    ];
}

/**
 * Meko brisanje unosa. Odbija se ako bi stanje posle brisanja bilo manje od nule
 * (npr. brisanje proizvodnje čiji su komadi već prodati).
 */
function unos_obrisi(int $unos_id, int $korisnik_id, string $akcija = 'brisanje'): array
{
    return db_trans(static function () use ($unos_id, $korisnik_id, $akcija): array {
        $u = db_one('SELECT * FROM unosi WHERE id = ? FOR UPDATE', [$unos_id]);
        if ($u === null || (int)$u['obrisan'] === 1) {
            return ['ok' => false, 'greska' => 'Unos ne postoji ili je već obrisan.'];
        }
        $sku_id = (int)$u['sku_id'];
        sku_zakljucaj($sku_id);
        $novo_stanje = stanje_sku($sku_id) - unos_efekat((string)$u['tip'], (int)$u['kolicina']);
        if ($novo_stanje < 0) {
            return ['ok' => false, 'greska' => 'Unos ne može da se obriše jer bi stanje postalo manje od nule (te količine su već prodate).'];
        }
        db_run('UPDATE unosi SET obrisan = 1, obrisan_u = ?, obrisao_id = ? WHERE id = ?', [sada(), $korisnik_id, $unos_id]);
        dnevnik_upis($akcija, 'unos', $unos_id, ['pre' => unos_snimak($u)]);
        return ['ok' => true, 'unos' => $u];
    });
}

/** Poslednji neobrisani unos radnika (proizvodnja ili kućna prodaja). */
function radnikov_poslednji_unos(int $radnik_id): ?array
{
    return db_one(
        "SELECT * FROM unosi WHERE korisnik_id = ? AND tip IN ('proizvodnja', 'kucna_prodaja') AND obrisan = 0 ORDER BY id DESC LIMIT 1",
        [$radnik_id]
    );
}

/** Unos koji radnik još sme da obriše (njegov poslednji, ne stariji od BRISANJE_RADNIK_MIN), ili null. */
function radnik_sme_da_obrise(int $radnik_id): ?array
{
    $u = radnikov_poslednji_unos($radnik_id);
    if ($u === null) {
        return null;
    }
    $proslo = time() - (int)strtotime((string)$u['uneto']);
    return $proslo <= BRISANJE_RADNIK_MIN * 60 ? $u : null;
}
