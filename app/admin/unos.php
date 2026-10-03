<?php
/**
 * Administrator – ispravka jednog unosa, brisanje i pregled ko ga je i kad menjao.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/izbor.php';
require __DIR__ . '/../inc/istorija.php';

$admin = zahtevaj_ulogu('admin');
$id = je_post() ? post_int('id') : get_int('id');
$nazad_upit = preg_replace('/[^A-Za-z0-9_=&%.+\-]/', '', je_post() ? post_str('nazad', 400) : get_str('nazad', 400));
$istorija_adresa = 'admin/istorija.php' . ($nazad_upit !== '' ? '?' . $nazad_upit : '');

$u = db_one(
    'SELECT u.*, r.ime AS radnik, ku.naziv AS kupac, ob.ime AS obrisao FROM unosi u '
    . 'JOIN korisnici r ON r.id = u.korisnik_id LEFT JOIN kupci ku ON ku.id = u.kupac_id LEFT JOIN korisnici ob ON ob.id = u.obrisao_id '
    . 'WHERE u.id = ?',
    [$id]
);
if ($u === null) {
    stranica_greske(404, 'Unos ne postoji', 'Traženi unos nije pronađen.', 'admin/istorija.php', 'Nazad na istoriju');
}
$tip = (string)$u['tip'];
$stranica = 'admin/unos.php?id=' . $id . ($nazad_upit !== '' ? '&nazad=' . rawurlencode($nazad_upit) : '');

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);

    if ($akcija === 'obrisi') {
        $rez = unos_obrisi($id, (int)$admin['id'], 'brisanje');
        flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos je obrisan.' : $rez['greska']);
        preusmeri($rez['ok'] ? $istorija_adresa : $stranica);
    }
    if ($akcija === 'vrati') {
        $rez = unos_vrati($id);
        flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos je vraćen.' : $rez['greska']);
        preusmeri($stranica);
    }
    if ($akcija === 'sacuvaj') {
        // vreme događaja
        $vreme = DateTime::createFromFormat('Y-m-d\TH:i', post_str('nastalo', 16));
        if ($vreme === false) {
            flash_dodaj('greska', 'Upišite ispravan datum i vreme.');
            preusmeri($stranica);
        }
        $novo_vreme = $vreme->format('Y-m-d H:i:s');
        if (date('Y-m-d H:i', (int)strtotime((string)$u['nastalo'])) === $vreme->format('Y-m-d H:i')) {
            $novo_vreme = (string)$u['nastalo'];            // vreme nije menjano – ne diramo sekunde
        }
        if ($novo_vreme < '2020-01-01 00:00:00' || $novo_vreme > date('Y-m-d H:i:s', strtotime('+1 day'))) {
            flash_dodaj('greska', 'Datum je van dozvoljenog opsega.');
            preusmeri($stranica);
        }

        $nova = ['nastalo' => $novo_vreme, 'napomena' => post_str('napomena', 255)];
        if ($tip !== 'korekcija') {
            $sku_id = post_int('sku_id');
            $sku = $sku_id === (int)$u['sku_id'] ? sku_podaci($sku_id) : ($sku_id > 0 ? sku_aktivan($sku_id) : null);
            if ($sku === null) {
                flash_dodaj('greska', 'Izaberite artikal i pakovanje.');
                preusmeri($stranica);
            }
            $kol = procitaj_kolicinu($sku);
            if (isset($kol['greska'])) {
                flash_dodaj('greska', $kol['greska']);
                preusmeri($stranica);
            }
            $nova['sku_id'] = $sku_id;
            $nova['kolicina'] = $kol['komadi'];
            $nova['palete'] = $kol['palete'];
            if ($tip === 'prodaja') {
                $kupac = post_str('kupac', 120);
                if ($kupac === '') {
                    flash_dodaj('greska', 'Upišite kupca.');
                    preusmeri($stranica);
                }
                $nova['kupac_id'] = kupac_id_za($kupac);
            }
        }
        $rez = unos_izmeni($id, $nova, (int)$admin['id']);
        if (!$rez['ok']) {
            flash_dodaj('greska', $rez['greska']);
            preusmeri($stranica);
        }
        flash_dodaj('uspeh', !empty($rez['nepromenjeno']) ? 'Ništa nije promenjeno.' : 'Izmena je sačuvana i upisana u dnevnik.');
        preusmeri($istorija_adresa);
    }
    preusmeri($stranica);
}

$sku_info = sku_podaci((int)$u['sku_id']);
$dnevnik = db_all(
    "SELECT d.*, k.ime FROM dnevnik d LEFT JOIN korisnici k ON k.id = d.korisnik_id
     WHERE d.objekat = 'unos' AND d.objekat_id = ? ORDER BY d.id DESC",
    [$id]
);
$palete = $u['palete'] === null ? 0 : (int)$u['palete'];

ui_start('Ispravka unosa', ['nav' => 'admin', 'aktivno' => 'istorija', 'js' => $tip === 'korekcija' ? [] : ['assets/js/unos.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url($istorija_adresa)) ?>"><?= ikona('nazad') ?> Istorija</a></p>

<div class="kartica">
    <div class="kartica-naslov">
        <h2><?= e(TIPOVI_UNOSA[$tip]) ?> #<?= (int)$u['id'] ?></h2>
        <span class="znacka znacka-<?= e($tip) ?>"><?= e(TIPOVI_UNOSA[$tip]) ?></span>
    </div>
    <p class="pomoc bez-margine">
        Uneo: <strong><?= e($u['radnik']) ?></strong>, <?= e(datum_vreme_srp((string)$u['uneto'])) ?>
        <?php if ($u['obrisan']): ?><br><strong>Obrisan</strong> (<?= e($u['obrisao'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$u['obrisan_u'])) ?>)<?php endif; ?>
    </p>
</div>

<?php if ($u['obrisan']): ?>
    <div class="kartica">
        <p><?= e($sku_info ? sku_naziv($sku_info) : '') ?> – <?= e(kolicina_tekst((int)$u['kolicina'], $palete ?: null)) ?></p>
        <form method="post" action="<?= e(url('admin/unos.php')) ?>">
            <?= csrf_polje() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="nazad" value="<?= e($nazad_upit) ?>">
            <input type="hidden" name="akcija" value="vrati">
            <button class="btn btn-primary btn-blok" type="submit">Vrati unos</button>
        </form>
    </div>
<?php else: ?>
    <form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/unos.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="nazad" value="<?= e($nazad_upit) ?>">
        <input type="hidden" name="akcija" value="sacuvaj">
        <div class="kartica-naslov"><h2>Izmena</h2></div>

        <?php if ($tip === 'korekcija'): ?>
            <p><?= e($sku_info ? sku_naziv($sku_info) : '') ?>: promena stanja <strong><?= ((int)$u['kolicina'] >= 0 ? '+' : '−') . e(broj(abs((int)$u['kolicina']))) ?> kom</strong>.</p>
            <p class="pomoc">Količina popisa se ne menja ovde. Ako je bila pogrešna, obrišite ovaj unos i uradite popis ponovo.</p>
        <?php else: ?>
            <?php if ($tip === 'prodaja'): ?>
                <div class="red-polja">
                    <label for="kupac">Kupac</label>
                    <input class="polje" id="kupac" name="kupac" type="text" maxlength="120" required value="<?= e($u['kupac'] ?? '') ?>">
                </div>
            <?php endif; ?>
            <?= izbor_html(katalog_za_izbor(true, (int)$u['sku_id']), (int)$u['sku_id'], true, [
                'kolicina' => $palete ?: (int)$u['kolicina'],
                'nacin'    => $palete ? 'palete' : 'komadi',
            ]) ?>
        <?php endif; ?>

        <div class="red-polja">
            <label for="nastalo">Datum i vreme</label>
            <input class="polje" id="nastalo" name="nastalo" type="datetime-local" required value="<?= e(date('Y-m-d\TH:i', (int)strtotime((string)$u['nastalo']))) ?>">
        </div>
        <div class="red-polja">
            <label for="napomena">Napomena</label>
            <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" value="<?= e($u['napomena'] ?? '') ?>">
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit"<?= $tip === 'korekcija' ? '' : ' data-sacuvaj' ?>>Sačuvaj izmene</button>
    </form>

    <form method="post" class="kartica" action="<?= e(url('admin/unos.php')) ?>"
          data-potvrda="Obrisati ovaj unos? Može da se vrati iz prikaza obrisanih.">
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="nazad" value="<?= e($nazad_upit) ?>">
        <input type="hidden" name="akcija" value="obrisi">
        <button class="btn btn-opasno btn-blok" type="submit"><?= ikona('kanta') ?> Obriši unos</button>
    </form>
<?php endif; ?>

<section class="kartica" aria-labelledby="dnevnik-naslov">
    <div class="kartica-naslov"><h2 id="dnevnik-naslov">Ko je i kad menjao</h2></div>
    <?php if (!$dnevnik): ?>
        <p class="pomoc bez-margine">Unos nije menjan.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($dnevnik as $d): ?>
                <li>
                    <strong><?= e(AKCIJE_DNEVNIKA[$d['akcija']] ?? $d['akcija']) ?></strong>
                    – <?= e($d['ime'] ?? 'nepoznat') ?>, <?= e(datum_vreme_srp((string)$d['vreme'])) ?>
                    <?php $promene = dnevnik_promene($d['detalji']); ?>
                    <?php if ($promene): ?>
                        <ul class="promene"><?php foreach ($promene as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
ui_end();
