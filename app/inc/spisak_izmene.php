<?php
/**
 * Izmene spiska dok je nacrt (administrator): zajedničko za stranicu spiska i "Spisak sa papira".
 * Upis robe za mesto (više artikala odjednom), zbirne palete i provera unosa iz formi.
 */
declare(strict_types=1);

/** Greška pri izmeni nacrta – poništava i ono što je u toj izmeni već upisano. */
final class GreskaNacrta extends RuntimeException
{
}

/**
 * Izmena dok je spisak nacrt: zaključava spisak, proverava status i izvršava $fn u jednoj transakciji.
 * $fn vraća poruku o uspehu ('' = nema izmena) ili ['greska' => tekst] – tada se sve poništava.
 * Vraća true kad je izmena upisana.
 */
function u_nacrtu(int $id, callable $fn): bool
{
    return u_spisku($id, ['nacrt'], $fn);
}

/** Kao u_nacrtu(), za izmene koje su dozvoljene i u drugim statusima (mesta: i dok spisak čeka utovar). */
function u_spisku(int $id, array $statusi, callable $fn): bool
{
    try {
        $rez = db_trans(static function () use ($id, $statusi, $fn): string {
            $sp = spisak_zakljucaj($id);
            if ($sp === null || !in_array($sp['status'], $statusi, true)) {
                throw new GreskaNacrta($statusi === ['nacrt']
                    ? 'Spisak se može menjati samo dok je nacrt. Ako je poslat na utovar, prvo ga vratite u nacrt.'
                    : 'Mesta mogu da se menjaju dok spisak nije utovaren (ni otkazan).');
            }
            $r = $fn($sp);
            if (is_array($r)) {
                throw new GreskaNacrta((string)$r['greska']);
            }
            if ($r !== '') {
                db_run('UPDATE spiskovi SET izmenjen = ? WHERE id = ?', [sada(), $id]);
            }
            return $r;
        });
    } catch (GreskaNacrta $g) {
        flash_dodaj('greska', $g->getMessage());
        return false;
    }
    if ($rez === '') {
        flash_dodaj('info', 'Nema izmena.');
        return false;
    }
    spisak_dnevnik($id, $rez);
    flash_dodaj('uspeh', ucfirst($rez) . '.');
    return true;
}

/**
 * Broj, kupac i adresa mesta iz forme (m_broj[id], m_kupac[id], m_adresa[id]; mesto koga nema u formi se ne dira).
 * Broj se menja kako god korisnik hoće: ako je novi broj već zauzet, ta dva mesta zamene brojeve (forma šalje
 * polja svih mesta, pa se menja samo ono što se razlikuje od upisanog u bazi). Vraća ['izmene' => broj
 * izmenjenih mesta, 'zamene' => [opis …], 'mesta' => [id izmenjenih]] ili ['greska' => tekst].
 */
function mesta_sacuvaj(int $id, array $p_broj, array $p_kupac, array $p_adresa): array
{
    $mesta = spisak_mesta($id);
    $radni = [];                                     // id mesta => broj, posle zamena
    foreach ($mesta as $m) {
        $radni[(int)$m['id']] = (int)$m['broj'];
    }
    $zamenjen = [];                                  // mesta koja su broj dobila zamenom
    foreach ($mesta as $m) {
        $mid = (int)$m['id'];
        if (!is_string($p_broj[$mid] ?? null)) {
            continue;
        }
        [$broj, $greska] = mesto_broj_iz($p_broj[$mid]);
        if ($greska !== null) {
            return ['greska' => mesto_naslov((int)$m['broj'], $m['kupac']) . ': ' . lcfirst($greska)];
        }
        if ($broj === (int)$m['broj'] || $broj === $radni[$mid]) {
            continue;                                // nije menjan (ili je već dobio taj broj zamenom)
        }
        $drugi = array_search($broj, $radni, true);
        if ($drugi !== false) {
            $radni[$drugi] = $radni[$mid];
            $zamenjen[$drugi] = true;
        }
        $radni[$mid] = $broj;
    }
    $izmene = 0;
    $zamene = [];
    $izmenjena = [];
    foreach ($mesta as $m) {
        $mid = (int)$m['id'];
        $kupac = is_string($p_kupac[$mid] ?? null) ? ulaz_str($p_kupac, (string)$mid, 120) : (string)$m['kupac'];
        $adresa = is_string($p_adresa[$mid] ?? null) ? ulaz_str($p_adresa, (string)$mid, 255) : (string)$m['adresa'];
        $kupac_id = $kupac === '' ? null : kupac_id_za($kupac);
        if ($radni[$mid] !== (int)$m['broj'] || $kupac_id !== ($m['kupac_id'] === null ? null : (int)$m['kupac_id']) || $adresa !== (string)$m['adresa']) {
            db_run('UPDATE spisak_mesta SET broj = ?, kupac_id = ?, adresa = ? WHERE id = ?', [$radni[$mid], $kupac_id, $adresa !== '' ? $adresa : null, $mid]);
            $izmene++;
            $izmenjena[] = $mid;
            if (isset($zamenjen[$mid]) && $radni[$mid] !== (int)$m['broj']) {
                $zamene[] = mesto_naslov((int)$m['broj'], $m['kupac']) . ' je sada Mesto ' . $radni[$mid];
            }
        }
    }
    return ['izmene' => $izmene, 'zamene' => $zamene, 'mesta' => $izmenjena];
}

/** Težina iz forme ("12,5") ili null. Vraća [vrednost, greška]. */
function kg_iz_posta(string $polje): array
{
    $t = str_replace(',', '.', post_str($polje, 10));
    if ($t === '') {
        return [null, null];
    }
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $t) || (float)$t <= 0) {
        return [null, 'Težina mora biti broj veći od 0 (npr. 25 ili 12,5).'];
    }
    return [round((float)$t, 2), null];
}

/** Broj mesta iz forme. Vraća [broj, greška]. */
function mesto_broj_iz(string $t): array
{
    $t = trim($t);
    if (!ctype_digit($t) || (int)$t < 1 || (int)$t > 999) {
        return [0, 'Broj mesta mora biti od 1 do 999.'];
    }
    return [(int)$t, null];
}

/**
 * Mesto ovog spiska (unutar izmene nacrta), sa nazivom kupca. Bez izabranog mesta (stara stranica
 * otvorena u pregledaču) radi samo kad spisak ima jedno mesto. Vraća red mesta ili ['greska' => tekst].
 */
function mesto_na_spisku(int $id, int $mesto_id): array
{
    if ($mesto_id === 0) {
        $mesta = db_all('SELECT id FROM spisak_mesta WHERE spisak_id = ?', [$id]);
        if (count($mesta) !== 1) {
            return ['greska' => $mesta ? 'Izaberite mesto za koje je roba.' : 'Prvo dodajte mesto istovara (Dodaj mesto).'];
        }
        $mesto_id = (int)$mesta[0]['id'];
    }
    $m = db_one('SELECT m.*, k.naziv AS kupac FROM spisak_mesta m LEFT JOIN kupci k ON k.id = m.kupac_id WHERE m.id = ? AND m.spisak_id = ?', [$mesto_id, $id]);
    return $m ?? ['greska' => 'To mesto više nije na spisku.'];
}

/** "Mesto 8 · Agrocentar" za mesto po id-u ('' ako ga nema). */
function mesto_opis(int $mesto_id): string
{
    $m = db_one('SELECT m.broj, k.naziv AS kupac FROM spisak_mesta m LEFT JOIN kupci k ON k.id = m.kupac_id WHERE m.id = ?', [$mesto_id]);
    return $m === null ? '' : mesto_naslov((int)$m['broj'], $m['kupac']);
}

/** Kad dodavanje ne uspe, upisano ostaje u formi (ne mora ponovo da se kuca). */
function zapamti_formu(int $id, string $vrsta, int $mesto_id, array $polja): void
{
    $_SESSION['spisak_forma'][$id] = ['vrsta' => $vrsta, 'mesto' => $mesto_id, 'polja' => $polja];
}

/** Niz tekstova iz forme (npr. r_tekst[]): samo tekstovi, skraćeni, najviše $n redova. */
function post_niz(string $kljuc, int $max = 120, int $n = 30): array
{
    $v = is_array($_POST[$kljuc] ?? null) ? array_slice(array_values($_POST[$kljuc]), 0, $n) : [];
    return array_map(static fn($x): string => is_string($x) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $x)), 0, $max) : '', $v);
}

/** Polja forme "Dodaj robu" (više artikala), da ostanu upisana kad dodavanje ne uspe. */
function roba_polja(): array
{
    return [
        'r_tekst' => post_niz('r_tekst'),
        'r_id'    => post_niz('r_id', 10),
        'r_pal'   => post_niz('r_pal', 8),
        'r_pak'   => post_niz('r_pak', 8),
        'r_kom'   => post_niz('r_kom', 8),
    ];
}

/**
 * Jedan red robe: artikal (izabran id ili otkucan tekst) i količina u paletama, paketima i komadima.
 * U zbirnu paletu ($u_paleti) ne idu cele palete – upisuje se koliko ide na jednu paletu.
 * Artikal kog nema u katalogu ("kora") je ostalo: samo naziv i komadi (roba_ostalo()).
 * Vraća ['sku', 'komadi', 'pal', 'pak', 'kom'] (za ostalo 'sku' => null i 'naziv') ili ['greska' => tekst].
 */
function roba_red(int $sku_id, string $tekst, string $pal, string $pak, string $kom, bool $u_paleti): array
{
    [$sku, $greska, $nepoznat] = sku_iz_unosa($sku_id, $tekst);
    if ($sku === null) {
        if (!$nepoznat) {
            return ['greska' => (string)$greska];
        }
        // van kataloga, a polja za količinu prazna: količina može da piše i u nazivu ("5 komada kesa", "kese 5")
        $k = $pal === '' && $pak === '' && $kom === '' ? kolicina_iz_naziva($tekst) : null;
        if ($k === null) {
            return roba_ostalo($tekst, $pal, $pak, $kom, $u_paleti);
        }
        if (!sku_iz_unosa(0, $k['naziv'])[2]) {
            // ostatak je artikal iz kataloga ("humovit 50 5") – ne pogađa se da li su palete, paketi ili komadi
            return ['greska' => '„' . $tekst . '“ – dodirnite pakovanje, pa količinu upišite u polja ispod (palete, paketi, komadi).'];
        }
        return roba_ostalo($k['naziv'], '', '', (string)$k['kom'], $u_paleti);
    }
    if ($u_paleti && (int)$pal > 0) {
        return ['greska' => 'u zbirnu paletu upišite pakete i/ili komade (koliko ide na jednu paletu), ne cele palete.'];
    }
    $r = popis_vrednost('', $u_paleti ? '' : $pal, $pak, $kom, $sku);
    if (isset($r['greska'])) {
        return ['greska' => (string)$r['greska']];
    }
    if (!$r['komadi']) {
        return ['greska' => 'upišite količinu (palete, pakete i/ili komade) za ' . sku_naziv($sku) . '.'];
    }
    return ['sku' => $sku, 'komadi' => (int)$r['komadi'], 'pal' => $u_paleti ? 0 : (int)$pal, 'pak' => (int)$pak, 'kom' => (int)$kom];
}

/**
 * Roba koje nema u katalogu ("kora", "saksije 30 cm") ide na spisak kao ostalo: naziv i broj komada,
 * bez stanja i bez težine. Vraća red kao roba_red() ('sku' => null, 'naziv') ili ['greska' => tekst].
 */
function roba_ostalo(string $tekst, string $pal, string $pak, string $kom, bool $u_paleti): array
{
    $naziv = ostalo_naziv($tekst);
    if ($u_paleti) {
        return ['greska' => '„' . $naziv . '“ nije u katalogu – roba van kataloga ne ide u zbirnu paletu (Gde ide: Posebne stavke).'];
    }
    if (trim($pal, '0') !== '' || trim($pak, '0') !== '') {
        return ['greska' => '„' . $naziv . '“ nije u katalogu (ostalo) – upišite samo broj komada.'];
    }
    if (!ctype_digit($kom) || (int)$kom <= 0) {
        return ['greska' => 'upišite broj komada za „' . $naziv . '“ (nije u katalogu – ide na spisak kao ostalo).'];
    }
    return ['sku' => null, 'naziv' => $naziv, 'komadi' => (int)$kom, 'pal' => 0, 'pak' => 0, 'kom' => 0];   // kao staro ostalo: samo količina
}

/**
 * Broj komada upisan u naziv robe van kataloga: "5 komada kesa", "5 kom kese", "5 kesa", "kese 5 kom", "kese 5",
 * "kese - 5". Vraća ['naziv' => naziv bez količine, 'kom' => broj] ili null (npr. "saksije 30 cm", "2 pal kese").
 * Isto prepoznaje assets/js/spisak.js (kolicinaIzNaziva).
 */
function kolicina_iz_naziva(string $tekst): ?array
{
    $t = trim((string)preg_replace('/\s+/u', ' ', $tekst));
    $kom = '(?:kom(?:ad[ai]?)?|kpl)\.?';
    if (preg_match('/^(\d{1,6})\s*(?:x\s*)?(?:' . $kom . ')?\s+(\S.*)$/iu', $t, $m)
        && preg_match('/^(?:pal|plt|pak)/iu', $m[2]) !== 1) {
        $naziv = $m[2];
        $broj = $m[1];
    } elseif (preg_match('/^(.*?\S)(?:\s*[-=:*]|\s+[x×])?\s*(\d{1,6})\s*(?:' . $kom . ')?$/iu', $t, $m)) {
        $naziv = $m[1];
        $broj = $m[2];
    } else {
        return null;
    }
    return (int)$broj > 0 && preg_match('/\p{L}{2}/u', $naziv) === 1 ? ['naziv' => $naziv, 'kom' => (int)$broj] : null;
}

/** Naziv robe van kataloga kako se upisuje: "kora" → "Kora". */
function ostalo_naziv(string $tekst): string
{
    $t = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $tekst)), 0, 120);
    return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

/** Naziv reda robe za poruke: "Humovit · 50 l" ili "Kora (ostalo)". */
function roba_naziv(array $r): string
{
    return $r['sku'] !== null ? sku_naziv($r['sku']) : $r['naziv'] . ' (ostalo)';
}

/** Dodaje red robe u zbir po artiklu (isti artikal dva puta = jedna stavka sa zbirom; ostalo po nazivu). */
function roba_saberi(array &$redovi, array $r): void
{
    $kljuc = $r['sku'] !== null ? (int)$r['sku']['sku_id'] : 'o:' . mb_strtolower($r['naziv']);
    if (!isset($redovi[$kljuc])) {
        $redovi[$kljuc] = $r;
        return;
    }
    foreach (['komadi', 'pal', 'pak', 'kom'] as $k) {
        $redovi[$kljuc][$k] += $r[$k];
    }
}

/**
 * Redovi forme "Dodaj robu" (r_tekst[], r_id[], r_pal[], r_pak[], r_kom[]): prazni se preskaču, isti artikal se sabira.
 * Vraća [[ključ iz roba_saberi() => red iz roba_red()], greška|null] – greška kaže i koji je red ("Red 2: …").
 */
function roba_iz_posta(bool $u_paleti): array
{
    $p = roba_polja();
    $redovi = [];
    $n = max(count($p['r_tekst']), count($p['r_id']));
    for ($i = 0; $i < $n; $i++) {
        $t = $p['r_tekst'][$i] ?? '';
        $sid = ctype_digit($p['r_id'][$i] ?? '') ? (int)$p['r_id'][$i] : 0;
        [$pal, $pak, $kom] = [$p['r_pal'][$i] ?? '', $p['r_pak'][$i] ?? '', $p['r_kom'][$i] ?? ''];
        if ($t === '' && $sid === 0 && $pal . $pak . $kom === '') {
            continue;
        }
        $r = roba_red($sid, $t, $pal, $pak, $kom, $u_paleti);
        if (isset($r['greska'])) {
            return [[], 'Red ' . ($i + 1) . ': ' . $r['greska']];
        }
        roba_saberi($redovi, $r);
    }
    return $redovi ? [$redovi, null] : [[], 'Upišite bar jedan artikal i količinu (npr. humovit, pa izaberite 50 l i upišite 2 palete).'];
}

/**
 * Upisuje robu za mesto (unutar izmene nacrta): nova stavka ili zamena količine ako je artikal već tu
 * (ostalo: ista roba = isti naziv u komadima). $pid > 0 = u zbirnu paletu (količina je za jednu paletu,
 * ukupno = × $broj paleta). Vraća [opisi za poruku, nazivi zamenjenih stavki] ili ['greska' => tekst].
 */
function spisak_upisi_robu(int $id, int $mid, int $pid, int $broj, array $redovi): array
{
    $opisi = [];
    $zamenjeni = [];
    foreach ($redovi as $r) {
        $komadi = (int)$r['komadi'] * max(1, $broj);
        if ($komadi > 99999999) {
            return ['greska' => roba_naziv($r) . ': količina je prevelika.'];
        }
        $postoji = $r['sku'] === null
            ? db_one("SELECT id, naziv FROM spisak_stavke WHERE spisak_id = ? AND mesto_id = ? AND paleta_id = ? AND sku_id IS NULL AND naziv = ? AND COALESCE(jedinica, 'kom') = 'kom'", [$id, $mid, $pid, $r['naziv']])
            : db_one('SELECT id FROM spisak_stavke WHERE spisak_id = ? AND mesto_id = ? AND paleta_id = ? AND sku_id = ?', [$id, $mid, $pid, (int)$r['sku']['sku_id']]);
        if ($postoji !== null) {
            db_run('UPDATE spisak_stavke SET kolicina = ?, palete = ?, paketi = ?, komadi = ? WHERE id = ?', [$komadi, $r['pal'], $r['pak'], $r['kom'], (int)$postoji['id']]);
            $ime = roba_naziv(isset($postoji['naziv']) ? ['naziv' => $postoji['naziv']] + $r : $r);    // ostalo: naziv kako je već upisan
            $zamenjeni[] = $ime;
            $opisi[] = $ime . ' – ' . broj($komadi) . ' kom (bilo je već na spisku – zamenjena količina)';
            continue;
        }
        $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
        if ($r['sku'] === null) {
            // ostalo: ne vodi se na stanju, bez težine
            db_run(
                "INSERT INTO spisak_stavke (spisak_id, mesto_id, paleta_id, sku_id, naziv, jedinica, tezina_kg, kolicina, redosled) VALUES (?, ?, ?, NULL, ?, 'kom', NULL, ?, ?)",
                [$id, $mid, $pid, $r['naziv'], $komadi, $red]
            );
        } else {
            db_run(
                'INSERT INTO spisak_stavke (spisak_id, mesto_id, paleta_id, sku_id, kolicina, palete, paketi, komadi, redosled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $mid, $pid, (int)$r['sku']['sku_id'], $komadi, $r['pal'], $r['pak'], $r['kom'], $red]
            );
        }
        $opisi[] = roba_naziv($r) . ' – ' . broj($komadi) . ' kom';
    }
    return [$opisi, $zamenjeni];
}

/**
 * Redovi zbirne palete iz teksta: [[sku_id, tekst, koliko na jednoj paleti ("25", "1/2", "3 pak")], ...].
 * Vraća [[sku_id => ['sku', 'komadi', 'paketi', 'kom']], greška|null].
 */
function zbirna_redovi(array $ulaz): array
{
    $redovi = [];
    $n = 0;
    foreach ($ulaz as [$sku_id, $tekst, $kol]) {
        if ($tekst === '' && $kol === '' && $sku_id === 0) {
            continue;
        }
        if (++$n > 20) {
            return [[], 'Na jednoj zbirnoj paleti može najviše 20 artikala.'];
        }
        if ($tekst === '' && $sku_id === 0) {
            return [[], 'Upišite artikal uz količinu „' . $kol . '“.'];
        }
        [$sku, $greska] = sku_iz_unosa($sku_id, $tekst);
        if ($sku === null) {
            return [[], (string)$greska];
        }
        $q = kolicina_na_paleti($kol, $sku);
        if (isset($q['greska'])) {
            return [[], $q['greska']];
        }
        $sid = (int)$sku['sku_id'];
        if (isset($redovi[$sid])) {
            foreach (['komadi', 'paketi', 'kom'] as $k) {
                $redovi[$sid][$k] += $q[$k];
            }
        } else {
            $redovi[$sid] = ['sku' => $sku] + $q;
        }
    }
    return $redovi ? [$redovi, null] : [[], 'Upišite bar jedan artikal i koliko ide na paletu (npr. rizla bela sitna 1/2).'];
}

/**
 * Nova zbirna paleta za mesto (unutar izmene nacrta), sa robom iz zbirna_redovi() ili prazna.
 * Naziv "Zbirna paleta N" kad nije upisan (broj ide redom na celom kamionu). Vraća ['pid', 'naziv', 'opis'] ili ['greska'].
 */
function spisak_nova_zbirna(int $id, int $mid, string $naziv, int $broj, array $redovi): array
{
    if ($naziv === '') {
        $nazivi = array_column(db_all('SELECT naziv FROM spisak_palete WHERE spisak_id = ?', [$id]), 'naziv');
        $n = count($nazivi) + 1;
        while (in_array('Zbirna paleta ' . $n, $nazivi, true)) {
            $n++;
        }
        $naziv = 'Zbirna paleta ' . $n;
    }
    $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_palete WHERE spisak_id = ?', [$id]) + 10;
    db_run('INSERT INTO spisak_palete (spisak_id, mesto_id, naziv, broj, redosled) VALUES (?, ?, ?, ?, ?)', [$id, $mid, $naziv, $broj, $red]);
    $pid = db_id();
    $opis = [];
    foreach ($redovi as $sid => $r) {
        if ($r['komadi'] * $broj > 99999999) {
            return ['greska' => 'Količina je prevelika.'];
        }
        $red_st = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
        db_run(
            'INSERT INTO spisak_stavke (spisak_id, mesto_id, paleta_id, sku_id, kolicina, palete, paketi, komadi, redosled) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [$id, $mid, $pid, (int)$sid, $r['komadi'] * $broj, $r['paketi'], $r['kom'], $red_st]
        );
        $opis[] = sku_naziv($r['sku']) . ' ' . broj($r['komadi']);
    }
    return ['pid' => $pid, 'naziv' => $naziv, 'opis' => $opis];
}
