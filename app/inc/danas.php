<?php
/**
 * Danas: šta je urađeno (proizvodnja) i šta je otišlo (utovar sa spiskova + prodaja + kućna prodaja − povrat),
 * po artiklu, u paletama i komadima. Za karticu "Danas" na početnoj (Stanje) i za obaveštenje na telefon uveče.
 */
declare(strict_types=1);

/**
 * Zbir jednog dana: ['dan', 'artikli' => [sku_id => ['sku' => red (SKU_POLJA), 'uradjeno' => kom, 'otislo' => kom]],
 * 'uradjeno' / 'otislo' => kom, 'uradjeno_pal' / 'otislo_pal' => palete (artikli bez palete se ne broje)].
 * Artikli su redom kao u katalogu; artikal kod kog se sve poništilo (utovar pa povrat istog dana) se ne prikazuje.
 */
function danas_zbir(?string $dan = null): array
{
    $dan ??= date('Y-m-d');
    $redovi = db_all(
        "SELECT u.uradjeno, u.otislo, " . SKU_POLJA . "
         FROM (SELECT sku_id,
                      SUM(CASE WHEN tip = 'proizvodnja' THEN kolicina ELSE 0 END) AS uradjeno,
                      SUM(CASE WHEN tip IN ('utovar', 'prodaja', 'kucna_prodaja') THEN kolicina WHEN tip = 'povrat' THEN -kolicina ELSE 0 END) AS otislo
               FROM unosi
               WHERE obrisan = 0 AND tip IN ('proizvodnja', 'utovar', 'prodaja', 'kucna_prodaja', 'povrat') AND nastalo >= ? AND nastalo < ?
               GROUP BY sku_id) u " . SKU_SPOJ . "
         JOIN kategorije k ON k.id = a.kategorija_id
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.jedinica, p.kolicina",
        [$dan . ' 00:00:00', date('Y-m-d', (int)strtotime($dan . ' +1 day')) . ' 00:00:00']
    );
    $z = ['dan' => $dan, 'artikli' => [], 'uradjeno' => 0, 'otislo' => 0, 'uradjeno_pal' => 0.0, 'otislo_pal' => 0.0];
    foreach ($redovi as $r) {
        $ur = (int)$r['uradjeno'];
        $ot = (int)$r['otislo'];
        if ($ur === 0 && $ot === 0) {
            continue;
        }
        $z['artikli'][(int)$r['sku_id']] = ['sku' => $r, 'uradjeno' => $ur, 'otislo' => $ot];
        $z['uradjeno'] += $ur;
        $z['otislo'] += $ot;
        $po = (int)$r['po_paleti'];
        if ($po > 0) {
            $z['uradjeno_pal'] += $ur / $po;
            $z['otislo_pal'] += $ot / $po;
        }
    }
    return $z;
}

/** Količina za karticu: "3 pal", "1 pal + 3 pak", "35 kom", "–" za nulu (minus kad je više vraćeno nego što je otišlo). */
function danas_kolicina(int $kom, array $sku): string
{
    if ($kom === 0) {
        return '–';
    }
    $t = razlaganje($kom, (int)$sku['po_paleti'], (int)$sku['po_paketu']);
    return $t !== '' ? $t : ($kom < 0 ? '−' : '') . broj(abs($kom)) . ' kom';
}

/** Ukupno paleta, kratko: "18 pal", "≈ 5,4 pal", "< 0,1 pal". */
function danas_pal(float $p): string
{
    $r = round($p, 1);
    if ($r == 0.0) {
        return $p == 0.0 ? '0 pal' : '< 0,1 pal';
    }
    return ($r == floor($r) ? broj((int)$r) : '≈ ' . broj($r, 1)) . ' pal';
}

/** Kartica "Danas" na početnoj: po artiklu urađeno i otišlo (osvežava se sama – assets/js/danas.js, admin/danas.php). */
function danas_kartica_html(array $z, int $sat_obavestenja): string
{
    ob_start(); ?>
    <details class="kartica danas-kartica" id="danas" open data-danas data-osvezi="<?= e(url('admin/danas.php')) ?>">
        <summary class="kartica-naslov">
            <span><strong class="danas-naslov">Danas</strong> <span class="pomoc"><?= e(dan_u_nedelji($z['dan'])) ?>, <?= e(datum_srp($z['dan'])) ?></span><br>
                <span class="danas-zbir">urađeno <strong><?= e(danas_pal($z['uradjeno_pal'])) ?></strong> · otišlo <strong><?= e(danas_pal($z['otislo_pal'])) ?></strong></span></span>
        </summary>
        <?php if (!$z['artikli']): ?>
            <p class="pomoc bez-margine">Danas još nema ni proizvodnje ni robe koja je otišla.</p>
        <?php else: ?>
            <div class="kalk-omot">
                <table class="kalk-tabela danas-tabela">
                    <thead><tr><th>Artikal</th><th class="desno">Urađeno</th><th class="desno">Otišlo</th></tr></thead>
                    <tbody>
                        <?php foreach ($z['artikli'] as $a): ?>
                            <tr>
                                <td><?= e(sku_naziv($a['sku'])) ?></td>
                                <td class="desno kalk-broj"><?= e(danas_kolicina($a['uradjeno'], $a['sku'])) ?></td>
                                <td class="desno kalk-broj"><?= e(danas_kolicina($a['otislo'], $a['sku'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Ukupno</td>
                            <td class="desno kalk-broj"><?= e(danas_pal($z['uradjeno_pal'])) ?><br><small><?= e(broj($z['uradjeno'])) ?> kom</small></td>
                            <td class="desno kalk-broj"><?= e(danas_pal($z['otislo_pal'])) ?><br><small><?= e(broj($z['otislo'])) ?> kom</small></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="pomoc bez-margine razmak-gore">Otišlo = utovar sa spiskova + prodaja + kućna prodaja (minus vraćeno).</p>
        <?php endif; ?>
        <p class="grupa-dugmica razmak-gore">
            <a class="btn btn-mali" href="<?= e(url('admin/proizvodnja.php')) ?>"><?= ikona('radnici') ?> Ko je šta uradio</a>
            <a class="btn btn-mali" href="<?= e(url('admin/istorija.php?' . http_build_query(['od' => $z['dan'], 'do' => $z['dan']]))) ?>"><?= ikona('istorija') ?> Današnji unosi</a>
            <a class="btn btn-mali" href="<?= e(url('admin/obavestenja.php')) ?>">🔔 Obaveštenje u <?= $sat_obavestenja ?>h</a>
        </p>
    </details>
    <?php
    return (string)ob_get_clean();
}

/**
 * Obaveštenje sa zbirom dana za telefon: naslov, tekst (najveći artikli, kratko) i adresa koja se otvara dodirom.
 * Vraća ['naslov', 'tekst', 'url', 'oznaka'].
 */
function danas_obavestenje(array $z): array
{
    $najvise = static function (string $polje) use ($z): string {
        $lista = array_filter($z['artikli'], static fn(array $a): bool => $a[$polje] > 0);
        usort($lista, static fn(array $a, array $b): int => $b[$polje] / max(1, (int)$b['sku']['po_paleti']) <=> $a[$polje] / max(1, (int)$a['sku']['po_paleti']));
        $delovi = array_map(static fn(array $a): string => sku_naziv($a['sku']) . ' ' . danas_kolicina($a[$polje], $a['sku']), array_slice($lista, 0, 4));
        return $delovi ? implode(', ', $delovi) . (count($lista) > 4 ? ' …' : '') : 'ništa';
    };
    return [
        'naslov' => 'Danas urađeno ' . danas_pal($z['uradjeno_pal']) . ' · otišlo ' . danas_pal($z['otislo_pal']),
        'tekst'  => "Urađeno: " . $najvise('uradjeno') . "\nOtišlo: " . $najvise('otislo'),
        'url'    => 'admin/stanje.php#danas',
        'oznaka' => 'dnevni-zbir-' . $z['dan'],
    ];
}
