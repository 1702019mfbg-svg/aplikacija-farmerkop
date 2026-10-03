/* Prodaja: brzi izbor ranijih kupaca. */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-kupac]') : null;
        if (!d) { return; }
        var polje = document.getElementById('kupac');
        if (polje) {
            polje.value = d.getAttribute('data-kupac');
            var cipovi = document.querySelectorAll('[data-kupac]');
            for (var i = 0; i < cipovi.length; i++) { cipovi[i].setAttribute('aria-pressed', cipovi[i] === d ? 'true' : 'false'); }
        }
    });
})();
