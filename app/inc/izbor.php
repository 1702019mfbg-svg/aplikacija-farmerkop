<?php
/**
 * Birač artikla → varijante (boja/granulacija) → pakovanja → količine (komadi ili palete).
 * Koriste ga radnik (proizvodnja, kućna prodaja) i administrator (prodaja, ispravka unosa).
 * Ponašanje je u assets/js/unos.js; ovde je samo HTML i podaci kataloga.
 */
declare(strict_types=1);

/**
 * @param array $katalog    rezultat katalog_za_izbor()
 * @param int   $sku_id     unapred izabran SKU (0 = ništa)
 * @param bool  $stanje     prikaži stanje uz pakovanja (samo za administratora)
 * @param array $pocetno    unapred popunjena količina: ['kolicina' => n, 'nacin' => 'komadi'|'palete']
 */
function izbor_html(array $katalog, int $sku_id = 0, bool $stanje = false, array $pocetno = []): string
{
    ob_start();
    ?>
    <div class="izbor" data-izbor data-sku="<?= $sku_id ?>" data-stanje="<?= $stanje ? '1' : '0' ?>"
         data-kolicina="<?= e($pocetno['kolicina'] ?? '') ?>" data-nacin="<?= e($pocetno['nacin'] ?? '') ?>"
         data-max="<?= (int)MAX_KOLICINA_UNOS ?>">
        <input type="hidden" name="sku_id" value="<?= $sku_id ?: '' ?>" data-sku-polje>
        <input type="hidden" name="nacin" value="komadi" data-nacin-polje>

        <div class="korak" data-korak="artikal">
            <h3 class="korak-naslov">Artikal</h3>
            <div data-artikli></div>
        </div>

        <div class="korak" data-korak="varijanta" hidden>
            <h3 class="korak-naslov" data-naslov-varijante>Varijanta</h3>
            <div class="izbor-mreza" data-varijante></div>
        </div>

        <div class="korak" data-korak="pakovanje" hidden>
            <h3 class="korak-naslov">Pakovanje</h3>
            <div class="izbor-mreza" data-pakovanja></div>
        </div>

        <div class="korak" data-korak="kolicina" hidden>
            <h3 class="korak-naslov">Količina</h3>
            <div class="segment" role="group" aria-label="Način unosa količine">
                <button type="button" data-nacin-vrednost="palete" aria-pressed="false">Palete</button>
                <button type="button" data-nacin-vrednost="komadi" aria-pressed="true">Komadi</button>
            </div>
            <input class="kolicina-polje" name="kolicina" type="text" inputmode="numeric" pattern="[0-9]*"
                   autocomplete="off" placeholder="0" aria-label="Količina" maxlength="6">
            <div class="koraci">
                <button type="button" data-delta="-10">−10</button>
                <button type="button" data-delta="-1">−1</button>
                <button type="button" data-delta="1">+1</button>
                <button type="button" data-delta="10">+10</button>
            </div>
            <p class="zbir" data-zbir aria-live="polite"></p>
        </div>

        <script type="application/json" data-katalog><?= json_za_html($katalog) ?></script>
    </div>
    <?php
    return (string)ob_get_clean();
}
