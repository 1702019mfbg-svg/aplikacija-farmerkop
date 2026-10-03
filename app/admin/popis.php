<?php
/**
 * Administrator – Podešavanja → Popis: ručna korekcija stanja uz obaveznu napomenu.
 * Upisuje se prebrojano stanje; program sam izračuna razliku i upiše je kao "korekciju" u istoriju.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

$admin = zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $napomena = post_str('napomena', 150);
    $unos = $_POST['prebrojano'] ?? [];
    $stavke = [];
    $greska = null;

    if (mb_strlen($napomena) < 3) {
        $greska = 'Upišite napomenu (npr. „Popis 30.09.“). Obavezna je da bi se znalo zašto je stanje menjano.';
    } elseif (!is_array($unos)) {
        $greska = 'Nema unetih vrednosti.';
    } else {
        foreach ($unos as $sid => $vrednost) {
            $vrednost = is_string($vrednost) ? trim($vrednost) : '';
            if ($vrednost === '') {
                continue;       // prazno polje = ne menjaj
            }
            if (!ctype_digit($vrednost) || (int)$vrednost > 99999999) {
                $greska = 'Prebrojano stanje mora biti ceo broj (0 ili veći).';
                break;
            }
            if (!is_int($sid) && !ctype_digit((string)$sid)) {
                continue;
            }
            $stavke[(int)$sid] = (int)$vrednost;
        }
        if ($greska === null && !$stavke) {
            $greska = 'Niste upisali nijedno prebrojano stanje.';
        }
    }

    if ($greska !== null) {
        flash_dodaj('greska', $greska);
    } else {
        $promenjeno = 0;
        $isto = 0;
        $opis = [];
        foreach ($stavke as $sid => $prebrojano) {
            $sku = sku_podaci($sid);
            if ($sku === null) {
                continue;
            }
            $rez = popis_postavi($sid, $prebrojano, $napomena, (int)$admin['id']);
            if ($rez['promena']) {
                $promenjeno++;
                $opis[] = sku_naziv($sku) . ': ' . broj($rez['bilo']) . ' → ' . broj($prebrojano) . ' (' . ($rez['razlika'] > 0 ? '+' : '−') . broj(abs($rez['razlika'])) . ')';
            } else {
                $isto++;
            }
        }
        if ($promenjeno > 0) {
            dnevnik_podesavanje('Popis („' . $napomena . '“): ' . implode('; ', array_slice($opis, 0, 20)));
        }
        flash_dodaj(
            $promenjeno > 0 ? 'uspeh' : 'info',
            $promenjeno > 0
                ? 'Popis je sačuvan. Promenjeno: ' . $promenjeno . ($isto ? ', bez razlike: ' . $isto : '') . '. ' . implode(' · ', array_slice($opis, 0, 6)) . (count($opis) > 6 ? ' …' : '')
                : 'Prebrojano stanje je isto kao u programu – ništa nije menjano.'
        );
    }
    preusmeri('admin/popis.php');
}

$redovi = db_all(
    'SELECT s.id AS sku_id, s.po_paleti, s.aktivan AS sku_aktivan, a.naziv AS artikal, a.oznaka, a.aktivan AS artikal_aktivan,
            k.aktivan AS kat_aktivna, v.naziv AS varijanta, v.aktivan AS var_aktivna, p.kolicina AS pak_kolicina, p.jedinica, p.aktivan AS pak_aktivno
     FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
     JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
     ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina'
);
$stanja = stanja_svih();
$po_artiklu = [];
foreach ($redovi as $r) {
    $st = $stanja[(int)$r['sku_id']] ?? 0;
    $aktivan = $r['sku_aktivan'] && $r['artikal_aktivan'] && $r['kat_aktivna'] && $r['pak_aktivno'] && ($r['varijanta'] === null || $r['var_aktivna']);
    if (!$aktivan && $st === 0) {
        continue;
    }
    $po_artiklu[$r['artikal']]['oznaka'] = $r['oznaka'];
    $po_artiklu[$r['artikal']]['redovi'][] = $r + ['stanje' => $st, 'aktivan' => $aktivan];
}

$poslednji = db_all(
    "SELECT u.id, u.kolicina, u.napomena, u.nastalo, r.ime AS radnik, " . SKU_POLJA . "
     FROM unosi u " . SKU_SPOJ . " JOIN korisnici r ON r.id = u.korisnik_id
     WHERE u.tip = 'korekcija' AND u.obrisan = 0 ORDER BY u.nastalo DESC, u.id DESC LIMIT 15"
);

ui_start('Popis stanja', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a></p>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/popis.php')) ?>"
      data-potvrda="Sačuvati popis? Stanje izabranih artikala biće postavljeno na prebrojano.">
    <?= csrf_polje() ?>
    <div class="kartica-naslov"><h2>Prebrojano stanje</h2></div>
    <p class="pomoc">Upišite koliko ste <strong>stvarno prebrojali</strong> (u komadima) samo za artikle koje želite da ispravite; prazna polja ostaju kako jesu. Razlika se upisuje u istoriju kao korekcija.</p>
    <div class="red-polja">
        <label for="napomena">Napomena (obavezna)</label>
        <input class="polje" id="napomena" name="napomena" type="text" maxlength="150" required placeholder="npr. Popis 30.09.">
    </div>

    <?php foreach ($po_artiklu as $naziv => $grupa): ?>
        <h3 class="popis-artikal"><?= e($naziv) ?><?php if ($grupa['oznaka']): ?> <span class="znacka znacka-pl"><?= e($grupa['oznaka']) ?></span><?php endif; ?></h3>
        <?php foreach ($grupa['redovi'] as $r): ?>
            <div class="popis-red<?= $r['aktivan'] ? '' : ' radnik-ugasen' ?>">
                <label for="p-<?= (int)$r['sku_id'] ?>">
                    <?= e(($r['varijanta'] !== null ? $r['varijanta'] . ' · ' : '') . pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica'])) ?>
                    <?php if (!$r['aktivan']): ?><span class="znacka">isključen</span><?php endif; ?>
                    <small class="pomoc">sada: <strong><?= e(broj($r['stanje'])) ?></strong><?php $pal = paletni_prikaz($r['stanje'], $r['po_paleti'] === null ? null : (int)$r['po_paleti']); echo $pal !== '' ? ' (' . e($pal) . ')' : ''; ?></small>
                </label>
                <input class="polje" id="p-<?= (int)$r['sku_id'] ?>" name="prebrojano[<?= (int)$r['sku_id'] ?>]" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="8" placeholder="—" aria-label="Prebrojano">
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$po_artiklu): ?><p class="pomoc">Nema artikala.</p><?php endif; ?>

    <button class="btn btn-primary btn-veliko btn-blok razmak-gore" type="submit">Sačuvaj popis</button>
</form>

<section class="kartica" aria-labelledby="posl-naslov">
    <div class="kartica-naslov"><h2 id="posl-naslov">Poslednje korekcije</h2></div>
    <?php if (!$poslednji): ?>
        <p class="pomoc bez-margine">Još nije bilo korekcija.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($poslednji as $u): ?>
                <li>
                    <div class="unos-red">
                        <span class="naziv"><?= e(sku_naziv($u)) ?></span>
                        <span class="kol"><?= ((int)$u['kolicina'] >= 0 ? '+' : '−') . e(broj(abs((int)$u['kolicina']))) ?> kom</span>
                        <span class="pod"><?= e(datum_kratko((string)$u['nastalo'])) ?> · <?= e($u['radnik']) ?> · <?= e($u['napomena'] ?? '') ?></span>
                        <span class="kol-pod"><a href="<?= e(url('admin/unos.php?id=' . (int)$u['id'])) ?>">detalji</a></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
ui_end();
