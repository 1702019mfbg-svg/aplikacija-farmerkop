<?php
/**
 * Početna adresa: vodi na prijavu ili na početnu stranicu po ulozi.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$korisnik = auth_user();
preusmeri($korisnik === null ? 'login.php' : pocetna_za($korisnik));
