<?php
/**
 * Testovi računanja kalkulacije (bez baze i servera): php tests/kalkulacija_test.php
 *
 * Primer: džak 50 l, dve sirovine 60 / 40 %:
 *   crni treset – bala 1.200 RSD, daje 300 l gotovog (faktor 300)  → 4 RSD/l × 30 l = 120 RSD
 *   perlit      – džak 900 RSD, daje 100 l gotovog (faktor 100)    → 9 RSD/l × 20 l = 180 RSD
 *   sirovine 300 RSD + vreća 25 RSD/džak + rad 0,50 RSD/l × 50 l = 25 RSD → ukupno 350 RSD
 *   prodajna 600 RSD sa PDV 20 % = 500 bez PDV-a → marža 150 RSD = 30 %
 */
declare(strict_types=1);
require __DIR__ . '/../app/inc/helpers.php';
require __DIR__ . '/../app/inc/kalkulacija.php';

$ok = 0;
$palo = [];
function proveri(bool $uslov, string $opis, mixed $detalj = ''): void
{
    global $ok, $palo;
    if ($uslov) {
        $ok++;
        echo "  ✓ $opis\n";
    } else {
        $palo[] = $opis;
        echo "  ✗ $opis\n      " . var_export($detalj, true) . "\n";
    }
}
function blizu(?float $a, float $b): bool
{
    return $a !== null && abs($a - $b) < 0.000001;
}

$sirovine = [
    1 => ['naziv' => 'Crni treset', 'cena' => 1200.0, 'faktor' => 300.0],
    2 => ['naziv' => 'Perlit', 'cena' => 900.0, 'faktor' => 100.0],
    3 => ['naziv' => 'Beli treset', 'cena' => null, 'faktor' => 250.0],
    4 => ['naziv' => 'NPK', 'cena' => 150.0, 'faktor' => null],
];
$recept = [['materijal_id' => 1, 'udeo' => 60], ['materijal_id' => 2, 'udeo' => 40]];
$troskovi = [['naziv' => 'Vreća 50 l', 'tip' => 'dzak', 'iznos' => 25.0], ['naziv' => 'Rad', 'tip' => 'litar', 'iznos' => 0.5]];

echo "▶ Dve sirovine 60 / 40, faktor 300 i 100\n";
$r = kalk_trosak_dzaka(50, $recept, $sirovine, $troskovi);
proveri($r['nedostaje'] === [], 'ništa ne nedostaje', $r['nedostaje']);
proveri(blizu($r['razrada'][0]['litara'], 30) && blizu($r['razrada'][0]['po_litru'], 4) && blizu($r['razrada'][0]['iznos'], 120), 'crni treset: 30 l × 4 RSD/l = 120 RSD', $r['razrada'][0]);
proveri(blizu($r['razrada'][1]['litara'], 20) && blizu($r['razrada'][1]['po_litru'], 9) && blizu($r['razrada'][1]['iznos'], 180), 'perlit: 20 l × 9 RSD/l = 180 RSD', $r['razrada'][1]);
proveri(blizu($r['sirovine'], 300), 'trošak sirovina 300 RSD', $r['sirovine']);
proveri(blizu($r['ostalo'], 50) && blizu($r['razrada_ostalo'][1]['iznos'], 25), 'ostalo: vreća 25 + rad 0,50 × 50 l = 50 RSD', $r['razrada_ostalo']);
proveri(blizu($r['ukupno'], 350), 'ukupan trošak 350 RSD', $r['ukupno']);
$bez = kalk_bez_pdv(600, true, 20);
proveri(blizu($bez, 500), '600 RSD sa PDV 20 % = 500 bez PDV-a', $bez);
$m = kalk_marza($bez, $r['ukupno']);
proveri(blizu($m['rsd'], 150) && blizu($m['pct'], 30), 'marža 150 RSD = 30 %', $m);
proveri(blizu(kalk_marza(500, 350)['pct'], kalk_marza(kalk_bez_pdv(500, false, 20), 350)['pct']), 'cena bez PDV-a se ne preračunava');
$p = kalk_potrebna_cena(350, 40);
proveri(blizu($p, 350 / 0.6) && blizu(kalk_marza($p, 350)['pct'], 40), 'za maržu 40 % treba 583,33 RSD bez PDV-a', $p);
proveri(kalk_rsd(kalk_sa_pdv($p, 20)) === '700,00 RSD', 'to je 700,00 RSD sa PDV-om', kalk_rsd(kalk_sa_pdv($p, 20)));
proveri(kalk_potrebna_cena(350, 100) === null, 'marža 100 % nije moguća');

echo "▶ Faktor menja trošak (sabijen treset)\n";
$r10 = kalk_trosak_dzaka(10, $recept, $sirovine, []);
proveri(blizu($r10['sirovine'], 60), 'džak 10 l: 6 l × 4 + 4 l × 9 = 60 RSD (srazmerno zapremini)', $r10['sirovine']);
$sabijen = $sirovine;
$sabijen[1]['faktor'] = 600.0;                // ista bala daje duplo više gotovog proizvoda
$rs = kalk_trosak_dzaka(50, $recept, $sabijen, []);
proveri(blizu($rs['razrada'][0]['iznos'], 60) && blizu($rs['sirovine'], 240), 'faktor 600 umesto 300: treset 60 RSD, sirovine 240 RSD', $rs['sirovine']);

echo "▶ Nedostaje cena ili faktor – nema tihe nule\n";
$rn = kalk_trosak_dzaka(50, [['materijal_id' => 1, 'udeo' => 50], ['materijal_id' => 3, 'udeo' => 50]], $sirovine, $troskovi);
proveri($rn['sirovine'] === null && $rn['ukupno'] === null, 'bez cene belog treseta trošak nije izračunat (null, ne 0)');
proveri(in_array('nedostaje cena za: Beli treset', $rn['nedostaje'], true), 'poruka: nedostaje cena za: Beli treset', $rn['nedostaje']);
proveri(blizu($rn['razrada'][0]['iznos'], 100) && $rn['razrada'][1]['iznos'] === null, 'razrada ipak pokazuje ono što može da se izračuna');
$rf = kalk_trosak_dzaka(50, [['materijal_id' => 1, 'udeo' => 99], ['materijal_id' => 4, 'udeo' => 1]], $sirovine, []);
proveri($rf['ukupno'] === null && in_array('nedostaje faktor (litara po jedinici) za: NPK', $rf['nedostaje'], true), 'bez faktora za NPK: nije izračunato', $rf['nedostaje']);
proveri(kalk_marza(500, null) === null, 'bez troška nema ni marže');
$rp = kalk_trosak_dzaka(50, [], $sirovine, $troskovi);
proveri($rp['ukupno'] === null && $rp['nedostaje'] === ['nema recepture'], 'bez recepture: „nema recepture“');

echo "▶ Zbir udela mora biti tačno 100 %\n";
proveri(kalk_zbir_ok([60, 40]) && kalk_zbir_ok([99.875, 0.125]) && kalk_zbir_ok(['33.333', '33.333', '33.334']), '60 + 40, 99,875 + 0,125, 33,333 × 2 + 33,334');
proveri(!kalk_zbir_ok([60, 39.999]) && !kalk_zbir_ok([60, 40.001]) && !kalk_zbir_ok([]), '99,999 i 100,001 nisu 100 %');
$r99 = kalk_trosak_dzaka(50, [['materijal_id' => 1, 'udeo' => 60], ['materijal_id' => 2, 'udeo' => 39]], $sirovine, []);
proveri($r99['ukupno'] === null && in_array('zbir udela u recepturi nije 100 %', $r99['nedostaje'], true), 'receptura od 99 % se ne računa');

echo "▶ Unos brojeva (1.234,56) i prikaz\n";
foreach ([['1.234,56', 1234.56], ['1234,56', 1234.56], ['1234.56', 1234.56], ['1.200', 1200.0], ['12', 12.0], ['0.125', 0.125], ['0,5', 0.5], ['1.234.567', 1234567.0], [' 25 RSD', 25.0]] as [$u, $v]) {
    proveri(kalk_broj_iz($u, 3) === $v, "„{$u}“ = $v", kalk_broj_iz($u, 3));
}
proveri(kalk_broj_iz('') === null && kalk_broj_iz('-5') === false && kalk_broj_iz('abc') === false && kalk_broj_iz('1,234') === false, 'prazno = null; negativno, slova i previše decimala = greška');
proveri(kalk_rsd(1234.5) === '1.234,50 RSD' && kalk_rsd(null) === '—' && kalk_pct(30) === '30,0 %', 'prikaz: 1.234,50 RSD, 30,0 %');
proveri(kalk_udeo_tekst(60) === '60 %' && kalk_udeo_tekst(0.125) === '0,125 %' && kalk_udeo_tekst(12.5) === '12,5 %', 'udeo: 60 %, 12,5 %, 0,125 %');

echo "▶ Šta ako: promena cene\n";
proveri(blizu(kalk_promeni(1200, 'pct', 10), 1320) && blizu(kalk_promeni(1200, 'pct', -25), 900), '+10 % i −25 %');
proveri(blizu(kalk_promeni(900, 'iznos', 100), 1000) && kalk_promeni(900, 'iznos', -1000) === null, '+100 RSD; ispod nule nije dozvoljeno');
$s2 = $sirovine;
$s2[1]['cena'] = kalk_promeni(1200, 'pct', 10);
proveri(blizu(kalk_trosak_dzaka(50, $recept, $s2, $troskovi)['ukupno'], 362), 'treset +10 %: 132 + 180 + 50 = 362 RSD');

echo "\n" . $ok . ' provera prošlo, ' . count($palo) . " palo\n";
if ($palo) {
    echo "PALE PROVERE:\n  - " . implode("\n  - ", $palo) . "\n";
}
exit($palo ? 1 : 0);
