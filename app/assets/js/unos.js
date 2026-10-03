/* Birač artikla, varijante i pakovanja + unos količine (komadi / palete). */
(function () {
    'use strict';

    var koren = document.querySelector('[data-izbor]');
    if (!koren) { return; }

    var katalog;
    try {
        katalog = JSON.parse(koren.querySelector('script[data-katalog]').textContent);
    } catch (e) {
        return;
    }

    var forma = koren.closest('form');
    var dugme = forma ? forma.querySelector('[data-sacuvaj]') : null;
    var pokaziStanje = koren.getAttribute('data-stanje') === '1';
    var MAX = parseInt(koren.getAttribute('data-max'), 10) || 10000;

    var polje = {
        sku: koren.querySelector('[data-sku-polje]'),
        nacin: koren.querySelector('[data-nacin-polje]'),
        kolicina: koren.querySelector('[name="kolicina"]')
    };
    var el = {
        artikli: koren.querySelector('[data-artikli]'),
        korakVarijanta: koren.querySelector('[data-korak="varijanta"]'),
        naslovVarijante: koren.querySelector('[data-naslov-varijante]'),
        varijante: koren.querySelector('[data-varijante]'),
        korakPak: koren.querySelector('[data-korak="pakovanje"]'),
        pakovanja: koren.querySelector('[data-pakovanja]'),
        korakKol: koren.querySelector('[data-korak="kolicina"]'),
        zbir: koren.querySelector('[data-zbir]'),
        koraci: koren.querySelectorAll('[data-delta]'),
        nacinDugmad: koren.querySelectorAll('[data-nacin-vrednost]')
    };

    var st = { artikal: null, varijanta: 0, sku: null, nacin: 'komadi' };
    var osnovniTekstDugmeta = dugme ? dugme.textContent : '';

    /* ── pomoćne ── */
    function napravi(tag, klasa, tekst) {
        var n = document.createElement(tag);
        if (klasa) { n.className = klasa; }
        if (tekst !== undefined) { n.textContent = tekst; }
        return n;
    }
    function broj(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function paleteTekst(n) {
        var m10 = n % 10, m100 = n % 100;
        var rec = (m10 === 1 && m100 !== 11) ? 'paleta' : ((m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) ? 'palete' : 'paleta');
        return broj(n) + ' ' + rec;
    }
    function pomeri(n) {
        var smanjeno = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (n && n.scrollIntoView) { n.scrollIntoView({ behavior: smanjeno ? 'auto' : 'smooth', block: 'start' }); }
    }
    function oznaci(kontejner, selektor, uslov) {
        var d = kontejner.querySelectorAll(selektor);
        for (var i = 0; i < d.length; i++) { d[i].setAttribute('aria-pressed', uslov(d[i]) ? 'true' : 'false'); }
    }
    function cuvaj(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* nije bitno */ } }
    function citaj(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

    function kolicina() {
        var q = parseInt(polje.kolicina.value, 10);
        return isNaN(q) ? 0 : q;
    }
    function upisiKolicinu(q) {
        if (q < 0) { q = 0; }
        if (q > 999999) { q = 999999; }
        polje.kolicina.value = q > 0 ? String(q) : '';
    }
    function ukupnoKomada() {
        var q = kolicina();
        if (st.nacin === 'palete' && st.sku) { return q * st.sku.po; }
        return q;
    }

    /* ── prikaz ── */
    function osvezi() {
        var q = kolicina();
        var kom = ukupnoKomada();
        el.zbir.textContent = '';
        el.zbir.classList.remove('greska-tekst');
        if (st.sku && q > 0) {
            if (kom > MAX) {
                el.zbir.classList.add('greska-tekst');
                el.zbir.textContent = 'Previše: ' + broj(kom) + ' kom. U jednom unosu najviše ' + broj(MAX) + ' kom. Proverite broj.';
            } else if (st.nacin === 'palete') {
                el.zbir.appendChild(document.createTextNode(paleteTekst(q) + ' × ' + broj(st.sku.po) + ' = '));
                el.zbir.appendChild(napravi('span', 'velik', broj(kom) + ' kom'));
            } else {
                el.zbir.appendChild(napravi('span', 'velik', broj(kom) + ' kom'));
                if (st.sku.po > 0 && kom >= st.sku.po) {
                    var pal = Math.floor(kom / st.sku.po), ost = kom % st.sku.po;
                    el.zbir.appendChild(document.createTextNode('  = ' + pal + ' pal' + (ost ? ' + ' + broj(ost) + ' kom' : '')));
                }
            }
        }
        if (dugme) {
            dugme.disabled = !(st.sku && q > 0 && kom <= MAX);
            dugme.textContent = osnovniTekstDugmeta + (st.sku && q > 0 && kom <= MAX ? ' · ' + broj(kom) + ' kom' : '');
        }
    }

    function postaviNacin(n, zapamti) {
        st.nacin = n;
        polje.nacin.value = n;
        for (var i = 0; i < el.nacinDugmad.length; i++) {
            el.nacinDugmad[i].setAttribute('aria-pressed', el.nacinDugmad[i].getAttribute('data-nacin-vrednost') === n ? 'true' : 'false');
        }
        var delte = n === 'palete' ? [-5, -1, 1, 5] : [-10, -1, 1, 10];
        for (var j = 0; j < el.koraci.length; j++) {
            el.koraci[j].setAttribute('data-delta', String(delte[j]));
            el.koraci[j].textContent = (delte[j] > 0 ? '+' : '−') + Math.abs(delte[j]);
        }
        if (zapamti) { cuvaj('fk_nacin', n); }
        osvezi();
    }

    function podesiNacinZaSku(zeljeni) {
        var imaPaletu = st.sku && st.sku.po > 0;
        for (var i = 0; i < el.nacinDugmad.length; i++) {
            if (el.nacinDugmad[i].getAttribute('data-nacin-vrednost') === 'palete') {
                el.nacinDugmad[i].disabled = !imaPaletu;
            }
        }
        var trazeni = zeljeni || citaj('fk_nacin') || 'palete';
        postaviNacin(trazeni === 'palete' && imaPaletu ? 'palete' : 'komadi', false);
    }

    /* ── izbor ── */
    function izaberiSku(s, bezPomeranja) {
        st.sku = s;
        polje.sku.value = s.id;
        oznaci(el.pakovanja, '[data-sku-id]', function (d) { return parseInt(d.getAttribute('data-sku-id'), 10) === s.id; });
        el.korakKol.hidden = false;
        podesiNacinZaSku(null);
        osvezi();
        if (!bezPomeranja) { pomeri(el.korakKol); }
    }

    function prikaziPakovanja(a, vid, bezPomeranja) {
        el.pakovanja.textContent = '';
        var lista = a.sku.filter(function (s) { return s.v === vid; });
        lista.forEach(function (s) {
            var d = napravi('button', 'izbor-dugme' + (pokaziStanje && s.st <= 0 ? ' prazno' : ''));
            d.type = 'button';
            d.setAttribute('data-sku-id', s.id);
            d.setAttribute('aria-pressed', 'false');
            d.appendChild(document.createTextNode(s.p));
            if (pokaziStanje) { d.appendChild(napravi('small', '', 'na stanju: ' + broj(s.st))); }
            d.addEventListener('click', function () { izaberiSku(s); });
            el.pakovanja.appendChild(d);
        });
        el.korakPak.hidden = false;
        st.sku = null;
        polje.sku.value = '';
        el.korakKol.hidden = true;
        if (lista.length === 1) {
            izaberiSku(lista[0], bezPomeranja);   // jedino pakovanje – izaberi odmah
        } else if (!bezPomeranja) {
            pomeri(el.korakPak);
        }
        osvezi();
    }

    function izaberiVarijantu(a, v, bezPomeranja) {
        st.varijanta = v.id;
        oznaci(el.varijante, '[data-var-id]', function (d) { return parseInt(d.getAttribute('data-var-id'), 10) === v.id; });
        prikaziPakovanja(a, v.id, bezPomeranja);
    }

    function izaberiArtikal(a, bezPomeranja) {
        st.artikal = a;
        st.varijanta = 0;
        st.sku = null;
        polje.sku.value = '';
        oznaci(el.artikli, '[data-artikal]', function (d) { return parseInt(d.getAttribute('data-artikal'), 10) === a.id; });
        el.korakKol.hidden = true;
        el.pakovanja.textContent = '';
        el.korakPak.hidden = true;
        if (a.varijante.length) {
            el.naslovVarijante.textContent = a.nv;
            el.varijante.textContent = '';
            a.varijante.forEach(function (v) {
                var d = napravi('button', 'izbor-dugme', v.naziv);
                d.type = 'button';
                d.setAttribute('data-var-id', v.id);
                d.setAttribute('aria-pressed', 'false');
                d.addEventListener('click', function () { izaberiVarijantu(a, v); });
                el.varijante.appendChild(d);
            });
            el.korakVarijanta.hidden = false;
            if (!bezPomeranja) { pomeri(el.korakVarijanta); }
        } else {
            el.korakVarijanta.hidden = true;
            prikaziPakovanja(a, 0, bezPomeranja);
        }
        osvezi();
    }

    function prikaziArtikle() {
        el.artikli.textContent = '';
        katalog.kategorije.forEach(function (k) {
            el.artikli.appendChild(napravi('h4', 'kat-naslov', k.naziv));
            var mreza = napravi('div', 'izbor-mreza');
            k.artikli.forEach(function (a) {
                var d = napravi('button', 'izbor-dugme', a.naziv);
                d.type = 'button';
                d.setAttribute('data-artikal', a.id);
                d.setAttribute('aria-pressed', 'false');
                if (a.oznaka) { d.appendChild(napravi('span', 'znacka znacka-pl', a.oznaka)); }
                d.addEventListener('click', function () { izaberiArtikal(a); });
                mreza.appendChild(d);
            });
            el.artikli.appendChild(mreza);
        });
    }

    /* ── količina ── */
    polje.kolicina.addEventListener('input', function () {
        var ociscen = polje.kolicina.value.replace(/\D+/g, '').replace(/^0+/, '');
        if (ociscen !== polje.kolicina.value) { polje.kolicina.value = ociscen; }
        osvezi();
    });
    koren.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-delta]') : null;
        if (d) {
            upisiKolicinu(kolicina() + parseInt(d.getAttribute('data-delta'), 10));
            osvezi();
            return;
        }
        var n = e.target.closest ? e.target.closest('[data-nacin-vrednost]') : null;
        if (n && !n.disabled) { postaviNacin(n.getAttribute('data-nacin-vrednost'), true); }
    });
    if (forma) {
        forma.addEventListener('submit', function (e) {
            if (!st.sku || kolicina() <= 0) { e.preventDefault(); }
        });
    }

    /* ── pokretanje ── */
    prikaziArtikle();

    var unapred = parseInt(koren.getAttribute('data-sku'), 10) || 0;
    if (unapred > 0) {
        katalog.kategorije.some(function (k) {
            return k.artikli.some(function (a) {
                return a.sku.some(function (s) {
                    if (s.id !== unapred) { return false; }
                    izaberiArtikal(a, true);
                    if (a.varijante.length) {
                        a.varijante.some(function (v) { if (v.id === s.v) { izaberiVarijantu(a, v, true); return true; } return false; });
                    }
                    izaberiSku(s, true);
                    var q = parseInt(koren.getAttribute('data-kolicina'), 10);
                    var n = koren.getAttribute('data-nacin');
                    if (n) { podesiNacinZaSku(n); }
                    if (q > 0) { upisiKolicinu(q); }
                    osvezi();
                    return true;
                });
            });
        });
        if (polje.kolicina && st.sku && !koren.getAttribute('data-kolicina')) { pomeri(el.korakKol); }
    } else {
        postaviNacin('komadi', false);
    }
})();
