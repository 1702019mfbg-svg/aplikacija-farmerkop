<?php
/**
 * Instalacija: pravi tabele u bazi, ubacuje početni katalog i pravi prvi administratorski nalog.
 * Posle uspešne instalacije fajl se sam briše (ako ne uspe, obrišite ga ručno u File Manager-u).
 */
declare(strict_types=1);

define('INSTALL_RUN', true);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/schema.php';

/** Prijateljsko objašnjenje greške pri povezivanju sa bazom. */
function install_objasni_vezu(PDOException $e): string
{
    $kod = 0;
    if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
        $kod = (int)$m[1];
    }
    return match ($kod) {
        1045    => 'Baza je odbila prijavu: pogrešno DB_USER ili DB_PASS u config.php.',
        1049    => 'Baza sa imenom iz DB_NAME ne postoji. Proverite ime baze u cPanel-u (uključujući prefiks).',
        1044    => 'Korisnik baze nema pravo nad ovom bazom. U cPanel-u uradite "Add User To Database" i izaberite ALL PRIVILEGES.',
        2002, 2006 => 'Server baze nije dostupan. Proverite DB_HOST u config.php (na cPanel-u je obično "localhost").',
        default => 'Ne mogu da se povežem sa bazom (šifra greške ' . ($kod ?: 'nepoznata') . '). Proverite podatke u config.php.',
    };
}

function install_tabela_postoji(string $ime): bool
{
    return (int)db_val(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$ime]
    ) > 0;
}

$problemi = [];
$stanje = 'forma';        // forma | gotovo | vec_instalirano | podesi
$obrisan_installer = false;
$greske_forme = [];

// 1) Da li je config.php popunjen?
$kljuc_dugacak = strlen((string)INSTALL_KLJUC) >= 8 && INSTALL_KLJUC !== 'PROMENI_OVO';
if (str_starts_with(DB_NAME, 'UPISITE') || str_starts_with(DB_USER, 'UPISITE') || str_starts_with(DB_PASS, 'UPISITE')) {
    $problemi[] = 'U fajlu config.php još nisu upisani podaci o bazi (DB_NAME, DB_USER, DB_PASS).';
}
if (!$kljuc_dugacak) {
    $problemi[] = 'U fajlu config.php promenite INSTALL_KLJUC u bilo koju svoju reč ili broj od najmanje 8 znakova.';
}

// 2) Da li baza radi?
if (!$problemi) {
    try {
        db();
    } catch (PDOException $e) {
        $problemi[] = install_objasni_vezu($e);
    }
}

// 3) Da li je već instalirano?
if (!$problemi) {
    if (install_tabela_postoji('korisnici') && (int)db_val("SELECT COUNT(*) FROM korisnici WHERE uloga = 'admin'") > 0) {
        $stanje = 'vec_instalirano';
    }
}

$ulaz = ['korisnicko_ime' => 'admin', 'ime' => 'Administrator', 'katalog' => true];

if (!$problemi && $stanje === 'forma' && je_post()) {
    csrf_proveri();
    $ulaz['korisnicko_ime'] = post_str('korisnicko_ime', 40);
    $ulaz['ime'] = post_str('ime', 60);
    $ulaz['katalog'] = isset($_POST['katalog']);
    $sifra = (string)($_POST['sifra'] ?? '');
    $sifra2 = (string)($_POST['sifra2'] ?? '');

    if (!hash_equals((string)INSTALL_KLJUC, (string)($_POST['kljuc'] ?? ''))) {
        $greske_forme[] = 'Instalacioni ključ nije tačan. Upišite tačno ono što piše pod INSTALL_KLJUC u config.php.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $ulaz['korisnicko_ime'])) {
        $greske_forme[] = 'Korisničko ime može imati 3–40 znakova: slova bez kvačica, cifre, tačku, crtu.';
    }
    if ($ulaz['ime'] === '') {
        $greske_forme[] = 'Upišite ime za prikaz (npr. Administrator).';
    }
    if (mb_strlen($sifra) < MIN_DUZINA_SIFRE) {
        $greske_forme[] = 'Šifra mora imati najmanje ' . MIN_DUZINA_SIFRE . ' znakova.';
    }
    if ($sifra !== $sifra2) {
        $greske_forme[] = 'Dve šifre se ne poklapaju.';
    }

    if (!$greske_forme) {
        try {
            foreach (schema_sql() as $sql) {
                db()->exec($sql);
            }
            if ((int)db_val('SELECT COUNT(*) FROM kategorije') === 0 && $ulaz['katalog']) {
                db_trans(static function (): void {
                    ubaci_pocetni_katalog();
                });
            }
            db_run(
                "INSERT INTO korisnici (uloga, ime, korisnicko_ime, hes, aktivan, napravljen) VALUES ('admin', ?, ?, ?, 1, ?)",
                [$ulaz['ime'], $ulaz['korisnicko_ime'], napravi_hes($sifra), sada()]
            );
            db_run('REPLACE INTO podesavanja (kljuc, vrednost) VALUES (?, ?)', ['schema_verzija', (string)SCHEMA_VERZIJA]);
            db_run('REPLACE INTO podesavanja (kljuc, vrednost) VALUES (?, ?)', ['instalirano', sada()]);
            $stanje = 'gotovo';
            $obrisan_installer = @unlink(__FILE__);
        } catch (PDOException $e) {
            error_log('[Farmerkop install] ' . $e->getMessage());
            $greske_forme[] = 'Instalacija nije uspela: ' . (DEBUG ? $e->getMessage() : 'greška u bazi. Proverite da korisnik baze ima sva prava (ALL PRIVILEGES).');
        }
    }
}

ui_start('Instalacija', ['bez_zaglavlja' => true, 'telo' => 'telo-prijava']);
?>
<div class="prijava-logo">
    <img src="<?= e(asset('assets/icons/icon.svg')) ?>" alt="" width="84" height="84">
    <h1><?= e(APP_NAZIV) ?></h1>
    <p>Instalacija aplikacije</p>
</div>

<?php if ($problemi): ?>
    <div class="kartica">
        <h2>Pre instalacije</h2>
        <?php foreach ($problemi as $p): ?>
            <div class="poruka poruka-upozorenje"><?= e($p) ?></div>
        <?php endforeach; ?>
        <p class="pomoc">Popravite pa osvežite ovu stranicu. Uputstvo je u fajlu UPUTSTVO.md.</p>
    </div>

<?php elseif ($stanje === 'vec_instalirano'): ?>
    <div class="kartica">
        <h2>Već je instalirano</h2>
        <p>Aplikacija je već instalirana. Zbog bezbednosti <strong>obrišite fajl install.php</strong> sa servera (cPanel → File Manager).</p>
        <p><a class="btn btn-primary btn-blok" href="<?= e(url('login.php')) ?>">Idi na prijavu</a></p>
    </div>

<?php elseif ($stanje === 'gotovo'): ?>
    <div class="kartica">
        <div class="poruka poruka-uspeh">Instalacija je uspešno završena.</div>
        <?php if ($obrisan_installer): ?>
            <p>Fajl install.php je automatski obrisan sa servera.</p>
        <?php else: ?>
            <div class="poruka poruka-upozorenje">Fajl install.php nije mogao da se obriše sam. <strong>Obrišite ga ručno</strong> (cPanel → File Manager), da niko drugi ne bi mogao da ga otvori.</div>
        <?php endif; ?>
        <p>Prijavite se kao administrator, pa u kartici „Radnici“ dodajte radnike.</p>
        <p><a class="btn btn-primary btn-veliko btn-blok" href="<?= e(url('login.php?admin=1')) ?>">Prijava administratora</a></p>
    </div>

<?php else: ?>
    <?php foreach ($greske_forme as $g): ?>
        <div class="poruka poruka-greska" role="alert"><?= e($g) ?></div>
    <?php endforeach; ?>
    <div class="poruka poruka-uspeh">Veza sa bazom radi.</div>
    <form method="post" class="kartica" autocomplete="off">
        <?= csrf_polje() ?>
        <h2>Prvi administratorski nalog</h2>
        <div class="red-polja">
            <label for="kljuc">Instalacioni ključ (iz config.php)</label>
            <input class="polje" id="kljuc" name="kljuc" type="password" required autocomplete="off">
        </div>
        <div class="red-polja">
            <label for="ime">Ime za prikaz</label>
            <input class="polje" id="ime" name="ime" type="text" maxlength="60" required value="<?= e($ulaz['ime']) ?>">
        </div>
        <div class="red-polja">
            <label for="korisnicko_ime">Korisničko ime</label>
            <input class="polje" id="korisnicko_ime" name="korisnicko_ime" type="text" maxlength="40" required
                   autocapitalize="none" spellcheck="false" value="<?= e($ulaz['korisnicko_ime']) ?>">
        </div>
        <div class="red-polja">
            <label for="sifra">Šifra (najmanje <?= (int)MIN_DUZINA_SIFRE ?> znakova)</label>
            <input class="polje" id="sifra" name="sifra" type="password" required autocomplete="new-password" minlength="<?= (int)MIN_DUZINA_SIFRE ?>">
        </div>
        <div class="red-polja">
            <label for="sifra2">Šifra još jednom</label>
            <input class="polje" id="sifra2" name="sifra2" type="password" required autocomplete="new-password">
        </div>
        <div class="red-polja">
            <label><input type="checkbox" name="katalog" value="1"<?= $ulaz['katalog'] ? ' checked' : '' ?>>
                Ubaci početni katalog (Humovit, Idea, malč, oblutak…)</label>
            <p class="pomoc">Sve se kasnije može menjati u Podešavanjima.</p>
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit">Instaliraj</button>
    </form>
<?php endif; ?>
<?php
ui_end();
