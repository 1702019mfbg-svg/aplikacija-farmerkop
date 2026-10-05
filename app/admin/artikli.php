<?php
/**
 * Administrator – Podešavanja → Artikli: kategorije i artikli (dodavanje, isključivanje, redosled).
 * Detalji artikla (boje/granulacije, pakovanja, minimum, paleta) su na stranici artikal.php.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $id = post_int('id');
    $nazad = 'admin/artikli.php';

    if ($akcija === 'nova_kategorija') {
        $naziv = post_str('naziv', 80);
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv kategorije.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM kategorije WHERE naziv = ?', [$naziv]) > 0) {
            flash_dodaj('greska', 'Kategorija sa tim nazivom već postoji.');
        } else {
            $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM kategorije') + 10;
            db_run('INSERT INTO kategorije (naziv, redosled, aktivan) VALUES (?, ?, 1)', [$naziv, $red]);
            dnevnik_podesavanje('Nova kategorija: ' . $naziv);
            flash_dodaj('uspeh', 'Kategorija „' . $naziv . '“ je dodata.');
        }
    } elseif ($akcija === 'kat_izmeni') {
        $naziv = post_str('naziv', 80);
        $k = db_one('SELECT * FROM kategorije WHERE id = ?', [$id]);
        if ($k === null || mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv kategorije.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM kategorije WHERE naziv = ? AND id <> ?', [$naziv, $id]) > 0) {
            flash_dodaj('greska', 'Kategorija sa tim nazivom već postoji.');
        } else {
            db_run('UPDATE kategorije SET naziv = ? WHERE id = ?', [$naziv, $id]);
            dnevnik_podesavanje('Kategorija: ' . $k['naziv'] . ' → ' . $naziv);
            flash_dodaj('uspeh', 'Naziv kategorije je sačuvan.');
        }
    } elseif ($akcija === 'kat_status') {
        $k = db_one('SELECT * FROM kategorije WHERE id = ?', [$id]);
        if ($k !== null) {
            $novo = (int)$k['aktivan'] === 1 ? 0 : 1;
            db_run('UPDATE kategorije SET aktivan = ? WHERE id = ?', [$novo, $id]);
            dnevnik_podesavanje(($novo ? 'Uključena' : 'Isključena') . ' kategorija: ' . $k['naziv']);
            flash_dodaj('uspeh', 'Kategorija „' . $k['naziv'] . '“ je ' . ($novo ? 'uključena.' : 'isključena (njeni artikli se ne nude za unos).'));
        }
    } elseif ($akcija === 'kat_pomeri') {
        pomeri_red('kategorije', $id, post_str('smer', 4));
    } elseif ($akcija === 'novi_artikal') {
        $naziv = post_str('naziv', 100);
        $kat = post_int('kategorija_id');
        $oznaka = post_str('oznaka', 20);
        $nv = post_str('nv', 30);
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv artikla.');
        } elseif (db_one('SELECT id FROM kategorije WHERE id = ?', [$kat]) === null) {
            flash_dodaj('greska', 'Izaberite kategoriju.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM artikli WHERE kategorija_id = ? AND naziv = ?', [$kat, $naziv]) > 0) {
            flash_dodaj('greska', 'U toj kategoriji već postoji artikal sa tim nazivom.');
        } else {
            $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM artikli WHERE kategorija_id = ?', [$kat]) + 10;
            db_run(
                'INSERT INTO artikli (kategorija_id, naziv, oznaka, naziv_varijante, redosled, aktivan) VALUES (?, ?, ?, ?, ?, 1)',
                [$kat, $naziv, $oznaka !== '' ? $oznaka : null, $nv !== '' ? $nv : null, $red]
            );
            $novi = db_id();
            dnevnik_podesavanje('Novi artikal: ' . $naziv, 'podesavanje', $novi);
            flash_dodaj('uspeh', 'Artikal „' . $naziv . '“ je dodat. Sada izaberite pakovanja' . ($nv !== '' ? ' i dodajte ' . mb_strtolower($nv) . '.' : '.'));
            preusmeri('admin/artikal.php?id=' . $novi);
        }
    } elseif ($akcija === 'art_status') {
        $a = db_one('SELECT * FROM artikli WHERE id = ?', [$id]);
        if ($a !== null) {
            $novo = (int)$a['aktivan'] === 1 ? 0 : 1;
            db_run('UPDATE artikli SET aktivan = ? WHERE id = ?', [$novo, $id]);
            dnevnik_podesavanje(($novo ? 'Uključen' : 'Isključen') . ' artikal: ' . $a['naziv'], 'podesavanje', $id);
            flash_dodaj('uspeh', 'Artikal „' . $a['naziv'] . '“ je ' . ($novo ? 'uključen i nudi se za unos.' : 'isključen (ne nudi se za unos; stanje ostaje vidljivo dok ga ima).'));
        }
    } elseif ($akcija === 'art_pomeri') {
        pomeri_red('artikli', $id, post_str('smer', 4));
    }
    preusmeri($nazad);
}

$kategorije = db_all('SELECT * FROM kategorije ORDER BY redosled, id');
$artikli = db_all(
    'SELECT a.*, (SELECT COUNT(*) FROM sku s WHERE s.artikal_id = a.id AND s.aktivan = 1) AS broj_sku,
            (SELECT COUNT(*) FROM varijante v WHERE v.artikal_id = a.id AND v.aktivan = 1) AS broj_var
     FROM artikli a ORDER BY a.redosled, a.id'
);
$po_kategoriji = [];
foreach ($artikli as $a) {
    $po_kategoriji[(int)$a['kategorija_id']][] = $a;
}

/** Mali obrazac sa jednim dugmetom (isključi, pomeri gore/dole...). */
function dugme_forma(string $akcija, int $id, string $tekst, string $klasa = 'btn btn-mali', array $dodatno = [], string $potvrda = ''): string
{
    $h = '<form method="post" class="forma-u-liniji" action="' . e(url('admin/artikli.php')) . '"' . ($potvrda !== '' ? ' data-potvrda="' . e($potvrda) . '"' : '') . '>'
        . csrf_polje() . '<input type="hidden" name="akcija" value="' . e($akcija) . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($dodatno as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h . '<button type="submit" class="' . e($klasa) . '">' . $tekst . '</button></form>';
}

ui_start('Artikli', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a></p>

<details class="kartica">
    <summary class="kartica-naslov"><h2>Novi artikal</h2></summary>
    <form method="post" autocomplete="off" action="<?= e(url('admin/artikli.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="novi_artikal">
        <div class="red-polja">
            <label for="a-naziv">Naziv</label>
            <input class="polje" id="a-naziv" name="naziv" type="text" maxlength="100" required placeholder="npr. Crni oblutak">
        </div>
        <div class="red-polja">
            <label for="a-kat">Kategorija</label>
            <select class="polje" id="a-kat" name="kategorija_id" required>
                <?php foreach ($kategorije as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['naziv']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="red-polja-2 red-polja">
            <div>
                <label for="a-oznaka">Oznaka (npr. PL)</label>
                <input class="polje" id="a-oznaka" name="oznaka" type="text" maxlength="20" placeholder="nije obavezno">
            </div>
            <div>
                <label for="a-nv">Izbor se zove</label>
                <input class="polje" id="a-nv" name="nv" type="text" maxlength="30" list="nazivi-izbora" placeholder="npr. Boja">
                <datalist id="nazivi-izbora"><option value="Boja"><option value="Granulacija"><option value="Vrsta"><option value="Model"></datalist>
            </div>
        </div>
        <p class="pomoc">„Izbor se zove“ popunite samo ako artikal dolazi u više varijanti (boje, granulacije…). Varijante i pakovanja dodajete na sledećem koraku.</p>
        <button class="btn btn-primary btn-blok" type="submit">Dodaj artikal</button>
    </form>
</details>

<?php foreach ($kategorije as $k): ?>
    <div class="kartica kat-kartica<?= (int)$k['aktivan'] ? '' : ' radnik-ugasen' ?>">
        <div class="kartica-naslov">
            <h2><?= e($k['naziv']) ?><?php if (!(int)$k['aktivan']): ?> <span class="znacka">isključena</span><?php endif; ?></h2>
            <span class="grupa-dugmica">
                <?= dugme_forma('kat_pomeri', (int)$k['id'], '↑', 'btn btn-mali', ['smer' => 'gore']) ?>
                <?= dugme_forma('kat_pomeri', (int)$k['id'], '↓', 'btn btn-mali', ['smer' => 'dole']) ?>
            </span>
        </div>
        <?php foreach ($po_kategoriji[(int)$k['id']] ?? [] as $a): ?>
            <div class="artikal-red<?= (int)$a['aktivan'] ? '' : ' radnik-ugasen' ?>">
                <div class="artikal-red-tekst">
                    <strong><?= e($a['naziv']) ?></strong>
                    <?php if ($a['oznaka']): ?><span class="znacka znacka-pl"><?= e($a['oznaka']) ?></span><?php endif; ?>
                    <?php if (!(int)$a['aktivan']): ?><span class="znacka">isključen</span><?php endif; ?>
                    <?php if ((int)$a['aktivan'] && (int)$a['broj_sku'] === 0): ?><span class="znacka znacka-nisko">nema pakovanja – ne nudi se za unos</span><?php endif; ?>
                    <div class="pomoc">
                        <?= (int)$a['broj_sku'] ?> pakovanja
                        <?php if ((int)$a['broj_var'] > 0): ?>· <?= (int)$a['broj_var'] ?> × <?= e(mb_strtolower($a['naziv_varijante'] ?: 'varijanti')) ?><?php endif; ?>
                    </div>
                </div>
                <div class="grupa-dugmica">
                    <a class="btn btn-mali btn-primary" href="<?= e(url('admin/artikal.php?id=' . (int)$a['id'])) ?>"><?= ikona('olovka') ?> Uredi</a>
                    <?= dugme_forma('art_status', (int)$a['id'], (int)$a['aktivan'] ? 'Isključi' : 'Uključi', 'btn btn-mali', [], (int)$a['aktivan'] ? 'Isključiti artikal „' . $a['naziv'] . '“? Neće se nuditi za unos (stanje ostaje vidljivo dok ga ima).' : '') ?>
                    <?= dugme_forma('art_pomeri', (int)$a['id'], '↑', 'btn btn-mali', ['smer' => 'gore']) ?>
                    <?= dugme_forma('art_pomeri', (int)$a['id'], '↓', 'btn btn-mali', ['smer' => 'dole']) ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($po_kategoriji[(int)$k['id']])): ?><p class="pomoc bez-margine">Nema artikala u ovoj kategoriji.</p><?php endif; ?>

        <details class="razmak-gore">
            <summary class="pomoc">Izmeni kategoriju</summary>
            <form method="post" class="razmak-gore" action="<?= e(url('admin/artikli.php')) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="kat_izmeni">
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                <div class="red-polja">
                    <label for="kat-<?= (int)$k['id'] ?>">Naziv kategorije</label>
                    <input class="polje" id="kat-<?= (int)$k['id'] ?>" name="naziv" type="text" maxlength="80" required value="<?= e($k['naziv']) ?>">
                </div>
                <div class="grupa-dugmica">
                    <button class="btn btn-primary btn-mali" type="submit">Sačuvaj naziv</button>
                </div>
            </form>
            <div class="razmak-gore">
                <?= dugme_forma('kat_status', (int)$k['id'], (int)$k['aktivan'] ? 'Isključi kategoriju' : 'Uključi kategoriju', 'btn btn-mali', [], (int)$k['aktivan'] ? 'Isključiti celu kategoriju „' . $k['naziv'] . '“ sa svim njenim artiklima? Neće se nuditi za unos.' : '') ?>
            </div>
        </details>
    </div>
<?php endforeach; ?>

<details class="kartica">
    <summary class="kartica-naslov"><h2>Nova kategorija</h2></summary>
    <form method="post" autocomplete="off" action="<?= e(url('admin/artikli.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="nova_kategorija">
        <div class="red-polja">
            <label for="nova-kat">Naziv</label>
            <input class="polje" id="nova-kat" name="naziv" type="text" maxlength="80" required placeholder="npr. Đubrivo">
        </div>
        <button class="btn btn-primary btn-blok" type="submit">Dodaj kategoriju</button>
    </form>
</details>
<?php
ui_end();
