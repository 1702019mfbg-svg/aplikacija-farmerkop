<?php
/**
 * Prijava: radnik (ime + PIN od 4 cifre) ili administrator (korisničko ime + šifra).
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$korisnik = auth_user();
if ($korisnik !== null) {
    preusmeri(pocetna_za($korisnik));
}

$admin_forma = isset($_GET['admin']);
$izabran = 0;
$greska = null;

if (je_post()) {
    csrf_proveri();
    if (post_str('tip') === 'admin') {
        $admin_forma = true;
        $rez = prijava_admin(post_str('korisnicko_ime', 40), (string)($_POST['sifra'] ?? ''));
    } else {
        $izabran = post_int('radnik_id');
        $rez = prijava_radnik($izabran, (string)($_POST['pin'] ?? ''));
    }
    if ($rez['ok']) {
        preusmeri(pocetna_za($rez['korisnik']));
    }
    $greska = $rez['poruka'];
}

$radnici = $admin_forma ? [] : db_all("SELECT id, ime FROM korisnici WHERE uloga = 'radnik' AND aktivan = 1 ORDER BY ime");

ui_start($admin_forma ? 'Prijava administratora' : 'Prijava', [
    'bez_zaglavlja' => true,
    'telo'          => 'telo-prijava',
    'js'            => $admin_forma ? [] : ['assets/js/pin.js'],
]);
?>
<div class="prijava-logo">
    <img src="<?= e(asset('assets/icons/icon.svg')) ?>" alt="" width="84" height="84">
    <h1><?= e(APP_NAZIV) ?></h1>
    <p>Evidencija proizvodnje i zaliha</p>
</div>

<?php if ($greska !== null): ?>
    <div class="poruka poruka-greska" role="alert"><?= e($greska) ?></div>
<?php endif; ?>

<?php if ($admin_forma): ?>
    <form method="post" class="kartica" autocomplete="off">
        <?= csrf_polje() ?>
        <input type="hidden" name="tip" value="admin">
        <h2>Administrator</h2>
        <div class="red-polja">
            <label for="korisnicko_ime">Korisničko ime</label>
            <input class="polje" id="korisnicko_ime" name="korisnicko_ime" type="text" maxlength="40"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required
                   value="<?= e(post_str('korisnicko_ime', 40)) ?>">
        </div>
        <div class="red-polja">
            <label for="sifra">Šifra</label>
            <input class="polje" id="sifra" name="sifra" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit">Prijavi se</button>
    </form>
    <p class="link-ispod"><a href="<?= e(url('login.php')) ?>">← Prijava radnika</a></p>
<?php else: ?>
    <form method="post" id="forma-radnik" class="kartica" autocomplete="off">
        <?= csrf_polje() ?>
        <input type="hidden" name="tip" value="radnik">
        <h2>Ko si ti?</h2>
        <?php if (!$radnici): ?>
            <p class="pomoc">Još nema upisanih radnika. Administrator ih dodaje u kartici „Radnici“.</p>
        <?php else: ?>
            <div class="imena" role="radiogroup" aria-label="Izaberite svoje ime">
                <?php foreach ($radnici as $r): ?>
                    <label class="ime">
                        <input type="radio" name="radnik_id" value="<?= (int)$r['id'] ?>" required<?= $izabran === (int)$r['id'] ? ' checked' : '' ?>>
                        <span><?= e($r['ime']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label for="pin">PIN (4 cifre)</label>
            <div class="pin-prikaz" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            <input class="polje polje-pin" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}"
                   maxlength="4" autocomplete="off" required placeholder="••••">
            <div class="tastatura">
                <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $c): ?>
                    <button type="button" data-cifra="<?= $c ?>"><?= $c ?></button>
                <?php endforeach; ?>
                <button type="button" class="sporedno" data-akcija="ocisti">Očisti</button>
                <button type="button" data-cifra="0">0</button>
                <button type="button" class="sporedno" data-akcija="brisi" aria-label="Obriši poslednju cifru">⌫</button>
            </div>
            <button class="btn btn-primary btn-veliko btn-blok" type="submit">Prijavi se</button>
        <?php endif; ?>
    </form>
    <p class="link-ispod"><a href="<?= e(url('login.php?admin=1')) ?>">Prijava administratora</a></p>
<?php endif; ?>

<p class="link-ispod razmak-gore">
    <button type="button" class="btn btn-mali" data-instaliraj hidden>Dodaj na početni ekran</button>
</p>
<?php
ui_end();
