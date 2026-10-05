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
        await page.getByRole('button', { name: 'Sačuvaj pakete, palete i minimum' }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('[data-grupa]').nth(5).locator('[data-polje="min"]').first().inputValue()) === '15', 'sačuvano za sve boje');

        // Pakovanja
        await page.goto(p.base + '/admin/pakovanja.php');
        await p.slika('55-pakovanja');

        // Popis
        await page.goto(p.base + '/admin/popis.php');
        await p.slika('56-popis', false);
        const humRed = page.locator('.popis-red', { hasText: '10 l' }).first();
        tvrdi((await humRed.locator('input').count()) === 3, 'Humovit 10 l u popisu ima polja za palete, pakete i komade');
        await humRed.locator('[data-p="pal"]').fill('1');
        await humRed.locator('[data-p="pak"]').fill('2');
        await humRed.locator('[data-p="kom"]').fill('3');
        const popisZbir = (await humRed.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(popisZbir.includes('= 285 kom'), '1 paleta (270) + 2 paketa (12) + 3 komada = 285: ' + popisZbir);
        await p.slika('56b-popis-unos', false);
        await page.fill('#napomena', 'Popis u pogonu');
        page.once('dialog', (d) => d.accept());
        await page.getByRole('button', { name: 'Sačuvaj popis' }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('→ 285'), 'popis: ' + await page.locator('.poruka-uspeh').innerText());
        await p.slika('57-popis-posle', false);

        // Šifra
        await page.goto(p.base + '/admin/sifra.php');
        await p.slika('58-sifra', false);
    },

    async paketi(page, p) {
        await p.prijavaRadnik('Marko', '1234');
        await page.locator('[data-artikal]', { hasText: /^Humovit$/ }).click();
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^10 l$/ }).click();
        tvrdi((await page.locator('[data-segment] button:visible').count()) === 3, 'Humovit 10 l: tri načina unosa (Palete, Paketi, Komadi)');
        await page.locator('[data-nacin-vrednost="paketi"]').click();
        tvrdi((await page.locator('[data-nacin-vrednost="paketi"]').getAttribute('aria-pressed')) === 'true', 'Paketi su izabrani');
        await page.fill('[name="kolicina"]', '12');
        let zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('12 paketa × 6 = 72 kom'), 'zbir paketa: ' + zbir);
        await page.locator('[data-delta="10"]').click();
        tvrdi((await page.locator('[name="kolicina"]').inputValue()) === '22', 'u paketima +10 dodaje 10 paketa');
        await page.locator('[data-delta="-10"]').click();
        await p.slika('15-radnik-paketi');
        await page.locator('[data-sacuvaj]').click();
        await page.waitForSelector('.poruka-uspeh');
        const poruka = await page.locator('.poruka-uspeh').innerText();
        tvrdi(poruka.includes('72 kom (12 paketa)'), 'poruka o čuvanju: ' + poruka);
        const stavka = (await page.locator('.lista li').first().innerText()).replace(/\s+/g, ' ');
        tvrdi(stavka.includes('72 kom') && stavka.includes('12 paketa'), 'u listi: ' + stavka);
        tvrdi((await page.locator('[data-nacin-vrednost="paketi"]').getAttribute('aria-pressed')) === 'true', 'izabrani način (paketi) se pamti za sledeći unos');

        // 25 l nema paket: samo palete i komadi
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^25 l$/ }).click();
        tvrdi(!(await page.locator('[data-nacin-vrednost="paketi"]').isVisible()), 'Humovit 25 l nema dugme Paketi');
        tvrdi((await page.locator('[data-segment] button:visible').count()) === 2, 'Humovit 25 l: Palete i Komadi');
        // 50 l isto; ništa ne sme da bude neizabrano
        tvrdi((await page.locator('[data-segment] button[aria-pressed="true"]:visible').count()) === 1, 'tačno jedan način je izabran');

        // Idea 10 l: 5 komada u paketu, 225 na paleti
        await page.locator('[data-artikal]', { hasText: /^Idea/ }).click();
        await page.locator('[data-pakovanja] .izbor-dugme', { hasText: /^10 l$/ }).click();
        await page.locator('[data-nacin-vrednost="paketi"]').click();
        await page.fill('[name="kolicina"]', '45');
        zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('45 paketa × 5 = 225 kom'), 'Idea 10 l: 45 paketa = 225 kom: ' + zbir);
        await page.locator('[data-nacin-vrednost="palete"]').click();
        await page.fill('[name="kolicina"]', '1');
        zbir = (await page.locator('[data-zbir]').innerText()).replace(/\s+/g, ' ');
        tvrdi(zbir.includes('1 paleta × 225 = 225 kom'), 'Idea 10 l: 1 paleta = 225 kom: ' + zbir);
    },

    async uvoz(page, p) {
        await p.prijavaAdmin('vlasnik', 'Tajna-lozinka-1');
        await page.goto(p.base + '/admin/podesavanja.php');
        await page.getByRole('link', { name: /Uvoz stanja iz fajla/ }).click();
        await page.waitForSelector('#tekst');
        await p.slika('60-uvoz-korak1');

        const nalepi = async (tekst) => {
            await page.goto(p.base + '/admin/uvoz.php');
            await page.fill('#tekst', tekst);
            await page.getByRole('button', { name: /Učitaj/ }).click();
            await page.waitForSelector('[data-uvoz]');
        };
        await nalepi('Naziv\tStanje\nHumovit 5 l\t450\nHumovit 10 l\t270\nIdea 10 l\t225\nMalč Farmerkop Crveni 50 l\t80\nKanta 10 l\t3\n');
        tvrdi((await page.locator('[data-uvoz-red]').count()) === 5, 'pregled ima 5 redova');
        const prvi = page.locator('select[data-uvoz-sku]').first();
        tvrdi((await prvi.locator('option').count()) > 20, 'izbor artikala je popunjen skriptom: ' + await prvi.locator('option').count());
        tvrdi((await prvi.locator('option:checked').innerText()).replace(/\s+/g, ' ') === 'Humovit · 5 l', 'prvi red je uparen sa Humovit 5 l: ' + await prvi.locator('option:checked').innerText());
        const kanta = page.locator('[data-uvoz-red]', { hasText: 'Kanta 10 l' });
        tvrdi((await kanta.locator('[data-uvoz-oznaka]').innerText()) === 'nije prepoznato', 'nepoznat proizvod je označen');
        await p.slika('61-uvoz-pregled');

        // Ručni izbor: oznaka se odmah menja, a posle osvežavanja ostaje
        await kanta.locator('select').selectOption({ label: 'Idea · 5 l' });
        tvrdi((await kanta.locator('[data-uvoz-oznaka]').innerText()) === 'izabrano', 'posle izbora oznaka je „izabrano“');
        await page.getByRole('button', { name: 'Osveži pregled' }).click();
        await page.waitForSelector('[data-uvoz]');
        const kanta2 = page.locator('[data-uvoz-red]', { hasText: 'Kanta 10 l' });
        tvrdi((await kanta2.locator('select option:checked').innerText()).replace(/\s+/g, ' ') === 'Idea · 5 l', 'ručni izbor je sačuvan posle osvežavanja');
        tvrdi((await kanta2.innerText()).includes('→ posle uvoza: 3 kom'), 'red pokazuje stanje posle uvoza: ' + (await kanta2.innerText()).replace(/\s+/g, ' '));

        // Odbijena potvrda: ništa se ne uvozi
        page.once('dialog', (d) => { tvrdi(d.message().includes('Uvesti stanje') && d.message().includes('5 artikala'), 'tekst potvrde: ' + d.message()); d.dismiss(); });
        await page.getByRole('button', { name: /Uvezi stanje/ }).click();
        await page.waitForTimeout(500);
        tvrdi(page.url().includes('/admin/uvoz.php') && (await page.locator('.poruka-uspeh').count()) === 0, 'ako se odbije potvrda, uvoz se ne pokreće');
        await p.slika('62-uvoz-pre-potvrde');

        // Potvrđen uvoz
        page.once('dialog', (d) => d.accept());
        await page.getByRole('button', { name: /Uvezi stanje/ }).click();
        await page.waitForURL('**/admin/stanje.php');
        const poruka = await page.locator('.poruka-uspeh').innerText();
        tvrdi(poruka.includes('Promenjeno artikala: 4') && poruka.includes('bez razlike: 1'), 'poruka posle uvoza (Idea 10 l je već 225): ' + poruka);
        const telo = await page.locator('main').innerText();
        tvrdi(telo.includes('450') && telo.includes('225'), 'Stanje prikazuje uvezene količine');
        await p.slika('63-uvoz-stanje');

        // Preuzimanje primera i učitavanje fajla preko dugmeta za izbor fajla
        await page.goto(p.base + '/admin/uvoz.php');
        const fs = require('fs');
        const os = require('os');
        const put = os.tmpdir() + '/fk-uvoz-' + p.tema + '.csv';
        fs.writeFileSync(put, '﻿Šifra;Naziv;Stanje\r\n1001;Humovit 5 l;450\r\n1002;Humovit 10 l;300\r\n');
        await page.setInputFiles('#fajl', put);
        await page.getByRole('button', { name: /Učitaj/ }).click();
        await page.waitForSelector('[data-uvoz]');
        tvrdi((await page.locator('[data-uvoz-red]').count()) === 2, 'fajl je učitan: 2 reda');
        tvrdi((await page.locator('#kol_sifra option:checked').innerText()).startsWith('Kolona 1'), 'kolona sa šifrom je prepoznata');
        await page.getByRole('button', { name: 'Odustani od uvoza' }).evaluate((b) => b.closest('form').setAttribute('data-potvrda', ''));
        await page.getByRole('button', { name: 'Odustani od uvoza' }).click();
        await page.waitForSelector('#tekst');
        tvrdi((await page.locator('.poruka-info').innerText()).includes('Uvoz je otkazan'), 'odustajanje vraća na početak');
        fs.unlinkSync(put);
    },

    async zastite(page, p) {
        await p.prijavaAdmin('vlasnik', 'Tajna-lozinka-1');
        await page.goto(p.base + '/admin/artikli.php');

        // „Isključi“ traži potvrdu; odbijanje ne menja ništa
        const red = page.locator('.artikal-red', { hasText: 'Humovit premium' });
        page.once('dialog', (d) => { tvrdi(d.message().includes('Isključiti artikal'), 'pitanje pre isključivanja: ' + d.message()); d.dismiss(); });
        await red.getByRole('button', { name: 'Isključi' }).click();
        await page.waitForTimeout(400);
        tvrdi((await page.locator('.poruka-uspeh').count()) === 0, 'odbijena potvrda ne isključuje artikal');
        tvrdi((await page.locator('.artikal-red', { hasText: 'Humovit premium' }).getByRole('button', { name: 'Isključi' }).count()) === 1, 'artikal je i dalje uključen');

        // Humovit: ne može da se snimi bez ijednog pakovanja
        await page.locator('.artikal-red').filter({ hasText: 'Humovit' }).filter({ hasNotText: 'premium' }).first().getByRole('link', { name: 'Uredi' }).click();
        await page.waitForURL('**/admin/artikal.php**');
        const url = page.url();
        const pak = page.locator('input[name="pak[]"]');
        const n = await pak.count();
        tvrdi(n >= 5, 'ima ponuđenih pakovanja: ' + n);
        for (let i = 0; i < n; i++) { if (await pak.nth(i).isChecked()) { await pak.nth(i).uncheck(); } }
        await page.getByRole('button', { name: 'Sačuvaj pakovanja' }).click();
        await page.waitForSelector('.poruka-greska');
        tvrdi((await page.locator('.poruka-greska').innerText()).includes('bar jedno pakovanje'), 'snimanje bez pakovanja je odbijeno');
        await page.goto(url);
        tvrdi((await page.locator('[data-grupa]').count()) >= 1 && (await page.locator('.poruka-greska:has-text("ne nudi za unos")').count()) === 0, 'artikal je ostao sa pakovanjima (nema crvenog upozorenja)');
        await p.slika('64-zastita-pakovanja');

        // Dodavanje varijante artiklu bez varijanti (slučaj sa Humovitom) pa povratak na staro stanje
        await page.fill('#v-nv', 'Boja');
        await page.fill('#v-naziv', 'Plavi');
        await page.getByRole('button', { name: 'Dodaj', exact: true }).click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('[data-grupa]').count()) >= 1, 'nova varijanta dobija pakovanja odmah');
        const vrati = page.getByRole('button', { name: 'Vrati artikal na stanje bez varijanti' });
        tvrdi((await vrati.count()) === 1, 'ponuđeno je dugme „Vrati artikal na stanje bez varijanti“');
        await p.slika('65-posle-varijante');
        page.once('dialog', (d) => d.accept());
        await vrati.click();
        await page.waitForSelector('.poruka-uspeh');
        tvrdi((await page.locator('.poruka-uspeh').innerText()).includes('bez varijanti'), 'artikal je vraćen na stanje bez varijanti');
        tvrdi((await page.getByRole('button', { name: 'Vrati artikal na stanje bez varijanti' }).count()) === 0, 'dugme nestaje kad nema aktivnih varijanti');

        // Radnik i dalje vidi Humovit sa svim pakovanjima
        const radnik = await p.novaStranica(p.tema);
        await radnik.page.goto(p.base + '/login.php');
        await radnik.page.locator('label.ime', { hasText: 'Marko' }).click();
        for (const c of '1234') { await radnik.page.locator(`[data-cifra="${c}"]`).click(); }
        await radnik.page.waitForURL('**/radnik/**');
        await radnik.page.locator('[data-artikal]', { hasText: /^Humovit$/ }).click();
        tvrdi((await radnik.page.locator('[data-pakovanja] .izbor-dugme').count()) === 4, 'radnik i posle svega vidi 4 pakovanja Humovita');
        await radnik.ctx.close();
    },

    async pwa(page, p) {
        // Posrednik ispred servera: gašenjem posrednika simuliramo da je telefon izgubio vezu.
        const http = require('http');
        const cilj = new URL(p.base);
        const server = http.createServer((req, res) => {
            const preq = http.request({ host: cilj.hostname, port: cilj.port, path: req.url, method: req.method, headers: { ...req.headers, host: cilj.host } }, (pres) => {
                res.writeHead(pres.statusCode, pres.headers);
                pres.pipe(res);
            });
            preq.on('error', () => { res.statusCode = 502; res.end(); });
            req.pipe(preq);
        });
        await new Promise((r) => server.listen(0, '127.0.0.1', r));
        const baza = 'http://127.0.0.1:' + server.address().port;
        try {
            await page.goto(baza + '/login.php');
            const href = await page.locator('link[rel="manifest"]').getAttribute('href');
            const odgovor = await page.request.get(baza + href);
            tvrdi(odgovor.status() === 200, 'manifest se učitava');
            const m = await odgovor.json();
            tvrdi(m.display === 'standalone' && m.short_name === 'Farmerkop' && m.lang === 'sr-Latn', 'manifest: standalone, Farmerkop, sr-Latn');
            tvrdi(m.icons.some((i) => i.sizes === '192x192') && m.icons.some((i) => i.sizes === '512x512') && m.icons.some((i) => i.purpose === 'maskable'), 'manifest: ikone 192, 512 i maskable');
            for (const ikona of m.icons) {
                const r = await page.request.get(baza + '/' + ikona.src);
                tvrdi(r.status() === 200 && r.headers()['content-type'] === 'image/png', 'ikona se učitava: ' + ikona.src);
            }
            tvrdi(m.theme_color === '#5c3d2e' && !!m.background_color, 'boje u manifestu');

            await page.evaluate(() => navigator.serviceWorker.ready);
            const stanje = await page.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); return r && r.active ? r.active.state : null; });
            tvrdi(stanje === 'activated', 'service worker je aktivan: ' + stanje);

            const cdp = await page.context().newCDPSession(page);
            const { installabilityErrors } = await cdp.send('Page.getInstallabilityErrors');
            tvrdi(installabilityErrors.length === 0, 'instalabilna aplikacija, greške: ' + JSON.stringify(installabilityErrors));
            const man = await cdp.send('Page.getAppManifest');
            tvrdi((man.errors || []).length === 0, 'manifest bez grešaka: ' + JSON.stringify(man.errors));

            // stranica sa stilovima ide u keš dok ima veze
            await page.reload();
            await page.waitForLoadState('load');
            tvrdi(await page.evaluate(() => !!navigator.serviceWorker.controller), 'stranicom upravlja service worker');

            // veza nestaje
            server.close();
            server.closeAllConnections();
            await page.goto(baza + '/login.php').catch(() => {});
            const tekst = await page.locator('body').innerText();
            tvrdi(tekst.includes('Nema internet veze'), 'bez veze se prikazuje stranica "Nema internet veze": ' + tekst.slice(0, 80));
            tvrdi(!tekst.includes('Ko si ti'), 'bez veze se ne prikazuju stari podaci iz keša');
            await p.slika('60-nema-veze');
        } finally {
            try { server.close(); server.closeAllConnections(); } catch (e) { /* već ugašen */ }
        }
    },
};
