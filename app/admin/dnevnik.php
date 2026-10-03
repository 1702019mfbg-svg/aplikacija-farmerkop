<?php
/**
 * Administrator – dnevnik izmena: poslednje izmene, brisanja i vraćanja unosa (ko, kad, šta).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';

zahtevaj_ulogu('admin');

$redovi = db_all(
    "SELECT d.*, k.ime FROM dnevnik d LEFT JOIN korisnici k ON k.id = d.korisnik_id
     WHERE d.objekat = 'unos' ORDER BY d.id DESC LIMIT 100"
);

ui_start('Dnevnik izmena', ['nav' => 'admin', 'aktivno' => 'istorija']);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/istorija.php')) ?>"><?= ikona('nazad') ?> Istorija</a></p>

<div class="kartica">
    <?php if (!$redovi): ?>
        <p class="bez-margine">Još nema izmena ni brisanja.</p>
    <?php else: ?>
        <p class="pomoc">Poslednjih <?= count($redovi) ?> promena.</p>
        <ul class="lista">
            <?php foreach ($redovi as $d): ?>
                <li>
                    <strong><?= e(AKCIJE_DNEVNIKA[$d['akcija']] ?? $d['akcija']) ?></strong>
                    – <?= e($d['ime'] ?? 'nepoznat') ?>, <?= e(datum_vreme_srp((string)$d['vreme'])) ?>
                    · <a href="<?= e(url('admin/unos.php?id=' . (int)$d['objekat_id'])) ?>">unos #<?= (int)$d['objekat_id'] ?></a>
                    <?php $promene = dnevnik_promene($d['detalji']); ?>
                    <?php if ($promene): ?>
                        <ul class="promene"><?php foreach ($promene as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php
ui_end();
