<?php
/**
 * Kalkulacija – "Šta ako": promena cene jedne sirovine ili jednog troška (u % ili u iznosu) i šta to znači za
 * cenu džaka i maržu svih proizvoda, u poređenju sa današnjim stanjem. Uz ciljanu maržu računa se i prodajna
 * cena potrebna za nju. Ništa se ne upisuje u bazu (forma je GET).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/kalk_baza.php';

kalk_zahtevaj('Šta ako');
$stranica = 'admin/kalk_staako.php';
$danas = kalk_danas();
$prag = kalk_podesavanje('prag_marze', 0);
$pdv = kalk_podesavanje('pdv_stopa', 20);
$sirovine = kalk_sirovine($danas);
$troskovi = kalk_troskovi($danas);

/** Broj sa znakom iz forme ("-10", "+5,5", "12"): [vrednost|null, greška|null]. */
function broj_sa_znakom(string $t, int $decimale): array
{
    $t = trim($t);
    $znak = 1;
    if ($t !== '' && ($t[0] === '-' || $t[0] === '+' || str_starts_with($t, '−'))) {
        $znak = str_starts_with($t, '+') ? 1 : -1;
        $t = ltrim(str_starts_with($t, '−') ? substr($t, strlen('−')) : substr($t, 1));
    }
    $v = kalk_broj_iz($t, $decimale);
    return $v === false ? [null, true] : [$v === null ? null : $znak * $v, null];
}

$predmet = get_str('predmet', 20);
$nacin = get_str('nacin', 5) === 'iznos' ? 'iznos' : 'pct';
[$vrednost, $los_broj] = broj_sa_znakom(get_str('vrednost', 20), 4);
[$cilj, $los_cilj] = broj_sa_znakom(get_str('cilj', 10), 1);
$greska = null;
$izmene = [];
$opis = '';
if ($predmet !== '') {
    if ($los_broj || $vrednost === null) {
        $greska = 'Upišite promenu kao broj, npr. 10 ili −5 (procenata), odnosno 100 ili −50 (RSD).';
    } elseif (preg_match('/^s(\d+)$/', $predmet, $m) && isset($sirovine[(int)$m[1]])) {
        $s = $sirovine[(int)$m[1]];
        $nova = $s['cena'] === null ? null : kalk_promeni($s['cena'], $nacin, $vrednost);
        if ($s['cena'] === null) {
            $greska = $s['naziv'] . ' nema cenu – upišite je u Sirovine i cene.';
        } elseif ($nova === null) {
            $greska = 'Cena ne može biti manja od nule.';
        } else {
            $izmene['sirovina'][(int)$m[1]] = $nova;
            $opis = $s['naziv'] . ': ' . kalk_rsd($s['cena']) . ' → ' . kalk_rsd($nova) . ' po ' . ($s['jedinica'] ?: 'jedinici');
        }
    } elseif (preg_match('/^t(\d+)$/', $predmet, $m) && ($t = array_values(array_filter($troskovi, static fn(array $x): bool => (int)$x['id'] === (int)$m[1]))[0] ?? null) !== null) {
        $nova = kalk_promeni($t['iznos'], $nacin, $vrednost);
        if ($nova === null) {
            $greska = 'Iznos ne može biti manji od nule.';
        } else {
            $izmene['trosak'][(int)$t['id']] = $nova;
            $dec = $t['tip'] === 'litar' ? 4 : 2;
            $opis = $t['naziv'] . ' (' . kalk_pakovanje_tekst($t['pakovanje_id'] ? $t : null) . '): ' . kalk_rsd($t['iznos'], $dec) . ' → ' . kalk_rsd($nova, $dec) . ($t['tip'] === 'litar' ? ' po litru' : ' po džaku');
        }
    } else {
        $greska = 'Izaberite sirovinu ili trošak.';
    }
}
if ($los_cilj || ($cilj !== null && ($cilj < 0 || $cilj >= 100))) {
    $greska = $greska ?? 'Ciljana marža mora biti broj od 0 do 99 (%).';
    $cilj = null;
}
$sada = kalk_pregled($danas);
$posle = $izmene ? kalk_pregled($danas, $izmene) : $sada;

ui_start('Šta ako', ['nav' => 'admin']);
kalk_meni('staako');
?>
<p class="pomoc">Probajte promenu cene sirovine ili troška i vidite novu cenu džaka i maržu za sve proizvode – u poređenju sa današnjim stanjem. Ništa se ne čuva. Uz ciljanu maržu dobijate i prodajnu cenu potrebnu za nju.</p>

<form method="get" class="kartica" action="<?= e(url($stranica)) ?>">
    <div class="kalk-forma">
        <label class="mala-oznaka">Šta se menja<select class="polje" name="predmet" required>
            <option value="">— izaberite —</option>
            <optgroup label="Sirovine">
                <?php foreach ($sirovine as $id => $s): ?><option value="s<?= $id ?>"<?= $predmet === 's' . $id ? ' selected' : '' ?>><?= e($s['naziv']) ?><?= $s['cena'] !== null ? ' (' . e(kalk_rsd($s['cena'])) . ')' : ' (nema cene)' ?></option><?php endforeach; ?>
            </optgroup>
            <?php if ($troskovi): ?>
                <optgroup label="Ambalaža i ostali troškovi">
                    <?php foreach ($troskovi as $t): ?><option value="t<?= (int)$t['id'] ?>"<?= $predmet === 't' . $t['id'] ? ' selected' : '' ?>><?= e($t['naziv'] . ' · ' . kalk_pakovanje_tekst($t['pakovanje_id'] ? $t : null)) ?></option><?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select></label>
        <label class="mala-oznaka">Promena<select class="polje" name="nacin">
            <option value="pct"<?= $nacin === 'pct' ? ' selected' : '' ?>>u procentima (%)</option>
            <option value="iznos"<?= $nacin === 'iznos' ? ' selected' : '' ?>>u iznosu (RSD)</option>
        </select></label>
        <label class="mala-oznaka">Za koliko (+ / −)<input class="polje" name="vrednost" type="text" inputmode="decimal" maxlength="20" required value="<?= e(get_str('vrednost', 20)) ?>" placeholder="npr. 10 ili -5"></label>
        <label class="mala-oznaka">Ciljana marža (%)<input class="polje" name="cilj" type="text" inputmode="decimal" maxlength="10" value="<?= e(get_str('cilj', 10)) ?>" placeholder="npr. 30"></label>
        <button class="btn btn-primary" type="submit">Izračunaj</button>
    </div>
</form>

<?php if ($greska !== null): ?>
    <div class="poruka poruka-greska" role="alert"><?= e($greska) ?></div>
<?php elseif ($opis !== ''): ?>
    <div class="poruka poruka-info" role="status"><strong>Šta ako:</strong> <?= e($opis) ?></div>
<?php endif; ?>

<?php foreach ($posle as $aid => $g): ?>
    <section class="kartica">
        <div class="kartica-naslov"><h2><?= e($g['artikal']) ?></h2></div>
        <div class="kalk-omot">
            <table class="kalk-tabela">
                <thead>
                    <tr>
                        <th>Džak</th><th class="desno">Trošak sada</th><th class="desno">Trošak posle</th><th class="desno">Marža sada</th><th class="desno">Marža posle</th>
                        <?php if ($cilj !== null): ?><th class="desno">Cena za <?= e(kalk_pct($cilj)) ?> (bez / sa PDV)</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($g['redovi'] as $i => $r):
                        $pre = $sada[$aid]['redovi'][$i];
                        $razlika = $r['trosak']['ukupno'] !== null && $pre['trosak']['ukupno'] !== null ? $r['trosak']['ukupno'] - $pre['trosak']['ukupno'] : null;
                        $potrebna = $cilj !== null ? kalk_potrebna_cena($r['trosak']['ukupno'], $cilj) : null; ?>
                        <tr data-sku="<?= (int)$r['sku_id'] ?>">
                            <td><strong><?= e($r['naziv']) ?></strong>
                                <?php if ($r['trosak']['nedostaje']): ?><br><span class="kalk-nedostaje"><?= e(implode('; ', $r['trosak']['nedostaje'])) ?></span><?php endif; ?></td>
                            <td class="desno kalk-broj"><?= e(kalk_rsd($pre['trosak']['ukupno'])) ?></td>
                            <td class="desno kalk-broj"><strong><?= e(kalk_rsd($r['trosak']['ukupno'])) ?></strong><?php if ($razlika !== null && abs($razlika) >= 0.005): ?><br><small class="<?= $razlika > 0 ? 'tekst-crveno' : 'kalk-marza-ok' ?>"><?= $razlika > 0 ? '+' : '−' ?><?= e(kalk_rsd(abs($razlika))) ?></small><?php endif; ?></td>
                            <td class="desno kalk-broj <?= kalk_marza_klasa($pre['marza'], $prag) ?>"><?= e(kalk_pct($pre['marza']['pct'] ?? null)) ?><br><small><?= e(kalk_rsd($pre['marza']['rsd'] ?? null)) ?></small></td>
                            <td class="desno kalk-broj <?= kalk_marza_klasa($r['marza'], $prag) ?>"><strong><?= e(kalk_pct($r['marza']['pct'] ?? null)) ?></strong><br><small><?= e(kalk_rsd($r['marza']['rsd'] ?? null)) ?></small></td>
                            <?php if ($cilj !== null): ?>
                                <td class="desno kalk-broj" data-potrebna><?= e(kalk_rsd($potrebna)) ?><br><small><?= e(kalk_rsd($potrebna === null ? null : kalk_sa_pdv($potrebna, $pdv))) ?></small></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$posle): ?>
    <div class="kartica"><p class="pomoc bez-margine">Još nema proizvoda sa recepturom ili prodajnom cenom (vidi Pregled).</p></div>
<?php endif; ?>
<?php
ui_end();
