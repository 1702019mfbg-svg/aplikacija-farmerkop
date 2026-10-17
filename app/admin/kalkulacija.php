<?php
/**
 * Kalkulacija – pregled: proizvod × veličina džaka sa troškom sirovina, ostalim troškovima, ukupnim troškom,
 * prodajnom cenom (bez PDV-a) i maržom (RSD i %), po cenama koje važe danas. Dodir na red otvara razradu po
 * sirovinama. Marža ispod praga je crvena. Ovde su i podešavanja modula (stopa PDV-a, prag marže).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

kalk_zahtevaj('Kalkulacija');
$stranica = 'admin/kalkulacija.php';

if (je_post()) {
    csrf_proveri();
    if (post_str('akcija', 20) === 'podesavanja') {
        $pdv = kalk_broj_iz(post_str('pdv_stopa', 10), 2);
        $prag = kalk_broj_iz(post_str('prag_marze', 10), 1);
        if ($pdv === null || $pdv === false || $pdv >= 100) {
            flash_dodaj('greska', 'Stopa PDV-a mora biti broj od 0 do 99 (npr. 20).');
        } elseif ($prag === null || $prag === false || $prag >= 100) {
            flash_dodaj('greska', 'Prag marže mora biti broj od 0 do 99 (npr. 15).');
        } else {
            kalk_podesi('pdv_stopa', (string)$pdv);
            kalk_podesi('prag_marze', (string)$prag);
            kalk_dnevnik('podešavanja: PDV ' . broj($pdv, 2) . ' %, prag marže ' . broj($prag, 1) . ' %');
            flash_dodaj('uspeh', 'Sačuvano: stopa PDV-a za nove cene ' . broj($pdv, 2) . ' %, marža ispod ' . broj($prag, 1) . ' % je crvena.');
        }
    }
    preusmeri($stranica);
}

$danas = kalk_danas();
$prag = kalk_podesavanje('prag_marze', 0);
$pdv = kalk_podesavanje('pdv_stopa', 20);
$pregled = kalk_pregled($danas);

/** Brojke jednog reda pregleda (i u zaglavlju tabele na širem ekranu). */
$kolone = ['Sirovine', 'Ostali troškovi', 'Ukupan trošak', 'Prodajna bez PDV', 'Marža RSD'];

ui_start('Kalkulacija', ['nav' => 'admin']);
kalk_meni('pregled');
?>
<p class="pomoc">Cena jednog džaka po cenama koje važe <strong>danas (<?= e(datum_srp($danas)) ?>)</strong>: sirovine po važećoj recepturi + ambalaža i ostali troškovi, prema prodajnoj ceni bez PDV-a. Marža u % je od prodajne cene bez PDV-a. Dodirnite red za razradu po sirovinama.</p>

<?php if (!$pregled): ?>
    <div class="kartica">
        <p>Još nema šta da se izračuna. Redom:</p>
        <ol class="bez-margine">
            <li><a href="<?= e(url('admin/kalk_sirovine.php')) ?>">Sirovine i cene</a> – cena i faktor za svaku sirovinu,</li>
            <li><a href="<?= e(url('admin/kalk_troskovi.php')) ?>">Troškovi</a> – vreća, etiketa, rad …,</li>
            <li><a href="<?= e(url('admin/kalk_recepture.php')) ?>">Recepture</a> – od čega je koji proizvod,</li>
            <li><a href="<?= e(url('admin/kalk_cene.php')) ?>">Prodajne cene</a>.</li>
        </ol>
    </div>
<?php endif; ?>

<?php foreach ($pregled as $aid => $g): ?>
    <section class="kartica" aria-labelledby="kalk-a-<?= (int)$aid ?>">
        <div class="kalk-stavka-glava">
            <h2 id="kalk-a-<?= (int)$aid ?>"><?= e($g['artikal']) ?></h2>
            <span class="pomoc"><?= $g['recept'] !== null ? 'receptura: ' . e($g['recept']['naziv']) : '<span class="tekst-crveno">nema recepture</span>' ?></span>
        </div>
        <div class="kalk-zaglavlje-tabele" aria-hidden="true"><span>Džak</span><?php foreach ($kolone as $k): ?><span><?= e($k) ?></span><?php endforeach; ?><span>Marža %</span></div>
        <?php foreach ($g['redovi'] as $r):
            $t = $r['trosak'];
            $m = $r['marza'];
            $klasa = kalk_marza_klasa($m, $prag); ?>
            <details class="kalk-red" data-sku="<?= (int)$r['sku_id'] ?>">
                <summary>
                    <span class="kalk-red-ime"><?= e($r['naziv']) ?></span>
                    <span class="kalk-red-brojke">
                        <span><span class="kalk-oznaka">Sirovine: </span><b class="kalk-broj"><?= e(kalk_rsd($t['sirovine'])) ?></b></span>
                        <span><span class="kalk-oznaka">Ostalo: </span><b class="kalk-broj"><?= e(kalk_rsd($t['ostalo'])) ?></b></span>
                        <span><span class="kalk-oznaka">Ukupno: </span><b class="kalk-broj"><?= e(kalk_rsd($t['ukupno'])) ?></b></span>
                        <span><span class="kalk-oznaka">Cena bez PDV: </span><b class="kalk-broj"><?= e(kalk_rsd($r['prodajna']['bez_pdv'] ?? null)) ?></b></span>
                        <span><span class="kalk-oznaka">Marža: </span><b class="kalk-broj <?= $klasa ?>"><?= e(kalk_rsd($m['rsd'] ?? null)) ?></b></span>
                    </span>
                    <span class="kalk-red-marza kalk-broj <?= $klasa ?>" data-marza><?= e(kalk_pct($m['pct'] ?? null)) ?></span>
                    <?php if ($t['nedostaje'] || $r['prodajna'] === null): ?>
                        <span class="kalk-nedostaje kalk-cela-sirina"><?= e(implode('; ', array_merge($t['nedostaje'], $r['prodajna'] === null ? ['nema prodajne cene'] : []))) ?></span>
                    <?php endif; ?>
                </summary>
                <div class="kalk-razrada">
                    <?php if ($t['razrada']): ?>
                        <h4>Sirovine za <?= e(broj($r['V'], $r['V'] == floor($r['V']) ? 0 : 2)) ?> l</h4>
                        <div class="kalk-omot">
                            <table class="kalk-tabela">
                                <thead><tr><th>Sirovina</th><th class="desno">Udeo</th><th class="desno">Litara</th><th class="desno">RSD/l</th><th class="desno">Iznos</th></tr></thead>
                                <tbody>
                                    <?php foreach ($t['razrada'] as $s): ?>
                                        <tr>
                                            <td><?= e($s['naziv']) ?></td>
                                            <td class="desno kalk-broj"><?= e(kalk_udeo_tekst($s['udeo'])) ?></td>
                                            <td class="desno kalk-broj"><?= e(broj($s['litara'], 2)) ?></td>
                                            <td class="desno kalk-broj"><?= $s['po_litru'] === null ? '<span class="tekst-crveno">nedostaje</span>' : e(broj($s['po_litru'], 4)) ?></td>
                                            <td class="desno kalk-broj"><?= e(kalk_rsd($s['iznos'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr><td colspan="4">Ukupno sirovine</td><td class="desno kalk-broj"><?= e(kalk_rsd($t['sirovine'])) ?></td></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                    <h4>Ambalaža i ostali troškovi</h4>
                    <?php if (!$t['razrada_ostalo']): ?>
                        <p class="pomoc bez-margine">Nema upisanih troškova za ovu veličinu (<a href="<?= e(url('admin/kalk_troskovi.php')) ?>">Troškovi</a>).</p>
                    <?php else: ?>
                        <div class="kalk-omot">
                            <table class="kalk-tabela">
                                <tbody>
                                    <?php foreach ($t['razrada_ostalo'] as $o): ?>
                                        <tr>
                                            <td><?= e($o['naziv']) ?></td>
                                            <td class="desno kalk-broj"><?= $o['tip'] === 'litar' ? e(broj($o['jedinicno'], 4)) . ' RSD/l × ' . e(broj($r['V'], 0)) . ' l' : 'po džaku' ?></td>
                                            <td class="desno kalk-broj"><?= e(kalk_rsd($o['iznos'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr><td colspan="2">Ukupno ostalo</td><td class="desno kalk-broj"><?= e(kalk_rsd($t['ostalo'])) ?></td></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($r['prodajna'] !== null): $p = $r['prodajna']; ?>
                        <p class="pomoc bez-margine razmak-gore">Prodajna cena: <?= e(kalk_rsd((float)$p['cena'])) ?> <?= (int)$p['sa_pdv'] === 1 ? 'sa PDV-om ' . e(broj((float)$p['pdv_stopa'], 0)) . ' % = ' . e(kalk_rsd($p['bez_pdv'])) . ' bez PDV-a' : 'bez PDV-a' ?>, važi od <?= e(datum_srp((string)$p['vazi_od'])) ?></p>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>

<details class="kartica">
    <summary class="kartica-naslov"><h2>Podešavanja kalkulacije</h2></summary>
    <form method="post" class="kalk-forma" autocomplete="off" action="<?= e(url($stranica)) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="podesavanja">
        <label class="mala-oznaka">Stopa PDV-a za nove cene (%)<input class="polje" name="pdv_stopa" type="text" inputmode="decimal" maxlength="6" value="<?= e(broj($pdv, $pdv == floor($pdv) ? 0 : 2)) ?>"></label>
        <label class="mala-oznaka">Marža je crvena ispod (%)<input class="polje" name="prag_marze" type="text" inputmode="decimal" maxlength="6" value="<?= e(broj($prag, $prag == floor($prag) ? 0 : 1)) ?>"></label>
        <button class="btn btn-braon" type="submit">Sačuvaj</button>
    </form>
    <p class="pomoc bez-margine razmak-gore">Stopa PDV-a se pamti uz svaku upisanu prodajnu cenu; promena važi za cene koje upišete posle nje.</p>
</details>
<?php
ui_end();
