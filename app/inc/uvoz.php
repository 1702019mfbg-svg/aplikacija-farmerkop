<?php
/**
 * Uvoz stanja iz fajla (CSV ili tabela nalepljena iz Excela / Bluesofta).
 *
 * Fajl se čita kao tekst, kolone se biraju na ekranu, a svaki red se uparuje sa jednim SKU-om
 * (artikal + boja/granulacija + pakovanje). Upisivanje ide preko popis_postavi(), dakle isto kao
 * ručni Popis: stanje posle uvoza je tačno ono iz fajla, a razlika ide u istoriju kao korekcija.
 */
declare(strict_types=1);

const UVOZ_MAX_BAJTOVA = 1048576;
const UVOZ_MAX_REDOVA = 600;
const UVOZ_MAX_KOLONA = 30;

const UVOZ_JEDINICE = ['komadi' => 'komadi', 'paketi' => 'paketi', 'palete' => 'palete'];

// ─── Tekst: slova, brojevi ─────────────────────────────────────────────────

/** Mala slova, ćirilica → latinica, bez kvačica (č/ć → c, š → s, ž → z, đ → dj). */
function uvoz_latinica(string $s): string
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'dj', 'е' => 'e', 'ж' => 'z', 'з' => 'z',
            'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ћ' => 'c', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'c', 'џ' => 'dz', 'ш' => 's',
            'č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj',
        ];
    }
    return strtr(mb_strtolower($s, 'UTF-8'), $mapa);
}

/** Za poređenje naziva: samo mala slova a-z i cifre, razmaci umesto svega ostalog. */
function uvoz_norm(string $s): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', uvoz_latinica($s)));
}

/** Ključ za pamćenje uparivanja: n:<naziv> ili s:<šifra> (najviše 190 znakova). */
function uvoz_kljuc(string $vrsta, string $tekst): string
{
    return substr($vrsta . ':' . uvoz_norm($tekst), 0, 190);
}

/**
 * Broj iz fajla. Format "sr" (podrazumevano): zarez je decimalni ("120,000" = 120), tačka razdvaja hiljade
 * ("1.250" = 1250). Format "en": obrnuto ("1,250" = 1250, "12.5" = 12,5).
 * Kad su prisutna oba znaka, poslednji je decimalni. Vraća null ako tekst nije broj.
 */
function uvoz_broj(string $t, string $format = 'sr'): ?float
{
    $t = trim(str_replace(["\xC2\xA0", ' ', "'"], '', $t));
    if ($t === '' || !preg_match('/^[+-]?[0-9][0-9.,]*$/', $t)) {
        return null;
    }
    $znak = $t[0] === '-' ? -1 : 1;
    $t = ltrim($t, '+-');
    $decimalni = $format === 'en' ? '.' : ',';
    $hiljade = $format === 'en' ? ',' : '.';

    $ima_zarez = str_contains($t, ',');
    $ima_tacku = str_contains($t, '.');
    if ($ima_zarez && $ima_tacku) {
        $dec = strrpos($t, ',') > strrpos($t, '.') ? ',' : '.';
        $tis = $dec === ',' ? '.' : ',';
        if (substr_count($t, $dec) > 1) {
            return null;
        }
        $t = str_replace($dec, '.', str_replace($tis, '', $t));
    } elseif ($ima_zarez || $ima_tacku) {
        $sep = $ima_zarez ? ',' : '.';
        $delovi = explode($sep, $t);
        if (count($delovi) > 2) {
            // 1.234.567 – razdvajač hiljada; svaka grupa posle prve ima tačno 3 cifre.
            foreach (array_slice($delovi, 1) as $d) {
                if (strlen($d) !== 3) {
                    return null;
                }
            }
            $t = implode('', $delovi);
        } elseif ($sep === $hiljade && strlen($delovi[1]) === 3 && strlen($delovi[0]) <= 3 && $delovi[0] !== '0') {
            $t = implode('', $delovi);
        } else {
            $t = $delovi[0] . '.' . $delovi[1];
        }
    }
    return is_numeric($t) ? $znak * (float)$t : null;
}

// ─── Čitanje fajla ─────────────────────────────────────────────────────────

/**
 * Windows-1250 (srpski Excel / stariji programi) → UTF-8. Sopstvena tabela, jer mbstring ne podržava
 * Windows-1250 na svakom serveru, a iconv ne mora biti uključen.
 */
function uvoz_iz_cp1250(string $s): string
{
    static $mapa = null;
    if ($mapa === null) {
        $visoko = '€?‚?„…†‡?‰Š‹ŚŤŽŹ' . '?‘’“”•–—?™š›śťžź' . "\u{A0}ˇ˘Ł¤Ą¦§¨©Ş«¬\u{AD}®Ż"
            . '°±˛ł´µ¶·¸ąş»Ľ˝ľż' . 'ŔÁÂĂÄĹĆÇČÉĘËĚÍÎĎ' . 'ĐŃŇÓÔŐÖ×ŘŮÚŰÜÝŢß' . 'ŕáâăäĺćçčéęëěíîď' . 'đńňóôőö÷řůúűüýţ˙';
        $znaci = preg_split('//u', $visoko, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mapa = [];
        foreach ($znaci as $i => $z) {
            $mapa[chr(0x80 + $i)] = $z;
        }
    }
    return strtr($s, $mapa);
}

/**
 * Pretvara tekst fajla u tabelu. Vraća ['redovi' => [[ćelije], ...], 'razdvajac' => string]
 * ili ['greska' => poruka].
 */
function uvoz_procitaj_tekst(string $sirovo): array
{
    if ($sirovo === '' || trim($sirovo) === '') {
        return ['greska' => 'Fajl je prazan.'];
    }
    if (strlen($sirovo) > UVOZ_MAX_BAJTOVA) {
        return ['greska' => 'Fajl je prevelik (najviše ' . intdiv(UVOZ_MAX_BAJTOVA, 1048576) . ' MB).'];
    }
    $pocetak = substr($sirovo, 0, 8);
    if (str_starts_with($pocetak, 'PK') || str_starts_with($pocetak, "\xD0\xCF\x11\xE0") || str_starts_with($pocetak, '%PDF') || str_contains(substr($sirovo, 0, 4096), "\0")) {
        return ['greska' => 'Ovo nije tekstualni fajl. Excel (.xlsx / .xls) i PDF ne mogu direktno: u Excelu izaberite „Sačuvaj kao → CSV“ ili označite tabelu, kopirajte je i nalepite u polje ispod.'];
    }
    if (str_starts_with($sirovo, "\xEF\xBB\xBF")) {
        $sirovo = substr($sirovo, 3);
    }
    if (!mb_check_encoding($sirovo, 'UTF-8')) {
        $sirovo = uvoz_iz_cp1250($sirovo);
    }

    $linije = preg_split('/\r\n|\n|\r/', $sirovo) ?: [];
    $linije = array_values(array_filter($linije, static fn(string $l): bool => trim($l) !== ''));
    if (!$linije) {
        return ['greska' => 'Fajl je prazan.'];
    }

    // Razdvajač: prvi (tab, ;, ,) koji u prvih nekoliko redova daje isti broj kolona (najmanje 2).
    $razdvajac = null;
    foreach (["\t", ';', ','] as $kandidat) {
        $brojevi = [];
        foreach (array_slice($linije, 0, 6) as $l) {
            $brojevi[] = count(str_getcsv($l, $kandidat, '"', ''));
        }
        if (min($brojevi) >= 2 && count(array_unique($brojevi)) === 1) {
            $razdvajac = $kandidat;
            break;
        }
    }
    if ($razdvajac === null) {
        // Tolerantnije: razdvajač koji ima najviše kolona u prvom redu.
        $najbolji = 1;
        foreach (["\t", ';', ','] as $kandidat) {
            $n = count(str_getcsv($linije[0], $kandidat, '"', ''));
            if ($n > $najbolji) {
                $najbolji = $n;
                $razdvajac = $kandidat;
            }
        }
    }
    if ($razdvajac === null) {
        return ['greska' => 'Ne prepoznajem kolone. Fajl treba da ima bar dve kolone (naziv i količina), razdvojene tabulatorom, tačka-zarezom ili zarezom.'];
    }

    $redovi = [];
    foreach ($linije as $l) {
        $celije = str_getcsv($l, $razdvajac, '"', '');
        if (count($celije) > UVOZ_MAX_KOLONA) {
            return ['greska' => 'Previše kolona u fajlu (najviše ' . UVOZ_MAX_KOLONA . ').'];
        }
        $celije = array_map(static fn($c): string => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)$c)), 0, 160), $celije);
        if (implode('', $celije) === '') {
            continue;
        }
        $redovi[] = $celije;
        if (count($redovi) > UVOZ_MAX_REDOVA + 1) {
            return ['greska' => 'Previše redova (najviše ' . UVOZ_MAX_REDOVA . '). Izvezite samo Farmerkop artikle ili obrišite suvišne redove.'];
        }
    }
    if (count($redovi) < 1) {
        return ['greska' => 'Fajl je prazan.'];
    }
    $ime_razdvajaca = ['t' => 'tabulator', ';' => 'tačka-zarez', ',' => 'zarez'];
    return ['redovi' => $redovi, 'razdvajac' => $razdvajac === "\t" ? $ime_razdvajaca['t'] : $ime_razdvajaca[$razdvajac]];
}

/** Širina tabele (najveći broj kolona). */
function uvoz_sirina(array $redovi): int
{
    $s = 0;
    foreach ($redovi as $r) {
        $s = max($s, count($r));
    }
    return $s;
}

/**
 * Pretpostavka šta je koja kolona: ['zaglavlje' => bool, 'naziv' => int, 'kolicina' => int, 'sifra' => int]
 * (-1 = nema). Sve se može promeniti na ekranu.
 */
function uvoz_pretpostavi_kolone(array $redovi): array
{
    $sirina = uvoz_sirina($redovi);
    $prvi = $redovi[0] ?? [];
    $ima_broj_u_prvom = false;
    foreach ($prvi as $c) {
        if (uvoz_broj($c) !== null) {
            $ima_broj_u_prvom = true;
        }
    }
    $zaglavlje = !$ima_broj_u_prvom && count($redovi) > 1;
    $podaci = $zaglavlje ? array_slice($redovi, 1) : $redovi;
    $uzorak = array_slice($podaci, 0, 200);

    // Statistika po koloni: udeo brojeva i prosečna dužina teksta.
    $stat = [];
    for ($k = 0; $k < $sirina; $k++) {
        $br = 0;
        $tekst = 0;
        $duzina = 0;
        foreach ($uzorak as $r) {
            $c = $r[$k] ?? '';
            if ($c === '') {
                continue;
            }
            if (uvoz_broj($c) !== null) {
                $br++;
            } else {
                $tekst++;
                $duzina += mb_strlen($c);
            }
        }
        $ukupno = max(1, $br + $tekst);
        $stat[$k] = ['broj' => $br / $ukupno, 'duzina' => $tekst > 0 ? $duzina / $tekst : 0];
    }

    $naziv = -1;
    $kolicina = -1;
    $sifra = -1;
    if ($zaglavlje) {
        foreach ($prvi as $k => $c) {
            $n = ' ' . uvoz_norm($c) . ' ';
            if ($sifra < 0 && preg_match('/ (sifra|sif|kod|code|id) /', $n)) {
                $sifra = $k;
            } elseif ($naziv < 0 && preg_match('/ (naziv|artikal|proizvod|roba|opis|name|item|artikl) /', $n)) {
                $naziv = $k;
            } elseif ($kolicina < 0 && preg_match('/ (stanje|kolicina|zaliha|kol|qty|stock|quantity|komada|komadi|lager) /', $n)) {
                $kolicina = $k;
            }
        }
    }
    if ($naziv < 0) {
        $najduzi = 0.0;
        for ($k = 0; $k < $sirina; $k++) {
            if ($k !== $kolicina && $k !== $sifra && $stat[$k]['broj'] < 0.5 && $stat[$k]['duzina'] > $najduzi) {
                $najduzi = $stat[$k]['duzina'];
                $naziv = $k;
            }
        }
    }
    if ($kolicina < 0) {
        for ($k = max(0, $naziv + 1); $k < $sirina; $k++) {
            if ($k !== $sifra && $stat[$k]['broj'] >= 0.7) {
                $kolicina = $k;
                break;
            }
        }
    }
    if ($kolicina < 0) {
        for ($k = 0; $k < $sirina; $k++) {
            if ($k !== $naziv && $k !== $sifra && $stat[$k]['broj'] >= 0.7) {
                $kolicina = $k;
                break;
            }
        }
    }
    return ['zaglavlje' => $zaglavlje, 'naziv' => $naziv, 'kolicina' => $kolicina, 'sifra' => $sifra];
}

// ─── Uparivanje redova sa SKU-ovima ────────────────────────────────────────

/** Nazivi artikala/varijanti bez zaglavlja koje ne nose značenje pri poređenju. */
function uvoz_reci(string $s): array
{
    $reci = [];
    foreach (explode(' ', uvoz_norm($s)) as $r) {
        if ($r !== '' && !in_array($r, ['cm', 'pl', 'kom'], true)) {
            $reci[] = $r;
        }
    }
    return $reci;
}

/** Dve reči su iste ako su jednake ili je jedna početak druge (malč/malca, oblutak/oblutka). */
function uvoz_iste_reci(string $a, string $b): bool
{
    if ($a === $b) {
        return true;
    }
    return min(strlen($a), strlen($b)) >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a));
}

function uvoz_sadrzi(array $skup, string $rec): bool
{
    foreach ($skup as $r) {
        if (uvoz_iste_reci($r, $rec)) {
            return true;
        }
    }
    return false;
}

/** Granulacija iz teksta: "1-3" (ili null). Vraća [opseg, tekst bez opsega]. */
function uvoz_izvuci_opseg(string $lat): array
{
    if (preg_match('/(\d{1,3})\s*[-–—]\s*(\d{1,3})/u', $lat, $m)) {
        return [$m[1] . '-' . $m[2], str_replace($m[0], ' ', $lat)];
    }
    return [null, $lat];
}

/** Pakovanje iz teksta: [količina, jedinica] ili null. Vraća [pak, tekst bez pakovanja]. */
function uvoz_izvuci_pakovanje(string $lat): array
{
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(l|lit[a-z]*|kg|kgr)(?![a-z0-9])/u', $lat, $m)) {
        $jed = str_starts_with($m[2], 'k') ? 'kg' : 'l';
        return [[(float)str_replace(',', '.', $m[1]), $jed], str_replace($m[0], ' ', $lat)];
    }
    return [null, $lat];
}

/**
 * Spisak aktivnih SKU-ova sa podacima za uparivanje.
 * ['sku' => [id => red], 'artikli' => [artikal_id => [...]]]
 */
function uvoz_indeks_sku(): array
{
    $redovi = db_all(
        'SELECT s.id AS sku_id, s.po_paleti, s.po_paketu, a.id AS artikal_id, a.naziv AS artikal, a.oznaka,
                v.id AS varijanta_id, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica
         FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
         JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE ' . SQL_AKTIVAN_SKU . '
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina'
    );
    $indeks = ['sku' => [], 'artikli' => []];
    foreach ($redovi as $r) {
        $sid = (int)$r['sku_id'];
        $aid = (int)$r['artikal_id'];
        $r['naziv'] = sku_naziv($r);
        $indeks['sku'][$sid] = $r;
        if (!isset($indeks['artikli'][$aid])) {
            $indeks['artikli'][$aid] = ['naziv' => $r['artikal'], 'reci' => uvoz_reci((string)$r['artikal']), 'sku' => []];
        }
        $indeks['artikli'][$aid]['sku'][] = $sid;
    }
    return $indeks;
}

/** Zapamćena uparivanja: [ključ => sku_id]. */
function uvoz_pamcenje(): array
{
    $rez = [];
    foreach (db_all('SELECT kljuc, sku_id FROM uvoz_mapiranje') as $r) {
        $rez[(string)$r['kljuc']] = (int)$r['sku_id'];
    }
    return $rez;
}

/**
 * Jedan red iz fajla → SKU. Vraća ['sku_id' => int (0 = nije prepoznato), 'izvor' => memorija|prepoznato|proveri|nema].
 */
function uvoz_upari(string $naziv, string $sifra, array $indeks, array $pamcenje): array
{
    $kljucevi = [];
    if ($sifra !== '' && uvoz_norm($sifra) !== '') {
        $kljucevi[] = uvoz_kljuc('s', $sifra);
    }
    if (uvoz_norm($naziv) !== '') {
        $kljucevi[] = uvoz_kljuc('n', $naziv);
    }
    foreach ($kljucevi as $k) {
        if (isset($pamcenje[$k]) && isset($indeks['sku'][$pamcenje[$k]])) {
            return ['sku_id' => $pamcenje[$k], 'izvor' => 'memorija'];
        }
    }

    $lat = uvoz_latinica($naziv);
    [$opseg, $lat] = uvoz_izvuci_opseg($lat);
    [$pak, $lat] = uvoz_izvuci_pakovanje($lat);
    $reci = uvoz_reci($lat);
    if (!$reci) {
        return ['sku_id' => 0, 'izvor' => 'nema'];
    }

    // 1. Artikal: svi njegovi nazivi se nalaze u tekstu (najviše reči pobeđuje), inače najbolji delimični pogodak.
    $pun = [];
    $delimicno = [];
    foreach ($indeks['artikli'] as $aid => $a) {
        $pogodjeno = 0;
        foreach ($a['reci'] as $rec) {
            if (uvoz_sadrzi($reci, $rec)) {
                $pogodjeno++;
            }
        }
        $ukupno = count($a['reci']);
        if ($ukupno > 0 && $pogodjeno === $ukupno) {
            $pun[$aid] = $ukupno;
        } elseif ($pogodjeno > 0 && $pogodjeno / max(1, $ukupno) >= 0.5) {
            $delimicno[$aid] = $pogodjeno;
        }
    }
    $izvor = 'prepoznato';
    $kandidati = $pun;
    if (!$kandidati) {
        $kandidati = $delimicno;
        $izvor = 'proveri';
    }
    if (!$kandidati) {
        return ['sku_id' => 0, 'izvor' => 'nema'];
    }
    $max = max($kandidati);
    $najbolji = array_keys(array_filter($kandidati, static fn(int $v): bool => $v === $max));
    if (count($najbolji) !== 1) {
        return ['sku_id' => 0, 'izvor' => 'nema'];
    }
    $aid = (int)$najbolji[0];
    $skui = array_map(static fn(int $sid): array => $indeks['sku'][$sid], $indeks['artikli'][$aid]['sku']);

    // Reči koje pripadaju samom artiklu ne računaju se kao deo boje/granulacije.
    $ostale = array_values(array_filter($reci, static fn(string $r): bool => !uvoz_sadrzi($indeks['artikli'][$aid]['reci'], $r)));

    // 2. Boja / granulacija.
    $sa_varijantom = array_filter($skui, static fn(array $r): bool => $r['varijanta'] !== null);
    if ($sa_varijantom) {
        $pogodak = [];
        foreach ($skui as $r) {
            if ($r['varijanta'] === null) {
                continue;
            }
            [$v_opseg, $v_tekst] = uvoz_izvuci_opseg(uvoz_latinica((string)$r['varijanta']));
            $v_reci = uvoz_reci($v_tekst);
            if ($v_opseg !== null) {
                if ($opseg === $v_opseg) {
                    $pogodak[(int)$r['varijanta_id']] = true;
                }
            } elseif ($v_reci) {
                $svi = true;
                foreach ($v_reci as $vr) {
                    if (!uvoz_sadrzi($ostale, $vr)) {
                        $svi = false;
                    }
                }
                if ($svi) {
                    $pogodak[(int)$r['varijanta_id']] = true;
                }
            }
        }
        if (count($pogodak) !== 1) {
            return ['sku_id' => 0, 'izvor' => 'nema'];
        }
        $vid = (int)array_key_first($pogodak);
        $skui = array_values(array_filter($skui, static fn(array $r): bool => (int)$r['varijanta_id'] === $vid));
    }

    // 3. Pakovanje.
    if ($pak !== null) {
        $skui = array_values(array_filter(
            $skui,
            static fn(array $r): bool => $r['jedinica'] === $pak[1] && abs((float)$r['pak_kolicina'] - $pak[0]) < 0.001
        ));
    } elseif (count($skui) > 1) {
        // Bez oznake jedinice: "Humovit 10" – broj mora da odgovara tačno jednom pakovanju.
        $brojevi = [];
        foreach (explode(' ', uvoz_norm($lat)) as $t) {
            if (ctype_digit($t)) {
                $brojevi[] = (float)$t;
            }
        }
        $skui = array_values(array_filter(
            $skui,
            static function (array $r) use ($brojevi): bool {
                foreach ($brojevi as $b) {
                    if (abs((float)$r['pak_kolicina'] - $b) < 0.001) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }
    if (count($skui) !== 1) {
        return ['sku_id' => 0, 'izvor' => 'nema'];
    }
    return ['sku_id' => (int)$skui[0]['sku_id'], 'izvor' => $izvor];
}

// ─── Pregled i primena ─────────────────────────────────────────────────────

/** Količina iz fajla u komadima za dati SKU. Vraća ['komadi' => int] ili ['greska' => tekst]. */
function uvoz_komadi(?float $kolicina, string $jedinica, ?array $sku): array
{
    if ($kolicina === null) {
        return ['greska' => 'količina nije broj'];
    }
    if ($kolicina < 0) {
        return ['greska' => 'količina je negativna'];
    }
    if (abs($kolicina - round($kolicina)) > 0.0001) {
        return ['greska' => 'količina nije ceo broj'];
    }
    $n = (int)round($kolicina);
    $mnozilac = 1;
    if ($jedinica === 'paketi') {
        $mnozilac = (int)($sku['po_paketu'] ?? 0);
        if ($sku !== null && $mnozilac <= 0) {
            return ['greska' => 'nije podešeno koliko komada ima u paketu'];
        }
    } elseif ($jedinica === 'palete') {
        $mnozilac = (int)($sku['po_paleti'] ?? 0);
        if ($sku !== null && $mnozilac <= 0) {
            return ['greska' => 'nije podešeno koliko komada ide na paletu'];
        }
    }
    $mnozilac = max(1, $mnozilac);
    if ($n * $mnozilac > 99999999) {
        return ['greska' => 'količina je prevelika'];
    }
    return ['komadi' => $n * $mnozilac];
}

/**
 * Sastavlja pregled: za svaki red fajla naziv, šifru, količinu, predloženi SKU i grešku.
 *
 * @param array $tabela    redovi iz fajla
 * @param array $izbor     ['zaglavlje' => bool, 'naziv' => int, 'kolicina' => int, 'sifra' => int, 'jedinica' => string, 'format' => 'sr'|'en']
 * @param array|null $rucno [indeks reda => sku_id] ručni izbor sa ekrana (null = uparuj sam)
 */
function uvoz_pregled(array $tabela, array $izbor, array $indeks, array $pamcenje, ?array $rucno): array
{
    $stavke = [];
    $pocetak = !empty($izbor['zaglavlje']) ? 1 : 0;
    for ($i = $pocetak; $i < count($tabela); $i++) {
        $r = $tabela[$i];
        $naziv = $izbor['naziv'] >= 0 ? (string)($r[$izbor['naziv']] ?? '') : '';
        $sifra = $izbor['sifra'] >= 0 ? (string)($r[$izbor['sifra']] ?? '') : '';
        $sirova = $izbor['kolicina'] >= 0 ? (string)($r[$izbor['kolicina']] ?? '') : '';
        if ($naziv === '' && $sifra === '' && $sirova === '') {
            continue;
        }
        if ($rucno !== null && array_key_exists($i, $rucno)) {
            $sid = (int)$rucno[$i];
            $sid = isset($indeks['sku'][$sid]) ? $sid : 0;
            $up = ['sku_id' => $sid, 'izvor' => $sid > 0 ? 'rucno' : 'preskoceno'];
        } else {
            $up = uvoz_upari($naziv, $sifra, $indeks, $pamcenje);
        }
        $kol = uvoz_broj($sirova, ($izbor['format'] ?? 'sr') === 'en' ? 'en' : 'sr');
        $sku = $up['sku_id'] > 0 ? $indeks['sku'][$up['sku_id']] : null;
        $k = uvoz_komadi($kol, (string)$izbor['jedinica'], $sku);
        $stavke[] = [
            'i'       => $i,
            'naziv'   => $naziv,
            'sifra'   => $sifra,
            'sirova'  => $sirova,
            'sku_id'  => $up['sku_id'],
            'izvor'   => $up['izvor'],
            'komadi'  => $k['komadi'] ?? null,
            'greska'  => $k['greska'] ?? null,
        ];
    }
    return $stavke;
}

/**
 * Zbir po SKU-u (duplikati se sabiraju) i spisak problema koji sprečavaju uvoz.
 * Vraća ['zbir' => [sku_id => komadi], 'greske' => [tekst], 'preskoceno' => int, 'duplikata' => int].
 */
function uvoz_zbir(array $stavke, array $indeks): array
{
    $zbir = [];
    $greske = [];
    $preskoceno = 0;
    $duplikata = 0;
    foreach ($stavke as $s) {
        if ($s['sku_id'] <= 0) {
            $preskoceno++;
            continue;
        }
        if ($s['greska'] !== null) {
            $greske[] = ($s['naziv'] !== '' ? $s['naziv'] : $s['sifra']) . ': ' . $s['greska'];
            continue;
        }
        if (isset($zbir[$s['sku_id']])) {
            $duplikata++;
            $zbir[$s['sku_id']] += (int)$s['komadi'];
        } else {
            $zbir[$s['sku_id']] = (int)$s['komadi'];
        }
        if ($zbir[$s['sku_id']] > 99999999) {
            $greske[] = $indeks['sku'][$s['sku_id']]['naziv'] . ': zbir količina je prevelik';
        }
    }
    return ['zbir' => $zbir, 'greske' => $greske, 'preskoceno' => $preskoceno, 'duplikata' => $duplikata];
}

/** Pamti uparivanje naziv/šifra → SKU za sledeći uvoz. */
function uvoz_zapamti(array $stavke): void
{
    foreach ($stavke as $s) {
        if ($s['sku_id'] <= 0 || $s['greska'] !== null) {
            continue;
        }
        $kljucevi = [];
        if (uvoz_norm($s['naziv']) !== '') {
            $kljucevi[] = uvoz_kljuc('n', $s['naziv']);
        }
        if (uvoz_norm($s['sifra']) !== '') {
            $kljucevi[] = uvoz_kljuc('s', $s['sifra']);
        }
        foreach ($kljucevi as $k) {
            db_run(
                'INSERT INTO uvoz_mapiranje (kljuc, sku_id, napravljen) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE sku_id = VALUES(sku_id), napravljen = VALUES(napravljen)',
                [$k, $s['sku_id'], sada()]
            );
        }
    }
}

/** Primer CSV-a (za preuzimanje) sa nazivima kakvi se u fajlu uparuju sami. */
function uvoz_primer_csv(): string
{
    $redovi = [
        ['Šifra', 'Naziv', 'Stanje'],
        ['1001', 'Humovit 5 l', '1250'],
        ['1002', 'Humovit 10 l', '540'],
        ['1003', 'Humovit 25 l', '120'],
        ['1010', 'Humovit premium 20 l', '240'],
        ['1020', 'Floris Savacoop PL 10 l', '270'],
        ['1030', 'Idea PL 10 l', '225'],
        ['1100', 'Malč Farmerkop Crveni 50 l', '80'],
        ['1101', 'Malč Farmerkop Neobojeni 50 l', '120'],
        ['1200', 'Beli oblutak 1-3 cm 20 kg', '100'],
        ['1204', 'Beli oblutak 4-7 cm krupnija 20 kg', '50'],
    ];
    $izlaz = "\xEF\xBB\xBF";
    foreach ($redovi as $r) {
        $izlaz .= implode(';', $r) . "\r\n";
    }
    return $izlaz;
}
