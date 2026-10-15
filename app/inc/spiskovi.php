<?php
/**
 * Spisak za utovar: kupac, datum, vozilo, vozač, stavke i status.
 *
 *   nacrt      – slobodno se menja, stanje se ne dira
 *   za_utovar  – radnici ga vide, količine su rezervisane
 *   utovareno  – potvrđeno; tek tada se skida stanje (faza 3)
 *   otkazano   – ništa se ne rezerviše; ako je bio utovaren, roba se vraća (povrat)
 *
 * Stavka je artikal iz aplikacije (sku_id, količina u komadima, kako je uneto: palete + paketi + komadi)
 * ili "ostalo" (sku_id = NULL): roba koja se ne vodi u aplikaciji i ne dira stanje.
 */
declare(strict_types=1);

const SPISAK_STATUSI = [
    'nacrt'     => 'Nacrt',
    'za_utovar' => 'Za utovar',
    'utovareno' => 'Utovareno',
    'otkazano'  => 'Otkazano',
];

const SPISAK_POLJA = 'sp.*, ku.naziv AS kupac, n.ime AS napravio, ut.ime AS utovario, ot.ime AS otkazao';
const SPISAK_IZ = 'FROM spiskovi sp JOIN kupci ku ON ku.id = sp.kupac_id JOIN korisnici n ON n.id = sp.napravio_id '
    . 'LEFT JOIN korisnici ut ON ut.id = sp.utovario_id LEFT JOIN korisnici ot ON ot.id = sp.otkazao_id';

function spisak_ucitaj(int $id): ?array
{
    return db_one('SELECT ' . SPISAK_POLJA . ' ' . SPISAK_IZ . ' WHERE sp.id = ?', [$id]);
}

/** Zaključava red spiska do kraja transakcije i vraća ga (null = ne postoji). */
function spisak_zakljucaj(int $id): ?array
{
    return db_one('SELECT * FROM spiskovi WHERE id = ? FOR UPDATE', [$id]);
}

/** Stavke spiska sa podacima o artiklu (za "ostalo" su polja artikla null). */
function spisak_stavke(int $spisak_id): array
{
    return db_all(
        'SELECT st.id AS stavka_id, st.spisak_id, st.sku_id, st.naziv AS ostalo_naziv, st.jedinica AS ostalo_jedinica,
                st.tezina_kg AS ostalo_kg, st.kolicina, st.palete AS u_palete, st.paketi AS u_paketi, st.komadi AS u_komadi,
                st.utovareno, st.redosled,
                s.po_paleti, s.po_paketu, s.tezina_kg, a.naziv AS artikal, a.oznaka, v.naziv AS varijanta,
                p.kolicina AS pak_kolicina, p.jedinica
         FROM spisak_stavke st
         LEFT JOIN sku s ON s.id = st.sku_id
         LEFT JOIN artikli a ON a.id = s.artikal_id
         LEFT JOIN pakovanja p ON p.id = s.pakovanje_id
         LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE st.spisak_id = ?
         ORDER BY (st.sku_id IS NULL), st.redosled, st.id',
        [$spisak_id]
    );
}

/** Naziv stavke: "Humovit · 10 l" ili naziv "ostalog". */
function stavka_naziv(array $st): string
{
    return $st['sku_id'] === null ? (string)$st['ostalo_naziv'] : sku_naziv($st);
}

/** Kako je količina uneta: "2 pal + 3 pak + 5 kom" (za "ostalo": "40 kom"). */
function stavka_unos_tekst(array $st): string
{
    if ($st['sku_id'] === null) {
        return broj((int)$st['kolicina']) . ' ' . ($st['ostalo_jedinica'] ?: 'kom');
    }
    $delovi = [];
    if ((int)$st['u_palete'] > 0) {
        $delovi[] = (int)$st['u_palete'] . ' pal';
    }
    if ((int)$st['u_paketi'] > 0) {
        $delovi[] = (int)$st['u_paketi'] . ' pak';
    }
    if ((int)$st['u_komadi'] > 0 || !$delovi) {
        $delovi[] = broj((int)$st['u_komadi']) . ' kom';
    }
    return implode(' + ', $delovi);
}

/** Težina stavke u kg (null = nepoznata). Za stavku koja je utovarena uzima se utovarena količina. */
function stavka_kg(array $st, ?int $kolicina = null): ?float
{
    $kol = $kolicina ?? (int)$st['kolicina'];
    if ($st['sku_id'] === null) {
        return $st['ostalo_kg'] !== null ? $kol * (float)$st['ostalo_kg'] : null;
    }
    $kom = tezina_kom($st);
    return $kom === null ? null : $kol * $kom;
}

/**
 * Zbir spiska: ukupno paleta (komadi / komada po paleti), težina u kg i šta nije moglo da se uračuna.
 * ['palete' => float, 'kg' => float, 'bez_palete' => int, 'bez_tezine' => int, 'stavki' => int]
 */
function spisak_zbir(array $stavke, bool $utovareno = false): array
{
    $z = ['palete' => 0.0, 'kg' => 0.0, 'bez_palete' => 0, 'bez_tezine' => 0, 'stavki' => count($stavke)];
    foreach ($stavke as $st) {
        $kol = $utovareno && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
        if ($st['sku_id'] !== null) {
            if ((int)$st['po_paleti'] > 0) {
                $z['palete'] += $kol / (int)$st['po_paleti'];
            } else {
                $z['bez_palete']++;
            }
        }
        $kg = stavka_kg($st, $kol);
        if ($kg === null) {
            $z['bez_tezine']++;
        } else {
            $z['kg'] += $kg;
        }
    }
    return $z;
}

/** "≈ 7,5 paleta" */
function palete_zbir_tekst(float $p): string
{
    $zaokruzeno = round($p, 1);
    if ($zaokruzeno == floor($zaokruzeno)) {
        return palete_tekst((int)$zaokruzeno);
    }
    return '≈ ' . broj($zaokruzeno, 1) . ' paleta';
}

/**
 * Stavke kojih na spisku ima više nego što je slobodno (stanje − rezervisano na DRUGIM spiskovima "Za utovar").
 * Samo upozorenje, ne zabrana. Vraća [stavka_id => ['slobodno' => n, 'trazi' => n]].
 */
function spisak_manjak(int $spisak_id, array $stavke): array
{
    $stanja = stanja_svih();
    $rez = [];
    foreach (db_all(
        "SELECT st.sku_id, SUM(st.kolicina) AS kom FROM spisak_stavke st JOIN spiskovi sp ON sp.id = st.spisak_id
         WHERE sp.status = 'za_utovar' AND sp.id <> ? AND st.sku_id IS NOT NULL GROUP BY st.sku_id",
        [$spisak_id]
    ) as $r) {
        $rez[(int)$r['sku_id']] = (int)$r['kom'];
    }
    $manjak = [];
    foreach ($stavke as $st) {
        if ($st['sku_id'] === null) {
            continue;
        }
        $sid = (int)$st['sku_id'];
        $slobodno = ($stanja[$sid] ?? 0) - ($rez[$sid] ?? 0);
        if ((int)$st['kolicina'] > $slobodno) {
            $manjak[(int)$st['stavka_id']] = ['slobodno' => $slobodno, 'trazi' => (int)$st['kolicina']];
        }
    }
    return $manjak;
}

/** Aktivni SKU-ovi za izbor stavke: [artikal => [[sku_id, naziv pakovanja/varijante, po_paleti, po_paketu], ...]]. */
function spisak_izbor_sku(): array
{
    $grupe = [];
    foreach (db_all(
        'SELECT s.id AS sku_id, s.po_paleti, s.po_paketu, a.naziv AS artikal, a.oznaka, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica
         FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
         JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE ' . SQL_AKTIVAN_SKU . '
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina'
    ) as $r) {
        $artikal = $r['artikal'] . ($r['oznaka'] ? ' (' . $r['oznaka'] . ')' : '');
        $grupe[$artikal][] = [
            (int)$r['sku_id'],
            ($r['varijanta'] !== null ? $r['varijanta'] . ' · ' : '') . pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica']),
            (int)$r['po_paleti'],
            (int)$r['po_paketu'],
        ];
    }
    return $grupe;
}

/** Zapis u dnevnik izmena za spisak. */
function spisak_dnevnik(int $id, string $tekst): void
{
    dnevnik_upis('spisak', 'spisak', $id, 'Spisak #' . $id . ': ' . $tekst);
}

/** Ranije upisana vozila ili vozači (za predlog pri kucanju). */
function spisak_predlozi(string $kolona): array
{
    if (!in_array($kolona, ['vozilo', 'vozac'], true)) {
        return [];
    }
    return array_column(db_all(
        "SELECT $kolona AS v, MAX(izmenjen) AS z FROM spiskovi WHERE $kolona IS NOT NULL AND $kolona <> '' GROUP BY $kolona ORDER BY z DESC LIMIT 30"
    ), 'v');
}

/** Provera i čišćenje zaglavlja spiska iz forme. Vraća [podaci, greška]. */
function spisak_zaglavlje_iz_posta(): array
{
    $p = [
        'kupac'    => post_str('kupac', 120),
        'datum'    => post_str('datum', 10),
        'vozilo'   => post_str('vozilo', 60),
        'vozac'    => post_str('vozac', 60),
        'napomena' => post_str('napomena', 255),
    ];
    if ($p['kupac'] === '') {
        return [$p, 'Upišite ili izaberite kupca.'];
    }
    if (!je_datum($p['datum'])) {
        return [$p, 'Izaberite datum utovara.'];
    }
    return [$p, null];
}

/**
 * Prelaz statusa (bez utovara – to je faza potvrde). Proverava trenutni status pod zaključavanjem,
 * pa dva klika ili dve otvorene kartice ne mogu da naprave zbrku.
 * Vraća ['ok' => bool, 'greska' => ?string].
 */
function spisak_promeni_status(int $id, string $ocekivan, string $novi, int $korisnik_id): array
{
    $dozvoljeno = [
        'nacrt'     => ['za_utovar', 'otkazano'],
        'za_utovar' => ['nacrt', 'otkazano'],
        'otkazano'  => ['nacrt'],
    ];
    return db_trans(static function () use ($id, $ocekivan, $novi, $korisnik_id, $dozvoljeno): array {
        $sp = spisak_zakljucaj($id);
        if ($sp === null) {
            return ['ok' => false, 'greska' => 'Spisak ne postoji.'];
        }
        if ($sp['status'] !== $ocekivan) {
            return ['ok' => false, 'greska' => 'Spisak je u međuvremenu promenjen (sada: ' . SPISAK_STATUSI[$sp['status']] . '). Pogledajte ga ponovo.'];
        }
        if (!in_array($novi, $dozvoljeno[$ocekivan] ?? [], true)) {
            return ['ok' => false, 'greska' => 'Ta promena nije moguća.'];
        }
        if ($novi === 'nacrt' && $ocekivan === 'otkazano' && $sp['utovareno_u'] !== null) {
            return ['ok' => false, 'greska' => 'Spisak koji je bio utovaren ne može ponovo da se otvori. Napravite novi spisak.'];
        }
        if ($novi === 'za_utovar' && (int)db_val('SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id = ?', [$id]) === 0) {
            return ['ok' => false, 'greska' => 'Spisak nema nijednu stavku.'];
        }
        $sad = sada();
        if ($novi === 'otkazano') {
            db_run('UPDATE spiskovi SET status = ?, otkazao_id = ?, otkazano_u = ?, izmenjen = ? WHERE id = ?', [$novi, $korisnik_id, $sad, $sad, $id]);
        } elseif ($novi === 'nacrt' && $ocekivan === 'otkazano') {
            db_run('UPDATE spiskovi SET status = ?, otkazao_id = NULL, otkazano_u = NULL, izmenjen = ? WHERE id = ?', [$novi, $sad, $id]);
        } else {
            db_run('UPDATE spiskovi SET status = ?, izmenjen = ? WHERE id = ?', [$novi, $sad, $id]);
        }
        return ['ok' => true, 'greska' => null];
    });
}
