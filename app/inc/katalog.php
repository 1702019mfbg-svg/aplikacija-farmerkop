<?php
/**
 * Katalog proizvoda i stanje zaliha.
 *
 * Najmanja jedinica evidencije je SKU = artikal + (boja/granulacija) + pakovanje.
 * Sve količine se vode u KOMADIMA (vrećama). Paleta je samo način unosa:
 * palete × (komada po paleti) = komadi. Koliko komada ide na paletu čuva se po SKU-u,
 * jer isti artikal u istoj litraži može imati drugačiju paletu (npr. Idea 10 l = 225).
 */
declare(strict_types=1);

const TIPOVI_UNOSA = [
    'proizvodnja'   => 'Proizvodnja',
    'prodaja'       => 'Prodaja',
    'kucna_prodaja' => 'Kućna prodaja',
    'korekcija'     => 'Korekcija (popis)',
    'utovar'        => 'Utovar',
    'povrat'        => 'Povrat (otkazan utovar)',
];

/** Vrste koje smanjuju stanje; ostale ga povećavaju (korekcija može biti i negativna). */
const TIPOVI_MINUS = ['prodaja', 'kucna_prodaja', 'utovar'];

/** Promena stanja jednog reda iz tabele unosi (alias "u"): prodaja i utovar smanjuju, ostalo povećava. */
const SQL_PROMENA = "CASE WHEN u.tip IN ('prodaja','kucna_prodaja','utovar') THEN -u.kolicina ELSE u.kolicina END";

/** Težina jednog komada (kg): upisana vrednost, a za pakovanja u kg sama količina pakovanja (20 kg = 20). */
const SQL_TEZINA = "COALESCE(s.tezina_kg, CASE WHEN p.jedinica = 'kg' THEN p.kolicina END)";

/** Polja koja opisuju SKU; koristi se uz SKU_SPOJ nad tabelom unosi (alias "u"). */
const SKU_POLJA = 's.id AS sku_id, s.po_paleti, s.po_paketu, s.tezina_kg, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, '
    . 'a.naziv_varijante, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica';

const SKU_SPOJ = 'JOIN sku s ON s.id = u.sku_id '
    . 'JOIN artikli a ON a.id = s.artikal_id '
    . 'JOIN pakovanja p ON p.id = s.pakovanje_id '
    . 'LEFT JOIN varijante v ON v.id = s.varijanta_id';

/** "5 l", "2,5 l", "20 kg" */
function pakovanje_naziv(float|int|string $kolicina, string $jedinica): string
{
    $t = rtrim(rtrim(number_format((float)$kolicina, 2, ',', ''), '0'), ',');
    return $t . ' ' . $jedinica;
}

/** "Humovit · 20 l", "Malč Farmerkop · Crveni · 50 l", "Beli oblutak · 1-3 cm · 20 kg" */
function sku_naziv(array $r): string
{
    $delovi = [(string)$r['artikal']];
    if (!empty($r['varijanta'])) {
        $delovi[] = (string)$r['varijanta'];
    }
    $delovi[] = pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica']);
    return implode(' · ', $delovi);
}

/** Oblik množine za "paleta": 1 paleta, 2 palete, 5 paleta, 21 paleta. */
function palete_tekst(int $n): string
{
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        $rec = 'paleta';
    } elseif ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
        $rec = 'palete';
    } else {
        $rec = 'paleta';
    }
    return broj($n) . ' ' . $rec;
}

/** Oblik množine za "paket": 1 paket, 2 paketa, 5 paketa, 21 paket. */
function paketi_tekst(int $n): string
{
    return broj($n) . ((($n % 10) === 1 && ($n % 100) !== 11) ? ' paket' : ' paketa');
}

/** Kako je unos prikazan: "360 kom (3 palete)", "72 kom (12 paketa)" ili "35 kom". */
function kolicina_tekst(int $komadi, ?int $palete = null, ?int $paketi = null): string
{
    $t = broj($komadi) . ' kom';
    if ($palete) {
        return $t . ' (' . palete_tekst($palete) . ')';
    }
    return $paketi ? $t . ' (' . paketi_tekst($paketi) . ')' : $t;
}

/** Kako je unos upisan, ako nije u komadima: "3 palete", "12 paketa" ili prazan tekst. */
function nacin_unosa_tekst(array $u): string
{
    if (!empty($u['palete'])) {
        return palete_tekst((int)$u['palete']);
    }
    return !empty($u['paketi']) ? paketi_tekst((int)$u['paketi']) : '';
}

/**
 * Stanje razloženo na palete, pakete i komade: "1 pal + 31 pak + 4 kom".
 * Prazan tekst ako nema ni cele palete ni celog paketa (nema šta da se razlaže).
 */
function razlaganje(int $komadi, ?int $po_paleti, ?int $po_paketu): string
{
    $po_paleti = $po_paleti && $po_paleti > 0 ? $po_paleti : null;
    $po_paketu = $po_paketu && $po_paketu > 0 ? $po_paketu : null;
    if ($po_paleti === null && $po_paketu === null) {
        return '';
    }
    $znak = $komadi < 0 ? '−' : '';
    $ostalo = abs($komadi);
    $pal = $po_paleti ? intdiv($ostalo, $po_paleti) : 0;
    $ostalo -= $pal * ($po_paleti ?? 0);
    $pak = $po_paketu ? intdiv($ostalo, $po_paketu) : 0;
    $ostalo -= $pak * ($po_paketu ?? 0);
    if ($pal === 0 && $pak === 0) {
        return '';
    }
    $delovi = [];
    if ($pal > 0) {
        $delovi[] = $znak . $pal . ' pal';
    }
    if ($pak > 0) {
        $delovi[] = ($pal === 0 ? $znak : '') . $pak . ' pak';
    }
    if ($ostalo > 0) {
        $delovi[] = broj($ostalo) . ' kom';
    }
    return implode(' + ', $delovi);
}

// ─── Čitanje kataloga ──────────────────────────────────────────────────────

const SQL_AKTIVAN_SKU = 's.aktivan = 1 AND a.aktivan = 1 AND k.aktivan = 1 AND p.aktivan = 1 AND (s.varijanta_id = 0 OR v.aktivan = 1)';

/** Jedan SKU, samo ako je cela veza aktivna (artikal, kategorija, pakovanje, varijanta). */
function sku_aktivan(int $sku_id): ?array
{
    return db_one(
        'SELECT s.id AS sku_id, s.sifra, s.po_paleti, s.po_paketu, s.tezina_kg, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
        . 'v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica '
        . 'FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id '
        . 'JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id '
        . 'WHERE s.id = ? AND ' . SQL_AKTIVAN_SKU,
        [$sku_id]
    );
}

/** Jedan SKU bez obzira na aktivnost (za prikaz starih unosa). */
function sku_podaci(int $sku_id): ?array
{
    return db_one(
        'SELECT s.id AS sku_id, s.sifra, s.po_paleti, s.po_paketu, s.tezina_kg, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
        . 'v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica '
        . 'FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN pakovanja p ON p.id = s.pakovanje_id '
        . 'LEFT JOIN varijante v ON v.id = s.varijanta_id WHERE s.id = ?',
        [$sku_id]
    );
}

/** Trenutno stanje (komada) jednog SKU-a. */
function stanje_sku(int $sku_id): int
{
    return (int)db_val(
        'SELECT COALESCE(SUM(' . SQL_PROMENA . '), 0) FROM unosi u WHERE u.sku_id = ? AND u.obrisan = 0',
        [$sku_id]
    );
}

/** Stanje svih SKU-ova: [sku_id => komada]. */
function stanja_svih(): array
{
    $rez = [];
    foreach (db_all('SELECT u.sku_id, SUM(' . SQL_PROMENA . ') AS stanje FROM unosi u WHERE u.obrisan = 0 GROUP BY u.sku_id') as $r) {
        $rez[(int)$r['sku_id']] = (int)$r['stanje'];
    }
    return $rez;
}

/** Rezervisano po SKU-u: zbir stavki spiskova koji čekaju utovar ("Za utovar"). [sku_id => komada] */
function rezervisano_svih(): array
{
    $rez = [];
    foreach (db_all(
        "SELECT st.sku_id, SUM(st.kolicina) AS kom FROM spisak_stavke st JOIN spiskovi sp ON sp.id = st.spisak_id
         WHERE sp.status = 'za_utovar' GROUP BY st.sku_id"
    ) as $r) {
        $rez[(int)$r['sku_id']] = (int)$r['kom'];
    }
    return $rez;
}

/** Težina jednog komada u kg (null = nije upisana). Prima red sa tezina_kg, pak_kolicina i jedinica. */
function tezina_kom(array $r): ?float
{
    if (isset($r['tezina_kg']) && $r['tezina_kg'] !== null && (float)$r['tezina_kg'] > 0) {
        return (float)$r['tezina_kg'];
    }
    return ($r['jedinica'] ?? '') === 'kg' ? (float)$r['pak_kolicina'] : null;
}

/** "12,5 kg", "1.250 kg", "2,4 t" za velike težine. */
function kg_tekst(float $kg): string
{
    if ($kg >= 10000) {
        return broj($kg / 1000, 1) . ' t';
    }
    return broj($kg, $kg == floor($kg) ? 0 : 1) . ' kg';
}

/** Danas proizvedeno i prodato po SKU-u: [sku_id => ['proizvedeno' => n, 'prodato' => n]]. */
function promet_danas(): array
{
    $rez = [];
    $redovi = db_all(
        "SELECT u.sku_id,
                SUM(CASE WHEN u.tip = 'proizvodnja' THEN u.kolicina ELSE 0 END) AS proizvedeno,
                SUM(CASE WHEN u.tip IN ('prodaja','kucna_prodaja','utovar') THEN u.kolicina ELSE 0 END) AS prodato
         FROM unosi u
         WHERE u.obrisan = 0 AND u.nastalo >= ? AND u.nastalo < ?
         GROUP BY u.sku_id",
        [danas_od(), danas_do()]
    );
    foreach ($redovi as $r) {
        $rez[(int)$r['sku_id']] = ['proizvedeno' => (int)$r['proizvedeno'], 'prodato' => (int)$r['prodato']];
    }
    return $rez;
}

/**
 * Aktivni katalog u obliku za biranje (kategorija → artikal → varijanta/pakovanje).
 * Sa $sa_stanjem = true svaki SKU nosi i trenutno stanje (samo za administratora!).
 * $uz_sku: dodatni SKU koji se prikazuje i kad je isključen (za ispravku starog unosa).
 */
function katalog_za_izbor(bool $sa_stanjem = false, int $uz_sku = 0): array
{
    $redovi = db_all(
        'SELECT s.id AS sku_id, s.varijanta_id, s.po_paleti, s.po_paketu, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
        . 'k.id AS kat_id, k.naziv AS kategorija, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica '
        . 'FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id '
        . 'JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id '
        . 'WHERE (' . SQL_AKTIVAN_SKU . ' OR s.id = ?) '
        . 'ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina',
        [$uz_sku]
    );
    $stanja = $sa_stanjem ? stanja_svih() : [];

    $kategorije = [];
    foreach ($redovi as $r) {
        $kid = (int)$r['kat_id'];
        $aid = (int)$r['artikal_id'];
        if (!isset($kategorije[$kid])) {
            $kategorije[$kid] = ['id' => $kid, 'naziv' => $r['kategorija'], 'artikli' => []];
        }
        if (!isset($kategorije[$kid]['artikli'][$aid])) {
            $kategorije[$kid]['artikli'][$aid] = [
                'id'        => $aid,
                'naziv'     => $r['artikal'],
                'oznaka'    => $r['oznaka'] ?? '',
                'nv'        => $r['naziv_varijante'] ?: 'Varijanta',
                'varijante' => [],
                'sku'       => [],
            ];
        }
        $vid = (int)$r['varijanta_id'];
        if ($vid > 0 && !isset($kategorije[$kid]['artikli'][$aid]['varijante'][$vid])) {
            $kategorije[$kid]['artikli'][$aid]['varijante'][$vid] = ['id' => $vid, 'naziv' => $r['varijanta']];
        }
        $sku = [
            'id' => (int)$r['sku_id'],
            'v'  => $vid,
            'p'  => pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica']),
            'po' => $r['po_paleti'] === null ? 0 : (int)$r['po_paleti'],
            'pp' => $r['po_paketu'] === null ? 0 : (int)$r['po_paketu'],
        ];
        if ($sa_stanjem) {
            $sku['st'] = $stanja[(int)$r['sku_id']] ?? 0;
        }
        $kategorije[$kid]['artikli'][$aid]['sku'][] = $sku;
    }

    // Spisak umesto mapa, da JSON bude niz.
    $izlaz = [];
    foreach ($kategorije as $k) {
        $k['artikli'] = array_values(array_map(static function (array $a): array {
            $a['varijante'] = array_values($a['varijante']);
            return $a;
        }, $k['artikli']));
        $izlaz[] = $k;
    }
    return ['kategorije' => $izlaz];
}

// ─── Šifre: kratko ime artikla za spisak za utovar (H50L = Humovit 50 l) ──

/** Velika slova bez kvačica: "Malč Žuti" → "MALC ZUTI" (ostali znaci postaju razmak). */
function sifra_ascii(string $t): string
{
    $t = strtr(mb_strtoupper($t), ['Č' => 'C', 'Ć' => 'C', 'Ž' => 'Z', 'Š' => 'S', 'Đ' => 'DJ']);
    return trim((string)preg_replace('/[^A-Z0-9]+/', ' ', $t));
}

/** Šifra kako se poredi i čuva: velika slova i cifre (i crtica), bez razmaka – "h 50 l" → "H50L". */
function sifra_normalizuj(string $t): string
{
    $t = strtr(mb_strtoupper(trim($t)), ['Č' => 'C', 'Ć' => 'C', 'Ž' => 'Z', 'Š' => 'S', 'Đ' => 'DJ']);
    return (string)preg_replace('/[^A-Z0-9-]+/', '', $t);
}

/** Naziv za poređenje pri kucanju: samo slova i cifre ("Beli oblutak · 1-3 cm" → "BELIOBLUTAK13CM"). */
function sifra_samo_slova(string $t): string
{
    return str_replace(' ', '', sifra_ascii($t));
}

/** Najkraći početak svake niske koji se ne poklapa sa početkom drugih: [CRVENI, CRNI, BRAON] → [CRV, CRN, B]. */
function jedinstveni_poceci(array $niske): array
{
    $rez = [];
    foreach ($niske as $k => $t) {
        $n = 1;
        while ($n < strlen($t)) {
            $pocetak = substr($t, 0, $n);
            $sukob = false;
            foreach ($niske as $k2 => $t2) {
                if ($k2 !== $k && strncmp($t2, $pocetak, $n) === 0) {
                    $sukob = true;
                    break;
                }
            }
            if (!$sukob) {
                break;
            }
            $n++;
        }
        $rez[$k] = substr($t, 0, $n);
    }
    return $rez;
}

/** "50 l" → "50L", "20 kg" → "20KG", "2,5 l" → "2-5L" */
function velicina_sifra(float|int|string $kolicina, string $jedinica): string
{
    $k = rtrim(rtrim(number_format((float)$kolicina, 2, '.', ''), '0'), '.');
    return str_replace('.', '-', $k) . strtoupper($jedinica);
}

/**
 * Predlog šifre za svaki SKU (i one koji već imaju šifru): prva slova reči artikla (Humovit premium → HP;
 * ako dva artikla imaju ista slova, duži početak prve reči: Crni oblutak → CRNO, Crveni oblutak → CRVO),
 * pa varijanta (granulacija 4-6 cm → 46, boja Crveni → CRV, Braon → B, Sitnija → S),
 * pa pakovanje kad artikal ima više pakovanja (50 l → 50L). Humovit 50 l → H50L, Beli oblutak 4-6 cm → BO46.
 */
function predlozi_sifre(): array
{
    $reci = [];
    $po_slovima = [];
    foreach (db_all('SELECT id, naziv FROM artikli ORDER BY id') as $a) {
        $r = explode(' ', sifra_ascii((string)$a['naziv']));
        $r = array_values(array_filter($r, static fn(string $w): bool => $w !== '')) ?: ['X'];
        $reci[(int)$a['id']] = $r;
        $po_slovima[implode('', array_map(static fn(string $w): string => $w[0], $r))][] = (int)$a['id'];
    }
    $skracenica = [];
    foreach ($po_slovima as $slova => $artikli) {
        if (count($artikli) === 1) {
            $skracenica[$artikli[0]] = $slova;
            continue;
        }
        $prve = [];
        foreach ($artikli as $aid) {
            $prve[$aid] = $reci[$aid][0];
        }
        foreach (jedinstveni_poceci($prve) as $aid => $pocetak) {
            $skracenica[$aid] = $pocetak . substr($slova, 1);
        }
    }

    $po_artiklu = [];
    foreach (db_all('SELECT id, artikal_id, naziv FROM varijante ORDER BY id') as $v) {
        $po_artiklu[(int)$v['artikal_id']][(int)$v['id']] = (string)$v['naziv'];
    }
    $varijanta = [];
    foreach ($po_artiklu as $nazivi) {
        $cifre = array_map(static fn(string $n): string => (string)preg_replace('/\D+/', '', $n), $nazivi);
        if (!in_array('', $cifre, true) && count(array_unique($cifre)) === count($cifre)) {
            $varijanta += $cifre;                                          // granulacije: 1-3 cm → 13
        } else {
            $varijanta += jedinstveni_poceci(array_map('sifra_samo_slova', $nazivi));   // boje i vrste
        }
    }

    $pakovanja = [];
    foreach (db_all('SELECT artikal_id, COUNT(DISTINCT pakovanje_id) AS n FROM sku GROUP BY artikal_id') as $r) {
        $pakovanja[(int)$r['artikal_id']] = (int)$r['n'];
    }
    $rez = [];
    foreach (db_all('SELECT s.id, s.artikal_id, s.varijanta_id, p.kolicina, p.jedinica FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id ORDER BY s.id') as $s) {
        $aid = (int)$s['artikal_id'];
        $sifra = ($skracenica[$aid] ?? 'X') . ($varijanta[(int)$s['varijanta_id']] ?? '');
        if (($pakovanja[$aid] ?? 1) > 1) {
            $sifra .= velicina_sifra($s['kolicina'], (string)$s['jedinica']);
        }
        $rez[(int)$s['id']] = substr($sifra, 0, 12);
    }
    return $rez;
}

/** Daje šifru svakom SKU-u koji je nema (nadogradnja, nova instalacija, novo pakovanje ili varijanta). */
function dopuni_sifre(): int
{
    $bez = db_all('SELECT id FROM sku WHERE sifra IS NULL ORDER BY id');
    if (!$bez) {
        return 0;
    }
    $predlozi = predlozi_sifre();
    $zauzete = [];
    foreach (db_all('SELECT sifra FROM sku WHERE sifra IS NOT NULL') as $r) {
        $zauzete[sifra_normalizuj((string)$r['sifra'])] = true;
    }
    foreach ($bez as $r) {
        $sid = (int)$r['id'];
        $osnova = $predlozi[$sid] ?? ('S' . $sid);
        $sifra = $osnova;
        for ($n = 2; isset($zauzete[$sifra]); $n++) {
            $sifra = $osnova . '-' . $n;
        }
        db_run('UPDATE sku SET sifra = ? WHERE id = ?', [$sifra, $sid]);
        $zauzete[$sifra] = true;
    }
    return count($bez);
}

/** Ispravna šifra posle normalizacije: počinje slovom ili cifrom, do 16 znakova (slova, cifre, crtica). */
function sifra_ispravna(string $sifra): bool
{
    return (bool)preg_match('/^[A-Z0-9][A-Z0-9-]{0,15}$/', $sifra);
}

/** Kratko ime SKU-a: šifra, a bez šifre pun naziv. */
function sku_kratko(array $r): string
{
    return !empty($r['sifra']) ? (string)$r['sifra'] : sku_naziv($r);
}

/** Šifra i pun naziv za poruke: "H50L (Humovit · 50 l)". */
function sku_oznaka(array $r): string
{
    return !empty($r['sifra']) ? $r['sifra'] . ' (' . sku_naziv($r) . ')' : sku_naziv($r);
}

/**
 * Aktivni SKU-ovi za unos šifrom ili nazivom (spisak za utovar), redom kao u katalogu:
 * [['sku_id', 'sifra', 'naziv', 'po', 'pp', 'stanje'?], ...]. Stanje samo za administratora.
 */
function sku_za_unos(bool $sa_stanjem = false): array
{
    $stanja = $sa_stanjem ? stanja_svih() : [];
    $rez = [];
    foreach (db_all(
        'SELECT s.id AS sku_id, s.sifra, s.po_paleti, s.po_paketu, a.naziv AS artikal, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica
         FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
         JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE ' . SQL_AKTIVAN_SKU . '
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina'
    ) as $r) {
        $red = [
            'sku_id' => (int)$r['sku_id'],
            'sifra'  => (string)($r['sifra'] ?? ''),
            'naziv'  => sku_naziv($r),
            'po'     => (int)$r['po_paleti'],
            'pp'     => (int)$r['po_paketu'],
        ];
        if ($sa_stanjem) {
            $red['stanje'] = $stanja[(int)$r['sku_id']] ?? 0;
        }
        $rez[] = $red;
    }
    return $rez;
}

/**
 * Artikal iz otkucanog teksta (šifra ili naziv; razmaci i crtice se ne gledaju): tačna šifra ("H50L"), tačan naziv ("Humovit 50 l"),
 * jedini koji tako počinje ("h50" → H50L), ili onaj kome fali samo jedinica ("h5" → H5L, a ne H50L).
 * Vraća ['sku' => red iz sku_aktivan() ili null, 'greska' => ?string].
 */
function sku_po_unosu(string $tekst): array
{
    $sifra = sifra_normalizuj($tekst);
    $slova = sifra_samo_slova($tekst);
    if ($sifra === '' && $slova === '') {
        return ['sku' => null, 'greska' => 'Upišite šifru artikla (npr. H50L) ili naziv.'];
    }
    $lista = sku_za_unos();
    $nadji = static function (callable $kljuc, string $t) use ($lista): ?array {
        if ($t === '') {
            return null;
        }
        $isti = array_values(array_filter($lista, static fn(array $r): bool => $kljuc($r) === $t));
        if (count($isti) === 1) {
            return $isti;
        }
        $pocinje = array_values(array_filter($lista, static fn(array $r): bool => $kljuc($r) !== '' && str_starts_with($kljuc($r), $t)));
        if (count($pocinje) > 1) {
            $sa_jedinicom = array_values(array_filter($pocinje, static fn(array $r): bool => in_array($kljuc($r), [$t . 'L', $t . 'KG'], true)));
            return count($sa_jedinicom) === 1 ? $sa_jedinicom : $pocinje;
        }
        return $pocinje;
    };
    // reči u bilo kom redu, i početkom reči: "rizla bela sit" → Bela rizla · Sitnija · 20 kg
    $reci = explode(' ', sifra_ascii($tekst));
    $po_recima = array_values(array_filter($lista, static function (array $r) use ($reci): bool {
        $u_nazivu = explode(' ', sifra_ascii($r['naziv']));
        foreach ($reci as $w) {
            $ima = false;
            foreach ($u_nazivu as $n) {
                if (str_starts_with($n, $w)) {
                    $ima = true;
                    break;
                }
            }
            if (!$ima) {
                return false;
            }
        }
        return true;
    }));
    foreach ([
        $nadji(static fn(array $r): string => sifra_samo_slova($r['sifra']), $slova),      // "bo 4-6" → BO46
        $nadji(static fn(array $r): string => sifra_samo_slova($r['naziv']), $slova),
        count($reci) > 1 && $reci[0] !== '' ? $po_recima : null,
    ] as $nadjeni) {
        if ($nadjeni !== null && count($nadjeni) === 1) {
            return ['sku' => sku_aktivan($nadjeni[0]['sku_id']), 'greska' => null];
        }
        if ($nadjeni !== null && count($nadjeni) > 1) {
            $ponuda = array_map(static fn(array $r): string => $r['sifra'] !== '' ? $r['sifra'] : $r['naziv'], array_slice($nadjeni, 0, 5));
            return ['sku' => null, 'greska' => '„' . trim($tekst) . '“ nije jasno – da li ste mislili: ' . implode(', ', $ponuda) . (count($nadjeni) > 5 ? ' …' : '') . '?'];
        }
    }
    $iskljucen = $sifra !== '' ? db_val('SELECT sifra FROM sku WHERE sifra = ?', [$sifra]) : null;
    return ['sku' => null, 'greska' => $iskljucen !== null
        ? 'Artikal sa šifrom ' . $iskljucen . ' je isključen u Podešavanjima.'
        : 'Nema artikla sa šifrom ili nazivom „' . trim($tekst) . '“. Šifre se vide u Podešavanjima kod svakog artikla.'];
}

// ─── Količina iz forme ─────────────────────────────────────────────────────

/**
 * Čita količinu iz POST-a ("kolicina" + "nacin" = komadi|paketi|palete) i pretvara u komade.
 * Vraća ['komadi' => int, 'palete' => ?int, 'paketi' => ?int] ili ['greska' => tekst].
 */
function procitaj_kolicinu(array $sku): array
{
    $unos = $_POST['kolicina'] ?? '';
    $broj = is_string($unos) ? filter_var(trim($unos), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : false;
    if ($broj === false) {
        return ['greska' => 'Upišite količinu (ceo broj veći od nule).'];
    }
    $palete = null;
    $paketi = null;
    $nacin = $_POST['nacin'] ?? 'komadi';
    if ($nacin === 'palete') {
        $po = (int)($sku['po_paleti'] ?? 0);
        if ($po <= 0) {
            return ['greska' => 'Za ovaj artikal nije podešeno koliko komada ide na paletu. Unesite pakete ili komade.'];
        }
        $palete = $broj;
        $broj = $broj * $po;
    } elseif ($nacin === 'paketi') {
        $pp = (int)($sku['po_paketu'] ?? 0);
        if ($pp <= 0) {
            return ['greska' => 'Za ovaj artikal nije podešeno koliko komada ima u paketu. Unesite palete ili komade.'];
        }
        $paketi = $broj;
        $broj = $broj * $pp;
    }
    if ($broj > MAX_KOLICINA_UNOS) {
        return ['greska' => 'Količina je prevelika (najviše ' . broj(MAX_KOLICINA_UNOS) . ' komada u jednom unosu). Proverite broj.'];
    }
    return ['komadi' => $broj, 'palete' => $palete, 'paketi' => $paketi];
}
