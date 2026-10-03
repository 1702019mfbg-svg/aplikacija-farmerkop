/* Tokovi (koraci) za snimanje ekrana i proveru ponašanja u pravom pregledaču. */
const tvrdi = (uslov, poruka) => { if (!uslov) { throw new Error('PROVERA PALA: ' + poruka); } };

module.exports = {
    async prijava(page, p) {
        await page.goto(p.base + '/login.php');
        await p.slika('01-prijava-radnik');
        await page.locator('label.ime', { hasText: 'Marko' }).click();
        await page.locator('[data-cifra="1"]').click();
        await page.locator('[data-cifra="2"]').click();
        await p.slika('02-prijava-radnik-pin');
        await page.goto(p.base + '/login.php?admin=1');
        await p.slika('03-prijava-admin');
    },

    async radnik(page, p) {
        await p.prijavaRadnik('Marko', '1234');
        tvrdi(page.url().endsWith('/radnik/index.php'), 'posle PIN-a radnik je na svom ekranu (auto-slanje)');
        await p.slika('10-radnik-pocetak');

        // Humovit → pakovanja
        await page.locator('[data-artikal]', { hasText: /^Humovit$/ }).click();
        tvrdi(await page.locator('[data-korak="pakovanje"]').isVisible(), 'posle artikla se vide pakovanja');
        tvrdi((await page.locator('[data-pakovanja] .izbor-dugme').count()) === 4, 'Humovit ima 4 pakovanja (5, 10, 25, 50 l)');
        tvrdi(!(await page.locator('[data-korak="varijanta"]').isVisible()), 'Humovit nema izbor boje');
        tvrdi(!(await page.locator('[data-korak="kolicina"]').isVisible()), 'količina se ne vidi dok nije izabrano pakovanje');
        await p.slika('11-radnik-pakovanja');

        // 10 l → količina, podrazumevano palete
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: '10 l' }).click();
        tvrdi(await page.locator('[data-korak="kolicina"]').isVisible(), 'količina se vidi posle izbora pakovanja');
        tvrdi((await page.locator('[data-nacin-vrednost="palete"]').getAttribute('aria-pressed')) === 'true', 'podrazumevani način je Palete');
        tvrdi((await page.locator('[data-sacuvaj]').isDisabled()), 'dugme Sačuvaj je isključeno dok nema količine');
        for (let i = 0; i < 3; i++) { await page.locator('[data-delta="1"]').click(); }
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '3', '+1 tri puta = 3');
        let zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('3 palete × 270 = 810 kom'), 'zbir paleta: ' + zbir);
        tvrdi((await page.locator('[data-sacuvaj]').innerText()).includes('810 kom'), 'dugme prikazuje ukupno komada');
        tvrdi(!(await page.locator('[data-sacuvaj]').isDisabled()), 'dugme Sačuvaj je uključeno');
        await page.locator('[data-delta="5"]').click();
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '8', '+5 paleta = 8');
        await page.locator('[data-delta="-5"]').click();
        await page.locator('[data-delta="-1"]').click();
        await page.locator('[data-delta="-1"]').click();
        await page.locator('[data-delta="-1"]').click();
        await page.locator('[data-delta="-1"]').click();
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '', 'količina ne ide ispod nule');
        tvrdi((await page.locator('[data-sacuvaj]').isDisabled()), 'bez količine dugme je opet isključeno');

        // komadi: −10 / −1 / +1 / +10 i upis rukom
        await page.locator('[data-nacin-vrednost="komadi"]').click();
        tvrdi((await page.locator('[data-delta="10"]').innerText()) === '+10', 'u komadima dugme je +10');
        await page.locator('[data-delta="10"]').click();
        await page.locator('[data-delta="10"]').click();
        await page.locator('[data-delta="1"]').click();
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '21', '+10 +10 +1 = 21');
        await page.locator('[data-delta="-10"]').click();
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '11', '−10 = 11');
        await page.fill('[name="kolicina"]', '');
        await page.locator('[name="kolicina"]').pressSequentially('0a7b5');
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '75', 'slova i vodeće nule se izbacuju: ' + await page.locator('[name="kolicina"]').inputValue());
        zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('75 kom'), 'zbir u komadima: ' + zbir);
        await p.slika('12-radnik-kolicina-komadi');

        // vrati na palete i sačuvaj 2 palete
        await page.locator('[data-nacin-vrednost="palete"]').click();
        await page.fill('[name="kolicina"]', '2');
        await page.locator('[data-sacuvaj]').click();
        await page.waitForSelector('.poruka-uspeh');
        const poruka = await page.locator('.poruka-uspeh').innerText();
        tvrdi(poruka.includes('Humovit · 10 l') && poruka.includes('540 kom (2 palete)'), 'poruka o čuvanju: ' + poruka);
        tvrdi((await page.locator('.lista li').count()) === 1, 'jedan unos u listi');
        tvrdi((await page.locator('[data-artikal][aria-pressed="true"]').innerText()).startsWith('Humovit'), 'posle čuvanja isti artikal ostaje izabran');
        tvrdi((await page.locator('[data-sku-polje]').inputValue()) !== '', 'i isto pakovanje ostaje izabrano');
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '', 'količina je prazna za sledeći unos');
        await p.slika('13-radnik-posle-cuvanja');

        // malč: boja pa jedino pakovanje automatski
        await page.locator('[data-artikal]', { hasText: /^Malč Farmerkop$/ }).click();
        tvrdi((await page.locator('[data-naslov-varijante]').textContent()) === 'Boja', 'za malč se traži Boja');
        tvrdi((await page.locator('[data-varijante] .izbor-dugme').count()) === 7, '7 boja');
        await page.locator('[data-varijante] .izbor-dugme', { hasText: 'Narandžasti' }).click();
        tvrdi(await page.locator('[data-korak="kolicina"]').isVisible(), 'jedino pakovanje (50 l) se bira samo');
        await p.slika('14-radnik-malc');

        // oblutak
        await page.locator('[data-artikal]', { hasText: /^Beli oblutak$/ }).click();
        tvrdi((await page.locator('[data-naslov-varijante]').textContent()) === 'Granulacija', 'za oblutak se traži Granulacija');
        await page.locator('[data-varijante] .izbor-dugme', { hasText: '4-7 cm (krupnija)' }).click();
        tvrdi((await page.locator('[data-pakovanja] .izbor-dugme').innerText()).includes('20 kg'), 'pakovanje oblutka je 20 kg');
        await page.fill('[name="kolicina"]', '2');
        zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('2 palete × 50 = 100 kom'), 'oblutak: 2 palete × 50: ' + zbir);

        // Idea 10 l = 225
        await page.locator('[data-artikal]', { hasText: /^Idea/ }).click();
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: '10 l' }).click();
        await page.fill('[name="kolicina"]', '1');
        zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('1 paleta × 225 = 225 kom'), 'Idea 10 l: 225 po paleti: ' + zbir);

        // Brisanje poslednjeg unosa
        tvrdi((await page.locator('form[data-potvrda] button').count()) === 1, 'postoji dugme Obriši');
        page.once('dialog', (d) => d.accept());
        await page.locator('form[data-potvrda] button').click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('obrisan'), 'unos je obrisan');
        tvrdi((await page.locator('.lista li').count()) === 0, 'lista je prazna posle brisanja');
    },

    async kucna(page, p) {
        await p.prijavaRadnik('Jelena', '4321');
        await page.goto(p.base + '/radnik/prodaja.php');
        await p.slika('20-kucna-prodaja');
        await page.locator('[data-artikal]', { hasText: /^Humovit$/ }).click();
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^5 l$/ }).click();
        await page.fill('[name="kolicina"]', '999');
        tvrdi((await page.locator('[data-zbir]').innerText()).includes('Previše'), 'za 999 paleta piše da je previše');
        tvrdi(await page.locator('[data-sacuvaj]').isDisabled(), 'za prevelik broj dugme ostaje isključeno');
        await p.slika('21-previse');
        await page.locator('[data-nacin-vrednost="komadi"]').click();
        await page.fill('[name="kolicina"]', '999');
        await page.fill('#napomena', 'komšija, gotovina');
        await page.locator('[data-sacuvaj]').click();
        await page.waitForSelector('.poruka-greska');
        const g = await page.locator('.poruka-greska').innerText();
        tvrdi(g.includes('Nema dovoljno na stanju'), 'prodaja bez zaliha je odbijena: ' + g);
        await p.slika('22-kucna-prodaja-nema-zalihe');
    },

    async admin(page, p) {
        await p.prijavaAdmin('vlasnik', 'Tajna-lozinka-1');
        await p.slika('30-admin-stanje');
        // sklapanje kartice artikla
        const prvi = page.locator('details.artikal-kartica').first();
        await prvi.locator('summary').click();
        tvrdi(!(await prvi.getAttribute('open')) !== undefined, 'kartica se sklapa');
        await prvi.locator('summary').click();
        await page.goto(p.base + '/admin/stanje.php?nisko=1');
        tvrdi((await page.locator('.sku-red.nisko').count()) === 2, 'dva reda ispod minimuma');
        await p.slika('31-admin-nisko', false);

        await page.goto(p.base + '/admin/prodaja.php');
        await p.slika('32-admin-prodaja');
        await page.locator('[data-kupac]', { hasText: 'Agrocentar Novi Sad' }).click();
        tvrdi((await page.locator('#kupac').inputValue()) === 'Agrocentar Novi Sad', 'dugme sa kupcem popunjava polje');
        await page.locator('[data-artikal]', { hasText: /^Humovit$/ }).click();
        tvrdi((await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^5 l/ }).innerText()).includes('na stanju: 780'), 'uz pakovanje piše stanje');
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^5 l/ }).click();
        await page.locator('[data-nacin-vrednost="komadi"]').click();
        await page.fill('[name="kolicina"]', '100');
        await p.slika('33-admin-prodaja-izbor', false);
        await page.locator('[data-sacuvaj]').click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('Agrocentar Novi Sad'), 'poruka o prodaji');
        tvrdi((await page.locator('#kupac').inputValue()) === 'Agrocentar Novi Sad', 'kupac ostaje za sledeću stavku');
        await p.slika('34-admin-prodaja-posle');
    },

    async istorija(page, p) {
        await p.prijavaAdmin('vlasnik', 'Tajna-lozinka-1');
        await page.goto(p.base + '/admin/istorija.php');
        tvrdi((await page.locator('.lista li').count()) >= 14, 'istorija prikazuje demo unose');
        await p.slika('40-istorija');
        await page.locator('.cip', { hasText: /^Prodaja$/ }).click();
        tvrdi(page.url().includes('tip=prodaja'), 'čip Prodaja menja filter');
        tvrdi((await page.locator('.lista li').count()) === 2, 'dve prodaje (admin), kućna se ne računa');
        await page.locator('a.btn', { hasText: 'Ispravi' }).first().click();
        await page.waitForURL('**/admin/unos.php**');
        tvrdi((await page.locator('[data-sku-polje]').inputValue()) !== '', 'ispravka: artikal je unapred izabran');
        tvrdi((await page.locator('#kupac').inputValue()) !== '', 'ispravka: kupac je upisan');
        await p.slika('41-ispravka');
        await page.locator('[data-nacin-vrednost="komadi"]').click();
        await page.fill('[name="kolicina"]', '7');
        await page.locator('[data-sacuvaj]').click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('Izmena je sačuvana'), 'izmena sačuvana');
        tvrdi(page.url().includes('/admin/istorija.php'), 'posle izmene se vraća na istoriju (sa istim filterom)');
        tvrdi(page.url().includes('tip=prodaja'), 'filter ostaje: ' + page.url());
        tvrdi((await page.locator('.znacka', { hasText: 'izmenjeno ×1' }).count()) === 1, 'oznaka izmenjeno ×1');
        await page.locator('.znacka', { hasText: 'izmenjeno' }).click();
        await page.waitForURL('**/admin/unos.php**');
        tvrdi((await page.locator('.promene li').first().innerText()).includes('→'), 'prikazana je istorija izmena');
        await p.slika('42-ispravka-istorija');
        // brisanje i vraćanje
        await page.goBack();
        page.once('dialog', (d) => d.accept());
        await page.locator('form[data-potvrda] button', { hasText: 'Obriši' }).first().click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.lista li').count()) === 1, 'posle brisanja ostaje jedna prodaja');
        await page.goto(p.base + '/admin/istorija.php?tip=prodaja&obrisani=1');
        tvrdi((await page.locator('.unos-obrisan').count()) === 1, 'obrisani unos je precrtan');
        await p.slika('43-istorija-obrisani');
        await page.locator('button', { hasText: 'Vrati unos' }).click();
        await page.waitForSelector('.poruka-uspeh');
        await page.goto(p.base + '/admin/dnevnik.php');
        tvrdi((await page.locator('.lista > li').count()) === 3, 'dnevnik: izmena, brisanje, vraćanje');
        await p.slika('44-dnevnik');
    },

    async podesavanja(page, p) {
        await p.prijavaAdmin('vlasnik', 'Tajna-lozinka-1');
        await page.goto(p.base + '/admin/radnici.php');
        await p.slika('50-radnici');
        const novoIme = 'Nikola K. ' + p.tema[0];
        await page.fill('#novo-ime', novoIme);
        await page.locator('[data-slucajni-pin="novi-pin"]').click();
        const pin = await page.locator('#novi-pin').inputValue();
        tvrdi(/^\d{4}$/.test(pin), 'slučajan PIN ima 4 cifre: ' + pin);
        tvrdi(!/^(\d)\1{3}$/.test(pin) && !['0123', '1234', '2345', '3456', '4567', '5678', '6789', '3210', '4321', '5432', '6543', '7654', '8765', '9876'].includes(pin), 'slučajan PIN nije lak: ' + pin);
        await page.getByRole('button', { name: 'Dodaj radnika' }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.radnik-kartica', { hasText: novoIme }).count()) === 1, 'novi radnik je u listi');
        await p.slika('51-radnici-posle-dodavanja', false);

        await page.goto(p.base + '/admin/podesavanja.php');
        await p.slika('52-podesavanja');
        await page.goto(p.base + '/admin/artikli.php');
        await p.slika('53-artikli');

        // Malč Farmerkop: prepiši vrednosti na sve boje
        await page.locator('.artikal-red', { hasText: 'Malč Farmerkop' }).getByRole('link', { name: 'Uredi' }).click();
        await page.waitForURL('**/admin/artikal.php**');
        await p.slika('54-artikal-malc');
        const grupe = page.locator('[data-grupa]');
        tvrdi((await grupe.count()) === 7, '7 grupa (po boji)');
        await grupe.nth(0).locator('[data-polje="min"]').first().fill('15');
        await grupe.nth(0).locator('[data-polje="po"]').first().fill('42');
        await page.locator('[data-kopiraj-sve]').click();
        tvrdi((await grupe.nth(6).locator('[data-polje="min"]').first().inputValue()) === '15', 'minimum je prepisan na poslednju boju');
        tvrdi((await grupe.nth(3).locator('[data-polje="po"]').first().inputValue()) === '42', 'paleta je prepisana na sve boje');
        await page.getByRole('button', { name: 'Sačuvaj paletu i minimum' }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('[data-grupa]').nth(5).locator('[data-polje="min"]').first().inputValue()) === '15', 'sačuvano za sve boje');

        // Pakovanja
        await page.goto(p.base + '/admin/pakovanja.php');
        await p.slika('55-pakovanja');

        // Popis
        await page.goto(p.base + '/admin/popis.php');
        await p.slika('56-popis', false);
        const humRed = page.locator('.popis-red', { hasText: '10 l' }).first();
        await humRed.locator('input').fill('300');
        await page.fill('#napomena', 'Popis u pogonu');
        page.once('dialog', (d) => d.accept());
        await page.getByRole('button', { name: 'Sačuvaj popis' }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('→ 300'), 'popis: ' + await page.locator('.poruka-uspeh').innerText());
        await p.slika('57-popis-posle', false);

        // Šifra
        await page.goto(p.base + '/admin/sifra.php');
        await p.slika('58-sifra', false);
    },
};
