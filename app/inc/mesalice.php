<?php
/**
 * Mešalice malča: radnik upisuje koliko je mešalica ofarbao, po boji (crveni, crni …), koliko god puta dnevno
 * (može i posle svake mešalice – sabira se). Samo za praćenje rada: ofarban malč se suši i još nije spakovan,
 * pa ne ulazi u stanje (vreće se upisuju u Proizvodnji). Norma se ne postavlja – vlasnik u pregledu
 * (admin/mesalice.php) vidi ko je koliko uradio.
 */
declare(strict_types=1);

/** Najviše mešalica u jednom unosu. */
const MESALICE_MAX = 99;

/**
 * Boje za mešalice: boje (varijante) malča iz kataloga, redom kao u katalogu, bez neobojenog (on se ne farba).
 * Nova boja malča u Podešavanjima odmah se pojavi i ovde.
 */
function mesalice_boje(): array
{
    $boje = [];
    foreach (db_all(
        "SELECT v.naziv FROM varijante v JOIN artikli a ON a.id = v.artikal_id JOIN kategorije k ON k.id = a.kategorija_id
         WHERE v.aktivan = 1 AND a.aktivan = 1 AND k.aktivan = 1 AND (k.naziv = 'Malč' OR a.naziv LIKE 'Malč%')
         ORDER BY k.redosled, k.id, a.redosled, a.id, v.redosled, v.id"
    ) as $r) {
        $b = trim((string)$r['naziv']);
        $mala = mb_strtolower($b);
        if ($b === '' || str_starts_with($mala, 'neobojen') || in_array($mala, array_map('mb_strtolower', $boje), true)) {
            continue;
        }
        $boje[] = $b;
    }
    return $boje;
}

/** Oznaka boje za tačkicu na dugmetu ("Crveni" → "crven", "Žuti" → "zut"); '' kad boja nije poznata. */
function mesalica_tacka(string $boja): string
{
    $a = strtolower(str_replace(' ', '', tekst_ascii($boja)));
    foreach (['crven', 'crn', 'braon', 'zut', 'narandz', 'zelen', 'plav', 'bel', 'siv', 'ljubic', 'roz', 'bordo', 'oker'] as $k) {
        if (str_starts_with($a, $k)) {
            return $k;
        }
    }
    return '';
}

/** "1 mešalica", "3 mešalice", "5 mešalica" */
function mesalice_tekst(int $n): string
{
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        return broj($n) . ' mešalica';
    }
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
        return broj($n) . ' mešalice';
    }
    return broj($n) . ' mešalica';
}

/**
 * Upis mešalica. Ista forma poslata dva puta (ključ) upisuje se samo jednom.
 * Vraća ['ok' => true, 'duplikat' => bool] ili ['ok' => false, 'greska' => tekst].
 */
function mesalica_dodaj(int $korisnik_id, string $boja, int $broj, ?string $kljuc): array
{
    if ($broj < 1 || $broj > MESALICE_MAX) {
        return ['ok' => false, 'greska' => 'Broj mešalica mora biti od 1 do ' . MESALICE_MAX . '.'];
    }
    if ($kljuc !== null && !preg_match('/^[0-9a-f]{32}$/', $kljuc)) {
        $kljuc = null;
    }
    if ($kljuc !== null && db_val('SELECT id FROM mesalice WHERE kljuc = ?', [$kljuc]) !== null) {
        return ['ok' => true, 'duplikat' => true];
    }
    $sad = sada();
    try {
        db_run('INSERT INTO mesalice (korisnik_id, boja, broj, nastalo, uneto, kljuc) VALUES (?, ?, ?, ?, ?, ?)', [$korisnik_id, $boja, $broj, $sad, $sad, $kljuc]);
    } catch (PDOException $e) {
        // istovremeno slanje iste forme: drugi upis odbija jedinstveni ključ – to je u redu
        if ($kljuc !== null && (int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => true, 'duplikat' => true];
        }
        throw $e;
    }
    return ['ok' => true, 'duplikat' => false];
}

/** Meko brisanje unosa mešalica (ostaje zapis u dnevniku izmena). Vraća ['ok' => true] ili ['ok' => false, 'greska']. */
function mesalica_obrisi(int $id, int $korisnik_id, string $akcija = 'brisanje'): array
{
    return db_trans(static function () use ($id, $korisnik_id, $akcija): array {
        $m = db_one('SELECT m.*, k.ime FROM mesalice m JOIN korisnici k ON k.id = m.korisnik_id WHERE m.id = ? FOR UPDATE', [$id]);
        if ($m === null || (int)$m['obrisan'] === 1) {
            return ['ok' => false, 'greska' => 'Taj unos ne postoji ili je već obrisan.'];
        }
        db_run('UPDATE mesalice SET obrisan = 1, obrisan_u = ?, obrisao_id = ? WHERE id = ?', [sada(), $korisnik_id, $id]);
        dnevnik_upis($akcija, 'mesalica', $id, 'Mešalice: ' . $m['ime'] . ' – ' . (int)$m['broj'] . ' × ' . $m['boja'] . ' (' . datum_vreme_srp((string)$m['nastalo']) . ')');
        return ['ok' => true];
    });
}

/** Poslednji unos mešalica radnika, ako je upisan pre manje od BRISANJE_RADNIK_MIN minuta (samo njega sme da obriše). */
function mesalica_radnik_sme_da_obrise(int $radnik_id): ?array
{
    $m = db_one('SELECT * FROM mesalice WHERE korisnik_id = ? AND obrisan = 0 ORDER BY id DESC LIMIT 1', [$radnik_id]);
    if ($m === null) {
        return null;
    }
    return time() - (int)strtotime((string)$m['uneto']) <= BRISANJE_RADNIK_MIN * 60 ? $m : null;
}

/** Zbir po boji za listu unosa: ['Crveni' => 5, 'Crni' => 3] (redom po prvom pojavljivanju). */
function mesalice_po_boji(array $unosi): array
{
    $z = [];
    foreach ($unosi as $u) {
        $z[(string)$u['boja']] = ($z[(string)$u['boja']] ?? 0) + (int)$u['broj'];
    }
    return $z;
}

/** "Crveni 5 · Crni 3" */
function mesalice_boje_tekst(array $po_boji): string
{
    return implode(' · ', array_map(static fn(string $b, int $n): string => $b . ' ' . broj($n), array_keys($po_boji), $po_boji));
}
