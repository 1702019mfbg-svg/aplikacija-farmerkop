/* Prijava radnika: krupna tastatura za PIN od 4 cifre. Bez skripte radi obično polje za unos. */
(function () {
    'use strict';
    var forma = document.getElementById('forma-radnik');
    if (!forma) { return; }
    var pin = forma.querySelector('#pin');
    var tacke = forma.querySelectorAll('.pin-prikaz span');
    if (!pin) { return; }

    forma.classList.add('pin-js');

    function osvezi() {
        for (var i = 0; i < tacke.length; i++) {
            tacke[i].classList.toggle('pun', i < pin.value.length);
        }
    }
    function izabranoIme() {
        return forma.querySelector('input[name="radnik_id"]:checked');
    }
    function posalji() {
        if (pin.value.length === 4 && izabranoIme()) {
            if (forma.requestSubmit) { forma.requestSubmit(); } else { forma.submit(); }
        }
    }
    function dodaj(cifra) {
        if (pin.value.length < 4) {
            pin.value += cifra;
            osvezi();
            posalji();
        }
    }

    forma.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-cifra],[data-akcija]') : null;
        if (!d) { return; }
        e.preventDefault();
        if (d.hasAttribute('data-cifra')) {
            dodaj(d.getAttribute('data-cifra'));
        } else if (d.getAttribute('data-akcija') === 'brisi') {
            pin.value = pin.value.slice(0, -1);
            osvezi();
        } else if (d.getAttribute('data-akcija') === 'ocisti') {
            pin.value = '';
            osvezi();
        }
    });

    forma.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'radnik_id') { posalji(); }
    });

    /* Tastatura računara */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) { return; }
        if (/^[0-9]$/.test(e.key)) { dodaj(e.key); }
        else if (e.key === 'Backspace') { pin.value = pin.value.slice(0, -1); osvezi(); }
    });

    osvezi();
})();
