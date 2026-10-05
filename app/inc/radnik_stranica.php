<?php
/**
 * Ekran radnika. Isti kod služi za dve stavke:
 *  - Proizvodnja   (tip "proizvodnja")
 *  - Kućna prodaja (tip "kucna_prodaja") – prodaja na licu mesta; radnik ne vidi stanje.
 *
 * Radnik vidi samo svoje današnje unose i može da obriše samo svoj poslednji unos
 * u roku od BRISANJE_RADNIK_MIN minuta.
 */
declare(strict_types=1);

require_once __DIR__ . '/izbor.php';

function radnik_postavke(string $tip): array
{
    $sve = [
        'proizvodnja' => [
            'naslov'  => 'Proizvodnja',
            'nav'     => 'proizvodnja',
            'adresa'  => 'radnik/index.php',
            'forma'   => 'Novi unos',
            'dugme'   => 'Sačuvaj unos',
            'ukupno'  => 'danas napravljeno (vreća)',
            'lista'   => 'Moji današnji unosi',
            'prazno'  => 'Danas još nema unosa.',
            'uspeh'   => 'Sačuvano',
            'napomena' => false,
        ],
        'kucna_prodaja' => [
            'naslov'  => 'Kućna prodaja',
            'nav'     => 'kucna',
            'adresa'  => 'radnik/prodaja.php',
            'forma'   => 'Nova prodaja na licu mesta',
            'dugme'   => 'Sačuvaj prodaju',
            'ukupno'  => 'danas prodato (vreća)',
            'lista'   => 'Moja današnja prodaja',
            'prazno'  => 'Danas još nema prodaje.',
            'uspeh'   => 'Prodaja sačuvana',
            'napomena' => true,
        ],
    ];
    return $sve[$tip];
}

function radnik_obradi_dodavanje(array $radnik, string $tip, array $cfg): void
{
    $sku_id = post_int('sku_id');
    $sku = $sku_id > 0 ? sku_aktivan($sku_id) : null;
    if ($sku === null) {
        flash_dodaj('greska', 'Izaberite artikal, pa pakovanje (i boju ili granulaciju ako je ima).');
        preusmeri($cfg['adresa']);
    }
    $adresa = $cfg['adresa'] . '?s=' . $sku_id;

    $kol = procitaj_kolicinu($sku);
    if (isset($kol['greska'])) {
        flash_dodaj('greska', $kol['greska']);
        preusmeri($adresa);
    }

    $napomena = $cfg['napomena'] ? post_str('napomena', 255) : null;
    $rez = unos_dodaj($tip, $sku_id, $kol['komadi'], $kol['palete'], (int)$radnik['id'], null, $napomena, post_str('kljuc', 32), null, $kol['paketi']);

    if ($rez['ok'] && !empty($rez['duplikat'])) {
        flash_dodaj('info', 'Ovaj unos je već sačuvan.');
    } elseif ($rez['ok']) {
        flash_dodaj('uspeh', $cfg['uspeh'] . ': ' . sku_naziv($sku) . ' – ' . kolicina_tekst($kol['komadi'], $kol['palete'], $kol['paketi']));
    } elseif (!empty($rez['nedovoljno'])) {
        // Radnik ne vidi stanje, pa mu se ne otkrivaju ni brojevi.
        flash_dodaj('greska', 'Nema dovoljno na stanju za tu količinu. Proverite broj ili se javite administratoru.');
    } else {
        flash_dodaj('greska', (string)($rez['greska'] ?? 'Unos nije sačuvan.'));
    }
    preusmeri($adresa);
}

function radnik_obradi_brisanje(array $radnik, array $cfg): void
{
    $id = post_int('id');
    $dozvoljen = radnik_sme_da_obrise((int)$radnik['id']);
    if ($dozvoljen === null || (int)$dozvoljen['id'] !== $id) {
        flash_dodaj('greska', 'Taj unos više ne može da se obriše. Briše se samo poslednji unos, u roku od ' . BRISANJE_RADNIK_MIN . ' minuta.');
        preusmeri($cfg['adresa']);
    }
    $rez = unos_obrisi($id, (int)$radnik['id'], 'brisanje_radnik');
    if ($rez['ok']) {
        flash_dodaj('uspeh', 'Unos je obrisan.');
    } else {
        flash_dodaj('greska', $rez['greska']);
    }
    preusmeri($cfg['adresa']);
}

function radnik_stranica(string $tip): void
{
    $radnik = zahtevaj_ulogu('radnik');
    $cfg = radnik_postavke($tip);

    if (je_post()) {
        csrf_proveri();
        $akcija = post_str('akcija', 20);
        if ($akcija === 'dodaj') {
            radnik_obradi_dodavanje($radnik, $tip, $cfg);
        } elseif ($akcija === 'obrisi') {
            radnik_obradi_brisanje($radnik, $cfg);
        }
        preusmeri($cfg['adresa']);
    }

    $unosi = db_all(
        'SELECT u.id, u.kolicina, u.palete, u.paketi, u.napomena, u.nastalo, u.uneto, ' . SKU_POLJA . ' FROM unosi u ' . SKU_SPOJ
        . ' WHERE u.korisnik_id = ? AND u.tip = ? AND u.obrisan = 0 AND u.nastalo >= ? AND u.nastalo < ? ORDER BY u.id DESC',
        [(int)$radnik['id'], $tip, danas_od(), danas_do()]
    );
    $ukupno = 0;
    foreach ($unosi as $u) {
        $ukupno += (int)$u['kolicina'];
    }
    $sme = radnik_sme_da_obrise((int)$radnik['id']);
    $sme_id = $sme !== null && $sme['tip'] === $tip ? (int)$sme['id'] : 0;

    $izabran_sku = get_int('s');
    if ($izabran_sku > 0 && sku_aktivan($izabran_sku) === null) {
        $izabran_sku = 0;
    }

    ui_start($cfg['naslov'], ['nav' => 'radnik', 'aktivno' => $cfg['nav'], 'js' => ['assets/js/unos.js']]);
    ?>
    <div class="stat-mreza">
        <div class="stat stat-uspeh">
            <span class="broj"><?= e(broj($ukupno)) ?></span>
            <span class="opis"><?= e($cfg['ukupno']) ?></span>
        </div>
        <div class="stat">
            <span class="broj"><?= count($unosi) ?></span>
            <span class="opis">unosa danas</span>
        </div>
    </div>

    <form method="post" class="kartica" autocomplete="off" action="<?= e(url($cfg['adresa'])) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="dodaj">
        <input type="hidden" name="kljuc" value="<?= e(bin2hex(random_bytes(16))) ?>">
        <div class="kartica-naslov"><h2><?= e($cfg['forma']) ?></h2></div>

        <?= izbor_html(katalog_za_izbor(false), $izabran_sku) ?>

        <?php if ($cfg['napomena']): ?>
            <div class="red-polja">
                <label for="napomena">Napomena (nije obavezno)</label>
                <input class="polje" id="napomena" name="napomena" type="text" maxlength="255" placeholder="npr. kupac, auto, gotovina">
            </div>
        <?php endif; ?>

        <button class="btn btn-primary btn-veliko btn-blok" type="submit" data-sacuvaj disabled><?= e($cfg['dugme']) ?></button>
    </form>

    <section class="kartica" aria-labelledby="naslov-lista">
        <div class="kartica-naslov"><h2 id="naslov-lista"><?= e($cfg['lista']) ?></h2></div>
        <?php if (!$unosi): ?>
            <p class="pomoc bez-margine"><?= e($cfg['prazno']) ?></p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($unosi as $u): ?>
                    <li>
                        <div class="unos-red">
                            <span class="naziv"><?= e(sku_naziv($u)) ?><?php if ($u['oznaka']): ?> <span class="znacka znacka-pl"><?= e($u['oznaka']) ?></span><?php endif; ?></span>
                            <span class="kol"><?= e(broj((int)$u['kolicina'])) ?> kom</span>
                            <span class="pod"><?= e(vreme_srp((string)$u['nastalo'])) ?><?= $u['napomena'] ? ' · ' . e($u['napomena']) : '' ?></span>
                            <span class="kol-pod"><?= e(nacin_unosa_tekst($u)) ?></span>
                            <?php if ((int)$u['id'] === $sme_id):
                                $ostalo = max(1, (int)ceil((BRISANJE_RADNIK_MIN * 60 - (time() - (int)strtotime((string)$u['uneto']))) / 60)); ?>
                                <div class="akcije">
                                    <form method="post" action="<?= e(url($cfg['adresa'])) ?>" class="forma-u-liniji"
                                          data-potvrda="Obrisati ovaj unos (<?= e(sku_naziv($u)) ?>, <?= (int)$u['kolicina'] ?> kom)?">
                                        <?= csrf_polje() ?>
                                        <input type="hidden" name="akcija" value="obrisi">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-opasno btn-mali"><?= ikona('kanta') ?> Obriši</button>
                                    </form>
                                    <span class="pomoc">Greška? Možeš da obrišeš još oko <?= $ostalo ?> min.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
    ui_end();
}
