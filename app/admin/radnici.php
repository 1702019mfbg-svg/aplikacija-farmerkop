<?php
/**
 * Administrator – Radnici: dodavanje (ime + PIN), gašenje i vraćanje pristupa,
 * promena imena i PIN-a, otključavanje i pregled ko je koliko proizveo po danima.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

$admin = zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $id = post_int('id');

    if ($akcija === 'novi') {
        $ime = post_str('ime', 60);
        $pin = (string)($_POST['pin'] ?? '');
        $greska = null;
        if (mb_strlen($ime) < 2) {
            $greska = 'Upišite ime radnika (najmanje 2 slova).';
        } else {
            $greska = provera_pina($pin);
        }
        if ($greska !== null) {
            flash_dodaj('greska', $greska);
        } else {
            try {
                db_run("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik', ?, ?, 1, ?)", [$ime, napravi_hes($pin), sada()]);
                dnevnik_podesavanje('Dodat radnik: ' . $ime, 'korisnik', db_id());
                flash_dodaj('uspeh', 'Radnik „' . $ime . '“ je dodat. Prijavljuje se izborom imena i svojim PIN-om.');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                flash_dodaj('greska', 'Već postoji korisnik sa tim imenom. Dodajte prezime ili inicijal (npr. „Marko P.“).');
            }
        }
    } elseif (in_array($akcija, ['status', 'otkljucaj', 'izmeni'], true)) {
        $r = db_one("SELECT * FROM korisnici WHERE id = ? AND uloga = 'radnik'", [$id]);
        if ($r === null) {
            flash_dodaj('greska', 'Radnik nije pronađen.');
        } elseif ($akcija === 'status') {
            $novo = (int)$r['aktivan'] === 1 ? 0 : 1;
            db_run('UPDATE korisnici SET aktivan = ? WHERE id = ?', [$novo, $id]);
            if ($novo === 0) {
                sesije_obrisi_korisnika($id);
            }
            dnevnik_podesavanje(($novo ? 'Vraćen pristup radniku: ' : 'Isključen pristup radniku: ') . $r['ime'], 'korisnik', $id);
            flash_dodaj('uspeh', $novo ? '„' . $r['ime'] . '“ ponovo može da se prijavi.' : '„' . $r['ime'] . '“ više ne može da se prijavi (odjavljen je odmah). Njegovi unosi ostaju sačuvani.');
        } elseif ($akcija === 'otkljucaj') {
            db_run('UPDATE korisnici SET neuspesni_pokusaji = 0, zakljucan_do = NULL WHERE id = ?', [$id]);
            dnevnik_podesavanje('Otključan nalog radnika: ' . $r['ime'], 'korisnik', $id);
            flash_dodaj('uspeh', 'Nalog „' . $r['ime'] . '“ je otključan.');
        } else {
            $ime = post_str('ime', 60);
            $pin = (string)($_POST['pin'] ?? '');
            $greska = null;
            if (mb_strlen($ime) < 2) {
                $greska = 'Ime mora imati najmanje 2 slova.';
            } elseif ($pin !== '') {
                $greska = provera_pina($pin);
            }
            if ($greska !== null) {
                flash_dodaj('greska', $greska);
            } else {
                try {
                    $poruke = [];
                    if ($ime !== $r['ime']) {
                        db_run('UPDATE korisnici SET ime = ? WHERE id = ?', [$ime, $id]);
                        dnevnik_podesavanje('Promenjeno ime radnika: ' . $r['ime'] . ' → ' . $ime, 'korisnik', $id);
                        $poruke[] = 'ime je promenjeno';
                    }
                    if ($pin !== '') {
                        db_run('UPDATE korisnici SET hes = ?, neuspesni_pokusaji = 0, zakljucan_do = NULL WHERE id = ?', [napravi_hes($pin), $id]);
                        sesije_obrisi_korisnika($id);
                        dnevnik_podesavanje('Promenjen PIN radniku: ' . $ime, 'korisnik', $id);
                        $poruke[] = 'PIN je promenjen (radnik je odjavljen)';
                    }
                    flash_dodaj($poruke ? 'uspeh' : 'info', $poruke ? ucfirst(implode(', ', $poruke)) . '.' : 'Ništa nije promenjeno.');
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }
                    flash_dodaj('greska', 'Već postoji korisnik sa tim imenom.');
                }
            }
        }
    }
    preusmeri('admin/radnici.php');
}

$radnici = db_all(
    "SELECT k.*, COALESCE(p.kom, 0) AS danas_kom
     FROM korisnici k
     LEFT JOIN (SELECT korisnik_id, SUM(kolicina) AS kom FROM unosi
                WHERE tip = 'proizvodnja' AND obrisan = 0 AND nastalo >= ? AND nastalo < ? GROUP BY korisnik_id) p ON p.korisnik_id = k.id
     WHERE k.uloga = 'radnik' ORDER BY k.aktivan DESC, k.ime",
    [danas_od(), danas_do()]
);

// ── Proizvodnja po danima ──
$od = get_str('od', 10);
$do = get_str('do', 10);
if (!je_datum($od) || !je_datum($do) || $od > $do) {
    $od = date('Y-m-d', strtotime('-13 days'));
    $do = date('Y-m-d');
}
if ((strtotime($do) - strtotime($od)) / 86400 > 366) {
    $od = date('Y-m-d', strtotime($do . ' -366 days'));
}
$podaci = db_all(
    "SELECT DATE(u.nastalo) AS dan, u.korisnik_id, SUM(u.kolicina) AS kom
     FROM unosi u WHERE u.tip = 'proizvodnja' AND u.obrisan = 0 AND u.nastalo >= ? AND u.nastalo < ?
     GROUP BY DATE(u.nastalo), u.korisnik_id",
    [$od . ' 00:00:00', date('Y-m-d', (int)strtotime($do . ' +1 day')) . ' 00:00:00']
);
$po_danu = [];
$po_radniku = [];
$kolone = [];
foreach ($podaci as $p) {
    $po_danu[$p['dan']][(int)$p['korisnik_id']] = (int)$p['kom'];
    $po_radniku[(int)$p['korisnik_id']] = ($po_radniku[(int)$p['korisnik_id']] ?? 0) + (int)$p['kom'];
}
krsort($po_danu);
$imena = [];
foreach (db_all('SELECT id, ime FROM korisnici') as $r) {
    $imena[(int)$r['id']] = $r['ime'];
}
foreach ($radnici as $r) {
    if ((int)$r['aktivan'] === 1 || isset($po_radniku[(int)$r['id']])) {
        $kolone[(int)$r['id']] = $r['ime'];
    }
}
foreach ($po_radniku as $rid => $_) {
    $kolone[$rid] = $kolone[$rid] ?? ($imena[$rid] ?? '?');
}
$ukupno_period = array_sum($po_radniku);
$opsezi = [
    '7 dana'        => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '14 dana'       => [date('Y-m-d', strtotime('-13 days')), date('Y-m-d')],
    'Ovaj mesec'    => [date('Y-m-01'), date('Y-m-d')],
    'Prošli mesec'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];

ui_start('Radnici', ['nav' => 'admin', 'aktivno' => 'radnici', 'js' => ['assets/js/radnici.js']]);
?>
<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/radnici.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="novi">
    <div class="kartica-naslov"><h2>Novi radnik</h2></div>
    <div class="red-polja">
        <label for="novo-ime">Ime (kako će se prikazivati pri prijavi)</label>
        <input class="polje" id="novo-ime" name="ime" type="text" maxlength="60" required placeholder="npr. Marko P.">
    </div>
    <div class="red-polja">
        <label for="novi-pin">PIN (4 cifre)</label>
        <div class="red-polja-pin">
            <input class="polje" id="novi-pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required placeholder="••••" autocomplete="off">
            <button type="button" class="btn" data-slucajni-pin="novi-pin">Slučajan PIN</button>
        </div>
        <p class="pomoc bez-margine">Ne može 0000, 1111, 1234 i slično. Radniku recite PIN lično.</p>
    </div>
    <button class="btn btn-primary btn-blok" type="submit">Dodaj radnika</button>
</form>

<h2 class="kat-naslov">Radnici (<?= count($radnici) ?>)</h2>
<?php if (!$radnici): ?>
    <div class="kartica"><p class="bez-margine">Još nema radnika. Dodajte prvog iznad.</p></div>
<?php endif; ?>
<?php foreach ($radnici as $r):
    $zakljucan = $r['zakljucan_do'] !== null && strtotime((string)$r['zakljucan_do']) > time();
    ?>
    <div class="kartica radnik-kartica<?= (int)$r['aktivan'] ? '' : ' radnik-ugasen' ?>">
        <div class="kartica-naslov">
            <h3><?= e($r['ime']) ?></h3>
            <span>
                <?php if ($zakljucan): ?><span class="znacka znacka-nisko">zaključan do <?= e(vreme_srp((string)$r['zakljucan_do'])) ?></span><?php endif; ?>
                <span class="znacka <?= (int)$r['aktivan'] ? 'znacka-proizvodnja' : '' ?>"><?= (int)$r['aktivan'] ? 'aktivan' : 'isključen' ?></span>
            </span>
        </div>
        <p class="pomoc">
            Danas proizvedeno: <strong><?= e(broj((int)$r['danas_kom'])) ?> kom</strong>
            · Poslednja prijava: <?= $r['poslednja_prijava'] ? e(datum_kratko((string)$r['poslednja_prijava'])) : 'nikad' ?>
        </p>
        <div class="grupa-dugmica">
            <form method="post" class="forma-u-liniji" action="<?= e(url('admin/radnici.php')) ?>"
                  <?= (int)$r['aktivan'] ? 'data-potvrda="Isključiti pristup za ' . e($r['ime']) . '? Biće odjavljen odmah, a njegovi unosi ostaju."' : '' ?>>
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="status">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-mali <?= (int)$r['aktivan'] ? 'btn-opasno' : 'btn-primary' ?>" type="submit"><?= (int)$r['aktivan'] ? 'Isključi pristup' : 'Vrati pristup' ?></button>
            </form>
            <?php if ($zakljucan): ?>
                <form method="post" class="forma-u-liniji" action="<?= e(url('admin/radnici.php')) ?>">
                    <?= csrf_polje() ?>
                    <input type="hidden" name="akcija" value="otkljucaj">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-mali" type="submit">Otključaj</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-mali" href="<?= e(url('admin/istorija.php?radnik=' . (int)$r['id'])) ?>">Njegovi unosi</a>
        </div>
        <details class="razmak-gore">
            <summary class="pomoc">Izmeni ime ili PIN</summary>
            <form method="post" autocomplete="off" action="<?= e(url('admin/radnici.php')) ?>" class="razmak-gore">
                <?= csrf_polje() ?>
                <input type="hidden" name="akcija" value="izmeni">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <div class="red-polja">
                    <label for="ime-<?= (int)$r['id'] ?>">Ime</label>
                    <input class="polje" id="ime-<?= (int)$r['id'] ?>" name="ime" type="text" maxlength="60" required value="<?= e($r['ime']) ?>">
                </div>
                <div class="red-polja">
                    <label for="pin-<?= (int)$r['id'] ?>">Novi PIN (ostavite prazno da se ne menja)</label>
                    <div class="red-polja-pin">
                        <input class="polje" id="pin-<?= (int)$r['id'] ?>" name="pin" type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="••••" autocomplete="off">
                        <button type="button" class="btn" data-slucajni-pin="pin-<?= (int)$r['id'] ?>">Slučajan PIN</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-blok" type="submit">Sačuvaj</button>
            </form>
        </details>
    </div>
<?php endforeach; ?>

<h2 class="kat-naslov">Ko je koliko proizveo</h2>
<div class="cipovi" role="group" aria-label="Period">
    <?php foreach ($opsezi as $naziv => [$a, $b]): ?>
        <a class="cip" href="<?= e(url('admin/radnici.php?od=' . $a . '&do=' . $b)) ?>" <?= ($od === $a && $do === $b) ? 'aria-current="true"' : '' ?>><?= e($naziv) ?></a>
    <?php endforeach; ?>
</div>
<form method="get" class="kartica" action="<?= e(url('admin/radnici.php')) ?>">
    <div class="red-polja-2 red-polja">
        <div><label for="od">Od</label><input class="polje" type="date" id="od" name="od" value="<?= e($od) ?>"></div>
        <div><label for="do">Do</label><input class="polje" type="date" id="do" name="do" value="<?= e($do) ?>"></div>
    </div>
    <button class="btn btn-mali" type="submit">Prikaži</button>
</form>

<div class="kartica">
    <?php if (!$po_danu): ?>
        <p class="bez-margine">U ovom periodu nema proizvodnje.</p>
    <?php else: ?>
        <p class="pomoc">Komada po danima (<?= e(datum_srp($od)) ?> – <?= e(datum_srp($do)) ?>). Dodirnite broj da vidite unose.</p>
        <div class="tabela-omot">
            <table class="tabela tabela-radnici">
                <thead>
                    <tr>
                        <th>Dan</th>
                        <?php foreach ($kolone as $rid => $ime): ?><th class="br"><?= e($ime) ?></th><?php endforeach; ?>
                        <th class="br">Ukupno</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($po_danu as $dan => $vrednosti): ?>
                        <tr>
                            <th scope="row"><?= e(datum_srp($dan . ' 00:00:00')) ?><br><span class="pomoc"><?= e(dan_u_nedelji($dan . ' 00:00:00')) ?></span></th>
                            <?php foreach ($kolone as $rid => $ime): ?>
                                <td class="br">
                                    <?php if (!empty($vrednosti[$rid])): ?>
                                        <a href="<?= e(url('admin/istorija.php?tip=proizvodnja&radnik=' . $rid . '&od=' . $dan . '&do=' . $dan)) ?>"><?= e(broj($vrednosti[$rid])) ?></a>
                                    <?php else: ?>–<?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="br"><strong><?= e(broj(array_sum($vrednosti))) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Ukupno</th>
                        <?php foreach ($kolone as $rid => $ime): ?><td class="br"><strong><?= e(broj($po_radniku[$rid] ?? 0)) ?></strong></td><?php endforeach; ?>
                        <td class="br"><strong><?= e(broj($ukupno_period)) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
ui_end();
