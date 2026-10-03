/* Snima ekrane aplikacije kao telefon (svetli i tamni režim). Pokreće se preko tests/ekrani.py */
const { chromium } = require('playwright');
const fs = require('fs');

const [base, izlaz, spisak] = process.argv.slice(2);
const zeljeni = (spisak || '').split(',').filter(Boolean);
const TELEFON = { width: 390, height: 844 };

async function novaStranica(browser, tema) {
    const ctx = await browser.newContext({
        viewport: TELEFON,
        deviceScaleFactor: 2,
        colorScheme: tema,
        locale: 'sr-Latn-RS',
        timezoneId: 'Europe/Belgrade',
        isMobile: true,
        hasTouch: true,
    });
    const page = await ctx.newPage();
    const greske = [];
    page.on('pageerror', (e) => greske.push('JS greška: ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') greske.push('konzola: ' + m.text()); });
    page.on('requestfailed', (r) => greske.push('zahtev nije uspeo: ' + r.url()));
    return { ctx, page, greske };
}

async function slika(page, ime, tema, celaStranica = true) {
    const putanja = `${izlaz}/${ime}-${tema === 'dark' ? 'tamno' : 'svetlo'}.png`;
    await page.screenshot({ path: putanja, fullPage: celaStranica });
    console.log('snimljeno', putanja);
}

async function prijavaRadnik(page, ime, pin) {
    await page.goto(base + '/login.php');
    await page.getByText(ime, { exact: true }).click();
    for (const c of pin) {
        await page.locator(`[data-cifra="${c}"]`).click();
    }
    await page.waitForURL('**/radnik/**');
}

async function prijavaAdmin(page, korisnik, sifra) {
    await page.goto(base + '/login.php?admin=1');
    await page.fill('#korisnicko_ime', korisnik);
    await page.fill('#sifra', sifra);
    await Promise.all([page.waitForURL('**/admin/**'), page.click('button[type=submit]')]);
}

(async () => {
    const browser = await chromium.launch();
    const sveGreske = [];
    const tokovi = require('./ekrani_tokovi.cjs');
    for (const tema of ['light', 'dark']) {
        const { ctx, page, greske } = await novaStranica(browser, tema);
        const pom = { base, slika: (ime, cela) => slika(page, ime, tema, cela), prijavaRadnik: (i, p) => prijavaRadnik(page, i, p), prijavaAdmin: (k, s) => prijavaAdmin(page, k, s), tema, browser, novaStranica: (t) => novaStranica(browser, t) };
        for (const [naziv, tok] of Object.entries(tokovi)) {
            if (zeljeni.length && !zeljeni.includes(naziv)) { continue; }
            try {
                await tok(page, pom);
            } catch (e) {
                sveGreske.push(`${naziv} (${tema}): ${e.message}`);
            }
        }
        sveGreske.push(...greske.map((g) => `${tema}: ${g}`));
        await ctx.close();
    }
    await browser.close();
    if (sveGreske.length) {
        console.log('PROBLEMI:\n' + sveGreske.join('\n'));
        process.exit(1);
    }
    console.log('bez JS grešaka');
})();
