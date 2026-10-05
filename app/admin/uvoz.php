<?php
/**
 * Administrator – Podešavanja → Uvoz stanja iz fajla (CSV ili tabela nalepljena iz Excela / Bluesofta).
 *
 * 1. korak: otpremanje fajla ili lepljenje tabele.
 * 2. korak: izbor kolona, jedinice i napomene, pregled uparivanja sa artiklima i uvoz.
 * Upisivanje ide kao Popis: stanje svakog artikla postaje tačno vrednost iz fajla, razlika ide u istoriju.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/podesavanja.php';
require __DIR__ . '/../inc/uvoz.php';

$admin = zahtevaj_ulogu('admin');
sesija_start();

if (get_int('primer') === 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="farmerkop-uvoz-primer.csv"');
    echo uvoz_primer_csv();
    exit;
}

/** Izbor kolona iz forme, proveren prema širini tabele. Vraća [izbor, greška]. */
function uvoz_izbor_iz_posta(int $sirina): array
{
    $izbor = [
        'zaglavlje' => post_str('zaglavlje', 1) === '1',
        'naziv'     => post_int('kol_naziv', -1),
        'kolicina'  => post_int('kol_kolicina', -1),
        'sifra'     => post_int('kol_sifra', -1),
        'jedinica'  => post_str('jedinica', 10),
        'format'    => post_str('format', 3),
    ];
    if (!isset(UVOZ_JEDINICE[$izbor['jedinica']])) {
        $izbor['jedinica'] = 'komadi';
    }
    if ($izbor['format'] !== 'en') {
        $izbor['format'] = 'sr';
    }
    foreach (['naziv', 'kolicina', 'sifra'] as $k) {
        if ($izbor[$k] < -1 || $izbor[$k] >= $sirina) {
            $izbor[$k] = -1;
        }
    }
    if ($izbor['naziv'] < 0 || $izbor['kolicina'] < 0) {
        return [$izbor, 'Izaberite kolonu sa nazivom artikla i kolonu sa količinom.'];
    }
    if ($izbor['naziv'] === $izbor['kolicina']) {
        return [$izbor, 'Kolona sa nazivom i kolona sa količinom moraju biti različite.'];
    }
    return [$izbor, null];
}

/** Ručni izbor artikala iz forme: [indeks reda => sku_id]. */
function uvoz_rucno_iz_posta(int $broj_redova): array
{
    $rez = [];
    $niz = $_POST['sku'] ?? [];
    if (!is_array($niz)) {
        return $rez;
    }
    foreach ($niz as $k => $v) {
        // PHP pretvara ključeve poput "3" u cele brojeve, pa se prihvataju oba oblika.
        if ((is_int($k) || ctype_digit((string)$k)) && $k >= 0 && (int)$k < $broj_redova && is_string($v) && ctype_digit($v) && strlen($v) <= 9) {
            $rez[(int)$k] = (int)$v;
        }
    }
    return $rez;
}

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    $nazad = 'admin/uvoz.php';

    if ($akcija === 'odustani') {
        unset($_SESSION['uvoz']);
        flash_dodaj('info', 'Uvoz je otkazan. Stanje nije menjano.');
        preusmeri($nazad);
    }

    if ($akcija === 'ucitaj') {
        $sirovo = '';
        $ime = 'nalepljena tabela';
        $f = $_FILES['fajl'] ?? null;
        if (is_array($f) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)$f['error'];
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                flash_dodaj('greska', 'Fajl je prevelik (najviše ' . intdiv(UVOZ_MAX_BAJTOVA, 1048576) . ' MB).');
                preusmeri($nazad);
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                flash_dodaj('greska', 'Fajl nije stigao do servera. Pokušajte ponovo.');
                preusmeri($nazad);
            }
            $sirovo = (string)file_get_contents((string)$f['tmp_name'], false, null, 0, UVOZ_MAX_BAJTOVA + 1);
            $ime = mb_substr(basename((string)($f['name'] ?? '')), 0, 80);
        } else {
            $t = $_POST['tekst'] ?? '';
            $sirovo = is_string($t) ? $t : '';
            if (trim($sirovo) === '') {
                flash_dodaj('greska', 'Izaberite fajl ili nalepite tabelu.');
                preusmeri($nazad);
            }
        }
        $p = uvoz_procitaj_tekst($sirovo);
        if (isset($p['greska'])) {
            flash_dodaj('greska', $p['greska']);
            preusmeri($nazad);
        }
        $pretpostavka = uvoz_pretpostavi_kolone($p['redovi']);
        $sirina = uvoz_sirina($p['redovi']);
        $_SESSION['uvoz'] = [
            'ime'       => $ime,
            'razdvajac' => $p['razdvajac'],
            'tabela'    => $p['redovi'],
            'izbor'     => [
                'zaglavlje' => $pretpostavka['zaglavlje'],
                'naziv'     => max(0, $pretpostavka['naziv']),
                'kolicina'  => $pretpostavka['kolicina'] >= 0 ? $pretpostavka['kolicina'] : max(0, $sirina - 1),
                'sifra'     => $pretpostavka['sifra'],
                'jedinica'  => 'komadi',
                'format'    => 'sr',
            ],
            'rucno'     => [],
            'napomena'  => 'Uvoz stanja ' . date('d.m.Y.'),
            'zapamti'   => true,
            'v'         => bin2hex(random_bytes(4)),
        ];
        preusmeri($nazad);
    }

    if ($akcija === 'osvezi' || $akcija === 'uvezi') {
        $u = $_SESSION['uvoz'] ?? null;
        if (!is_array($u)) {
            flash_dodaj('greska', 'Nema učitanog fajla. Učitajte fajl ponovo.');
            preusmeri($nazad);
        }
        if (post_str('kraj', 1) !== '1') {
            flash_dodaj('greska', 'Forma je stigla nepotpuna (previše polja za server). Uvezite manji fajl ili samo Farmerkop artikle.');
            preusmeri($nazad);
        }
        $tabela = $u['tabela'];
        [$izbor, $greska_kolona] = uvoz_izbor_iz_posta(uvoz_sirina($tabela));
        $napomena = post_str('napomena', 150);
        $zapamti = post_str('zapamti', 1) === '1';
        $rucno_posle = uvoz_rucno_iz_posta(count($tabela));
        $isti_redovi = $izbor['naziv'] === $u['izbor']['naziv'] && $izbor['sifra'] === $u['izbor']['sifra'] && $izbor['zaglavlje'] === $u['izbor']['zaglavlje'];
        $verzija_ok = hash_equals((string)$u['v'], post_str('v', 20));

        if ($akcija === 'osvezi') {
            if ($greska_kolona !== null) {
                flash_dodaj('greska', $greska_kolona);
                preusmeri($nazad);
            }
            if ($isti_redovi && $verzija_ok) {
                // Pamte se samo izbori koji se razlikuju od automatskog uparivanja (ostalo se i dalje uparuje samo).
                $auto = [];
                foreach (uvoz_pregled($tabela, $u['izbor'], uvoz_indeks_sku(), uvoz_pamcenje(), null) as $s) {
                    $auto[$s['i']] = $s['sku_id'];
                }
                $rucno = $u['rucno'];
                foreach ($rucno_posle as $i => $sid) {
                    if (($auto[$i] ?? 0) === $sid) {
                        unset($rucno[$i]);
                    } else {
                        $rucno[$i] = $sid;
                    }
                }
                $u['rucno'] = $rucno;
            } else {
                $u['rucno'] = [];
            }
            $u['izbor'] = $izbor;
            $u['napomena'] = $napomena !== '' ? $napomena : $u['napomena'];
            $u['zapamti'] = $zapamti;
            $u['v'] = bin2hex(random_bytes(4));
            $_SESSION['uvoz'] = $u;
            preusmeri($nazad);
        }

        // ── Uvoz ──
        $u['napomena'] = $napomena !== '' ? $napomena : $u['napomena'];
        $u['zapamti'] = $zapamti;
        $_SESSION['uvoz'] = $u;
        if (!$verzija_ok || $izbor != $u['izbor']) {
            flash_dodaj('greska', 'Izbor kolona ili jedinice je promenjen posle poslednjeg pregleda. Kliknite „Osveži pregled“ i proverite redove pre uvoza.');
            preusmeri($nazad);
        }
        if ($greska_kolona !== null) {
            flash_dodaj('greska', $greska_kolona);
            preusmeri($nazad);
        }
        if (mb_strlen($napomena) < 3) {
            flash_dodaj('greska', 'Upišite napomenu (npr. „Početno stanje“). Obavezna je da bi se znalo zašto je stanje menjano.');
            preusmeri($nazad);
        }
        $indeks = uvoz_indeks_sku();
        $stavke = uvoz_pregled($tabela, $izbor, $indeks, uvoz_pamcenje(), array_replace($u['rucno'], $rucno_posle));
        $z = uvoz_zbir($stavke, $indeks);
        if ($z['greske']) {
            flash_dodaj('greska', 'Ispravite ili preskočite redove sa greškom: ' . implode('; ', array_slice($z['greske'], 0, 5)) . (count($z['greske']) > 5 ? ' …' : ''));
            $u['rucno'] = array_replace($u['rucno'], $rucno_posle);
            $u['v'] = bin2hex(random_bytes(4));
            $_SESSION['uvoz'] = $u;
            preusmeri($nazad);
        }
        if (!$z['zbir']) {
            flash_dodaj('greska', 'Nijedan red nije uparen sa artiklom, pa nema šta da se uveze.');
            preusmeri($nazad);
        }

        $promenjeno = 0;
        $isto = 0;
        $opis = [];
        foreach ($z['zbir'] as $sid => $komadi) {
            $rez = popis_postavi((int)$sid, (int)$komadi, $napomena, (int)$admin['id']);
            if ($rez['promena']) {
                $promenjeno++;
                $opis[] = $indeks['sku'][$sid]['naziv'] . ': ' . broj($rez['bilo']) . ' → ' . broj($komadi) . ' (' . ($rez['razlika'] > 0 ? '+' : '−') . broj(abs($rez['razlika'])) . ')';
            } else {
                $isto++;
            }
        }
        if ($zapamti) {
            uvoz_zapamti($stavke);
        }
        if ($promenjeno > 0) {
            dnevnik_podesavanje('Uvoz stanja iz fajla „' . $u['ime'] . '“ („' . $napomena . '“): ' . implode('; ', array_slice($opis, 0, 20)) . (count($opis) > 20 ? ' …' : ''));
        }
        unset($_SESSION['uvoz']);
        flash_dodaj(
            $promenjeno > 0 ? 'uspeh' : 'info',
            $promenjeno > 0
                ? 'Stanje je uvezeno. Promenjeno artikala: ' . $promenjeno . ($isto ? ', bez razlike: ' . $isto : '') . ($z['preskoceno'] ? ', preskočenih redova: ' . $z['preskoceno'] : '') . '.'
                : 'Stanje u fajlu je isto kao u programu – ništa nije menjano.'
        );
        preusmeri('admin/stanje.php');
    }
    preusmeri($nazad);
}

// ─── Prikaz ────────────────────────────────────────────────────────────────
$u = $_SESSION['uvoz'] ?? null;
$u = is_array($u) ? $u : null;

if ($u === null) {
    $broj_mapiranja = (int)db_val('SELECT COUNT(*) FROM uvoz_mapiranje');
    ui_start('Uvoz stanja', ['nav' => 'admin', 'aktivno' => 'podesavanja']);
    ?>
    <p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a>
       <a class="btn btn-mali" href="<?= e(url('admin/popis.php')) ?>">Ručni popis</a></p>

    <section class="kartica">
        <div class="kartica-naslov"><h2>Uvoz stanja iz fajla</h2></div>
        <p class="pomoc">Za početno stanje ili usklađivanje sa Bluesoftom: izvezite stanje zaliha iz programa za fakture u <strong>CSV</strong> (ili Excel pa „Sačuvaj kao CSV“), ili jednostavno <strong>kopirajte tabelu i nalepite je</strong> ispod.
            Fajl treba da ima bar naziv artikla i količinu (šifra je nepotrebna ali pomaže). U sledećem koraku proveravate kako je svaki red uparen sa vašim artiklima i tek onda uvozite.</p>
        <p class="pomoc bez-margine">Stanje svakog uparenog artikla postaje tačno ono iz fajla (kao popis), a razlika se upisuje u istoriju. Artikli kojih nema u fajlu ostaju kako jesu.</p>
    </section>

    <form method="post" enctype="multipart/form-data" class="kartica" action="<?= e(url('admin/uvoz.php')) ?>">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="ucitaj">
        <div class="kartica-naslov"><h2>1. Fajl ili nalepljena tabela</h2></div>
        <div class="red-polja">
            <label for="fajl">Fajl (CSV ili TXT, do <?= (int)(UVOZ_MAX_BAJTOVA / 1048576) ?> MB)</label>
            <input class="polje" type="file" id="fajl" name="fajl" accept=".csv,.txt,.tsv,text/csv,text/plain">
        </div>
        <p class="pomoc">— ili —</p>
        <div class="red-polja">
            <label for="tekst">Nalepite tabelu (kopirano iz Excela ili Bluesofta)</label>
            <textarea class="polje" id="tekst" name="tekst" rows="6" placeholder="Naziv&#9;Stanje&#10;Humovit 5 l&#9;1250" spellcheck="false"></textarea>
        </div>
        <button class="btn btn-primary btn-veliko btn-blok" type="submit"><?= ikona('otpremi') ?> Učitaj</button>
        <p class="pomoc razmak-gore bez-margine">Najviše <?= (int)UVOZ_MAX_REDOVA ?> redova odjednom – ako je fajl veći, izvezite samo Farmerkop artikle.
            <a href="<?= e(url('admin/uvoz.php?primer=1')) ?>">Preuzmi primer fajla</a>.
            <?php if ($broj_mapiranja > 0): ?>Program pamti <?= (int)$broj_mapiranja ?> ranije izabranih uparivanja pa ih sledeći put sam primeni.<?php endif; ?></p>
    </form>
    <?php
    ui_end();
    exit;
}

// ── Korak 2: pregled ──
$tabela = $u['tabela'];
$izbor = $u['izbor'];
$indeks = uvoz_indeks_sku();
$stavke = uvoz_pregled($tabela, $izbor, $indeks, uvoz_pamcenje(), $u['rucno']);
$z = uvoz_zbir($stavke, $indeks);
$stanja = stanja_svih();
$sirina = uvoz_sirina($tabela);

$brojaci = ['memorija' => 0, 'prepoznato' => 0, 'proveri' => 0, 'rucno' => 0, 'nema' => 0, 'preskoceno' => 0];
foreach ($stavke as $s) {
    $brojaci[$s['izvor']] = ($brojaci[$s['izvor']] ?? 0) + 1;
}
$sa_greskom = count($z['greske']);
$nepoznato = $brojaci['nema'] + $brojaci['preskoceno'];

$kolone_opis = [];
for ($k = 0; $k < $sirina; $k++) {
    $primer = $tabela[0][$k] ?? '';
    $kolone_opis[$k] = 'Kolona ' . ($k + 1) . ($primer !== '' ? ' · ' . mb_substr($primer, 0, 28) : '');
}

$katalog_js = [];
foreach ($indeks['sku'] as $sid => $r) {
    $katalog_js[] = [(int)$sid, (string)$r['naziv']];
}

$oznake = [
    'memorija'   => ['zapamćeno', 'znacka-proizvodnja'],
    'prepoznato' => ['prepoznato', 'znacka-proizvodnja'],
    'proveri'    => ['proveri!', 'znacka-prodaja'],
    'rucno'      => ['izabrano', 'znacka-korekcija'],
    'preskoceno' => ['preskočeno', ''],
    'nema'       => ['nije prepoznato', 'znacka-nisko'],
];
$potvrda = 'Uvesti stanje? Stanje ' . count($z['zbir']) . ' artikala biće postavljeno na vrednosti iz fajla'
    . ($brojaci['proveri'] > 0 ? ' (od toga ' . $brojaci['proveri'] . ' označenih „proveri!“)' : '')
    . ($z['preskoceno'] > 0 ? '. Preskočenih redova: ' . $z['preskoceno'] : '') . '.';

ui_start('Uvoz stanja', ['nav' => 'admin', 'aktivno' => 'podesavanja', 'js' => ['assets/js/uvoz.js']]);
?>
<p><a class="btn btn-mali" href="<?= e(url('admin/podesavanja.php')) ?>"><?= ikona('nazad') ?> Podešavanja</a></p>

<section class="kartica">
    <div class="kartica-naslov"><h2>Učitano: <?= e($u['ime']) ?></h2></div>
    <p class="pomoc bez-margine"><?= e(broj(count($tabela))) ?> redova, razdvajač: <?= e($u['razdvajac']) ?>.</p>
    <form method="post" class="razmak-gore" action="<?= e(url('admin/uvoz.php')) ?>" data-potvrda="Odustati od uvoza? Učitani fajl se odbacuje, stanje nije menjano.">
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="odustani">
        <button class="btn btn-mali" type="submit">Odustani od uvoza</button>
    </form>
</section>

<form method="post" class="kartica" autocomplete="off" action="<?= e(url('admin/uvoz.php')) ?>" data-uvoz>
    <?= csrf_polje() ?>
    <input type="hidden" name="v" value="<?= e($u['v']) ?>">
    <div class="kartica-naslov"><h2>2. Kolone i jedinica</h2></div>

    <div class="red-polja">
        <label><input type="checkbox" name="zaglavlje" value="1"<?= $izbor['zaglavlje'] ? ' checked' : '' ?>> Prvi red je zaglavlje (nazivi kolona)</label>
    </div>
    <div class="red-polja">
        <label for="kol_naziv">Kolona sa nazivom artikla</label>
        <select class="polje" id="kol_naziv" name="kol_naziv">
            <?php foreach ($kolone_opis as $k => $t): ?><option value="<?= $k ?>"<?= $izbor['naziv'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="red-polja">
        <label for="kol_kolicina">Kolona sa količinom (stanjem)</label>
        <select class="polje" id="kol_kolicina" name="kol_kolicina">
            <?php foreach ($kolone_opis as $k => $t): ?><option value="<?= $k ?>"<?= $izbor['kolicina'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="red-polja">
        <label for="kol_sifra">Kolona sa šifrom (nije obavezno)</label>
        <select class="polje" id="kol_sifra" name="kol_sifra">
            <option value="-1"<?= $izbor['sifra'] < 0 ? ' selected' : '' ?>>— nema —</option>
            <?php foreach ($kolone_opis as $k => $t): ?><option value="<?= $k ?>"<?= $izbor['sifra'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="red-polja-2 red-polja">
        <div>
            <label for="jedinica">Količina u fajlu je u</label>
            <select class="polje" id="jedinica" name="jedinica">
                <option value="komadi"<?= $izbor['jedinica'] === 'komadi' ? ' selected' : '' ?>>komadima</option>
                <option value="paketi"<?= $izbor['jedinica'] === 'paketi' ? ' selected' : '' ?>>paketima</option>
                <option value="palete"<?= $izbor['jedinica'] === 'palete' ? ' selected' : '' ?>>paletama</option>
            </select>
        </div>
        <div>
            <label for="format">Format brojeva</label>
            <select class="polje" id="format" name="format">
                <option value="sr"<?= $izbor['format'] === 'sr' ? ' selected' : '' ?>>1.250,00 (srpski)</option>
                <option value="en"<?= $izbor['format'] === 'en' ? ' selected' : '' ?>>1,250.00 (engleski)</option>
            </select>
        </div>
    </div>
    <div class="red-polja">
        <label for="napomena">Napomena (obavezna)</label>
        <input class="polje" id="napomena" name="napomena" type="text" maxlength="150" required value="<?= e($u['napomena']) ?>">
    </div>
    <div class="red-polja">
        <label><input type="checkbox" name="zapamti" value="1"<?= $u['zapamti'] ? ' checked' : '' ?>> Zapamti uparivanja za sledeći uvoz</label>
    </div>
    <button class="btn btn-braon btn-blok" type="submit" name="akcija" value="osvezi">Osveži pregled</button>

    <div class="kartica-naslov razmak-gore"><h2>3. Pregled redova</h2></div>
    <div class="stat-mreza tri">
        <div class="stat stat-uspeh"><span class="broj"><?= e(broj(count($z['zbir']))) ?></span><span class="opis">artikala se uvozi</span></div>
        <div class="stat"><span class="broj"><?= e(broj($nepoznato)) ?></span><span class="opis">preskočeno</span></div>
        <div class="stat"><span class="broj"><?= e(broj($sa_greskom)) ?></span><span class="opis">sa greškom</span></div>
    </div>
    <?php if ($brojaci['proveri'] > 0): ?>
        <div class="poruka poruka-upozorenje" role="status">Redovi označeni <strong>„proveri!“</strong> upareni su po delimičnom poklapanju naziva – proverite da li je izabran pravi artikal.</div>
    <?php endif; ?>
    <?php if ($z['duplikata'] > 0): ?>
        <div class="poruka poruka-info" role="status">Više redova pripada istom artiklu (<?= (int)$z['duplikata'] ?>): njihove količine se sabiraju.</div>
    <?php endif; ?>
    <?php if (!$stavke): ?>
        <p class="pomoc">Nema redova za prikaz. Proverite da li je prvi red zaglavlje i da li je izabrana prava kolona.</p>
    <?php endif; ?>

    <?php foreach ($stavke as $s):
        $st = ($s['sku_id'] > 0 && $s['greska'] !== null) ? 'pogresno' : $s['izvor'];
        [$oznaka_tekst, $oznaka_klasa] = $oznake[$s['izvor']] ?? ['', ''];
        $sku = $s['sku_id'] > 0 ? $indeks['sku'][$s['sku_id']] : null; ?>
        <div class="uvoz-red uvoz-<?= e($st) ?>" data-uvoz-red>
            <div class="uvoz-izvor">
                <strong><?= e($s['naziv'] !== '' ? $s['naziv'] : '(bez naziva)') ?></strong>
                <?php if ($s['sifra'] !== ''): ?><small class="pomoc">šifra <?= e($s['sifra']) ?></small><?php endif; ?>
                <span class="uvoz-kol"><?= e($s['sirova']) ?><?= $s['komadi'] !== null && $sku !== null ? ' = ' . e(broj((int)$s['komadi'])) . ' kom' : '' ?></span>
            </div>
            <div class="uvoz-izbor">
                <select class="polje" name="sku[<?= (int)$s['i'] ?>]" aria-label="Artikal za red „<?= e($s['naziv']) ?>“" data-uvoz-sku data-izabrano="<?= (int)$s['sku_id'] ?>">
                    <option value="0"<?= $sku === null ? ' selected' : '' ?>>— preskoči —</option>
                    <?php if ($sku !== null): ?><option value="<?= (int)$s['sku_id'] ?>" selected><?= e($sku['naziv']) ?></option><?php endif; ?>
                </select>
                <span class="znacka <?= e($oznaka_klasa) ?>" data-uvoz-oznaka><?= e($oznaka_tekst) ?></span>
            </div>
            <?php if ($s['greska'] !== null && $sku !== null): ?>
                <p class="uvoz-greska"><?= e(ucfirst($s['greska'])) ?> – ispravite izbor ili preskočite red.</p>
            <?php elseif ($sku !== null && $s['komadi'] !== null): ?>
                <p class="pomoc uvoz-sada">u programu sada: <?= e(broj($stanja[(int)$s['sku_id']] ?? 0)) ?> kom → posle uvoza: <strong><?= e(broj((int)$s['komadi'])) ?></strong> kom</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <input type="hidden" name="kraj" value="1">
    <button class="btn btn-primary btn-veliko btn-blok razmak-gore" type="submit" name="akcija" value="uvezi" data-potvrda="<?= e($potvrda) ?>"><?= ikona('kvacica') ?> Uvezi stanje</button>
    <p class="pomoc razmak-gore bez-margine">Ako ste menjali kolone, jedinicu ili izbor artikla, prvo kliknite „Osveži pregled“. Uvoz upisuje korekcije u istoriju – svaka se može videti u Istoriji (Popis).</p>

    <script type="application/json" data-uvoz-katalog><?= json_za_html($katalog_js) ?></script>
</form>
<?php
ui_end();
