<?php
/**
 * Administrator – Utovar: spiskovi za utovar (filter po statusu) i pravljenje novog spiska.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/spiskovi.php';

$admin = zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    [$p, $greska] = spisak_zaglavlje_iz_posta();
    if ($greska !== null) {
        flash_dodaj('greska', $greska);
        preusmeri('admin/utovar.php?' . http_build_query(['novi' => 1, 'kupac' => $p['kupac'], 'datum' => $p['datum'], 'vozilo' => $p['vozilo'], 'vozac' => $p['vozac'], 'napomena' => $p['napomena']]));
    }
    $id = db_trans(static function () use ($p, $admin): int {
        $kupac_id = kupac_id_za($p['kupac']);
        $sad = sada();
        db_run(
            "INSERT INTO spiskovi (kupac_id, datum, vozilo, vozac, napomena, status, napravio_id, napravljen, izmenjen)
             VALUES (?, ?, ?, ?, ?, 'nacrt', ?, ?, ?)",
            [$kupac_id, $p['datum'], $p['vozilo'] ?: null, $p['vozac'] ?: null, $p['napomena'] ?: null, (int)$admin['id'], $sad, $sad]
        );
        return db_id();
    });
    spisak_dnevnik($id, 'napravljen (kupac ' . $p['kupac'] . ', utovar ' . datum_srp($p['datum']) . ')');
    flash_dodaj('uspeh', 'Spisak #' . $id . ' je napravljen. Dodajte stavke.');
    preusmeri('admin/spisak.php?id=' . $id);
}

$filteri = ['aktivni' => 'Aktivni', 'utovareno' => 'Utovareno', 'otkazano' => 'Otkazano', 'svi' => 'Svi'];
$filter = get_str('f', 12);
$filter = isset($filteri[$filter]) ? $filter : 'aktivni';
$uslov = [
    'aktivni'   => "sp.status IN ('nacrt','za_utovar')",
    'utovareno' => "sp.status = 'utovareno'",
    'otkazano'  => "sp.status = 'otkazano'",
    'svi'       => '1 = 1',
][$filter];
$redosled = $filter === 'aktivni' ? 'sp.datum ASC, sp.id ASC' : 'sp.datum DESC, sp.id DESC';

$spiskovi = db_all('SELECT ' . SPISAK_POLJA . ' ' . SPISAK_IZ . ' WHERE ' . $uslov . ' ORDER BY ' . $redosled . ' LIMIT 100');
$brojevi = [];
foreach (db_all("SELECT status, COUNT(*) AS n FROM spiskovi GROUP BY status") as $r) {
    $brojevi[$r['status']] = (int)$r['n'];
}

$novi_otvoren = get_int('novi') === 1 || !$spiskovi;
$kupci = db_all('SELECT naziv FROM kupci ORDER BY (poslednja_prodaja IS NULL), poslednja_prodaja DESC, naziv LIMIT 300');
$nedavni = array_slice(array_column($kupci, 'naziv'), 0, 6);
$unapred = [
    'kupac' => get_str('kupac', 120), 'datum' => get_str('datum', 10) ?: date('Y-m-d'),
    'vozilo' => get_str('vozilo', 60), 'vozac' => get_str('vozac', 60), 'napomena' => get_str('napomena', 255),
];

ui_start('Utovar', ['nav' => 'admin', 'aktivno' => 'utovar', 'js' => ['assets/js/prodaja.js']]);
?>
<details class="kartica" <?= $novi_otvoren ? 'open' : '' ?>>
    <summary class="kartica-naslov"><h2><?= ikona('plus') ?> Novi spisak za utovar</h2></summary>
    <form method="post" autocomplete="off" action="<?= e(url('admin/utovar.php')) ?>">
        <?= csrf_polje() ?>
        <div class="red-polja">
            <label for="kupac">Kupac</label>
            <input class="polje" id="kupac" name="kupac" type="text" maxlength="120" list="lista-kupaca" required
                   placeholder="Upišite ime ili izaberite ranijeg" value="<?= e($unapred['kupac']) ?>">
            <datalist id="lista-kupaca">
                <?php foreach ($kupci as $k): ?><option value="<?= e($k['naziv']) ?>"><?php endforeach; ?>
            </datalist>
            <?php if ($nedavni): ?>
                <div class="izbor-mreza razmak-gore" aria-label="Raniji kupci">
                    <?php foreach ($nedavni as $n): ?>
                        <button type="button" class="izbor-dugme" data-kupac="<?= e($n) ?>" aria-pressed="false"><?= e($n) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="red-polja">
            <label for="datum">Datum utovara</label>
            <input class="polje" id="datum" name="datum" type="date" required value="<?= e($unapred['datum']) ?>">
        </div>
        <div class="red-polja-2 red-polja">
            <div>
                <label for="vozilo">Vozilo</label>
                <input class="polje" id="vozilo" name="vozilo" type="text" maxlength="60" list="lista-vozila" placeholder="npr. NS 123-AB" value="<?= e($unapred['vozilo']) ?>">
                <datalist id="lista-vozila"><?php foreach (spisak_predlozi('vozilo') as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
            </div>
            <div>
                <label for="vozac">Vozač</label>
                <input class="polje" id="vozac" name="vozac" type="text" maxlength="60" list="lista-vozaca" value="<?= e($unapred['vozac']) ?>">
                <datalist id="lista-vozaca"><?php foreach (spisak_predlozi('vozac') as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
            </div>
        </div>
        <div class="red-polja">
            <label for="napomena">Napomena (nije obavezno)</label>
            <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" placeholder="npr. broj otpremnice, adresa istovara" value="<?= e($unapred['napomena']) ?>">
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit">Napravi spisak</button>
    </form>
</details>

<div class="cipovi" role="group" aria-label="Spiskovi">
    <?php foreach ($filteri as $k => $naziv):
        $n = match ($k) {
            'aktivni' => ($brojevi['nacrt'] ?? 0) + ($brojevi['za_utovar'] ?? 0),
            'svi' => array_sum($brojevi),
            default => $brojevi[$k] ?? 0,
        }; ?>
        <a class="cip" href="<?= e(url('admin/utovar.php?f=' . $k)) ?>" <?= $filter === $k ? 'aria-current="true"' : '' ?>><?= e($naziv) ?> (<?= (int)$n ?>)</a>
    <?php endforeach; ?>
</div>

<?php if (!$spiskovi): ?>
    <div class="kartica"><p class="bez-margine pomoc">Nema spiskova<?= $filter === 'aktivni' ? ' koji čekaju utovar' : '' ?>.</p></div>
<?php else: ?>
    <div class="spiskovi">
        <?php foreach ($spiskovi as $sp):
            $stavke = spisak_stavke((int)$sp['id']);
            $z = spisak_zbir($stavke, $sp['status'] === 'utovareno'); ?>
            <a class="spisak-kartica spisak-<?= e($sp['status']) ?>" href="<?= e(url('admin/spisak.php?id=' . (int)$sp['id'])) ?>">
                <span class="spisak-glava">
                    <strong><?= e($sp['kupac']) ?></strong>
                    <span class="znacka status-<?= e($sp['status']) ?>"><?= e(SPISAK_STATUSI[$sp['status']]) ?></span>
                </span>
                <span class="spisak-pod">
                    #<?= (int)$sp['id'] ?> · <?= e(dan_u_nedelji((string)$sp['datum'])) ?> <?= e(datum_srp((string)$sp['datum'])) ?>
                    <?= $sp['vozilo'] ? ' · ' . e($sp['vozilo']) : '' ?><?= $sp['vozac'] ? ' · ' . e($sp['vozac']) : '' ?>
                </span>
                <span class="spisak-zbir">
                    <?= (int)$z['stavki'] ?> <?= $z['stavki'] === 1 ? 'stavka' : ($z['stavki'] >= 2 && $z['stavki'] <= 4 ? 'stavke' : 'stavki') ?>
                    <?php if ($z['palete'] > 0): ?> · <?= e(palete_zbir_tekst($z['palete'])) ?><?php endif; ?>
                    <?php if ($z['kg'] > 0): ?> · <?= e(kg_tekst($z['kg'])) ?><?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="pomoc razmak-gore">Prodaja bez spiska (izuzetak, npr. neko dođe po par vreća):
    <a href="<?= e(url('admin/prodaja.php')) ?>">Ručna prodaja</a>.</p>
<?php
ui_end();
