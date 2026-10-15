/* Spisak za utovar: zbir paleta + paketa + komada uživo i prikaz samo onih polja koja artikal ima. */
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
        var pal = vrednost(red.querySelector('[data-p="pal"]'));
        var pak = vrednost(red.querySelector('[data-p="pak"]'));
        var kom = vrednost(red.querySelector('[data-p="kom"]'));
        var ukupno = pal * po + pak * pp + kom;
        if (!ukupno) { zbir.textContent = ''; return; }
        var tekst = '= ' + broj(ukupno) + ' kom';
        if (po > 0) { tekst += ' (≈ ' + String(Math.round(ukupno / po * 10) / 10).replace('.', ',') + ' pal)'; }
        if (red.closest('[data-nova-stavka]')) { tekst += ' · na stanju ' + broj(sada); }
        zbir.textContent = tekst;
        zbir.className = 'popis-zbir' + (red.closest('[data-nova-stavka]') && ukupno > sada ? ' tekst-crveno' : '');
    }
    document.addEventListener('input', function (e) {
        var red = e.target.closest ? e.target.closest('[data-popis]') : null;
        if (red) { preracunaj(red); }
    });
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel.hasAttribute || !sel.hasAttribute('data-izbor-sku')) { return; }
        var forma = sel.closest('form');
        var red = forma.querySelector('[data-popis]');
        var o = sel.options[sel.selectedIndex];
        var po = o ? (o.getAttribute('data-po') || '0') : '0';
        var pp = o ? (o.getAttribute('data-pp') || '0') : '0';
        red.setAttribute('data-po', po);
        red.setAttribute('data-pp', pp);
        red.setAttribute('data-sada', o ? (o.getAttribute('data-sada') || '0') : '0');
        var pal = forma.querySelector('[data-polje-pal]');
        var pak = forma.querySelector('[data-polje-pak]');
        pal.hidden = po === '0';
        pak.hidden = pp === '0';
        if (pal.hidden) { pal.querySelector('input').value = ''; }
        if (pak.hidden) { pak.querySelector('input').value = ''; }
        preracunaj(red);
    });
    var pocetni = document.querySelector('[data-izbor-sku]');
    if (pocetni) { pocetni.dispatchEvent(new Event('change', { bubbles: true })); }
})();
