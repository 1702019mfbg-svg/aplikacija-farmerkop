<?php
/**
 * Obaveštenja na telefon (Web Push): pretplata telefona administratora, šifrovanje poruke (RFC 8291, aes128gcm),
 * potpis servera (VAPID, RFC 8292) i slanje servisu za obaveštenja (Google, Apple, Mozilla, Microsoft) preko
 * curl-a – bez dodatnih biblioteka. Dnevni zbir u zadati sat šalje cron.php (cPanel → Cron Jobs, jednom na sat).
 */
declare(strict_types=1);

/** Servisi za obaveštenja kojima se šalje (adresa pretplate mora biti na jednom od njih). */
const PUSH_SERVISI = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

function b64u(string $b): string
{
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function b64u_dekod(string $t): string
{
    return (string)base64_decode(strtr($t, '-_', '+/'), true);
}

/** Ključ EC P-256 (za VAPID i za šifrovanje svake poruke). */
function push_novi_kljuc(): OpenSSLAsymmetricKey
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($k === false) {
        throw new RuntimeException('OpenSSL ne može da napravi EC ključ (prime256v1).');
    }
    return $k;
}

/** Javni ključ kao nekompresovana tačka (65 bajtova: 0x04 || X || Y). */
function push_tacka(OpenSSLAsymmetricKey $k): string
{
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** Nekompresovana tačka (65 bajtova) → javni ključ za OpenSSL. */
function push_javni_kljuc(string $tacka): OpenSSLAsymmetricKey
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $tacka;
    $k = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    if ($k === false) {
        throw new RuntimeException('Neispravan ključ pretplate.');
    }
    return $k;
}

/** VAPID ključevi servera, prave se jednom i čuvaju u bazi: ['javni' => base64url tačke, 'privatni' => PEM]. */
function push_vapid(): array
{
    $v = json_decode((string)podesavanje('vapid'), true);
    if (is_array($v) && isset($v['javni'], $v['privatni'])) {
        return $v;
    }
    $k = push_novi_kljuc();
    openssl_pkey_export($k, $pem);
    // INSERT IGNORE: ako je drugi zahtev istovremeno upisao svoje ključeve, važe njegovi
    db_run('INSERT IGNORE INTO podesavanja (kljuc, vrednost) VALUES (?, ?)', ['vapid', json_encode(['javni' => b64u(push_tacka($k)), 'privatni' => $pem])]);
    return json_decode((string)podesavanje('vapid'), true);
}

/**
 * Šifrovana poruka za jedan telefon (RFC 8291 sa RFC 8188 aes128gcm, jedan zapis):
 * so (16) || veličina zapisa (4) || dužina ključa (1) || privremeni javni ključ (65) || šifrovano || oznaka (16).
 */
function push_sifruj(string $poruka, string $p256dh, string $auth): string
{
    $ua_javni = b64u_dekod($p256dh);
    $tajna = b64u_dekod($auth);
    $privremeni = push_novi_kljuc();
    $as_javni = push_tacka($privremeni);
    $ecdh = openssl_pkey_derive(push_javni_kljuc($ua_javni), $privremeni);
    if ($ecdh === false) {
        throw new RuntimeException('Šifrovanje obaveštenja nije uspelo (ECDH).');
    }
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua_javni . $as_javni, $tajna);
    $so = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $so);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $so);
    $tag = '';
    $sifrovano = openssl_encrypt($poruka . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $so . pack('N', 4096) . chr(strlen($as_javni)) . $as_javni . $sifrovano . $tag;
}

/** ECDSA potpis iz DER oblika (SEQUENCE { r, s }) u 64 bajta r || s, kako traži JWT (ES256). */
function push_der_u_rs(string $der): string
{
    $i = 2;
    $rs = '';
    for ($n = 0; $n < 2; $n++) {
        $duzina = ord($der[$i + 1]);
        $rs .= str_pad(ltrim(substr($der, $i + 2, $duzina), "\0"), 32, "\0", STR_PAD_LEFT);
        $i += 2 + $duzina;
    }
    return $rs;
}

/** Zaglavlje Authorization (VAPID): potpisan JWT za servis na adresi $endpoint i javni ključ servera. */
function push_vapid_zaglavlje(string $endpoint, string $kontakt): string
{
    $v = push_vapid();
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $podaci = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
        . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $kontakt], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($podaci, $der, $v['privatni'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Potpis obaveštenja nije uspeo.');
    }
    return 'vapid t=' . $podaci . '.' . b64u(push_der_u_rs($der)) . ', k=' . $v['javni'];
}

/** Da li je adresa pretplate na pravom servisu za obaveštenja (https). Za testove: PUSH_DOZVOLI_LOKALNO u config.php. */
function push_adresa_ispravna(string $endpoint): bool
{
    $u = parse_url($endpoint);
    if (!is_array($u) || empty($u['host']) || empty($u['scheme'])) {
        return false;
    }
    if (defined('PUSH_DOZVOLI_LOKALNO') && PUSH_DOZVOLI_LOKALNO && $u['host'] === '127.0.0.1') {
        return true;
    }
    if ($u['scheme'] !== 'https') {
        return false;
    }
    foreach (PUSH_SERVISI as $servis) {
        if ($u['host'] === $servis || str_ends_with($u['host'], '.' . $servis)) {
            return true;
        }
    }
    return false;
}

/** Kratak opis uređaja iz pregledača: "Android · Chrome", "iPhone · Safari". */
function push_uredjaj(string $ua): string
{
    $sistem = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'Mac',
        default => 'Uređaj',
    };
    $pregledac = match (true) {
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'Edg') => 'Edge',
        str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari') => 'Safari',
        default => 'pregledač',
    };
    return $sistem . ' · ' . $pregledac;
}

/** Čuva pretplatu telefona (isti telefon ponovo = osvežava se). Vraća grešku ili null. */
function push_pretplata_sacuvaj(int $korisnik_id, string $endpoint, string $p256dh, string $auth, string $uredjaj): ?string
{
    if (strlen($endpoint) > 1000 || !push_adresa_ispravna($endpoint)) {
        return 'Nepoznat servis za obaveštenja.';
    }
    $k = b64u_dekod($p256dh);
    if (strlen($k) !== 65 || $k[0] !== "\x04" || strlen(b64u_dekod($auth)) !== 16) {
        return 'Neispravni ključevi pretplate.';
    }
    db_run(
        'INSERT INTO push_pretplate (korisnik_id, endpoint, endpoint_hes, p256dh, auth, uredjaj, napravljen) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE korisnik_id = VALUES(korisnik_id), p256dh = VALUES(p256dh), auth = VALUES(auth), uredjaj = VALUES(uredjaj), greska = NULL',
        [$korisnik_id, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr($uredjaj, 0, 120), sada()]
    );
    return null;
}

/** Pretplate aktivnih administratora. */
function push_pretplate_admina(): array
{
    return db_all("SELECT p.* FROM push_pretplate p JOIN korisnici k ON k.id = p.korisnik_id WHERE k.uloga = 'admin' AND k.aktivan = 1 ORDER BY p.id");
}

/** Šalje poruku jednom telefonu. Vraća ['ok' => bool, 'kod' => HTTP status (0 = bez veze), 'greska' => ?string]. */
function push_posalji(array $pretplata, array $poruka): array
{
    $kontakt = podesavanje('adresa_aplikacije') ?? 'mailto:obavestenja@farmerkop.rs';
    try {
        $telo = push_sifruj(json_encode($poruka, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$pretplata['p256dh'], (string)$pretplata['auth']);
        $potpis = push_vapid_zaglavlje((string)$pretplata['endpoint'], $kontakt);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'kod' => 0, 'greska' => $e->getMessage()];
    }
    $ch = curl_init((string)$pretplata['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $telo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 43200',                     // telefon bez veze dobije poruku kad se poveže (do 12 sati)
            'Urgency: normal',
            'Topic: dnevnizbir',              // nova poruka zamenjuje staru koja još nije stigla
            'Authorization: ' . $potpis,
        ],
    ]);
    $odgovor = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $greska = $odgovor === false ? curl_error($ch) : null;
    if ($kod >= 200 && $kod < 300) {
        return ['ok' => true, 'kod' => $kod, 'greska' => null];
    }
    return ['ok' => false, 'kod' => $kod, 'greska' => $greska ?? ('servis je odgovorio ' . $kod . ($odgovor !== '' ? ': ' . mb_substr(trim(strip_tags((string)$odgovor)), 0, 120) : ''))];
}

/**
 * Šalje poruku svim telefonima administratora. Pretplata koju servis više ne poznaje (404/410) se briše.
 * Vraća ['poslato' => broj, 'greske' => [opis …]].
 */
function push_posalji_svima(array $pretplate, array $poruka): array
{
    $rez = ['poslato' => 0, 'greske' => []];
    foreach ($pretplate as $p) {
        $r = push_posalji($p, $poruka);
        if ($r['ok']) {
            $rez['poslato']++;
            db_run('UPDATE push_pretplate SET poslednje_slanje = ?, greska = NULL WHERE id = ?', [sada(), (int)$p['id']]);
        } elseif (in_array($r['kod'], [404, 410], true)) {
            db_run('DELETE FROM push_pretplate WHERE id = ?', [(int)$p['id']]);
            $rez['greske'][] = $p['uredjaj'] . ': telefon više nije prijavljen (uklonjen sa spiska)';
        } else {
            db_run('UPDATE push_pretplate SET greska = ? WHERE id = ?', [mb_substr((string)$r['greska'], 0, 255), (int)$p['id']]);
            $rez['greske'][] = $p['uredjaj'] . ': ' . $r['greska'];
        }
    }
    return $rez;
}

/** Tajni ključ za pokretanje cron.php preko adrese (pravi se jednom). */
function push_cron_kljuc(): string
{
    db_run('INSERT IGNORE INTO podesavanja (kljuc, vrednost) VALUES (?, ?)', ['cron_kljuc', bin2hex(random_bytes(16))]);
    return (string)podesavanje('cron_kljuc');
}

/**
 * Dnevni zbir na telefon (cron.php, jednom na sat): posle zadatog sata, jednom dnevno, ako je danas bilo nečega.
 * Vraća rečenicu o tome šta je urađeno (za ispis).
 */
function dnevni_zbir_posalji_ako_treba(): string
{
    podesavanje_upisi('cron_poslednji', sada());
    $sat = (int)podesavanje('obavestenje_sat', '20');
    $dan = date('Y-m-d');
    if ((int)date('G') < $sat) {
        return 'Još nije ' . $sat . 'h – dnevni zbir se šalje posle ' . $sat . 'h.';
    }
    if (podesavanje('dnevni_zbir_poslat') === $dan) {
        return 'Današnji zbir je već poslat.';
    }
    if ((int)db_val("SELECT GET_LOCK('farmerkop_zbir', 0)") !== 1) {
        return 'Slanje je već u toku.';
    }
    try {
        $pretplate = push_pretplate_admina();
        if (!$pretplate) {
            return 'Nijedan telefon nije prijavljen za obaveštenja.';
        }
        $z = danas_zbir($dan);
        if (!$z['artikli']) {
            return 'Danas nije bilo ni proizvodnje ni robe koja je otišla – ništa se ne šalje.';
        }
        $r = push_posalji_svima($pretplate, danas_obavestenje($z));
        if ($r['poslato'] > 0) {
            podesavanje_upisi('dnevni_zbir_poslat', $dan);
        }
        return 'Dnevni zbir poslat na ' . $r['poslato'] . ' od ' . count($pretplate) . ($r['greske'] ? '. Greške: ' . implode('; ', $r['greske']) : '.');
    } finally {
        db_run("DO RELEASE_LOCK('farmerkop_zbir')");
    }
}
