<?php
/**
 * Pomoćne funkcije za Radnike i Podešavanja.
 */
declare(strict_types=1);

/** Previše lak PIN: 0000, 1111, ... ili niz 1234, 4321, 2345... */
function je_slab_pin(string $pin): bool
{
    if (preg_match('/^(\d)\1{3}$/', $pin)) {
        return true;
    }
    $c = array_map('intval', str_split($pin));
    $raste = true;
    $pada = true;
    for ($i = 1; $i < 4; $i++) {
        if ($c[$i] !== $c[$i - 1] + 1) {
            $raste = false;
        }
        if ($c[$i] !== $c[$i - 1] - 1) {
            $pada = false;
        }
    }
    return $raste || $pada;
}

/** Poruka o grešci za PIN ili null ako je ispravan. */
function provera_pina(string $pin): ?string
{
    if (!je_pin($pin)) {
        return 'PIN mora imati tačno 4 cifre.';
    }
    if (je_slab_pin($pin)) {
        return 'PIN je previše lak (npr. 0000, 1111, 1234). Izaberite drugi.';
    }
    return null;
}

/** Odjavljuje sve sesije jednog korisnika (npr. posle promene PIN-a ili isključenja). */
function sesije_obrisi_korisnika(int $uid, string $osim_id = ''): void
{
    db_run('DELETE FROM sesije WHERE id <> ? AND podaci LIKE ?', [$osim_id, 'uid|i:' . $uid . ';%']);
}

/** Beleška o promeni podešavanja u dnevniku izmena. */
function dnevnik_podesavanje(string $tekst, string $objekat = 'podesavanje', ?int $id = null): void
{
    dnevnik_upis('podesavanje', $objekat, $id, $tekst);
}

/**
 * Pomera red gore/dole unutar grupe (kategorije, artikli jedne kategorije, varijante jednog artikla)
 * i ponovo numeriše redosled.
 */
function pomeri_red(string $tabela, int $id, string $smer): void
{
    $grupe = ['kategorije' => '', 'artikli' => 'kategorija_id', 'varijante' => 'artikal_id'];
    if (!isset($grupe[$tabela])) {
        return;
    }
    $kolona = $grupe[$tabela];
    $red = db_one("SELECT * FROM $tabela WHERE id = ?", [$id]);
    if ($red === null) {
        return;
    }
    $sestre = $kolona === ''
        ? db_all("SELECT id FROM $tabela ORDER BY redosled, id")
        : db_all("SELECT id FROM $tabela WHERE $kolona = ? ORDER BY redosled, id", [$red[$kolona]]);
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $sestre);
    $i = array_search($id, $ids, true);
    if ($i === false) {
        return;
    }
    $j = $smer === 'gore' ? $i - 1 : $i + 1;
    if ($j >= 0 && $j < count($ids)) {
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    }
    foreach ($ids as $poz => $sid) {
        db_run("UPDATE $tabela SET redosled = ? WHERE id = ?", [($poz + 1) * 10, $sid]);
    }
}
