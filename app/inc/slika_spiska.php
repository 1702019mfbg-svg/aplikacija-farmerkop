<?php
/**
 * Slikaj spisak: fotografija rukom pisanog spiska → AI servis (Anthropic Messages API, Claude) prepiše rukopis
 * u tekst u obliku koji razume inc/spisak_papir.php ("8 Agrocentar", "2 pal humovit 50", "a + b") →
 * isti pregled kao za otkucan spisak, pa administrator proveri i sačuva.
 *
 * Ključ (ANTHROPIC_API_KEY) je samo u config.php na serveru. Slika se ne čuva na disku.
 * Poziv je običan HTTPS zahtev (PHP curl), bez dodatnih biblioteka – aplikacija se postavlja ZIP-om na cPanel.
 */
declare(strict_types=1);

const SLIKA_MAX_STRANA = 2000;          // duža strana slike koja se šalje (piksela) – dovoljno za rukopis
const SLIKA_MAX_BAJTOVA = 4500000;      // servis prima do 5 MB po slici
const SLIKA_TIPOVI = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];

/** Da li je čitanje slike uključeno (upisan ključ i PHP ima curl). */
function slika_ukljucena(): bool
{
    return ANTHROPIC_API_KEY !== '' && function_exists('curl_init');
}

/**
 * Slika iz forme (polje "slika"): provera tipa i veličine; prevelika se smanji (ako hosting ima GD).
 * Vraća ['mime' => ..., 'bajtovi' => ...] ili ['greska' => tekst].
 */
function slika_iz_posta(): array
{
    $f = $_FILES['slika'] ?? null;
    if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
        return ['greska' => 'Izaberite ili slikajte spisak.'];
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['greska' => 'Slika je prevelika za hosting (najviše ' . ini_get('upload_max_filesize') . '). Slikajte ponovo ili smanjite sliku.'];
    }
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return ['greska' => 'Izaberite ili slikajte spisak.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        return ['greska' => 'Slika nije stigla do servera – pokušajte ponovo.'];
    }
    $info = @getimagesize((string)$f['tmp_name']);
    if ($info === false || !isset(SLIKA_TIPOVI[$info[2]])) {
        return ['greska' => 'To nije slika (JPG, PNG ili WEBP). Slikajte spisak telefonom.'];
    }
    $bajtovi = (string)file_get_contents((string)$f['tmp_name']);
    $mime = SLIKA_TIPOVI[$info[2]];
    if (max($info[0], $info[1]) > SLIKA_MAX_STRANA || strlen($bajtovi) > SLIKA_MAX_BAJTOVA) {
        $manja = slika_smanji($bajtovi, (int)$info[0], (int)$info[1]);
        if ($manja !== null) {
            [$bajtovi, $mime] = [$manja, 'image/jpeg'];
        }
    }
    if (strlen($bajtovi) > SLIKA_MAX_BAJTOVA) {
        return ['greska' => 'Slika je prevelika (najviše 4,5 MB). Slikajte ponovo, malo dalje od papira.'];
    }
    return ['mime' => $mime, 'bajtovi' => $bajtovi];
}

/** Smanjuje sliku na SLIKA_MAX_STRANA (duža strana), JPEG. Null ako hosting nema GD ili slika ne može da se otvori. */
function slika_smanji(string $bajtovi, int $sirina, int $visina): ?string
{
    if (!function_exists('imagecreatefromstring') || $sirina < 1 || $visina < 1) {
        return null;
    }
    $izvor = @imagecreatefromstring($bajtovi);
    if ($izvor === false) {
        return null;
    }
    $k = min(1, SLIKA_MAX_STRANA / max($sirina, $visina));
    $s = max(1, (int)round($sirina * $k));
    $v = max(1, (int)round($visina * $k));
    $nova = imagecreatetruecolor($s, $v);
    imagecopyresampled($nova, $izvor, 0, 0, 0, 0, $s, $v, $sirina, $visina);
    ob_start();
    imagejpeg($nova, null, 85);
    return (string)ob_get_clean();
}

/** Uputstvo servisu: kako da prepiše spisak (oblik koji čita papir_procitaj) i katalog za pravilne nazive. */
function slika_uputstvo(array $nazivi): string
{
    return "You transcribe a photographed, handwritten loading list for Farmerkop, a Serbian producer of potting soil, "
        . "mulch and decorative stones. The paper is in Serbian (Latin or Cyrillic script). Output ONLY the list as plain text "
        . "in Serbian Latin script, one entry per line, in the same order as on the paper - no commentary, no headings, no markdown.\n\n"
        . "Format rules:\n"
        . "- A delivery stop (usually a circled number, or a number standing alone on its line) is its own line: the number, then the "
        . "customer name if one is written next to it, e.g. `8 Agrocentar`. An address written under it stays on its own line.\n"
        . "- Each product line is the quantity, then the product, e.g. `2 pal humovit 50`, `3 pak idea 5`, `30 kom humovit 10`, "
        . "`1/2 rizla bela sitna`. Keep units exactly as written (pal = pallets, pak = packages, kom = pieces, t = tons). "
        . "If a number has no unit on the paper, write only the number - never add a unit.\n"
        . "- Several products on one pallet (joined with +, or grouped with a bracket) go on one line joined with ` + `, "
        . "e.g. `1/2 rizla bela sitna + 1/2 oblutak beli 40-60`.\n"
        . "- When the handwriting clearly means a product from the catalog below, write it with the catalog words (keep the size "
        . "such as 5, 10, 20, 25 or 50 l, and the granulation such as 4-6). If you are not sure, write what you see.\n"
        . "- If a word or number cannot be read, write `??` in its place. Never guess a quantity.\n"
        . "- Leave out crossed-out text, dates, signatures and doodles.\n\n"
        . "Catalog:\n" . implode("\n", $nazivi);
}

/**
 * Šalje sliku servisu i vraća prepisan tekst: ['tekst' => string] ili ['greska' => tekst za korisnika].
 * $nazivi: nazivi artikala iz kataloga ("Humovit · 50 l" …), da bi prepis koristio prave nazive.
 */
function slika_procitaj(string $bajtovi, string $mime, array $nazivi): array
{
    if (!slika_ukljucena()) {
        return ['greska' => 'Čitanje slike nije uključeno: u config.php upišite ključ (ANTHROPIC_API_KEY).'];
    }
    $telo = [
        'model'         => ANTHROPIC_MODEL,
        'max_tokens'    => 8000,
        'output_config' => ['effort' => 'medium'],
        'fallbacks'     => 'default',                  // ako servis odbije, sam pokuša drugim modelom
        'system'        => slika_uputstvo($nazivi),
        'messages'      => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bajtovi)]],
                ['type' => 'text', 'text' => 'Prepiši ovaj spisak za utovar.'],
            ],
        ]],
    ];
    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($telo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $odgovor = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mreza = curl_error($ch);
    curl_close($ch);

    if ($odgovor === false) {
        error_log('[Farmerkop] slika spiska: veza nije uspela: ' . $mreza);
        return ['greska' => 'Server nije uspeo da se poveže sa servisom za čitanje slike. Pokušajte ponovo za minut.'];
    }
    $j = json_decode((string)$odgovor, true);
    if ($kod !== 200 || !is_array($j)) {
        error_log('[Farmerkop] slika spiska: HTTP ' . $kod . ': ' . mb_substr((string)$odgovor, 0, 500));
        return ['greska' => match (true) {
            $kod === 401, $kod === 403 => 'Ključ za čitanje slike nije ispravan – proverite ANTHROPIC_API_KEY u config.php.',
            $kod === 429, $kod >= 500  => 'Servis za čitanje slike je trenutno zauzet. Pokušajte ponovo za minut.',
            $kod === 413               => 'Slika je prevelika za servis. Slikajte ponovo, malo dalje od papira.',
            default                    => 'Servis nije prihvatio sliku (greška ' . $kod . '). Pokušajte drugu sliku ili otkucajte spisak.',
        }];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') {
        return ['greska' => 'Servis nije pročitao ovu sliku. Slikajte ponovo ili otkucajte spisak.'];
    }
    $tekst = '';
    foreach (is_array($j['content'] ?? null) ? $j['content'] : [] as $blok) {
        if (is_array($blok) && ($blok['type'] ?? '') === 'text' && is_string($blok['text'] ?? null)) {
            $tekst .= $blok['text'];
        }
    }
    $tekst = trim(str_replace(["\r\n", '```'], ["\n", ''], $tekst));
    if ($tekst === '') {
        return ['greska' => 'Na slici nije pročitan nijedan red. Slikajte ceo papir, oštro i sa dovoljno svetla.'];
    }
    return ['tekst' => mb_substr($tekst, 0, PAPIR_MAX_TEKST)];
}
