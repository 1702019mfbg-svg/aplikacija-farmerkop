<?php
/**
 * Kalkulacija (Faza 1) – samo računanje, bez baze, da bi se lako testiralo (tests/kalkulacija_test.php).
 *
 * Trošak sirovina za džak od V litara = Σ udeo_i × V × (cena_i / litara gotovog po jedinici_i)
 * Ukupan trošak = trošak sirovina + ostali troškovi (po džaku + po litru × V)
 * Marža = prodajna cena bez PDV-a − ukupan trošak (RSD i % od prodajne cene bez PDV-a)
 *
 * Kad sirovini nedostaje cena ili faktor, trošak se NE računa (nema tihe nule): vraća se null i spisak
 * onoga što nedostaje ("nedostaje cena za: Beli treset").
 */
declare(strict_types=1);

/** Udeli se čuvaju na 3 decimale; zbir mora biti tačno 100 % (poredi se u hiljaditim delovima procenta). */
const KALK_ZBIR_UDELA = 100000;

/**
 * Broj iz unosa: "1.234,56", "1234,56", "1234.56", "12". Prazno → null, neispravno ili negativno → false.
 * Tačka je razdvajač hiljada samo u obliku 1.234 / 1.234.567 (tačno tri cifre posle tačke, ne 0.125) ili uz zarez.
 */
function kalk_broj_iz(string $t, int $decimale = 2): float|null|false
{
    $t = str_replace([' ', "\u{00A0}", 'RSD', 'rsd', 'din', '%'], '', trim($t));
    if ($t === '') {
        return null;
    }
    if (str_contains($t, ',')) {
        $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
    } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $t)) {
        $t = str_replace('.', '', $t);
    }
    if (!preg_match('/^\d{1,12}(\.\d+)?$/', $t)) {
        return false;
    }
    $dec = strpos($t, '.') === false ? 0 : strlen($t) - strpos($t, '.') - 1;
    if ($dec > $decimale) {
        return false;
    }
    return (float)$t;
}

/** "1.234,56 RSD" (null → "—"). */
function kalk_rsd(?float $x, int $decimale = 2): string
{
    return $x === null ? '—' : broj(round($x, $decimale), $decimale) . ' RSD';
}

/** "30,0 %" (null → "—"). */
function kalk_pct(?float $x, int $decimale = 1): string
{
    return $x === null ? '—' : broj(round($x, $decimale), $decimale) . ' %';
}

/** Udeo za prikaz: "60 %", "0,125 %". */
function kalk_udeo_tekst(float $udeo): string
{
    $d = abs($udeo - round($udeo)) < 0.0005 ? 0 : (abs($udeo * 10 - round($udeo * 10)) < 0.005 ? 1 : (abs($udeo * 100 - round($udeo * 100)) < 0.05 ? 2 : 3));
    return broj($udeo, $d) . ' %';
}

/** Zbir udela u hiljaditim delovima procenta (100 % = 100000). */
function kalk_zbir_udela(array $udeli): int
{
    return array_sum(array_map(static fn($u): int => (int)round((float)$u * 1000), $udeli));
}

/** Da li je zbir udela tačno 100 %. */
function kalk_zbir_ok(array $udeli): bool
{
    return kalk_zbir_udela($udeli) === KALK_ZBIR_UDELA;
}

/** Cena bez PDV-a (marže se uvek računaju bez PDV-a). */
function kalk_bez_pdv(float $cena, bool $sa_pdv, float $stopa): float
{
    return $sa_pdv ? $cena / (1 + $stopa / 100) : $cena;
}

/** Cena sa PDV-om (za prikaz potrebne cene). */
function kalk_sa_pdv(float $cena_bez, float $stopa): float
{
    return $cena_bez * (1 + $stopa / 100);
}

/**
 * Trošak jednog džaka od $V litara.
 *
 * @param array $sastav   [['materijal_id' => int, 'udeo' => float (%)], ...] – udeli po zapremini gotovog proizvoda
 * @param array $sirovine [materijal_id => ['naziv' => string, 'cena' => ?float (po jedinici nabavke),
 *                        'faktor' => ?float (litara gotovog po jedinici), 'jedinica' => ?string]]
 * @param array $troskovi [['naziv' => string, 'tip' => 'dzak'|'litar', 'iznos' => float], ...] – već izabrani za ovu veličinu
 * @return array ['sirovine' => ?float, 'ostalo' => float, 'ukupno' => ?float, 'nedostaje' => [tekst, ...],
 *               'razrada' => [['naziv', 'udeo', 'litara', 'po_litru' => ?float, 'iznos' => ?float]],
 *               'razrada_ostalo' => [['naziv', 'tip', 'iznos']]]
 */
function kalk_trosak_dzaka(float $V, array $sastav, array $sirovine, array $troskovi): array
{
    $razrada = [];
    $bez_cene = [];
    $bez_faktora = [];
    $zbir = 0.0;
    foreach ($sastav as $s) {
        $id = (int)$s['materijal_id'];
        $m = $sirovine[$id] ?? ['naziv' => 'sirovina #' . $id, 'cena' => null, 'faktor' => null];
        $udeo = (float)$s['udeo'];
        $litara = $udeo / 100 * $V;
        $cena = $m['cena'] ?? null;
        $faktor = $m['faktor'] ?? null;
        $po_litru = null;
        if ($cena === null) {
            $bez_cene[] = $m['naziv'];
        }
        if ($faktor === null || $faktor <= 0) {
            $bez_faktora[] = $m['naziv'];
        }
        if ($cena !== null && $faktor !== null && $faktor > 0) {
            $po_litru = $cena / $faktor;
        }
        $iznos = $po_litru === null ? null : $litara * $po_litru;
        $zbir += $iznos ?? 0;
        $razrada[] = ['naziv' => $m['naziv'], 'udeo' => $udeo, 'litara' => $litara, 'po_litru' => $po_litru, 'iznos' => $iznos];
    }
    $nedostaje = [];
    if (!$sastav) {
        $nedostaje[] = 'nema recepture';
    }
    if ($sastav && !kalk_zbir_ok(array_column($sastav, 'udeo'))) {
        $nedostaje[] = 'zbir udela u recepturi nije 100 %';
    }
    if ($bez_cene) {
        $nedostaje[] = 'nedostaje cena za: ' . implode(', ', $bez_cene);
    }
    if ($bez_faktora) {
        $nedostaje[] = 'nedostaje faktor (litara po jedinici) za: ' . implode(', ', $bez_faktora);
    }

    $ostalo = 0.0;
    $razrada_ostalo = [];
    foreach ($troskovi as $t) {
        $iznos = $t['tip'] === 'litar' ? (float)$t['iznos'] * $V : (float)$t['iznos'];
        $ostalo += $iznos;
        $razrada_ostalo[] = ['naziv' => $t['naziv'], 'tip' => $t['tip'], 'jedinicno' => (float)$t['iznos'], 'iznos' => $iznos];
    }
    $sir = $nedostaje ? null : $zbir;
    return [
        'sirovine'       => $sir,
        'ostalo'         => $ostalo,
        'ukupno'         => $sir === null ? null : $sir + $ostalo,
        'nedostaje'      => $nedostaje,
        'razrada'        => $razrada,
        'razrada_ostalo' => $razrada_ostalo,
    ];
}

/** Marža: ['rsd' => prodajna − trošak, 'pct' => % od prodajne cene bez PDV-a] ili null kad nešto nedostaje. */
function kalk_marza(?float $prodajna_bez_pdv, ?float $ukupno): ?array
{
    if ($prodajna_bez_pdv === null || $ukupno === null) {
        return null;
    }
    $rsd = $prodajna_bez_pdv - $ukupno;
    return ['rsd' => $rsd, 'pct' => $prodajna_bez_pdv > 0 ? $rsd / $prodajna_bez_pdv * 100 : null];
}

/** Prodajna cena bez PDV-a potrebna za ciljanu maržu (% od prodajne): trošak / (1 − marža). Null za maržu ≥ 100 %. */
function kalk_potrebna_cena(?float $ukupno, float $ciljana_pct): ?float
{
    if ($ukupno === null || $ciljana_pct >= 100) {
        return null;
    }
    return $ukupno / (1 - $ciljana_pct / 100);
}

/** Nova cena posle promene: u procentima (+10 / −5) ili u iznosu (+25 / −10 RSD). Ne ide ispod nule (null). */
function kalk_promeni(float $cena, string $nacin, float $vrednost): ?float
{
    $nova = $nacin === 'pct' ? $cena * (1 + $vrednost / 100) : $cena + $vrednost;
    return $nova < 0 ? null : $nova;
}
