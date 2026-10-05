<?php
/**
 * Zajednički početak svake stranice: podešavanja, greške, zaglavlja, pomoćne funkcije.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Aplikaciji je potreban PHP 8.0 ili noviji. U cPanel-u otvorite "Select PHP Version" i izaberite 8.0 ili noviju verziju.');
}

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nedostaje fajl config.php.');
}
require APP_ROOT . '/config.php';

// Podrazumevane vrednosti za sve što nije upisano u config.php
foreach ([
    'DB_HOST'             => 'localhost',
    'DB_PORT'             => 3306,
    'APP_NAZIV'           => 'Farmerkop',
    'VREMENSKA_ZONA'      => 'Europe/Belgrade',
    'SESIJA_RADNIK_MIN'   => 60,
    'SESIJA_ADMIN_MIN'    => 30,
    'MAX_POKUSAJA'        => 5,
    'ZAKLJUCAVANJE_MIN'   => 10,
    'BRISANJE_RADNIK_MIN' => 10,
    'MAX_KOLICINA_UNOS'   => 10000,
    'MIN_DUZINA_SIFRE'    => 8,
    'APP_BASE_PUTANJA'    => null,
    'INSTALL_KLJUC'       => 'PROMENI_OVO',
    'DEBUG'               => false,
] as $kljuc => $vrednost) {
    if (!defined($kljuc)) {
        define($kljuc, $vrednost);
    }
}

date_default_timezone_set(VREMENSKA_ZONA);
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Sav izlaz ide kroz bafer, da bi se u slučaju greške prikazala čista stranica greške.
ob_start();

set_exception_handler(static function (Throwable $e): void {
    error_log('[Farmerkop] ' . get_class($e) . ': ' . $e->getMessage() . ' u ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $naslov = 'Došlo je do greške';
    $tekst = 'Pokušajte ponovo. Ako se greška ponavlja, javite administratoru.';
    if ($e instanceof PDOException) {
        $poruka = $e->getMessage();
        if (str_contains($poruka, '42S02') || stripos($poruka, "doesn't exist") !== false) {
            // Tabele ne postoje: aplikacija još nije instalirana.
            if (is_file(APP_ROOT . '/install.php') && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php' && !headers_sent()) {
                header('Location: ' . url('install.php'));
                exit;
            }
            $naslov = 'Aplikacija nije instalirana';
            $tekst = 'U bazi nema potrebnih tabela. Otvorite stranicu install.php (ako ste je obrisali, otpremite je ponovo).';
        } elseif (preg_match('/SQLSTATE\[HY000\] \[(1045|1049|1044|2002|2006)\]/', $poruka)) {
            $naslov = 'Nema veze sa bazom';
            $tekst = 'Aplikacija ne može da se poveže sa bazom podataka. Proverite podatke u fajlu config.php (DB_HOST, DB_NAME, DB_USER, DB_PASS).';
        }
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><html lang="sr-Latn"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($naslov, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#f4ead7;color:#2b2118;margin:0;padding:24px}'
        . '.k{max-width:520px;margin:12vh auto;background:#fffaf0;border:1px solid #d9c7a3;border-radius:16px;padding:24px}'
        . 'h1{margin:0 0 8px;font-size:1.4rem}a{color:#4f6f2f;font-weight:700}</style></head><body><div class="k">'
        . '<h1>' . htmlspecialchars($naslov, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($tekst, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="javascript:history.back()">← Nazad</a></p>';
    if (DEBUG) {
        echo '<pre style="white-space:pre-wrap;font-size:.8rem">' . htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</div></body></html>';
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/katalog.php';
require_once __DIR__ . '/unosi.php';
require_once __DIR__ . '/migracije.php';
require_once __DIR__ . '/layout.php';

posalji_zaglavlja();

// Posle ažuriranja fajlova baza se nadograđuje sama (jednom).
if (!defined('INSTALL_RUN')) {
    migriraj_ako_treba();
}
