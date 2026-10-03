<?php
/**
 * Zajednički izgled stranica: zaglavlje, donja navigacija, ikone.
 */
declare(strict_types=1);

/** Jednostavne ikone (24×24, linija). */
function ikona(string $ime, string $klasa = 'ikona'): string
{
    static $putanje = [
        'stanje'       => '<path d="M12 3 3 7.5l9 4.5 9-4.5L12 3z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
        'prodaja'      => '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2 3h3l2.6 12.2h11.2L21 7H6"/>',
        'istorija'     => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v5l3 2"/>',
        'radnici'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17" cy="9" r="2.5"/><path d="M17.5 14c2.5 0 4 1.8 4 4.5"/>',
        'podesavanja'  => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'kutija'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'kuca'         => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'tema'         => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor"/>',
        'odjava'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'kanta'        => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m6 6 1 14h10l1-14"/><path d="M10 10v6M14 10v6"/>',
        'olovka'       => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="m13.5 6.5 4 4"/>',
        'preuzmi'      => '<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 21h16"/>',
        'upozorenje'   => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v5"/><path d="M12 18v.01"/>',
        'kvacica'      => '<path d="m4 12.5 5 5L20 6.5"/>',
        'nazad'        => '<path d="m15 5-7 7 7 7"/>',
        'plus'         => '<path d="M12 5v14M5 12h14"/>',
    ];
    $p = $putanje[$ime] ?? '';
    return '<svg class="' . e($klasa) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

function nav_stavke(string $vrsta): array
{
    if ($vrsta === 'admin') {
        return [
            'stanje'      => ['Stanje', 'admin/stanje.php', 'stanje'],
            'prodaja'     => ['Prodaja', 'admin/prodaja.php', 'prodaja'],
            'istorija'    => ['Istorija', 'admin/istorija.php', 'istorija'],
            'radnici'     => ['Radnici', 'admin/radnici.php', 'radnici'],
            'podesavanja' => ['Podešavanja', 'admin/podesavanja.php', 'podesavanja'],
        ];
    }
    return [
        'proizvodnja' => ['Proizvodnja', 'radnik/index.php', 'kutija'],
        'kucna'       => ['Kućna prodaja', 'radnik/prodaja.php', 'kuca'],
    ];
}

/**
 * Počinje HTML stranicu.
 * Opcije: telo (klasa), nav ('admin'|'radnik'), aktivno (ključ u navigaciji),
 *         bez_zaglavlja (bool), nazad (adresa), js (spisak dodatnih skripti).
 */
function ui_start(string $naslov, array $o = []): void
{
    $GLOBALS['__ui'] = $o;
    $korisnik = empty($o['bez_zaglavlja']) ? auth_user() : null;
    $telo = trim('app ' . ($o['telo'] ?? '') . (isset($o['nav']) ? ' ima-nav' : ''));
    ?>
<!doctype html>
<html lang="sr-Latn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#5c3d2e" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1b1713" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAZIV) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e($naslov) ?> · <?= e(APP_NAZIV) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('assets/icons/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= e(asset('assets/icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<script src="<?= e(asset('assets/js/tema.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer data-sw="<?= e(url('sw.js')) ?>" data-scope="<?= e(url('')) ?>"></script>
<?php foreach (($o['js'] ?? []) as $skripta): ?>
<script src="<?= e(asset($skripta)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="<?= e($telo) ?>">
<?php if (empty($o['bez_zaglavlja'])): ?>
<header class="zaglavlje">
    <div class="zaglavlje-tekst">
        <span class="brend"><?= e(APP_NAZIV) ?><?php if ($korisnik): ?> · <?= e($korisnik['ime']) ?><?php endif; ?></span>
        <h1><?= e($naslov) ?></h1>
    </div>
    <div class="zaglavlje-akcije">
        <button type="button" class="ikona-dugme" data-tema aria-label="Promeni temu (automatski, svetla, tamna)" title="Tema"><?= ikona('tema') ?></button>
        <?php if ($korisnik): ?>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="forma-u-liniji">
            <?= csrf_polje() ?>
            <button type="submit" class="ikona-dugme" aria-label="Odjava" title="Odjava"><?= ikona('odjava') ?></button>
        </form>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>
<main class="sadrzaj">
<?= flash_html() ?>
<?php
}

/** Završava stranicu; za prijavljene ispisuje donju navigaciju. */
function ui_end(): void
{
    $o = $GLOBALS['__ui'] ?? [];
    echo "</main>\n";
    if (isset($o['nav'])) {
        $aktivno = (string)($o['aktivno'] ?? '');
        echo '<nav class="donja-nav" aria-label="Glavna navigacija">';
        foreach (nav_stavke((string)$o['nav']) as $kljuc => [$tekst, $adresa, $ikona]) {
            $akt = $kljuc === $aktivno;
            echo '<a href="' . e(url($adresa)) . '" class="nav-stavka' . ($akt ? ' aktivna' : '') . '"'
                . ($akt ? ' aria-current="page"' : '') . '>' . ikona($ikona) . '<span>' . e($tekst) . '</span></a>';
        }
        echo "</nav>\n";
    }
    echo "</body>\n</html>\n";
}
