<?php
/**
 * Administrator – Podešavanja → Artikli → jedan artikal:
 *  1) osnovni podaci, 2) varijante (boje / granulacije), 3) pakovanja koja artikal ima,
 *  4) komada po paleti i minimum zalihe za svako pakovanje (i svaku varijantu),
 *  5) kako artikal piše na spisku za utovar i kuca se pri unosu (Humovit: H50L; ostali ime, npr. B oblutak 4/6).
 *
 * Ništa se ne briše: varijante, pakovanja i artikli se samo isključuju, pa istorija i stanje ostaju tačni.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

zahtevaj_ulogu('admin');

$id = je_post() ? post_int('id') : get_int('id');
$a = db_one('SELECT a.*, k.naziv AS kategorija FROM artikli a JOIN kategorije k ON k.id = a.kategorija_id WHERE a.id = ?', [$id]);
if ($a === null) {
    stranica_greske(404, 'Artikal ne postoji', 'Traženi artikal nije pronađen.', 'admin/artikli.php', 'Nazad na artikle');
}
$stranica = 'admin/artikal.php?id=' . $id;

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);

    if ($akcija === 'osnovno') {
        $naziv = post_str('naziv', 100);
        $kat = post_int('kategorija_id');
        $oznaka = post_str('oznaka', 20);
        $nv = post_str('nv', 30);
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv artikla.');
        } elseif (db_one('SELECT id FROM kategorije WHERE id = ?', [$kat]) === null) {
            flash_dodaj('greska', 'Izaberite kategoriju.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM artikli WHERE kategorija_id = ? AND naziv = ? AND id <> ?', [$kat, $naziv, $id]) > 0) {
            flash_dodaj('greska', 'U toj kategoriji već postoji artikal sa tim nazivom.');
        } else {
            db_run(
                'UPDATE artikli SET naziv = ?, kategorija_id = ?, oznaka = ?, naziv_varijante = ? WHERE id = ?',
                [$naziv, $kat, $oznaka !== '' ? $oznaka : null, $nv !== '' ? $nv : null, $id]
            );
            dnevnik_podesavanje('Izmenjen artikal: ' . $a['naziv'] . ($a['naziv'] !== $naziv ? ' → ' . $naziv : ''), 'podesavanje', $id);
            flash_dodaj('uspeh', 'Podaci o artiklu su sačuvani.');
        }
    } elseif ($akcija === 'status') {
        $novo = (int)$a['aktivan'] === 1 ? 0 : 1;
        db_run('UPDATE artikli SET aktivan = ? WHERE id = ?', [$novo, $id]);
        dnevnik_podesavanje(($novo ? 'Uključen' : 'Isključen') . ' artikal: ' . $a['naziv'], 'podesavanje', $id);
        flash_dodaj('uspeh', 'Artikal je ' . ($novo ? 'uključen i nudi se za unos.' : 'isključen (ne nudi se za unos; stanje ostaje vidljivo dok ga ima).'));
    } elseif ($akcija === 'var_nova') {
        $naziv = post_str('naziv', 60);
        $nv = post_str('nv', 30);
        if ($naziv === '') {
            flash_dodaj('greska', 'Upišite naziv.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM varijante WHERE artikal_id = ? AND naziv = ?', [$id, $naziv]) > 0) {
            flash_dodaj('greska', '„' . $naziv . '“ već postoji kod ovog artikla.');
        } else {
            $upozorenje = db_trans(static function () use ($id, $naziv, $nv, $a): string {
                $imao = (int)db_val('SELECT COUNT(*) FROM varijante WHERE artikal_id = ? AND aktivan = 1', [$id]) > 0;
                // pakovanja koja artikal već ima – nova varijanta ih dobija odmah, sa istom paletom
                $sablon = db_all('SELECT pakovanje_id, MAX(po_paleti) AS po, MAX(po_paketu) AS pp FROM sku WHERE artikal_id = ? AND aktivan = 1 GROUP BY pakovanje_id', [$id]);
                $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM varijante WHERE artikal_id = ?', [$id]) + 10;
                db_run('INSERT INTO varijante (artikal_id, naziv, redosled, aktivan) VALUES (?, ?, ?, 1)', [$id, $naziv, $red]);
                $vid = db_id();
                $upoz = '';
                if (!$imao) {
                    $ima_stanje = (int)db_val(
                        'SELECT COUNT(*) FROM unosi u JOIN sku s ON s.id = u.sku_id WHERE s.artikal_id = ? AND s.varijanta_id = 0 AND u.obrisan = 0',
                        [$id]
                    ) > 0;
                    db_run('UPDATE sku SET aktivan = 0 WHERE artikal_id = ? AND varijanta_id = 0', [$id]);
                    if ($ima_stanje) {
                        $upoz = ' Ranije stanje bez izbora ostaje vidljivo u Stanju dok ga ne prebrojite u Popisu.';
                    }
                }
                if ($a['naziv_varijante'] === null || $a['naziv_varijante'] === '') {
                    db_run('UPDATE artikli SET naziv_varijante = ? WHERE id = ?', [$nv !== '' ? $nv : 'Varijanta', $id]);
                }
                foreach ($sablon as $s) {
                    db_run(
                        'INSERT INTO sku (artikal_id, varijanta_id, pakovanje_id, po_paleti, po_paketu, min_zaliha, aktivan) VALUES (?, ?, ?, ?, ?, 0, 1)',
                        [$id, $vid, (int)$s['pakovanje_id'], $s['po'] === null ? null : (int)$s['po'], $s['pp'] === null ? null : (int)$s['pp']]
                    );
                }
                dopuni_sifre();
                return $upoz;
            });
            dnevnik_podesavanje('Nova varijanta „' . $naziv . '“ kod artikla ' . $a['naziv'], 'podesavanje', $id);
            flash_dodaj('uspeh', '„' . $naziv . '“ je dodato.' . $upozorenje);
        }
    } elseif ($akcija === 'var_izmeni') {
        $vid = post_int('vid');
        $naziv = post_str('naziv', 60);
        $v = db_one('SELECT * FROM varijante WHERE id = ? AND artikal_id = ?', [$vid, $id]);
        if ($v === null || $naziv === '') {
            flash_dodaj('greska', 'Upišite naziv.');
        } elseif ((int)db_val('SELECT COUNT(*) FROM varijante WHERE artikal_id = ? AND naziv = ? AND id <> ?', [$id, $naziv, $vid]) > 0) {
            flash_dodaj('greska', '„' . $naziv . '“ već postoji kod ovog artikla.');
        } else {
            db_run('UPDATE varijante SET naziv = ? WHERE id = ?', [$naziv, $vid]);
            dnevnik_podesavanje('Varijanta kod artikla ' . $a['naziv'] . ': ' . $v['naziv'] . ' → ' . $naziv, 'podesavanje', $id);
            flash_dodaj('uspeh', 'Naziv je sačuvan.');
        }
    } elseif ($akcija === 'var_status') {
        $vid = post_int('vid');
        $v = db_one('SELECT * FROM varijante WHERE id = ? AND artikal_id = ?', [$vid, $id]);
        if ($v !== null) {
            $novo = (int)$v['aktivan'] === 1 ? 0 : 1;
            db_run('UPDATE varijante SET aktivan = ? WHERE id = ?', [$novo, $vid]);
            dnevnik_podesavanje(($novo ? 'Uključena' : 'Isključena') . ' varijanta „' . $v['naziv'] . '“ kod artikla ' . $a['naziv'], 'podesavanje', $id);
            flash_dodaj('uspeh', '„' . $v['naziv'] . '“ je ' . ($novo ? 'uključeno.' : 'isključeno (ne nudi se za unos).'));
        }
    } elseif ($akcija === 'var_pomeri') {
        if (db_one('SELECT id FROM varijante WHERE id = ? AND artikal_id = ?', [post_int('vid'), $id]) !== null) {
            pomeri_red('varijante', post_int('vid'), post_str('smer', 4));
        }
    } elseif ($akcija === 'pakovanja') {
        $dozvoljena = array_map(static fn(array $r): int => (int)$r['id'], db_all('SELECT id FROM pakovanja WHERE aktivan = 1'));
        $zeljena = [];
        foreach ((array)($_POST['pak'] ?? []) as $p) {
            if (is_string($p) && ctype_digit($p) && in_array((int)$p, $dozvoljena, true)) {
                $zeljena[] = (int)$p;
            }
        }
        if (!$zeljena) {
            flash_dodaj('greska', 'Artikal mora imati bar jedno pakovanje, inače se ne nudi za unos. Označite pakovanja, ili isključite ceo artikal ako ga više ne pravite. Ništa nije promenjeno.');
            preusmeri($stranica);
        }
        db_trans(static function () use ($id, $zeljena): void {
            $varijante = array_map(static fn(array $r): int => (int)$r['id'], db_all('SELECT id FROM varijante WHERE artikal_id = ? AND aktivan = 1', [$id]));
            $grupe = $varijante ?: [0];
            foreach ($zeljena as $pid) {
                $po = db_val('SELECT MAX(po_paleti) FROM sku WHERE artikal_id = ? AND pakovanje_id = ?', [$id, $pid]);
                $pp = db_val('SELECT MAX(po_paketu) FROM sku WHERE artikal_id = ? AND pakovanje_id = ?', [$id, $pid]);
                foreach ($grupe as $vid) {
                    $sku = db_one('SELECT id FROM sku WHERE artikal_id = ? AND varijanta_id = ? AND pakovanje_id = ?', [$id, $vid, $pid]);
                    if ($sku === null) {
                        db_run(
                            'INSERT INTO sku (artikal_id, varijanta_id, pakovanje_id, po_paleti, po_paketu, min_zaliha, aktivan) VALUES (?, ?, ?, ?, ?, 0, 1)',
                            [$id, $vid, $pid, $po === null ? null : (int)$po, $pp === null ? null : (int)$pp]
                        );
                    } else {
                        db_run('UPDATE sku SET aktivan = 1 WHERE id = ?', [$sku['id']]);
                    }
                }
            }
            foreach (db_all('SELECT id, pakovanje_id FROM sku WHERE artikal_id = ? AND aktivan = 1', [$id]) as $s) {
                if (!in_array((int)$s['pakovanje_id'], $zeljena, true)) {
                    db_run('UPDATE sku SET aktivan = 0 WHERE id = ?', [$s['id']]);
                }
            }
            dopuni_sifre();
        });
        dnevnik_podesavanje('Izmenjena pakovanja artikla ' . $a['naziv'], 'podesavanje', $id);
        flash_dodaj('uspeh', 'Pakovanja su sačuvana. Dole podesite komada u paketu, komada po paleti i minimum.');
    } elseif ($akcija === 'vrednosti') {
        $po_unos = (array)($_POST['po'] ?? []);
        $pp_unos = (array)($_POST['pp'] ?? []);
        $kg_unos = (array)($_POST['kg'] ?? []);
        $min_unos = (array)($_POST['min'] ?? []);
        $greske = [];
        $izmene = [];
        $upozorenja = [];
        foreach (db_all('SELECT s.id, s.po_paleti, s.po_paketu, s.tezina_kg, s.min_zaliha, p.kolicina AS pak_kolicina, p.jedinica FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id WHERE s.artikal_id = ?', [$id]) as $s) {
            $sid = (int)$s['id'];
            if (!array_key_exists($sid, $po_unos) && !array_key_exists($sid, $pp_unos) && !array_key_exists($sid, $min_unos) && !array_key_exists($sid, $kg_unos)) {
                continue;
            }
            $po = array_key_exists($sid, $po_unos) ? trim((string)$po_unos[$sid]) : ($s['po_paleti'] === null ? '' : (string)$s['po_paleti']);
            $pp = array_key_exists($sid, $pp_unos) ? trim((string)$pp_unos[$sid]) : ($s['po_paketu'] === null ? '' : (string)$s['po_paketu']);
            $kg = array_key_exists($sid, $kg_unos) ? str_replace(',', '.', trim((string)$kg_unos[$sid])) : ($s['tezina_kg'] === null ? '' : (string)(float)$s['tezina_kg']);
            $mn = array_key_exists($sid, $min_unos) ? trim((string)$min_unos[$sid]) : (string)$s['min_zaliha'];
            if ($kg !== '' && (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $kg) || (float)$kg <= 0)) {
                $greske[] = 'Težina jednog komada mora biti broj veći od 0 (npr. 5 ili 12,5 kg).';
                continue;
            }
            if ($po !== '' && (!ctype_digit($po) || (int)$po < 1 || (int)$po > 65535)) {
                $greske[] = 'Komada po paleti mora biti ceo broj od 1 do 65535.';
                continue;
            }
            if ($pp !== '' && (!ctype_digit($pp) || (int)$pp < 1 || (int)$pp > 65535)) {
                $greske[] = 'Komada u paketu mora biti ceo broj od 1 do 65535.';
                continue;
            }
            if ($mn !== '' && (!ctype_digit($mn) || (int)$mn > 99999999)) {
                $greske[] = 'Minimum mora biti ceo broj (0 ili veći).';
                continue;
            }
            if ($po !== '' && $pp !== '' && (int)$po % (int)$pp !== 0) {
                $upozorenja[] = pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica']) . ': paleta (' . (int)$po . ' kom) nije deljiva sa paketom (' . (int)$pp . ' kom)';
            }
            $izmene[] = [$sid, $po === '' ? null : (int)$po, $pp === '' ? null : (int)$pp, $kg === '' ? null : round((float)$kg, 2), $mn === '' ? 0 : (int)$mn];
        }
        if ($greske) {
            flash_dodaj('greska', $greske[0]);
        } else {
            db_trans(static function () use ($izmene): void {
                foreach ($izmene as [$sid, $po, $pp, $kg, $mn]) {
                    db_run('UPDATE sku SET po_paleti = ?, po_paketu = ?, tezina_kg = ?, min_zaliha = ? WHERE id = ?', [$po, $pp, $kg, $mn, $sid]);
                }
            });
            dnevnik_podesavanje('Izmenjeni minimumi, komada po paleti i u paketu i težina za artikal ' . $a['naziv'], 'podesavanje', $id);
            flash_dodaj('uspeh', 'Sačuvano.');
            if ($upozorenja) {
                flash_dodaj('upozorenje', 'Proverite brojeve: ' . implode('; ', $upozorenja) . '. Sačuvano je kako ste upisali.');
            }
        }
    } elseif ($akcija === 'sifre') {
        // kako artikal piše na spisku za utovar (H50L, B oblutak 4/6); isti tekst ne sme kod dva artikla
        $unos = is_array($_POST['sifra'] ?? null) ? $_POST['sifra'] : [];
        $nove = [];                                      // sku_id => kratko ime
        $kljucevi = [];                                  // za poređenje (bez razmaka, znakova i velikih slova)
        $greska = null;
        foreach (db_all('SELECT id FROM sku WHERE artikal_id = ?', [$id]) as $s) {
            $sid = (int)$s['id'];
            if (!is_string($unos[$sid] ?? null)) {
                continue;
            }
            $sifra = sifra_cista($unos[$sid]);
            if ($sifra === '') {
                $greska = 'Upišite kako artikal piše na spisku – polje ne može biti prazno.';
                break;
            }
            if (!sifra_ispravna($sifra)) {
                $greska = '„' . $sifra . '“: do 40 znakova – slova, cifre, razmak i / . , + ( ) -.';
                break;
            }
            $kljuc = sifra_samo_slova($sifra);
            if (isset($kljucevi[$kljuc])) {
                $greska = '„' . $sifra . '“ je upisano dva puta.';
                break;
            }
            $kljucevi[$kljuc] = $sid;
            $nove[$sid] = $sifra;
        }
        if ($greska === null && $nove) {
            // isto ne sme da piše ni kod artikla koji se ovde ne menja (i isključenog)
            foreach (db_all(
                'SELECT s.id, s.sifra, a.naziv AS artikal, v.naziv AS varijanta, p.kolicina AS pak_kolicina, p.jedinica FROM sku s
                 JOIN artikli a ON a.id = s.artikal_id JOIN pakovanja p ON p.id = s.pakovanje_id LEFT JOIN varijante v ON v.id = s.varijanta_id
                 WHERE s.sifra IS NOT NULL'
            ) as $t) {
                $sid = $kljucevi[sifra_samo_slova((string)$t['sifra'])] ?? null;
                if ($sid !== null && $sid !== (int)$t['id'] && !isset($nove[(int)$t['id']])) {
                    $greska = '„' . $nove[$sid] . '“ već piše kod drugog artikla (' . sku_naziv($t) . ').';
                    break;
                }
            }
        }
        if ($greska !== null) {
            flash_dodaj('greska', $greska . ' Ništa nije promenjeno.');
            preusmeri($stranica . '#sifre');
        }
        $izmena = db_trans(static function () use ($id, $nove): int {
            $stare = [];
            foreach (db_all('SELECT id, sifra FROM sku WHERE artikal_id = ?', [$id]) as $s) {
                $stare[(int)$s['id']] = (string)$s['sifra'];
            }
            $menja = array_filter($nove, static fn(string $sifra, int $sid): bool => ($stare[$sid] ?? '') !== $sifra, ARRAY_FILTER_USE_BOTH);
            // prvo prazno, da zamena dva imena (H5L ↔ H10L) ne udari u jedinstveni ključ
            foreach (array_keys($menja) as $sid) {
                db_run('UPDATE sku SET sifra = NULL WHERE id = ?', [$sid]);
            }
            foreach ($menja as $sid => $sifra) {
                db_run('UPDATE sku SET sifra = ? WHERE id = ?', [$sifra, $sid]);
            }
            return count($menja);
        });
        if ($izmena > 0) {
            dnevnik_podesavanje('Izmenjeno kako piše na spisku, artikal ' . $a['naziv'] . ' (' . implode(', ', $nove) . ')', 'podesavanje', $id);
            flash_dodaj('uspeh', 'Sačuvano – ovako će pisati na spisku.');
        } else {
            flash_dodaj('info', 'Nema izmena.');
        }
        preusmeri($stranica . '#sifre');
    } elseif ($akcija === 'bez_varijanti') {
        db_trans(static function () use ($id): void {
            db_run('UPDATE varijante SET aktivan = 0 WHERE artikal_id = ?', [$id]);
            db_run('UPDATE sku s JOIN pakovanja p ON p.id = s.pakovanje_id SET s.aktivan = 1 WHERE s.artikal_id = ? AND s.varijanta_id = 0 AND p.aktivan = 1', [$id]);
        });
        dnevnik_podesavanje('Artikal ' . $a['naziv'] . ' vraćen na stanje bez varijanti', 'podesavanje', $id);
        flash_dodaj('uspeh', 'Artikal je vraćen na stanje bez varijanti: stara pakovanja se ponovo nude za unos, a varijante su isključene (nisu obrisane).');
    }
    preusmeri($stranica);
}

$kategorije = db_all('SELECT id, naziv FROM kategorije ORDER BY redosled, id');
$varijante = db_all('SELECT * FROM varijante WHERE artikal_id = ? ORDER BY redosled, id', [$id]);
$sva_pakovanja = db_all('SELECT * FROM pakovanja WHERE aktivan = 1 ORDER BY jedinica, kolicina');
$sku_redovi = db_all(
    'SELECT s.*, p.kolicina AS pak_kolicina, p.jedinica FROM sku s JOIN pakovanja p ON p.id = s.pakovanje_id
     WHERE s.artikal_id = ? AND s.aktivan = 1 AND p.aktivan = 1 ORDER BY s.varijanta_id, p.jedinica, p.kolicina',
    [$id]
);
$izabrana_pakovanja = [];
foreach ($sku_redovi as $s) {
    $izabrana_pakovanja[(int)$s['pakovanje_id']] = true;
}
$naziv_izbora = $a['naziv_varijante'] ?: 'Varijanta';

// matrica: grupe po varijanti
$varijanta_naziv = [0 => 'Bez izbora (staro stanje)'];
foreach ($varijante as $v) {
    $varijanta_naziv[(int)$v['id']] = $v['naziv'] . ((int)$v['aktivan'] ? '' : ' (isključeno)');
}
$grupe = [];
foreach ($sku_redovi as $s) {
    $grupe[(int)$s['varijanta_id']][] = $s;
}
ksort($grupe);
$aktivnih_varijanti = count(array_filter($varijante, static fn(array $v): bool => (int)$v['aktivan'] === 1));
$imam_stara = isset($grupe[0]) && $aktivnih_varijanti > 0;
$ima_stara_pakovanja = (int)db_val('SELECT COUNT(*) FROM sku WHERE artikal_id = ? AND varijanta_id = 0', [$id]) > 0;

ui_start($a['naziv'], ['nav' => 'admin', 'aktivno' => 'podesavanja', 'js' => ['assets/js/artikal.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/artikli.php')) ?>"><?= ikona('nazad') ?> Svi artikli</a></p>

<?php if ((int)$a['aktivan'] === 1 && !$sku_redovi): ?>
    <div class="poruka poruka-greska" role="alert"><strong>Ovaj artikal se ne nudi za unos</strong> jer nema nijedno pakovanje<?= $aktivnih_varijanti === 0 && $varijante ? ' (sve varijante su isključene)' : '' ?>. Označite pakovanja u delu „Pakovanja“ ispod.</div>
<?php elseif ((int)$a['aktivan'] === 0): ?>
    <div class="poruka poruka-upozorenje" role="status">Artikal je <strong>isključen</strong> i ne nudi se za unos. Uključite ga dugmetom ispod „Osnovno“.</div>
<?php endif; ?>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/artikal.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="osnovno">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="kartica-naslov">
        <h2>Osnovno</h2>
        <span class="znacka <?= (int)$a['aktivan'] ? 'znacka-proizvodnja' : '' ?>"><?= (int)$a['aktivan'] ? 'uključen' : 'isključen' ?></span>
    </div>
    <div class="red-polja">
        <label for="naziv">Naziv</label>
        <input class="polje" id="naziv" name="naziv" type="text" maxlength="100" required value="<?= e($a['naziv']) ?>">
    </div>
    <div class="red-polja">
        <label for="kat">Kategorija</label>
        <select class="polje" id="kat" name="kategorija_id">
            <?php foreach ($kategorije as $k): ?><option value="<?= (int)$k['id'] ?>"<?= (int)$k['id'] === (int)$a['kategorija_id'] ? ' selected' : '' ?>><?= e($k['naziv']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="red-polja-2 red-polja">
        <div>
            <label for="oznaka">Oznaka (npr. PL)</label>
            <input class="polje" id="oznaka" name="oznaka" type="text" maxlength="20" value="<?= e($a['oznaka'] ?? '') ?>">
        </div>
        <div>
            <label for="nv">Izbor se zove</label>
            <input class="polje" id="nv" name="nv" type="text" maxlength="30" list="nazivi-izbora" value="<?= e($a['naziv_varijante'] ?? '') ?>" placeholder="npr. Boja">
            <datalist id="nazivi-izbora"><option value="Boja"><option value="Granulacija"><option value="Vrsta"><option value="Model"></datalist>
        </div>
    </div>
    <button class="btn btn-primary btn-blok" type="submit">Sačuvaj</button>
</form>
<form method="post" class="razmak-gore" action="<?= e(url('admin/artikal.php')) ?>"
      <?= (int)$a['aktivan'] ? 'data-potvrda="Isključiti artikal? Neće se nuditi za unos, a stanje ostaje vidljivo dok ga ima."' : '' ?>>
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="status">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-blok <?= (int)$a['aktivan'] ? 'btn-opasno' : 'btn-primary' ?>" type="submit"><?= (int)$a['aktivan'] ? 'Isključi artikal' : 'Uključi artikal' ?></button>
</form>

<section class="kartica razmak-gore" aria-labelledby="var-naslov">
    <div class="kartica-naslov"><h2 id="var-naslov"><?= e($a['naziv_varijante'] ? $naziv_izbora . ' (varijante)' : 'Boje / granulacije') ?></h2></div>
    <?php if (!$varijante): ?>
        <p class="pomoc">Ovaj artikal nema varijanti. Dodajte ih samo ako se isti artikal pravi u više boja, granulacija ili sličnog.</p>
    <?php endif; ?>
    <?php foreach ($varijante as $i => $v): ?>
        <div class="varijanta-red<?= (int)$v['aktivan'] ? '' : ' radnik-ugasen' ?>">
            <form method="post" class="varijanta-ime" action="<?= e(url('admin/artikal.php')) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="var_izmeni">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="vid" value="<?= (int)$v['id'] ?>">
                <input class="polje" name="naziv" type="text" maxlength="60" required value="<?= e($v['naziv']) ?>" aria-label="Naziv">
                <button class="btn btn-mali" type="submit">Sačuvaj</button>
            </form>
            <div class="grupa-dugmica">
                <?php foreach ([['var_status', (int)$v['aktivan'] ? 'Isključi' : 'Uključi', []], ['var_pomeri', '↑', ['smer' => 'gore']], ['var_pomeri', '↓', ['smer' => 'dole']]] as [$akc, $tekst, $dod]): ?>
                    <form method="post" class="forma-u-liniji" action="<?= e(url('admin/artikal.php')) ?>">
                        <?= csrf_polje() ?>
                        <input type="hidden" name="akcija" value="<?= e($akc) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="vid" value="<?= (int)$v['id'] ?>">
                        <?php foreach ($dod as $k => $vv): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($vv) ?>"><?php endforeach; ?>
                        <button class="btn btn-mali" type="submit"><?= e($tekst) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($varijante && $ima_stara_pakovanja && ($aktivnih_varijanti > 0 || !$sku_redovi)): ?>
        <form method="post" class="razmak-gore" action="<?= e(url('admin/artikal.php')) ?>"
              data-potvrda="Vratiti artikal na stanje bez varijanti? Varijante se isključuju (ne brišu), a stara pakovanja se ponovo nude za unos.">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="bez_varijanti">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-mali" type="submit">Vrati artikal na stanje bez varijanti</button>
        </form>
    <?php endif; ?>

    <form method="post" autocomplete="off" class="razmak-gore" action="<?= e(url('admin/artikal.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="var_nova">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if (!$a['naziv_varijante']): ?>
            <div class="red-polja">
                <label for="v-nv">Šta je to? (naslov izbora)</label>
                <input class="polje" id="v-nv" name="nv" type="text" maxlength="30" list="nazivi-izbora" placeholder="npr. Boja ili Granulacija">
            </div>
        <?php endif; ?>
        <div class="red-polja">
            <label for="v-naziv">Dodaj <?= e(mb_strtolower($a['naziv_varijante'] ?: 'varijantu')) ?></label>
            <input class="polje" id="v-naziv" name="naziv" type="text" maxlength="60" required placeholder="npr. Plavi ili 8-12 cm">
        </div>
        <button class="btn btn-primary btn-blok" type="submit">Dodaj</button>
        <p class="pomoc">Novo dobija ista pakovanja kao ostali. Isključivanjem se samo prestaje nuditi za unos – ništa se ne briše.</p>
    </form>
</section>

<form method="post" class="kartica" action="<?= e(url('admin/artikal.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="pakovanja">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="kartica-naslov"><h2>Pakovanja</h2></div>
    <p class="pomoc">Označite u kojim pakovanjima se ovaj artikal pravi<?= $varijante ? ' (važi za sve ' . e(mb_strtolower($naziv_izbora)) . ')' : '' ?>.</p>
    <div class="izbor-mreza razmak-dole">
        <?php foreach ($sva_pakovanja as $p): ?>
            <label class="ime">
                <input type="checkbox" name="pak[]" value="<?= (int)$p['id'] ?>"<?= isset($izabrana_pakovanja[(int)$p['id']]) ? ' checked' : '' ?>>
                <span><?= e(pakovanje_naziv($p['kolicina'], (string)$p['jedinica'])) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="pomoc">Nema željenog pakovanja? Dodajte ga u <a href="<?= e(url('admin/pakovanja.php')) ?>">Podešavanja → Pakovanja</a>.</p>
    <button class="btn btn-primary btn-blok" type="submit">Sačuvaj pakovanja</button>
</form>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/artikal.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="vrednosti">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="kartica-naslov"><h2>Paket, paleta, težina i minimum</h2></div>
    <?php if (!$grupe): ?>
        <p class="pomoc bez-margine">Prvo izaberite pakovanja iznad.</p>
    <?php else: ?>
        <p class="pomoc"><strong>Komada u paketu</strong> (transportno pakovanje) i <strong>komada po paleti</strong> koriste se kad se unosi po paketima ili paletama; prazno = ne nudi se taj način unosa. <strong>Kg/kom</strong> je okvirna težina jednog komada, za težinu tovara na spisku za utovar (kod pakovanja u kg ne mora da se upisuje). <strong>Minimum</strong> je broj komada ispod kog se na Stanju pali crveno upozorenje (0 = bez upozorenja).</p>
        <?php if (count($grupe) > 1): ?>
            <button type="button" class="btn btn-mali razmak-dole" data-kopiraj-sve>Prepiši vrednosti iz prvog na sve ostale</button>
        <?php endif; ?>
        <?php $prva = true; foreach ($grupe as $vid => $redovi): ?>
            <details class="matrica-grupa" <?= $prva ? 'open' : '' ?> data-grupa>
                <?php if (count($grupe) > 1 || $vid !== 0): ?>
                    <summary class="matrica-naslov"><?= e($varijanta_naziv[$vid] ?? '?') ?> <span class="pomoc">· <?= count($redovi) ?> pak.</span></summary>
                <?php endif; ?>
                <?php if ($vid === 0 && $imam_stara): ?>
                    <p class="poruka poruka-upozorenje">Ovo su stara pakovanja bez izbora. Ne nude se za unos jer artikal sad ima varijante.</p>
                <?php endif; ?>
                <div class="matrica-zaglavlje" aria-hidden="true"><span>Pak.</span><span>U paketu</span><span>Po paleti</span><span>Kg/kom</span><span>Min.</span></div>
                <?php foreach ($redovi as $s): ?>
                    <div class="matrica-red" data-pak="<?= (int)$s['pakovanje_id'] ?>">
                        <strong><?= e(pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?></strong>
                        <input class="polje" name="pp[<?= (int)$s['id'] ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5"
                               value="<?= $s['po_paketu'] === null ? '' : (int)$s['po_paketu'] ?>" placeholder="—" aria-label="Komada u paketu, <?= e(pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?>" data-polje="pp">
                        <input class="polje" name="po[<?= (int)$s['id'] ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5"
                               value="<?= $s['po_paleti'] === null ? '' : (int)$s['po_paleti'] ?>" placeholder="—" aria-label="Komada po paleti, <?= e(pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?>" data-polje="po">
                        <input class="polje" name="kg[<?= (int)$s['id'] ?>]" type="text" inputmode="decimal" maxlength="7"
                               value="<?= $s['tezina_kg'] === null ? '' : e(rtrim(rtrim(number_format((float)$s['tezina_kg'], 2, ',', ''), '0'), ',')) ?>"
                               placeholder="<?= $s['jedinica'] === 'kg' ? e(trim(pakovanje_naziv($s['pak_kolicina'], ''))) : '—' ?>" aria-label="Težina jednog komada u kg, <?= e(pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?>" data-polje="kg">
                        <input class="polje" name="min[<?= (int)$s['id'] ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8"
                               value="<?= (int)$s['min_zaliha'] ?>" aria-label="Minimum, <?= e(pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?>" data-polje="min">
                    </div>
                <?php endforeach; ?>
            </details>
        <?php $prva = false; endforeach; ?>
        <button class="btn btn-primary btn-blok razmak-gore" type="submit">Sačuvaj pakete, palete, težinu i minimum</button>
    <?php endif; ?>
</form>

<?php if ($sku_redovi): ?>
<form method="post" class="kartica" id="sifre" autocomplete="off" action="<?= e(url('admin/artikal.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="sifre">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="kartica-naslov"><h2>Kako piše na spisku</h2></div>
    <p class="pomoc">Ovako artikal piše na spisku za utovar, na štampi i u poruci za Viber/WhatsApp, i ovako se kuca pri unosu. Humovit ima kratke šifre (<strong>H50L</strong>), ostali artikli ime (npr. <strong>B oblutak 4/6</strong>). Do 40 znakova; isto ne sme da piše kod dva artikla.</p>
    <div class="sifre-mreza">
        <?php foreach ($grupe as $vid => $redovi): ?>
            <?php foreach ($redovi as $s): ?>
                <label class="sifra-red">
                    <span><?= e(($vid > 0 ? ($varijanta_naziv[$vid] ?? '?') . ' · ' : '') . pakovanje_naziv($s['pak_kolicina'], (string)$s['jedinica'])) ?></span>
                    <input class="polje" name="sifra[<?= (int)$s['id'] ?>]" type="text" maxlength="40" required spellcheck="false" value="<?= e((string)$s['sifra']) ?>">
                </label>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-blok razmak-gore" type="submit">Sačuvaj</button>
</form>
<?php endif; ?>
<?php
ui_end();
