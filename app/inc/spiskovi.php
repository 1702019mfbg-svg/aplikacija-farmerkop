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
                st.utovareno, st.redosled, st.dodao_id, d.ime AS dodao,
                s.po_paleti, s.po_paketu, s.tezina_kg, a.naziv AS artikal, a.oznaka, v.naziv AS varijanta,
                p.kolicina AS pak_kolicina, p.jedinica
         FROM spisak_stavke st
         LEFT JOIN korisnici d ON d.id = st.dodao_id
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
        'utovareno' => ['otkazano'],
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
        if ($novi === 'otkazano' && $ocekivan === 'utovareno') {
            spisak_vrati_robu($sp, $korisnik_id);
        }
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

/**
 * Otkazivanje utovarenog spiska: sve što je skinuto sa stanja vraća se kao "povrat" (ništa se ne briše).
 * Poziva se unutar transakcije, sa zaključanim spiskom.
 */
function spisak_vrati_robu(array $sp, int $korisnik_id): void
{
    $redovi = db_all(
        "SELECT sku_id, SUM(CASE WHEN tip = 'utovar' THEN kolicina ELSE -kolicina END) AS kom
         FROM unosi WHERE spisak_id = ? AND obrisan = 0 AND tip IN ('utovar', 'povrat') GROUP BY sku_id ORDER BY sku_id",
        [(int)$sp['id']]
    );
    $sad = sada();
    foreach ($redovi as $r) {
        if ((int)$r['kom'] <= 0) {
            continue;
        }
        sku_zakljucaj((int)$r['sku_id']);
        db_run(
            "INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto)
             VALUES ('povrat', ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int)$r['sku_id'], (int)$r['kom'], $korisnik_id, (int)$sp['kupac_id'], (int)$sp['id'], 'Otkazan spisak #' . (int)$sp['id'], $sad, $sad]
        );
    }
}

/**
 * Stvarno utovarena količina iz forme potvrde, po stavkama.
 * Vraća [[stavka_id => ['kolicina' => n, 'palete' => n|null, 'paketi' => n|null]], greška|null].
 * Svaka stavka mora biti štiklirana; količina može da se ispravi (i na 0 ako nije utovareno ništa).
 */
function utovar_iz_posta(array $stavke): array
{
    $niz = static fn(string $k): array => is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
    [$ok, $pal, $pak, $kom, $okol] = [$niz('ok'), $niz('pal'), $niz('pak'), $niz('kom'), $niz('o_kol')];
    $rez = [];
    $neoznaceno = [];
    foreach ($stavke as $st) {
        $sid = (int)$st['stavka_id'];
        if (($ok[$sid] ?? '') !== '1') {
            $neoznaceno[] = stavka_naziv($st);
            continue;
        }
        if ($st['sku_id'] === null) {
            $v = is_string($okol[$sid] ?? null) ? trim($okol[$sid]) : (string)$st['kolicina'];
            if ($v === '' || !ctype_digit($v) || strlen($v) > 8) {
                return [[], stavka_naziv($st) . ': količina mora biti ceo broj (0 ili veći).'];
            }
            $rez[$sid] = ['kolicina' => (int)$v, 'palete' => null, 'paketi' => null];
            continue;
        }
        $ima_unos = isset($pal[$sid]) || isset($pak[$sid]) || isset($kom[$sid]);
        if (!$ima_unos) {
            $rez[$sid] = ['kolicina' => (int)$st['kolicina'], 'palete' => (int)$st['u_palete'] ?: null, 'paketi' => (int)$st['u_paketi'] ?: null];
            continue;
        }
        $r = popis_vrednost('', $pal[$sid] ?? '', $pak[$sid] ?? '', $kom[$sid] ?? '', $st);
        if (isset($r['greska'])) {
            return [[], $r['greska']];
        }
        $rez[$sid] = [
            'kolicina' => (int)($r['komadi'] ?? 0),
            'palete'   => is_string($pal[$sid] ?? null) && (int)$pal[$sid] > 0 ? (int)$pal[$sid] : null,
            'paketi'   => is_string($pak[$sid] ?? null) && (int)$pak[$sid] > 0 ? (int)$pak[$sid] : null,
        ];
    }
    if ($neoznaceno) {
        return [[], 'Štiklirajte svaku stavku kad je utovarena (nedostaje: ' . implode(', ', array_slice($neoznaceno, 0, 4))
            . (count($neoznaceno) > 4 ? ' …' : '') . '). Ako nešto nije utovareno, upišite količinu 0.'];
    }
    return [$rez, null];
}

/**
 * Potvrda utovara – jedna nedeljiva operacija: zaključa spisak, proveri da još čeka utovar,
 * upiše stvarno utovarene količine, skine stanje (vrsta "utovar") i označi spisak kao utovaren.
 * Drugi klik, loš internet ili drugi telefon ne mogu da skinu stanje dvaput: drugi pokušaj zatiče
 * spisak već utovaren i ništa ne upisuje. Stanje sme da ode u minus (roba je već na kamionu).
 * Vraća ['ok' => bool, 'vec' => bool (već utovaren), 'greska' => ?string, 'razlike' => [tekst]].
 */
function spisak_potvrdi_utovar(int $id, array $kolicine, int $korisnik_id): array
{
    return db_trans(static function () use ($id, $kolicine, $korisnik_id): array {
        $sp = spisak_zakljucaj($id);
        if ($sp === null) {
            return ['ok' => false, 'vec' => false, 'greska' => 'Spisak ne postoji.', 'razlike' => []];
        }
        if ($sp['status'] === 'utovareno') {
            return ['ok' => false, 'vec' => true, 'greska' => 'Ovaj spisak je već potvrđen kao utovaren – ništa nije upisano ponovo.', 'razlike' => []];
        }
        if ($sp['status'] !== 'za_utovar') {
            return ['ok' => false, 'vec' => false, 'greska' => 'Spisak više ne čeka utovar (administrator ga je vratio u nacrt ili otkazao).', 'razlike' => []];
        }
        $stavke = spisak_stavke($id);
        $ids = array_map(static fn(array $st): int => (int)$st['stavka_id'], $stavke);
        sort($ids);
        $poslato = array_keys($kolicine);
        sort($poslato);
        if ($ids !== $poslato) {
            return ['ok' => false, 'vec' => false, 'greska' => 'Spisak je u međuvremenu izmenjen. Otvorite ga ponovo.', 'razlike' => []];
        }
        $sku_ids = array_values(array_unique(array_filter(array_map(static fn(array $st): int => (int)$st['sku_id'], $stavke))));
        sort($sku_ids);
        foreach ($sku_ids as $sku_id) {
            sku_zakljucaj($sku_id);       // uvek istim redom, da se dva utovara ne zaglave
        }
        $sad = sada();
        $razlike = [];
        foreach ($stavke as $st) {
            $sid = (int)$st['stavka_id'];
            $k = $kolicine[$sid];
            db_run('UPDATE spisak_stavke SET utovareno = ? WHERE id = ?', [$k['kolicina'], $sid]);
            if ($k['kolicina'] !== (int)$st['kolicina']) {
                $razlike[] = stavka_naziv($st) . ': ' . broj($k['kolicina']) . ' umesto ' . broj((int)$st['kolicina']);
            }
            if ($st['sku_id'] === null || $k['kolicina'] === 0) {
                continue;
            }
            db_run(
                "INSERT INTO unosi (tip, sku_id, kolicina, palete, paketi, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto)
                 VALUES ('utovar', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int)$st['sku_id'], $k['kolicina'], $k['palete'], $k['paketi'], $korisnik_id, (int)$sp['kupac_id'], $id, 'Spisak #' . $id, $sad, $sad]
            );
        }
        db_run("UPDATE spiskovi SET status = 'utovareno', utovario_id = ?, utovareno_u = ?, izmenjen = ? WHERE id = ?", [$korisnik_id, $sad, $sad, $id]);
        db_run('UPDATE kupci SET poslednja_prodaja = ? WHERE id = ?', [$sad, (int)$sp['kupac_id']]);
        return ['ok' => true, 'vec' => false, 'greska' => null, 'razlike' => $razlike];
    });
}

/** Tekst spiska za deljenje (Viber, WhatsApp, SMS). */
function spisak_tekst_za_deljenje(array $sp, array $stavke): string
{
    $utovaren = $sp['status'] === 'utovareno';
    $z = spisak_zbir($stavke, $utovaren);
    $l = [];
    $l[] = 'Farmerkop – spisak za utovar #' . (int)$sp['id'] . ($utovaren ? ' (utovareno)' : '');
    $l[] = 'Kupac: ' . $sp['kupac'];
    $l[] = 'Utovar: ' . dan_u_nedelji((string)$sp['datum']) . ', ' . datum_srp((string)$sp['datum']);
    if ($sp['vozilo']) {
        $l[] = 'Vozilo: ' . $sp['vozilo'];
    }
    if ($sp['vozac']) {
        $l[] = 'Vozač: ' . $sp['vozac'];
    }
    $l[] = '';
    $n = 0;
    foreach ($stavke as $st) {
        $n++;
        $kol = $utovaren && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
        if ($st['sku_id'] === null) {
            $opis = broj($kol) . ' ' . ($st['ostalo_jedinica'] ?: 'kom');
        } else {
            $opis = ($utovaren && $kol !== (int)$st['kolicina'] ? broj($kol) . ' kom' : stavka_unos_tekst($st) . ((int)$st['u_palete'] > 0 || (int)$st['u_paketi'] > 0 ? ' = ' . broj($kol) . ' kom' : ''));
        }
        $l[] = $n . '. ' . stavka_naziv($st) . ' – ' . $opis . ($st['dodao_id'] !== null ? ' (dodato pri utovaru)' : '');
    }
    $l[] = '';
    $l[] = 'Ukupno: ' . ($z['palete'] > 0 ? palete_zbir_tekst($z['palete']) . ', ' : '') . '≈ ' . kg_tekst($z['kg']);
    if ($sp['napomena']) {
        $l[] = 'Napomena: ' . $sp['napomena'];
    }
    return implode("\n", $l);
}

/**
 * Utovar šlepera na licu mesta: radnik (ili administrator) dodaje artikal kog nema na spisku, dok spisak
 * čeka utovar. Stavka pamti ko ju je dodao. Vraća ['ok' => bool, 'greska' => ?string, 'stavka_id' => ?int].
 */
function spisak_dodaj_pri_utovaru(int $id, array $sku, int $komadi, int $pal, int $pak, int $kom, int $korisnik_id): array
{
    return db_trans(static function () use ($id, $sku, $komadi, $pal, $pak, $kom, $korisnik_id): array {
        $sp = spisak_zakljucaj($id);
        if ($sp === null || $sp['status'] !== 'za_utovar') {
            return ['ok' => false, 'greska' => 'Spisak više ne čeka utovar.', 'stavka_id' => null];
        }
        if (db_val('SELECT id FROM spisak_stavke WHERE spisak_id = ? AND sku_id = ?', [$id, (int)$sku['sku_id']]) !== null) {
            return ['ok' => false, 'greska' => sku_naziv($sku) . ' je već na spisku – kod te stavke dodirnite „Više ili manje od plana“ i upišite količinu.', 'stavka_id' => null];
        }
        $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
        db_run(
            'INSERT INTO spisak_stavke (spisak_id, sku_id, kolicina, palete, paketi, komadi, dodao_id, redosled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, (int)$sku['sku_id'], $komadi, $pal, $pak, $kom, $korisnik_id, $red]
        );
        $stavka_id = db_id();
        db_run('UPDATE spiskovi SET izmenjen = ? WHERE id = ?', [sada(), $id]);
        return ['ok' => true, 'greska' => null, 'stavka_id' => $stavka_id];
    });
}
