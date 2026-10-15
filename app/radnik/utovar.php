<?php
/**
 * Utovar (radnik, a može i administrator): spiskovi koji čekaju utovar i potvrda utovara.
 * Radnik štiklira svaku stavku, po potrebi ispravi količinu i potvrdi – tek tada se skida stanje.
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
    [$kolicine, $greska] = utovar_iz_posta(spisak_stavke($id));
    if ($greska !== null) {
        flash_dodaj('greska', $greska);
        // da se izbor ne izgubi: štiklirane stavke i ispravke ostaju u sesiji samo za ovaj prikaz
        $_SESSION['utovar_forma'][$id] = array_intersect_key($_POST, array_flip(['ok', 'pal', 'pak', 'kom', 'o_kol']));
        preusmeri($nazad);
    }
    $rez = spisak_potvrdi_utovar($id, $kolicine, (int)$korisnik['id']);
    unset($_SESSION['utovar_forma'][$id]);
    if ($rez['ok']) {
        spisak_dnevnik($id, 'utovareno (potvrdio ' . $korisnik['ime'] . ')' . ($rez['razlike'] ? '; drugačije od plana: ' . implode('; ', $rez['razlike']) : ''));
        flash_dodaj('uspeh', 'Utovar je potvrđen: ' . $sp['kupac'] . ', spisak #' . $id . '. Stanje je skinuto.'
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
    $z = spisak_zbir($stavke, $sp['status'] === 'utovareno');
    $forma = $_SESSION['utovar_forma'][$id] ?? [];
    unset($_SESSION['utovar_forma'][$id]);
    $f = static fn(string $k, int $sid): ?string => is_array($forma[$k] ?? null) && is_string($forma[$k][$sid] ?? null) ? $forma[$k][$sid] : null;

    ui_start('Utovar #' . $id, $nav + ['js' => ['assets/js/spisak.js', 'assets/js/utovar.js']]);
    ?>
    <p><a class="btn btn-mali" href="<?= e(url('radnik/utovar.php')) ?>"><?= ikona('nazad') ?> Svi spiskovi</a></p>
    <section class="kartica">
        <div class="kartica-naslov">
            <h2><?= e($sp['kupac']) ?></h2>
            <span class="znacka status-<?= e($sp['status']) ?>"><?= e(SPISAK_STATUSI[$sp['status']]) ?></span>
        </div>
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
    <form method="post" class="kartica" autocomplete="off" action="<?= e(url('radnik/utovar.php')) ?>" data-utovar
          data-potvrda="Potvrditi utovar? Stanje će biti skinuto i spisak se više ne menja.">
        <?= csrf_polje() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="kartica-naslov"><h2>Štiklirajte šta je utovareno</h2></div>
        <p class="pomoc">Ako je utovareno drugačije od plana, dodirnite <strong>Drugačija količina</strong> i upišite stvarno utovareno (0 ako nije utovareno ništa).</p>
        <ul class="lista utovar-lista">
            <?php foreach ($stavke as $st):
                $sid = (int)$st['stavka_id'];
                $ostalo = $st['sku_id'] === null;
                $izmenjeno = $f('pal', $sid) !== null || $f('pak', $sid) !== null || $f('kom', $sid) !== null || $f('o_kol', $sid) !== null;
                $stiklirano = $f('ok', $sid) === '1'; ?>
                <li class="utovar-stavka">
                    <label class="utovar-cek">
                        <input type="checkbox" name="ok[<?= $sid ?>]" value="1"<?= $stiklirano ? ' checked' : '' ?> data-cek>
                        <span>
                            <strong><?= e(stavka_naziv($st)) ?></strong>
                            <span class="utovar-plan"><?= e(stavka_unos_tekst($st)) ?><?= !$ostalo && ((int)$st['u_palete'] > 0 || (int)$st['u_paketi'] > 0) ? ' = ' . e(broj((int)$st['kolicina'])) . ' kom' : '' ?></span>
                        </span>
                    </label>
                    <details class="utovar-izmena"<?= $izmenjeno ? ' open' : '' ?>>
                        <summary>Drugačija količina</summary>
                        <div class="stavka-izmena" data-popis data-po="<?= (int)$st['po_paleti'] ?>" data-pp="<?= (int)$st['po_paketu'] ?>" data-sada="<?= (int)$st['kolicina'] ?>">
                            <?php if ($ostalo): ?>
                                <label class="mala-oznaka"><?= e($st['ostalo_jedinica'] ?: 'kom') ?><input class="polje" name="o_kol[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= e($f('o_kol', $sid) ?? (string)(int)$st['kolicina']) ?>"<?= $izmenjeno ? '' : ' disabled' ?>></label>
                            <?php else: ?>
                                <?php if ((int)$st['po_paleti'] > 0): ?><label class="mala-oznaka">paleta<input class="polje" name="pal[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e($f('pal', $sid) ?? ((int)$st['u_palete'] ?: '')) ?>" placeholder="0" data-p="pal"<?= $izmenjeno ? '' : ' disabled' ?>></label><?php endif; ?>
                                <?php if ((int)$st['po_paketu'] > 0): ?><label class="mala-oznaka">paketa<input class="polje" name="pak[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= e($f('pak', $sid) ?? ((int)$st['u_paketi'] ?: '')) ?>" placeholder="0" data-p="pak"<?= $izmenjeno ? '' : ' disabled' ?>></label><?php endif; ?>
                                <label class="mala-oznaka">komada<input class="polje" name="kom[<?= $sid ?>]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" value="<?= e($f('kom', $sid) ?? ((int)$st['u_komadi'] ?: '')) ?>" placeholder="0" data-p="kom"<?= $izmenjeno ? '' : ' disabled' ?>></label>
                            <?php endif; ?>
                        </div>
                        <?php if (!$ostalo): ?><div class="popis-zbir" data-zbir aria-live="polite"></div><?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="utovar-brojac" data-brojac aria-live="polite"></p>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit" data-potvrdi><?= ikona('kvacica') ?> Potvrdi utovar</button>
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
                <span class="spisak-glava"><strong><?= e($sp['kupac']) ?></strong><span class="znacka status-za_utovar">Za utovar</span></span>
                <span class="spisak-pod">#<?= (int)$sp['id'] ?> · <?= e(dan_u_nedelji((string)$sp['datum'])) ?> <?= e(datum_srp((string)$sp['datum'])) ?><?= $sp['vozilo'] ? ' · ' . e($sp['vozilo']) : '' ?></span>
                <span class="spisak-zbir"><?= (int)$z['stavki'] ?> stavki<?= $z['palete'] > 0 ? ' · ' . e(palete_zbir_tekst($z['palete'])) : '' ?> · ≈ <?= e(kg_tekst($z['kg'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($danas): ?>
    <h2 class="kat-naslov">Danas utovareno</h2>
    <div class="spiskovi">
        <?php foreach ($danas as $sp): ?>
            <a class="spisak-kartica spisak-utovareno" href="<?= e(url('radnik/utovar.php?id=' . (int)$sp['id'])) ?>">
                <span class="spisak-glava"><strong><?= e($sp['kupac']) ?></strong><span class="znacka status-utovareno">Utovareno</span></span>
                <span class="spisak-pod">#<?= (int)$sp['id'] ?> · <?= e(vreme_srp((string)$sp['utovareno_u'])) ?> · <?= e($sp['utovario'] ?? '') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
ui_end();
