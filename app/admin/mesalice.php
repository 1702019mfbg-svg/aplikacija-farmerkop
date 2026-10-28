<?php
/**
 * Administrator – mešalice malča: koliko je koji radnik ofarbao u izabranom periodu, po boji i po danima.
 * Norma se ne postavlja – ovo je samo pregled rada. Pogrešan unos se briše (zapis ostaje u dnevniku izmena).
 * Izvoz u Excel (CSV). Stanje se mešalicama ne menja.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';
require __DIR__ . '/../inc/mesalice.php';

$admin = zahtevaj_ulogu('admin');

[$od, $do] = period_iz_adrese('Ova nedelja');
$period = ['od' => $od, 'do' => $do];
$ovde = 'admin/mesalice.php?' . http_build_query($period);

if (je_post()) {
    csrf_proveri();
    if (post_str('akcija', 20) === 'obrisi') {
        $rez = mesalica_obrisi(post_int('id'), (int)$admin['id']);
        flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos mešalica je obrisan.' : (string)$rez['greska']);
    }
    preusmeri($ovde . '#unosi');
}

$unosi = db_all(
    'SELECT m.id, m.korisnik_id, k.ime, m.boja, m.broj, m.nastalo FROM mesalice m JOIN korisnici k ON k.id = m.korisnik_id
     WHERE m.obrisan = 0 AND m.nastalo >= ? AND m.nastalo < ? ORDER BY m.nastalo DESC, m.id DESC',
    period_granice($od, $do)
);

if (get_int('csv') === 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="farmerkop-mesalice-' . $od . '-' . $do . '.csv"');
    $izlaz = fopen('php://output', 'w');
    fwrite($izlaz, "\xEF\xBB\xBF");
    fwrite($izlaz, implode(';', array_map('csv_tekst', ['Datum', 'Vreme', 'Radnik', 'Boja', 'Mešalica'])) . "\r\n");
    foreach (array_reverse($unosi) as $u) {
        fwrite($izlaz, implode(';', [date('d.m.Y', (int)strtotime((string)$u['nastalo'])), vreme_srp((string)$u['nastalo']),
            csv_tekst($u['ime']), csv_tekst($u['boja']), (string)(int)$u['broj']]) . "\r\n");
    }
    fclose($izlaz);
    exit;
}

// po radniku: ukupno, po boji i po danima (najnoviji dan prvi)
$ukupno = 0;
$radnici = [];
foreach ($unosi as $u) {
    $n = (int)$u['broj'];
    $ukupno += $n;
    $r = &$radnici[(int)$u['korisnik_id']];
    $r ??= ['ime' => (string)$u['ime'], 'ukupno' => 0, 'unosi' => [], 'dani' => []];
    $r['ukupno'] += $n;
    $r['unosi'][] = $u;
    $r['dani'][substr((string)$u['nastalo'], 0, 10)][] = $u;
    unset($r);
}
uasort($radnici, static fn(array $a, array $b): int => [$b['ukupno'], $a['ime']] <=> [$a['ukupno'], $b['ime']]);
$po_boji = mesalice_po_boji(array_reverse($unosi));
arsort($po_boji);

ui_start('Mešalice', ['nav' => 'admin', 'aktivno' => 'radnici']);
?>
<?= period_izbor_html('admin/mesalice.php', $od, $do) ?>

<section class="kartica" aria-labelledby="naslov-ukupno">
    <div class="kartica-naslov"><h2 id="naslov-ukupno">Ukupno: <?= e(mesalice_tekst($ukupno)) ?></h2></div>
    <p class="pomoc bez-margine"><?= e(period_opis($od, $do)) ?><?= $po_boji ? ' · ' . e(mesalice_boje_tekst($po_boji)) : '' ?></p>
    <p class="pomoc bez-margine razmak-gore">Mešalice upisuju radnici (meni Mešalice). Ovo je samo pregled rada – stanje se ne menja, vreće se upisuju u Proizvodnji.</p>
</section>

<?php if (!$unosi): ?>
    <div class="kartica"><p class="bez-margine">U ovom periodu nema upisanih mešalica.</p></div>
<?php else: ?>
    <p class="grupa-dugmica"><a class="btn btn-braon btn-mali" href="<?= e(url('admin/mesalice.php?' . http_build_query($period + ['csv' => 1]))) ?>"><?= ikona('preuzmi') ?> Izvoz u Excel (CSV)</a></p>
    <?php foreach ($radnici as $kid => $r): ?>
        <details class="kartica mesalice-radnik" data-radnik="<?= (int)$kid ?>"<?= count($radnici) === 1 ? ' open' : '' ?>>
            <summary class="kartica-naslov">
                <span><strong><?= e($r['ime']) ?></strong> – <?= e(mesalice_tekst($r['ukupno'])) ?><br><small class="pomoc"><?= e(mesalice_boje_tekst(mesalice_po_boji(array_reverse($r['unosi'])))) ?></small></span>
            </summary>
            <div class="kalk-omot">
                <table class="kalk-tabela">
                    <thead><tr><th>Dan</th><th class="desno">Mešalica</th><th>Boje</th></tr></thead>
                    <tbody>
                        <?php foreach ($r['dani'] as $dan => $u_danu): ?>
                            <tr>
                                <td><?= e(dan_u_nedelji($dan)) ?>, <?= e(datum_srp($dan)) ?></td>
                                <td class="desno kalk-broj"><?= e(broj(array_sum(array_map(static fn(array $u): int => (int)$u['broj'], $u_danu)))) ?></td>
                                <td><?= e(mesalice_boje_tekst(mesalice_po_boji(array_reverse($u_danu)))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>

    <details class="kartica" id="unosi">
        <summary class="kartica-naslov"><h2>Svi unosi (<?= count($unosi) ?>)</h2></summary>
        <p class="pomoc">Pogrešan unos obrišite – brisanje ostaje zapisano u dnevniku izmena.</p>
        <ul class="lista">
            <?php foreach ($unosi as $u): ?>
                <li>
                    <div class="unos-red">
                        <span class="naziv"><?= e($u['boja']) ?></span>
                        <span class="kol"><?= e(mesalice_tekst((int)$u['broj'])) ?></span>
                        <span class="pod"><?= e($u['ime']) ?> · <?= e(datum_kratko((string)$u['nastalo'])) ?></span>
                        <div class="akcije">
                            <form method="post" action="<?= e(url($ovde)) ?>" class="forma-u-liniji"
                                  data-potvrda="Obrisati unos: <?= e($u['ime']) ?>, <?= e($u['boja']) ?>, <?= e(mesalice_tekst((int)$u['broj'])) ?>?">
                                <?= csrf_polje() ?>
                                <input type="hidden" name="akcija" value="obrisi">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-opasno btn-mali" aria-label="Obriši unos: <?= e($u['ime']) ?>, <?= e($u['boja']) ?>, <?= e(mesalice_tekst((int)$u['broj'])) ?>"><?= ikona('kanta') ?> Obriši</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>
<?php
ui_end();
