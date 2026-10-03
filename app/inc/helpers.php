<?php
/**
 * Pomoćne funkcije: ispis, adrese, formatiranje, čitanje unosa.
 */
declare(strict_types=1);

/** Bezbedan ispis u HTML (sprečava XSS). */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON koji je bezbedno ubaciti u <script type="application/json">. */
function json_za_html(mixed $podaci): string
{
    return json_encode($podaci, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function je_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Putanja ispred aplikacije ('' ako je u korenu poddomena). */
function app_base(): string
{
    static $baza = null;
    if ($baza !== null) {
        return $baza;
    }
    if (APP_BASE_PUTANJA !== null) {
        return $baza = rtrim((string)APP_BASE_PUTANJA, '/');
    }
    $doc = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $app = realpath(APP_ROOT);
    if ($doc !== false && $app !== false && str_starts_with($app, $doc)) {
        return $baza = rtrim(str_replace('\\', '/', substr($app, strlen($doc))), '/');
    }
    return $baza = '';
}

function url(string $putanja = ''): string
{
    return app_base() . '/' . ltrim($putanja, '/');
}

/** Adresa statičnog fajla sa verzijom (da pregledač uzme novu kopiju kad se fajl promeni). */
function asset(string $putanja): string
{
    $f = APP_ROOT . '/' . ltrim($putanja, '/');
    return url($putanja) . '?v=' . (is_file($f) ? (string)filemtime($f) : '1');
}

function preusmeri(string $putanja): void
{
    header('Location: ' . url($putanja));
    exit;
}

function je_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function ip_adresa(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ─── Čitanje unosa iz formi ────────────────────────────────────────────────

function ulaz_str(array $izvor, string $kljuc, int $max = 255): string
{
    $v = $izvor[$kljuc] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return mb_substr($v, 0, $max);
}

function post_str(string $kljuc, int $max = 255): string
{
    return ulaz_str($_POST, $kljuc, $max);
}

function get_str(string $kljuc, int $max = 255): string
{
    return ulaz_str($_GET, $kljuc, $max);
}

function ulaz_int(array $izvor, string $kljuc, int $podrazumevano = 0): int
{
    $v = $izvor[$kljuc] ?? null;
    if (!is_string($v) && !is_int($v)) {
        return $podrazumevano;
    }
    $r = filter_var($v, FILTER_VALIDATE_INT);
    return $r === false ? $podrazumevano : (int)$r;
}

function post_int(string $kljuc, int $podrazumevano = 0): int
{
    return ulaz_int($_POST, $kljuc, $podrazumevano);
}

function get_int(string $kljuc, int $podrazumevano = 0): int
{
    return ulaz_int($_GET, $kljuc, $podrazumevano);
}

function je_datum(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x !== false && $x->format('Y-m-d') === $d;
}

// ─── Formatiranje (srpski) ─────────────────────────────────────────────────

function broj(int|float $n, int $decimale = 0): string
{
    return number_format((float)$n, $decimale, ',', '.');
}

function datum_srp(string $dt): string
{
    $t = strtotime($dt);
    return $t === false ? '' : date('d.m.Y.', $t);
}

function vreme_srp(string $dt): string
{
    $t = strtotime($dt);
    return $t === false ? '' : date('H:i', $t);
}

function datum_vreme_srp(string $dt): string
{
    return datum_srp($dt) . ' ' . vreme_srp($dt);
}

function dan_u_nedelji(string $dt): string
{
    static $dani = ['nedelja', 'ponedeljak', 'utorak', 'sreda', 'četvrtak', 'petak', 'subota'];
    $t = strtotime($dt);
    return $t === false ? '' : $dani[(int)date('w', $t)];
}

/** "danas 14:05", "juče 09:30" ili "02.10. 16:00". */
function datum_kratko(string $dt): string
{
    $t = strtotime($dt);
    if ($t === false) {
        return '';
    }
    $dan = date('Y-m-d', $t);
    if ($dan === date('Y-m-d')) {
        return 'danas ' . date('H:i', $t);
    }
    if ($dan === date('Y-m-d', strtotime('-1 day'))) {
        return 'juče ' . date('H:i', $t);
    }
    return date('d.m. H:i', $t);
}

function sada(): string
{
    return date('Y-m-d H:i:s');
}

/** Početak današnjeg dana (uključivo) i početak sutrašnjeg (isključivo). */
function danas_od(): string
{
    return date('Y-m-d') . ' 00:00:00';
}

function danas_do(): string
{
    return (new DateTime('tomorrow'))->format('Y-m-d') . ' 00:00:00';
}

// ─── Poruke (flash) ────────────────────────────────────────────────────────

function flash_dodaj(string $tip, string $poruka): void
{
    sesija_start();
    $_SESSION['flash'][] = [$tip, $poruka];
}

/** HTML svih čekajućih poruka; posle ispisa se brišu. */
function flash_html(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as [$tip, $poruka]) {
        $klasa = in_array($tip, ['uspeh', 'greska', 'info', 'upozorenje'], true) ? $tip : 'info';
        $uloga = $klasa === 'greska' ? 'alert' : 'status';
        $html .= '<div class="poruka poruka-' . $klasa . '" role="' . $uloga . '">' . e($poruka) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ─── Zaglavlja i stranice greške ───────────────────────────────────────────

function posalji_zaglavlja(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (je_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/** Stranica sa porukom (403, 404, istekao CSRF...). Prekida izvršavanje. */
function stranica_greske(int $kod, string $naslov, string $poruka, ?string $link = null, string $linkTekst = 'Nazad'): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    posalji_zaglavlja();
    http_response_code($kod);
    ui_start($naslov, ['bez_zaglavlja' => true, 'telo' => 'prazna']);
    echo '<div class="kartica kartica-greska"><h1>' . e($naslov) . '</h1><p>' . e($poruka) . '</p>';
    if ($link !== null) {
        echo '<p><a class="btn btn-primary" href="' . e(url($link)) . '">' . e($linkTekst) . '</a></p>';
    }
    echo '</div>';
    ui_end();
    exit;
}
