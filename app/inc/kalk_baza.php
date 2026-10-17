<?php
/**
 * Kalkulacija (Faza 1) – podaci iz baze (tabele calc_*, migracije/001_kalkulacija.sql) i zajednički delovi ekrana.
 * Samo za administratora. Računanje je u inc/kalkulacija.php; ovde se samo čitaju važeće cene na dan.
 * Postojeće tabele (artikli, pakovanja, sku, korisnici) se samo čitaju.
 */
declare(strict_types=1);
require_once __DIR__ . '/kalkulacija.php';

const KALK_TABELE = ['calc_materials', 'calc_material_prices', 'calc_cost_items', 'calc_recipes', 'calc_recipe_items', 'calc_selling_prices', 'calc_settings'];
const KALK_MIGRACIJA = 'migracije/001_kalkulacija.sql';

/** Greška pri čuvanju u kalkulaciji – poništava celu izmenu, a poruka ide korisniku. */
final class GreskaKalk extends RuntimeException
{
}

/** Da li je migracija pokrenuta (postoje sve tabele modula). */
function kalk_instaliran(): bool
{
    $mesta = implode(',', array_fill(0, count(KALK_TABELE), '?'));
    return (int)db_val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($mesta)", KALK_TABELE) === count(KALK_TABELE);
}

/**
 * Početak svake stranice modula: samo administrator (radnika vraća na njegov početni ekran, kao i ostale
 * administratorske stranice); ako migracija nije pokrenuta, prikaže uputstvo umesto greške.
 */
function kalk_zahtevaj(string $naslov): array
{
    $admin = zahtevaj_ulogu('admin');
    if (!kalk_instaliran()) {
        ui_start($naslov, ['nav' => 'admin']);
        ?>
        <div class="kartica">
            <h2>Modul Kalkulacija još nije uključen</h2>
            <p>Pokrenite migraciju baze <strong><?= e(KALK_MIGRACIJA) ?></strong> (jednom): cPanel → <strong>phpMyAdmin</strong> → izaberite bazu aplikacije → kartica <strong>Import</strong> → izaberite fajl → <strong>Go</strong>.</p>
            <p class="pomoc bez-margine">Migracija samo dodaje nove tabele (calc_…); postojeći podaci, zalihe i unosi se ne menjaju.</p>
        </div>
        <?php
        ui_end();
        exit;
    }
    return $admin;
}

/** Podmeni modula (isti spisak kao u meniju ☰ u zaglavlju). */
function kalk_meni(string $aktivno): void
{
    echo '<nav class="kalk-meni" aria-label="Kalkulacija">';
    foreach (kalk_meni_stavke() as $kljuc => [$tekst, $adresa]) {
        $akt = $kljuc === $aktivno;
        echo '<a href="' . e(url($adresa)) . '" class="kalk-meni-stavka' . ($akt ? ' aktivna' : '') . '"' . ($akt ? ' aria-current="page"' : '') . '>' . e($tekst) . '</a>';
    }
    echo '</nav>';
}

/** Zapis u dnevnik izmena (vidi se u Istorija → Dnevnik izmena, kao podešavanje). */
function kalk_dnevnik(string $tekst): void
{
    dnevnik_upis('podesavanje', 'podesavanje', null, 'Kalkulacija: ' . $tekst);
}

function kalk_danas(): string
{
    return date('Y-m-d');
}

/** Datum iz forme (Y-m-d), od 2000. do 2100. godine; inače null. */
function kalk_datum_iz(string $t): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($t), $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
        return null;
    }
    return trim($t);
}

/** Pogrešan unos (cena, trošak, prodajna cena) može da se obriše samo istog dana kad je upisan; istorija ostaje. */
function kalk_moze_brisati(array $red): bool
{
    return substr((string)$red['napravljen'], 0, 10) === kalk_danas();
}

/** Podešavanje modula (stopa PDV-a, prag marže) kao broj. */
function kalk_podesavanje(string $kljuc, float $podrazumevano): float
{
    $v = db_val('SELECT vrednost FROM calc_settings WHERE kljuc = ?', [$kljuc]);
    return $v === null || !is_numeric($v) ? $podrazumevano : (float)$v;
}

function kalk_podesi(string $kljuc, string $vrednost): void
{
    db_run('INSERT INTO calc_settings (kljuc, vrednost) VALUES (?, ?) ON DUPLICATE KEY UPDATE vrednost = VALUES(vrednost)', [$kljuc, $vrednost]);
}

/** Pakovanje za prikaz: "50 l" ili "sve veličine". */
function kalk_pakovanje_tekst(?array $r): string
{
    return $r === null || $r['pak_kolicina'] === null ? 'sve veličine' : pakovanje_naziv($r['pak_kolicina'], (string)$r['pak_jedinica']);
}

/** Pakovanja u litrima (veličine džaka: 5, 10, 20, 25, 50 l …). */
function kalk_pakovanja(): array
{
    return db_all("SELECT id, kolicina AS pak_kolicina, jedinica AS pak_jedinica FROM pakovanja WHERE jedinica = 'l' ORDER BY kolicina");
}

/**
 * Sirovine sa cenom koja važi na $datum: [id => red + 'cena' (?float), 'cena_od', 'faktor' (?float)].
 */
function kalk_sirovine(string $datum): array
{
    $rez = [];
    foreach (db_all(
        'SELECT m.*,
                (SELECT p.cena FROM calc_material_prices p WHERE p.materijal_id = m.id AND p.vazi_od <= ? ORDER BY p.vazi_od DESC LIMIT 1) AS cena,
                (SELECT p.vazi_od FROM calc_material_prices p WHERE p.materijal_id = m.id AND p.vazi_od <= ? ORDER BY p.vazi_od DESC LIMIT 1) AS cena_od
         FROM calc_materials m ORDER BY m.aktivna DESC, m.id',
        [$datum, $datum]
    ) as $m) {
        $m['cena'] = $m['cena'] === null ? null : (float)$m['cena'];
        $m['faktor'] = $m['litara_po_jedinici'] === null ? null : (float)$m['litara_po_jedinici'];
        $rez[(int)$m['id']] = $m;
    }
    return $rez;
}

/**
 * Troškovi koji važe na $datum: za svaki trošak (naziv + veličina) poslednji red sa datumom ≤ $datum.
 * Svaki red: id, naziv, tip, iznos (float), vazi_od, pakovanje_id, pak_kolicina, pak_jedinica.
 */
function kalk_troskovi(string $datum): array
{
    $redovi = db_all(
        'SELECT c.*, p.kolicina AS pak_kolicina, p.jedinica AS pak_jedinica
         FROM calc_cost_items c LEFT JOIN pakovanja p ON p.id = c.pakovanje_id
         WHERE c.vazi_od <= ? AND NOT EXISTS (
             SELECT 1 FROM calc_cost_items c2 WHERE c2.naziv = c.naziv AND c2.pakovanje_id <=> c.pakovanje_id AND c2.vazi_od <= ? AND c2.vazi_od > c.vazi_od)
         ORDER BY c.naziv, p.kolicina IS NOT NULL, p.kolicina',
        [$datum, $datum]
    );
    foreach ($redovi as &$r) {
        $r['iznos'] = (float)$r['iznos'];
    }
    return $redovi;
}

/**
 * Troškovi za jednu veličinu džaka: oni za sve veličine i oni baš za to pakovanje (iznos 0 se preskače).
 * Trošak upisan baš za tu veličinu zamenjuje istoimeni trošak "za sve veličine" (npr. Vreća 5 l umesto Vreća).
 */
function kalk_troskovi_za(array $troskovi, int $pakovanje_id): array
{
    $posebni = [];
    foreach ($troskovi as $t) {
        if ((int)$t['pakovanje_id'] === $pakovanje_id) {
            $posebni[mb_strtolower($t['naziv'])] = true;
        }
    }
    $rez = [];
    foreach ($troskovi as $t) {
        $za_sve = $t['pakovanje_id'] === null && !isset($posebni[mb_strtolower($t['naziv'])]);
        if (($za_sve || (int)$t['pakovanje_id'] === $pakovanje_id) && $t['iznos'] > 0) {
            $rez[] = ['id' => (int)$t['id'], 'naziv' => $t['naziv'], 'tip' => $t['tip'], 'iznos' => $t['iznos']];
        }
    }
    return $rez;
}

/** Sastav recepture: [['materijal_id', 'udeo' (float), 'naziv'], ...]. */
function kalk_stavke_recepture(int $recept_id): array
{
    $rez = db_all(
        'SELECT i.materijal_id, i.udeo, m.naziv FROM calc_recipe_items i JOIN calc_materials m ON m.id = i.materijal_id
         WHERE i.recept_id = ? ORDER BY i.udeo DESC, m.naziv',
        [$recept_id]
    );
    foreach ($rez as &$r) {
        $r['materijal_id'] = (int)$r['materijal_id'];
        $r['udeo'] = (float)$r['udeo'];
    }
    return $rez;
}

/** Recepture koje važe na $datum (aktivna verzija sa najkasnijim datumom ≤ $datum): [artikal_id => red + 'stavke']. */
function kalk_recepture(string $datum): array
{
    $rez = [];
    foreach (db_all(
        'SELECT r.*, a.naziv AS artikal FROM calc_recipes r JOIN artikli a ON a.id = r.artikal_id
         WHERE r.aktivna = 1 AND r.vazi_od <= ? AND NOT EXISTS (
             SELECT 1 FROM calc_recipes r2 WHERE r2.artikal_id = r.artikal_id AND r2.aktivna = 1 AND r2.vazi_od <= ?
             AND (r2.vazi_od > r.vazi_od OR (r2.vazi_od = r.vazi_od AND r2.id > r.id)))',
        [$datum, $datum]
    ) as $r) {
        $r['stavke'] = kalk_stavke_recepture((int)$r['id']);
        $rez[(int)$r['artikal_id']] = $r;
    }
    return $rez;
}

/** Prodajne cene koje važe na $datum: [sku_id => red + 'bez_pdv' (float)]. */
function kalk_prodajne(string $datum): array
{
    $rez = [];
    foreach (db_all(
        'SELECT sp.* FROM calc_selling_prices sp WHERE sp.vazi_od <= ? AND NOT EXISTS (
             SELECT 1 FROM calc_selling_prices s2 WHERE s2.sku_id = sp.sku_id AND s2.vazi_od <= ? AND s2.vazi_od > sp.vazi_od)',
        [$datum, $datum]
    ) as $r) {
        $r['bez_pdv'] = kalk_bez_pdv((float)$r['cena'], (int)$r['sa_pdv'] === 1, (float)$r['pdv_stopa']);
        $rez[(int)$r['sku_id']] = $r;
    }
    return $rez;
}

/** Proizvodi (artikli) koji se pakuju u litrima – za recepture. */
function kalk_artikli(): array
{
    return db_all(
        "SELECT a.id, a.naziv FROM artikli a JOIN kategorije k ON k.id = a.kategorija_id
         WHERE a.aktivan = 1 AND EXISTS (SELECT 1 FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id
                                          WHERE s.artikal_id = a.id AND s.aktivan = 1 AND p.jedinica = 'l')
         ORDER BY k.redosled, k.id, a.redosled, a.id"
    );
}

/** Proizvodi u litrima (aktivni SKU: artikal + varijanta + pakovanje), redom kao u katalogu. */
function kalk_proizvodi(): array
{
    return db_all(
        "SELECT s.id AS sku_id, s.artikal_id, a.naziv AS artikal, v.naziv AS varijanta, s.pakovanje_id, p.kolicina AS pak_kolicina, p.jedinica
         FROM sku s JOIN artikli a ON a.id = s.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
         JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
         WHERE " . SQL_AKTIVAN_SKU . " AND p.jedinica = 'l'
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id, p.kolicina"
    );
}

/**
 * Pregled kalkulacije na $datum: proizvodi (sa recepturom ili prodajnom cenom) × veličine džaka.
 * $izmene (za "Šta ako"): ['sirovina' => [id => nova cena], 'trosak' => [id reda troška => nov iznos]].
 * Vraća [artikal_id => ['artikal', 'recept' => red|null, 'redovi' => [['sku_id', 'naziv', 'V', 'trosak', 'prodajna', 'marza']]]].
 */
function kalk_pregled(string $datum, array $izmene = []): array
{
    $sirovine = [];
    foreach (kalk_sirovine($datum) as $id => $m) {
        $cena = array_key_exists($id, $izmene['sirovina'] ?? []) ? $izmene['sirovina'][$id] : $m['cena'];
        $sirovine[$id] = ['naziv' => $m['naziv'], 'cena' => $cena, 'faktor' => $m['faktor'], 'jedinica' => $m['jedinica']];
    }
    $troskovi = kalk_troskovi($datum);
    foreach ($troskovi as &$t) {
        if (array_key_exists((int)$t['id'], $izmene['trosak'] ?? [])) {
            $t['iznos'] = (float)$izmene['trosak'][(int)$t['id']];
        }
    }
    unset($t);
    $recepture = kalk_recepture($datum);
    $prodajne = kalk_prodajne($datum);

    $rez = [];
    foreach (kalk_proizvodi() as $p) {
        $aid = (int)$p['artikal_id'];
        $sid = (int)$p['sku_id'];
        $recept = $recepture[$aid] ?? null;
        if ($recept === null && !isset($prodajne[$sid])) {
            continue;
        }
        $V = (float)$p['pak_kolicina'];
        $trosak = kalk_trosak_dzaka($V, $recept['stavke'] ?? [], $sirovine, kalk_troskovi_za($troskovi, (int)$p['pakovanje_id']));
        $prodajna = $prodajne[$sid] ?? null;
        $rez[$aid] ??= ['artikal' => $p['artikal'], 'recept' => $recept, 'redovi' => []];
        $rez[$aid]['redovi'][] = [
            'sku_id'   => $sid,
            'naziv'    => ($p['varijanta'] !== null ? $p['varijanta'] . ' · ' : '') . pakovanje_naziv($p['pak_kolicina'], 'l'),
            'pun'      => sku_naziv(['artikal' => $p['artikal'], 'varijanta' => $p['varijanta'], 'pak_kolicina' => $p['pak_kolicina'], 'jedinica' => 'l']),
            'V'        => $V,
            'trosak'   => $trosak,
            'prodajna' => $prodajna,
            'marza'    => kalk_marza($prodajna['bez_pdv'] ?? null, $trosak['ukupno']),
        ];
    }
    return $rez;
}

/** Klasa boje marže: crveno ispod praga (i kad je gubitak), zeleno iznad. */
function kalk_marza_klasa(?array $marza, float $prag): string
{
    if ($marza === null || $marza['pct'] === null) {
        return 'kalk-marza-nema';
    }
    return $marza['pct'] < $prag || $marza['rsd'] < 0 ? 'kalk-marza-niska' : 'kalk-marza-ok';
}
