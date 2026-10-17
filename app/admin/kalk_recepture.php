<?php
/**
 * Kalkulacija – recepture: od kojih sirovina je proizvod (udeo u % zapremine gotovog proizvoda, zbir tačno 100 %).
 * Izmena važeće recepture pravi NOVU verziju (sa datumom od kog važi); stara ostaje u istoriji.
 * Verzija se može isključiti (aktivna: ne) – npr. kad je upisana greškom.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

$admin = kalk_zahtevaj('Recepture');
$stranica = 'admin/kalk_recepture.php';

/** Redovi recepture iz forme (sirovina + udeo). Vraća [[materijal_id => udeo], greška|null]. */
function recept_iz_posta(array $sirovine): array
{
    $m = is_array($_POST['m'] ?? null) ? array_values($_POST['m']) : [];
    $u = is_array($_POST['u'] ?? null) ? array_values($_POST['u']) : [];
    $redovi = [];
    foreach ($m as $i => $mid) {
        $mid = is_string($mid) && ctype_digit($mid) ? (int)$mid : 0;
        $tekst = is_string($u[$i] ?? null) ? trim($u[$i]) : '';
        if ($mid === 0 && $tekst === '') {
            continue;
        }
        if (!isset($sirovine[$mid])) {
            return [[], 'Red ' . ($i + 1) . ': izaberite sirovinu.'];
        }
        $udeo = kalk_broj_iz($tekst, 3);
        if ($udeo === null || $udeo === false || $udeo <= 0 || $udeo > 100) {
            return [[], $sirovine[$mid]['naziv'] . ': udeo mora biti broj veći od 0 i najviše 100 (do 3 decimale, npr. 60 ili 0,125).'];
        }
        if (isset($redovi[$mid])) {
            return [[], $sirovine[$mid]['naziv'] . ' je upisana dva puta.'];
        }
        $redovi[$mid] = $udeo;
    }
    if (!$redovi) {
        return [[], 'Upišite bar jednu sirovinu i njen udeo.'];
    }
    if (!kalk_zbir_ok($redovi)) {
        $zbir = kalk_zbir_udela($redovi) / 1000;
        return [[], 'Zbir udela je ' . broj($zbir, 3) . ' %, a mora biti tačno 100 % (' . ($zbir < 100 ? 'nedostaje ' : 'višak ') . broj(abs(100 - $zbir), 3) . ' %).'];
    }
    return [$redovi, null];
}

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $posle = $stranica;
    if ($akcija === 'sacuvaj') {
        $artikal_id = post_int('artikal_id');
        $artikal = db_one('SELECT id, naziv FROM artikli WHERE id = ?', [$artikal_id]);
        $datum = kalk_datum_iz(post_str('vazi_od', 10));
        $naziv = post_str('naziv', 100);
        $sirovine = kalk_sirovine(kalk_danas());
        [$redovi, $greska] = recept_iz_posta($sirovine);
        if ($artikal === null) {
            $greska = 'Izaberite proizvod.';
        } elseif ($datum === null) {
            $greska = 'Izaberite datum od kog receptura važi.';
        }
        $novi = 0;
        if ($greska === null) {
            $naziv = $naziv !== '' ? $naziv : $artikal['naziv'] . ' od ' . datum_srp($datum);
            try {
                db_trans(static function () use ($artikal, $datum, $naziv, $redovi, $admin, &$novi): void {
                    // zaključava recepture tog proizvoda, da dva čuvanja ne naprave dve verzije za isti dan
                    db_all('SELECT id FROM calc_recipes WHERE artikal_id = ? FOR UPDATE', [(int)$artikal['id']]);
                    $isti_dan = db_one('SELECT * FROM calc_recipes WHERE artikal_id = ? AND aktivna = 1 AND vazi_od = ?', [(int)$artikal['id'], $datum]);
                    if ($isti_dan !== null) {
                        throw new GreskaKalk('Za ' . $artikal['naziv'] . ' već postoji verzija od ' . datum_srp($datum) . ' („' . $isti_dan['naziv']
                            . '“). Izaberite drugi datum ili tu verziju isključite u istoriji.');
                    }
                    $prethodna = db_val('SELECT id FROM calc_recipes WHERE artikal_id = ? AND aktivna = 1 AND vazi_od <= ? ORDER BY vazi_od DESC LIMIT 1', [(int)$artikal['id'], $datum]);
                    db_run('INSERT INTO calc_recipes (artikal_id, naziv, vazi_od, aktivna, aktivna_od, prethodna_id, napomena, napravio_id, napravljen) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)',
                        [(int)$artikal['id'], $naziv, $datum, $datum, $prethodna, post_str('napomena', 255) ?: null, (int)$admin['id'], sada()]);
                    $novi = db_id();
                    foreach ($redovi as $mid => $udeo) {
                        db_run('INSERT INTO calc_recipe_items (recept_id, materijal_id, udeo) VALUES (?, ?, ?)', [$novi, $mid, $udeo]);
                    }
                    // provera i posle upisa (u istoj transakciji): zbir je tačno 100 %
                    if ((int)round((float)db_val('SELECT SUM(udeo) * 1000 FROM calc_recipe_items WHERE recept_id = ?', [$novi])) !== KALK_ZBIR_UDELA) {
                        throw new GreskaKalk('Zbir udela nije 100 %.');
                    }
                });
            } catch (GreskaKalk $g) {
                $greska = $g->getMessage();
                $novi = 0;
            }
        }
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
            $_SESSION['kalk_recept_forma'] = [
                'artikal_id' => $artikal_id, 'naziv' => $naziv, 'vazi_od' => post_str('vazi_od', 10), 'napomena' => post_str('napomena', 255),
                'redovi' => array_map(null, is_array($_POST['m'] ?? null) ? array_values($_POST['m']) : [], is_array($_POST['u'] ?? null) ? array_values($_POST['u']) : []),
            ];
            $posle = $stranica . '?artikal=' . $artikal_id . '#forma';
        } else {
            $sastav = implode(', ', array_map(static fn(int $mid, float $u): string => $sirovine[$mid]['naziv'] . ' ' . kalk_udeo_tekst($u), array_keys($redovi), $redovi));
            kalk_dnevnik('receptura ' . $artikal['naziv'] . ' „' . $naziv . '“ od ' . datum_srp($datum) . ': ' . $sastav);
            flash_dodaj('uspeh', 'Sačuvana receptura „' . $naziv . '“ za ' . $artikal['naziv'] . ' (važi od ' . datum_srp($datum) . '). Ranije verzije ostaju u istoriji.');
            $posle = $stranica . '#artikal-' . (int)$artikal['id'];
        }
    } elseif ($akcija === 'aktivna') {
        $r = db_one('SELECT r.*, a.naziv AS artikal FROM calc_recipes r JOIN artikli a ON a.id = r.artikal_id WHERE r.id = ?', [post_int('recept_id')]);
        $ukljuci = post_int('vrednost') === 1;
        if ($r === null) {
            flash_dodaj('greska', 'Receptura ne postoji.');
        } else {
            $posle = $stranica . '#artikal-' . (int)$r['artikal_id'];
            $isti = $ukljuci ? db_one('SELECT naziv FROM calc_recipes WHERE artikal_id = ? AND aktivna = 1 AND vazi_od = ? AND id <> ?', [(int)$r['artikal_id'], $r['vazi_od'], (int)$r['id']]) : null;
            if ($isti !== null) {
                flash_dodaj('greska', 'Za isti datum je već uključena verzija „' . $isti['naziv'] . '“ – prvo nju isključite.');
            } elseif ((int)$r['aktivna'] === ($ukljuci ? 1 : 0)) {
                flash_dodaj('info', 'Nema izmena.');
            } else {
                db_run('UPDATE calc_recipes SET aktivna = ?, aktivna_od = ? WHERE id = ?', [$ukljuci ? 1 : 0, $ukljuci ? $r['vazi_od'] : null, (int)$r['id']]);
                kalk_dnevnik(($ukljuci ? 'uključena' : 'isključena') . ' receptura ' . $r['artikal'] . ' „' . $r['naziv'] . '“ od ' . datum_srp((string)$r['vazi_od']));
                flash_dodaj('uspeh', ($ukljuci ? 'Uključena' : 'Isključena') . ' verzija „' . $r['naziv'] . '“.');
            }
        }
    }
    preusmeri($posle);
}

$danas = kalk_danas();
$sirovine = kalk_sirovine($danas);
$artikli = kalk_artikli();
$vazece = kalk_recepture($danas);
$verzije = [];
foreach (db_all('SELECT r.*, k.ime AS napravio FROM calc_recipes r LEFT JOIN korisnici k ON k.id = r.napravio_id ORDER BY r.vazi_od DESC, r.id DESC') as $r) {
    $r['stavke'] = kalk_stavke_recepture((int)$r['id']);
    $verzije[(int)$r['artikal_id']][] = $r;
}

// forma: posle greške ono što je upisano, inače kopija važeće (ili izabrane) verzije za novu verziju
$forma = $_SESSION['kalk_recept_forma'] ?? null;
unset($_SESSION['kalk_recept_forma']);
$izabran = get_int('artikal');
if ($forma === null) {
    $osnova = get_int('od') > 0 ? db_one('SELECT * FROM calc_recipes WHERE id = ?', [get_int('od')]) : ($vazece[$izabran] ?? null);
    if ($osnova !== null) {
        $izabran = (int)$osnova['artikal_id'];
    }
    $forma = [
        'artikal_id' => $izabran, 'naziv' => '', 'vazi_od' => $danas, 'napomena' => '',
        'redovi' => $osnova !== null ? array_map(static fn(array $s): array => [(string)$s['materijal_id'], broj((float)$s['udeo'], 3)], kalk_stavke_recepture((int)$osnova['id'])) : [],
    ];
}
$redovi_forme = array_pad($forma['redovi'], max(5, count($forma['redovi']) + 1), ['', '']);

ui_start('Recepture', ['nav' => 'admin', 'js' => ['assets/js/kalkulacija.js']]);
kalk_meni('recepture');
?>
<p class="pomoc">Udeo sirovine je u <strong>procentima zapremine gotovog proizvoda</strong>; zbir mora biti tačno <strong>100 %</strong>. Izmena recepture pravi <strong>novu verziju</strong> sa datumom od kog važi – ranije verzije ostaju u istoriji, a kalkulacija uzima verziju koja važi danas.</p>

<?php foreach ($artikli as $a):
    $aid = (int)$a['id'];
    if (empty($verzije[$aid])) {
        continue;
    }
    $v = $vazece[$aid] ?? null; ?>
    <section class="kartica" id="artikal-<?= $aid ?>">
        <div class="kalk-stavka-glava">
            <h3><?= e($a['naziv']) ?></h3>
            <a class="btn btn-mali" href="<?= e(url($stranica . '?artikal=' . $aid . '#forma')) ?>"><?= ikona('olovka') ?> Izmeni (nova verzija)</a>
        </div>
        <?php if ($v === null): ?>
            <p class="kalk-nedostaje">Danas ne važi nijedna verzija (sve su isključene ili počinju kasnije).</p>
        <?php else: ?>
            <div class="kalk-podaci"><span>Važi: <b><?= e($v['naziv']) ?></b></span><span>od <b><?= e(datum_srp((string)$v['vazi_od'])) ?></b></span></div>
            <ul class="kalk-sastav">
                <?php foreach ($v['stavke'] as $s): ?><li><?= e($s['naziv']) ?> – <strong><?= e(kalk_udeo_tekst($s['udeo'])) ?></strong></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <details class="kalk-istorija">
            <summary>Sve verzije (<?= count($verzije[$aid]) ?>)</summary>
            <ul class="lista">
                <?php foreach ($verzije[$aid] as $r): ?>
                    <li>
                        <span>
                            <strong><?= e($r['naziv']) ?></strong> · od <?= e(datum_srp((string)$r['vazi_od'])) ?>
                            <?php if ((int)$r['id'] === (int)($v['id'] ?? 0)): ?><span class="znacka status-utovareno">važi danas</span><?php endif; ?>
                            <?php if ((int)$r['aktivna'] === 0): ?><span class="znacka">isključena</span><?php elseif ((string)$r['vazi_od'] > $danas): ?><span class="znacka">zakazana</span><?php endif; ?>
                            <br><small class="pomoc"><?= e(implode(', ', array_map(static fn(array $s): string => $s['naziv'] . ' ' . kalk_udeo_tekst($s['udeo']), $r['stavke']))) ?> · <?= e($r['napravio'] ?? '') ?>, <?= e(datum_vreme_srp((string)$r['napravljen'])) ?></small>
                        </span>
                        <span class="grupa-dugmica">
                            <a class="btn btn-mali" href="<?= e(url($stranica . '?od=' . (int)$r['id'] . '#forma')) ?>">Kopiraj</a>
                            <form method="post" class="forma-u-liniji" action="<?= e(url($stranica)) ?>">
                                <?= csrf_polje() ?>
                                <input type="hidden" name="akcija" value="aktivna">
                                <input type="hidden" name="recept_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="vrednost" value="<?= (int)$r['aktivna'] === 1 ? 0 : 1 ?>">
                                <button class="btn btn-mali<?= (int)$r['aktivna'] === 1 ? ' btn-opasno' : '' ?>" type="submit"><?= (int)$r['aktivna'] === 1 ? 'Isključi' : 'Uključi' ?></button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </section>
<?php endforeach; ?>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url($stranica)) ?>" id="forma" data-recept>
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="sacuvaj">
    <div class="kartica-naslov"><h2><?= ikona('plus') ?> <?= $forma['artikal_id'] && !empty($verzije[$forma['artikal_id']]) ? 'Nova verzija recepture' : 'Nova receptura' ?></h2></div>
    <?php if (!$sirovine): ?>
        <p class="kalk-nedostaje">Prvo dodajte sirovine (Sirovine i cene).</p>
    <?php endif; ?>
    <div class="kalk-forma">
        <label class="mala-oznaka">Proizvod<select class="polje" name="artikal_id" required>
            <option value="">— izaberite —</option>
            <?php foreach ($artikli as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$forma['artikal_id'] === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['naziv']) ?></option><?php endforeach; ?>
        </select></label>
        <label class="mala-oznaka">Naziv verzije<input class="polje" name="naziv" type="text" maxlength="100" value="<?= e($forma['naziv']) ?>" placeholder="npr. Humovit 2026 (nije obavezno)"></label>
        <label class="mala-oznaka">Važi od<input class="polje" name="vazi_od" type="date" required value="<?= e($forma['vazi_od']) ?>"></label>
    </div>
    <div class="kalk-recept-redovi razmak-gore" data-recept-redovi>
        <div class="kalk-recept-red zbirna-red-naslov" aria-hidden="true"><span>Sirovina</span><span>Udeo %</span></div>
        <?php foreach ($redovi_forme as $i => [$mid, $udeo]): ?>
            <div class="kalk-recept-red" data-recept-red>
                <select class="polje" name="m[]" aria-label="Sirovina <?= $i + 1 ?>">
                    <option value="">—</option>
                    <?php foreach ($sirovine as $id => $m): ?><option value="<?= $id ?>"<?= (string)$mid === (string)$id ? ' selected' : '' ?>><?= e($m['naziv']) ?></option><?php endforeach; ?>
                </select>
                <input class="polje" name="u[]" type="text" inputmode="decimal" maxlength="8" aria-label="Udeo u procentima, sirovina <?= $i + 1 ?>" value="<?= e((string)$udeo) ?>" placeholder="%" data-udeo>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-mali" data-recept-jos hidden><?= ikona('plus') ?> Još jedna sirovina</button>
    <p class="kalk-zbir" data-recept-zbir aria-live="polite"></p>
    <div class="red-polja">
        <label for="napomena">Napomena (nije obavezno)</label>
        <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" value="<?= e($forma['napomena']) ?>">
    </div>
    <button class="btn btn-primary btn-blok" type="submit">Sačuvaj recepturu</button>
</form>
<?php
ui_end();
