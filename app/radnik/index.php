<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$k = zahtevaj_ulogu('radnik');
ui_start('Proizvodnja', ['nav' => 'radnik', 'aktivno' => 'proizvodnja']);
echo '<div class="kartica"><p>Zdravo, ' . e($k['ime']) . '.</p></div>';
ui_end();
