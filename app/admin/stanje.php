<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$k = zahtevaj_ulogu('admin');
ui_start('Stanje', ['nav' => 'admin', 'aktivno' => 'stanje']);
echo '<div class="kartica"><p>Zdravo, ' . e($k['ime']) . '.</p></div>';
ui_end();
