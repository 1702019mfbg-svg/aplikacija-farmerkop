/* Uvoz stanja: popunjava izbor artikala u svakom redu (server šalje samo izabranu stavku). */
(function () {
    'use strict';

    function ucitaj() {
        var forma = document.querySelector('[data-uvoz]');
        if (!forma) { return; }
        var izvor = forma.querySelector('script[data-uvoz-katalog]');
        var lista = [];
        try { lista = JSON.parse(izvor ? izvor.textContent : '[]'); } catch (e) { lista = []; }

        var selekti = forma.querySelectorAll('select[data-uvoz-sku]');
        for (var i = 0; i < selekti.length; i++) {
            var sel = selekti[i];
            var izabrano = sel.getAttribute('data-izabrano') || '0';
            var frag = document.createDocumentFragment();
            var skip = document.createElement('option');
            skip.value = '0';
            skip.textContent = '— preskoči —';
            frag.appendChild(skip);
            for (var j = 0; j < lista.length; j++) {
                var o = document.createElement('option');
                o.value = String(lista[j][0]);
                o.textContent = lista[j][1];
                frag.appendChild(o);
            }
            sel.innerHTML = '';
            sel.appendChild(frag);
            sel.value = izabrano;
            if (sel.value !== izabrano) { sel.value = '0'; }
        }

        forma.addEventListener('change', function (e) {
            var t = e.target;
            if (!t || !t.hasAttribute || !t.hasAttribute('data-uvoz-sku')) { return; }
            var red = t.closest('[data-uvoz-red]');
            if (!red) { return; }
            red.className = 'uvoz-red uvoz-' + (t.value === '0' ? 'preskoceno' : 'rucno');
            var oznaka = red.querySelector('[data-uvoz-oznaka]');
            if (oznaka) {
                oznaka.className = 'znacka' + (t.value === '0' ? '' : ' znacka-korekcija');
                oznaka.textContent = t.value === '0' ? 'preskočeno' : 'izabrano';
            }
            var sada = red.querySelector('.uvoz-sada, .uvoz-greska');
            if (sada) { sada.textContent = 'Kliknite „Osveži pregled“ da vidite novu količinu.'; sada.className = 'pomoc uvoz-sada'; }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ucitaj);
    } else {
        ucitaj();
    }
})();
