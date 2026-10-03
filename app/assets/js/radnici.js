/* Radnici: dugme "Slučajan PIN" (4 cifre koje nisu previše lake). */
(function () {
    'use strict';

    function slab(pin) {
        if (/^(\d)\1{3}$/.test(pin)) { return true; }
        var raste = true, pada = true;
        for (var i = 1; i < 4; i++) {
            var a = parseInt(pin[i - 1], 10), b = parseInt(pin[i], 10);
            if (b !== a + 1) { raste = false; }
            if (b !== a - 1) { pada = false; }
        }
        return raste || pada;
    }
    function slucajan() {
        var pin;
        do {
            var n = new Uint16Array(1);
            window.crypto.getRandomValues(n);
            pin = String(n[0] % 10000);
            while (pin.length < 4) { pin = '0' + pin; }
        } while (slab(pin));
        return pin;
    }
    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-slucajni-pin]') : null;
        if (!d) { return; }
        var polje = document.getElementById(d.getAttribute('data-slucajni-pin'));
        if (polje) { polje.value = slucajan(); polje.focus(); }
    });
})();
