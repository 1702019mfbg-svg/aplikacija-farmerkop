/* Štampa / PDF i deljenje spiska za utovar. */
(function () {
    'use strict';
    var tekstPolje = document.querySelector('[data-tekst]');
    var poruka = document.querySelector('[data-podeli-poruka]');
    var tekst = tekstPolje ? tekstPolje.value : '';
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-stampaj]')) {
            window.print();
            return;
        }
        var d = e.target.closest('[data-podeli]');
        if (!d) { return; }
        if (navigator.share) {
            navigator.share({ title: d.getAttribute('data-naslov') || 'Spisak za utovar', text: tekst }).catch(function () {});
            return;
        }
        var gotovo = function () { if (poruka) { poruka.textContent = 'Tekst spiska je kopiran – nalepite ga u Viber ili WhatsApp.'; } };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(tekst).then(gotovo, function () { pokazi(); });
        } else {
            pokazi();
        }
        function pokazi() {
            tekstPolje.hidden = false;
            tekstPolje.rows = 10;
            tekstPolje.select();
            if (poruka) { poruka.textContent = 'Označite tekst, kopirajte ga i nalepite u Viber ili WhatsApp.'; }
        }
    });
})();
