<?php
/**
 * Istorija unosa: filteri, upiti, opis izmena iz dnevnika i izvoz u CSV.
 */
declare(strict_types=1);

/** Filteri iz adrese (GET). Bez ikakvog filtera po datumu prikazuje se poslednjih 7 dana. */
function istorija_filteri(): array
{
    $tip = get_str('tip', 20);
    if (!isset(TIPOVI_UNOSA[$tip])) {
        $tip = '';
    }
    if (isset($_GET['od']) || isset($_GET['do'])) {
        $od = get_str('od', 10);
        $do = get_str('do', 10);
    } else {
        $od = date('Y-m-d', strtotime('-6 days'));
        $do = date('Y-m-d');
    }
    return [
        'tip'      => $tip,
        'od'       => je_datum($od) ? $od : '',
        'do'       => je_datum($do) ? $do : '',
        'radnik'   => max(0, get_int('radnik')),
        'artikal'  => max(0, get_int('artikal')),
        'q'        => get_str('q', 60),
        'obrisani' => get_int('obrisani') === 1,
    ];
}

/** Parametri adrese za zadate filtere (da linkovi i izvoz zadrže izbor). */
function istorija_query(array $f, array $dodatno = []): string
{
    $p = [
        'tip' => $f['tip'], 'od' => $f['od'], 'do' => $f['do'], 'radnik' => $f['radnik'] ?: '',
        'artikal' => $f['artikal'] ?: '', 'q' => $f['q'], 'obrisani' => $f['obrisani'] ? 1 : '',
    ];
    return http_build_query(array_merge($p, $dodatno));
}

function istorija_like(string $tekst): string
{
    return '%' . addcslashes($tekst, '%_\\') . '%';
}

/** [uslov, parametri] za WHERE; $sa_obrisanim određuje da li se obrisani unosi računaju. */
function istorija_uslov(array $f, bool $sa_obrisanim): array
{
    $uslovi = [];
    $p = [];
    if (!$sa_obrisanim) {
        $uslovi[] = 'u.obrisan = 0';
    }
    if ($f['tip'] !== '') {
        $uslovi[] = 'u.tip = ?';
        $p[] = $f['tip'];
    }
    if ($f['od'] !== '') {
        $uslovi[] = 'u.nastalo >= ?';
        $p[] = $f['od'] . ' 00:00:00';
    }
    if ($f['do'] !== '') {
        $uslovi[] = 'u.nastalo < ?';
        $p[] = date('Y-m-d', (int)strtotime($f['do'] . ' +1 day')) . ' 00:00:00';
    }
    if ($f['radnik'] > 0) {
        $uslovi[] = 'u.korisnik_id = ?';
        $p[] = $f['radnik'];
    }
    if ($f['artikal'] > 0) {
        $uslovi[] = 's.artikal_id = ?';
        $p[] = $f['artikal'];
    }
    if ($f['q'] !== '') {
        $uslovi[] = '(ku.naziv LIKE ? OR u.napomena LIKE ?)';
        $p[] = istorija_like($f['q']);
        $p[] = istorija_like($f['q']);
    }
    return [$uslovi ? implode(' AND ', $uslovi) : '1 = 1', $p];
}

const ISTORIJA_IZ = 'FROM unosi u JOIN sku s ON s.id = u.sku_id JOIN artikli a ON a.id = s.artikal_id '
    . 'JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id '
    . 'JOIN korisnici r ON r.id = u.korisnik_id LEFT JOIN kupci ku ON ku.id = u.kupac_id '
    . 'LEFT JOIN korisnici ob ON ob.id = u.obrisao_id ';

const ISTORIJA_POLJA = 'u.id, u.tip, u.kolicina, u.palete, u.paketi, u.napomena, u.nastalo, u.obrisan, u.obrisan_u, u.spisak_id, '
    . 'r.ime AS radnik, r.uloga AS radnik_uloga, ku.naziv AS kupac, ob.ime AS obrisao, '
    . 's.id AS sku_id, s.po_paleti, a.id AS artikal_id, a.naziv AS artikal, a.oznaka, v.naziv AS varijanta, '
    . 'p.kolicina AS pak_kolicina, p.jedinica';

/** Zbirovi (komadi i broj unosa) po vrsti, bez obrisanih. */
function istorija_zbirovi(array $f): array
{
    [$uslov, $p] = istorija_uslov($f, false);
    $rez = ['proizvodnja' => 0, 'prodaja' => 0, 'kucna_prodaja' => 0, 'korekcija' => 0, 'utovar' => 0, 'povrat' => 0, 'broj' => 0];
    foreach (db_all('SELECT u.tip, COUNT(*) AS n, SUM(u.kolicina) AS kom ' . ISTORIJA_IZ . ' WHERE ' . $uslov . ' GROUP BY u.tip', $p) as $r) {
        $rez[$r['tip']] = (int)$r['kom'];
        $rez['broj'] += (int)$r['n'];
    }
    return $rez;
}

// ─── Dnevnik izmena ────────────────────────────────────────────────────────

const AKCIJE_DNEVNIKA = [
    'izmena'          => 'Izmenjeno',
    'brisanje'        => 'Obrisano',
    'brisanje_radnik' => 'Obrisao radnik',
    'povracaj'        => 'Vraćeno',
    'podesavanje'     => 'Podešavanje',
    'spisak'          => 'Spisak za utovar',
];

/** Opis promene u obliku liste rečenica ("Količina: 100 → 120 kom"). */
function dnevnik_promene(?string $detalji): array
{
    $d = $detalji === null ? null : json_decode($detalji, true);
    if (!is_array($d)) {
        return $detalji !== null && $detalji !== '' ? [$detalji] : [];     // obična rečenica (podešavanja)
    }
    $pre = $d['pre'] ?? null;
    $posle = $d['posle'] ?? null;
    if (is_array($pre) && is_array($posle)) {
        $rez = [];
        if (($pre['artikal'] ?? '') !== ($posle['artikal'] ?? '')) {
            $rez[] = 'Artikal: ' . $pre['artikal'] . ' → ' . $posle['artikal'];
        }
        if (($pre['kolicina'] ?? null) !== ($posle['kolicina'] ?? null)) {
            $rez[] = 'Količina: ' . broj((int)$pre['kolicina']) . ' → ' . broj((int)$posle['kolicina']) . ' kom';
        }
        if (($pre['palete'] ?? null) !== ($posle['palete'] ?? null)) {
            $rez[] = 'Palete: ' . ($pre['palete'] ?? '—') . ' → ' . ($posle['palete'] ?? '—');
        }
        if (($pre['paketi'] ?? null) !== ($posle['paketi'] ?? null)) {
            $rez[] = 'Paketi: ' . ($pre['paketi'] ?? '—') . ' → ' . ($posle['paketi'] ?? '—');
        }
        if (($pre['kupac_id'] ?? null) !== ($posle['kupac_id'] ?? null)) {
            $ime = static fn($id) => $id ? (string)db_val('SELECT naziv FROM kupci WHERE id = ?', [(int)$id]) : '—';
            $rez[] = 'Kupac: ' . $ime($pre['kupac_id'] ?? null) . ' → ' . $ime($posle['kupac_id'] ?? null);
        }
        if (($pre['napomena'] ?? null) !== ($posle['napomena'] ?? null)) {
            $rez[] = 'Napomena: „' . ($pre['napomena'] ?? '') . '“ → „' . ($posle['napomena'] ?? '') . '“';
        }
        if (($pre['nastalo'] ?? '') !== ($posle['nastalo'] ?? '')) {
            $rez[] = 'Vreme: ' . datum_vreme_srp((string)$pre['nastalo']) . ' → ' . datum_vreme_srp((string)$posle['nastalo']);
        }
        return $rez;
    }
    $snimak = $pre ?? $posle;
    if (is_array($snimak)) {
        return [($snimak['artikal'] ?? '') . ' – ' . broj((int)($snimak['kolicina'] ?? 0)) . ' kom, ' . datum_vreme_srp((string)($snimak['nastalo'] ?? ''))];
    }
    return [];
}

// ─── Period (pregledi: mešalice, proizvodnja po radniku) ───────────────────

/** Brzi izbor perioda: naziv => [od, do]. */
function period_opsezi(): array
{
    $danas = date('Y-m-d');
    $juce = date('Y-m-d', strtotime('-1 day'));
    return [
        'Danas'        => [$danas, $danas],
        'Juče'         => [$juce, $juce],
        'Ova nedelja'  => [date('Y-m-d', strtotime('monday this week')), $danas],
        'Ovaj mesec'   => [date('Y-m-01'), $danas],
        'Prošli mesec' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    ];
}

/** Period iz adrese (?od=…&do=…); bez njega ili neispravan: $podrazumevano (naziv iz period_opsezi()). Vraća [od, do]. */
function period_iz_adrese(string $podrazumevano): array
{
    $od = get_str('od', 10);
    $do = get_str('do', 10);
    if (!je_datum($od) || !je_datum($do)) {
        return period_opsezi()[$podrazumevano];
    }
    return $od <= $do ? [$od, $do] : [$do, $od];
}

/** Uslov za SQL: [od 00:00, dan posle do 00:00). */
function period_granice(string $od, string $do): array
{
    return [$od . ' 00:00:00', date('Y-m-d', (int)strtotime($do . ' +1 day')) . ' 00:00:00'];
}

/** "sreda, 28.10.2026." za jedan dan, inače "26.10.2026. – 28.10.2026." */
function period_opis(string $od, string $do): string
{
    return $od === $do ? dan_u_nedelji($od) . ', ' . datum_srp($od) : datum_srp($od) . ' – ' . datum_srp($do);
}

/** Izbor perioda za stranicu $adresa: dugmad (Danas, Juče …) i „Drugi period“ (od–do). */
function period_izbor_html(string $adresa, string $od, string $do): string
{
    ob_start(); ?>
    <div class="cipovi" role="group" aria-label="Period">
        <?php foreach (period_opsezi() as $naziv => [$o_od, $o_do]): ?>
            <a class="cip" href="<?= e(url($adresa . '?' . http_build_query(['od' => $o_od, 'do' => $o_do]))) ?>"
               <?= $od === $o_od && $do === $o_do ? 'aria-current="true"' : '' ?>><?= e($naziv) ?></a>
        <?php endforeach; ?>
    </div>
    <details class="kartica">
        <summary class="kartica-naslov"><h2>Drugi period</h2></summary>
        <form method="get" action="<?= e(url($adresa)) ?>">
            <div class="red-polja-2 red-polja">
                <div><label for="od">Od datuma</label><input class="polje" type="date" id="od" name="od" value="<?= e($od) ?>"></div>
                <div><label for="do">Do datuma</label><input class="polje" type="date" id="do" name="do" value="<?= e($do) ?>"></div>
            </div>
            <button class="btn btn-primary btn-blok" type="submit">Prikaži</button>
        </form>
    </details>
    <?php
    return (string)ob_get_clean();
}

// ─── CSV ───────────────────────────────────────────────────────────────────

/** Tekst za CSV: zaštita od "formula injection" u Excelu i navodnici po pravilima. */
function csv_tekst(?string $t): string
{
    $t = (string)$t;
    if ($t !== '' && str_contains("=+-@\t\r", $t[0])) {
        $t = "'" . $t;
    }
    return '"' . str_replace('"', '""', $t) . '"';
}
