<?php
/**
 * Prijava, odjava, uloge, zaključavanje posle pogrešnih pokušaja.
 *
 * - Radnik: bira svoje ime i unosi PIN od 4 cifre.
 * - Administrator: korisničko ime i šifra.
 * - Šifre i PIN-ovi se čuvaju samo kao heš (password_hash), nikad kao tekst.
 */
declare(strict_types=1);

/** Heš koji se proverava kad korisnik ne postoji, da vreme odgovora ne otkrije da li nalog postoji. */
const LAZNI_HES = '$2y$10$HxyRwtP9jpl/z7sR7DO6/e0Oyy5td.R4F32w7AjmX0N7GHtc0I23q';

/** Najviše pogrešnih pokušaja sa jedne IP adrese u 15 minuta. */
const IP_MAX_NEUSPEHA = 30;

function je_pin(string $pin): bool
{
    return (bool)preg_match('/^[0-9]{4}$/', $pin);
}

/** Prijavljeni korisnik ili null. Proverava istek sesije i da li je nalog još uključen. */
function auth_user(): ?array
{
    static $kes = false;
    if ($kes !== false) {
        return $kes;
    }
    sesija_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        return $kes = null;
    }

    $minuta = (($_SESSION['uloga'] ?? '') === 'admin') ? SESIJA_ADMIN_MIN : SESIJA_RADNIK_MIN;
    $dozvoljeno = (int)round($minuta * 60);
    if (time() - (int)($_SESSION['poslednje'] ?? 0) > $dozvoljeno) {
        ocisti_sesiju();
        flash_dodaj('info', 'Odjavljeni ste zbog neaktivnosti. Prijavite se ponovo.');
        return $kes = null;
    }

    $u = db_one('SELECT id, uloga, ime, korisnicko_ime, aktivan FROM korisnici WHERE id = ?', [$uid]);
    if ($u === null || !$u['aktivan'] || $u['uloga'] !== ($_SESSION['uloga'] ?? '')) {
        ocisti_sesiju();
        return $kes = null;
    }

    $_SESSION['poslednje'] = time();
    return $kes = $u;
}

/** Briše podatke sesije i menja njen identifikator (sesija ostaje otvorena da bi mogla da se prikaže poruka). */
function ocisti_sesiju(): void
{
    sesija_start();
    $_SESSION = [];
    session_regenerate_id(true);
}

function odjavi(): void
{
    sesija_start();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pocetna_za(array $korisnik): string
{
    return $korisnik['uloga'] === 'admin' ? 'admin/stanje.php' : 'radnik/index.php';
}

/** Zahteva prijavu (i, opciono, ulogu). Inače vodi na prijavu. */
function zahtevaj_ulogu(?string $uloga = null): array
{
    $u = auth_user();
    if ($u === null) {
        preusmeri('login.php');
    }
    if ($uloga !== null && $u['uloga'] !== $uloga) {
        preusmeri(pocetna_za($u));
    }
    return $u;
}

// ─── Prijava ───────────────────────────────────────────────────────────────

function ip_blokiran(): bool
{
    $n = (int)db_val(
        'SELECT COUNT(*) FROM neuspele_prijave WHERE ip = ? AND vreme > ?',
        [ip_adresa(), date('Y-m-d H:i:s', time() - 900)]
    );
    return $n >= IP_MAX_NEUSPEHA;
}

function ip_zabelezi_neuspeh(): void
{
    db_run('INSERT INTO neuspele_prijave (ip, vreme) VALUES (?, ?)', [ip_adresa(), sada()]);
    if (random_int(1, 50) === 1) {
        db_run('DELETE FROM neuspele_prijave WHERE vreme < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

/** Trajanje zaključavanja: prvo ZAKLJUCAVANJE_MIN, svaki sledeći krug duplo duže (najviše 12 h). */
function trajanje_zakljucavanja_min(int $neuspesni): int
{
    $krug = intdiv($neuspesni, MAX_POKUSAJA);
    return (int)min(ZAKLJUCAVANJE_MIN * (2 ** max(0, $krug - 1)), 720);
}

function prijava_radnik(int $id, string $pin): array
{
    if (ip_blokiran()) {
        return ['ok' => false, 'poruka' => 'Previše pogrešnih pokušaja sa ovog uređaja. Sačekajte nekoliko minuta.'];
    }
    if ($id <= 0) {
        return ['ok' => false, 'poruka' => 'Izaberite svoje ime sa liste.'];
    }
    if (!je_pin($pin)) {
        return ['ok' => false, 'poruka' => 'PIN ima tačno 4 cifre.'];
    }
    $u = db_one("SELECT * FROM korisnici WHERE id = ? AND uloga = 'radnik'", [$id]);
    return prijava_proveri($u, $pin, 'Pogrešan PIN.', true);
}

function prijava_admin(string $korisnicko, string $sifra): array
{
    if (ip_blokiran()) {
        return ['ok' => false, 'poruka' => 'Previše pogrešnih pokušaja sa ovog uređaja. Sačekajte nekoliko minuta.'];
    }
    if ($korisnicko === '' || $sifra === '') {
        return ['ok' => false, 'poruka' => 'Upišite korisničko ime i šifru.'];
    }
    $u = db_one("SELECT * FROM korisnici WHERE korisnicko_ime = ? AND uloga = 'admin'", [$korisnicko]);
    return prijava_proveri($u, $sifra, 'Pogrešno korisničko ime ili šifra.', false);
}

function prijava_proveri(?array $u, string $tajna, string $poruka_greske, bool $pokazi_preostalo): array
{
    if ($u === null || !$u['aktivan']) {
        password_verify($tajna, LAZNI_HES);
        ip_zabelezi_neuspeh();
        return ['ok' => false, 'poruka' => $u === null ? $poruka_greske : 'Vaš pristup je isključen. Javite se administratoru.'];
    }

    if ($u['zakljucan_do'] !== null && strtotime((string)$u['zakljucan_do']) > time()) {
        return ['ok' => false, 'poruka' => 'Nalog je privremeno zaključan zbog više pogrešnih pokušaja. Pokušajte ponovo posle '
            . date('H:i', strtotime((string)$u['zakljucan_do'])) . '.'];
    }

    if (password_verify($tajna, (string)$u['hes'])) {
        db_run('UPDATE korisnici SET neuspesni_pokusaji = 0, zakljucan_do = NULL, poslednja_prijava = ? WHERE id = ?', [sada(), $u['id']]);
        if (password_needs_rehash((string)$u['hes'], PASSWORD_DEFAULT)) {
            db_run('UPDATE korisnici SET hes = ? WHERE id = ?', [password_hash($tajna, PASSWORD_DEFAULT), $u['id']]);
        }
        prijavi_korisnika($u);
        return ['ok' => true, 'poruka' => '', 'korisnik' => $u];
    }

    $n = (int)$u['neuspesni_pokusaji'] + 1;
    $zakljucaj_do = null;
    if ($n % MAX_POKUSAJA === 0) {
        $zakljucaj_do = date('Y-m-d H:i:s', time() + trajanje_zakljucavanja_min($n) * 60);
    }
    db_run('UPDATE korisnici SET neuspesni_pokusaji = ?, zakljucan_do = ? WHERE id = ?', [$n, $zakljucaj_do, $u['id']]);
    ip_zabelezi_neuspeh();

    if ($zakljucaj_do !== null) {
        return ['ok' => false, 'poruka' => 'Previše pogrešnih pokušaja. Nalog je zaključan na ' . trajanje_zakljucavanja_min($n) . ' min.'];
    }
    $ostalo = MAX_POKUSAJA - ($n % MAX_POKUSAJA);
    return ['ok' => false, 'poruka' => $pokazi_preostalo ? $poruka_greske . ' Preostalo pokušaja: ' . $ostalo . '.' : $poruka_greske];
}

function prijavi_korisnika(array $u): void
{
    sesija_start();
    session_regenerate_id(true);
    $_SESSION = [
        'uid'       => (int)$u['id'],
        'uloga'     => (string)$u['uloga'],
        'poslednje' => time(),
    ];
}

function napravi_hes(string $tajna): string
{
    return password_hash($tajna, PASSWORD_DEFAULT);
}

/** Dnevnik izmena: ko je i kad šta promenio. */
function dnevnik_upis(string $akcija, string $objekat, ?int $objekat_id, mixed $detalji = null): void
{
    $u = auth_user();
    db_run(
        'INSERT INTO dnevnik (korisnik_id, vreme, akcija, objekat, objekat_id, detalji) VALUES (?, ?, ?, ?, ?, ?)',
        [
            $u['id'] ?? null,
            sada(),
            $akcija,
            $objekat,
            $objekat_id,
            $detalji === null ? null : (is_string($detalji) ? $detalji : json_encode($detalji, JSON_UNESCAPED_UNICODE)),
        ]
    );
}
