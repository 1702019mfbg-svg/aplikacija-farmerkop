<?php
/**
 * Kalkulacija – prodajne cene po proizvodu i veličini džaka (sa ili bez PDV-a; marže se računaju bez PDV-a).
 * Nova cena = novi red sa datumom; stara ostaje u istoriji.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

$admin = kalk_zahtevaj('Prodajne cene');
$stranica = 'admin/kalk_cene.php';

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $sku_id = post_int('sku_id');
    $posle = $stranica . ($sku_id > 0 ? '#sku-' . $sku_id : '');
    if ($akcija === 'cena') {
        $sku = db_one('SELECT s.id, s.pakovanje_id FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id WHERE s.id = ? AND p.jedinica = ?', [$sku_id, 'l']);
        $cena = kalk_broj_iz(post_str('cena', 20), 2);
        $datum = kalk_datum_iz(post_str('vazi_od', 10));
        $sa_pdv = post_str('sa_pdv', 1) === '1';
        $stopa = kalk_podesavanje('pdv_stopa', 20);
        $podaci = $sku !== null ? sku_podaci($sku_id) : null;
        if ($podaci === null) {
            flash_dodaj('greska', 'Proizvod ne postoji.');
        } elseif ($cena === null || $cena === false) {
            flash_dodaj('greska', 'Cena mora biti broj (0 ili veći), npr. 1.250,00.');
        } elseif ($datum === null) {
            flash_dodaj('greska', 'Izaberite datum od kog cena važi.');
        } else {
            $postoji = db_one('SELECT * FROM calc_selling_prices WHERE sku_id = ? AND vazi_od = ?', [$sku_id, $datum]);
            if ($postoji !== null) {
                flash_dodaj('greska', sku_naziv($podaci) . ' već ima cenu od ' . datum_srp($datum) . ': ' . kalk_rsd((float)$postoji['cena'])
                    . '. Izaberite drugi datum' . (kalk_moze_brisati($postoji) ? ', ili obrišite pogrešnu cenu u istoriji pa unesite ponovo' : '') . '.');
            } else {
                db_run('INSERT INTO calc_selling_prices (sku_id, cena, sa_pdv, pdv_stopa, vazi_od, napravio_id, napravljen) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$sku_id, $cena, $sa_pdv ? 1 : 0, $stopa, $datum, (int)$admin['id'], sada()]);
                $opis = sku_naziv($podaci) . ': ' . kalk_rsd($cena) . ($sa_pdv ? ' sa PDV-om' : ' bez PDV-a') . ' od ' . datum_srp($datum);
                kalk_dnevnik('prodajna cena ' . $opis);
                flash_dodaj('uspeh', 'Nova prodajna cena – ' . $opis . '.');
            }
        }
    } elseif ($akcija === 'obrisi') {
        $c = db_one('SELECT * FROM calc_selling_prices WHERE id = ?', [post_int('cena_id')]);
        if ($c === null) {
            flash_dodaj('greska', 'Cena ne postoji.');
        } elseif (!kalk_moze_brisati($c)) {
            flash_dodaj('greska', 'Cena upisana ranijih dana se ne briše (istorija). Unesite novu cenu sa novim datumom.');
        } else {
            $posle = $stranica . '#sku-' . (int)$c['sku_id'];
            db_run('DELETE FROM calc_selling_prices WHERE id = ?', [(int)$c['id']]);
            kalk_dnevnik('obrisana pogrešno upisana prodajna cena ' . sku_naziv((array)sku_podaci((int)$c['sku_id'])) . ': ' . kalk_rsd((float)$c['cena']));
            flash_dodaj('uspeh', 'Obrisana pogrešno upisana cena.');
        }
    }
    preusmeri($posle);
}

$danas = kalk_danas();
$stopa = kalk_podesavanje('pdv_stopa', 20);
$vazece = kalk_prodajne($danas);
$istorija = [];
foreach (db_all('SELECT * FROM calc_selling_prices ORDER BY vazi_od DESC, id DESC') as $c) {
    $istorija[(int)$c['sku_id']][] = $c;
}
$po_artiklu = [];
foreach (kalk_proizvodi() as $p) {
    $po_artiklu[(int)$p['artikal_id']]['naziv'] = $p['artikal'];
    $po_artiklu[(int)$p['artikal_id']]['sku'][] = $p;
}

ui_start('Prodajne cene', ['nav' => 'admin']);
kalk_meni('cene');
?>
<p class="pomoc">Upišite cenu po kojoj prodajete džak – <strong>sa ili bez PDV-a</strong>, kako vam je lakše; marža se uvek računa <strong>bez PDV-a</strong> (stopa za nove cene: <?= e(broj($stopa, $stopa == floor($stopa) ? 0 : 2)) ?> %, menja se na Pregledu). Nova cena se dodaje sa datumom – stare ostaju u istoriji.</p>

<?php foreach ($po_artiklu as $aid => $a): ?>
    <section class="kartica">
        <div class="kartica-naslov"><h2><?= e($a['naziv']) ?></h2></div>
        <?php foreach ($a['sku'] as $p):
            $sid = (int)$p['sku_id'];
            $v = $vazece[$sid] ?? null; ?>
            <div class="kalk-stavka" id="sku-<?= $sid ?>">
                <div class="kalk-stavka-glava">
                    <h3><?= e(($p['varijanta'] !== null ? $p['varijanta'] . ' · ' : '') . pakovanje_naziv($p['pak_kolicina'], 'l')) ?></h3>
                    <span class="kalk-broj"><?php if ($v !== null): ?><strong><?= e(kalk_rsd((float)$v['cena'])) ?></strong> <?= (int)$v['sa_pdv'] === 1 ? 'sa PDV-om' : 'bez PDV-a' ?><?php else: ?><span class="pomoc">nema cene</span><?php endif; ?></span>
                </div>
                <?php if ($v !== null): ?>
                    <div class="kalk-podaci"><span>od <b><?= e(datum_srp((string)$v['vazi_od'])) ?></b></span><span>bez PDV-a: <b class="kalk-broj"><?= e(kalk_rsd($v['bez_pdv'])) ?></b></span><?php if ((int)$v['sa_pdv'] === 1): ?><span>PDV <?= e(broj((float)$v['pdv_stopa'], 0)) ?> %</span><?php endif; ?></div>
                <?php endif; ?>
                <form method="post" class="kalk-forma" autocomplete="off" action="<?= e(url($stranica)) ?>">
                    <?= csrf_polje() ?>
                    <input type="hidden" name="akcija" value="cena">
                    <input type="hidden" name="sku_id" value="<?= $sid ?>">
                    <label class="mala-oznaka">Nova cena (RSD)<input class="polje" name="cena" type="text" inputmode="decimal" maxlength="20" required></label>
                    <label class="mala-oznaka">Cena je<select class="polje" name="sa_pdv">
                        <option value="1"<?= $v === null || (int)$v['sa_pdv'] === 1 ? ' selected' : '' ?>>sa PDV-om</option>
                        <option value="0"<?= $v !== null && (int)$v['sa_pdv'] === 0 ? ' selected' : '' ?>>bez PDV-a</option>
                    </select></label>
                    <label class="mala-oznaka">Važi od<input class="polje" name="vazi_od" type="date" required value="<?= e($danas) ?>"></label>
                    <button class="btn btn-braon" type="submit">Dodaj cenu</button>
                </form>
                <?php if (!empty($istorija[$sid])): ?>
                    <details class="kalk-istorija">
                        <summary>Istorija (<?= count($istorija[$sid]) ?>)</summary>
                        <ul class="lista">
                            <?php foreach ($istorija[$sid] as $c): ?>
                                <li>
                                    <span>od <strong><?= e(datum_srp((string)$c['vazi_od'])) ?></strong>: <span class="kalk-broj"><?= e(kalk_rsd((float)$c['cena'])) ?></span> <?= (int)$c['sa_pdv'] === 1 ? 'sa PDV-om' : 'bez PDV-a' ?>
                                        <?= (string)$c['vazi_od'] > $danas ? '<span class="znacka">zakazano</span>' : '' ?></span>
                                    <?php if (kalk_moze_brisati($c)): ?>
                                        <form method="post" class="forma-u-liniji" action="<?= e(url($stranica)) ?>" data-potvrda="Obrisati pogrešno upisanu cenu?">
                                            <?= csrf_polje() ?>
                                            <input type="hidden" name="akcija" value="obrisi">
                                            <input type="hidden" name="cena_id" value="<?= (int)$c['id'] ?>">
                                            <button class="btn btn-mali btn-opasno" type="submit">Obriši (greška)</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
<?php
ui_end();
