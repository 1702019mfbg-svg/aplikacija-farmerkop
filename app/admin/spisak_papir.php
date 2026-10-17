<?php
/**
 * Administrator – spisak sa papira: ceo spisak (mesta i roba) se prepiše ili izdiktira telefonom u jedno polje,
 * aplikacija ga pročita (inc/spisak_papir.php) i prikaže na proveru – nejasni redovi se ispravljaju dodirom na
 * pakovanje – pa se sve sačuva na spisak odjednom (postojeće mesto sa istim brojem se dopunjuje).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';
require __DIR__ . '/../inc/spiskovi.php';
require __DIR__ . '/../inc/spisak_izmene.php';
require __DIR__ . '/../inc/spisak_papir.php';

zahtevaj_ulogu('admin');
$id = je_post() ? post_int('id') : get_int('id');
$sp = spisak_ucitaj($id);
if ($sp === null) {
    stranica_greske(404, 'Spisak ne postoji', 'Traženi spisak za utovar ne postoji.', 'admin/utovar.php', 'Svi spiskovi');
}
$nazad = 'admin/spisak.php?id=' . $id;
if ($sp['status'] !== 'nacrt') {
    unset($_SESSION['spisak_papir'][$id]);
    flash_dodaj('greska', 'Spisak sa papira može da se upiše samo dok je spisak nacrt.');
    preusmeri($nazad);
}

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 12);
    $ovde = 'admin/spisak_papir.php?id=' . $id;
    if ($akcija === 'procitaj') {
        $tekst = is_string($_POST['tekst'] ?? null) ? mb_substr(str_replace("\r\n", "\n", $_POST['tekst']), 0, PAPIR_MAX_TEKST) : '';
        $bez = post_str('bez_oznake', 3) === 'pal' ? 'pal' : 'kom';
        $mesta = spisak_mesta($id);
        $r = papir_procitaj($tekst, $bez, count($mesta) === 1 ? (int)$mesta[0]['broj'] : mesto_sledeci_broj($id));
        $_SESSION['spisak_papir'][$id] = ['tekst' => $tekst, 'bez' => $bez, 'mesta' => $r['mesta']];
        if ($r['greska'] !== null) {
            flash_dodaj('greska', $r['greska']);
            preusmeri($ovde . '&tekst=1');
        }
        preusmeri($ovde . '#pregled');
    }
    if ($akcija === 'odustani') {
        unset($_SESSION['spisak_papir'][$id]);
        flash_dodaj('info', 'Spisak sa papira nije upisan.');
        preusmeri($nazad);
    }
    if ($akcija === 'sacuvaj') {
        $mesta = papir_iz_posta();
        [$plan, $greske] = papir_proveri($mesta);
        $_SESSION['spisak_papir'][$id]['mesta'] = $mesta;
        if (!$mesta) {
            flash_dodaj('greska', 'Nema ničega za čuvanje.');
            preusmeri($ovde . '&tekst=1');
        }
        if ($greske > 0) {
            flash_dodaj('greska', 'Proverite označene redove (' . $greske . ') – ispravite ih ili štiklirajte „Preskoči“, pa sačuvajte ponovo.');
            preusmeri($ovde . '#pregled');
        }
        $prvo = 0;
        $ok = u_nacrtu($id, static function () use ($id, $plan, &$prvo) {
            $opis = [];
            $stavki = 0;
            foreach ($plan as $p) {
                $m = db_one('SELECT m.*, k.naziv AS kupac FROM spisak_mesta m LEFT JOIN kupci k ON k.id = m.kupac_id WHERE m.spisak_id = ? AND m.broj = ?', [$id, $p['broj']]);
                if ($m === null) {
                    $red = (int)db_val('SELECT COALESCE(MAX(redosled), 0) FROM spisak_mesta WHERE spisak_id = ?', [$id]) + 10;
                    db_run(
                        'INSERT INTO spisak_mesta (spisak_id, broj, kupac_id, adresa, redosled) VALUES (?, ?, ?, ?, ?)',
                        [$id, $p['broj'], $p['kupac'] !== '' ? kupac_id_za($p['kupac']) : null, $p['adresa'] !== '' ? $p['adresa'] : null, $red]
                    );
                    $mid = db_id();
                    $kupac = $p['kupac'] !== '' ? $p['kupac'] : null;
                } else {
                    $mid = (int)$m['id'];
                    $kupac = $m['kupac'];
                    if ($m['kupac_id'] === null && $p['kupac'] !== '') {
                        db_run('UPDATE spisak_mesta SET kupac_id = ? WHERE id = ?', [kupac_id_za($p['kupac']), $mid]);
                        $kupac = $p['kupac'];
                    }
                    if ($m['adresa'] === null && $p['adresa'] !== '') {
                        db_run('UPDATE spisak_mesta SET adresa = ? WHERE id = ?', [$p['adresa'], $mid]);
                    }
                }
                $prvo = $prvo ?: $mid;
                $rez = spisak_upisi_robu($id, $mid, 0, 1, $p['roba']);
                if (isset($rez['greska'])) {
                    return ['greska' => mesto_naslov($p['broj'], $kupac) . ': ' . $rez['greska']];
                }
                $n = count($p['roba']);
                foreach ($p['zbirne'] as [$broj, $redovi]) {
                    $z = spisak_nova_zbirna($id, $mid, '', $broj, $redovi);
                    if (isset($z['greska'])) {
                        return ['greska' => mesto_naslov($p['broj'], $kupac) . ': ' . $z['greska']];
                    }
                    $n += count($redovi);
                }
                $stavki += $n;
                $opis[] = mesto_naslov($p['broj'], $kupac) . ($n > 0 ? ' (' . stavki_tekst($n) . ')' : '');
            }
            return 'upisan spisak sa papira: ' . implode(', ', $opis) . ($stavki === 0 ? ' – bez robe' : '');
        });
        if ($ok) {
            unset($_SESSION['spisak_papir'][$id]);
            preusmeri($nazad . ($prvo > 0 ? '&m=' . $prvo . '#mesto-' . $prvo : ''));
        }
        preusmeri($ovde . '#pregled');
    }
    preusmeri($ovde);
}

$papir = $_SESSION['spisak_papir'][$id] ?? null;
$uredi_tekst = $papir === null || get_int('tekst') === 1 || !$papir['mesta'];
$kupci = db_all('SELECT naziv FROM kupci ORDER BY (poslednja_prodaja IS NULL), poslednja_prodaja DESC, naziv LIMIT 300');
$primer = "8 Agrocentar\n2 pal humovit 50\nhumovit 10 - 30\n3 pak idea 5\n1/2 rizla bela sitna + 1/2 oblutak beli 40-60\n\n9\n1 pal idea 10\n2 t oblutak beli 4-6";

ui_start('Spisak sa papira', ['nav' => 'admin', 'aktivno' => 'utovar', 'js' => ['assets/js/spisak.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url($nazad)) ?>"><?= ikona('nazad') ?> Spisak #<?= $id ?></a></p>

<?php if ($uredi_tekst): ?>
    <form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/spisak_papir.php')) ?>" data-papir>
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="procitaj">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="kartica-naslov"><h2><?= ikona('olovka') ?> Spisak sa papira</h2></div>
        <p class="pomoc">Prepišite spisak kako je napisan na papiru – ili ga na telefonu <strong>izdiktirajte</strong> (dodirnite mikrofon na tastaturi). Aplikacija ga pročita i pokaže vam šta je razumela, pa vi proverite i sačuvate.</p>
        <ul class="pomoc">
            <li>Svako mesto počnite <strong>brojem</strong> u svom redu (8, (8) ili Mesto 8), a iza broja može kupac.</li>
            <li>Svaki artikal u svom redu, sa količinom: <strong>2 pal</strong>, <strong>3 pak</strong>, <strong>30 kom</strong>, <strong>2 t</strong> ili <strong>1/2</strong> (pola palete).</li>
            <li>Zbirna paleta u jednom redu, sa <strong>+</strong> između: 1/2 rizla bela sitna + 1/2 oblutak beli 40-60.</li>
        </ul>
        <p class="papir-primer" aria-label="Primer"><?= e($primer) ?></p>
        <div class="red-polja">
            <label for="tekst">Spisak</label>
            <textarea class="polje papir-tekst" id="tekst" name="tekst" rows="12" maxlength="<?= PAPIR_MAX_TEKST ?>" autocapitalize="off" spellcheck="false" placeholder="8 Agrocentar&#10;2 pal humovit 50&#10;…" required><?= e($papir['tekst'] ?? '') ?></textarea>
        </div>
        <fieldset class="red-polja papir-izbor">
            <legend>Broj bez oznake (npr. „humovit 10 - 30“) znači</legend>
            <label class="radio-red"><input type="radio" name="bez_oznake" value="kom"<?= ($papir['bez'] ?? 'kom') === 'kom' ? ' checked' : '' ?>> komada</label>
            <label class="radio-red"><input type="radio" name="bez_oznake" value="pal"<?= ($papir['bez'] ?? '') === 'pal' ? ' checked' : '' ?>> paleta</label>
        </fieldset>
        <button class="btn btn-primary btn-blok btn-veliko" type="submit">Pročitaj spisak</button>
    </form>
<?php else:
    $mesta = $papir['mesta'];
    $robe = 0;
    $proveri = 0;
    /** Red koji treba pogledati: artikal nije izabran ili nema količine (ili je čuvanje javilo grešku). */
    $nejasan = static fn(array $r): bool => $r['preskoci'] !== '1' && ($r['greska'] !== '' || ($r['vrsta'] === 'zbirna'
        ? (bool)array_filter($r['delovi'], static fn(array $d): bool => $d['id'] === '' || $d['kol'] === '')
        : $r['id'] === '' || $r['pal'] . $r['pak'] . $r['kom'] === ''));
    foreach ($mesta as $m) {
        foreach ($m['redovi'] as $r) {
            $robe++;
            $proveri += $nejasan($r) ? 1 : 0;
        }
    }
    ?>
    <form method="post" autocomplete="off" action="<?= e(url('admin/spisak_papir.php')) ?>" id="pregled" data-papir>
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="skriven-vizuelno" type="submit" name="akcija" value="sacuvaj" tabindex="-1" aria-hidden="true">Sačuvaj sve</button>
        <section class="kartica">
            <div class="kartica-naslov"><h2>Proverite šta je pročitano</h2></div>
            <p class="pomoc bez-margine">
                <?= e(mesta_tekst(count($mesta))) ?>, <?= e(stavki_tekst($robe)) ?>.
                <?php if ($proveri > 0): ?><strong class="tekst-crveno">Proverite označeno (<?= $proveri ?>)</strong> – kucajte artikal i dodirnite pakovanje, upišite količinu ili štiklirajte „Preskoči“.<?php else: ?>Sve je prepoznato – proverite količine i sačuvajte.<?php endif; ?>
            </p>
        </section>
        <?php foreach ($mesta as $i => $m): ?>
            <section class="kartica papir-mesto" aria-label="Mesto <?= e($m['broj']) ?>">
                <div class="mesto-polja red-polja">
                    <div class="mesto-broj-polje">
                        <label for="m_broj_<?= $i ?>">Broj mesta</label>
                        <input class="polje" id="m_broj_<?= $i ?>" name="m[<?= $i ?>][broj]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" value="<?= e($m['broj']) ?>">
                    </div>
                    <div class="mesto-kupac-polje">
                        <label for="m_kupac_<?= $i ?>">Kupac (nije obavezno)</label>
                        <input class="polje" id="m_kupac_<?= $i ?>" name="m[<?= $i ?>][kupac]" type="text" maxlength="120" list="lista-kupaca" value="<?= e($m['kupac']) ?>">
                    </div>
                </div>
                <?php if ($m['adresa'] !== ''): ?>
                    <div class="red-polja">
                        <label for="m_adresa_<?= $i ?>">Adresa ili napomena</label>
                        <input class="polje" id="m_adresa_<?= $i ?>" name="m[<?= $i ?>][adresa]" type="text" maxlength="255" value="<?= e($m['adresa']) ?>">
                    </div>
                <?php else: ?>
                    <input type="hidden" name="m[<?= $i ?>][adresa]" value="">
                <?php endif; ?>
                <?php if (($m['greska'] ?? '') !== ''): ?><p class="tekst-crveno"><strong><?= e($m['greska']) ?></strong></p><?php endif; ?>
                <?php if (!$m['redovi']): ?><p class="pomoc bez-margine">Za ovo mesto nema robe (mesto će biti dodato prazno).</p><?php endif; ?>
                <div class="roba-redovi">
                    <?php foreach ($m['redovi'] as $j => $r):
                        $ime = 'r[' . $i . '][' . $j . ']';
                        $preskocen = $r['preskoci'] === '1';
                        $klasa = ($r['greska'] !== '' && !$preskocen ? ' papir-greska' : ($nejasan($r) ? ' papir-proveri' : '')) . ($preskocen ? ' papir-preskocen' : ''); ?>
                        <?php if ($r['vrsta'] === 'zbirna'): ?>
                            <div class="roba-red papir-red papir-zbirna<?= $klasa ?>">
                                <span class="papir-izvor"><?= e($r['izvor']) ?></span>
                                <input type="hidden" name="<?= $ime ?>[vrsta]" value="zbirna">
                                <input type="hidden" name="<?= $ime ?>[izvor]" value="<?= e($r['izvor']) ?>">
                                <div class="papir-zbirna-glava"><?= ikona('stanje') ?> Zbirna paleta ×
                                    <input class="polje" name="<?= $ime ?>[broj]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" aria-label="Koliko ovakvih paleta" value="<?= e($r['broj']) ?>">
                                </div>
                                <div class="zbirna-redovi">
                                    <div class="zbirna-red zbirna-red-naslov" aria-hidden="true"><span>Artikal</span><span>Na paleti</span></div>
                                    <?php foreach ($r['delovi'] as $k => $d): ?>
                                        <div class="zbirna-red" data-zbirna-red>
                                            <?= polje_artikla($ime . '[d][' . $k . '][tekst]', $ime . '[d][' . $k . '][id]', $d['tekst'], (int)$d['id'], '', '', 'Artikal ' . ($k + 1)) ?>
                                            <input class="polje" name="<?= $ime ?>[d][<?= $k ?>][kol]" type="text" maxlength="12" autocomplete="off" aria-label="Koliko na paleti, artikal <?= $k + 1 ?>" value="<?= e($d['kol']) ?>" data-zbirna-kol>
                                            <p class="zbirna-info" data-zbirna-info aria-live="polite"></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($r['greska'] !== ''): ?><p class="papir-greska-tekst"><?= e($r['greska']) ?></p><?php endif; ?>
                                <label class="papir-preskoci"><input type="checkbox" name="<?= $ime ?>[preskoci]" value="1"<?= $preskocen ? ' checked' : '' ?> data-papir-preskoci> Preskoči ovaj red</label>
                            </div>
                        <?php else: ?>
                            <div class="roba-red papir-red<?= $klasa ?>" data-roba-red data-popis data-po="0" data-pp="0" data-sada="0">
                                <span class="papir-izvor"><?= e($r['izvor']) ?></span>
                                <input type="hidden" name="<?= $ime ?>[vrsta]" value="roba">
                                <input type="hidden" name="<?= $ime ?>[izvor]" value="<?= e($r['izvor']) ?>">
                                <input type="hidden" name="<?= $ime ?>[napomena]" value="<?= e($r['napomena']) ?>">
                                <?= polje_artikla($ime . '[tekst]', $ime . '[id]', $r['tekst'], (int)$r['id'], '', '', 'Artikal: ' . $r['izvor']) ?>
                                <div class="popis-polja roba-kol">
                                    <label class="mala-oznaka" data-polje-pal>paleta<input class="polje" name="<?= $ime ?>[pal]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pal" value="<?= e($r['pal']) ?>"></label>
                                    <label class="mala-oznaka" data-polje-pak>paketa<input class="polje" name="<?= $ime ?>[pak]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pak" value="<?= e($r['pak']) ?>"></label>
                                    <label class="mala-oznaka">komada<input class="polje" name="<?= $ime ?>[kom]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="0" data-p="kom" value="<?= e($r['kom']) ?>"></label>
                                </div>
                                <p class="popis-zbir" data-zbir aria-live="polite"></p>
                                <?php if ($r['napomena'] !== ''): ?><p class="papir-napomena"><?= e($r['napomena']) ?></p><?php endif; ?>
                                <?php if ($r['greska'] !== ''): ?><p class="papir-greska-tekst"><?= e($r['greska']) ?></p><?php endif; ?>
                                <label class="papir-preskoci"><input type="checkbox" name="<?= $ime ?>[preskoci]" value="1"<?= $preskocen ? ' checked' : '' ?> data-papir-preskoci> Preskoči ovaj red</label>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
        <p class="pomoc">Mesto sa istim brojem koje već postoji na spisku se dopunjuje; isti artikal na tom mestu dobija novu količinu.</p>
        <button class="btn btn-primary btn-blok btn-veliko" type="submit" name="akcija" value="sacuvaj"><?= ikona('kvacica') ?> Sačuvaj sve na spisak</button>
        <div class="grupa-dugmica razmak-gore">
            <a class="btn" href="<?= e(url('admin/spisak_papir.php?id=' . $id . '&tekst=1')) ?>"><?= ikona('olovka') ?> Izmeni tekst</a>
            <button class="btn btn-opasno" type="submit" name="akcija" value="odustani" formnovalidate data-potvrda="Odustati? Pročitani spisak neće biti upisan.">Odustani</button>
        </div>
    </form>
    <script type="application/json" id="sku-podaci"><?= sku_podaci_json(sku_za_unos(true)) ?></script>
<?php endif; ?>
<datalist id="lista-kupaca"><?php foreach ($kupci as $k): ?><option value="<?= e($k['naziv']) ?>"><?php endforeach; ?></datalist>
<?php
ui_end();
