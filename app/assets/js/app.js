/* Farmerkop – zajednička skripta: tema, service worker, potvrde, zaštita od dvostrukog slanja. */
(function () {
    'use strict';

    var skripta = document.currentScript;
    var swUrl = skripta ? skripta.getAttribute('data-sw') : null;
    var scope = skripta ? skripta.getAttribute('data-scope') : null;

    /* ── Tema: automatski → tamna → svetla → automatski ── */
    function citajTemu() {
        try { var t = localStorage.getItem('fk_tema'); return (t === 'dark' || t === 'light') ? t : 'auto'; } catch (e) { return 'auto'; }
    }
    function primeniTemu(t) {
        var koren = document.documentElement;
        if (t === 'auto') { koren.removeAttribute('data-tema'); } else { koren.setAttribute('data-tema', t); }
        try { if (t === 'auto') { localStorage.removeItem('fk_tema'); } else { localStorage.setItem('fk_tema', t); } } catch (e) { /* ignoriši */ }
        var boja = (t === 'dark') ? '#1b1713' : (t === 'light' ? '#5c3d2e' : null);
        var metas = document.querySelectorAll('meta[name="theme-color"]');
        for (var i = 0; i < metas.length; i++) {
            if (!metas[i].hasAttribute('data-original')) {
                metas[i].setAttribute('data-original', metas[i].getAttribute('content'));
            }
            metas[i].setAttribute('content', boja || metas[i].getAttribute('data-original'));
        }
    }
    var NAZIV_TEME = { auto: 'automatska', dark: 'tamna', light: 'svetla' };
    var SLEDECA = { auto: 'dark', dark: 'light', light: 'auto' };
    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-tema]') : null;
        if (!d) { return; }
        var nova = SLEDECA[citajTemu()];
        primeniTemu(nova);
        d.setAttribute('title', 'Tema: ' + NAZIV_TEME[nova]);
    });
    if (citajTemu() !== 'auto') { primeniTemu(citajTemu()); }

    /* ── Potvrda pre opasne radnje: <form data-potvrda="Pitanje?"> ── */
    document.addEventListener('submit', function (e) {
        var forma = e.target;
        var pitanje = (e.submitter && e.submitter.getAttribute && e.submitter.getAttribute('data-potvrda'))
            || (forma.getAttribute && forma.getAttribute('data-potvrda'));
        if (pitanje && !window.confirm(pitanje)) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        /* Zaštita od dvostrukog dodira: drugo slanje iste forme se ignoriše nekoliko sekundi. */
        if ((forma.method || '').toLowerCase() === 'post') {
            if (forma.getAttribute('data-poslato') === '1') {
                e.preventDefault();
                return;
            }
            forma.setAttribute('data-poslato', '1');
            forma.classList.add('salje');
            window.setTimeout(function () {
                forma.removeAttribute('data-poslato');
                forma.classList.remove('salje');
            }, 6000);
        }
    }, true);

    /* ── Service worker (PWA) ── */
    if ('serviceWorker' in navigator && swUrl) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl, { scope: scope || '/' }).catch(function () { /* nije kritično */ });
        });
    }

    /* ── Dugme "Dodaj na početni ekran" (Android/Chrome) ── */
    var odlozeno = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        odlozeno = e;
        var d = document.querySelector('[data-instaliraj]');
        if (d) { d.hidden = false; }
    });
    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-instaliraj]') : null;
        if (!d || !odlozeno) { return; }
        odlozeno.prompt();
        odlozeno.userChoice.then(function () { odlozeno = null; d.hidden = true; });
    });
    window.addEventListener('appinstalled', function () {
        var d = document.querySelector('[data-instaliraj]');
        if (d) { d.hidden = true; }
    });
})();
