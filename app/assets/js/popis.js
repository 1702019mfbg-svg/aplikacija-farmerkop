/* Popis: ukupno komada iz paleta + paketa + komada i razlika prema trenutnom stanju. */
(function () {
    'use strict';
    function broj(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function brojIz(polje) {
        if (!polje) { return null; }
        var v = polje.value.replace(/\D+/g, '');
        if (v !== polje.value) { polje.value = v; }
        return v === '' ? null : parseInt(v, 10);
    }
    document.addEventListener('input', function (e) {
        var red = e.target.closest ? e.target.closest('[data-popis]') : null;
        if (!red) { return; }
        var po = parseInt(red.getAttribute('data-po'), 10) || 0;
        var pp = parseInt(red.getAttribute('data-pp'), 10) || 0;
        var sada = parseInt(red.getAttribute('data-sada'), 10) || 0;
        var pal = brojIz(red.querySelector('[data-p="pal"]'));
        var pak = brojIz(red.querySelector('[data-p="pak"]'));
        var kom = brojIz(red.querySelector('[data-p="kom"]'));
        var zbir = red.querySelector('[data-zbir]');
        if (pal === null && pak === null && kom === null) { zbir.textContent = ''; return; }
        var ukupno = (pal || 0) * po + (pak || 0) * pp + (kom || 0);
        var razlika = ukupno - sada;
        var znak = razlika > 0 ? '+' : (razlika < 0 ? '−' : '±');
        zbir.textContent = '= ' + broj(ukupno) + ' kom  (' + (razlika === 0 ? 'isto kao sada' : znak + broj(Math.abs(razlika)) + ' prema sadašnjem stanju') + ')';
        zbir.className = 'popis-zbir' + (razlika === 0 ? '' : ' razlika');
    });
})();
