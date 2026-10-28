<?php
/**
 * Administrator – kartica "Danas" sa početne (deo stranice), da se osveži bez ponovnog učitavanja (assets/js/danas.js).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/danas.php';

zahtevaj_ulogu('admin');
header('X-Farmerkop-Deo: danas');
echo danas_kartica_html(danas_zbir(), (int)podesavanje('obavestenje_sat', '20'));
