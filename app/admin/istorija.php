<?php
/**
 * Administrator – Istorija: svi unosi sa filterima, ispravka, brisanje (meko) i izvoz u CSV.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';

$admin = zahtevaj_ulogu('admin');
$f = istorija_filteri();
$po_strani = 40;

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $id = post_int('id');
    $nazad = 'admin/istorija.php?' . preg_replace('/[^A-Za-z0-9_=&%.+\-]/', '', post_str('nazad', 400));
    if ($akcija === 'obrisi') {
        $rez = unos_obrisi($id, (int)$admin['id'], 'brisanje');
        flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos je obrisan. Može da se vrati iz prikaza obrisanih.' : $rez['greska']);
    } elseif ($akcija === 'vrati') {
        $rez = unos_vrati($id);
        flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos je vraćen.' : $rez['greska']);
    }
    preusmeri($nazad);
}

[$uslov, $parametri] = istorija_uslov($f, $f['obrisani']);
$ukupno = (int)db_val('SELECT COUNT(*) ' . ISTORIJA_IZ . ' WHERE ' . $uslov, $parametri);
$strana_max = max(1, (int)ceil($ukupno / $po_strani));
$strana = min(max(1, get_int('strana', 1)), $strana_max);
$pomak = ($strana - 1) * $po_strani;

$redovi = db_all(
    'SELECT ' . ISTORIJA_POLJA . ", (SELECT COUNT(*) FROM dnevnik d WHERE d.objekat = 'unos' AND d.objekat_id = u.id AND d.akcija = 'izmena') AS izmena_broj "
    . ISTORIJA_IZ . ' WHERE ' . $uslov . ' ORDER BY u.nastalo DESC, u.id DESC LIMIT ' . $po_strani . ' OFFSET ' . $pomak,
    $parametri
);
$zbir = istorija_zbirovi($f);

$radnici = db_all("SELECT id, ime, uloga FROM korisnici ORDER BY uloga, ime");
$artikli = db_all('SELECT id, naziv, aktivan FROM artikli ORDER BY naziv');

$osnovni = istorija_query($f);
$ima_filter_vise = $f['radnik'] > 0 || $f['artikal'] > 0 || $f['q'] !== '' || $f['obrisani'];
$danas = date('Y-m-d');
$opsezi = [
    'Danas'   => ['od' => $danas, 'do' => $danas],
    '7 dana'  => ['od' => date('Y-m-d', strtotime('-6 days')), 'do' => $danas],
    '30 dana' => ['od' => date('Y-m-d', strtotime('-29 days')), 'do' => $danas],
    'Sve'     => ['od' => '', 'do' => ''],
];
$tipovi_cipovi = ['' => 'Sve', 'proizvodnja' => 'Proizvodnja', 'prodaja' => 'Prodaja', 'kucna_prodaja' => 'Kućna prodaja', 'korekcija' => 'Popis'];

ui_start('Istorija', ['nav' => 'admin', 'aktivno' => 'istorija']);
?>
<div class="cipovi" role="group" aria-label="Vrsta unosa">
    <?php foreach ($tipovi_cipovi as $kljuc => $naziv): ?>
        <a class="cip" href="<?= e(url('admin/istorija.php?' . istorija_query(array_merge($f, ['tip' => $kljuc])))) ?>"
           <?= $f['tip'] === $kljuc ? 'aria-current="true"' : '' ?>><?= e($naziv) ?></a>
    <?php endforeach; ?>
</div>
<div class="cipovi" role="group" aria-label="Period">
    <?php foreach ($opsezi as $naziv => $o): ?>
        <a class="cip" href="<?= e(url('admin/istorija.php?' . istorija_query(array_merge($f, $o)))) ?>"
           <?= ($f['od'] === $o['od'] && $f['do'] === $o['do']) ? 'aria-current="true"' : '' ?>><?= e($naziv) ?></a>
    <?php endforeach; ?>
</div>

<details class="kartica"<?= $ima_filter_vise ? ' open' : '' ?>>
    <summary class="kartica-naslov"><h2>Još filtera</h2></summary>
    <form method="get" action="<?= e(url('admin/istorija.php')) ?>">
        <input type="hidden" name="tip" value="<?= e($f['tip']) ?>">
        <div class="red-polja-2 red-polja">
            <div><label for="od">Od datuma</label><input class="polje" type="date" id="od" name="od" value="<?= e($f['od']) ?>"></div>
            <div><label for="do">Do datuma</label><input class="polje" type="date" id="do" name="do" value="<?= e($f['do']) ?>"></div>
        </div>
        <div class="red-polja">
            <label for="radnik">Radnik</label>
            <select class="polje" id="radnik" name="radnik">
                <option value="0">Svi</option>
                <?php foreach ($radnici as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"<?= $f['radnik'] === (int)$r['id'] ? ' selected' : '' ?>><?= e($r['ime']) ?><?= $r['uloga'] === 'admin' ? ' (administrator)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="red-polja">
            <label for="artikal">Artikal</label>
            <select class="polje" id="artikal" name="artikal">
                <option value="0">Svi</option>
                <?php foreach ($artikli as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"<?= $f['artikal'] === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['naziv']) ?><?= $a['aktivan'] ? '' : ' (isključen)' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="red-polja">
            <label for="q">Kupac ili napomena sadrži</label>
            <input class="polje" type="search" id="q" name="q" maxlength="60" value="<?= e($f['q']) ?>">
        </div>
        <div class="red-polja">
            <label><input type="checkbox" name="obrisani" value="1"<?= $f['obrisani'] ? ' checked' : '' ?>> Prikaži i obrisane unose</label>
        </div>
        <div class="grupa-dugmica">
            <button class="btn btn-primary" type="submit">Primeni</button>
            <a class="btn" href="<?= e(url('admin/istorija.php')) ?>">Poništi</a>
        </div>
    </form>
</details>

<div class="stat-mreza tri">
    <div class="stat stat-uspeh"><span class="broj"><?= e(broj($zbir['proizvodnja'])) ?></span><span class="opis">proizvedeno (kom)</span></div>
    <div class="stat"><span class="broj"><?= e(broj($zbir['prodaja'] + $zbir['kucna_prodaja'])) ?></span><span class="opis">prodato (kom)</span></div>
    <div class="stat"><span class="broj"><?= e(broj($zbir['broj'])) ?></span><span class="opis">unosa</span></div>
</div>

<p class="red-izmedju">
    <a class="btn btn-braon btn-mali" href="<?= e(url('admin/izvoz.php?' . $osnovni)) ?>"><?= ikona('preuzmi') ?> Izvoz u Excel (CSV)</a>
    <a class="btn btn-mali" href="<?= e(url('admin/dnevnik.php')) ?>">Dnevnik izmena</a>
</p>

<?php if (!$redovi): ?>
    <div class="kartica"><p class="bez-margine">Nema unosa za izabrane filtere.</p></div>
<?php else: ?>
    <div class="kartica">
        <ul class="lista">
            <?php foreach ($redovi as $u):
                $kom = (int)$u['kolicina'];
                $predznak = in_array($u['tip'], ['prodaja', 'kucna_prodaja'], true) ? '−' : '+';
                $prikaz_kom = $u['tip'] === 'korekcija' ? ($kom >= 0 ? '+' : '−') . broj(abs($kom)) : $predznak . broj($kom);
                ?>
                <li<?= $u['obrisan'] ? ' class="unos-obrisan"' : '' ?>>
                    <div class="unos-red">
                        <span class="naziv"><?= e(sku_naziv($u)) ?><?php if ($u['oznaka']): ?> <span class="znacka znacka-pl"><?= e($u['oznaka']) ?></span><?php endif; ?></span>
                        <span class="kol"><?= e($prikaz_kom) ?> kom</span>
                        <span class="pod">
                            <?= e(datum_kratko((string)$u['nastalo'])) ?>
                            <span class="znacka znacka-<?= e($u['tip']) ?>"><?= e(TIPOVI_UNOSA[$u['tip']]) ?></span>
                            <?= e($u['radnik']) ?><?= $u['kupac'] ? ' → <strong>' . e($u['kupac']) . '</strong>' : '' ?>
                            <?= $u['napomena'] ? ' · ' . e($u['napomena']) : '' ?>
                        </span>
                        <span class="kol-pod"><?= $u['palete'] ? e(palete_tekst((int)$u['palete'])) : '' ?></span>
                        <div class="akcije">
                            <?php if ($u['obrisan']): ?>
                                <span class="pomoc">Obrisao: <?= e($u['obrisao'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$u['obrisan_u'])) ?></span>
                                <form method="post" class="forma-u-liniji" action="<?= e(url('admin/istorija.php')) ?>">
                                    <?= csrf_polje() ?>
                                    <input type="hidden" name="akcija" value="vrati">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="nazad" value="<?= e($osnovni . '&strana=' . $strana) ?>">
                                    <button class="btn btn-mali" type="submit">Vrati unos</button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-mali" href="<?= e(url('admin/unos.php?id=' . (int)$u['id'] . '&nazad=' . rawurlencode($osnovni . '&strana=' . $strana))) ?>"><?= ikona('olovka') ?> Ispravi</a>
                                <form method="post" class="forma-u-liniji" action="<?= e(url('admin/istorija.php')) ?>"
                                      data-potvrda="Obrisati ovaj unos (<?= e(sku_naziv($u)) ?>, <?= $kom ?> kom)?">
                                    <?= csrf_polje() ?>
                                    <input type="hidden" name="akcija" value="obrisi">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="nazad" value="<?= e($osnovni . '&strana=' . $strana) ?>">
                                    <button class="btn btn-opasno btn-mali" type="submit"><?= ikona('kanta') ?> Obriši</button>
                                </form>
                                <?php if ((int)$u['izmena_broj'] > 0): ?>
                                    <a class="znacka" href="<?= e(url('admin/unos.php?id=' . (int)$u['id'])) ?>">izmenjeno ×<?= (int)$u['izmena_broj'] ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($strana_max > 1): ?>
        <nav class="stranice" aria-label="Stranice">
            <?php if ($strana > 1): ?><a class="btn btn-mali" href="<?= e(url('admin/istorija.php?' . $osnovni . '&strana=' . ($strana - 1))) ?>">‹ Novije</a><?php else: ?><span></span><?php endif; ?>
            <span class="pomoc">Strana <?= $strana ?> od <?= $strana_max ?></span>
            <?php if ($strana < $strana_max): ?><a class="btn btn-mali" href="<?= e(url('admin/istorija.php?' . $osnovni . '&strana=' . ($strana + 1))) ?>">Starije ›</a><?php else: ?><span></span><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
ui_end();
