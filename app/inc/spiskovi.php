<?php
/**
 * Spisak za utovar (jedan kamion): datum, vozilo, vozač, mesta istovara i status. Mesto istovara ima broj
 * koji upisuje vlasnik (kao zaokružen broj na papiru: 8, 9, 5 …), kupca (nije obavezan) i svoju robu.
 *
 *   nacrt      – slobodno se menja, stanje se ne dira
 *   za_utovar  – radnici ga vide, količine su rezervisane
 *   utovareno  – potvrđeno; tek tada se skida stanje (faza 3)
 *   otkazano   – ništa se ne rezerviše; ako je bio utovaren, roba se vraća (povrat)
 *
 * Stavka je artikal iz aplikacije (sku_id, količina u komadima, kako je uneto: palete + paketi + komadi)
 * ili "ostalo" (sku_id = NULL): roba koja se ne vodi u aplikaciji i ne dira stanje.
 * Zbirna paleta (spisak_palete) grupiše stavke koje se slažu na istu paletu; kod njih je "kako je uneto"
 * količina za JEDNU paletu (paketi + komadi), a kolicina = ta količina × broj ovakvih paleta.
 */
declare(strict_types=1);

const SPISAK_STATUSI = [
    'nacrt'     => 'Nacrt',
    'za_utovar' => 'Za utovar',
    'utovareno' => 'Utovareno',
    'otkazano'  => 'Otkazano',
];

/**
 * "kupac" = kupci sa mesta, redom ("Agrocentar, Cvećara Flora"; null ako nijedno mesto nema kupca),
 * "mesta" = brojevi mesta redom ("8, 9, 5"), broj_mesta = koliko mesta ima utovar.
 */
const SPISAK_POLJA = 'sp.*, '
    . "(SELECT GROUP_CONCAT(km.naziv ORDER BY m.redosled, m.id SEPARATOR ', ') FROM spisak_mesta m JOIN kupci km ON km.id = m.kupac_id WHERE m.spisak_id = sp.id) AS kupac, "
    . "(SELECT GROUP_CONCAT(m.broj ORDER BY m.redosled, m.id SEPARATOR ', ') FROM spisak_mesta m WHERE m.spisak_id = sp.id) AS mesta, "
    . '(SELECT COUNT(*) FROM spisak_mesta m WHERE m.spisak_id = sp.id) AS broj_mesta, '
    . 'n.ime AS napravio, ut.ime AS utovario, ot.ime AS otkazao';
const SPISAK_IZ = 'FROM spiskovi sp JOIN korisnici n ON n.id = sp.napravio_id '
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

/** Mesta istovara jednog utovara, redom kako su na spisku, sa nazivom kupca (ako ga ima). */
function spisak_mesta(int $spisak_id): array
{
    return db_all(
        'SELECT m.*, k.naziv AS kupac FROM spisak_mesta m LEFT JOIN kupci k ON k.id = m.kupac_id WHERE m.spisak_id = ? ORDER BY m.redosled, m.id',
        [$spisak_id]
    );
}

/** "Mesto 8 · Cvećara Flora" ili samo "Mesto 8" */
function mesto_naslov(int $broj, ?string $kupac): string
{
    return 'Mesto ' . $broj . ($kupac !== null && $kupac !== '' ? ' · ' . $kupac : '');
}

/** "1 mesto", "3 mesta", "21 mesto" */
function mesta_tekst(int $n): string
{
    return $n . ($n % 10 === 1 && $n % 100 !== 11 ? ' mesto' : ' mesta');
}

/** "1 stavka", "3 stavke", "5 stavki" */
function stavki_tekst(int $n): string
{
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        return $n . ' stavka';
    }
    return $n . ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? ' stavke' : ' stavki');
}

/**
 * Naslov spiska: jedno mesto – kupac (ili "Mesto 8"); više mesta – "3 mesta: 8, 9, 5" (kupci idu ispod, spisak_kupci()).
 */
function spisak_naslov(array $sp): string
{
    $n = (int)($sp['broj_mesta'] ?? 0);
    if ($n === 0) {
        return 'Spisak #' . (int)$sp['id'] . ' (bez mesta)';
    }
    if ($n === 1) {
        return !empty($sp['kupac']) ? (string)$sp['kupac'] : 'Mesto ' . $sp['mesta'];
    }
    return mesta_tekst($n) . ': ' . $sp['mesta'];
}

/** Kupci sa mesta kad utovar ima više mesta ("Agrocentar, Cvećara Flora"), inače ''. */
function spisak_kupci(array $sp): string
{
    return (int)($sp['broj_mesta'] ?? 0) > 1 && !empty($sp['kupac']) ? (string)$sp['kupac'] : '';
}

/** Sledeći slobodan broj mesta na utovaru (najveći + 1). */
function mesto_sledeci_broj(int $spisak_id): int
{
    return (int)db_val('SELECT COALESCE(MAX(broj), 0) FROM spisak_mesta WHERE spisak_id = ?', [$spisak_id]) + 1;
}

/**
 * Stavke po mestima: [mesto_id => ['broj' => broj mesta, 'mesto' => red, 'stavke' => [...], 'grupe' => spisak_grupe()]],
 * redom kao mesta na spisku (i prazna mesta). Stavke bez mesta (stari podaci) idu u grupu 0 na kraju.
 */
function spisak_po_mestima(array $stavke, array $mesta): array
{
    $rez = [];
    foreach ($mesta as $m) {
        $rez[(int)$m['id']] = ['broj' => (int)$m['broj'], 'mesto' => $m, 'stavke' => []];
    }
    foreach ($stavke as $st) {
        $mid = (int)$st['mesto_id'];
        if (!isset($rez[$mid])) {
            $rez[$mid] = ['broj' => 0, 'mesto' => null, 'stavke' => []];
        }
        $rez[$mid]['stavke'][] = $st;
    }
    foreach ($rez as $mid => $r) {
        $rez[$mid]['grupe'] = spisak_grupe($r['stavke']);
    }
    return $rez;
}

/** Stavke spiska sa podacima o artiklu (za "ostalo" su polja artikla null), redom po mestima. */
function spisak_stavke(int $spisak_id): array
{
    return db_all(
        'SELECT st.id AS stavka_id, st.spisak_id, st.mesto_id, m.broj AS mesto_broj, m.kupac_id AS mesto_kupac_id, mk.naziv AS mesto_kupac,
                st.sku_id, st.naziv AS ostalo_naziv, st.jedinica AS ostalo_jedinica,
                st.tezina_kg AS ostalo_kg, st.kolicina, st.palete AS u_palete, st.paketi AS u_paketi, st.komadi AS u_komadi,
                st.utovareno, st.redosled, st.dodao_id, d.ime AS dodao,
                st.paleta_id, pl.naziv AS paleta_naziv, pl.broj AS paleta_broj,
                s.sifra, s.po_paleti, s.po_paketu, s.tezina_kg, a.naziv AS artikal, a.oznaka, v.naziv AS varijanta,
                p.kolicina AS pak_kolicina, p.jedinica
         FROM spisak_stavke st
         LEFT JOIN spisak_mesta m ON m.id = st.mesto_id
         LEFT JOIN kupci mk ON mk.id = m.kupac_id
         LEFT JOIN spisak_palete pl ON pl.id = st.paleta_id
         LEFT JOIN korisnici d ON d.id = st.dodao_id
         LEFT JOIN sku s ON s.id = st.sku_id
         LEFT JOIN artikli a ON a.id = s.artikal_id
         LEFT JOIN pakovanja p ON p.id = s.pakovanje_id
         LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE st.spisak_id = ?
         ORDER BY (m.id IS NULL), m.redosled, st.mesto_id, (st.paleta_id > 0), pl.redosled, st.paleta_id, (st.sku_id IS NULL), st.redosled, st.id',
        [$spisak_id]
    );
}

/** Zbirne palete spiska (i prazne), redom. */
function spisak_palete(int $spisak_id): array
{
    return db_all('SELECT * FROM spisak_palete WHERE spisak_id = ? ORDER BY redosled, id', [$spisak_id]);
}

/** Stavke po grupama: [0 => posebne stavke, paleta_id => stavke te zbirne palete]; uz svaku grupu naziv i broj. */
function spisak_grupe(array $stavke): array
{
    $grupe = [0 => ['naziv' => null, 'broj' => 0, 'stavke' => []]];
    foreach ($stavke as $st) {
        $pid = (int)$st['paleta_id'];
        if (!isset($grupe[$pid])) {
            $grupe[$pid] = ['naziv' => (string)$st['paleta_naziv'], 'broj' => (int)$st['paleta_broj'], 'stavke' => []];
        }
        $grupe[$pid]['stavke'][] = $st;
    }
    return $grupe;
}

/** "1 artikal", "3 artikla", "5 artikala" */
function artikala_tekst(int $n): string
{
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        $rec = 'artikal';
    } elseif ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
        $rec = 'artikla';
    } else {
        $rec = 'artikala';
    }
    return $n . ' ' . $rec;
}

/** "Zbirna paleta 1 (2 palete)" */
function paleta_naslov(string $naziv, int $broj): string
{
    return $naziv . ($broj > 1 ? ' (' . palete_tekst($broj) . ')' : '');
}

/** Naziv stavke: "Humovit · 10 l" ili naziv "ostalog". */
function stavka_naziv(array $st): string
{
    return $st['sku_id'] === null ? (string)$st['ostalo_naziv'] : sku_naziv($st);
}

/** Kako stavka piše na spisku: "H10L", "B oblutak 4/6"; za "ostalo" njen naziv. */
function stavka_kratko(array $st): string
{
    return $st['sku_id'] === null ? (string)$st['ostalo_naziv'] : sku_kratko($st);
}

/** Pun naziv sitno ispod šifre (samo uz šifru kao H50L – ime kao "B oblutak 4/6" je već jasno). */
function stavka_ime_ispod(array $st): ?string
{
    return $st['sku_id'] !== null && sifra_je_kod($st['sifra'] ?? null) ? sku_naziv($st) : null;
}

/**
 * Kratko ime sa zbirnom paletom (i mestom, kad utovar ima više mesta): "H10L (mesto 8, Zbirna paleta 1)".
 */
function stavka_puni_naziv(array $st, bool $sa_mestom = false): string
{
    $dodaci = [];
    if ($sa_mestom && $st['mesto_broj'] !== null) {
        $dodaci[] = 'mesto ' . (int)$st['mesto_broj'];
    }
    if ((int)($st['paleta_id'] ?? 0) > 0) {
        $dodaci[] = (string)$st['paleta_naziv'];
    }
    return stavka_kratko($st) . ($dodaci ? ' (' . implode(', ', $dodaci) . ')' : '');
}

/** Da li stavke idu za više od jednog mesta (kupca). */
function vise_mesta(array $stavke): bool
{
    return count(array_unique(array_map(static fn(array $st): int => (int)$st['mesto_id'], $stavke))) > 1;
}

/** Kako je količina uneta: "2 pal + 3 pak + 5 kom" (za "ostalo": "40 kom"). */
function stavka_unos_tekst(array $st): string
{
    if ($st['sku_id'] === null) {
        return broj((int)$st['kolicina']) . ' ' . ($st['ostalo_jedinica'] ?: 'kom');
    }
    if ((int)($st['paleta_id'] ?? 0) > 0) {
        $delovi = [];
        if ((int)$st['u_paketi'] > 0) {
            $delovi[] = (int)$st['u_paketi'] . ' pak';
        }
        if ((int)$st['u_komadi'] > 0 || !$delovi) {
            $delovi[] = broj((int)$st['u_komadi']) . ' kom';
        }
        return implode(' + ', $delovi) . ((int)$st['paleta_broj'] > 1 ? ' na paleti' : '');
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

/**
 * Plan stavke za prikaz: "1 pal + 2 pak = 282 kom", "5 kom na paleti × 2 = 10 kom", "40 kom".
 */
function stavka_plan_tekst(array $st): string
{
    $t = stavka_unos_tekst($st);
    if ($st['sku_id'] === null) {
        return $t;
    }
    $kol = broj((int)$st['kolicina']) . ' kom';
    if ((int)($st['paleta_id'] ?? 0) > 0) {
        if ((int)$st['paleta_broj'] > 1) {
            return $t . ' × ' . (int)$st['paleta_broj'] . ' = ' . $kol;
        }
        return (int)$st['u_paketi'] > 0 ? $t . ' = ' . $kol : $t;
    }
    return ((int)$st['u_palete'] > 0 || (int)$st['u_paketi'] > 0) ? $t . ' = ' . $kol : $t;
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
 * Zbir spiska: ukupno paleta (komadi / komada po paleti, a zbirna paleta je cela paleta), težina u kg
 * i šta nije moglo da se uračuna.
 * ['palete' => float, 'zbirnih' => int, 'kg' => float, 'bez_palete' => int, 'bez_tezine' => int, 'stavki' => int]
 */
function spisak_zbir(array $stavke, bool $utovareno = false): array
{
    $z = ['palete' => 0.0, 'zbirnih' => 0, 'kg' => 0.0, 'bez_palete' => 0, 'bez_tezine' => 0, 'stavki' => count($stavke)];
    $vidjene = [];
    foreach ($stavke as $st) {
        $kol = $utovareno && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
        $pid = (int)($st['paleta_id'] ?? 0);
        if ($pid > 0) {
            if (!isset($vidjene[$pid])) {
                $vidjene[$pid] = true;
                $z['palete'] += (int)$st['paleta_broj'];
                $z['zbirnih'] += (int)$st['paleta_broj'];
            }
        } elseif ($st['sku_id'] !== null) {
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

/** "≈ 7,5 paleta" (sasvim malo robe: "< 0,1 paleta") */
function palete_zbir_tekst(float $p): string
{
    $zaokruzeno = round($p, 1);
    if ($p > 0 && $zaokruzeno == 0) {
        return '< 0,1 paleta';
    }
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
    // isti artikal može biti i posebna stavka i u zbirnoj paleti – gleda se ukupno na spisku
    $na_spisku = [];
    foreach ($stavke as $st) {
        if ($st['sku_id'] !== null) {
            $na_spisku[(int)$st['sku_id']] = ($na_spisku[(int)$st['sku_id']] ?? 0) + (int)$st['kolicina'];
        }
    }
    $manjak = [];
    foreach ($stavke as $st) {
        if ($st['sku_id'] === null) {
            continue;
        }
        $sid = (int)$st['sku_id'];
        $slobodno = ($stanja[$sid] ?? 0) - ($rez[$sid] ?? 0);
        if ($na_spisku[$sid] > $slobodno) {
            $manjak[(int)$st['stavka_id']] = ['slobodno' => $slobodno, 'trazi' => $na_spisku[$sid], 'stavka' => (int)$st['kolicina']];
        }
    }
    return $manjak;
}

/**
 * Koliko ide na JEDNU zbirnu paletu, iz jednog polja: "25" (komada), "1/2" ili "pola" (deo pune palete
 * tog artikla – rizla 50 po paleti, pola = 25), "3 pak" (paketa).
 * Vraća ['komadi' => ukupno komada na paleti, 'paketi' => n, 'kom' => n] ili ['greska' => tekst].
 */
function kolicina_na_paleti(string $t, array $sku): array
{
    $t = mb_strtolower((string)preg_replace('/\s+/u', '', $t));
    $ime = sku_kratko($sku);
    if ($t === 'pola' || $t === '½') {
        $t = '1/2';
    }
    if ($t === '') {
        return ['greska' => $ime . ': upišite koliko ide na paletu (npr. 25 ili 1/2).'];
    }
    if (ctype_digit($t)) {
        if ((int)$t < 1 || (int)$t > 99999) {
            return ['greska' => $ime . ': količina mora biti od 1 do 99.999 komada.'];
        }
        return ['komadi' => (int)$t, 'paketi' => 0, 'kom' => (int)$t];
    }
    if (preg_match('#^(\d{1,3})/(\d{1,3})$#', $t, $m)) {
        [$a, $b] = [(int)$m[1], (int)$m[2]];
        if ($a < 1 || $b < 1 || $a > $b) {
            return ['greska' => $ime . ': deo palete se piše kao 1/2, 1/3 ili 3/4.'];
        }
        if ((int)$sku['po_paleti'] <= 0) {
            return ['greska' => $ime . ': nije podešeno koliko komada ide na paletu – upišite broj komada.'];
        }
        $n = (int)round((int)$sku['po_paleti'] * $a / $b);
        return $n < 1 ? ['greska' => $ime . ': ' . $a . '/' . $b . ' palete je manje od jednog komada.'] : ['komadi' => $n, 'paketi' => 0, 'kom' => $n];
    }
    if (preg_match('/^(\d{1,4})(p|pak|paket|paketa)$/u', $t, $m)) {
        if ((int)$sku['po_paketu'] <= 0) {
            return ['greska' => $ime . ': ovaj artikal se ne pakuje u pakete – upišite broj komada.'];
        }
        if ((int)$m[1] < 1) {
            return ['greska' => $ime . ': upišite koliko paketa (1 ili više).'];
        }
        return ['komadi' => (int)$m[1] * (int)$sku['po_paketu'], 'paketi' => (int)$m[1], 'kom' => 0];
    }
    return ['greska' => $ime . ': količinu upišite kao broj komada (25), deo palete (1/2) ili pakete (3 pak).'];
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
        if ($novi === 'za_utovar') {
            if ((int)db_val('SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id = ?', [$id]) === 0) {
                return ['ok' => false, 'greska' => 'Spisak nema nijednu stavku.'];
            }
            foreach (spisak_mesta($id) as $m) {
                if ((int)db_val('SELECT COUNT(*) FROM spisak_stavke WHERE mesto_id = ?', [(int)$m['id']]) === 0) {
                    return ['ok' => false, 'greska' => mesto_naslov((int)$m['broj'], $m['kupac']) . ' nema nijednu stavku – dodajte robu ili uklonite mesto.'];
                }
            }
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
        "SELECT sku_id, kupac_id, SUM(CASE WHEN tip = 'utovar' THEN kolicina ELSE -kolicina END) AS kom
         FROM unosi WHERE spisak_id = ? AND obrisan = 0 AND tip IN ('utovar', 'povrat') GROUP BY sku_id, kupac_id ORDER BY sku_id, kupac_id",
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
            [(int)$r['sku_id'], (int)$r['kom'], $korisnik_id, $r['kupac_id'] === null ? null : (int)$r['kupac_id'], (int)$sp['id'], 'Otkazan spisak #' . (int)$sp['id'], $sad, $sad]
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
    $sa_kupcem = vise_mesta($stavke);
    foreach ($stavke as $st) {
        $sid = (int)$st['stavka_id'];
        if (($ok[$sid] ?? '') !== '1') {
            $neoznaceno[] = stavka_puni_naziv($st, $sa_kupcem);
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
        $sa_kupcem = vise_mesta($stavke);
        $kupci = [];
        foreach ($stavke as $st) {
            $sid = (int)$st['stavka_id'];
            $k = $kolicine[$sid];
            db_run('UPDATE spisak_stavke SET utovareno = ? WHERE id = ?', [$k['kolicina'], $sid]);
            if ($k['kolicina'] !== (int)$st['kolicina']) {
                $razlike[] = stavka_puni_naziv($st, $sa_kupcem) . ': ' . broj($k['kolicina']) . ' umesto ' . broj((int)$st['kolicina']);
            }
            // roba se vodi na kupca svog mesta (stari podaci bez mesta: kupac spiska)
            $kupac_id = $st['mesto_kupac_id'] !== null ? (int)$st['mesto_kupac_id'] : ($sp['kupac_id'] !== null ? (int)$sp['kupac_id'] : null);
            if ($kupac_id !== null) {
                $kupci[$kupac_id] = true;
            }
            if ($st['sku_id'] === null || $k['kolicina'] === 0) {
                continue;
            }
            db_run(
                "INSERT INTO unosi (tip, sku_id, kolicina, palete, paketi, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto)
                 VALUES ('utovar', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int)$st['sku_id'], $k['kolicina'], $k['palete'], $k['paketi'], $korisnik_id, $kupac_id, $id,
                 'Spisak #' . $id . ($st['mesto_broj'] !== null ? ' – mesto ' . (int)$st['mesto_broj'] : '')
                 . ((int)$st['paleta_id'] > 0 ? ' – ' . $st['paleta_naziv'] : ''), $sad, $sad]
            );
        }
        db_run("UPDATE spiskovi SET status = 'utovareno', utovario_id = ?, utovareno_u = ?, izmenjen = ? WHERE id = ?", [$korisnik_id, $sad, $sad, $id]);
        foreach (array_keys($kupci) as $kupac_id) {
            db_run('UPDATE kupci SET poslednja_prodaja = ? WHERE id = ?', [$sad, $kupac_id]);
        }
        return ['ok' => true, 'vec' => false, 'greska' => null, 'razlike' => $razlike];
    });
}

/** Tekst spiska za deljenje (Viber, WhatsApp, SMS), po mestima istovara. */
function spisak_tekst_za_deljenje(array $sp, array $stavke): string
{
    $utovaren = $sp['status'] === 'utovareno';
    $z = spisak_zbir($stavke, $utovaren);
    $l = [];
    $l[] = 'Farmerkop – spisak za utovar #' . (int)$sp['id'] . ($utovaren ? ' (utovareno)' : '');
    $l[] = 'Utovar: ' . dan_u_nedelji((string)$sp['datum']) . ', ' . datum_srp((string)$sp['datum']);
    if ($sp['vozilo']) {
        $l[] = 'Vozilo: ' . $sp['vozilo'];
    }
    if ($sp['vozac']) {
        $l[] = 'Vozač: ' . $sp['vozac'];
    }
    foreach (spisak_po_mestima($stavke, spisak_mesta((int)$sp['id'])) as $mesto) {
        if (!$mesto['stavke']) {
            continue;
        }
        $l[] = '';
        $m = $mesto['mesto'];
        $l[] = $m !== null
            ? 'MESTO ' . $mesto['broj'] . ($m['kupac'] ? ' – ' . $m['kupac'] : '') . ($m['adresa'] ? ' (' . $m['adresa'] . ')' : '')
            : 'BEZ MESTA';
        $n = 0;
        foreach ($mesto['grupe'] as $pid => $g) {
            if (!$g['stavke']) {
                continue;
            }
            if ($pid > 0) {
                $l[] = '  ZBIRNA PALETA – ' . paleta_naslov($g['naziv'], $g['broj']) . ':';
            }
            foreach ($g['stavke'] as $st) {
                $n++;
                $kol = $utovaren && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
                $opis = $utovaren && $kol !== (int)$st['kolicina'] ? broj($kol) . ' kom (utovareno)' : stavka_plan_tekst($st);
                $l[] = ($pid > 0 ? '     ' : '  ') . $n . '. ' . stavka_kratko($st) . ' – ' . $opis . ($st['dodao_id'] !== null ? ' (dodato pri utovaru)' : '');
            }
        }
    }
    if ($sp['napomena']) {
        $l[] = '';
        $l[] = 'Napomena: ' . $sp['napomena'];
    }
    $l[] = '';
    $l[] = 'Ukupno: ' . ($z['palete'] > 0 ? palete_zbir_tekst($z['palete']) . ($z['zbirnih'] > 0 ? ' (zbirnih: ' . $z['zbirnih'] . ')' : '') . ', ' : '') . '≈ ' . kg_tekst($z['kg']);
    return implode("\n", $l);
}

/**
 * Utovar šlepera na licu mesta: radnik (ili administrator) dodaje artikal kog nema na spisku, dok spisak
 * čeka utovar. Stavka pamti ko ju je dodao. Vraća ['ok' => bool, 'greska' => ?string, 'stavka_id' => ?int].
 */
function spisak_dodaj_pri_utovaru(int $id, int $mesto_id, array $sku, int $komadi, int $pal, int $pak, int $kom, int $korisnik_id): array
{
    return db_trans(static function () use ($id, $mesto_id, $sku, $komadi, $pal, $pak, $kom, $korisnik_id): array {
        $ne = static fn(string $greska): array => ['ok' => false, 'greska' => $greska, 'stavka_id' => null, 'mesto' => null];
        $sp = spisak_zakljucaj($id);
        if ($sp === null || $sp['status'] !== 'za_utovar') {
            return $ne('Spisak više ne čeka utovar.');
        }
        $mesta = spisak_mesta($id);
        if ($mesto_id === 0 && count($mesta) === 1) {
            $mesto_id = (int)$mesta[0]['id'];           // jedno mesto – ne mora da se bira
        }
        $mesto = null;
        foreach ($mesta as $m) {
            if ((int)$m['id'] === $mesto_id) {
                $mesto = $m;
            }
        }
        if ($mesto === null && ($mesta || $mesto_id !== 0)) {
            return $ne('Izaberite za koje mesto je roba (Za mesto).');
        }
        if (db_val('SELECT id FROM spisak_stavke WHERE spisak_id = ? AND mesto_id = ? AND paleta_id = 0 AND sku_id = ?', [$id, $mesto_id, (int)$sku['sku_id']]) !== null) {
            return $ne(sku_oznaka($sku) . ' je već na spisku' . ($mesto !== null ? ' za ' . mesto_naslov((int)$mesto['broj'], $mesto['kupac']) : '')
                . ' – kod te stavke dodirnite „Više ili manje od plana“ i upišite količinu.');
        }
        $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
        db_run(
            'INSERT INTO spisak_stavke (spisak_id, mesto_id, sku_id, kolicina, palete, paketi, komadi, dodao_id, redosled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $mesto_id, (int)$sku['sku_id'], $komadi, $pal, $pak, $kom, $korisnik_id, $red]
        );
        $stavka_id = db_id();
        db_run('UPDATE spiskovi SET izmenjen = ? WHERE id = ?', [sada(), $id]);
        return ['ok' => true, 'greska' => null, 'stavka_id' => $stavka_id, 'mesto' => $mesto];
    });
}
