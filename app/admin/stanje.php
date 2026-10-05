<?php
/**
 * Administrator – Stanje zaliha po artiklu, varijanti i pakovanju.
 * Stanje = proizvedeno − prodato (+ korekcije popisa). Crveno kad je ispod minimuma.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

zahtevaj_ulogu('admin');

$samo_nisko = get_int('nisko') === 1;

$redovi = db_all(
    'SELECT s.id AS sku_id, s.po_paleti, s.po_paketu, s.min_zaliha, s.aktivan AS sku_aktivan, '
    . 'a.id AS artikal_id, a.naziv AS artikal, a.oznaka, a.aktivan AS artikal_aktivan, '
    . 'k.id AS kat_id, k.naziv AS kategorija, k.aktivan AS kat_aktivna, '
    . 'v.naziv AS varijanta, v.aktivan AS var_aktivna, p.kolicina AS pak_kolicina, p.jedinica, p.aktivan AS pak_aktivno '
    . 'FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id '
    . 'JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id '
    . 'ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina'
);
$stanja = stanja_svih();
$danas = promet_danas();

$kategorije = [];
$ukupno_proizvedeno = 0;
$ukupno_prodato = 0;
$ispod_minimuma = 0;

foreach ($redovi as $r) {
    $id = (int)$r['sku_id'];
    $st = $stanja[$id] ?? 0;
    $aktivan = $r['sku_aktivan'] && $r['artikal_aktivan'] && $r['kat_aktivna'] && $r['pak_aktivno']
        && ($r['varijanta'] === null || $r['var_aktivna']);
    if (!$aktivan && $st === 0) {
        continue;       // isključeni SKU-ovi se prikazuju samo dok na stanju još ima nečega
    }
    $min = (int)$r['min_zaliha'];
    $nisko = $min > 0 && $st < $min;
    $proizv = $danas[$id]['proizvedeno'] ?? 0;
    $prodato = $danas[$id]['prodato'] ?? 0;
    $ukupno_proizvedeno += $proizv;
    $ukupno_prodato += $prodato;
    if ($nisko && $aktivan) {
        $ispod_minimuma++;
    }
    if ($samo_nisko && !$nisko) {
        continue;
    }

    $kid = (int)$r['kat_id'];
    $aid = (int)$r['artikal_id'];
    $kategorije[$kid]['naziv'] = $r['kategorija'];
    $a = &$kategorije[$kid]['artikli'][$aid];
    $a['naziv'] = $r['artikal'];
    $a['oznaka'] = $r['oznaka'];
    $a['aktivan'] = (bool)$r['artikal_aktivan'];
    $a['ukupno'] = ($a['ukupno'] ?? 0) + $st;
    $a['nisko'] = ($a['nisko'] ?? 0) + ($nisko ? 1 : 0);
    $a['sku'][] = [
        'naziv'    => ($r['varijanta'] !== null ? $r['varijanta'] . ' · ' : '') . pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica']),
        'stanje'   => $st,
        'min'      => $min,
        'nisko'    => $nisko,
        'po'       => $r['po_paleti'] === null ? null : (int)$r['po_paleti'],
        'pp'       => $r['po_paketu'] === null ? null : (int)$r['po_paketu'],
        'proizv'   => $proizv,
        'prodato'  => $prodato,
        'ukupno_kolicina' => $st * (float)$r['pak_kolicina'],
        'jedinica' => (string)$r['jedinica'],
        'aktivan'  => (bool)$aktivan,
    ];
    unset($a);
}

ui_start('Stanje', ['nav' => 'admin', 'aktivno' => 'stanje']);
?>
<div class="stat-mreza tri">
    <div class="stat stat-uspeh">
        <span class="broj"><?= e(broj($ukupno_proizvedeno)) ?></span>
        <span class="opis">proizvedeno danas (kom)</span>
    </div>
    <div class="stat">
        <span class="broj"><?= e(broj($ukupno_prodato)) ?></span>
        <span class="opis">prodato danas (kom)</span>
    </div>
    <a class="stat <?= $ispod_minimuma > 0 ? 'stat-upozorenje' : '' ?> stat-link" href="<?= e(url('admin/stanje.php' . ($samo_nisko ? '' : '?nisko=1'))) ?>">
        <span class="broj"><?= $ispod_minimuma ?></span>
        <span class="opis">ispod minimuma<?= $samo_nisko ? ' ✓ filter' : '' ?></span>
    </a>
</div>

<?php if ($samo_nisko): ?>
    <p><a class="btn btn-mali" href="<?= e(url('admin/stanje.php')) ?>">← Prikaži sve artikle</a></p>
<?php endif; ?>

<?php if (!$kategorije): ?>
    <div class="kartica"><p class="bez-margine"><?= $samo_nisko ? 'Nijedan artikal nije ispod minimuma.' : 'Nema artikala. Dodajte ih u Podešavanjima.' ?></p></div>
<?php endif; ?>

<?php foreach ($kategorije as $kat): ?>
    <h2 class="kat-naslov"><?= e($kat['naziv']) ?></h2>
    <?php foreach ($kat['artikli'] as $a): ?>
        <details class="kartica artikal-kartica" open>
            <summary class="artikal-glava">
                <h3>
                    <?= e($a['naziv']) ?>
                    <?php if ($a['oznaka']): ?><span class="znacka znacka-pl"><?= e($a['oznaka']) ?></span><?php endif; ?>
                    <?php if (!$a['aktivan']): ?><span class="znacka">isključen</span><?php endif; ?>
                </h3>
                <span class="ukupno">
                    <?= e(broj($a['ukupno'])) ?> kom
                    <?php if ($a['nisko'] > 0): ?><span class="znacka znacka-nisko">ispod min.: <?= (int)$a['nisko'] ?></span><?php endif; ?>
                </span>
            </summary>
            <?php foreach ($a['sku'] as $s): ?>
                <div class="sku-red<?= $s['nisko'] ? ' nisko' : '' ?>">
                    <div class="sku-glavno">
                        <div class="sku-ime"><?= e($s['naziv']) ?><?php if (!$s['aktivan']): ?> <span class="znacka">isključen</span><?php endif; ?></div>
                        <?php $pal = razlaganje($s['stanje'], $s['po'], $s['pp']); ?>
                        <?php if ($pal !== ''): ?><div class="sku-pal">= <?= e($pal) ?></div><?php endif; ?>
                        <?php if ($s['nisko']): ?>
                            <div class="sku-upoz"><?= ikona('upozorenje', 'ikona ikona-mala') ?> ispod minimuma (<?= e(broj($s['min'])) ?>)</div>
                        <?php endif; ?>
                    </div>
                    <div class="sku-stanje"><?= e(broj($s['stanje'])) ?></div>
                    <div class="sku-meta">
                        danas: +<?= e(broj($s['proizv'])) ?> / −<?= e(broj($s['prodato'])) ?>
                        · ukupno <?= e(broj($s['ukupno_kolicina'], $s['ukupno_kolicina'] == floor($s['ukupno_kolicina']) ? 0 : 1)) ?> <?= e($s['jedinica']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php
ui_end();
