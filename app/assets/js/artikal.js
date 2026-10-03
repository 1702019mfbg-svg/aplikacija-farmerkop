/* Artikal: "Prepiši vrednosti iz prvog na sve ostale" (paleta i minimum po pakovanju). */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-kopiraj-sve]') : null;
        if (!d) { return; }
        var grupe = document.querySelectorAll('[data-grupa]');
        if (grupe.length < 2) { return; }
        var izvor = {};
        var prvi = grupe[0].querySelectorAll('[data-pak]');
        for (var i = 0; i < prvi.length; i++) {
            izvor[prvi[i].getAttribute('data-pak')] = {
                po: prvi[i].querySelector('[data-polje="po"]').value,
                min: prvi[i].querySelector('[data-polje="min"]').value
            };
        }
        for (var g = 1; g < grupe.length; g++) {
            var redovi = grupe[g].querySelectorAll('[data-pak]');
            for (var r = 0; r < redovi.length; r++) {
                var v = izvor[redovi[r].getAttribute('data-pak')];
                if (v) {
                    redovi[r].querySelector('[data-polje="po"]').value = v.po;
                    redovi[r].querySelector('[data-polje="min"]').value = v.min;
                }
            }
            grupe[g].open = true;
        }
    });
})();
