<?php
/**
 * Administrator – kupci po godini: ko je koliko robe uzeo u izabranoj godini (prodaja + utovar sa spiskova − povrat),
 * po kupcu i artiklu, u komadima, litrima i kilogramima. Kućna prodaja i mesta bez upisanog kupca idu pod
 * "Bez kupca". Obrisani unosi i otkazani utovari se ne računaju. Izvoz u Excel (CSV).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';

zahtevaj_ulogu('admin');

$godine = array_map('intval', array_column(db_all(
    "SELECT DISTINCT YEAR(nastalo) AS g FROM unosi WHERE obrisan = 0 AND tip IN ('prodaja', 'kucna_prodaja', 'utovar', 'povrat') ORDER BY g DESC"
), 'g'));
$ova = (int)date('Y');
if (!in_array($ova, $godine, true)) {
    array_unshift($godine, $ova);
}
$godina = get_int('godina') ?: $ova;
if ($godina < 2000 || $godina > 2100) {
    $godina = $ova;
}
$trazi = get_str('kupac', 120);

$redovi = db_all(
    "SELECT u.kupac_id, k.naziv AS kupac, u.sku_id, a.naziv AS artikal, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica,
            SUM(CASE WHEN u.tip = 'povrat' THEN -u.kolicina ELSE u.kolicina END) AS kom,
            SUM(CASE WHEN u.tip = 'utovar' THEN 1 ELSE 0 END) AS utovara
     FROM unosi u
     JOIN sku s ON s.id = u.sku_id JOIN artikli a ON a.id = s.artikal_id JOIN pakovanja p ON p.id = s.pakovanje_id
     LEFT JOIN varijante v ON v.id = s.varijanta_id LEFT JOIN kupci k ON k.id = u.kupac_id
     WHERE u.obrisan = 0 AND u.tip IN ('prodaja', 'kucna_prodaja', 'utovar', 'povrat') AND u.nastalo >= ? AND u.nastalo < ?
     GROUP BY u.kupac_id, k.naziv, u.sku_id, a.naziv, v.naziv, p.kolicina, p.jedinica, a.redosled, a.id, v.redosled, v.id
     HAVING kom <> 0
     ORDER BY a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina",
    [$godina . '-01-01 00:00:00', ($godina + 1) . '-01-01 00:00:00']
);

$kupci = [];
foreach ($redovi as $r) {
    $kljuc = $r['kupac_id'] === null ? 0 : (int)$r['kupac_id'];
    $kupci[$kljuc] ??= ['naziv' => $r['kupac'] ?? 'Bez kupca (kućna prodaja i mesta bez kupca)', 'kom' => 0, 'l' => 0.0, 'kg' => 0.0, 'artikli' => []];
    $kom = (int)$r['kom'];
    $ukupno = $kom * (float)$r['pak_kolicina'];
    $kupci[$kljuc]['kom'] += $kom;
    $kupci[$kljuc][$r['jedinica'] === 'kg' ? 'kg' : 'l'] += $ukupno;
    $kupci[$kljuc]['artikli'][] = ['naziv' => sku_naziv($r), 'artikal' => $r['artikal'], 'varijanta' => $r['varijanta'], 'pakovanje' => pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica']),
                                   'kom' => $kom, 'ukupno' => $ukupno, 'jedinica' => $r['jedinica']];
}
if ($trazi !== '') {
    $kupci = array_filter($kupci, static fn(array $k): bool => mb_stripos($k['naziv'], $trazi) !== false);
}
uasort($kupci, static fn(array $a, array $b): int => [$b['l'] + $b['kg'], $b['kom']] <=> [$a['l'] + $a['kg'], $a['kom']]);

if (get_int('csv') === 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="farmerkop-kupci-' . $godina . '.csv"');
    $izlaz = fopen('php://output', 'w');
    fwrite($izlaz, "\xEF\xBB\xBF");
    fwrite($izlaz, implode(';', array_map('csv_tekst', ['Godina', 'Kupac', 'Artikal', 'Boja / granulacija', 'Pakovanje', 'Komada', 'Ukupno', 'Jedinica'])) . "\r\n");
    foreach ($kupci as $k) {
        foreach ($k['artikli'] as $a) {
            fwrite($izlaz, implode(';', [(string)$godina, csv_tekst($k['naziv']), csv_tekst($a['artikal']), csv_tekst((string)($a['varijanta'] ?? '')),
                csv_tekst($a['pakovanje']), (string)$a['kom'], str_replace('.', ',', (string)round($a['ukupno'], 2)), $a['jedinica']]) . "\r\n");
        }
    }
    fclose($izlaz);
    exit;
}

/** "12.500 l · 3,2 t" */
$kolicina_tekst = static function (float $l, float $kg): string {
    $delovi = [];
    if ($l > 0) {
        $delovi[] = broj($l) . ' l';
    }
    if ($kg > 0) {
        $delovi[] = kg_tekst($kg);
    }
    return implode(' · ', $delovi);
};

ui_start('Kupci po godini', ['nav' => 'admin', 'aktivno' => 'istorija']);
?>
<form method="get" class="kartica" action="<?= e(url('admin/kupci.php')) ?>">
    <div class="kalk-forma">
        <label class="mala-oznaka">Godina<select class="polje" name="godina">
            <?php foreach ($godine as $g): ?><option value="<?= $g ?>"<?= $g === $godina ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
        </select></label>
        <label class="mala-oznaka">Kupac<input class="polje" name="kupac" type="text" maxlength="120" value="<?= e($trazi) ?>" placeholder="svi kupci"></label>
        <button class="btn btn-primary" type="submit">Prikaži</button>
    </div>
    <p class="pomoc bez-margine razmak-gore">Prodaja + utovar sa spiskova − povrat, od 1. 1. do 31. 12. <?= $godina ?>. Otkazani utovari i obrisani unosi se ne računaju.</p>
</form>

<?php if (!$kupci): ?>
    <div class="kartica"><p class="bez-margine">Za <?= $godina ?>. nema prodaje ni utovara<?= $trazi !== '' ? ' za „' . e($trazi) . '“' : '' ?>.</p></div>
<?php else: ?>
    <p class="grupa-dugmica"><a class="btn btn-braon btn-mali" href="<?= e(url('admin/kupci.php?' . http_build_query(['godina' => $godina, 'kupac' => $trazi, 'csv' => 1]))) ?>"><?= ikona('preuzmi') ?> Izvoz u Excel (CSV)</a></p>
    <?php foreach ($kupci as $kid => $k): ?>
        <details class="kartica kupac-godina" data-kupac="<?= (int)$kid ?>">
            <summary class="kartica-naslov">
                <span><strong><?= e($k['naziv']) ?></strong><br><small class="pomoc"><?= e(broj($k['kom'])) ?> kom<?= $kolicina_tekst($k['l'], $k['kg']) !== '' ? ' · ' . e($kolicina_tekst($k['l'], $k['kg'])) : '' ?></small></span>
            </summary>
            <div class="kalk-omot">
                <table class="kalk-tabela">
                    <thead><tr><th>Artikal</th><th class="desno">Komada</th><th class="desno">Ukupno</th></tr></thead>
                    <tbody>
                        <?php foreach ($k['artikli'] as $a): ?>
                            <tr><td><?= e($a['naziv']) ?></td><td class="desno kalk-broj"><?= e(broj($a['kom'])) ?></td><td class="desno kalk-broj"><?= e($a['jedinica'] === 'kg' ? kg_tekst($a['ukupno']) : broj($a['ukupno']) . ' l') ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<?php
ui_end();
