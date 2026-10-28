/* Kartica „Danas“ na početnoj osvežava se sama: kad se aplikacija ponovo otvori i na svaka 2 minuta dok je ekran upaljen. */
(function () {
    'use strict';
    var kartica = document.querySelector('[data-danas]');
    if (!kartica || !window.fetch) { return; }
    var adresa = kartica.getAttribute('data-osvezi');
    var poslednje = Date.now();
    function osvezi() {
        poslednje = Date.now();
        fetch(adresa, { credentials: 'same-origin' })
            .then(function (r) { return r.ok && r.headers.get('X-Farmerkop-Deo') === 'danas' ? r.text() : null; })
            .then(function (html) {
                var stara = document.querySelector('[data-danas]');
                if (!html || !stara) { return; }
                var t = document.createElement('div');
                t.innerHTML = html;
                var nova = t.querySelector('[data-danas]');
                if (nova) {
                    nova.open = stara.open;
                    stara.replaceWith(nova);
                }
            })
            .catch(function () { /* bez veze – ostaje kako je */ });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - poslednje > 60000) { osvezi(); }
    });
    window.setInterval(function () {
        if (document.visibilityState === 'visible') { osvezi(); }
    }, 120000);
})();
