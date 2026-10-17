/* Slikaj spisak: posle izbora slike telefon je smanji (duža strana 2000 px, JPEG) – brže slanje i manje od
 * ograničenja hostinga – pa odmah pošalje. Bez JavaScript-a se šalje originalna slika dugmetom. */
(function () {
    'use strict';
    var forma = document.querySelector('[data-slika-forma]');
    var polje = forma ? forma.querySelector('[data-slika]') : null;
    if (!polje) { return; }
    var status = forma.querySelector('[data-slika-status]');
    var posalji = forma.querySelector('[data-slika-posalji]');
    var MAX = 2000;
    if (posalji) { posalji.hidden = true; }

    function javi(t) { if (status) { status.textContent = t; } }
    function slikaIz(fajl) {
        if (window.createImageBitmap) { return window.createImageBitmap(fajl); }
        return new Promise(function (ok, ne) {
            var r = new FileReader();
            r.onload = function () { var i = new Image(); i.onload = function () { ok(i); }; i.onerror = ne; i.src = r.result; };
            r.onerror = ne;
            r.readAsDataURL(fajl);
        });
    }
    function smanji(fajl) {
        return slikaIz(fajl).then(function (s) {
            var k = Math.min(1, MAX / Math.max(s.width, s.height));
            var c = document.createElement('canvas');
            c.width = Math.round(s.width * k);
            c.height = Math.round(s.height * k);
            c.getContext('2d').drawImage(s, 0, 0, c.width, c.height);
            return new Promise(function (ok) { c.toBlob(ok, 'image/jpeg', 0.85); });
        });
    }
    function saljiOriginal() {
        javi('Čitam spisak sa slike… (do 1 minuta, ne zatvarajte stranicu)');
        forma.classList.add('salje');
        forma.submit();
    }
    polje.addEventListener('change', function () {
        var fajl = polje.files && polje.files[0];
        if (!fajl) { return; }
        javi('Pripremam sliku…');
        smanji(fajl).then(function (blob) {
            if (!blob || !window.DataTransfer) { saljiOriginal(); return; }
            var dt = new DataTransfer();
            dt.items.add(new File([blob], 'spisak.jpg', { type: 'image/jpeg' }));
            polje.files = dt.files;
            saljiOriginal();
        }).catch(saljiOriginal);
    });
})();
