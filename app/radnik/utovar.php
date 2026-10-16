<?php
/**
 * Utovar (radnik, a može i administrator): spiskovi koji čekaju utovar i potvrda utovara.
 * Roba je složena po mestima istovara (Mesto 8, 9, 5 …). Radnik štiklira svaku stavku, po potrebi utovari
 * više ili manje od plana (npr. šleper na licu mesta), doda artikal kog nema na spisku (za izabrano mesto)
 * i potvrdi – tek tada se skida stanje.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';
require __DIR__ . '/../inc/spiskovi.php';

$korisnik = zahtevaj_ulogu();
$je_admin = $korisnik['uloga'] === 'admin';
$id = je_post() ? post_int('id') : get_int('id');

if (je_post()) {
    csrf_proveri();
    $sp = spisak_ucitaj($id);
    $nazad = 'radnik/utovar.php?id=' . $id;
    if ($sp === null) {
        flash_dodaj('greska', 'Spisak ne postoji.');
        preusmeri('radnik/utovar.php');
    }
    $stanje_forme = array_intersect_key($_POST, array_flip(['ok', 'pal', 'pak', 'kom', 'o_kol']));

    // ── Dodavanje artikla kog nema na spisku (utovar na licu mesta) ──
    if (post_str('akcija', 10) === 'dodaj') {
        $_SESSION['utovar_forma'][$id] = $stanje_forme;      // štiklirano i upisano ostaje posle dodavanja
        $novi = [post_str('novi_pal', 8), post_str('novi_pak', 8), post_str('novi_kom', 8)];
        $artikal = post_str('novi_artikal', 120);
        if ($artikal === '' && post_int('novi_sku') === 0 && implode('', $novi) === '') {
            flash_dodaj('info', 'Ništa nije dodato. Kad završite utovar, dodirnite „Potvrdi utovar“.');
            preusmeri($nazad);
        }
        if ($artikal !== '') {
            $nadjen = sku_po_unosu($artikal);         // šifra (H50L) ili naziv
            $sku = $nadjen['sku'];
            $greska_artikla = (string)$nadjen['greska'];
        } else {
            $sku = sku_aktivan(post_int('novi_sku'));
            $greska_artikla = 'Upišite artikal koji dodajete na spisak (npr. H50L ili B oblutak 4/6).';
        }
        if ($sku === null) {
            flash_dodaj('greska', $greska_artikla);
            preusmeri($nazad);
        }
        $r = popis_vrednost('', $novi[0], $novi[1], $novi[2], $sku);
        if (isset($r['greska']) || !$r['komadi']) {
            flash_dodaj('greska', $r['greska'] ?? 'Upišite koliko je utovareno (palete, paketi i/ili komadi).');
            preusmeri($nazad);
        }
        $rez = spisak_dodaj_pri_utovaru($id, max(0, post_int('novi_mesto')), $sku, (int)$r['komadi'], (int)$novi[0], (int)$novi[1], (int)$novi[2], (int)$korisnik['id']);
        if (!$rez['ok']) {
            flash_dodaj('greska', (string)$rez['greska']);
            preusmeri($nazad);
        }
        $ok = is_array($stanje_forme['ok'] ?? null) ? $stanje_forme['ok'] : [];
        $ok[(int)$rez['stavka_id']] = '1';                      // dodato pri utovaru = utovareno
        $_SESSION['utovar_forma'][$id]['ok'] = $ok;
        $za = $rez['mesto'] !== null ? ' za ' . mesto_naslov((int)$rez['mesto']['broj'], $rez['mesto']['kupac']) : '';
        spisak_dnevnik($id, 'pri utovaru dodato' . $za . ': ' . sku_oznaka($sku) . ' – ' . broj((int)$r['komadi']) . ' kom (' . $korisnik['ime'] . ')');
        flash_dodaj('uspeh', 'Dodato na spisak' . $za . ': ' . sku_oznaka($sku) . ' – ' . broj((int)$r['komadi']) . ' kom (štiklirano). Kad završite utovar, potvrdite ga.');
        preusmeri($nazad);
    }

    [$kolicine, $greska] = utovar_iz_posta(spisak_stavke($id));
    if ($greska !== null) {
        flash_dodaj('greska', $greska);
        // da se izbor ne izgubi: štiklirane stavke i ispravke ostaju u sesiji samo za ovaj prikaz
        $_SESSION['utovar_forma'][$id] = $stanje_forme;
        preusmeri($nazad);
    }
    $rez = spisak_potvrdi_utovar($id, $kolicine, (int)$korisnik['id']);
    unset($_SESSION['utovar_forma'][$id]);
    if ($rez['ok']) {
        spisak_dnevnik($id, 'utovareno (potvrdio ' . $korisnik['ime'] . ')' . ($rez['razlike'] ? '; drugačije od plana: ' . implode('; ', $rez['razlike']) : ''));
        flash_dodaj('uspeh', 'Utovar je potvrđen: ' . spisak_naslov($sp) . ', spisak #' . $id . '. Stanje je skinuto.'
            . ($rez['razlike'] ? ' Drugačije od plana: ' . implode('; ', $rez['razlike']) . '.' : ''));
        preusmeri($je_admin ? 'admin/spisak.php?id=' . $id : 'radnik/utovar.php');
    }
    flash_dodaj($rez['vec'] ? 'info' : 'greska', (string)$rez['greska']);
    preusmeri($rez['vec'] ? 'radnik/utovar.php' : $nazad);
}

$nav = $je_admin ? ['nav' => 'admin', 'aktivno' => 'utovar'] : ['nav' => 'radnik', 'aktivno' => 'utovar'];

// ── Jedan spisak: potvrda utovara ──
if ($id > 0) {
    $sp = spisak_ucitaj($id);
    if ($sp === null || !in_array($sp['status'], ['za_utovar', 'utovareno'], true)) {
        flash_dodaj('info', 'Ovaj spisak više ne čeka utovar.');
        preusmeri('radnik/utovar.php');
    }
    $stavke = spisak_stavke($id);
    $mesta = spisak_mesta($id);
    $z = spisak_zbir($stavke, $sp['status'] === 'utovareno');
    $forma = $_SESSION['utovar_forma'][$id] ?? [];
    unset($_SESSION['utovar_forma'][$id]);
    $f = static fn(string $k, int $sid): ?string => is_array($forma[$k] ?? null) && is_string($forma[$k][$sid] ?? null) ? $forma[$k][$sid] : null;

    ui_start('Utovar #' . $id, $nav + ['js' => ['assets/js/spisak.js', 'assets/js/utovar.js']]);
    ?>
    <p><a class="btn btn-mali" href="<?= e(url('radnik/utovar.php')) ?>"><?= ikona('nazad') ?> Svi spiskovi</a></p>
    <section class="kartica">
        <div class="kartica-naslov">
            <h2><?= e(spisak_naslov($sp)) ?></h2>
            <span class="znacka status-<?= e($sp['status']) ?>"><?= e(SPISAK_STATUSI[$sp['status']]) ?></span>
        </div>
        <?php if (spisak_kupci($sp) !== ''): ?><p class="spisak-kupci"><?= e(spisak_kupci($sp)) ?></p><?php endif; ?>
        <dl class="spisak-podaci">
            <div><dt>Utovar</dt><dd><?= e(dan_u_nedelji((string)$sp['datum'])) ?>, <?= e(datum_srp((string)$sp['datum'])) ?></dd></div>
            <?php if ($sp['vozilo']): ?><div><dt>Vozilo</dt><dd><?= e($sp['vozilo']) ?></dd></div><?php endif; ?>
            <?php if ($sp['vozac']): ?><div><dt>Vozač</dt><dd><?= e($sp['vozac']) ?></dd></div><?php endif; ?>
            <?php if ($sp['napomena']): ?><div><dt>Napomena</dt><dd><?= e($sp['napomena']) ?></dd></div><?php endif; ?>
        </dl>
        <p class="razmak-dole"><a class="btn btn-mali" href="<?= e(url('admin/spisak_stampa.php?id=' . $id)) ?>"><?= ikona('preuzmi') ?> Štampa / Podeli</a></p>
        <p class="bez-margine"><strong><?= e($z['palete'] > 0 ? palete_zbir_tekst($z['palete']) : '') ?></strong><?= $z['palete'] > 0 ? ' · ' : '' ?>≈ <?= e(kg_tekst($z['kg'])) ?></p>
    </section>

    <?php if ($sp['status'] === 'utovareno'): ?>
        <div class="poruka poruka-uspeh" role="status">Utovareno <?= e(datum_vreme_srp((string)$sp['utovareno_u'])) ?> (<?= e($sp['utovario'] ?? '') ?>).</div>
    <?php else: ?>
    <form method="post" class="kartica" autocomplete="off" action="<?= e(url('radnik/utovar.php')) ?>" data-utovar>
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="kartica-naslov"><h2>Štiklirajte šta je utovareno</h2></div>
        <p class="pomoc">Ako je utovareno <strong>više ili manje</strong> od plana (npr. u šleper stane više), dodirnite <strong>Više ili manje od plana</strong> i upišite stvarno utovareno (0 ako nije utovareno ništa). Artikal kog nema na spisku dodajte dole.</p>
        <?php
        /** Jedna stavka za štikliranje; $grupa = zbirna paleta kojoj pripada (0 = posebna stavka). */
        $red_utovara = static function (array $st, int $grupa) use ($f): void {
            $sid = (int)$st['stavka_id'];
            $ostalo = $st['sku_id'] === null;
            $u_paleti = $grupa > 0;
            $izmenjeno = $f('pal', $sid) !== null || $f('pak', $sid) !== null || $f('kom', $sid) !== null || $f('o_kol', $sid) !== null;
            $stiklirano = $f('ok', $sid) === '1';
            $iskljuceno = $izmenjeno ? '' : ' disabled';
            ?>
            <li class="utovar-stavka">
                <label class="utovar-cek">
                    <input type="checkbox" name="ok[<?= $sid ?>]" value="1"<?= $stiklirano ? ' checked' : '' ?> data-cek<?= $u_paleti ? ' data-grupa="' . $grupa . '"' : '' ?>>
                    <span>
                        <strong><?= e(stavka_kratko($st)) ?><?php if (stavka_ime_ispod($st) !== null): ?> <small class="utovar-ime"><?= e(stavka_ime_ispod($st)) ?></small><?php endif; ?></strong>
                        <?php if ($st['dodao_id'] !== null): ?><span class="znacka">dodato pri utovaru</span><?php endif; ?>
                        <span class="utovar-plan"><?= e(stavka_plan_tekst($st)) ?></span>
                    </span>
                </label>
                <details class="utovar-izmena"<?= $izmenjeno ? ' open' : '' ?>>
                    <summary>Više ili manje od plana</summary>
                    <div class="stavka-izmena" data-popis data-po="<?= $u_paleti ? 0 : (int)$st['po_paleti'] ?>" data-pp="<?= $u_paleti ? 0 : (int)$st['po_paketu'] ?>" data-sada="<?= (int)$st['kolicina'] ?>">
                        <?php if ($ostalo): ?>
                            <label class="mala-oznaka"><?= e($st['ostalo_jedinica'] ?: 'kom') ?><input class="polje" name="o_kol[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= e($f('o_kol', $sid) ?? (string)(int)$st['kolicina']) ?>"<?= $iskljuceno ?>></label>
                        <?php elseif ($u_paleti): ?>
                            <label class="mala-oznaka">komada ukupno<?= (int)$st['paleta_broj'] > 1 ? ' (sve palete)' : '' ?><input class="polje" name="kom[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= e($f('kom', $sid) ?? (string)(int)$st['kolicina']) ?>" data-p="kom"<?= $iskljuceno ?>></label>
                        <?php else: ?>
                            <?php if ((int)$st['po_paleti'] > 0): ?><label class="mala-oznaka">paleta<input class="polje" name="pal[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e($f('pal', $sid) ?? ((int)$st['u_palete'] ?: '')) ?>" placeholder="0" data-p="pal"<?= $iskljuceno ?>></label><?php endif; ?>
                            <?php if ((int)$st['po_paketu'] > 0): ?><label class="mala-oznaka">paketa<input class="polje" name="pak[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e($f('pak', $sid) ?? ((int)$st['u_paketi'] ?: '')) ?>" placeholder="0" data-p="pak"<?= $iskljuceno ?>></label><?php endif; ?>
                            <label class="mala-oznaka">komada<input class="polje" name="kom[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= e($f('kom', $sid) ?? ((int)$st['u_komadi'] ?: '')) ?>" placeholder="0" data-p="kom"<?= $iskljuceno ?>></label>
                        <?php endif; ?>
                    </div>
                    <?php if (!$ostalo && !$u_paleti): ?><div class="popis-zbir" data-zbir aria-live="polite"></div><?php endif; ?>
                </details>
            </li>
            <?php
        };
        ?>
        <?php foreach (spisak_po_mestima($stavke, $mesta) as $mid => $grupa):
            if (!$grupa['stavke']) {
                continue;
            }
            $m = $grupa['mesto']; ?>
            <section class="utovar-mesto" id="mesto-<?= (int)$mid ?>" aria-labelledby="mesto-<?= (int)$mid ?>-naslov">
                <div class="mesto-glava">
                    <?php if ($m !== null): ?><span class="mesto-krug" aria-hidden="true"><?= (int)$m['broj'] ?></span><?php endif; ?>
                    <div class="mesto-ime">
                        <h3 id="mesto-<?= (int)$mid ?>-naslov"><?= $m !== null ? 'Mesto ' . (int)$m['broj'] : 'Bez mesta' ?></h3>
                        <?php if ($m !== null && $m['kupac'] !== null): ?><span class="mesto-kupac"><?= e($m['kupac']) ?></span><?php endif; ?>
                        <?php if ($m !== null && $m['adresa'] !== null): ?><span class="mesto-adresa"><?= e($m['adresa']) ?></span><?php endif; ?>
                    </div>
                </div>
                <?php if ($grupa['grupe'][0]['stavke']): ?>
                    <ul class="lista utovar-lista"><?php foreach ($grupa['grupe'][0]['stavke'] as $st) { $red_utovara($st, 0); } ?></ul>
                <?php endif; ?>
                <?php foreach ($grupa['grupe'] as $pid => $g):
                    if ($pid === 0) {
                        continue;
                    } ?>
                    <div class="zbirna-paleta utovar-zbirna">
                        <label class="utovar-cek utovar-grupa">
                            <input type="checkbox" data-cek-grupa="<?= (int)$pid ?>" aria-label="Štikliraj celu zbirnu paletu <?= e($g['naziv']) ?>">
                            <span>
                                <strong><?= ikona('stanje') ?> <?= e($g['naziv']) ?></strong>
                                <span class="utovar-plan">zbirna paleta – složiti <?= $g['broj'] > 1 ? e(palete_tekst($g['broj'])) . ', na svaku' : 'na jednu paletu' ?>: <?= e(artikala_tekst(count($g['stavke']))) ?></span>
                            </span>
                        </label>
                        <ul class="lista utovar-lista"><?php foreach ($g['stavke'] as $st) { $red_utovara($st, (int)$pid); } ?></ul>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <details class="utovar-dodaj" data-dodavanje>
            <summary class="btn btn-blok"><?= ikona('plus') ?> Dodaj artikal kog nema na spisku</summary>
            <?php if (count($mesta) > 1): ?>
                <div class="red-polja razmak-gore">
                    <label for="novi_mesto">Za mesto</label>
                    <select class="polje" id="novi_mesto" name="novi_mesto">
                        <option value="">— za koje mesto? —</option>
                        <?php foreach ($mesta as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"><?= e(mesto_naslov((int)$m['broj'], $m['kupac'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="novi_mesto" value="<?= $mesta ? (int)$mesta[0]['id'] : 0 ?>">
            <?php endif; ?>
            <div class="red-polja razmak-gore">
                <label for="novi_artikal">Artikal (H50L, B oblutak 4/6 …)</label>
                <input class="polje polje-sifra" id="novi_artikal" name="novi_artikal" type="text" list="lista-sku" maxlength="120"
                       autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="npr. H50L ili oblutak beli 4/6" data-artikal>
                <p class="artikal-ime" data-artikal-ime aria-live="polite"></p>
            </div>
            <div class="popis-polja" data-popis data-po="0" data-pp="0">
                <label class="mala-oznaka" data-polje-pal>paleta<input class="polje" name="novi_pal" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pal"></label>
                <label class="mala-oznaka" data-polje-pak>paketa<input class="polje" name="novi_pak" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="0" data-p="pak"></label>
                <label class="mala-oznaka">komada<input class="polje" name="novi_kom" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="0" data-p="kom"></label>
            </div>
            <p class="popis-zbir" data-zbir aria-live="polite"></p>
            <button class="btn btn-braon btn-blok" type="submit" name="akcija" value="dodaj" data-dodaj>Dodaj na spisak</button>
            <?php $sku_unos = sku_za_unos(); /* bez stanja – radnik ga ne vidi */ ?>
            <datalist id="lista-sku"><?php foreach ($sku_unos as $r): ?><option value="<?= e($r['sifra'] !== '' ? $r['sifra'] : $r['naziv']) ?>"><?= e($r['naziv']) ?></option><?php endforeach; ?></datalist>
            <script type="application/json" id="sku-podaci"><?= json_za_html(array_map(static fn(array $r): array => [$r['sifra'], $r['naziv'], $r['sku_id'], $r['po'], $r['pp']], $sku_unos)) ?></script>
        </details>
        <p class="utovar-brojac" data-brojac aria-live="polite"></p>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit" name="akcija" value="potvrdi" data-potvrdi
                data-potvrda="Potvrditi utovar? Stanje će biti skinuto i spisak se više ne menja."><?= ikona('kvacica') ?> Potvrdi utovar</button>
    </form>
    <?php endif; ?>
    <?php
    ui_end();
    exit;
}

// ── Spiskovi koji čekaju utovar ──
$spiskovi = db_all('SELECT ' . SPISAK_POLJA . ' ' . SPISAK_IZ . " WHERE sp.status = 'za_utovar' ORDER BY sp.datum, sp.id");
$danas = db_all('SELECT ' . SPISAK_POLJA . ' ' . SPISAK_IZ . " WHERE sp.status = 'utovareno' AND sp.utovareno_u >= ? ORDER BY sp.utovareno_u DESC", [danas_od()]);

ui_start('Utovar', $nav);
?>
<?php if (!$spiskovi): ?>
    <div class="kartica"><p class="bez-margine">Nema spiskova koji čekaju utovar.</p></div>
<?php else: ?>
    <div class="spiskovi">
        <?php foreach ($spiskovi as $sp):
            $z = spisak_zbir(spisak_stavke((int)$sp['id'])); ?>
            <a class="spisak-kartica spisak-za_utovar" href="<?= e(url('radnik/utovar.php?id=' . (int)$sp['id'])) ?>">
                <span class="spisak-glava"><strong><?= e(spisak_naslov($sp)) ?></strong><span class="znacka status-za_utovar">Za utovar</span></span>
                <?php if (spisak_kupci($sp) !== ''): ?><span class="spisak-pod"><?= e(spisak_kupci($sp)) ?></span><?php endif; ?>
                <span class="spisak-pod">#<?= (int)$sp['id'] ?> · <?= e(dan_u_nedelji((string)$sp['datum'])) ?> <?= e(datum_srp((string)$sp['datum'])) ?><?= $sp['vozilo'] ? ' · ' . e($sp['vozilo']) : '' ?></span>
                <span class="spisak-zbir"><?= e(stavki_tekst((int)$z['stavki'])) ?><?= $z['palete'] > 0 ? ' · ' . e(palete_zbir_tekst($z['palete'])) : '' ?> · ≈ <?= e(kg_tekst($z['kg'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($danas): ?>
    <h2 class="kat-naslov">Danas utovareno</h2>
    <div class="spiskovi">
        <?php foreach ($danas as $sp): ?>
            <a class="spisak-kartica spisak-utovareno" href="<?= e(url('radnik/utovar.php?id=' . (int)$sp['id'])) ?>">
                <span class="spisak-glava"><strong><?= e(spisak_naslov($sp)) ?></strong><span class="znacka status-utovareno">Utovareno</span></span>
                <span class="spisak-pod">#<?= (int)$sp['id'] ?> · <?= e(vreme_srp((string)$sp['utovareno_u'])) ?> · <?= e($sp['utovario'] ?? '') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
ui_end();
