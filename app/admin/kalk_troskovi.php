<?php
/**
 * Kalkulacija – ambalaža i ostali troškovi (vreća, etiketa, paleta, folija, rad, struja, gorivo …),
 * po džaku ili po litru, za sve veličine ili samo za jednu (vreća 5 l ≠ vreća 50 l). Nova cena troška = novi red
 * sa datumom; stari ostaje u istoriji. Trošak koji se više ne plaća: nova cena 0.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

$admin = kalk_zahtevaj('Troškovi');
$stranica = 'admin/kalk_troskovi.php';
const KALK_TIPOVI = ['dzak' => 'po džaku', 'litar' => 'po litru'];

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $posle = $stranica;
    if ($akcija === 'trosak') {
        $naziv = post_str('naziv', 100);
        $tip = post_str('tip', 10);
        $pak = post_int('pakovanje_id');
        $iznos = kalk_broj_iz(post_str('iznos', 20), 4);
        $datum = kalk_datum_iz(post_str('vazi_od', 10));
        $pakovanja = array_column(kalk_pakovanja(), null, 'id');
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv troška (npr. Vreća, Etiketa, Rad).');
        } elseif (!isset(KALK_TIPOVI[$tip])) {
            flash_dodaj('greska', 'Izaberite da li je trošak po džaku ili po litru.');
        } elseif ($pak !== 0 && !isset($pakovanja[$pak])) {
            flash_dodaj('greska', 'Izaberite veličinu džaka (ili „sve veličine“).');
        } elseif ($iznos === null || $iznos === false) {
            flash_dodaj('greska', 'Iznos mora biti broj (0 ili veći), npr. 25,00 ili 0,35.');
        } elseif ($datum === null) {
            flash_dodaj('greska', 'Izaberite datum od kog iznos važi.');
        } else {
            $pak_id = $pak === 0 ? null : $pak;
            $postoji = db_one('SELECT * FROM calc_cost_items WHERE naziv = ? AND pakovanje_id <=> ? AND vazi_od = ?', [$naziv, $pak_id, $datum]);
            $velicina = $pak_id === null ? 'sve veličine' : kalk_pakovanje_tekst($pakovanja[$pak]);
            if ($postoji !== null) {
                flash_dodaj('greska', '„' . $naziv . '“ (' . $velicina . ') već ima iznos od ' . datum_srp($datum) . ': ' . kalk_rsd((float)$postoji['iznos'], 4)
                    . '. Izaberite drugi datum' . (kalk_moze_brisati($postoji) ? ', ili obrišite pogrešan iznos u istoriji pa unesite ponovo' : '') . '.');
            } else {
                db_run('INSERT INTO calc_cost_items (naziv, tip, iznos, vazi_od, pakovanje_id, napomena, napravio_id, napravljen) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$naziv, $tip, $iznos, $datum, $pak_id, post_str('napomena', 255) ?: null, (int)$admin['id'], sada()]);
                $opis = $naziv . ' (' . $velicina . '): ' . kalk_rsd($iznos, 4) . ' ' . KALK_TIPOVI[$tip] . ' od ' . datum_srp($datum);
                kalk_dnevnik('trošak ' . $opis);
                flash_dodaj('uspeh', 'Sačuvano: ' . $opis . '.');
            }
        }
    } elseif ($akcija === 'obrisi') {
        $c = db_one('SELECT * FROM calc_cost_items WHERE id = ?', [post_int('trosak_id')]);
        if ($c === null) {
            flash_dodaj('greska', 'Trošak ne postoji.');
        } elseif (!kalk_moze_brisati($c)) {
            flash_dodaj('greska', 'Iznos upisan ranijih dana se ne briše (istorija). Unesite novi iznos sa novim datumom (0 = trošak više ne postoji).');
        } else {
            db_run('DELETE FROM calc_cost_items WHERE id = ?', [(int)$c['id']]);
            kalk_dnevnik('obrisan pogrešno upisan trošak ' . $c['naziv'] . ': ' . kalk_rsd((float)$c['iznos'], 4) . ' od ' . datum_srp((string)$c['vazi_od']));
            flash_dodaj('uspeh', 'Obrisan pogrešno upisan iznos.');
        }
    }
    preusmeri($posle);
}

$danas = kalk_danas();
$vazeci = [];
foreach (kalk_troskovi($danas) as $t) {
    $vazeci[mb_strtolower($t['naziv']) . '|' . (int)$t['pakovanje_id']] = $t;
}
// svi troškovi (i oni koji tek počinju da važe), sa istorijom
$grupe = [];
foreach (db_all('SELECT c.*, p.kolicina AS pak_kolicina, p.jedinica AS pak_jedinica FROM calc_cost_items c LEFT JOIN pakovanja p ON p.id = c.pakovanje_id
                 ORDER BY c.naziv, p.kolicina IS NOT NULL, p.kolicina, c.vazi_od DESC, c.id DESC') as $c) {
    $k = mb_strtolower($c['naziv']) . '|' . (int)$c['pakovanje_id'];
    $grupe[$k] ??= ['naziv' => $c['naziv'], 'pakovanje_id' => (int)$c['pakovanje_id'], 'pak' => $c, 'redovi' => []];
    $grupe[$k]['redovi'][] = $c;
}
$pakovanja = kalk_pakovanja();
$nazivi = array_unique(array_column(array_column($grupe, 'pak'), 'naziv'));
$predlozi = array_unique(array_merge($nazivi, ['Vreća', 'Etiketa', 'Paleta', 'Folija', 'Rad', 'Struja', 'Gorivo']));

ui_start('Troškovi', ['nav' => 'admin']);
kalk_meni('troskovi');
?>
<p class="pomoc">Ambalaža i ostali troškovi koji se dodaju na sirovine: <strong>po džaku</strong> (vreća, etiketa, deo palete i folije) ili <strong>po litru</strong> (rad, struja, gorivo – množi se zapreminom džaka). Trošak može da važi za sve veličine ili samo za jednu. Nova cena = novi red sa datumom; trošak koji više ne postoji: upišite 0.</p>

<section class="kartica">
    <div class="kartica-naslov"><h2>Troškovi koji važe danas</h2></div>
    <?php if (!$grupe): ?>
        <p class="pomoc bez-margine">Još nema upisanih troškova. Dodajte prvi ispod (npr. Vreća 50 l – 25,00 RSD po džaku).</p>
    <?php endif; ?>
    <?php foreach ($grupe as $k => $g):
        $v = $vazeci[$k] ?? null;
        $velicina = kalk_pakovanje_tekst($g['pakovanje_id'] ? $g['pak'] : null); ?>
        <div class="kalk-stavka" id="trosak-<?= e(md5($k)) ?>">
            <div class="kalk-stavka-glava">
                <h3><?= e($g['naziv']) ?> <small class="pomoc">· <?= e($velicina) ?></small></h3>
                <span class="kalk-broj"><strong><?= $v !== null ? e(kalk_rsd($v['iznos'], $v['tip'] === 'litar' ? 4 : 2)) . ' ' . e(KALK_TIPOVI[$v['tip']]) : '<span class="pomoc">još ne važi</span>' ?></strong></span>
            </div>
            <?php if ($v !== null): ?><div class="kalk-podaci"><span>važi od <b><?= e(datum_srp((string)$v['vazi_od'])) ?></b></span><?php if ($v['napomena']): ?><span><?= e($v['napomena']) ?></span><?php endif; ?></div><?php endif; ?>
            <form method="post" class="kalk-forma" autocomplete="off" action="<?= e(url($stranica)) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="trosak">
                <input type="hidden" name="naziv" value="<?= e($g['naziv']) ?>">
                <input type="hidden" name="pakovanje_id" value="<?= (int)$g['pakovanje_id'] ?>">
                <label class="mala-oznaka">Novi iznos (RSD)<input class="polje" name="iznos" type="text" inputmode="decimal" maxlength="20" required></label>
                <label class="mala-oznaka">Obračun<select class="polje" name="tip"><?php foreach (KALK_TIPOVI as $t => $tekst): ?><option value="<?= $t ?>"<?= ($v['tip'] ?? $g['redovi'][0]['tip']) === $t ? ' selected' : '' ?>><?= e($tekst) ?></option><?php endforeach; ?></select></label>
                <label class="mala-oznaka">Važi od<input class="polje" name="vazi_od" type="date" required value="<?= e($danas) ?>"></label>
                <button class="btn btn-braon" type="submit">Nova cena</button>
            </form>
            <details class="kalk-istorija">
                <summary>Istorija (<?= count($g['redovi']) ?>)</summary>
                <ul class="lista">
                    <?php foreach ($g['redovi'] as $c): ?>
                        <li>
                            <span>od <strong><?= e(datum_srp((string)$c['vazi_od'])) ?></strong>: <span class="kalk-broj"><?= e(kalk_rsd((float)$c['iznos'], 4)) ?></span> <?= e(KALK_TIPOVI[$c['tip']]) ?>
                                <?= (string)$c['vazi_od'] > $danas ? '<span class="znacka">zakazano</span>' : '' ?></span>
                            <?php if (kalk_moze_brisati($c)): ?>
                                <form method="post" class="forma-u-liniji" action="<?= e(url($stranica)) ?>" data-potvrda="Obrisati pogrešno upisan iznos?">
                                    <?= csrf_polje() ?>
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="trosak_id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-mali btn-opasno" type="submit">Obriši (greška)</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
    <?php endforeach; ?>
</section>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url($stranica)) ?>" id="novi-trosak">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="trosak">
    <div class="kartica-naslov"><h2><?= ikona('plus') ?> Novi trošak</h2></div>
    <div class="kalk-forma">
        <label class="mala-oznaka">Naziv<input class="polje" name="naziv" type="text" maxlength="100" required list="kalk-troskovi" placeholder="npr. Vreća"></label>
        <label class="mala-oznaka">Obračun<select class="polje" name="tip"><?php foreach (KALK_TIPOVI as $t => $tekst): ?><option value="<?= $t ?>"><?= e($tekst) ?></option><?php endforeach; ?></select></label>
        <label class="mala-oznaka">Za veličinu džaka<select class="polje" name="pakovanje_id">
            <option value="0">sve veličine</option>
            <?php foreach ($pakovanja as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e(kalk_pakovanje_tekst($p)) ?></option><?php endforeach; ?>
        </select></label>
        <label class="mala-oznaka">Iznos (RSD)<input class="polje" name="iznos" type="text" inputmode="decimal" maxlength="20" required placeholder="npr. 25,00"></label>
        <label class="mala-oznaka">Važi od<input class="polje" name="vazi_od" type="date" required value="<?= e($danas) ?>"></label>
        <label class="mala-oznaka">Napomena<input class="polje" name="napomena" type="text" maxlength="255"></label>
        <button class="btn btn-primary" type="submit">Dodaj trošak</button>
    </div>
</form>
<datalist id="kalk-troskovi"><?php foreach ($predlozi as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
<?php
ui_end();
