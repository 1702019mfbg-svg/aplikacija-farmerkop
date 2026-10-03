<?php
/**
 * Administrator – Podešavanja → Pakovanja: spisak litraža i težina; dodavanje novih i isključivanje.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    if ($akcija === 'novo') {
        $sirovo = str_replace(',', '.', post_str('kolicina', 10));
        $jedinica = post_str('jedinica', 3);
        if (!is_numeric($sirovo) || (float)$sirovo <= 0 || (float)$sirovo > 1000) {
            flash_dodaj('greska', 'Upišite veličinu pakovanja kao broj veći od nule (npr. 5, 12,5 ili 20).');
        } elseif (!in_array($jedinica, ['l', 'kg'], true)) {
            flash_dodaj('greska', 'Izaberite jedinicu: litara ili kilograma.');
        } else {
            $kolicina = round((float)$sirovo, 2);
            if ((int)db_val('SELECT COUNT(*) FROM pakovanja WHERE kolicina = ? AND jedinica = ?', [$kolicina, $jedinica]) > 0) {
                flash_dodaj('greska', 'To pakovanje već postoji (ako je isključeno, uključite ga).');
            } else {
                db_run('INSERT INTO pakovanja (kolicina, jedinica, aktivan) VALUES (?, ?, 1)', [$kolicina, $jedinica]);
                dnevnik_podesavanje('Novo pakovanje: ' . pakovanje_naziv($kolicina, $jedinica));
                flash_dodaj('uspeh', 'Pakovanje ' . pakovanje_naziv($kolicina, $jedinica) . ' je dodato. Dodelite ga artiklima u Podešavanja → Artikli.');
            }
        }
    } elseif ($akcija === 'status') {
        $p = db_one('SELECT * FROM pakovanja WHERE id = ?', [post_int('id')]);
        if ($p !== null) {
            $novo = (int)$p['aktivan'] === 1 ? 0 : 1;
            db_run('UPDATE pakovanja SET aktivan = ? WHERE id = ?', [$novo, $p['id']]);
            $naziv = pakovanje_naziv($p['kolicina'], (string)$p['jedinica']);
            dnevnik_podesavanje(($novo ? 'Uključeno' : 'Isključeno') . ' pakovanje: ' . $naziv);
            flash_dodaj('uspeh', 'Pakovanje ' . $naziv . ' je ' . ($novo ? 'uključeno.' : 'isključeno (ne nudi se za unos; stanje ostaje vidljivo dok ga ima).'));
        }
    }
    preusmeri('admin/pakovanja.php');
}

$pakovanja = db_all(
    'SELECT p.*, (SELECT COUNT(*) FROM sku s WHERE s.pakovanje_id = p.id AND s.aktivan = 1) AS u_upotrebi
     FROM pakovanja p ORDER BY p.jedinica, p.kolicina'
);

ui_start('Pakovanja', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a></p>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/pakovanja.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="novo">
    <div class="kartica-naslov"><h2>Novo pakovanje</h2></div>
    <div class="red-polja-2 red-polja">
        <div>
            <label for="kolicina">Veličina</label>
            <input class="polje" id="kolicina" name="kolicina" type="text" inputmode="decimal" maxlength="10" required placeholder="npr. 15">
        </div>
        <div>
            <label for="jedinica">Jedinica</label>
            <select class="polje" id="jedinica" name="jedinica">
                <option value="l">litara (l)</option>
                <option value="kg">kilograma (kg)</option>
            </select>
        </div>
    </div>
    <button class="btn btn-primary btn-blok" type="submit">Dodaj pakovanje</button>
</form>

<div class="kartica">
    <div class="kartica-naslov"><h2>Pakovanja (<?= count($pakovanja) ?>)</h2></div>
    <ul class="lista">
        <?php foreach ($pakovanja as $p): ?>
            <li class="<?= (int)$p['aktivan'] ? '' : 'radnik-ugasen' ?>">
                <div class="unos-red">
                    <span class="naziv"><?= e(pakovanje_naziv($p['kolicina'], (string)$p['jedinica'])) ?>
                        <?php if (!(int)$p['aktivan']): ?><span class="znacka">isključeno</span><?php endif; ?></span>
                    <form method="post" class="forma-u-liniji desno" action="<?= e(url('admin/pakovanja.php')) ?>"
                          <?= (int)$p['aktivan'] && (int)$p['u_upotrebi'] > 0 ? 'data-potvrda="Pakovanje se koristi kod ' . (int)$p['u_upotrebi'] . ' stavki. Isključivanjem se prestaje nuditi za unos. Nastaviti?"' : '' ?>>
                        <?= csrf_polje() ?>
                        <input type="hidden" name="akcija" value="status">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-mali" type="submit"><?= (int)$p['aktivan'] ? 'Isključi' : 'Uključi' ?></button>
                    </form>
                    <span class="pod">koristi se kod <?= (int)$p['u_upotrebi'] ?> stavki</span>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php
ui_end();
