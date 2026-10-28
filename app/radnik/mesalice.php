<?php
/**
 * Radnik – mešalice malča: koliko je mešalica ofarbao, po boji. Upisuje se koliko god puta dnevno (i posle svake
 * mešalice – sabira se); stanje se ne menja. Radnik vidi svoje današnje unose i može da obriše poslednji
 * u roku od BRISANJE_RADNIK_MIN minuta.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/mesalice.php';

$radnik = zahtevaj_ulogu('radnik');
$rid = (int)$radnik['id'];
$boje = mesalice_boje();

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    if ($akcija === 'dodaj') {
        $boja = post_str('boja', 60);
        $broj_t = post_str('broj', 4);
        if (!in_array($boja, $boje, true)) {
            flash_dodaj('greska', 'Izaberi boju.');
        } elseif (!ctype_digit($broj_t) || (int)$broj_t < 1 || (int)$broj_t > MESALICE_MAX) {
            flash_dodaj('greska', 'Upiši koliko mešalica (od 1 do ' . MESALICE_MAX . ').');
        } else {
            $rez = mesalica_dodaj($rid, $boja, (int)$broj_t, post_str('kljuc', 32));
            if (!$rez['ok']) {
                flash_dodaj('greska', (string)$rez['greska']);
            } elseif ($rez['duplikat']) {
                flash_dodaj('info', 'Ovaj unos je već sačuvan.');
            } else {
                flash_dodaj('uspeh', 'Sačuvano: ' . $boja . ' – ' . mesalice_tekst((int)$broj_t) . '.');
            }
        }
    } elseif ($akcija === 'obrisi') {
        $id = post_int('id');
        $sme = mesalica_radnik_sme_da_obrise($rid);
        if ($sme === null || (int)$sme['id'] !== $id) {
            flash_dodaj('greska', 'Taj unos više ne može da se obriše. Briše se samo poslednji unos, u roku od ' . BRISANJE_RADNIK_MIN . ' minuta.');
        } else {
            $rez = mesalica_obrisi($id, $rid, 'brisanje_radnik');
            flash_dodaj($rez['ok'] ? 'uspeh' : 'greska', $rez['ok'] ? 'Unos je obrisan.' : (string)$rez['greska']);
        }
    }
    preusmeri('radnik/mesalice.php');
}

$danas = db_all(
    'SELECT id, boja, broj, nastalo, uneto FROM mesalice WHERE korisnik_id = ? AND obrisan = 0 AND nastalo >= ? AND nastalo < ? ORDER BY id DESC',
    [$rid, danas_od(), danas_do()]
);
$ukupno = array_sum(array_map(static fn(array $u): int => (int)$u['broj'], $danas));
$po_boji = mesalice_po_boji(array_reverse($danas));
$sme = mesalica_radnik_sme_da_obrise($rid);
$sme_id = $sme !== null ? (int)$sme['id'] : 0;
// poslednja boja ostaje izabrana (posle svake mešalice samo se dodirne Sačuvaj)
$poslednja = (string)db_val('SELECT boja FROM mesalice WHERE korisnik_id = ? AND obrisan = 0 ORDER BY id DESC LIMIT 1', [$rid]);

ui_start('Mešalice', ['nav' => 'radnik', 'aktivno' => 'mesalice', 'js' => ['assets/js/mesalice.js']]);
?>
<div class="stat-mreza">
    <div class="stat stat-uspeh">
        <span class="broj"><?= e(broj($ukupno)) ?></span>
        <span class="opis">danas mešalica</span>
    </div>
    <div class="stat">
        <span class="broj"><?= count($danas) ?></span>
        <span class="opis">unosa danas</span>
    </div>
</div>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('radnik/mesalice.php')) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="dodaj">
    <input type="hidden" name="kljuc" value="<?= e(bin2hex(random_bytes(16))) ?>">
    <div class="kartica-naslov"><h2>Upiši mešalice</h2></div>
    <?php if (!$boje): ?>
        <p class="pomoc bez-margine">Još nema boja malča. Javi administratoru (Podešavanja → Malč → boje).</p>
    <?php else: ?>
        <h3 class="korak-naslov" id="naslov-boja">Boja</h3>
        <div class="imena boje-mesalice" role="radiogroup" aria-labelledby="naslov-boja">
            <?php foreach ($boje as $b): $tacka = mesalica_tacka($b); ?>
                <label class="ime">
                    <input type="radio" name="boja" value="<?= e($b) ?>" required<?= $b === $poslednja ? ' checked' : '' ?>>
                    <span><?php if ($tacka !== ''): ?><i class="boja-tacka" data-boja="<?= e($tacka) ?>" aria-hidden="true"></i><?php endif; ?><?= e($b) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <h3 class="korak-naslov"><label for="broj">Koliko mešalica</label></h3>
        <input class="kolicina-polje" id="broj" name="broj" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2"
               autocomplete="off" value="1" required data-max="<?= MESALICE_MAX ?>">
        <div class="koraci koraci-2">
            <button type="button" data-mesalice-delta="-1" aria-label="Jedna mešalica manje">−1</button>
            <button type="button" data-mesalice-delta="1" aria-label="Jedna mešalica više">+1</button>
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit">Sačuvaj</button>
        <p class="pomoc bez-margine razmak-gore">Upiši koliko god puta treba, i posle svake mešalice – sve se sabira. Stanje se ne menja: vreće se upisuju u Proizvodnji kad se malč spakuje.</p>
    <?php endif; ?>
</form>

<section class="kartica" aria-labelledby="naslov-lista">
    <div class="kartica-naslov"><h2 id="naslov-lista">Moje današnje mešalice</h2></div>
    <?php if (!$danas): ?>
        <p class="pomoc bez-margine">Danas još nema unosa.</p>
    <?php else: ?>
        <p class="mesalice-boje"><?= e(mesalice_boje_tekst($po_boji)) ?></p>
        <ul class="lista">
            <?php foreach ($danas as $u): ?>
                <li>
                    <div class="unos-red">
                        <span class="naziv"><?= e($u['boja']) ?></span>
                        <span class="kol"><?= e(mesalice_tekst((int)$u['broj'])) ?></span>
                        <span class="pod"><?= e(vreme_srp((string)$u['nastalo'])) ?></span>
                        <?php if ((int)$u['id'] === $sme_id):
                            $ostalo = max(1, (int)ceil((BRISANJE_RADNIK_MIN * 60 - (time() - (int)strtotime((string)$u['uneto']))) / 60)); ?>
                            <div class="akcije">
                                <form method="post" action="<?= e(url('radnik/mesalice.php')) ?>" class="forma-u-liniji"
                                      data-potvrda="Obrisati ovaj unos (<?= e($u['boja']) ?>, <?= e(mesalice_tekst((int)$u['broj'])) ?>)?">
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
