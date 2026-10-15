<?php
/**
 * Administrator – jedan spisak za utovar: podaci, stavke (artikli, zbirne palete i "ostalo"), zbir paleta
 * i težine, upozorenja kad nema dovoljno slobodne robe i promena statusa.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';
require __DIR__ . '/../inc/spiskovi.php';

$admin = zahtevaj_ulogu('admin');
$id = je_post() ? post_int('id') : get_int('id');
$sp = spisak_ucitaj($id);
if ($sp === null) {
    stranica_greske(404, 'Spisak ne postoji', 'Traženi spisak za utovar ne postoji.', 'admin/utovar.php', 'Svi spiskovi');
}
$stranica = 'admin/spisak.php?id=' . $id;

/**
 * Izmena dok je spisak nacrt: zaključava spisak, proverava status i izvršava $fn.
 * $fn vraća poruku o uspehu ili ['greska' => tekst].
 */
function u_nacrtu(int $id, callable $fn): void
{
    $rez = db_trans(static function () use ($id, $fn) {
        $sp = spisak_zakljucaj($id);
        if ($sp === null || $sp['status'] !== 'nacrt') {
            return ['greska' => 'Spisak se može menjati samo dok je nacrt. Ako je poslat na utovar, prvo ga vratite u nacrt.'];
        }
        $r = $fn($sp);
        if (!is_array($r)) {
            db_run('UPDATE spiskovi SET izmenjen = ? WHERE id = ?', [sada(), $id]);
        }
        return $r;
    });
    if (is_array($rez)) {
        flash_dodaj('greska', $rez['greska']);
    } elseif ($rez === '') {
        flash_dodaj('info', 'Nema izmena.');
    } else {
        spisak_dnevnik($id, $rez);
        flash_dodaj('uspeh', ucfirst($rez) . '.');
    }
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

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $ukloni = post_int('ukloni');
    $ukloni_paletu = post_int('ukloni_paletu');
    $posle = $stranica;

    if ($ukloni_paletu > 0) {
        u_nacrtu($id, static function () use ($id, $ukloni_paletu) {
            $pl = db_one('SELECT * FROM spisak_palete WHERE id = ? AND spisak_id = ?', [$ukloni_paletu, $id]);
            if ($pl === null) {
                return ['greska' => 'Zbirna paleta ne postoji.'];
            }
            db_run('DELETE FROM spisak_stavke WHERE spisak_id = ? AND paleta_id = ?', [$id, $ukloni_paletu]);
            db_run('DELETE FROM spisak_palete WHERE id = ?', [$ukloni_paletu]);
            return 'uklonjena zbirna paleta „' . $pl['naziv'] . '“ sa svim artiklima';
        });
    } elseif ($akcija === 'nova_paleta') {
        $naziv = post_str('p_naziv', 60);
        $broj_t = post_str('p_broj', 4);
        $broj_t = $broj_t === '' ? '1' : $broj_t;
        if (!ctype_digit($broj_t) || (int)$broj_t < 1 || (int)$broj_t > 999) {
            flash_dodaj('greska', 'Broj ovakvih paleta mora biti od 1 do 999.');
        } else {
            $nova = 0;
            u_nacrtu($id, static function () use ($id, $naziv, $broj_t, &$nova) {
                $n = (int)db_val('SELECT COUNT(*) FROM spisak_palete WHERE spisak_id = ?', [$id]) + 1;
                $naziv = $naziv !== '' ? $naziv : 'Zbirna paleta ' . $n;
                $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_palete WHERE spisak_id = ?', [$id]) + 10;
                db_run('INSERT INTO spisak_palete (spisak_id, naziv, broj, redosled) VALUES (?, ?, ?, ?)', [$id, $naziv, (int)$broj_t, $red]);
                $nova = db_id();
                return 'napravljena zbirna paleta „' . $naziv . '“' . ((int)$broj_t > 1 ? ' (' . palete_tekst((int)$broj_t) . ')' : '')
                    . ' – dodajte u nju artikle (Gde ide: ' . $naziv . ')';
            });
            if ($nova > 0) {
                $posle = $stranica . '&u=' . $nova . '#dodaj';
            }
        }
    } elseif ($ukloni > 0) {
        u_nacrtu($id, static function () use ($id, $ukloni) {
            $st = db_one('SELECT * FROM spisak_stavke WHERE id = ? AND spisak_id = ?', [$ukloni, $id]);
            if ($st === null) {
                return ['greska' => 'Stavka ne postoji.'];
            }
            $naziv = $st['sku_id'] !== null ? sku_naziv((array)sku_podaci((int)$st['sku_id'])) : (string)$st['naziv'];
            if ((int)$st['paleta_id'] > 0) {
                $naziv .= ' (' . (string)db_val('SELECT naziv FROM spisak_palete WHERE id = ?', [(int)$st['paleta_id']]) . ')';
            }
            db_run('DELETE FROM spisak_stavke WHERE id = ?', [$ukloni]);
            return 'uklonjena stavka ' . $naziv;
        });
    } elseif ($akcija === 'zaglavlje') {
        [$p, $greska] = spisak_zaglavlje_iz_posta();
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            u_nacrtu($id, static function () use ($id, $p) {
                db_run(
                    'UPDATE spiskovi SET kupac_id = ?, datum = ?, vozilo = ?, vozac = ?, napomena = ? WHERE id = ?',
                    [kupac_id_za($p['kupac']), $p['datum'], $p['vozilo'] ?: null, $p['vozac'] ?: null, $p['napomena'] ?: null, $id]
                );
                return 'izmenjeni podaci (kupac ' . $p['kupac'] . ', utovar ' . datum_srp($p['datum']) . ')';
            });
        }
    } elseif ($akcija === 'dodaj') {
        $sku = sku_aktivan(post_int('sku_id'));
        $pid = max(0, post_int('paleta_id'));
        if ($pid > 0) {
            $posle = $stranica . '&u=' . $pid . '#dodaj';
        }
        if ($sku === null) {
            flash_dodaj('greska', 'Izaberite artikal i pakovanje.');
        } elseif ($pid > 0 && (int)post_str('pal', 8) > 0) {
            flash_dodaj('greska', 'U zbirnu paletu upišite pakete i/ili komade (koliko ide na jednu paletu), ne cele palete.');
        } else {
            $r = popis_vrednost('', $pid > 0 ? '' : ($_POST['pal'] ?? ''), $_POST['pak'] ?? '', $_POST['kom'] ?? '', $sku);
            if (isset($r['greska'])) {
                flash_dodaj('greska', $r['greska']);
            } elseif (!$r['komadi']) {
                flash_dodaj('greska', 'Upišite količinu (palete, pakete i/ili komade).');
            } else {
                $pal = $pid > 0 ? 0 : (int)post_str('pal', 8);
                $pak = (int)post_str('pak', 8);
                $kom = (int)post_str('kom', 8);
                u_nacrtu($id, static function () use ($id, $sku, $r, $pal, $pak, $kom, $pid) {
                    $broj = 1;
                    $gde = '';
                    if ($pid > 0) {
                        $pl = db_one('SELECT * FROM spisak_palete WHERE id = ? AND spisak_id = ?', [$pid, $id]);
                        if ($pl === null) {
                            return ['greska' => 'Zbirna paleta ne postoji.'];
                        }
                        $broj = (int)$pl['broj'];
                        $gde = ' (' . $pl['naziv'] . ')';
                    }
                    $komadi = (int)$r['komadi'] * $broj;
                    if ($komadi > 99999999) {
                        return ['greska' => 'Količina je prevelika.'];
                    }
                    $postoji = db_val('SELECT id FROM spisak_stavke WHERE spisak_id = ? AND paleta_id = ? AND sku_id = ?', [$id, $pid, (int)$sku['sku_id']]);
                    if ($postoji !== null) {
                        db_run('UPDATE spisak_stavke SET kolicina = ?, palete = ?, paketi = ?, komadi = ? WHERE id = ?', [$komadi, $pal, $pak, $kom, (int)$postoji]);
                        return 'stavka ' . sku_naziv($sku) . $gde . ' je već bila na spisku – zamenjena novom količinom (' . broj($komadi) . ' kom)';
                    }
                    $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
                    db_run(
                        'INSERT INTO spisak_stavke (spisak_id, paleta_id, sku_id, kolicina, palete, paketi, komadi, redosled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$id, $pid, (int)$sku['sku_id'], $komadi, $pal, $pak, $kom, $red]
                    );
                    return 'dodato ' . sku_naziv($sku) . ': ' . broj($komadi) . ' kom' . $gde;
                });
            }
        }
    } elseif ($akcija === 'ostalo') {
        $naziv = post_str('o_naziv', 120);
        $kol = post_str('o_kolicina', 8);
        $jedinica = post_str('o_jedinica', 20) ?: 'kom';
        [$kg, $greska] = kg_iz_posta('o_kg');
        if (mb_strlen($naziv) < 2) {
            flash_dodaj('greska', 'Upišite naziv robe.');
        } elseif (!ctype_digit($kol) || (int)$kol <= 0) {
            flash_dodaj('greska', 'Količina mora biti ceo broj veći od 0.');
        } elseif ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            u_nacrtu($id, static function () use ($id, $naziv, $kol, $jedinica, $kg) {
                $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_stavke WHERE spisak_id = ?', [$id]) + 10;
                db_run(
                    'INSERT INTO spisak_stavke (spisak_id, sku_id, naziv, jedinica, tezina_kg, kolicina, redosled) VALUES (?, NULL, ?, ?, ?, ?, ?)',
                    [$id, $naziv, $jedinica, $kg, (int)$kol, $red]
                );
                return 'dodato (ostalo) ' . $naziv . ': ' . broj((int)$kol) . ' ' . $jedinica;
            });
        }
    } elseif ($akcija === 'stavke') {
        u_nacrtu($id, static function () use ($id) {
            $niz = static fn(string $k): array => is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
            [$p_pal, $p_pak, $p_kom, $p_okol] = [$niz('pal'), $niz('pak'), $niz('kom'), $niz('o_kol')];
            [$p_pnaziv, $p_pbroj] = [$niz('pl_naziv'), $niz('pl_broj')];
            $izmene = 0;

            // zbirne palete: naziv i broj ovakvih paleta
            $palete = [];
            foreach (spisak_palete($id) as $pl) {
                $pid = (int)$pl['id'];
                $naziv = is_string($p_pnaziv[$pid] ?? null) ? mb_substr(trim((string)preg_replace('/\s+/u', ' ', $p_pnaziv[$pid])), 0, 60) : (string)$pl['naziv'];
                $broj_t = is_string($p_pbroj[$pid] ?? null) ? trim($p_pbroj[$pid]) : (string)$pl['broj'];
                if ($naziv === '') {
                    return ['greska' => 'Zbirna paleta mora imati naziv.'];
                }
                if (!ctype_digit($broj_t) || (int)$broj_t < 1 || (int)$broj_t > 999) {
                    return ['greska' => $pl['naziv'] . ': broj ovakvih paleta mora biti od 1 do 999.'];
                }
                $palete[$pid] = ['stari' => (int)$pl['broj'], 'novi' => (int)$broj_t];
                if ($naziv !== $pl['naziv'] || (int)$broj_t !== (int)$pl['broj']) {
                    db_run('UPDATE spisak_palete SET naziv = ?, broj = ? WHERE id = ?', [$naziv, (int)$broj_t, $pid]);
                    $izmene++;
                }
            }

            foreach (spisak_stavke($id) as $st) {
                $sid = (int)$st['stavka_id'];
                $pid = (int)$st['paleta_id'];
                if ($st['sku_id'] === null) {
                    $kol = is_string($p_okol[$sid] ?? null) ? trim($p_okol[$sid]) : null;
                    if ($kol === null) {
                        continue;
                    }
                    if (!ctype_digit($kol) || (int)$kol <= 0) {
                        return ['greska' => stavka_naziv($st) . ': količina mora biti ceo broj veći od 0 (za brisanje koristite Ukloni).'];
                    }
                    if ((int)$kol !== (int)$st['kolicina']) {
                        db_run('UPDATE spisak_stavke SET kolicina = ? WHERE id = ?', [(int)$kol, $sid]);
                        $izmene++;
                    }
                    continue;
                }
                if ($pid > 0) {
                    // u zbirnoj paleti se upisuje koliko ide na JEDNU paletu; ukupno = to × broj paleta
                    $broj = $palete[$pid]['novi'] ?? (int)$st['paleta_broj'];
                    $stari = $palete[$pid]['stari'] ?? (int)$st['paleta_broj'];
                    if (isset($p_pak[$sid]) || isset($p_kom[$sid])) {
                        $r = popis_vrednost('', '', $p_pak[$sid] ?? '', $p_kom[$sid] ?? '', $st);
                        if (isset($r['greska'])) {
                            return ['greska' => $r['greska']];
                        }
                        if (!$r['komadi']) {
                            return ['greska' => stavka_puni_naziv($st) . ': količina ne može biti 0 (za brisanje koristite Ukloni).'];
                        }
                        $na_paleti = (int)$r['komadi'];
                        $pak = (int)($p_pak[$sid] ?? 0);
                        $kom = (int)($p_kom[$sid] ?? 0);
                    } else {
                        $na_paleti = intdiv((int)$st['kolicina'], max(1, $stari));
                        $pak = (int)$st['u_paketi'];
                        $kom = (int)$st['u_komadi'];
                    }
                    $nova = $na_paleti * $broj;
                    if ($nova > 99999999) {
                        return ['greska' => 'Količina je prevelika.'];
                    }
                    if ($nova !== (int)$st['kolicina'] || $pak !== (int)$st['u_paketi'] || $kom !== (int)$st['u_komadi']) {
                        db_run('UPDATE spisak_stavke SET kolicina = ?, palete = 0, paketi = ?, komadi = ? WHERE id = ?', [$nova, $pak, $kom, $sid]);
                        $izmene++;
                    }
                    continue;
                }
                if (!isset($p_pal[$sid]) && !isset($p_pak[$sid]) && !isset($p_kom[$sid])) {
                    continue;
                }
                $r = popis_vrednost('', $p_pal[$sid] ?? '', $p_pak[$sid] ?? '', $p_kom[$sid] ?? '', $st);
                if (isset($r['greska'])) {
                    return ['greska' => $r['greska']];
                }
                if (!$r['komadi']) {
                    return ['greska' => stavka_naziv($st) . ': količina ne može biti 0 (za brisanje koristite Ukloni).'];
                }
                $pal = (int)($p_pal[$sid] ?? 0);
                $pak = (int)($p_pak[$sid] ?? 0);
                $kom = (int)($p_kom[$sid] ?? 0);
                if ($r['komadi'] !== (int)$st['kolicina'] || $pal !== (int)$st['u_palete'] || $pak !== (int)$st['u_paketi'] || $kom !== (int)$st['u_komadi']) {
                    db_run('UPDATE spisak_stavke SET kolicina = ?, palete = ?, paketi = ?, komadi = ? WHERE id = ?', [$r['komadi'], $pal, $pak, $kom, $sid]);
                    $izmene++;
                }
            }
            return $izmene > 0 ? 'sačuvane izmene (' . $izmene . ')' : '';
        });
    } elseif ($akcija === 'status') {
        $novi = post_str('novi', 12);
        $ocekivan = post_str('ocekivan', 12);
        $rez = spisak_promeni_status($id, $ocekivan, $novi, (int)$admin['id']);
        if (!$rez['ok']) {
            flash_dodaj('greska', (string)$rez['greska']);
        } else {
            $poruke = [
                'za_utovar' => ['poslat na utovar', 'Spisak je poslat na utovar: radnici ga vide, a količine su rezervisane.'],
                'nacrt'     => ['vraćen u nacrt', 'Spisak je vraćen u nacrt – možete ga menjati. Radnici ga ne vide dok ga ponovo ne pošaljete.'],
                'otkazano'  => $ocekivan === 'utovareno'
                    ? ['otkazan utovar – roba vraćena na stanje', 'Utovar je otkazan i sva roba je vraćena na stanje (povrat).']
                    : ['otkazan', 'Spisak je otkazan. Ništa nije skinuto sa stanja.'],
            ];
            spisak_dnevnik($id, $poruke[$novi][0]);
            flash_dodaj('uspeh', $poruke[$novi][1]);
        }
    }
    preusmeri($posle);
}

$stavke = spisak_stavke($id);
$palete = spisak_palete($id);
$grupe = spisak_grupe($stavke);
$izabrana_paleta = get_int('u');
$utovaren = $sp['status'] === 'utovareno';
$z = spisak_zbir($stavke, $utovaren);
$nacrt = $sp['status'] === 'nacrt';
$manjak = in_array($sp['status'], ['nacrt', 'za_utovar'], true) ? spisak_manjak($id, $stavke) : [];
$stanja = stanja_svih();

ui_start('Spisak #' . $id, ['nav' => 'admin', 'aktivno' => 'utovar', 'js' => ['assets/js/spisak.js', 'assets/js/prodaja.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/utovar.php')) ?>"><?= ikona('nazad') ?> Svi spiskovi</a></p>

<section class="kartica spisak-<?= e($sp['status']) ?>">
    <div class="kartica-naslov">
        <h2><?= e($sp['kupac']) ?></h2>
        <span class="znacka status-<?= e($sp['status']) ?>"><?= e(SPISAK_STATUSI[$sp['status']]) ?></span>
    </div>
    <dl class="spisak-podaci">
        <div><dt>Utovar</dt><dd><?= e(dan_u_nedelji((string)$sp['datum'])) ?>, <?= e(datum_srp((string)$sp['datum'])) ?></dd></div>
        <?php if ($sp['vozilo']): ?><div><dt>Vozilo</dt><dd><?= e($sp['vozilo']) ?></dd></div><?php endif; ?>
        <?php if ($sp['vozac']): ?><div><dt>Vozač</dt><dd><?= e($sp['vozac']) ?></dd></div><?php endif; ?>
        <?php if ($sp['napomena']): ?><div><dt>Napomena</dt><dd><?= e($sp['napomena']) ?></dd></div><?php endif; ?>
        <div><dt>Napravio</dt><dd><?= e($sp['napravio']) ?>, <?= e(datum_vreme_srp((string)$sp['napravljen'])) ?></dd></div>
        <?php if ($sp['utovareno_u']): ?><div><dt>Utovario</dt><dd><?= e($sp['utovario'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$sp['utovareno_u'])) ?></dd></div><?php endif; ?>
        <?php if ($sp['otkazano_u']): ?><div><dt>Otkazao</dt><dd><?= e($sp['otkazao'] ?? '—') ?>, <?= e(datum_vreme_srp((string)$sp['otkazano_u'])) ?></dd></div><?php endif; ?>
    </dl>

    <div class="stat-mreza tri">
        <div class="stat"><span class="broj"><?= (int)$z['stavki'] ?></span><span class="opis">stavki</span></div>
        <div class="stat"><span class="broj"><?= e(broj(round($z['palete'], 1), round($z['palete'], 1) == floor(round($z['palete'], 1)) ? 0 : 1)) ?></span><span class="opis">paleta<?= $z['zbirnih'] ? ' (zbirnih: ' . (int)$z['zbirnih'] . ')' : '' ?><?= $z['bez_palete'] ? ' (+' . (int)$z['bez_palete'] . ' bez palete)' : '' ?></span></div>
        <div class="stat"><span class="broj"><?= e(kg_tekst($z['kg'])) ?></span><span class="opis">težina<?= $z['bez_tezine'] ? ' (' . (int)$z['bez_tezine'] . ' bez težine)' : '' ?></span></div>
    </div>

    <p class="grupa-dugmica razmak-gore">
        <a class="btn btn-mali" href="<?= e(url('admin/spisak_stampa.php?id=' . $id)) ?>"><?= ikona('preuzmi') ?> Štampa / PDF / Podeli</a>
    </p>

    <?php if ($nacrt): ?>
        <details class="razmak-gore">
            <summary class="btn btn-mali"><?= ikona('olovka') ?> Izmeni kupca, datum, vozilo</summary>
            <form method="post" autocomplete="off" class="razmak-gore" action="<?= e(url('admin/spisak.php')) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="zaglavlje">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="red-polja">
                    <label for="kupac">Kupac</label>
                    <input class="polje" id="kupac" name="kupac" type="text" maxlength="120" required list="lista-kupaca" value="<?= e($sp['kupac']) ?>">
                    <datalist id="lista-kupaca"><?php foreach (db_all('SELECT naziv FROM kupci ORDER BY naziv LIMIT 300') as $k): ?><option value="<?= e($k['naziv']) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="red-polja">
                    <label for="datum">Datum utovara</label>
                    <input class="polje" id="datum" name="datum" type="date" required value="<?= e((string)$sp['datum']) ?>">
                </div>
                <div class="red-polja-2 red-polja">
                    <div><label for="vozilo">Vozilo</label><input class="polje" id="vozilo" name="vozilo" type="text" maxlength="60" value="<?= e($sp['vozilo'] ?? '') ?>"></div>
                    <div><label for="vozac">Vozač</label><input class="polje" id="vozac" name="vozac" type="text" maxlength="60" value="<?= e($sp['vozac'] ?? '') ?>"></div>
                </div>
                <div class="red-polja">
                    <label for="napomena">Napomena</label>
                    <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" value="<?= e($sp['napomena'] ?? '') ?>">
                </div>
                <button class="btn btn-primary btn-blok" type="submit">Sačuvaj podatke</button>
            </form>
        </details>
    <?php endif; ?>
</section>

<?php if ($manjak): ?>
    <div class="poruka poruka-upozorenje" role="status">
        <strong>Upozorenje:</strong> za <?= count($manjak) ?> <?= count($manjak) === 1 ? 'stavku' : 'stavke' ?> na spisku ima više nego što je slobodno (stanje minus ono što je već rezervisano na drugim spiskovima). Spisak može da se pošalje, ovo je samo upozorenje.
    </div>
<?php endif; ?>

<?php
/** Jedna stavka spiska (posebna ili u zbirnoj paleti). */
$prikazi_stavku = static function (array $st) use ($nacrt, $utovaren, $manjak, $stanja): void {
    $sid = (int)$st['stavka_id'];
    $kol = $utovaren && $st['utovareno'] !== null ? (int)$st['utovareno'] : (int)$st['kolicina'];
    $kg = stavka_kg($st, $kol);
    $ostalo = $st['sku_id'] === null;
    $u_paleti = (int)$st['paleta_id'] > 0;
    ?>
    <li class="stavka<?= isset($manjak[$sid]) ? ' stavka-manjak' : '' ?>">
        <div class="stavka-glava">
            <strong><?= e(stavka_naziv($st)) ?></strong>
            <?php if ($ostalo): ?><span class="znacka">ostalo – ne vodi se na stanju</span><?php endif; ?>
            <?php if ($st['dodao_id'] !== null): ?><span class="znacka status-za_utovar">dodato pri utovaru · <?= e($st['dodao'] ?? '') ?></span><?php endif; ?>
            <?php if (!$ostalo && $st['oznaka']): ?><span class="znacka znacka-pl"><?= e($st['oznaka']) ?></span><?php endif; ?>
        </div>
        <div class="stavka-kol">
            <?= e(stavka_plan_tekst($st)) ?>
            <?php if ($kg !== null): ?> · ≈ <?= e(kg_tekst($kg)) ?><?php endif; ?>
            <?php if ($utovaren && $st['utovareno'] !== null && (int)$st['utovareno'] !== (int)$st['kolicina']): ?>
                <br><span class="tekst-crveno">utovareno <?= e(broj((int)$st['utovareno'])) ?> umesto <?= e(broj((int)$st['kolicina'])) ?></span>
            <?php endif; ?>
        </div>
        <?php if (isset($manjak[$sid])): $m = $manjak[$sid]; ?>
            <div class="stavka-upoz"><?= ikona('upozorenje', 'ikona ikona-mala') ?> slobodno je samo <?= e(broj($m['slobodno'])) ?> kom (stanje <?= e(broj($stanja[(int)$st['sku_id']] ?? 0)) ?>)<?= $m['trazi'] !== $m['stavka'] ? ', a na spisku ukupno ' . e(broj($m['trazi'])) . ' kom' : '' ?></div>
        <?php endif; ?>
        <?php if ($nacrt): ?>
            <div class="stavka-izmena" data-popis data-po="<?= $u_paleti ? 0 : (int)$st['po_paleti'] ?>" data-pp="<?= (int)$st['po_paketu'] ?>" data-broj="<?= $u_paleti ? (int)$st['paleta_broj'] : 1 ?>" data-sada="<?= (int)$st['kolicina'] ?>">
                <?php if ($ostalo): ?>
                    <label class="mala-oznaka"><?= e($st['ostalo_jedinica'] ?: 'kom') ?><input class="polje" name="o_kol[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= (int)$st['kolicina'] ?>"></label>
                <?php else: ?>
                    <?php if (!$u_paleti && (int)$st['po_paleti'] > 0): ?><label class="mala-oznaka">paleta<input class="polje" name="pal[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= (int)$st['u_palete'] ?: '' ?>" placeholder="0" data-p="pal"></label><?php endif; ?>
                    <?php if ((int)$st['po_paketu'] > 0): ?><label class="mala-oznaka">paketa<?= $u_paleti ? ' na pal.' : '' ?><input class="polje" name="pak[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= (int)$st['u_paketi'] ?: '' ?>" placeholder="0" data-p="pak"></label><?php endif; ?>
                    <label class="mala-oznaka">komada<?= $u_paleti ? ' na pal.' : '' ?><input class="polje" name="kom[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= (int)$st['u_komadi'] ?: '' ?>" placeholder="0" data-p="kom"></label>
                <?php endif; ?>
                <button class="btn btn-mali btn-opasno" type="submit" name="ukloni" value="<?= $sid ?>" data-potvrda="Ukloniti „<?= e(stavka_puni_naziv($st)) ?>“ sa spiska?" aria-label="Ukloni <?= e(stavka_naziv($st)) ?>"><?= ikona('kanta') ?></button>
            </div>
            <?php if (!$ostalo): ?><div class="popis-zbir" data-zbir aria-live="polite"></div><?php endif; ?>
        <?php endif; ?>
    </li>
    <?php
};
?>
<section class="kartica" aria-labelledby="stavke-naslov">
    <div class="kartica-naslov"><h2 id="stavke-naslov">Stavke</h2></div>
    <?php if (!$stavke && !$palete): ?>
        <p class="pomoc bez-margine">Spisak još nema stavki. Dodajte ih ispod.</p>
    <?php else: ?>
        <?php if ($nacrt): ?>
        <form method="post" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
            <?= csrf_polje() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
        <?php endif; ?>
        <?php if ($grupe[0]['stavke']): ?>
            <ul class="lista stavke-lista"><?php foreach ($grupe[0]['stavke'] as $st) { $prikazi_stavku($st); } ?></ul>
        <?php endif; ?>
        <?php foreach ($palete as $pl):
            $pid = (int)$pl['id'];
            $u_paleti = $grupe[$pid]['stavke'] ?? [];
            $pz = spisak_zbir($u_paleti, $utovaren); ?>
            <div class="zbirna-paleta" id="paleta-<?= $pid ?>">
                <div class="zbirna-glava">
                    <?php if ($nacrt): ?>
                        <label class="mala-oznaka zbirna-naziv">zbirna paleta<input class="polje" name="pl_naziv[<?= $pid ?>]" type="text" maxlength="60" required value="<?= e($pl['naziv']) ?>"></label>
                        <label class="mala-oznaka zbirna-broj">ovakvih paleta<input class="polje" name="pl_broj[<?= $pid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= (int)$pl['broj'] ?>"></label>
                        <button class="btn btn-mali btn-opasno" type="submit" name="ukloni_paletu" value="<?= $pid ?>" data-potvrda="Ukloniti zbirnu paletu „<?= e($pl['naziv']) ?>“ sa svim artiklima u njoj?" aria-label="Ukloni zbirnu paletu <?= e($pl['naziv']) ?>"><?= ikona('kanta') ?></button>
                    <?php else: ?>
                        <strong><?= ikona('stanje') ?> <?= e(paleta_naslov((string)$pl['naziv'], (int)$pl['broj'])) ?></strong>
                    <?php endif; ?>
                </div>
                <p class="zbirna-zbir">Zbirna paleta<?= (int)$pl['broj'] > 1 ? ' × ' . (int)$pl['broj'] : '' ?> · <?= e(artikala_tekst(count($u_paleti))) ?><?= $pz['kg'] > 0 ? ' · ≈ ' . e(kg_tekst($pz['kg'])) : '' ?><?= (int)$pl['broj'] > 1 && $pz['kg'] > 0 ? ' ukupno' : '' ?></p>
                <?php if (!$u_paleti): ?>
                    <p class="pomoc bez-margine">Prazna – dodajte artikle ispod (Gde ide: <?= e($pl['naziv']) ?>).</p>
                <?php else: ?>
                    <ul class="lista stavke-lista"><?php foreach ($u_paleti as $st) { $prikazi_stavku($st); } ?></ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($nacrt): ?>
            <button class="btn btn-braon btn-blok razmak-gore" type="submit" name="akcija" value="stavke">Sačuvaj izmene</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($nacrt): ?>
    <details class="kartica">
        <summary class="kartica-naslov"><h2><?= ikona('plus') ?> Nova zbirna paleta</h2></summary>
        <form method="post" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="nova_paleta">
            <input type="hidden" name="id" value="<?= $id ?>">
            <p class="pomoc">Zbirna paleta je više različitih artikala složenih na jednu paletu (npr. 5 kom po 5 l, 5 po 10 l, 5 po 50 l). Radnici je slažu i tovare kao jednu paletu. Posle pravljenja dodajte artikle u nju (u „Dodaj artikal“ izaberite <strong>Gde ide</strong>).</p>
            <div class="red-polja-2 red-polja">
                <div><label for="p_naziv">Naziv</label><input class="polje" id="p_naziv" name="p_naziv" type="text" maxlength="60" placeholder="Zbirna paleta <?= count($palete) + 1 ?>"></div>
                <div><label for="p_broj">Koliko ovakvih paleta</label><input class="polje" id="p_broj" name="p_broj" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="1"></div>
            </div>
            <button class="btn btn-primary btn-blok" type="submit">Napravi zbirnu paletu</button>
        </form>
    </details>

    <form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>" data-nova-stavka data-dodavanje id="dodaj">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="dodaj">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="kartica-naslov"><h2><?= ikona('plus') ?> Dodaj artikal</h2></div>
        <?php if ($palete): ?>
            <div class="red-polja">
                <label for="paleta_id">Gde ide</label>
                <select class="polje" id="paleta_id" name="paleta_id" data-odrediste>
                    <option value="0" data-broj="1">Posebna stavka (palete, paketi, komadi)</option>
                    <?php foreach ($palete as $pl): ?>
                        <option value="<?= (int)$pl['id'] ?>" data-broj="<?= (int)$pl['broj'] ?>"<?= $izabrana_paleta === (int)$pl['id'] ? ' selected' : '' ?>>U zbirnu paletu: <?= e(paleta_naslov((string)$pl['naziv'], (int)$pl['broj'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="pomoc bez-margine" data-pomoc-zbirna hidden>U zbirnu paletu upišite koliko ide na <strong>jednu</strong> paletu (paketi i/ili komadi).</p>
            </div>
        <?php else: ?>
            <input type="hidden" name="paleta_id" value="0">
        <?php endif; ?>
        <div class="red-polja">
            <label for="sku_id">Artikal i pakovanje</label>
            <select class="polje" id="sku_id" name="sku_id" required data-izbor-sku>
                <option value="">— izaberite —</option>
                <?php foreach (spisak_izbor_sku() as $artikal => $redovi): ?>
                    <optgroup label="<?= e($artikal) ?>">
                        <?php foreach ($redovi as [$sid, $naziv, $po, $pp]): ?>
                            <option value="<?= $sid ?>" data-po="<?= $po ?>" data-pp="<?= $pp ?>" data-sada="<?= (int)($stanja[$sid] ?? 0) ?>"><?= e($artikal . ' · ' . $naziv) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="popis-polja" data-popis data-po="0" data-pp="0" data-sada="0">
            <label class="mala-oznaka" data-polje-pal>paleta<input class="polje" name="pal" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pal"></label>
            <label class="mala-oznaka" data-polje-pak>paketa<input class="polje" name="pak" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pak"></label>
            <label class="mala-oznaka">komada<input class="polje" name="kom" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="0" data-p="kom"></label>
        </div>
        <p class="popis-zbir" data-zbir aria-live="polite"></p>
        <button class="btn btn-primary btn-blok" type="submit">Dodaj na spisak</button>
    </form>

    <details class="kartica">
        <summary class="kartica-naslov"><h2><?= ikona('plus') ?> Dodaj ostalo (roba koja se ne vodi u aplikaciji)</h2></summary>
        <form method="post" autocomplete="off" action="<?= e(url('admin/spisak.php')) ?>">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="ostalo">
            <input type="hidden" name="id" value="<?= $id ?>">
            <p class="pomoc">Ova roba se samo upisuje na spisak (i u težinu tovara) – stanje u aplikaciji se ne menja.</p>
            <div class="red-polja">
                <label for="o_naziv">Naziv</label>
                <input class="polje" id="o_naziv" name="o_naziv" type="text" maxlength="120" required placeholder="npr. Saksije 30 cm">
            </div>
            <div class="red-polja-3 red-polja">
                <div><label for="o_kolicina">Količina</label><input class="polje" id="o_kolicina" name="o_kolicina" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" required></div>
                <div><label for="o_jedinica">Jedinica</label><input class="polje" id="o_jedinica" name="o_jedinica" type="text" maxlength="20" value="kom" list="lista-jedinica">
                    <datalist id="lista-jedinica"><option value="kom"><option value="vreća"><option value="paleta"><option value="paket"><option value="kg"></datalist></div>
                <div><label for="o_kg">Kg po jedinici</label><input class="polje" id="o_kg" name="o_kg" type="text" inputmode="decimal" maxlength="8" placeholder="nije obavezno"></div>
            </div>
            <button class="btn btn-primary btn-blok" type="submit">Dodaj ostalo</button>
        </form>
    </details>
<?php endif; ?>

<section class="kartica" aria-labelledby="akcije-naslov">
    <div class="kartica-naslov"><h2 id="akcije-naslov">Status spiska</h2></div>
    <?php
    $dugmad = [];
    if ($sp['status'] === 'nacrt') {
        $dugmad[] = ['za_utovar', 'Pošalji na utovar', 'btn-primary btn-veliko', 'Poslati spisak na utovar? Radnici će ga videti, a količine će biti rezervisane.' . ($manjak ? ' Pažnja: za neke stavke nema dovoljno slobodne robe.' : '')];
        $dugmad[] = ['otkazano', 'Otkaži spisak', 'btn-opasno', 'Otkazati ovaj spisak?'];
        $opis = 'Nacrt: menjajte slobodno. Stanje se ne dira, radnici ga ne vide.';
    } elseif ($sp['status'] === 'za_utovar') {
        $dugmad[] = ['nacrt', 'Vrati u nacrt (za izmene)', '', 'Vratiti spisak u nacrt? Radnici ga neće videti dok ga ponovo ne pošaljete.'];
        $dugmad[] = ['otkazano', 'Otkaži spisak', 'btn-opasno', 'Otkazati ovaj spisak? Rezervacija se ukida.'];
        $opis = 'Čeka utovar: radnici ga vide, količine su rezervisane. Stanje se skida tek kad se potvrdi utovar (radnik, ili vi dugmetom ispod).';
    } elseif ($sp['status'] === 'otkazano') {
        if ($sp['utovareno_u'] === null) {
            $dugmad[] = ['nacrt', 'Vrati u nacrt', '', 'Ponovo otvoriti otkazan spisak kao nacrt?'];
        }
        $opis = 'Otkazan spisak ništa ne rezerviše i ne menja stanje.';
    } else {
        $dugmad[] = ['otkazano', 'Otkaži utovar (vrati robu na stanje)', 'btn-opasno', 'Otkazati utovaren spisak? Sva utovarena roba se vraća na stanje (upisuje se kao povrat).'];
        $opis = 'Utovareno: stanje je skinuto i spisak se više ne menja. Ako je utovar greška, otkažite ga – roba se vraća na stanje.';
    }
    ?>
    <p class="pomoc"><?= e($opis) ?></p>
    <div class="grupa-dugmica">
        <?php foreach ($dugmad as [$novi, $tekst, $klasa, $potvrda]): ?>
            <form method="post" class="forma-u-liniji" action="<?= e(url('admin/spisak.php')) ?>" data-potvrda="<?= e($potvrda) ?>">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="status">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="ocekivan" value="<?= e($sp['status']) ?>">
                <input type="hidden" name="novi" value="<?= e($novi) ?>">
                <button class="btn <?= e($klasa) ?>" type="submit"><?= e($tekst) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php if ($sp['status'] === 'za_utovar'): ?>
        <p class="razmak-gore"><a class="btn btn-braon btn-blok" href="<?= e(url('radnik/utovar.php?id=' . $id)) ?>"><?= ikona('kamion') ?> Potvrdi utovar (štikliranje stavki)</a></p>
    <?php endif; ?>
</section>
<?php
ui_end();
