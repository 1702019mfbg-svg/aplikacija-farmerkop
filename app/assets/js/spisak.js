/* Spisak za utovar: zbir paleta + paketa + komada uživo i prikaz samo onih polja koja artikal ima.
 * U zbirnoj paleti se upisuje količina za jednu paletu (bez celih paleta), a zbir pokazuje i ukupno × broj paleta.
 * Roba se dodaje po mestima istovara (forma u kartici svakog mesta). */
(function () {
    'use strict';
    function broj(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function vrednost(polje) {
        if (!polje || polje.closest('[hidden]')) { return 0; }
        var v = polje.value.replace(/\D+/g, '');
        if (v !== polje.value) { polje.value = v; }
        return v === '' ? 0 : parseInt(v, 10);
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
        if (red.closest('[data-nova-stavka]')) { tekst += ' · na stanju ' + broj(sada); }
        zbir.textContent = tekst;
        zbir.className = 'popis-zbir' + (red.closest('[data-nova-stavka]') && ukupno > sada ? ' tekst-crveno' : '');
    }

    /* Izbor artikla (i odredišta – posebna stavka ili zbirna paleta) u formi za dodavanje. */
    function osvezi(okvir) {
        var sel = okvir.querySelector('[data-izbor-sku]');
        var red = okvir.querySelector('[data-popis]');
        if (!sel || !red) { return; }
        var o = sel.options[sel.selectedIndex];
        var po = o ? (o.getAttribute('data-po') || '0') : '0';
        var pp = o ? (o.getAttribute('data-pp') || '0') : '0';
        var odr = okvir.querySelector('[data-odrediste]');
        var uPaleti = odr && odr.value !== '0';
        var oOdr = uPaleti ? odr.options[odr.selectedIndex] : null;
        if (uPaleti) { po = '0'; }                          // u zbirnu paletu ne idu cele palete
        red.setAttribute('data-po', po);
        red.setAttribute('data-pp', pp);
        red.setAttribute('data-broj', oOdr ? (oOdr.getAttribute('data-broj') || '1') : '1');
        red.setAttribute('data-sada', o ? (o.getAttribute('data-sada') || '0') : '0');
        var pal = okvir.querySelector('[data-polje-pal]');
        var pak = okvir.querySelector('[data-polje-pak]');
        pal.hidden = po === '0';
        pak.hidden = pp === '0';
        if (pal.hidden) { pal.querySelector('input').value = ''; }
        if (pak.hidden) { pak.querySelector('input').value = ''; }
        var pomoc = okvir.querySelector('[data-pomoc-zbirna]');
        if (pomoc) { pomoc.hidden = !uPaleti; }
        preracunaj(red);
    }

    document.addEventListener('input', function (e) {
        var red = e.target.closest ? e.target.closest('[data-popis]') : null;
        if (red) { preracunaj(red); }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.hasAttribute || !(t.hasAttribute('data-izbor-sku') || t.hasAttribute('data-odrediste'))) { return; }
        osvezi(t.closest('[data-dodavanje]') || t.closest('form'));
    });
    var okviri = document.querySelectorAll('[data-dodavanje]');
    for (var i = 0; i < okviri.length; i++) { osvezi(okviri[i]); }

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
