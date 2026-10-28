<?php
/**
 * Administrator – proizvodnja po radniku: koliko je ko napravio u periodu (podrazumevano danas), po artiklu,
 * u paletama (cele palete + paketi + komadi, po broju komada na paleti iz Podešavanja) i komadima; ukupno po
 * artiklu (ko je koliko) i unosi za proveru. Isti podaci kao u Istoriji (proizvodnja, bez obrisanih) – ovde samo
 * sabrani. Izvoz u Excel (CSV). Stanje i početni ekran se ne menjaju.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';
require __DIR__ . '/../inc/spiskovi.php';

zahtevaj_ulogu('admin');

[$od, $do] = period_iz_adrese('Danas');
$period = ['od' => $od, 'do' => $do];

$unosi = db_all(
    'SELECT u.id, u.korisnik_id, k.ime, u.kolicina, u.palete, u.paketi, u.nastalo, ' . SKU_POLJA . '
     FROM unosi u JOIN korisnici k ON k.id = u.korisnik_id ' . SKU_SPOJ . "
     WHERE u.tip = 'proizvodnja' AND u.obrisan = 0 AND u.nastalo >= ? AND u.nastalo < ? ORDER BY u.nastalo, u.id",
    period_granice($od, $do)
);

// sabrano po radniku i artiklu (artikli redom kao u katalogu)
$redosled = array_flip(array_column(sku_za_unos(false, true), 'sku_id'));
$po_katalogu = static fn(int $a, int $b): int => [$redosled[$a] ?? PHP_INT_MAX, $a] <=> [$redosled[$b] ?? PHP_INT_MAX, $b];
$radnici = [];
$artikli = [];
foreach ($unosi as $u) {
    $sid = (int)$u['sku_id'];
    $kid = (int)$u['korisnik_id'];
    $kom = (int)$u['kolicina'];
    $r = &$radnici[$kid];
    $r ??= ['ime' => (string)$u['ime'], 'kom' => 0, 'artikli' => [], 'unosi' => []];
    $r['kom'] += $kom;
    $r['artikli'][$sid] ??= ['sku' => $u, 'kom' => 0];
    $r['artikli'][$sid]['kom'] += $kom;
    $r['unosi'][] = $u;
    unset($r);
    $artikli[$sid] ??= ['sku' => $u, 'kom' => 0, 'radnici' => []];
    $artikli[$sid]['kom'] += $kom;
    $artikli[$sid]['radnici'][$kid] = ($artikli[$sid]['radnici'][$kid] ?? 0) + $kom;
}
uksort($artikli, $po_katalogu);

/** Palete za listu [['sku', 'kom'], …]: zbir komada / komada po paleti (artikli bez palete se ne broje). */
$palete = static fn(array $lista): float => array_sum(array_map(
    static fn(array $x): float => (int)$x['sku']['po_paleti'] > 0 ? $x['kom'] / (int)$x['sku']['po_paleti'] : 0.0,
    $lista
));
/** Komada artikala kojima nije podešeno koliko ide na paletu. */
$bez_palete = static fn(array $lista): int => array_sum(array_map(static fn(array $x): int => (int)$x['sku']['po_paleti'] > 0 ? 0 : $x['kom'], $lista));
/** "3 pal", "2 pal + 3 pak + 4 kom" ili "35 kom" (manje od palete i paketa). */
$razlozeno = static fn(int $kom, array $s): string => razlaganje($kom, (int)$s['po_paleti'], (int)$s['po_paketu']) ?: broj($kom) . ' kom';

foreach ($radnici as &$r) {
    uksort($r['artikli'], $po_katalogu);
    $r['pal'] = $palete($r['artikli']);
}
unset($r);
uasort($radnici, static fn(array $a, array $b): int => [$b['pal'], $b['kom'], $a['ime']] <=> [$a['pal'], $a['kom'], $b['ime']]);

if (get_int('csv') === 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="farmerkop-proizvodnja-' . $od . '-' . $do . '.csv"');
    $izlaz = fopen('php://output', 'w');
    fwrite($izlaz, "\xEF\xBB\xBF");
    fwrite($izlaz, implode(';', array_map('csv_tekst', ['Od', 'Do', 'Radnik', 'Artikal', 'Boja / granulacija', 'Pakovanje', 'Komada', 'Paleta', 'Palete, paketi, komadi'])) . "\r\n");
    foreach ($radnici as $r) {
        foreach ($r['artikli'] as $a) {
            $po = (int)$a['sku']['po_paleti'];
            fwrite($izlaz, implode(';', [datum_srp($od), datum_srp($do), csv_tekst($r['ime']), csv_tekst($a['sku']['artikal']), csv_tekst((string)($a['sku']['varijanta'] ?? '')),
                csv_tekst(pakovanje_naziv($a['sku']['pak_kolicina'], (string)$a['sku']['jedinica'])), (string)$a['kom'],
                $po > 0 ? str_replace('.', ',', (string)round($a['kom'] / $po, 2)) : '', csv_tekst($razlozeno($a['kom'], $a['sku']))]) . "\r\n");
        }
    }
    fclose($izlaz);
    exit;
}

$ukupno_kom = array_sum(array_column($artikli, 'kom'));
$ukupno_pal = $palete($artikli);
$ukupno_bez = $bez_palete($artikli);
$danas = [$od, $do] === period_opsezi()['Danas'];

ui_start('Proizvodnja po radniku', ['nav' => 'admin', 'aktivno' => 'radnici']);
?>
<?= period_izbor_html('admin/proizvodnja.php', $od, $do) ?>

<section class="kartica" aria-labelledby="naslov-ukupno">
    <div class="kartica-naslov"><h2 id="naslov-ukupno"><?= $danas ? 'Danas' : 'Ukupno' ?>: <?= e(palete_zbir_tekst($ukupno_pal)) ?></h2></div>
    <p class="pomoc bez-margine">
        <?= e(period_opis($od, $do)) ?> · <?= e(broj($ukupno_kom)) ?> kom<?= $radnici ? ' · ' . count($radnici) . (count($radnici) === 1 ? ' radnik' : ' radnika') : '' ?><?= $ukupno_bez > 0 ? ' · ' . e(broj($ukupno_bez)) . ' kom artikala bez podešene palete' : '' ?>
    </p>
    <p class="pomoc bez-margine razmak-gore">Palete se računaju iz komada (koliko ide na paletu – Podešavanja → Artikli), pa je isto bilo da je radnik upisao palete, pakete ili komade.</p>
</section>

<?php if (!$unosi): ?>
    <div class="kartica"><p class="bez-margine"><?= $danas ? 'Danas još nema proizvodnje.' : 'U ovom periodu nema proizvodnje.' ?></p></div>
<?php else: ?>
    <p class="grupa-dugmica"><a class="btn btn-braon btn-mali" href="<?= e(url('admin/proizvodnja.php?' . http_build_query($period + ['csv' => 1]))) ?>"><?= ikona('preuzmi') ?> Izvoz u Excel (CSV)</a></p>

    <?php foreach ($radnici as $kid => $r): ?>
        <details class="kartica proizvodnja-radnik" data-radnik="<?= (int)$kid ?>" open>
            <summary class="kartica-naslov">
                <span><strong><?= e($r['ime']) ?></strong> – <?= e(palete_zbir_tekst($r['pal'])) ?><br><small class="pomoc"><?= e(broj($r['kom'])) ?> kom · <?= count($r['unosi']) ?> <?= count($r['unosi']) === 1 ? 'unos' : 'unosa' ?></small></span>
            </summary>
            <div class="kalk-omot">
                <table class="kalk-tabela">
                    <thead><tr><th>Artikal</th><th class="desno">Palete</th><th class="desno">Komada</th></tr></thead>
                    <tbody>
                        <?php foreach ($r['artikli'] as $a): ?>
                            <tr>
                                <td><?= e(sku_naziv($a['sku'])) ?></td>
                                <td class="desno kalk-broj"><strong><?= e($razlozeno($a['kom'], $a['sku'])) ?></strong></td>
                                <td class="desno kalk-broj"><?= e(broj($a['kom'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <details class="razmak-gore">
                <summary class="pomoc">Unosi (<?= count($r['unosi']) ?>) – kako je upisano</summary>
                <ul class="lista">
                    <?php foreach (array_reverse($r['unosi']) as $u): ?>
                        <li>
                            <div class="unos-red">
                                <span class="naziv"><?= e(sku_naziv($u)) ?></span>
                                <span class="kol"><?= e(kolicina_tekst((int)$u['kolicina'], $u['palete'] === null ? null : (int)$u['palete'], $u['paketi'] === null ? null : (int)$u['paketi'])) ?></span>
                                <span class="pod"><?= e(datum_kratko((string)$u['nastalo'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="bez-margine"><a class="btn btn-mali" href="<?= e(url('admin/istorija.php?' . http_build_query(['tip' => 'proizvodnja', 'radnik' => (int)$kid, 'od' => $od, 'do' => $do]))) ?>"><?= ikona('istorija') ?> U Istoriji (ispravka i brisanje)</a></p>
            </details>
        </details>
    <?php endforeach; ?>

    <section class="kartica" aria-labelledby="naslov-artikli">
        <div class="kartica-naslov"><h2 id="naslov-artikli">Po artiklu (svi radnici)</h2></div>
        <div class="kalk-omot">
            <table class="kalk-tabela">
                <thead><tr><th>Artikal</th><th class="desno">Palete</th><th class="desno">Komada</th></tr></thead>
                <tbody>
                    <?php foreach ($artikli as $a): ?>
                        <tr>
                            <td><?= e(sku_naziv($a['sku'])) ?>
                                <br><small class="pomoc"><?= e(implode(' · ', array_map(static fn(int $kid, int $kom): string => $radnici[$kid]['ime'] . ' ' . $razlozeno($kom, $a['sku']), array_keys($a['radnici']), $a['radnici']))) ?></small>
                            </td>
                            <td class="desno kalk-broj"><strong><?= e($razlozeno($a['kom'], $a['sku'])) ?></strong></td>
                            <td class="desno kalk-broj"><?= e(broj($a['kom'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php
ui_end();
