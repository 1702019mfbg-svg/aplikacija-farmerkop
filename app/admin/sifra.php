<?php
/**
 * Administrator – Podešavanja → promena administratorske šifre.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';

$admin = zahtevaj_ulogu('admin');

if (je_post()) {
    csrf_proveri();
    $trenutna = (string)($_POST['trenutna'] ?? '');
    $nova = (string)($_POST['nova'] ?? '');
    $potvrda = (string)($_POST['potvrda'] ?? '');
    $hes = (string)db_val('SELECT hes FROM korisnici WHERE id = ?', [(int)$admin['id']]);

    if (!password_verify($trenutna, $hes)) {
        $n = (int)($_SESSION['sifra_pokusaji'] ?? 0) + 1;
        if ($n >= 5) {
            odjavi();
            sesija_start();
            flash_dodaj('greska', 'Previše pogrešnih pokušaja. Prijavite se ponovo.');
            preusmeri('login.php?admin=1');
        }
        $_SESSION['sifra_pokusaji'] = $n;
        flash_dodaj('greska', 'Trenutna šifra nije tačna. Preostalo pokušaja: ' . (5 - $n) . '.');
    } elseif (mb_strlen($nova) < MIN_DUZINA_SIFRE) {
        flash_dodaj('greska', 'Nova šifra mora imati najmanje ' . MIN_DUZINA_SIFRE . ' znakova.');
    } elseif ($nova !== $potvrda) {
        flash_dodaj('greska', 'Nova šifra i potvrda se ne poklapaju.');
    } elseif ($nova === $trenutna) {
        flash_dodaj('greska', 'Nova šifra mora biti drugačija od trenutne.');
    } elseif (mb_strtolower($nova) === mb_strtolower((string)$admin['korisnicko_ime'])) {
        flash_dodaj('greska', 'Šifra ne sme biti isto što i korisničko ime.');
    } else {
        db_run('UPDATE korisnici SET hes = ?, neuspesni_pokusaji = 0, zakljucan_do = NULL WHERE id = ?', [napravi_hes($nova), (int)$admin['id']]);
        sesije_obrisi_korisnika((int)$admin['id'], session_id());
        session_regenerate_id(true);
        unset($_SESSION['sifra_pokusaji']);
        dnevnik_podesavanje('Promenjena administratorska šifra', 'korisnik', (int)$admin['id']);
        flash_dodaj('uspeh', 'Šifra je promenjena. Na ostalim uređajima ste odjavljeni.');
        preusmeri('admin/podesavanja.php');
    }
    preusmeri('admin/sifra.php');
}

ui_start('Promena šifre', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a></p>
<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/sifra.php')) ?>">
    <?= csrf_polje() ?>
    <div class="kartica-naslov"><h2>Nova administratorska šifra</h2></div>
    <input type="text" name="korisnicko" value="<?= e($admin['korisnicko_ime'] ?? '') ?>" autocomplete="username" hidden readonly>
    <div class="red-polja">
        <label for="trenutna">Trenutna šifra</label>
        <input class="polje" id="trenutna" name="trenutna" type="password" required autocomplete="current-password">
    </div>
    <div class="red-polja">
        <label for="nova">Nova šifra (najmanje <?= (int)MIN_DUZINA_SIFRE ?> znakova)</label>
        <input class="polje" id="nova" name="nova" type="password" required minlength="<?= (int)MIN_DUZINA_SIFRE ?>" autocomplete="new-password">
    </div>
    <div class="red-polja">
        <label for="potvrda">Nova šifra još jednom</label>
        <input class="polje" id="potvrda" name="potvrda" type="password" required autocomplete="new-password">
    </div>
    <button class="btn btn-primary btn-veliko btn-blok" type="submit">Promeni šifru</button>
</form>
<?php
ui_end();
