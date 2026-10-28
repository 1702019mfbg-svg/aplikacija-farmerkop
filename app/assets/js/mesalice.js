/* Mešalice (radnik): −1 / +1 menja broj (od 1 do najviše), a dodir na broj ga označi pa se odmah kuca novi. */
(function () {
    'use strict';
    var polje = document.getElementById('broj');
    if (!polje) { return; }
    var najvise = parseInt(polje.getAttribute('data-max'), 10) || 99;
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-mesalice-delta]') : null;
        if (!b) { return; }
        var v = parseInt(polje.value.replace(/\D+/g, ''), 10) || 0;
        polje.value = String(Math.min(najvise, Math.max(1, v + parseInt(b.getAttribute('data-mesalice-delta'), 10))));
    });
    polje.addEventListener('focus', function () { polje.select(); });
})();
