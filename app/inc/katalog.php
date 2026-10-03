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
];

/** Promena stanja jednog reda iz tabele unosi (alias "u"): prodaja smanjuje, ostalo povećava. */
const SQL_PROMENA = "CASE WHEN u.tip IN ('prodaja','kucna_prodaja') THEN -u.kolicina ELSE u.kolicina END";

/** Polja koja opisuju SKU; koristi se uz SKU_SPOJ nad tabelom unosi (alias "u"). */
const SKU_POLJA = 's.id AS sku_id, s.po_paleti, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, '
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

/** Kako je unos prikazan: "360 kom (3 palete)" ili "35 kom". */
function kolicina_tekst(int $komadi, ?int $palete = null): string
{
    $t = broj($komadi) . ' kom';
    return $palete ? $t . ' (' . palete_tekst($palete) . ')' : $t;
}

/** Stanje razloženo na palete: "10 pal + 40 kom"; prazan tekst ako paleta nije podešena. */
function paletni_prikaz(int $komadi, ?int $po_paleti): string
{
    if (!$po_paleti || $po_paleti <= 0) {
        return '';
    }
    $neg = $komadi < 0;
    $a = abs($komadi);
    $pal = intdiv($a, $po_paleti);
    $ost = $a % $po_paleti;
    if ($pal === 0) {
        return '';
    }
    $t = ($neg ? '−' : '') . $pal . ' pal' . ($ost > 0 ? ' + ' . broj($ost) . ' kom' : '');
    return $t;
}

// ─── Čitanje kataloga ──────────────────────────────────────────────────────

const SQL_AKTIVAN_SKU = 's.aktivan = 1 AND a.aktivan = 1 AND k.aktivan = 1 AND p.aktivan = 1 AND (s.varijanta_id = 0 OR v.aktivan = 1)';

/** Jedan SKU, samo ako je cela veza aktivna (artikal, kategorija, pakovanje, varijanta). */
function sku_aktivan(int $sku_id): ?array
{
    return db_one(
        'SELECT s.id AS sku_id, s.po_paleti, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
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
        'SELECT s.id AS sku_id, s.po_paleti, s.min_zaliha, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
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

/** Danas proizvedeno i prodato po SKU-u: [sku_id => ['proizvedeno' => n, 'prodato' => n]]. */
function promet_danas(): array
{
    $rez = [];
    $redovi = db_all(
        "SELECT u.sku_id,
                SUM(CASE WHEN u.tip = 'proizvodnja' THEN u.kolicina ELSE 0 END) AS proizvedeno,
                SUM(CASE WHEN u.tip IN ('prodaja','kucna_prodaja') THEN u.kolicina ELSE 0 END) AS prodato
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
        'SELECT s.id AS sku_id, s.varijanta_id, s.po_paleti, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.naziv_varijante, '
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

// ─── Količina iz forme ─────────────────────────────────────────────────────

/**
 * Čita količinu iz POST-a ("kolicina" + "nacin" = komadi|palete) i pretvara u komade.
 * Vraća ['komadi' => int, 'palete' => ?int] ili ['greska' => tekst].
 */
function procitaj_kolicinu(array $sku): array
{
    $unos = $_POST['kolicina'] ?? '';
    $broj = is_string($unos) ? filter_var(trim($unos), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : false;
    if ($broj === false) {
        return ['greska' => 'Upišite količinu (ceo broj veći od nule).'];
    }
    $palete = null;
    if (($_POST['nacin'] ?? 'komadi') === 'palete') {
        $po = (int)($sku['po_paleti'] ?? 0);
        if ($po <= 0) {
            return ['greska' => 'Za ovaj artikal nije podešeno koliko komada ide na paletu. Unesite komade.'];
        }
        $palete = $broj;
        $broj = $broj * $po;
    }
    if ($broj > MAX_KOLICINA_UNOS) {
        return ['greska' => 'Količina je prevelika (najviše ' . broj(MAX_KOLICINA_UNOS) . ' komada u jednom unosu). Proverite broj.'];
    }
    return ['komadi' => $broj, 'palete' => $palete];
}
