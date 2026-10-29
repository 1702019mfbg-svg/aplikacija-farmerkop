/* Spisak za utovar: izbor artikla kucanjem ("humovit" → dugmad 5 l / 10 l / 25 l / 50 l, dodir izabere),
 * više artikala odjednom (uvek jedan prazan red na kraju), zbir paleta + paketa + komada uživo i prikaz samo onih
 * polja koja artikal ima. Naziv kog nema u katalogu ("kora") u redovima robe je ostalo: žuta napomena i samo
 * komadi. U zbirnoj paleti se upisuje količina za jednu paletu (bez celih paleta), a zbir pokazuje
 * i ukupno × broj paleta. Zbirna paleta odjednom: redovi artikal + koliko na paleti (25, 1/2, 3 pak). */
(function () {
    'use strict';
    function broj(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function vrednost(polje) {
        if (!polje || polje.closest('[hidden]')) { return 0; }
        var v = polje.value.replace(/\D+/g, '');
        if (v !== polje.value) { polje.value = v; }
        return v === '' ? 0 : parseInt(v, 10);
    }

    /* ── Artikli: [id, artikal, varijanta, pakovanje, po paleti, po paketu, stanje (samo administrator)] ── */
    function ascii(t) {
        return t.toUpperCase().replace(/[ČĆ]/g, 'C').replace(/Ž/g, 'Z').replace(/Š/g, 'S').replace(/Đ/g, 'DJ')
            .replace(/[^A-Z0-9]+/g, ' ').trim();
    }
    function reci(t) { var a = ascii(t); return a === '' ? [] : a.split(' '); }
    var artikli = [];
    var poId = {};
    var podaci = document.getElementById('sku-podaci');
    if (podaci) {
        try {
            artikli = JSON.parse(podaci.textContent).map(function (r, i) {
                var a = { id: r[0], artikal: r[1], varijanta: r[2], pakovanje: r[3], po: r[4], pp: r[5],
                          stanje: r.length > 6 ? r[6] : null, red: i };
                a.naziv = a.artikal + (a.varijanta ? ' · ' + a.varijanta : '') + ' · ' + a.pakovanje;
                a.w = reci(a.artikal + ' ' + a.varijanta + ' ' + a.pakovanje);
                poId[a.id] = a;
                return a;
            });
        } catch (e) { artikli = []; }
    }

    /* Isto kao na serveru (katalog.php): 0 = ista reč, 1 = početak reči, isti koren ("sitna" – SITNIJA)
     * ili milimetri ("40" – 4 cm), null = ne poklapa se. */
    function poklapanje(w, n) {
        if (w === n) { return 0; }
        if (/^\d+$/.test(w)) {
            if (w.length >= 2 && w.slice(-1) === '0' && n === w.slice(0, -1)) { return 1; }
            return /^\d+$/.test(n) && n.indexOf(w) === 0 ? 1 : null;
        }
        if (n.indexOf(w) === 0 || (w.length >= 4 && n.slice(0, 4) === w.slice(0, 4))) { return 1; }
        return null;
    }
    var JEDINICE = { L: 1, KG: 1, G: 1, CM: 1, MM: 1 };
    /* Svaka otkucana reč mora da postoji u nazivu; manja ocena = bolje (najbolji prvi, pa redom kao u katalogu). */
    function pronadji(tekst) {
        var w = reci(tekst);
        if (!w.length) { return []; }
        var rez = [];
        artikli.forEach(function (a) {
            var ocena = 0;
            var pokrivene = {};
            for (var i = 0; i < w.length; i++) {
                var najbolje = null;
                for (var j = 0; j < a.w.length; j++) {
                    var p = poklapanje(w[i], a.w[j]);
                    if (p !== null && (najbolje === null || p < najbolje[0])) { najbolje = [p, j]; }
                }
                if (najbolje === null) { return; }
                ocena += najbolje[0] * 0.5;
                pokrivene[najbolje[1]] = true;
            }
            a.w.forEach(function (n, j) {
                if (!pokrivene[j] && !/^\d+$/.test(n) && !JEDINICE[n]) { ocena += 1; }
            });
            rez.push({ a: a, ocena: ocena });
        });
        rez.sort(function (x, y) { return x.ocena - y.ocena || x.a.red - y.a.red; });
        return rez;
    }
    /* Jedan najbolji artikal (kao na serveru: otkucano se prihvata i bez dodira) ili null. */
    function jedini(nadjeni) {
        return nadjeni.length && (nadjeni.length === 1 || nadjeni[1].ocena > nadjeni[0].ocena) ? nadjeni[0].a : null;
    }

    /* ── Polje za izbor artikla (data-biraj) ── */
    function delovi(okvir) {
        return {
            tekst: okvir.querySelector('[data-biraj-tekst]'),
            id: okvir.querySelector('[data-biraj-id]'),
            status: okvir.querySelector('[data-biraj-status]'),
            ponuda: okvir.querySelector('[data-biraj-ponuda]')
        };
    }
    function izabrani(okvir) {
        return poId[delovi(okvir).id.value] || null;
    }
    /* Dugmad po artiklu: Humovit [5 l][10 l][25 l][50 l], Beli oblutak [1-3 cm][2-4 cm] … */
    function nacrtajPonudu(d, nadjeni, istaknut) {
        var grupe = [];
        var poArtiklu = {};
        nadjeni.slice(0, 40).forEach(function (n) {
            var g = poArtiklu[n.a.artikal];
            if (!g) {
                if (grupe.length >= 6) { return; }
                g = poArtiklu[n.a.artikal] = { ime: n.a.artikal, clanovi: [] };
                grupe.push(g);
            }
            g.clanovi.push(n.a);
        });
        d.ponuda.textContent = '';
        grupe.forEach(function (g) {
            g.clanovi.sort(function (x, y) { return x.red - y.red; });
            var istoPak = g.clanovi.every(function (a) { return a.pakovanje === g.clanovi[0].pakovanje; });
            var istaVar = g.clanovi.every(function (a) { return a.varijanta === g.clanovi[0].varijanta; });
            var red = document.createElement('div');
            red.className = 'biraj-grupa';
            var ime = document.createElement('span');
            ime.className = 'biraj-ime';
            ime.textContent = g.ime + (istaVar && g.clanovi[0].varijanta ? ' · ' + g.clanovi[0].varijanta : '');
            red.appendChild(ime);
            var dugmad = document.createElement('span');
            dugmad.className = 'biraj-dugmad';
            g.clanovi.forEach(function (a) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'biraj-dugme' + (istaknut === a ? ' izabran' : '');
                b.setAttribute('data-biraj-sku', String(a.id));
                b.setAttribute('aria-pressed', istaknut === a ? 'true' : 'false');
                b.setAttribute('aria-label', a.naziv);
                b.textContent = istaVar ? a.pakovanje : (istoPak ? a.varijanta : a.varijanta + ' · ' + a.pakovanje);
                dugmad.appendChild(b);
            });
            red.appendChild(dugmad);
            d.ponuda.appendChild(red);
        });
        d.ponuda.hidden = grupe.length === 0;
    }
    /* vrsta: '' = obično, 'greska' = crveno, 'ostalo' = žuta napomena (nije u katalogu) */
    function postaviStatus(d, tekst, vrsta) {
        d.status.textContent = tekst;
        d.status.classList.toggle('tekst-crveno', vrsta === 'greska');
        d.status.classList.toggle('biraj-napomena', vrsta === 'ostalo');
    }
    /* Broj komada upisan u naziv robe van kataloga ("5 komada kesa", "kese 5") – kao kolicina_iz_naziva() u PHP-u. */
    var KOM = '(?:kom(?:ad[ai]?)?|kpl)\\.?';
    var KOL_NA_POCETKU = new RegExp('^(\\d{1,6})\\s*(?:x\\s*)?(?:' + KOM + ')?\\s+(\\S.*)$', 'i');
    var KOL_NA_KRAJU = new RegExp('^(.*?\\S)(?:\\s*[-=:*]|\\s+[x×])?\\s*(\\d{1,6})\\s*(?:' + KOM + ')?$', 'i');
    function kolicinaIzNaziva(tekst) {
        var t = tekst.replace(/\s+/g, ' ').trim();
        var m = KOL_NA_POCETKU.exec(t);
        var naziv, br;
        if (m && !/^(?:pal|plt|pak)/i.test(m[2])) { naziv = m[2]; br = m[1]; }
        else if ((m = KOL_NA_KRAJU.exec(t))) { naziv = m[1]; br = m[2]; }
        else { return null; }
        return parseInt(br, 10) > 0 && /[A-Z]{2}/.test(ascii(naziv)) ? { naziv: naziv, kom: parseInt(br, 10) } : null;
    }
    /* Polje u redu robe (data-biraj-ostalo), naziv sa slovom, a takvog artikla nema – ide kao ostalo. */
    function vanKataloga(okvir, t, nadjeni) {
        return okvir.hasAttribute('data-biraj-ostalo') && !nadjeni.length && artikli.length > 0 && t.length >= 2 && /[A-Z]/.test(ascii(t));
    }
    /* Posle kucanja: ponuda pakovanja i (kad je samo jedno najbolje) izabran artikal.
     * javi: false = bez osvežavanja reda (početak), 'uvek' = osveži red i kad je id isti. */
    function osveziBiraj(okvir, javi) {
        var d = delovi(okvir);
        var t = d.tekst.value.trim();
        var pre = d.id.value;
        var preVan = okvir.classList.contains('biraj-van');
        var van = false;
        var a = poId[d.id.value];
        if (a && t === a.naziv) {
            d.ponuda.hidden = true;
            d.ponuda.textContent = '';
            postaviStatus(d, '', '');
        } else if (t === '') {
            d.id.value = '';
            d.ponuda.hidden = true;
            d.ponuda.textContent = '';
            postaviStatus(d, '', '');
        } else {
            var nadjeni = pronadji(t);
            var jedan = jedini(nadjeni);
            d.id.value = jedan ? String(jedan.id) : '';
            van = vanKataloga(okvir, t, nadjeni);
            if (van) {
                // "humovit 15": nema ga, ali ponudi Humovit (možda je greška u kucanju); "5 komada kesa": traži se "kesa"
                var w = reci(t);
                var k = kolicinaIzNaziva(t);
                var slicni = k ? pronadji(k.naziv) : (w.length > 1 ? pronadji(w[0]) : []);
                nacrtajPonudu(d, slicni, null);
                postaviStatus(d, 'Nema u katalogu – ide na spisak kao ostalo (samo komadi, bez stanja).'
                    + (slicni.length ? ' Ako ste mislili na artikal iz kataloga, dodirnite ga:' : ''), 'ostalo');
            } else {
                nacrtajPonudu(d, nadjeni, jedan);
                if (!nadjeni.length) {
                    var ostaloPolje = okvir.hasAttribute('data-biraj-ostalo');
                    postaviStatus(d, artikli.length && (!ostaloPolje || t.length >= 2) ? 'Nema takvog artikla – proverite naziv.' : '', 'greska');
                } else if (jedan) {
                    postaviStatus(d, '✓ ' + jedan.naziv + (nadjeni.length > 1 ? ' (ili dodirnite drugo)' : ''), '');
                } else {
                    postaviStatus(d, 'Dodirnite pakovanje:', '');
                }
            }
        }
        okvir.classList.toggle('biraj-van', van);
        if (javi === 'uvek' || (javi !== false && (pre !== d.id.value || preVan !== van))) { promenaArtikla(okvir); }
    }
    /* Sledeće polje za upis posle izbora (palete, paketi, komadi ili "na paleti"). */
    function sledecePolje(polje) {
        var forma = polje.form;
        if (!forma) { return null; }
        var sva = forma.querySelectorAll('input:not([type="hidden"]), select, textarea');
        var posle = false;
        for (var i = 0; i < sva.length; i++) {
            if (posle && !sva[i].closest('[hidden]') && !sva[i].disabled) { return sva[i]; }
            if (sva[i] === polje) { posle = true; }
        }
        return null;
    }
    function izaberi(okvir, a) {
        var d = delovi(okvir);
        d.tekst.value = a.naziv;
        d.id.value = String(a.id);
        osveziBiraj(okvir, 'uvek');
        var sledece = sledecePolje(d.tekst);
        if (sledece) { sledece.focus(); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-biraj-sku]') : null;
        if (!b) { return; }
        var a = poId[b.getAttribute('data-biraj-sku')];
        if (a) { izaberi(b.closest('[data-biraj]'), a); }
    });
    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (!t.hasAttribute || !t.hasAttribute('data-biraj-tekst')) { return; }
        var okvir = t.closest('[data-biraj]');
        var d = delovi(okvir);
        if (e.key === 'ArrowDown' && !d.ponuda.hidden) {
            var prvo = d.ponuda.querySelector('.izabran') || d.ponuda.querySelector('button');
            if (prvo) { e.preventDefault(); prvo.focus(); }
            return;
        }
        if (e.key !== 'Enter' || t.value.trim() === '') { return; }
        e.preventDefault();                       // Enter u polju artikla bira, ne šalje formu
        var a = izabrani(okvir);
        if (a) { izaberi(okvir, a); return; }
        if (okvir.classList.contains('biraj-van')) {         // ostalo ("kora"): dalje na komade
            var sledece = sledecePolje(t);
            if (sledece) { sledece.focus(); }
            return;
        }
        var dugme = d.ponuda.querySelector('button');
        if (dugme) { dugme.focus(); }
    });
    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (!t.hasAttribute) { return; }
        if (t.hasAttribute('data-oznaci')) { t.select(); return; }      // broj mesta: označi ceo broj
        // izabran artikal: dodir označi ceo naziv, pa se samo kuca novi
        if (t.hasAttribute('data-biraj-tekst')) {
            var a = izabrani(t.closest('[data-biraj]'));
            if (a && t.value === a.naziv) { t.select(); }
        }
    });

    /* ── Količina: palete / paketi / komadi i zbir ── */
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
        if (!ukupno) {
            zbir.textContent = '';
            zbir.className = 'popis-zbir';
            ostaloIzNaziva(red, zbir);
            return;
        }
        var tekst;
        if (paleta > 1) {
            tekst = '= ' + broj(ukupno) + ' kom na paleti × ' + paleta + ' = ' + broj(ukupno * paleta) + ' kom';
            ukupno = ukupno * paleta;
        } else {
            tekst = '= ' + broj(ukupno) + ' kom';
            if (po > 0) { tekst += ' (≈ ' + String(Math.round(ukupno / po * 10) / 10).replace('.', ',') + ' pal)'; }
        }
        var saStanjem = red.getAttribute('data-ima-stanje') === '1';
        if (saStanjem) { tekst += ' · na stanju ' + broj(sada); }
        zbir.textContent = tekst;
        zbir.className = 'popis-zbir' + (saStanjem && ukupno > sada ? ' tekst-crveno' : '');
    }
    /* Roba van kataloga, polja za količinu prazna, a broj je u nazivu: "= 5 kom · ostalo „Kesa“" (tako se i čuva). */
    function ostaloIzNaziva(red, zbir) {
        var okvir = red.querySelector('[data-biraj]');
        var polje = red.querySelector('[data-biraj-tekst]');
        if (!okvir || !polje || !okvir.classList.contains('biraj-van')) { return; }
        var kol = red.querySelectorAll('[data-p]');
        for (var i = 0; i < kol.length; i++) { if (kol[i].value.trim() !== '') { return; } }
        var k = kolicinaIzNaziva(polje.value);
        if (!k) { return; }
        if (pronadji(k.naziv).length) {
            zbir.textContent = 'Dodirnite pakovanje gore, pa upišite količinu u polja.';
            zbir.className = 'popis-zbir tekst-crveno';
            return;
        }
        zbir.textContent = '= ' + broj(k.kom) + ' kom · ostalo „' + k.naziv.charAt(0).toUpperCase() + k.naziv.slice(1) + '“';
    }
    /* Red robe (ili forma radnika): izabran artikal određuje polja (palete / paketi / komadi) i zbir. */
    function popisZa(okvir) {
        var red = okvir.closest('[data-roba-red]') || okvir.closest('[data-dodavanje]');
        if (!red) { return null; }
        return red.hasAttribute('data-popis') ? red : red.querySelector('[data-popis]');
    }
    function osveziRed(red) {
        var okvir = red.closest('[data-roba-red]') || red.closest('[data-dodavanje]') || red;
        var biraj = okvir.querySelector('[data-biraj]');
        var a = biraj ? izabrani(biraj) : null;
        var van = !!(biraj && biraj.classList.contains('biraj-van'));      // ostalo: samo komadi
        var forma = red.closest('form') || red;
        var odr = forma.querySelector('[data-odrediste]');
        var uPaleti = !!(odr && odr.value !== '0' && odr.value !== '');
        var oOdr = uPaleti ? odr.options[odr.selectedIndex] : null;
        red.setAttribute('data-po', String(a && !uPaleti ? a.po : 0));     // u zbirnu paletu ne idu cele palete
        red.setAttribute('data-pp', String(a ? a.pp : 0));
        red.setAttribute('data-broj', oOdr ? (oOdr.getAttribute('data-broj') || '1') : '1');
        red.setAttribute('data-sada', String(a && a.stanje !== null ? a.stanje : 0));
        red.setAttribute('data-ima-stanje', a && a.stanje !== null ? '1' : '0');
        var pal = okvir.querySelector('[data-polje-pal]');
        var pak = okvir.querySelector('[data-polje-pak]');
        // dok artikal nije izabran, vide se sva polja (ništa ne skače dok se kuca); za ostalo samo komadi
        // (upisane palete / pakete ne briše – ostaju vidljive, pa čuvanje kaže šta da se ispravi)
        if (pal) {
            pal.hidden = uPaleti || (a !== null && !a.po) || (van && pal.querySelector('input').value === '');
            if (pal.hidden) { pal.querySelector('input').value = ''; }
        }
        if (pak) {
            pak.hidden = (a !== null && !a.pp) || (van && pak.querySelector('input').value === '');
            if (pak.hidden) { pak.querySelector('input').value = ''; }
        }
        var pomoc = forma.querySelector('[data-pomoc-zbirna]');
        if (pomoc) { pomoc.hidden = !uPaleti; }
        preracunaj(red);
    }

    /* ── Zbirna paleta: koliko na jednoj paleti – 25 (komada), 1/2 (deo palete) ili 3 pak (paketa) ── */
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
        var info = red.querySelector('[data-zbirna-info]');
        var kol = red.querySelector('[data-zbirna-kol]');
        var biraj = red.querySelector('[data-biraj]');
        if (!info || !kol || !biraj) { return; }
        var a = izabrani(biraj);
        var r = a ? naPaleti(kol.value, a) : null;
        info.textContent = r ? '= ' + r.tekst : '';
        info.classList.toggle('tekst-crveno', !!(r && r.greska));
    }

    function promenaArtikla(okvir) {
        var zr = okvir.closest('[data-zbirna-red]');
        if (zr) { osveziZbirniRed(zr); return; }
        var red = popisZa(okvir);
        if (red) { osveziRed(red); }
    }

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.closest) { return; }
        var redForme = t.closest('[data-roba-red], [data-zbirna-red]');
        if (redForme) { dodajPrazanRed(redForme); }
        if (t.hasAttribute('data-biraj-tekst')) {
            osveziBiraj(t.closest('[data-biraj]'));
            var rr = t.closest('[data-roba-red]');
            if (rr && rr.hasAttribute('data-popis')) { preracunaj(rr); }     // i "5 komada kesa" dok se kuca
            return;
        }
        var zr = t.closest('[data-zbirna-red]');
        if (zr) { osveziZbirniRed(zr); return; }
        var red = t.closest('[data-popis]');
        if (red) { preracunaj(red); }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-papir-preskoci')) {     // spisak sa papira: preskočen red bledi
            t.closest('.papir-red').classList.toggle('papir-preskocen', t.checked);
            return;
        }
        if (!t.hasAttribute || !t.hasAttribute('data-odrediste')) { return; }
        var redovi = t.form.querySelectorAll('[data-popis]');
        for (var i = 0; i < redovi.length; i++) { osveziRed(redovi[i]); }
    });

    /* Početno stanje (i posle neuspelog čuvanja, kad upisano ostane u formi). */
    var birajSvi = document.querySelectorAll('[data-biraj]');
    for (var b = 0; b < birajSvi.length; b++) { osveziBiraj(birajSvi[b], false); }
    var popisi = document.querySelectorAll('[data-roba-red], [data-dodavanje] [data-popis]');
    for (var p = 0; p < popisi.length; p++) { osveziRed(popisi[p]); }
    var zbirni = document.querySelectorAll('[data-zbirna-red]');
    for (var z = 0; z < zbirni.length; z++) { osveziZbirniRed(zbirni[z]); }

    /* Uvek jedan prazan red na kraju: čim se u poslednji red nešto upiše, ispod se sam doda novi prazan
     * (roba za mesto do 30, zbirna paleta do 20). Samo u formama "Dodaj robu" i "Zbirna paleta" (ne u pregledu sa
     * papira). Kursor ostaje gde se kuca. */
    function prazanRed(red) {
        var polja = red.querySelectorAll('input:not([type="hidden"])');
        for (var i = 0; i < polja.length; i++) { if (polja[i].value.trim() !== '') { return false; } }
        return true;
    }
    function dodajPrazanRed(red) {
        var roba = red.hasAttribute('data-roba-red');
        var redovi = red.parentElement;
        if (!redovi || !redovi.hasAttribute(roba ? 'data-roba-redovi' : 'data-zbirna-redovi')) { return; }
        var svi = redovi.querySelectorAll(roba ? '[data-roba-red]' : '[data-zbirna-red]');
        if (svi[svi.length - 1] !== red || svi.length >= (roba ? 30 : 20) || prazanRed(red)) { return; }
        var novi = red.cloneNode(true);
        var n = svi.length + 1;
        var polja = novi.querySelectorAll('input');
        for (var k = 0; k < polja.length; k++) {
            polja[k].value = '';
            if (polja[k].type !== 'hidden') { polja[k].placeholder = polja[k].hasAttribute('data-p') ? '0' : ''; }
            var al = polja[k].getAttribute('aria-label');
            if (al) { polja[k].setAttribute('aria-label', al.replace(/\d+$/, String(n))); }
        }
        var skriveni = novi.querySelectorAll('[data-polje-pal], [data-polje-pak]');
        for (var h = 0; h < skriveni.length; h++) { skriveni[h].hidden = false; }
        var prazni = novi.querySelectorAll('[data-biraj-status], [data-zbir], [data-zbirna-info]');
        for (var q = 0; q < prazni.length; q++) { prazni[q].textContent = ''; prazni[q].classList.remove('tekst-crveno', 'biraj-napomena'); }
        var biraj = novi.querySelector('[data-biraj]');
        if (biraj) { biraj.classList.remove('biraj-van'); }
        var ponuda = novi.querySelector('[data-biraj-ponuda]');
        if (ponuda) { ponuda.textContent = ''; ponuda.hidden = true; }
        var rb = novi.querySelector('.roba-broj');
        if (rb) { rb.textContent = String(n); }
        redovi.appendChild(novi);
        if (roba) { osveziRed(novi); }
    }

    /* Mesto se skuplja i otvara dodirom na broj ili ime (roba je u <details class="mesto-telo">). */
    function oznaciMesto(telo) {
        var krug = document.querySelector('.mesto-krug[aria-controls="' + telo.id + '"]');
        if (krug) { krug.setAttribute('aria-expanded', telo.open ? 'true' : 'false'); }
    }
    document.addEventListener('click', function (e) {
        var p = e.target.closest ? e.target.closest('[data-mesto-prekidac]') : null;
        if (!p) { return; }
        var telo = document.getElementById(p.getAttribute('data-mesto-prekidac'));
        if (!telo) { return; }
        telo.open = !telo.open;
        oznaciMesto(telo);                                   // odmah (događaj "toggle" stiže kasnije)
    });
    document.addEventListener('toggle', function (e) {      // otvaranje dodirom na red sa zbirom
        if (e.target.classList && e.target.classList.contains('mesto-telo')) { oznaciMesto(e.target); }
    }, true);

    /* Posle dodavanja stranica se otvara kod forme (#dodaj-12, #mesto-12): poruka ide tamo, da se vidi. */
    var cilj = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    var teloCilja = cilj && cilj.closest ? cilj.closest('.mesto-telo') : null;
    if (teloCilja) { teloCilja.open = true; }
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

    /* Nesačuvane izmene (količine, mesta, palete, upisana roba): upozorenje pre napuštanja stranice. */
    var izmene = document.getElementById('izmene');
    function prati(forma) {
        return !!forma && (forma === izmene || (forma.matches && forma.matches('form[data-dodavanje], form[data-zbirna], form[data-papir]')));
    }
    var promenjeno = false;
    document.addEventListener('input', function (e) {
        if (prati(e.target.form)) { promenjeno = true; }
    });
    window.addEventListener('submit', function (e) {
        if (prati(e.target) && !e.defaultPrevented) { promenjeno = false; }
    });
    window.addEventListener('beforeunload', function (e) {
        if (promenjeno) { e.preventDefault(); e.returnValue = ''; }
    });
})();
