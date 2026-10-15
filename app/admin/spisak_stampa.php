<?php
/**
 * Spisak za utovar – prikaz za štampu (A4 i telefon), čuvanje kao PDF (Štampaj → Sačuvaj kao PDF)
 * i deljenje teksta spiska (Viber, WhatsApp, SMS…).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/spiskovi.php';

$korisnik = zahtevaj_ulogu();
$id = get_int('id');
$sp = spisak_ucitaj($id);
if ($sp === null || ($korisnik['uloga'] !== 'admin' && !in_array($sp['status'], ['za_utovar', 'utovareno'], true))) {
    stranica_greske(404, 'Spisak ne postoji', 'Traženi spisak za utovar ne postoji.', 'index.php', 'Nazad');
}
$stavke = spisak_stavke($id);
$utovaren = $sp['status'] === 'utovareno';
$z = spisak_zbir($stavke, $utovaren);
$tekst = spisak_tekst_za_deljenje($sp, $stavke);
$nazad = $korisnik['uloga'] === 'admin' ? 'admin/spisak.php?id=' . $id : 'radnik/utovar.php?id=' . $id;

ui_start('Spisak #' . $id, ['bez_zaglavlja' => true, 'telo' => 'stampa', 'js' => ['assets/js/stampa.js']]);
?>
<div class="ne-stampa stampa-akcije">
    <a class="btn btn-mali" href="<?= e(url($nazad)) ?>"><?= ikona('nazad') ?> Nazad</a>
    <button type="button" class="btn btn-primary" data-stampaj><?= ikona('preuzmi') ?> Štampaj / PDF</button>
    <button type="button" class="btn btn-braon" data-podeli data-naslov="<?= e('Spisak za utovar #' . $id) ?>">Podeli</button>
    <a class="btn btn-mali" href="https://wa.me/?text=<?= e(rawurlencode($tekst)) ?>" target="_blank" rel="noopener">WhatsApp</a>
    <a class="btn btn-mali" href="viber://forward?text=<?= e(rawurlencode($tekst)) ?>">Viber</a>
    <p class="pomoc bez-margine">PDF: dodirnite „Štampaj / PDF“ i kao štampač izaberite <strong>Sačuvaj kao PDF</strong>.</p>
    <p class="pomoc bez-margine" data-podeli-poruka aria-live="polite"></p>
    <textarea class="polje" data-tekst readonly rows="3" hidden><?= e($tekst) ?></textarea>
</div>

<article class="stampa-list">
    <header class="stampa-glava">
        <div>
            <div class="stampa-firma">Farmerkop d.o.o.</div>
            <h1>Spisak za utovar #<?= $id ?></h1>
        </div>
        <div class="stampa-status"><?= e(SPISAK_STATUSI[$sp['status']]) ?></div>
    </header>
    <dl class="stampa-podaci">
        <div><dt>Kupac</dt><dd><?= e($sp['kupac']) ?></dd></div>
        <div><dt>Datum utovara</dt><dd><?= e(dan_u_nedelji((string)$sp['datum'])) ?>, <?= e(datum_srp((string)$sp['datum'])) ?></dd></div>
        <div><dt>Vozilo</dt><dd><?= e($sp['vozilo'] ?: '—') ?></dd></div>
        <div><dt>Vozač</dt><dd><?= e($sp['vozac'] ?: '—') ?></dd></div>
        <?php if ($sp['napomena']): ?><div class="siroko"><dt>Napomena</dt><dd><?= e($sp['napomena']) ?></dd></div><?php endif; ?>
        <?php if ($utovaren): ?><div class="siroko"><dt>Utovareno</dt><dd><?= e(datum_vreme_srp((string)$sp['utovareno_u'])) ?>, <?= e($sp['utovario'] ?? '') ?></dd></div><?php endif; ?>
    </dl>

    <table class="stampa-tabela">
        <thead><tr><th>#</th><th>Artikal</th><th>Količina</th><th class="br">Kom</th><th class="br">≈ kg</th><th class="cek" aria-label="Utovareno">✓</th></tr></thead>
        <tbody>
        <?php $n = 0; foreach ($stavke as $st):
            $n++;
            $kol = $utovaren && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
            $kg = stavka_kg($st, $kol); ?>
            <tr>
                <td><?= $n ?></td>
                <td><?= e(stavka_naziv($st)) ?><?= $st['sku_id'] === null ? ' <small>(ostalo)</small>' : '' ?></td>
                <td><?= e(stavka_unos_tekst($st)) ?><?php if ($utovaren && $st['utovareno'] !== null && (int)$st['utovareno'] !== (int)$st['kolicina']): ?><br><small>utovareno: <?= e(broj((int)$st['utovareno'])) ?></small><?php endif; ?></td>
                <td class="br"><?= $st['sku_id'] === null ? '' : e(broj($kol)) ?></td>
                <td class="br"><?= $kg === null ? '—' : e(broj($kg, $kg == floor($kg) ? 0 : 1)) ?></td>
                <td class="cek"><?= $utovaren ? '✓' : '☐' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td></td><td colspan="2"><strong>Ukupno</strong><?= $z['palete'] > 0 ? ' · ' . e(palete_zbir_tekst($z['palete'])) : '' ?></td><td></td><td class="br"><strong><?= e(broj($z['kg'], 0)) ?></strong></td><td></td></tr>
        </tfoot>
    </table>
    <?php if ($z['bez_tezine'] || $z['bez_palete']): ?>
        <p class="stampa-napomena">Težina i broj paleta su okvirni<?= $z['bez_tezine'] ? '; bez težine: ' . (int)$z['bez_tezine'] . ' stavki' : '' ?><?= $z['bez_palete'] ? '; bez palete: ' . (int)$z['bez_palete'] . ' stavki' : '' ?>.</p>
    <?php else: ?>
        <p class="stampa-napomena">Težina i broj paleta su okvirni.</p>
    <?php endif; ?>

    <div class="stampa-potpisi">
        <div>Utovario</div><div>Vozač</div><div>Primio</div>
    </div>
</article>
<?php
ui_end();
