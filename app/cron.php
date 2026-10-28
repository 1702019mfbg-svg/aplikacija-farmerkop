<?php
/**
 * Zakazani posao (cPanel → Cron Jobs, jednom na sat): dnevni zbir na telefon administratora u zadati sat
 * (Obaveštenja na telefon). Pokreće se komandom "php …/cron.php" ili adresom ".../cron.php?kljuc=…" –
 * tačna komanda i ključ pišu na stranici admin/obavestenja.php.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/danas.php';
require __DIR__ . '/inc/push.php';

if (PHP_SAPI !== 'cli' && !hash_equals(push_cron_kljuc(), get_str('kljuc', 64))) {
    stranica_greske(403, 'Zabranjeno', 'Ova adresa radi samo sa ključem sa stranice Obaveštenja.');
}
$poruka = dnevni_zbir_posalji_ako_treba();
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo date('d.m.Y. H:i') . ' – ' . $poruka . "\n";
