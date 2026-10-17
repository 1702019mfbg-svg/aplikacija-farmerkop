/* Kalkulacija – receptura: živi zbir udela (mora biti tačno 100 %) i dugme „Još jedna sirovina“. */
(function () {
    'use strict';
    var forma = document.querySelector('[data-recept]');
    if (!forma) { return; }
    var zbirEl = forma.querySelector('[data-recept-zbir]');

    /* "60", "12,5", "0.125", "1.234,5" → hiljaditi delovi procenta (60 % = 60000); null = neispravno */
    function hiljaditi(t) {
        t = t.replace(/\s|%/g, '');
        if (t === '') { return 0; }
        if (t.indexOf(',') >= 0) { t = t.replace(/\./g, '').replace(',', '.'); }
        if (!/^\d{1,3}(\.\d{1,3})?$/.test(t)) { return null; }
        return Math.round(parseFloat(t) * 1000);
    }
    function prikazi(h) {
        var s = (h / 1000).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
        return s.replace('.', ',') + ' %';
    }
    function osvezi() {
        var polja = forma.querySelectorAll('[data-udeo]');
        var zbir = 0;
        var los = false;
        var ima = false;
        for (var i = 0; i < polja.length; i++) {
            var h = hiljaditi(polja[i].value);
            if (h === null) { los = true; continue; }
            if (h > 0) { ima = true; }
            zbir += h;
        }
        if (!ima && !los) { zbirEl.textContent = ''; zbirEl.className = 'kalk-zbir'; return; }
        var tekst = 'Zbir: ' + prikazi(zbir);
        if (los) { tekst += ' – neki udeo nije broj (npr. 60 ili 0,125)'; }
        else if (zbir === 100000) { tekst += ' ✓'; }
        else if (zbir < 100000) { tekst += ' – nedostaje ' + prikazi(100000 - zbir); }
        else { tekst += ' – višak ' + prikazi(zbir - 100000); }
        zbirEl.textContent = tekst;
        zbirEl.className = 'kalk-zbir ' + (!los && zbir === 100000 ? 'kalk-zbir-ok' : 'tekst-crveno');
    }
    forma.addEventListener('input', function (e) { if (e.target.hasAttribute('data-udeo')) { osvezi(); } });
    osvezi();

    var jos = forma.querySelector('[data-recept-jos]');
    if (jos) {
        jos.hidden = false;
        jos.addEventListener('click', function () {
            var redovi = forma.querySelectorAll('[data-recept-red]');
            if (redovi.length >= 30) { return; }
            var novi = redovi[redovi.length - 1].cloneNode(true);
            var n = redovi.length + 1;
            novi.querySelector('select').value = '';
            novi.querySelector('select').setAttribute('aria-label', 'Sirovina ' + n);
            var u = novi.querySelector('[data-udeo]');
            u.value = '';
            u.setAttribute('aria-label', 'Udeo u procentima, sirovina ' + n);
            forma.querySelector('[data-recept-redovi]').appendChild(novi);
            novi.querySelector('select').focus();
        });
    }

    /* Zbir mora biti tačno 100 % pre slanja (isto proverava i server). Na prozoru, pre zaštite od
     * dvostrukog slanja iz app.js – da odbijeno slanje ne zaključa formu. */
    window.addEventListener('submit', function (e) {
        if (e.target !== forma) { return; }
        osvezi();
        if (zbirEl.textContent === '' || zbirEl.classList.contains('tekst-crveno')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.alert((zbirEl.textContent || 'Upišite sirovine i udele.') + '\n\nZbir udela mora biti tačno 100 %.');
        }
    }, true);
})();
