/* Spisak za utovar: unos artikla kako piše na spisku (H50L, B oblutak 4/6) ili nazivom ("oblutak beli 40-60"),
 * zbir paleta + paketa + komada uživo i prikaz samo onih polja koja artikal ima. U zbirnoj paleti se upisuje količina
 * za jednu paletu (bez celih paleta), a zbir pokazuje i ukupno × broj paleta. Zbirna paleta odjednom: redovi
 * artikal + koliko na paleti (25, 1/2, 3 pak). */
(function () {
    'use strict';
    function broj(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function vrednost(polje) {
        if (!polje || polje.closest('[hidden]')) { return 0; }
        var v = polje.value.replace(/\D+/g, '');
        if (v !== polje.value) { polje.value = v; }
        return v === '' ? 0 : parseInt(v, 10);
    }

    /* ── Artikli za unos: [kako piše na spisku, naziv, id, po paleti, po paketu, stanje] ── */
    function velika(t) {
        return t.toUpperCase().replace(/[ČĆ]/g, 'C').replace(/Ž/g, 'Z').replace(/Š/g, 'S').replace(/Đ/g, 'DJ');
    }
    function samoSlova(t) { return velika(t).replace(/[^A-Z0-9]+/g, ''); }
    function reci(t) { return velika(t).replace(/[^A-Z0-9]+/g, ' ').trim().split(' '); }
    var artikli = [];
    var podaci = document.getElementById('sku-podaci');
    if (podaci) {
        try {
            artikli = JSON.parse(podaci.textContent).map(function (r) {
                return { sifra: r[0], naziv: r[1], id: r[2], po: r[3], pp: r[4], stanje: r.length > 5 ? r[5] : null,
                         k: samoSlova(r[0]), s: samoSlova(r[1]), w: reci(r[1] + ' ' + r[0]) };
            });
        } catch (e) { artikli = []; }
    }
    function nadji(kljuc, t) {
        if (!t) { return null; }
        var isti = artikli.filter(function (a) { return a[kljuc] === t; });
        if (isti.length === 1) { return isti; }
        var pocinje = artikli.filter(function (a) { return a[kljuc] !== '' && a[kljuc].indexOf(t) === 0; });
        if (pocinje.length > 1) {
            var saJedinicom = pocinje.filter(function (a) { return a[kljuc] === t + 'L' || a[kljuc] === t + 'KG'; });
            return saJedinicom.length === 1 ? saJedinicom : pocinje;
        }
        return pocinje;
    }
    /* Reč odgovara: početak reči ("obl"), isti koren od 4 slova ("sitna" – SITNIJA) ili milimetri ("40" – 4 cm). */
    function recOdgovara(w, n) {
        if (n.indexOf(w) === 0) { return true; }
        if (w.length >= 4 && !/^\d+$/.test(w) && n.slice(0, 4) === w.slice(0, 4)) { return true; }
        return /^\d+0$/.test(w) && w.length >= 2 && n === w.slice(0, -1);
    }
    /* Isti redosled kao na serveru: kako piše na spisku ("b oblutak 4/6"), početak toga ("h50" → H50L),
     * naziv, pa reči u bilo kom redu ("rizla bela sitna", "oblutak beli 40-60"). */
    function pronadji(tekst) {
        var w = reci(tekst);
        var poRecima = w.length > 1 && w[0] !== '' ? artikli.filter(function (a) {
            return w.every(function (rec) { return a.w.some(function (n) { return recOdgovara(rec, n); }); });
        }) : null;
        var probe = [nadji('k', samoSlova(tekst)), nadji('s', samoSlova(tekst)), poRecima];
        for (var i = 0; i < probe.length; i++) {
            if (probe[i] && probe[i].length === 1) { return { artikal: probe[i][0] }; }
            if (probe[i] && probe[i].length > 1) { return { vise: probe[i] }; }
        }
        return null;
    }
    /* Ispod polja: koji je artikal prepoznat (ili da nije). */
    function prikaziIme(polje, nadjeno, dodatak) {
        var red = polje.closest('[data-zbirna-red]') || polje.closest('.red-polja') || polje.parentNode;
        var ime = red ? red.querySelector('[data-artikal-ime]') : null;
        if (!ime) { return; }
        var greska = false;
        var t = '';
        if (polje.value.trim() === '') {
            t = '';
        } else if (nadjeno && nadjeno.artikal) {
            t = '= ' + nadjeno.artikal.naziv + (dodatak ? ' – ' + dodatak.tekst : '');
            greska = !!(dodatak && dodatak.greska);
        } else if (nadjeno && nadjeno.vise) {
            t = 'Nije jasno – da li ste mislili: ' + nadjeno.vise.slice(0, 5).map(function (a) { return a.sifra || a.naziv; }).join(', ') + '?';
            greska = true;
        } else {
            t = 'Nema takvog artikla.';
            greska = true;
        }
        ime.textContent = t;
        ime.classList.toggle('tekst-crveno', greska);
    }

    function zbirPolje(red) {
        var s = red.nextElementSibling;
        return s && s.hasAttribute('data-zbir') ? s : red.querySelector('[data-zbir]');
    }
    function preracunaj(red) {
        var zbir = zbirPolje(red);
        if (!zbir) { return; }
        var po = parseInt(red.getAttribute('data-po'), 10) || 0;
        var pp = parseInt(red.getAttribute('data-pp'), 10) || 0;
        var sada = parseInt(red.getAttribute('data-sada'), 10) || 0;
        var paleta = parseInt(red.getAttribute('data-broj'), 10) || 1;
        var pal = vrednost(red.querySelector('[data-p="pal"]'));
        var pak = vrednost(red.querySelector('[data-p="pak"]'));
        var kom = vrednost(red.querySelector('[data-p="kom"]'));
        var ukupno = pal * po + pak * pp + kom;
        if (!ukupno) { zbir.textContent = ''; return; }
        var tekst;
        if (paleta > 1) {
            tekst = '= ' + broj(ukupno) + ' kom na paleti × ' + paleta + ' = ' + broj(ukupno * paleta) + ' kom';
            ukupno = ukupno * paleta;
        } else {
            tekst = '= ' + broj(ukupno) + ' kom';
            if (po > 0) { tekst += ' (≈ ' + String(Math.round(ukupno / po * 10) / 10).replace('.', ',') + ' pal)'; }
        }
        var nova = red.closest('[data-nova-stavka]') && red.getAttribute('data-ima-stanje') === '1';
        if (nova) { tekst += ' · na stanju ' + broj(sada); }
        zbir.textContent = tekst;
        zbir.className = 'popis-zbir' + (nova && ukupno > sada ? ' tekst-crveno' : '');
    }

    /* Forma za jedan artikal: prepoznat artikal određuje polja (palete / paketi / komadi) i zbir. */
    function osvezi(okvir) {
        var unos = okvir.querySelector('[data-artikal]');
        var red = okvir.querySelector('[data-popis]');
        if (!unos || !red) { return; }
        var nadjeno = unos.value.trim() !== '' ? pronadji(unos.value) : null;
        var a = nadjeno && nadjeno.artikal ? nadjeno.artikal : null;
        prikaziIme(unos, nadjeno, null);
        var odr = okvir.querySelector('[data-odrediste]');
        var uPaleti = odr && odr.value !== '0';
        var oOdr = uPaleti ? odr.options[odr.selectedIndex] : null;
        var po = a && !uPaleti ? a.po : 0;                 // u zbirnu paletu ne idu cele palete
        red.setAttribute('data-po', String(po));
        red.setAttribute('data-pp', String(a ? a.pp : 0));
        red.setAttribute('data-broj', oOdr ? (oOdr.getAttribute('data-broj') || '1') : '1');
        red.setAttribute('data-sada', String(a && a.stanje !== null ? a.stanje : 0));
        red.setAttribute('data-ima-stanje', a && a.stanje !== null ? '1' : '0');
        var pal = okvir.querySelector('[data-polje-pal]');
        var pak = okvir.querySelector('[data-polje-pak]');
        // dok artikal nije prepoznat, vide se sva polja (ništa ne skače dok se kuca)
        pal.hidden = uPaleti || (a !== null && a.po === 0);
        pak.hidden = a !== null && a.pp === 0;
        if (pal.hidden) { pal.querySelector('input').value = ''; }
        if (pak.hidden) { pak.querySelector('input').value = ''; }
        var pomoc = okvir.querySelector('[data-pomoc-zbirna]');
        if (pomoc) { pomoc.hidden = !uPaleti; }
        preracunaj(red);
    }

    /* Zbirna paleta: koliko na jednoj paleti – 25 (komada), 1/2 (deo palete) ili 3 pak (paketa). */
    function naPaleti(t, a) {
        t = t.toLowerCase().replace(/\s+/g, '');
        if (t === 'pola' || t === '½') { t = '1/2'; }
        if (t === '') { return null; }
        if (/^\d+$/.test(t)) { return { tekst: broj(parseInt(t, 10)) + ' kom' }; }
        var m = /^(\d{1,3})\/(\d{1,3})$/.exec(t);
        if (m) {
            var x = parseInt(m[1], 10);
            var y = parseInt(m[2], 10);
            if (x < 1 || y < 1 || x > y) { return { tekst: 'deo palete se piše kao 1/2, 1/3 ili 3/4', greska: true }; }
            if (!a.po) { return { tekst: 'nije podešeno koliko ide na paletu – upišite komade', greska: true }; }
            return { tekst: m[1] + '/' + m[2] + ' palete = ' + broj(Math.round(a.po * x / y)) + ' kom' };
        }
        m = /^(\d{1,4})(p|pak|paket|paketa)$/.exec(t);
        if (m) {
            if (!a.pp) { return { tekst: 'ovaj artikal nema pakete – upišite komade', greska: true }; }
            return { tekst: m[1] + ' pak = ' + broj(parseInt(m[1], 10) * a.pp) + ' kom' };
        }
        return { tekst: 'upišite 25, 1/2 ili 3 pak', greska: true };
    }
    function osveziZbirniRed(red) {
        var unos = red.querySelector('[data-artikal]');
        var kol = red.querySelector('[data-zbirna-kol]');
        var nadjeno = unos.value.trim() !== '' ? pronadji(unos.value) : null;
        var dodatak = nadjeno && nadjeno.artikal && kol ? naPaleti(kol.value, nadjeno.artikal) : null;
        prikaziIme(unos, nadjeno, dodatak);
    }

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.closest) { return; }
        var zr = t.closest('[data-zbirna-red]');
        if (zr) { osveziZbirniRed(zr); return; }
        if (t.hasAttribute('data-artikal')) { osvezi(t.closest('[data-dodavanje]') || t.closest('form')); return; }
        var red = t.closest('[data-popis]');
        if (red) { preracunaj(red); }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.hasAttribute || !(t.hasAttribute('data-artikal') || t.hasAttribute('data-odrediste'))) { return; }
        var zr = t.closest('[data-zbirna-red]');
        if (zr) { osveziZbirniRed(zr); return; }
        osvezi(t.closest('[data-dodavanje]') || t.closest('form'));
    });
    var okviri = document.querySelectorAll('[data-dodavanje]');
    for (var i = 0; i < okviri.length; i++) { osvezi(okviri[i]); }
    var zbirni = document.querySelectorAll('[data-zbirna-red]');
    for (var z = 0; z < zbirni.length; z++) { osveziZbirniRed(zbirni[z]); }

    /* Zbirna paleta: još jedan red (artikal + količina). */
    var jos = document.querySelectorAll('[data-zbirna-jos]');
    for (var j = 0; j < jos.length; j++) {
        jos[j].hidden = false;
        jos[j].addEventListener('click', function () {
            var redovi = this.closest('form').querySelector('[data-zbirna-redovi]');
            var svi = redovi.querySelectorAll('[data-zbirna-red]');
            if (svi.length >= 20) { return; }
            var novi = svi[svi.length - 1].cloneNode(true);
            var polja = novi.querySelectorAll('input');
            for (var k = 0; k < polja.length; k++) {
                polja[k].value = '';
                polja[k].placeholder = '';
                polja[k].setAttribute('aria-label', polja[k].getAttribute('aria-label').replace(/\d+$/, String(svi.length + 1)));
            }
            novi.querySelector('[data-artikal-ime]').textContent = '';
            redovi.appendChild(novi);
            polja[0].focus();
        });
    }

    /* Posle dodavanja stranica se otvara kod forme (#dodaj-12, #mesto-12): poruka ide tamo, da se vidi. */
    var cilj = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    var mestoPoruke = cilj ? cilj.querySelector('[data-poruke]') : null;
    var glavni = document.querySelector('main');
    if (mestoPoruke && glavni) {
        var el = glavni.firstElementChild;
        var pomereno = false;
        while (el && el.classList.contains('poruka')) {
            var sledeci = el.nextElementSibling;
            mestoPoruke.appendChild(el);
            pomereno = true;
            el = sledeci;
        }
        if (cilj.tagName === 'DETAILS') { cilj.open = true; }
        if (pomereno) { cilj.scrollIntoView({ block: 'start' }); }
    }

    /* Broj mesta: dodir označi ceo broj, pa se samo upiše novi. */
    document.addEventListener('focusin', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-oznaci')) { e.target.select(); }
    });

    /* Nesačuvane izmene (količine, mesta, palete): upozorenje pre napuštanja stranice. */
    var izmene = document.getElementById('izmene');
    if (izmene) {
        var promenjeno = false;
        document.addEventListener('input', function (e) {
            if (e.target.form === izmene) { promenjeno = true; }
        });
        window.addEventListener('submit', function (e) {
            if (e.target === izmene && !e.defaultPrevented) { promenjeno = false; }
        });
        window.addEventListener('beforeunload', function (e) {
            if (promenjeno) { e.preventDefault(); e.returnValue = ''; }
        });
    }
})();
