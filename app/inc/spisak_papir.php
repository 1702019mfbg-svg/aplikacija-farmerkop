<?php
/**
 * Spisak sa papira: tekst (prepisan ili izdiktiran telefonom) → mesta istovara i roba. Na primer:
 *
 *   8 Agrocentar                                     ← mesto 8 (kupac nije obavezan); može i "(8)", "Mesto 8", "M8"
 *   2 pal humovit 50                                 ← 2 palete
 *   humovit 10 - 30                                  ← 30 (broj bez oznake: komada, ili paleta – bira se u formi)
 *   3 pak idea 5
 *   1/2 rizla bela sitna + 1/2 oblutak beli 40-60    ← zbirna paleta (pola + pola)
 *   2x 1/2 rizla + 1/2 oblutak                       ← dve takve zbirne palete
 *
 * Ništa se ne upisuje naslepo: sve pročitano se prikaže na proveru (admin/spisak_papir.php), nejasno se označi
 * i ispravi (dodirom na pakovanje), pa se sve sačuva odjednom.
 */
declare(strict_types=1);

const PAPIR_MAX_TEKST = 8000;
const PAPIR_MAX_REDOVA = 200;

/** Brojevi rečima (diktiranje): "dve palete" → "2 palete". */
const PAPIR_BROJEVI = [
    'jedan' => 1, 'jedna' => 1, 'jednu' => 1, 'jedno' => 1, 'dva' => 2, 'dve' => 2, 'dvije' => 2, 'tri' => 3,
    'četiri' => 4, 'cetiri' => 4, 'pet' => 5, 'šest' => 6, 'sest' => 6, 'sedam' => 7, 'osam' => 8, 'devet' => 9,
    'deset' => 10, 'jedanaest' => 11, 'dvanaest' => 12, 'trinaest' => 13, 'četrnaest' => 14, 'cetrnaest' => 14,
    'petnaest' => 15, 'šesnaest' => 16, 'sesnaest' => 16, 'sedamnaest' => 17, 'osamnaest' => 18, 'devetnaest' => 19,
    'dvadeset' => 20, 'trideset' => 30, 'četrdeset' => 40, 'cetrdeset' => 40, 'pedeset' => 50, 'šezdeset' => 60,
    'sezdeset' => 60, 'sedamdeset' => 70, 'osamdeset' => 80, 'devedeset' => 90,
];

/** Oznake količine iza broja: palete, paketi, komadi (i vreće, džakovi, kese = komadi) i tone. */
const PAPIR_JEDINICA = '(palet[aeiu]?|pal|plt|paket[aiu]?|pak|komad[ai]?|kom|kpl|vreć[aeiu]?|vrec[aeiu]?|džak(?:a|ova)?|dzak(?:a|ova)?|kes[aeiu]|ton[aeu]?|t)';

/** Jedan red teksta, očišćen: zaokruženi brojevi ①, ½, crtice za nabrajanje, brojevi rečima, litara → l. */
function papir_cist_red(string $l): string
{
    $l = (string)preg_replace_callback('/[\x{2460}-\x{2473}]/u', static fn(array $m): string => ' (' . (mb_ord($m[0]) - 0x2460 + 1) . ') ', $l);
    $l = str_replace(['½', '¼', '¾', '×', '–', '—', '⁄'], [' 1/2 ', ' 1/4 ', ' 3/4 ', 'x', '-', '-', '/'], $l);
    $l = trim((string)preg_replace('/\s+/u', ' ', $l));
    $l = (string)preg_replace('/^[-•*·>]+\s*/u', '', $l);                         // nabrajanje: "- 2 pal humovit"
    $l = (string)preg_replace_callback('/(?<![\p{L}\d])(\p{L}+)(?![\p{L}\d])/u', static function (array $m): string {
        $r = mb_strtolower($m[1]);
        return isset(PAPIR_BROJEVI[$r]) ? (string)PAPIR_BROJEVI[$r] : $m[1];
    }, $l);
    $l = (string)preg_replace('/(\d)\s*(?:litara|litra|litre|lit)\.?(?![\p{L}])/iu', '$1 l', $l);
    $l = (string)preg_replace('/(\d)\s*(?:kilograma|kilogram|kila|kilo)(?![\p{L}])/iu', '$1 kg', $l);
    $l = (string)preg_replace('/(\d)\s*(?:santimetara|centimetara|cm)\.?(?![\p{L}])/iu', '$1 cm', $l);
    return trim($l);
}

/** Pola palete u tekstu ("pola", "polovina", "pola palete") → [ostatak teksta, true] */
function papir_pola(string $t): array
{
    $n = 0;
    $t = (string)preg_replace('/(?<![\p{L}])(?:pola|polovina|polovinu)(?:\s+palet[aeiu]?)?(?![\p{L}])/iu', ' ', $t, -1, $n);
    return [trim((string)preg_replace('/\s+/u', ' ', $t)), $n > 0];
}

/** Naziv artikla bez reči koje ne pripadaju nazivu ("od", "po", "-", ":"). */
function papir_naziv(string $t): string
{
    $t = (string)preg_replace('/(?<![\p{L}])(?:od|po|za|sa|na|i|x|komada|komad|paleta|palete|paketa|paket|kom|pal|pak|vreća|vreće|vreca|vrece|džak|džaka|dzak|kesa|kese)(?![\p{L}])/iu', ' ', $t);
    $t = (string)preg_replace('/\s+/u', ' ', $t);
    return trim($t, " \t-=:;,.+*");
}

/** Najbolji artikal za tekst, ako je samo jedan. Vraća red iz sku_za_unos() ili null. */
function papir_artikal(string $tekst, array $lista): ?array
{
    $n = sku_pronadji($tekst, $lista);
    if (!$n || (count($n) > 1 && $n[1]['ocena'] === $n[0]['ocena'])) {
        return null;
    }
    return $n[0]['sku'];
}

/** Najbolja ocena za tekst (null = nijedan artikal). */
function papir_ocena(string $tekst, array $lista): ?float
{
    $n = sku_pronadji($tekst, $lista);
    return $n ? $n[0]['ocena'] : null;
}

/**
 * Količina i naziv iz jednog reda robe. Vraća ['naziv' => tekst, 'pal', 'pak', 'kom', 'deo' => [a, b]|null,
 * 't' => tone|null, 'ima' => da li je količina upisana]. Broj bez oznake ide u $bez_oznake ('kom' ili 'pal').
 */
function papir_kolicina(string $l, string $bez_oznake, array $lista): array
{
    $q = ['pal' => 0, 'pak' => 0, 'kom' => 0, 'deo' => null, 't' => null, 'ima' => false];
    $t = ' ' . $l . ' ';
    // deo palete sa oznakom: "1/2 palete"
    if (preg_match('#(?<![\d/])(\d{1,2})\s*/\s*(\d{1,2})\s*(?:palet[aeiu]?|pal|plt)\.?(?![\p{L}])#iu', $t, $m)) {
        $q['deo'] = [(int)$m[1], (int)$m[2]];
        $t = str_replace($m[0], ' ', $t);
    }
    [$t, $pola] = papir_pola($t);
    if ($pola && $q['deo'] === null) {
        $q['deo'] = [1, 2];
    }
    $t = ' ' . $t . ' ';
    // broj sa oznakom: "2 pal", "3 pak", "30 kom", "2 t", "40 vreća"
    if (preg_match_all('/(?<![\d\/.,])(\d{1,5}(?:[.,]\d{1,2})?)\s*' . PAPIR_JEDINICA . '\.?(?![\p{L}\d])/iu', $t, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $j = mb_strtolower($m[2]);
            $broj = (float)str_replace(',', '.', $m[1]);
            if (str_starts_with($j, 't')) {
                $q['t'] = ($q['t'] ?? 0) + $broj;
            } elseif (str_starts_with($j, 'pal') || $j === 'plt') {
                $q['pal'] += (int)$broj;
            } elseif (str_starts_with($j, 'pak')) {
                $q['pak'] += (int)$broj;
            } else {
                $q['kom'] += (int)$broj;
            }
            $t = (string)preg_replace('/' . preg_quote($m[0], '/') . '/u', ' ', $t, 1);
        }
    }
    $t = trim((string)preg_replace('/\s+/u', ' ', $t));
    // deo palete na početku reda: "1/2 rizla bela sitna"
    if (preg_match('#^(\d{1,2})\s*/\s*(\d{1,2})\s+(?=\S)#u', $t, $m) && (int)$m[1] >= 1 && (int)$m[1] < (int)$m[2] && $q['deo'] === null) {
        $q['deo'] = [(int)$m[1], (int)$m[2]];
        $t = trim(substr($t, strlen($m[0])));
    }
    $ima = $q['pal'] || $q['pak'] || $q['kom'] || $q['deo'] !== null || $q['t'] !== null;
    if (!$ima) {
        $bez = null;
        if (preg_match('/^(\d{1,5})\s*[x*]?\s+(?=\D)/u', $t, $m)) {                       // "30 humovit 10"
            $bez = (int)$m[1];
            $t = trim(substr($t, strlen($m[0])));
        } elseif (preg_match('/\s[-=:x*]\s*(\d{1,5})$/u', $t, $m)) {                    // "humovit 10 - 30"
            $bez = (int)$m[1];
            $t = trim(substr($t, 0, -strlen($m[0])));
        } elseif (preg_match('/^(.+?)\s(\d{1,5})$/u', $t, $m)) {                          // "humovit 10 30"
            $sa = papir_ocena(papir_naziv($t), $lista);
            $bez_br = papir_ocena(papir_naziv($m[1]), $lista);
            if ($bez_br !== null && ($sa === null || $bez_br < $sa)) {
                $bez = (int)$m[2];
                $t = $m[1];
            }
        }
        if ($bez !== null && $bez > 0) {
            $q[$bez_oznake === 'pal' ? 'pal' : 'kom'] += $bez;
            $ima = true;
        }
    }
    $q['ima'] = $ima;
    $q['naziv'] = papir_naziv($t);
    return $q;
}

/** Red robe za pregled: artikal (prepoznat ili ne), palete / paketi / komadi i napomena. */
function papir_red_robe(string $izvor, string $l, string $bez_oznake, array $lista): array
{
    $q = papir_kolicina($l, $bez_oznake, $lista);
    $sku = $q['naziv'] !== '' ? papir_artikal($q['naziv'], $lista) : null;
    $napomene = [];
    $kom = $q['kom'];
    if ($q['deo'] !== null) {
        [$a, $b] = $q['deo'];
        $deo = $a . '/' . $b . ' palete';
        if ($a < 1 || $b < 1 || $a > $b) {
            $napomene[] = 'deo palete „' . $a . '/' . $b . '“ nije jasan – upišite količinu';
        } elseif ($sku !== null && $sku['po'] > 0) {
            $n = (int)round($sku['po'] * $a / $b);
            $kom += $n;
            $napomene[] = $deo . ' = ' . broj($n) . ' kom';
        } else {
            $napomene[] = $deo . ' – izaberite artikal, pa upišite koliko je to komada';
        }
    }
    if ($q['t'] !== null) {
        $tez = $sku !== null ? tezina_kom((array)sku_podaci($sku['sku_id'])) : null;
        $t = rtrim(rtrim(number_format($q['t'], 2, ',', ''), '0'), ',');
        if ($tez !== null && $tez > 0) {
            $n = (int)round($q['t'] * 1000 / $tez);
            $kom += $n;
            $napomene[] = $t . ' t ≈ ' . broj($n) . ' kom (' . kg_tekst($tez) . ' po komadu)';
        } else {
            $napomene[] = $t . ' t – upišite koliko je to komada';
        }
    }
    if ($q['naziv'] === '') {
        $napomene[] = 'nema naziva artikla';
    } elseif (!$q['ima']) {
        $napomene[] = 'nema količine – upišite je';
    }
    return [
        'vrsta'    => 'roba',
        'izvor'    => $izvor,
        'tekst'    => $sku !== null ? $sku['naziv'] : $q['naziv'],
        'id'       => $sku !== null ? (string)$sku['sku_id'] : '',
        'pal'      => $q['pal'] > 0 ? (string)$q['pal'] : '',
        'pak'      => $q['pak'] > 0 ? (string)$q['pak'] : '',
        'kom'      => $kom > 0 ? (string)$kom : '',
        'napomena' => implode('; ', $napomene),
        'preskoci' => '',
        'greska'   => '',
    ];
}

/** Deo zbirne palete ("1/2 rizla bela sitna", "3 pak idea 5", "25 humovit 10"): artikal i koliko na jednoj paleti. */
function papir_deo_zbirne(string $l, array $lista): array
{
    [$t, $pola] = papir_pola($l);
    $kol = $pola ? '1/2' : '';
    if ($kol === '' && preg_match('#^(\d{1,2})\s*/\s*(\d{1,2})(?:\s*(?:palet[aeiu]?|pal)\.?)?\s+#iu', $t, $m)) {
        $kol = $m[1] . '/' . $m[2];
        $t = substr($t, strlen($m[0]));
    } elseif ($kol === '' && preg_match('#\s[-=:]\s*(\d{1,2})\s*/\s*(\d{1,2})$#u', $t, $m)) {
        $kol = $m[1] . '/' . $m[2];
        $t = substr($t, 0, -strlen($m[0]));
    } elseif ($kol === '' && preg_match('/(?<![\d\/])(\d{1,4})\s*(paket[aiu]?|pak|komad[ai]?|kom|vreć[aeiu]?|vrec[aeiu]?)\.?(?![\p{L}])/iu', $t, $m)) {
        $kol = (int)$m[1] . (str_starts_with(mb_strtolower($m[2]), 'pak') ? ' pak' : '');
        $t = str_replace($m[0], ' ', $t);
    } elseif ($kol === '' && preg_match('/^(\d{1,4})\s+(?=\D)/u', $t, $m)) {
        $kol = $m[1];
        $t = substr($t, strlen($m[0]));
    }
    $naziv = papir_naziv($t);
    $sku = $naziv !== '' ? papir_artikal($naziv, $lista) : null;
    return ['tekst' => $sku !== null ? $sku['naziv'] : $naziv, 'id' => $sku !== null ? (string)$sku['sku_id'] : '', 'kol' => $kol];
}

/** Da li tekst izgleda kao roba (ima količinu ili poznat artikal), a ne kao kupac ili adresa. */
function papir_lici_na_robu(string $t, array $lista): bool
{
    if (preg_match('/(?<![\d\/.,])\d{1,5}\s*' . PAPIR_JEDINICA . '\.?(?![\p{L}\d])/iu', $t) || str_contains($t, '+')) {
        return true;
    }
    $naziv = papir_naziv((string)preg_replace('/\d+/', ' ', $t));
    return $naziv !== '' && sku_pronadji($naziv, $lista) !== [];
}

/**
 * Mesto iz reda teksta: "8", "(8)", "8)", "Mesto 8", "M8", "8 Agrocentar", "8 - Agrocentar, Novi Sad".
 * Vraća [broj, ostatak (kupac), siguran] ili null. Nesiguran (broj + tekst) važi samo ako red ne liči na robu.
 */
function papir_mesto(string $l): ?array
{
    if (preg_match('/^(?:mesto|mjesto|m)\s*\.?\s*(?:broj\s*|br\.?\s*)?(\d{1,3})(?!\d)\s*[.:)\-,]?\s*(.*)$/iu', $l, $m)) {
        return [(int)$m[1], trim($m[2]), true];
    }
    if (preg_match('/^[(\[{]\s*(\d{1,3})\s*[)\]}]\s*[.:\-,]?\s*(.*)$/u', $l, $m)) {
        return [(int)$m[1], trim($m[2]), true];
    }
    if (preg_match('/^(\d{1,3})\s*[.:)]?$/u', $l, $m)) {
        return [(int)$m[1], '', true];
    }
    if (preg_match('/^(\d{1,3})\s*[.:)\-,]?\s+(\D.*)$/u', $l, $m)) {
        return [(int)$m[1], trim($m[2]), false];
    }
    return null;
}

/**
 * Ceo tekst → ['mesta' => [['broj', 'kupac', 'adresa', 'redovi' => [...]]], 'greska' => ?string].
 * Roba pre prvog mesta ide na mesto $prvi_broj. Isti broj mesta dva puta = isto mesto.
 */
function papir_procitaj(string $tekst, string $bez_oznake, int $prvi_broj): array
{
    $lista = sku_za_unos();
    $linije = preg_split('/\R/u', mb_substr($tekst, 0, PAPIR_MAX_TEKST)) ?: [];
    $mesta = [];
    $tekuce = null;
    $novo_mesto = static function (int $broj, string $kupac) use (&$mesta, &$tekuce): void {
        foreach ($mesta as $i => $m) {
            if ((int)$m['broj'] === $broj) {
                $tekuce = $i;
                if ($kupac !== '' && $mesta[$i]['kupac'] === '') {
                    $mesta[$i]['kupac'] = $kupac;
                }
                return;
            }
        }
        $mesta[] = ['broj' => (string)$broj, 'kupac' => $kupac, 'adresa' => '', 'redovi' => [], 'greska' => ''];
        $tekuce = array_key_last($mesta);
    };
    $n = 0;
    foreach ($linije as $sirovo) {
        $l = papir_cist_red($sirovo);
        if ($l === '' || !preg_match('/[\p{L}\d]/u', $l)) {
            continue;
        }
        if (++$n > PAPIR_MAX_REDOVA) {
            return ['mesta' => $mesta, 'greska' => 'Tekst je predugačak – najviše ' . PAPIR_MAX_REDOVA . ' redova odjednom.'];
        }
        $izvor = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $sirovo)), 0, 160);

        $mesto = papir_mesto($l);
        if ($mesto !== null && $mesto[0] >= 1 && ($mesto[2] || !papir_lici_na_robu($l, $lista))) {
            if ($mesto[1] === '' || !papir_lici_na_robu($mesto[1], $lista)) {
                $novo_mesto($mesto[0], mb_substr(trim($mesto[1], " -:,."), 0, 120));
                continue;
            }
            $novo_mesto($mesto[0], '');                 // "(8) 2 pal humovit 50": mesto i roba u istom redu
            $l = $mesto[1];
        }
        if ($tekuce === null) {
            $novo_mesto($prvi_broj, '');
        }
        $m = &$mesta[$tekuce];

        // kupac (bez brojeva) ili adresa odmah ispod broja mesta – red koji ne liči na robu
        if (!$m['redovi'] && !papir_lici_na_robu($l, $lista)) {
            if ($m['kupac'] === '' && !preg_match('/\d/', $l)) {
                $m['kupac'] = mb_substr($l, 0, 120);
                unset($m);
                continue;
            }
            if ($m['adresa'] === '') {
                $m['adresa'] = mb_substr($l, 0, 255);
                unset($m);
                continue;
            }
        }

        if (str_contains($l, '+')) {
            $broj = 1;
            if (preg_match('/^(\d{1,3})\s*(?:x|\*|puta|palet[aeiu]?\s*[:\-])\s*(.+)$/iu', $l, $mm)) {
                $broj = max(1, (int)$mm[1]);
                $l = $mm[2];
            }
            $delovi = array_values(array_filter(array_map('trim', explode('+', $l)), static fn(string $d): bool => $d !== ''));
            $cele = array_filter($delovi, static fn(string $d): bool => (bool)preg_match('/(?<![\d\/])\d{1,5}\s*(?:palet[aeiu]?|pal|plt)\.?(?![\p{L}])/iu', $d));
            if ($cele) {
                // "2 pal humovit 50 + 1 pal idea 10" – to su posebne stavke, ne zbirna paleta
                foreach ($delovi as $d) {
                    $m['redovi'][] = papir_red_robe($izvor, $d, $bez_oznake, $lista);
                }
                unset($m);
                continue;
            }
            $zbirna = ['vrsta' => 'zbirna', 'izvor' => $izvor, 'broj' => (string)$broj, 'delovi' => [], 'napomena' => '', 'preskoci' => '', 'greska' => ''];
            foreach (array_slice($delovi, 0, 20) as $d) {
                $zbirna['delovi'][] = papir_deo_zbirne($d, $lista);
            }
            $m['redovi'][] = $zbirna;
            unset($m);
            continue;
        }
        $m['redovi'][] = papir_red_robe($izvor, $l, $bez_oznake, $lista);
        unset($m);
    }
    return ['mesta' => $mesta, 'greska' => $mesta ? null : 'U tekstu nema ničega za spisak.'];
}

/** Ono što je u formi pregleda (m[i][...], r[i][j][...]) – isti oblik kao papir_procitaj(). */
function papir_iz_posta(): array
{
    $str = static fn(mixed $v, int $max): string => is_string($v) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $v)), 0, $max) : '';
    $mesta = [];
    $pm = is_array($_POST['m'] ?? null) ? array_slice($_POST['m'], 0, 100, true) : [];
    $pr = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
    foreach ($pm as $i => $m) {
        if (!is_array($m)) {
            continue;
        }
        $mesto = ['broj' => $str($m['broj'] ?? '', 4), 'kupac' => $str($m['kupac'] ?? '', 120), 'adresa' => $str($m['adresa'] ?? '', 255), 'redovi' => [], 'greska' => ''];
        foreach (is_array($pr[$i] ?? null) ? array_slice($pr[$i], 0, PAPIR_MAX_REDOVA) : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $red = ['izvor' => $str($r['izvor'] ?? '', 160), 'napomena' => $str($r['napomena'] ?? '', 300), 'preskoci' => ($r['preskoci'] ?? '') === '1' ? '1' : '', 'greska' => ''];
            if (($r['vrsta'] ?? '') === 'zbirna') {
                $red = ['vrsta' => 'zbirna', 'broj' => $str($r['broj'] ?? '', 4), 'delovi' => []] + $red;
                foreach (is_array($r['d'] ?? null) ? array_slice($r['d'], 0, 20) : [] as $d) {
                    if (is_array($d)) {
                        $red['delovi'][] = ['tekst' => $str($d['tekst'] ?? '', 120), 'id' => $str($d['id'] ?? '', 10), 'kol' => $str($d['kol'] ?? '', 12)];
                    }
                }
            } else {
                $red = ['vrsta' => 'roba', 'tekst' => $str($r['tekst'] ?? '', 120), 'id' => $str($r['id'] ?? '', 10),
                        'pal' => $str($r['pal'] ?? '', 8), 'pak' => $str($r['pak'] ?? '', 8), 'kom' => $str($r['kom'] ?? '', 8)] + $red;
            }
            $mesto['redovi'][] = $red;
        }
        $mesta[] = $mesto;
    }
    return $mesta;
}

/**
 * Provera pregleda pre čuvanja: svaka greška se upiše uz svoj red (ili mesto).
 * Vraća [plan po broju mesta ['broj', 'kupac', 'adresa', 'roba' => [sku_id => red], 'zbirne' => [[broj, redovi]]], broj grešaka].
 */
function papir_proveri(array &$mesta): array
{
    $plan = [];
    $greske = 0;
    foreach ($mesta as &$m) {
        [$broj, $g] = mesto_broj_iz($m['broj']);
        if ($g !== null) {
            $m['greska'] = $g;
            $greske++;
            $broj = 0;
        }
        $p = &$plan[$broj];
        $p ??= ['broj' => $broj, 'kupac' => '', 'adresa' => '', 'roba' => [], 'zbirne' => []];
        $p['kupac'] = $p['kupac'] !== '' ? $p['kupac'] : $m['kupac'];
        $p['adresa'] = $p['adresa'] !== '' ? $p['adresa'] : $m['adresa'];
        foreach ($m['redovi'] as &$r) {
            if ($r['preskoci'] === '1') {
                continue;
            }
            if ($r['vrsta'] === 'zbirna') {
                $b = $r['broj'] === '' ? '1' : $r['broj'];
                $ulaz = array_map(static fn(array $d): array => [ctype_digit($d['id']) ? (int)$d['id'] : 0, $d['tekst'], $d['kol']], $r['delovi']);
                [$redovi, $greska] = zbirna_redovi($ulaz);
                if ($greska === null && (!ctype_digit($b) || (int)$b < 1 || (int)$b > 999)) {
                    $greska = 'Broj ovakvih paleta mora biti od 1 do 999.';
                }
                if ($greska !== null) {
                    $r['greska'] = $greska;
                    $greske++;
                    continue;
                }
                $p['zbirne'][] = [(int)$b, $redovi];
                continue;
            }
            $x = roba_red(ctype_digit($r['id']) ? (int)$r['id'] : 0, $r['tekst'], $r['pal'], $r['pak'], $r['kom'], false);
            if (isset($x['greska'])) {
                $r['greska'] = ucfirst($x['greska']);
                $greske++;
                continue;
            }
            roba_saberi($p['roba'], $x);
        }
        unset($r, $p);
    }
    unset($m);
    return [$plan, $greske];
}
