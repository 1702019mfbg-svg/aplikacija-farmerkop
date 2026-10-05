<?php
/**
 * Administrator – Podešavanja: ulaz u artikle, pakovanja, popis, šifru i dnevnik.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

$admin = zahtevaj_ulogu('admin');

$stavke = [
    ['admin/artikli.php', 'kutija', 'Artikli, boje i granulacije', 'Dodavanje i izmena artikala, kategorija, boja, granulacija, minimuma i broja komada po paleti.'],
    ['admin/pakovanja.php', 'stanje', 'Pakovanja', 'Litraža i težina (5 l, 10 l, 20 kg…).'],
    ['admin/popis.php', 'kvacica', 'Popis i korekcija stanja', 'Upišite prebrojano stanje; razlika se upisuje sa napomenom.'],
    ['admin/uvoz.php', 'otpremi', 'Uvoz stanja iz fajla', 'CSV ili tabela iz Bluesofta / Excela: stanje svih artikala odjednom.'],
    ['admin/sifra.php', 'upozorenje', 'Promena administratorske šifre', 'Nova šifra za prijavu administratora.'],
    ['admin/dnevnik.php', 'istorija', 'Dnevnik izmena', 'Ko je i kad menjao unose i podešavanja.'],
    ['admin/izvoz.php?od=&do=', 'preuzmi', 'Izvoz svih unosa (CSV)', 'Kompletna istorija za Excel.'],
];
$broj_unosa = (int)db_val('SELECT COUNT(*) FROM unosi WHERE obrisan = 0');

ui_start('Podešavanja', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
?>
<div class="meni-lista">
    <?php foreach ($stavke as [$adresa, $ikona, $naslov, $opis]): ?>
        <a class="meni-stavka" href="<?= e(url($adresa)) ?>">
            <?= ikona($ikona) ?>
            <span><strong><?= e($naslov) ?></strong><small><?= e($opis) ?></small></span>
            <span class="meni-strelica" aria-hidden="true">›</span>
        </a>
    <?php endforeach; ?>
</div>

<div class="kartica razmak-gore">
    <h3>O aplikaciji</h3>
    <p class="pomoc bez-margine">
        <?= e(APP_NAZIV) ?> – evidencija proizvodnje, prodaje i zaliha<br>
        Prijavljeni ste kao: <strong><?= e($admin['ime']) ?></strong> (<?= e($admin['korisnicko_ime'] ?? '') ?>)<br>
        Unosa u bazi: <?= e(broj($broj_unosa)) ?> · Vremenska zona: <?= e(date_default_timezone_get()) ?> · Server vreme: <?= e(date('d.m.Y. H:i')) ?>
    </p>
</div>
<?php
ui_end();
