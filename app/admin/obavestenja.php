<?php
/**
 * Administrator – obaveštenja na telefon: svako veče u zadati sat (podrazumevano 20h) telefonu stiže zbir dana
 * (urađeno / otišlo po artiklu). Ovde se obaveštenja uključuju na telefonu (assets/js/obavestenja.js), bira sat,
 * šalje probno obaveštenje i piše tačna komanda za cPanel → Cron Jobs (cron.php, jednom na sat).
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/danas.php';
require __DIR__ . '/../inc/push.php';

$admin = zahtevaj_ulogu('admin');
$ovde = 'admin/obavestenja.php';

if (je_post()) {
    csrf_proveri();
    $akcija = post_str('akcija', 20);
    if ($akcija === 'pretplata') {
        $greska = push_pretplata_sacuvaj((int)$admin['id'], post_str('endpoint', 1000), post_str('p256dh', 200), post_str('auth', 60),
            push_uredjaj((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($greska === null) {
            // adresa aplikacije ide u potpis obaveštenja (VAPID "sub"); cron je ne zna sam
            podesavanje_upisi('adresa_aplikacije', (je_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url(''));
            flash_dodaj('uspeh', 'Obaveštenja su uključena na ovom telefonu. Probajte: „Pošalji probno obaveštenje“.');
        } else {
            flash_dodaj('greska', $greska);
        }
    } elseif ($akcija === 'odjava') {
        db_run('DELETE FROM push_pretplate WHERE endpoint_hes = ?', [hash('sha256', post_str('endpoint', 1000))]);
        flash_dodaj('uspeh', 'Obaveštenja su isključena na ovom telefonu.');
    } elseif ($akcija === 'ukloni') {
        db_run('DELETE FROM push_pretplate WHERE id = ?', [post_int('id')]);
        flash_dodaj('uspeh', 'Telefon je uklonjen – na njega više ne stižu obaveštenja.');
    } elseif ($akcija === 'sat') {
        $sat = post_int('sat');
        if ($sat >= 12 && $sat <= 23) {
            podesavanje_upisi('obavestenje_sat', (string)$sat);
            flash_dodaj('uspeh', 'Dnevni zbir stiže svako veče u ' . $sat . 'h.');
        } else {
            flash_dodaj('greska', 'Izaberite sat od 12 do 23.');
        }
    } elseif ($akcija === 'probno') {
        $pretplate = push_pretplate_admina();
        if (!$pretplate) {
            flash_dodaj('greska', 'Nijedan telefon nije prijavljen – prvo na telefonu dodirnite „Uključi obaveštenja na ovom telefonu“.');
        } else {
            $poruka = danas_obavestenje(danas_zbir());
            $poruka['naslov'] = 'Probno – ' . $poruka['naslov'];
            $poruka['oznaka'] = 'probno';
            $r = push_posalji_svima($pretplate, $poruka);
            if ($r['poslato'] > 0) {
                flash_dodaj('uspeh', 'Probno obaveštenje je poslato na ' . $r['poslato'] . ' od ' . count($pretplate) . ' (stiže za nekoliko sekundi).');
            }
            foreach ($r['greske'] as $g) {
                flash_dodaj('greska', $g);
            }
        }
    }
    preusmeri($ovde);
}

$pretplate = db_all('SELECT p.*, k.ime FROM push_pretplate p JOIN korisnici k ON k.id = p.korisnik_id ORDER BY p.id');
$vapid = push_vapid();
$sat = (int)podesavanje('obavestenje_sat', '20');
$cron_poslednji = podesavanje('cron_poslednji');
$zbir_poslat = podesavanje('dnevni_zbir_poslat');
$adresa = (je_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cron_url = $adresa . url('cron.php') . '?kljuc=' . push_cron_kljuc();
$cron_radi = $cron_poslednji !== null && strtotime($cron_poslednji) > time() - 3 * 3600;

ui_start('Obaveštenja na telefon', ['nav' => 'admin', 'aktivno' => 'stanje', 'js' => ['assets/js/obavestenja.js']]);
?>
<section class="kartica" aria-labelledby="naslov-push">
    <div class="kartica-naslov"><h2 id="naslov-push">🔔 Dnevni zbir na telefon</h2></div>
    <p class="pomoc">Svako veče u <strong><?= $sat ?>h</strong> na telefon stiže obaveštenje: koliko je danas <strong>urađeno</strong> i koliko je <strong>otišlo</strong> (po artiklu, u paletama). Dodir na obaveštenje otvara početnu. Ako tog dana nije bilo ničega, ne stiže ništa.</p>
    <div data-push data-vapid="<?= e($vapid['javni']) ?>">
        <p class="push-status" data-push-status aria-live="polite">Proveravam ovaj telefon…</p>
        <button type="button" class="btn btn-primary btn-blok btn-veliko" data-push-ukljuci hidden>🔔 Uključi obaveštenja na ovom telefonu</button>
        <button type="button" class="btn btn-blok" data-push-iskljuci hidden>Isključi na ovom telefonu</button>
    </div>
    <form method="post" id="push-forma" action="<?= e(url($ovde)) ?>" hidden>
        <?= csrf_polje() ?>
        <input type="hidden" name="akcija" value="pretplata">
        <input type="hidden" name="endpoint" value="">
        <input type="hidden" name="p256dh" value="">
        <input type="hidden" name="auth" value="">
    </form>
    <script type="application/json" id="push-poznati"><?= json_za_html(array_column($pretplate, 'endpoint')) ?></script>
    <p class="pomoc bez-margine razmak-gore"><strong>iPhone:</strong> obaveštenja rade samo kad je aplikacija dodata na početni ekran (Safari → Podeli → Dodaj na početni ekran) i otvorena odatle, na iOS 16.4 ili novijem.</p>
</section>

<section class="kartica" aria-labelledby="naslov-telefoni">
    <div class="kartica-naslov"><h2 id="naslov-telefoni">Prijavljeni telefoni (<?= count($pretplate) ?>)</h2></div>
    <?php if (!$pretplate): ?>
        <p class="pomoc">Još nijedan. Otvorite ovu stranicu na svom telefonu i dodirnite „Uključi obaveštenja na ovom telefonu“.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($pretplate as $p): ?>
                <li>
                    <div class="unos-red">
                        <span class="naziv"><?= e($p['uredjaj'] ?? 'Telefon') ?></span>
                        <span class="pod"><?= e($p['ime']) ?> · od <?= e(datum_srp((string)$p['napravljen'])) ?><?= $p['poslednje_slanje'] ? ' · poslednje obaveštenje ' . e(datum_kratko((string)$p['poslednje_slanje'])) : '' ?></span>
                        <?php if ($p['greska']): ?><span class="kol-pod tekst-crveno"><?= e($p['greska']) ?></span><?php endif; ?>
                        <div class="akcije">
                            <form method="post" action="<?= e(url($ovde)) ?>" class="forma-u-liniji" data-potvrda="Ukloniti ovaj telefon? Na njega više neće stizati obaveštenja.">
                                <?= csrf_polje() ?>
                                <input type="hidden" name="akcija" value="ukloni">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn btn-opasno btn-mali"><?= ikona('kanta') ?> Ukloni</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post" action="<?= e(url($ovde)) ?>" class="razmak-gore">
            <?= csrf_polje() ?>
            <input type="hidden" name="akcija" value="probno">
            <button type="submit" class="btn btn-braon btn-blok">Pošalji probno obaveštenje (današnji zbir do sada)</button>
        </form>
    <?php endif; ?>
</section>

<form method="post" class="kartica" action="<?= e(url($ovde)) ?>">
    <?= csrf_polje() ?>
    <input type="hidden" name="akcija" value="sat">
    <div class="kartica-naslov"><h2>Vreme dnevnog zbira</h2></div>
    <div class="red-polja">
        <label for="sat">Svako veče u</label>
        <select class="polje" id="sat" name="sat">
            <?php for ($h = 12; $h <= 23; $h++): ?><option value="<?= $h ?>"<?= $h === $sat ? ' selected' : '' ?>><?= $h ?>:00</option><?php endfor; ?>
        </select>
    </div>
    <button class="btn btn-primary btn-blok" type="submit">Sačuvaj</button>
</form>

<section class="kartica" aria-labelledby="naslov-cron">
    <div class="kartica-naslov"><h2 id="naslov-cron">Zakazano slanje (cPanel – jednom)</h2></div>
    <?php if ($cron_radi): ?>
        <p class="poruka poruka-uspeh bez-margine">✓ Zakazano slanje radi (poslednje pokretanje <?= e(datum_kratko((string)$cron_poslednji)) ?>).<?= $zbir_poslat ? ' Poslednji dnevni zbir: ' . e(datum_srp($zbir_poslat)) . '.' : '' ?></p>
    <?php else: ?>
        <p class="poruka poruka-upozorenje bez-margine">Zakazano slanje još nije podešeno<?= $cron_poslednji ? ' (poslednje pokretanje ' . e(datum_kratko($cron_poslednji)) . ')' : '' ?> – bez njega obaveštenje uveče ne stiže. Podesite ga jednom u cPanel-u:</p>
    <?php endif; ?>
    <ol class="pomoc razmak-gore">
        <li>U cPanel-u otvorite <strong>Cron Jobs</strong> (deo „Advanced“).</li>
        <li>Kod <strong>Common Settings</strong> izaberite <strong>Once Per Hour (0 * * * *)</strong>.</li>
        <li>U polje <strong>Command</strong> nalepite (dodirnite red da se označi, pa Kopiraj):
            <code class="cron-komanda">wget -q -O /dev/null "<?= e($cron_url) ?>"</code>
            Ako hosting ne dozvoljava wget, može i: <code class="cron-komanda">php <?= e(APP_ROOT) ?>/cron.php</code>
        </li>
        <li><strong>Add New Cron Job</strong>. Posle sat vremena ovde piše „Zakazano slanje radi“.</li>
    </ol>
    <p class="pomoc bez-margine">Ključ u adresi je tajna (sa njim se pokreće samo slanje dnevnog zbira).</p>
</section>
<?php
ui_end();
