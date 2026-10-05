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
    ?string $nastalo = null,
    ?int $paketi = null
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
        return db_trans(static function () use ($tip, $sku_id, $kolicina, $palete, $paketi, $korisnik_id, $kupac_id, $napomena, $kljuc, $nastalo): array {
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
                'INSERT INTO unosi (tip, sku_id, kolicina, palete, paketi, korisnik_id, kupac_id, napomena, nastalo, uneto, kljuc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tip, $sku_id, $kolicina, $palete, $paketi, $korisnik_id, $kupac_id, $napomena, $nastalo ?? $sad, $sad, $kljuc]
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
        'paketi'   => $u['paketi'] === null ? null : (int)$u['paketi'],
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

/** ID kupca po nazivu; ako ga nema, pravi se novi (veličina slova i kvačice se ne razlikuju). */
function kupac_id_za(string $naziv): int
{
    $naziv = mb_substr(trim($naziv), 0, 120);
    $id = db_val('SELECT id FROM kupci WHERE naziv = ?', [$naziv]);
    if ($id !== null) {
        return (int)$id;
    }
    try {
        db_run('INSERT INTO kupci (naziv, napravljen) VALUES (?, ?)', [$naziv, sada()]);
        return db_id();
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return (int)db_val('SELECT id FROM kupci WHERE naziv = ?', [$naziv]);
        }
        throw $e;
    }
}

/**
 * Ispravka unosa (samo administrator). Vrsta unosa se ne menja.
 * Kod korekcije (popisa) menjaju se samo vreme i napomena.
 * $nova: sku_id, kolicina, palete, paketi, kupac_id, napomena, nastalo.
 * Stanje ni jednog SKU-a ne sme da padne ispod nule.
 */
function unos_izmeni(int $id, array $nova, int $korisnik_id): array
{
    return db_trans(static function () use ($id, $nova, $korisnik_id): array {
        $stari = db_one('SELECT * FROM unosi WHERE id = ? FOR UPDATE', [$id]);
        if ($stari === null || (int)$stari['obrisan'] === 1) {
            return ['ok' => false, 'greska' => 'Unos ne postoji ili je obrisan.'];
        }
        $tip = (string)$stari['tip'];
        $stari_sku = (int)$stari['sku_id'];
        $korekcija = $tip === 'korekcija';
        $novi_sku = $korekcija ? $stari_sku : (int)$nova['sku_id'];
        $nova_kol = $korekcija ? (int)$stari['kolicina'] : (int)$nova['kolicina'];
        $nove_palete = $korekcija ? null : ($nova['palete'] ?? null);
        $nove_paketi = $korekcija ? null : ($nova['paketi'] ?? null);
        $kupac_id = $tip === 'prodaja' ? ($nova['kupac_id'] ?? null) : ($stari['kupac_id'] === null ? null : (int)$stari['kupac_id']);
        $napomena = isset($nova['napomena']) ? mb_substr(trim((string)$nova['napomena']), 0, 255) : null;
        if ($napomena === '') {
            $napomena = null;
        }

        $zakljucaj = array_unique([$stari_sku, $novi_sku]);
        sort($zakljucaj);
        foreach ($zakljucaj as $sid) {
            sku_zakljucaj($sid);
        }

        if (!$korekcija) {
            if ($novi_sku === $stari_sku) {
                $posle = stanje_sku($stari_sku) - unos_efekat($tip, (int)$stari['kolicina']) + unos_efekat($tip, $nova_kol);
                if ($posle < 0) {
                    return ['ok' => false, 'greska' => 'Ova izmena bi spustila stanje ispod nule (bilo bi ' . broj($posle) . ' kom).'];
                }
            } else {
                $staro_posle = stanje_sku($stari_sku) - unos_efekat($tip, (int)$stari['kolicina']);
                $novo_posle = stanje_sku($novi_sku) + unos_efekat($tip, $nova_kol);
                if ($staro_posle < 0) {
                    return ['ok' => false, 'greska' => 'Izmenom artikla stanje starog artikla bi palo ispod nule (bilo bi ' . broj($staro_posle) . ' kom).'];
                }
                if ($novo_posle < 0) {
                    return ['ok' => false, 'greska' => 'Na izabranom artiklu nema dovoljno na stanju za ovu količinu.'];
                }
            }
        }

        $pre = unos_snimak($stari);
        db_run(
            'UPDATE unosi SET sku_id = ?, kolicina = ?, palete = ?, paketi = ?, kupac_id = ?, napomena = ?, nastalo = ? WHERE id = ?',
            [$novi_sku, $nova_kol, $nove_palete, $nove_paketi, $kupac_id, $napomena, $nova['nastalo'], $id]
        );
        $posle_snimak = unos_snimak(db_one('SELECT * FROM unosi WHERE id = ?', [$id]));
        if ($pre == $posle_snimak) {
            return ['ok' => true, 'nepromenjeno' => true];
        }
        dnevnik_upis('izmena', 'unos', $id, ['pre' => $pre, 'posle' => $posle_snimak]);
        return ['ok' => true, 'nepromenjeno' => false];
    });
}

/** Vraća obrisan unos (ako stanje posle toga ostaje u redu). */
function unos_vrati(int $unos_id): array
{
    return db_trans(static function () use ($unos_id): array {
        $u = db_one('SELECT * FROM unosi WHERE id = ? FOR UPDATE', [$unos_id]);
        if ($u === null || (int)$u['obrisan'] === 0) {
            return ['ok' => false, 'greska' => 'Unos nije obrisan.'];
        }
        $sku_id = (int)$u['sku_id'];
        sku_zakljucaj($sku_id);
        if (stanje_sku($sku_id) + unos_efekat((string)$u['tip'], (int)$u['kolicina']) < 0) {
            return ['ok' => false, 'greska' => 'Unos ne može da se vrati jer na stanju nema dovoljno za tu prodaju.'];
        }
        db_run('UPDATE unosi SET obrisan = 0, obrisan_u = NULL, obrisao_id = NULL WHERE id = ?', [$unos_id]);
        dnevnik_upis('povracaj', 'unos', $unos_id, ['posle' => unos_snimak($u)]);
        return ['ok' => true];
    });
}

/**
 * Popis: postavlja stanje SKU-a na prebrojanu vrednost upisom korekcije (razlike).
 * Razlika se računa u trenutku čuvanja, pod zaključanim redom, pa je stanje posle popisa tačno
 * koliko je prebrojano, čak i ako je u međuvremenu bilo novih unosa.
 * Vraća ['ok' => true, 'promena' => bool, 'bilo' => n, 'razlika' => n].
 */
function popis_postavi(int $sku_id, int $prebrojano, string $napomena, int $korisnik_id): array
{
    return db_trans(static function () use ($sku_id, $prebrojano, $napomena, $korisnik_id): array {
        sku_zakljucaj($sku_id);
        $bilo = stanje_sku($sku_id);
        $razlika = $prebrojano - $bilo;
        if ($razlika === 0) {
            return ['ok' => true, 'promena' => false, 'bilo' => $bilo, 'razlika' => 0];
        }
        $sad = sada();
        db_run(
            "INSERT INTO unosi (tip, sku_id, kolicina, palete, korisnik_id, kupac_id, napomena, nastalo, uneto, kljuc)
             VALUES ('korekcija', ?, ?, NULL, ?, NULL, ?, ?, ?, NULL)",
            [$sku_id, $razlika, $korisnik_id, mb_substr(trim($napomena), 0, 200) . ' [bilo ' . $bilo . ', prebrojano ' . $prebrojano . ']', $sad, $sad]
        );
        return ['ok' => true, 'promena' => true, 'bilo' => $bilo, 'razlika' => $razlika];
    });
}
