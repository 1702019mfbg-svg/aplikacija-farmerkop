<?php
/**
 * Pravi ikone aplikacije (vreća sa klicom) – SVG i PNG – u app/assets/icons/.
 * Pokretanje: php tools/napravi_ikone.php
 *
 * Isti oblici se crtaju za SVG i za PNG, pa su ikone uvek iste.
 */
declare(strict_types=1);

$izlaz = __DIR__ . '/../app/assets/icons';
if (!is_dir($izlaz)) {
    mkdir($izlaz, 0755, true);
}

const BRAON = '#5c3d2e';
const KRAFT = '#d8b57a';
const KRAFT_TAMNO = '#c39a58';
const ETIKETA = '#f3e6c8';
const LIST_1 = '#8db557';
const LIST_2 = '#a6cb70';
const LIST_TAMNO = '#4a6b2a';

/** Zaobljeni pravougaonik kao niz komandi putanje. */
function zaobljen(float $x, float $y, float $w, float $h, float $r): array
{
    return [
        ['M', $x + $r, $y], ['L', $x + $w - $r, $y], ['Q', $x + $w, $y, $x + $w, $y + $r],
        ['L', $x + $w, $y + $h - $r], ['Q', $x + $w, $y + $h, $x + $w - $r, $y + $h],
        ['L', $x + $r, $y + $h], ['Q', $x, $y + $h, $x, $y + $h - $r],
        ['L', $x, $y + $r], ['Q', $x, $y, $x + $r, $y], ['Z'],
    ];
}

/** Oblici ikone (u prostoru 512×512), od pozadine ka vrhu. */
function oblici(): array
{
    return [
        ['boja' => KRAFT, 'putanja' => [
            ['M', 148, 196], ['L', 364, 196], ['L', 380, 410], ['Q', 381, 428, 363, 428],
            ['L', 149, 428], ['Q', 131, 428, 132, 410], ['Z'],
        ]],
        ['boja' => KRAFT_TAMNO, 'putanja' => [['M', 148, 196], ['L', 364, 196], ['L', 366, 244], ['L', 146, 244], ['Z']]],
        ['boja' => ETIKETA, 'putanja' => zaobljen(200, 292, 112, 90, 16)],
        ['boja' => LIST_TAMNO, 'putanja' => [['M', 226, 360], ['Q', 224, 316, 292, 314], ['Q', 296, 358, 226, 360], ['Z']]],
        // stabljika i listovi
        ['boja' => LIST_1, 'putanja' => [['M', 249, 204], ['L', 249, 126], ['Q', 249, 119, 256, 119], ['Q', 263, 119, 263, 126], ['L', 263, 204], ['Z']]],
        ['boja' => LIST_1, 'putanja' => [['M', 256, 156], ['Q', 196, 166, 184, 98], ['Q', 246, 94, 256, 156], ['Z']]],
        ['boja' => LIST_2, 'putanja' => [['M', 256, 136], ['Q', 320, 146, 336, 80], ['Q', 270, 74, 256, 136], ['Z']]],
    ];
}

function putanja_svg(array $komande): string
{
    $d = '';
    foreach ($komande as $k) {
        $d .= $k[0] . ($k[0] === 'Z' ? '' : implode(' ', array_slice($k, 1))) . ' ';
    }
    return trim($d);
}

/** Pretvara putanju u spisak tačaka (krive se uzorkuju). */
function putanja_tacke(array $komande, float $skala, float $razmera): array
{
    $tacke = [];
    $tx = 0.0;
    $ty = 0.0;
    $map = static fn(float $x, float $y): array => [(($x - 256) * $skala + 256) * $razmera, (($y - 256) * $skala + 256) * $razmera];
    foreach ($komande as $k) {
        if ($k[0] === 'M' || $k[0] === 'L') {
            [$tx, $ty] = [$k[1], $k[2]];
            $tacke[] = $map($tx, $ty);
        } elseif ($k[0] === 'Q') {
            [$cx, $cy, $x, $y] = [$k[1], $k[2], $k[3], $k[4]];
            for ($i = 1; $i <= 24; $i++) {
                $t = $i / 24;
                $bx = (1 - $t) ** 2 * $tx + 2 * (1 - $t) * $t * $cx + $t ** 2 * $x;
                $by = (1 - $t) ** 2 * $ty + 2 * (1 - $t) * $t * $cy + $t ** 2 * $y;
                $tacke[] = $map($bx, $by);
            }
            [$tx, $ty] = [$x, $y];
        }
    }
    return $tacke;
}

function hex_boja(GdImage $im, string $hex): int
{
    return (int)imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

/**
 * Crta ikonu veličine $px.
 * $zaobljeno: true = zaobljena pozadina sa providnim uglovima; false = pozadina do ivica.
 * $skala: veličina crteža unutar ikone (za "maskable" je manja).
 */
function nacrtaj(int $px, bool $zaobljeno, float $skala = 1.0): GdImage
{
    $nad = 4;                       // crta se 4× veće pa se smanjuje (glatke ivice)
    $velika = 512 * $nad;
    $im = imagecreatetruecolor($velika, $velika);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    $bg = hex_boja($im, BRAON);
    if ($zaobljeno) {
        $t = putanja_tacke(zaobljen(0, 0, 512, 512, 112), 1.0, (float)$nad);
        $ravno = [];
        foreach ($t as [$x, $y]) { $ravno[] = (int)round($x); $ravno[] = (int)round($y); }
        imagefilledpolygon($im, $ravno, $bg);
    } else {
        imagefilledrectangle($im, 0, 0, $velika, $velika, $bg);
    }

    foreach (oblici() as $o) {
        $ravno = [];
        foreach (putanja_tacke($o['putanja'], $skala, (float)$nad) as [$x, $y]) {
            $ravno[] = (int)round($x);
            $ravno[] = (int)round($y);
        }
        imagefilledpolygon($im, $ravno, hex_boja($im, $o['boja']));
    }

    $mala = imagecreatetruecolor($px, $px);
    imagealphablending($mala, false);
    imagesavealpha($mala, true);
    imagefill($mala, 0, 0, (int)imagecolorallocatealpha($mala, 0, 0, 0, 127));
    // postepeno smanjivanje daje oštriji rezultat nego jedan veliki skok
    $tren = $im;
    $sir = $velika;
    while ($sir / 2 > $px) {
        $sir = (int)($sir / 2);
        $korak = imagescale($tren, $sir, $sir, IMG_BICUBIC);
        $tren = $korak;
    }
    imagealphablending($mala, false);
    imagecopyresampled($mala, $tren, 0, 0, 0, 0, $px, $px, imagesx($tren), imagesy($tren));
    return $mala;
}

function snimi(GdImage $im, string $fajl): void
{
    imagepng($im, $fajl, 9);
    echo 'napravljeno ' . basename($fajl) . ' (' . imagesx($im) . '×' . imagesy($im) . ')' . PHP_EOL;
}

// PNG
snimi(nacrtaj(192, true), "$izlaz/icon-192.png");
snimi(nacrtaj(512, true), "$izlaz/icon-512.png");
snimi(nacrtaj(512, false, 0.9), "$izlaz/icon-maskable-512.png");
snimi(nacrtaj(180, false, 0.92), "$izlaz/apple-touch-icon.png");
snimi(nacrtaj(32, true), "$izlaz/favicon-32.png");

// SVG
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">' . "\n";
$svg .= '  <path d="' . putanja_svg(zaobljen(0, 0, 512, 512, 112)) . '" fill="' . BRAON . "\"/>\n";
foreach (oblici() as $o) {
    $svg .= '  <path d="' . putanja_svg($o['putanja']) . '" fill="' . $o['boja'] . "\"/>\n";
}
$svg .= "</svg>\n";
file_put_contents("$izlaz/icon.svg", $svg);
echo "napravljeno icon.svg\n";
