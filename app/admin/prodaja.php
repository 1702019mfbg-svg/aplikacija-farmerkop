<?php
/**
 * Administrator – unos prodaje (kupac, artikal, pakovanje, količina) i lista poslednjih prodaja.
 * Prodaja veća od stanja se ne dozvoljava.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/izbor.php';

$admin = zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $kupac = post_str('kupac', 120);
    $sku_id = post_int('sku_id');
    $sku = $sku_id > 0 ? sku_aktivan($sku_id) : null;
    $nazad = 'admin/prodaja.php?' . http_build_query(['kupac' => $kupac, 's' => $sku_id > 0 ? $sku_id : '']);

    if ($kupac === '') {
        flash_dodaj('greska', 'Upišite ili izaberite kupca.');
    } elseif ($sku === null) {
        flash_dodaj('greska', 'Izaberite artikal, pa pakovanje (i boju ili granulaciju ako je ima).');
    } else {
        $kol = procitaj_kolicinu($sku);
        if (isset($kol['greska'])) {
            flash_dodaj('greska', $kol['greska']);
        } else {
            $stanje = stanje_sku($sku_id);
            if ($kol['komadi'] > $stanje) {
                flash_dodaj('greska', 'Nema dovoljno na stanju za ' . sku_naziv($sku) . ': tražite ' . broj($kol['komadi'])
                    . ' kom, a na stanju je ' . broj($stanje) . ' kom.');
            } else {
                $kupac_id = kupac_id_za($kupac);
                $rez = unos_dodaj('prodaja', $sku_id, $kol['komadi'], $kol['palete'], (int)$admin['id'], $kupac_id, post_str('napomena', 255), post_str('kljuc', 32), null, $kol['paketi']);
                if ($rez['ok'] && !empty($rez['duplikat'])) {
                    flash_dodaj('info', 'Ova prodaja je već sačuvana.');
                } elseif ($rez['ok']) {
                    db_run('UPDATE kupci SET poslednja_prodaja = ? WHERE id = ?', [sada(), $kupac_id]);
                    flash_dodaj('uspeh', 'Prodaja sačuvana: ' . $kupac . ' – ' . sku_naziv($sku) . ' – ' . kolicina_tekst($kol['komadi'], $kol['palete'], $kol['paketi']));
                    $nazad = 'admin/prodaja.php?' . http_build_query(['kupac' => $kupac]);
                } elseif (!empty($rez['nedovoljno'])) {
                    flash_dodaj('greska', 'Nema dovoljno na stanju: na stanju je ' . broj((int)$rez['stanje']) . ' kom.');
                } else {
                    flash_dodaj('greska', (string)($rez['greska'] ?? 'Prodaja nije sačuvana.'));
                }
            }
        }
    }
    preusmeri($nazad);
}

$kupci = db_all('SELECT naziv FROM kupci ORDER BY (poslednja_prodaja IS NULL), poslednja_prodaja DESC, naziv LIMIT 300');
$nedavni = array_slice(array_column($kupci, 'naziv'), 0, 6);

$prodaje = db_all(
    "SELECT u.id, u.tip, u.kolicina, u.palete, u.paketi, u.napomena, u.nastalo, ku.naziv AS kupac, r.ime AS radnik, " . SKU_POLJA . "
     FROM unosi u " . SKU_SPOJ . "
     JOIN korisnici r ON r.id = u.korisnik_id
     LEFT JOIN kupci ku ON ku.id = u.kupac_id
     WHERE u.obrisan = 0 AND u.tip IN ('prodaja', 'kucna_prodaja')
     ORDER BY u.nastalo DESC, u.id DESC LIMIT 30"
);
$danas_prodato = (int)db_val(
    "SELECT COALESCE(SUM(kolicina), 0) FROM unosi WHERE obrisan = 0 AND tip IN ('prodaja', 'kucna_prodaja') AND nastalo >= ? AND nastalo < ?",
    [danas_od(), danas_do()]
);

$izabran_sku = get_int('s');
if ($izabran_sku > 0 && sku_aktivan($izabran_sku) === null) {
    $izabran_sku = 0;
}
$unapred_kupac = get_str('kupac', 120);

ui_start('Prodaja', ['nav' => 'admin', 'aktivno' => 'prodaja', 'js' => ['assets/js/unos.js', 'assets/js/prodaja.js']]);
?>
<div class="stat-mreza">
    <div class="stat">
        <span class="broj"><?= e(broj($danas_prodato)) ?></span>
        <span class="opis">danas prodato (kom, sa kućnom prodajom)</span>
    </div>
</div>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/prodaja.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="kljuc" value="<?= e(bin2hex(random_bytes(16))) ?>">
    <div class="kartica-naslov"><h2>Nova prodaja</h2></div>

    <div class="red-polja">
        <label for="kupac">Kupac</label>
        <input class="polje" id="kupac" name="kupac" type="text" maxlength="120" list="lista-kupaca" required
               placeholder="Upišite ime ili izaberite ranijeg" value="<?= e($unapred_kupac) ?>">
        <datalist id="lista-kupaca">
            <?php foreach ($kupci as $k): ?><option value="<?= e($k['naziv']) ?>"><?php endforeach; ?>
        </datalist>
        <?php if ($nedavni): ?>
            <div class="izbor-mreza razmak-gore" aria-label="Raniji kupci">
                <?php foreach ($nedavni as $n): ?>
                    <button type="button" class="izbor-dugme" data-kupac="<?= e($n) ?>" aria-pressed="<?= $n === $unapred_kupac ? 'true' : 'false' ?>"><?= e($n) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?= izbor_html(katalog_za_izbor(true), $izabran_sku, true) ?>

    <div class="red-polja">
        <label for="napomena">Napomena (nije obavezno)</label>
        <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" placeholder="npr. broj otpremnice">
    </div>
    <button class="btn btn-primary btn-veliko btn-blok" type="submit" data-sacuvaj disabled>Sačuvaj prodaju</button>
</form>

<section class="kartica" aria-labelledby="poslednje">
    <div class="kartica-naslov"><h2 id="poslednje">Poslednje prodaje</h2></div>
    <?php if (!$prodaje): ?>
        <p class="pomoc bez-margine">Još nema prodaje.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($prodaje as $u): ?>
                <li>
                    <div class="unos-red">
                        <span class="naziv"><?= e(sku_naziv($u)) ?></span>
                        <span class="kol">−<?= e(broj((int)$u['kolicina'])) ?> kom</span>
                        <span class="pod">
                            <?= e(datum_kratko((string)$u['nastalo'])) ?> ·
                            <?php if ($u['tip'] === 'kucna_prodaja'): ?>
                                <span class="znacka znacka-kucna_prodaja">kućna prodaja</span> <?= e($u['radnik']) ?>
                            <?php else: ?>
                                <strong><?= e($u['kupac'] ?? '—') ?></strong>
                            <?php endif; ?>
                            <?= $u['napomena'] ? ' · ' . e($u['napomena']) : '' ?>
                        </span>
                        <span class="kol-pod"><?= e(nacin_unosa_tekst($u)) ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
ui_end();
