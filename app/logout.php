<?php
/**
 * Odjava. Radi samo preko forme (POST) sa CSRF žetonom.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if (!je_post()) {
    preusmeri('index.php');
}
csrf_proveri();
odjavi();
preusmeri('login.php');
