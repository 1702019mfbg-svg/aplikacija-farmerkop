/* Primeni sačuvanu temu (svetla/tamna) pre iscrtavanja, da ne bi bilo treptanja. */
(function () {
    try {
        var t = localStorage.getItem('fk_tema');
        if (t === 'dark' || t === 'light') {
            document.documentElement.setAttribute('data-tema', t);
        }
    } catch (e) { /* privatni prozor ili blokirano skladište – nije bitno */ }
})();
