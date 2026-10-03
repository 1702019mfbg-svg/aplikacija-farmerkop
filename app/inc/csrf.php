<?php
/**
 * CSRF zaštita: svaka forma nosi tajni žeton koji server proverava.
 */
declare(strict_types=1);

function csrf_token(): string
{
    sesija_start();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_polje(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Poziva se na početku obrade svake POST forme. */
function csrf_proveri(): void
{
    $poslat = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($poslat) || !hash_equals(csrf_token(), $poslat)) {
        stranica_greske(
            403,
            'Sesija je istekla',
            'Forma više nije važeća (istekla je sesija ili je stranica bila predugo otvorena). Osvežite stranicu i pokušajte ponovo.',
            'index.php',
            'Nazad na početnu'
        );
    }
}
