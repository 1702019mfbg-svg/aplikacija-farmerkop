<?php
/**
 * Kalkulacija – sirovine i nabavne cene: jedinica nabavke, faktor (litara gotovog proizvoda po jedinici),
 * nova cena sa datumom (stara ostaje u istoriji) i pregled istorije cena.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

$admin = kalk_zahtevaj('Sirovine i cene');
$stranica = 'admin/kalk_sirovine.php';

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $id = post_int('id');
    $posle = $stranica . ($id > 0 ? '#sirovina-' . $id : '');

    if ($akcija === 'nova' || $akcija === 'izmeni') {
        $naziv = post_str('naziv', 100);
        $jedinica = post_str('jedinica', 20);
        $napomena = post_str('napomena', 255);
        $faktor = kalk_broj_iz(post_str('faktor', 20), 3);
        $staro = $akcija === 'izmeni' ? db_one('SELECT * FROM calc_materials WHERE id = ?', [$id]) : null;
        if ($akcija === 'izmeni' && $staro === null) {
            flash_dodaj('greska', 'Sirovina ne postoji.');
        } elseif (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv sirovine.');
        } elseif ($faktor === false || $faktor === 0.0) {
            flash_dodaj('greska', 'Faktor (litara gotovog proizvoda po jedinici) mora biti broj veći od 0, npr. 250 ili 1,5.');
        } elseif (db_val('SELECT id FROM calc_materials WHERE naziv = ? AND id <> ?', [$naziv, $id]) !== null) {
            flash_dodaj('greska', 'Sirovina „' . $naziv . '“ već postoji.');
        } elseif ($staro === null) {
            db_run('INSERT INTO calc_materials (naziv, jedinica, litara_po_jedinici, napomena, napravljen, izmenjen) VALUES (?, ?, ?, ?, ?, ?)',
                [$naziv, $jedinica ?: null, $faktor, $napomena ?: null, sada(), sada()]);
            $id = db_id();
            $posle = $stranica . '#sirovina-' . $id;
            kalk_dnevnik('nova sirovina ' . $naziv);
            flash_dodaj('uspeh', 'Dodata sirovina „' . $naziv . '“. Upišite joj cenu.');
        } else {
            $novo = [$naziv, $jedinica ?: null, $faktor === null ? null : number_format($faktor, 3, '.', ''), $napomena ?: null];
            $pre = [$staro['naziv'], $staro['jedinica'], $staro['litara_po_jedinici'], $staro['napomena']];
            if ($novo === $pre) {
                flash_dodaj('info', 'Nema izmena.');
            } else {
                db_run('UPDATE calc_materials SET naziv = ?, jedinica = ?, litara_po_jedinici = ?, napomena = ?, izmenjen = ? WHERE id = ?', [...$novo, sada(), $id]);
                $opis = [];
                if ($pre[2] !== $novo[2]) {
                    $opis[] = 'faktor ' . ($pre[2] === null ? '—' : broj((float)$pre[2], 3)) . ' → ' . ($novo[2] === null ? '—' : broj((float)$novo[2], 3)) . ' l';
                }
                if ($pre[1] !== $novo[1]) {
                    $opis[] = 'jedinica ' . ($pre[1] ?? '—') . ' → ' . ($novo[1] ?? '—');
                }
                if ($pre[0] !== $novo[0]) {
                    $opis[] = 'naziv ' . $pre[0] . ' → ' . $novo[0];
                }
                kalk_dnevnik('izmenjena sirovina ' . $naziv . ($opis ? ' (' . implode(', ', $opis) . ')' : ''));
                flash_dodaj('uspeh', 'Sačuvano: ' . $naziv . '.');
            }
        }
    } elseif ($akcija === 'cena') {
        $m = db_one('SELECT * FROM calc_materials WHERE id = ?', [$id]);
        $cena = kalk_broj_iz(post_str('cena', 20), 2);
        $datum = kalk_datum_iz(post_str('vazi_od', 10));
        if ($m === null) {
            flash_dodaj('greska', 'Sirovina ne postoji.');
        } elseif ($cena === null || $cena === false) {
            flash_dodaj('greska', 'Cena mora biti broj (0 ili veći), npr. 1.250,00.');
        } elseif ($datum === null) {
            flash_dodaj('greska', 'Izaberite datum od kog cena važi.');
        } else {
            $postoji = db_one('SELECT * FROM calc_material_prices WHERE materijal_id = ? AND vazi_od = ?', [$id, $datum]);
            if ($postoji !== null) {
                flash_dodaj('greska', 'Za ' . datum_srp($datum) . ' već postoji cena ' . kalk_rsd((float)$postoji['cena']) . ' za ' . $m['naziv']
                    . '. Izaberite drugi datum' . (kalk_moze_brisati($postoji) ? ', ili obrišite pogrešnu cenu u istoriji pa unesite ponovo' : '') . '.');
            } else {
                db_run('INSERT INTO calc_material_prices (materijal_id, cena, vazi_od, napomena, napravio_id, napravljen) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $cena, $datum, post_str('napomena', 255) ?: null, (int)$admin['id'], sada()]);
                kalk_dnevnik('cena ' . $m['naziv'] . ': ' . kalk_rsd($cena) . ' po ' . ($m['jedinica'] ?: 'jedinici') . ' od ' . datum_srp($datum));
                flash_dodaj('uspeh', 'Nova cena za ' . $m['naziv'] . ': ' . kalk_rsd($cena) . ' od ' . datum_srp($datum) . '. Ranije cene ostaju u istoriji.');
            }
        }
    } elseif ($akcija === 'obrisi_cenu') {
        $c = db_one('SELECT p.*, m.naziv FROM calc_material_prices p JOIN calc_materials m ON m.id = p.materijal_id WHERE p.id = ?', [post_int('cena_id')]);
        if ($c === null) {
            flash_dodaj('greska', 'Cena ne postoji.');
        } elseif (!kalk_moze_brisati($c)) {
            flash_dodaj('greska', 'Cena upisana ranijih dana se ne briše (istorija). Unesite novu cenu sa novim datumom.');
        } else {
            $posle = $stranica . '#sirovina-' . (int)$c['materijal_id'];
            db_run('DELETE FROM calc_material_prices WHERE id = ?', [(int)$c['id']]);
            kalk_dnevnik('obrisana pogrešno upisana cena ' . $c['naziv'] . ': ' . kalk_rsd((float)$c['cena']) . ' od ' . datum_srp((string)$c['vazi_od']));
            flash_dodaj('uspeh', 'Obrisana pogrešno upisana cena.');
        }
    }
    preusmeri($posle);
}

$danas = kalk_danas();
$sirovine = kalk_sirovine($danas);
$istorija = [];
foreach (db_all('SELECT * FROM calc_material_prices ORDER BY vazi_od DESC, id DESC') as $c) {
    $istorija[(int)$c['materijal_id']][] = $c;
}
$u_recepturi = array_column(db_all(
    'SELECT DISTINCT i.materijal_id FROM calc_recipe_items i JOIN calc_recipes r ON r.id = i.recept_id WHERE r.aktivna = 1'
), 'materijal_id', 'materijal_id');
$jedinice = ['bala', 'džak', 'kg', 'm³', 'l', 'vreća', 'tona'];

ui_start('Sirovine i cene', ['nav' => 'admin']);
kalk_meni('sirovine');
?>
<p class="pomoc">Cena je po <strong>jedinici nabavke</strong> (bala, džak, kg, m³ …). <strong>Faktor</strong> = koliko litara <strong>gotovog proizvoda</strong> daje jedna jedinica (beli treset je sabijen u bali; džak se puni posle mešanja i sleganja). Iz toga se računa cena po litru gotovog proizvoda. Nova cena se dodaje sa datumom – stare ostaju u istoriji.</p>

<?php foreach ($sirovine as $mid => $m):
    $po_litru = $m['cena'] !== null && $m['faktor'] !== null ? $m['cena'] / $m['faktor'] : null; ?>
    <section class="kartica" id="sirovina-<?= $mid ?>">
        <div class="kalk-stavka-glava">
            <h3><?= e($m['naziv']) ?></h3>
            <?php if (isset($u_recepturi[$mid])): ?><span class="znacka">u recepturi</span><?php endif; ?>
        </div>
        <div class="kalk-podaci">
            <span>Jedinica: <b><?= e($m['jedinica'] ?? '—') ?></b></span>
            <span>Faktor: <b><?= $m['faktor'] !== null ? e(broj($m['faktor'], $m['faktor'] == floor($m['faktor']) ? 0 : 3)) . ' l gotovog' : '<span class="tekst-crveno">nije upisan</span>' ?></b></span>
            <span>Cena danas: <b class="kalk-broj"><?= $m['cena'] !== null ? e(kalk_rsd($m['cena'])) : '<span class="tekst-crveno">nema cene</span>' ?></b><?= $m['cena_od'] ? ' od ' . e(datum_srp((string)$m['cena_od'])) : '' ?></span>
            <span>Po litru gotovog: <b class="kalk-broj"><?= e($po_litru === null ? '—' : broj($po_litru, 4) . ' RSD/l') ?></b></span>
        </div>
        <?php if ($m['napomena']): ?><p class="pomoc bez-margine"><?= e($m['napomena']) ?></p><?php endif; ?>

        <form method="post" class="kalk-forma" autocomplete="off" action="<?= e(url($stranica)) ?>">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="cena">
            <input type="hidden" name="id" value="<?= $mid ?>">
            <label class="mala-oznaka">Nova cena (RSD po <?= e($m['jedinica'] ?: 'jedinici') ?>)<input class="polje" name="cena" type="text" inputmode="decimal" maxlength="20" required placeholder="npr. 1.250,00"></label>
            <label class="mala-oznaka">Važi od<input class="polje" name="vazi_od" type="date" required value="<?= e($danas) ?>"></label>
            <button class="btn btn-primary" type="submit">Dodaj cenu</button>
        </form>

        <details class="kalk-istorija">
            <summary>Izmeni naziv, jedinicu, faktor</summary>
            <form method="post" class="kalk-forma" autocomplete="off" action="<?= e(url($stranica)) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="izmeni">
                <input type="hidden" name="id" value="<?= $mid ?>">
                <label class="mala-oznaka">Naziv<input class="polje" name="naziv" type="text" maxlength="100" required value="<?= e($m['naziv']) ?>"></label>
                <label class="mala-oznaka">Jedinica nabavke<input class="polje" name="jedinica" type="text" maxlength="20" list="kalk-jedinice" value="<?= e($m['jedinica'] ?? '') ?>" placeholder="bala, džak, kg…"></label>
                <label class="mala-oznaka">Litara gotovog po jedinici<input class="polje" name="faktor" type="text" inputmode="decimal" maxlength="20" value="<?= $m['faktor'] !== null ? e(broj($m['faktor'], 3)) : '' ?>" placeholder="npr. 250"></label>
                <label class="mala-oznaka">Napomena<input class="polje" name="napomena" type="text" maxlength="255" value="<?= e($m['napomena'] ?? '') ?>"></label>
                <button class="btn btn-braon" type="submit">Sačuvaj</button>
            </form>
        </details>

        <details class="kalk-istorija">
            <summary>Istorija cena (<?= count($istorija[$mid] ?? []) ?>)</summary>
            <?php if (empty($istorija[$mid])): ?>
                <p class="pomoc bez-margine">Još nema upisanih cena.</p>
            <?php else: ?>
                <ul class="lista">
                    <?php foreach ($istorija[$mid] as $c): ?>
                        <li>
                            <span>od <strong><?= e(datum_srp((string)$c['vazi_od'])) ?></strong>: <span class="kalk-broj"><?= e(kalk_rsd((float)$c['cena'])) ?></span>
                                <?= (string)$c['vazi_od'] > $danas ? '<span class="znacka">zakazano</span>' : '' ?><?= $c['napomena'] ? ' · ' . e($c['napomena']) : '' ?></span>
                            <?php if (kalk_moze_brisati($c)): ?>
                                <form method="post" class="forma-u-liniji" action="<?= e(url($stranica)) ?>" data-potvrda="Obrisati pogrešno upisanu cenu <?= e(kalk_rsd((float)$c['cena'])) ?>?">
                                    <?= csrf_polje() ?>
                                    <input type="hidden" name="akcija" value="obrisi_cenu">
                                    <input type="hidden" name="cena_id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-mali btn-opasno" type="submit">Obriši (greška)</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>
    </section>
<?php endforeach; ?>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url($stranica)) ?>" id="nova-sirovina">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="nova">
    <div class="kartica-naslov"><h2><?= ikona('plus') ?> Nova sirovina</h2></div>
    <div class="kalk-forma">
        <label class="mala-oznaka">Naziv<input class="polje" name="naziv" type="text" maxlength="100" required></label>
        <label class="mala-oznaka">Jedinica nabavke<input class="polje" name="jedinica" type="text" maxlength="20" list="kalk-jedinice" placeholder="bala, džak, kg…"></label>
        <label class="mala-oznaka">Litara gotovog po jedinici<input class="polje" name="faktor" type="text" inputmode="decimal" maxlength="20" placeholder="nije obavezno"></label>
        <label class="mala-oznaka">Napomena<input class="polje" name="napomena" type="text" maxlength="255"></label>
        <button class="btn btn-primary" type="submit">Dodaj sirovinu</button>
    </div>
</form>
<datalist id="kalk-jedinice"><?php foreach ($jedinice as $j): ?><option value="<?= e($j) ?>"><?php endforeach; ?></datalist>
<?php
ui_end();
