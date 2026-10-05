<?php
/**
 * Izvoz istorije u CSV (otvara se u Excelu). Isti filteri kao na stranici Istorija.
 * UTF-8 sa BOM-om i tačka-zarez kao razdvajač, da Excel na srpskom ispravno prikaže slova i kolone.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/istorija.php';

zahtevaj_ulogu('admin');
$f = istorija_filteri();
[$uslov, $parametri] = istorija_uslov($f, $f['obrisani']);

$st = db_run('SELECT ' . ISTORIJA_POLJA . ', u.uneto ' . ISTORIJA_IZ . ' WHERE ' . $uslov . ' ORDER BY u.nastalo ASC, u.id ASC', $parametri);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="farmerkop-istorija-' . date('Y-m-d') . '.csv"');

$izlaz = fopen('php://output', 'w');
fwrite($izlaz, "\xEF\xBB\xBF");

$kolone = ['ID', 'Datum', 'Vreme', 'Dan', 'Vrsta', 'Artikal', 'Boja / granulacija', 'Pakovanje', 'Količina (kom)', 'Promena stanja (kom)',
    'Palete', 'Paketi', 'Ukupno (l ili kg)', 'Radnik / unos', 'Kupac', 'Napomena'];
if ($f['obrisani']) {
    $kolone[] = 'Obrisano';
}
fwrite($izlaz, implode(';', array_map('csv_tekst', $kolone)) . "\r\n");

while (($r = $st->fetch()) !== false) {
    $kom = (int)$r['kolicina'];
    $promena = in_array($r['tip'], ['prodaja', 'kucna_prodaja'], true) ? -$kom : $kom;
    $ukupno = $kom * (float)$r['pak_kolicina'];
    $red = [
        (string)$r['id'],
        csv_tekst(datum_srp((string)$r['nastalo'])),
        csv_tekst(vreme_srp((string)$r['nastalo'])),
        csv_tekst(dan_u_nedelji((string)$r['nastalo'])),
        csv_tekst(TIPOVI_UNOSA[$r['tip']]),
        csv_tekst((string)$r['artikal']),
        csv_tekst((string)($r['varijanta'] ?? '')),
        csv_tekst(pakovanje_naziv($r['pak_kolicina'], (string)$r['jedinica'])),
        (string)$kom,
        (string)$promena,
        $r['palete'] === null ? '' : (string)(int)$r['palete'],
        $r['paketi'] === null ? '' : (string)(int)$r['paketi'],
        str_replace('.', ',', rtrim(rtrim(number_format($ukupno, 2, '.', ''), '0'), '.')),
        csv_tekst((string)$r['radnik']),
        csv_tekst((string)($r['kupac'] ?? '')),
        csv_tekst((string)($r['napomena'] ?? '')),
    ];
    if ($f['obrisani']) {
        $red[] = csv_tekst($r['obrisan'] ? 'da' : 'ne');
    }
    fwrite($izlaz, implode(';', $red) . "\r\n");
}
fclose($izlaz);
exit;
