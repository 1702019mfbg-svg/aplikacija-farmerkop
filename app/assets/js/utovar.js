/* Potvrda utovara: polja za drugačiju količinu se uključuju tek kad se otvore; brojač štikliranih stavki. */
(function () {
    'use strict';
    var forma = document.querySelector('[data-utovar]');
    if (!forma) { return; }
    function brojac() {
        var svi = forma.querySelectorAll('[data-cek]');
        var ok = forma.querySelectorAll('[data-cek]:checked');
        var b = forma.querySelector('[data-brojac]');
        if (b) { b.textContent = 'Štiklirano ' + ok.length + ' od ' + svi.length; }
        var d = forma.querySelector('[data-potvrdi]');
        if (d) { d.classList.toggle('btn-ugaseno', ok.length !== svi.length); }
    }
    forma.addEventListener('toggle', function (e) {
        var det = e.target;
        if (!det.classList || !det.classList.contains('utovar-izmena')) { return; }
        var polja = det.querySelectorAll('input');
        for (var i = 0; i < polja.length; i++) { polja[i].disabled = !det.open; }
        if (det.open && polja.length) { polja[polja.length - 1].focus(); }
    }, true);
    /* Zbirna paleta: jedan kvadratić štiklira (ili odštiklira) sve artikle na toj paleti. */
    function grupa(g) {
        var linije = forma.querySelectorAll('[data-cek][data-grupa="' + g + '"]');
        var ok = 0;
        for (var i = 0; i < linije.length; i++) { if (linije[i].checked) { ok++; } }
        var gr = forma.querySelector('[data-cek-grupa="' + g + '"]');
        if (gr) { gr.checked = ok === linije.length && ok > 0; gr.indeterminate = ok > 0 && ok < linije.length; }
    }
    forma.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-cek-grupa')) {
            var linije = forma.querySelectorAll('[data-cek][data-grupa="' + t.getAttribute('data-cek-grupa') + '"]');
            for (var i = 0; i < linije.length; i++) { linije[i].checked = t.checked; }
        } else if (t.hasAttribute && t.hasAttribute('data-grupa')) {
            grupa(t.getAttribute('data-grupa'));
        }
        brojac();
    });
    var grupe = forma.querySelectorAll('[data-cek-grupa]');
    for (var g = 0; g < grupe.length; g++) { grupa(grupe[g].getAttribute('data-cek-grupa')); }
    // na prozoru (pre opšte potvrde iz app.js), da se prvo proveri da je sve štiklirano
    window.addEventListener('submit', function (e) {
        if (e.target !== forma) { return; }
        if (e.submitter && e.submitter.hasAttribute('data-dodaj')) { return; }   // dodavanje artikla nije potvrda
        var svi = forma.querySelectorAll('[data-cek]');
        var ok = forma.querySelectorAll('[data-cek]:checked');
        if (ok.length !== svi.length) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.alert('Štiklirajte svaku stavku kad je utovarena (' + ok.length + ' od ' + svi.length + '). Ako nešto nije utovareno, otvorite „Više ili manje od plana“ i upišite 0.');
        }
    }, true);
    brojac();
})();
