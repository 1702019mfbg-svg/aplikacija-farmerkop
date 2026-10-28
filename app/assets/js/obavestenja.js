/* Obaveštenja na telefon: uključivanje na ovom uređaju (dozvola + pretplata kod servisa za obaveštenja, pa se
 * pretplata pošalje serveru formom) i isključivanje. Bez podrške (npr. iPhone u Safariju) – objašnjenje. */
(function () {
    'use strict';
    var okvir = document.querySelector('[data-push]');
    var forma = document.getElementById('push-forma');
    if (!okvir || !forma) { return; }
    var status = okvir.querySelector('[data-push-status]');
    var ukljuci = okvir.querySelector('[data-push-ukljuci]');
    var iskljuci = okvir.querySelector('[data-push-iskljuci]');
    var kljuc = okvir.getAttribute('data-vapid');
    var poznati = [];
    try { poznati = JSON.parse(document.getElementById('push-poznati').textContent); } catch (e) { poznati = []; }

    function poruka(t, greska) {
        status.textContent = t;
        status.classList.toggle('tekst-crveno', !!greska);
    }
    function uBajtove(b64) {
        var s = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        var a = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i); }
        return a;
    }
    function uB64(buf) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function posalji(akcija, sub) {
        var j = sub.toJSON();
        forma.elements.akcija.value = akcija;
        forma.elements.endpoint.value = j.endpoint;
        forma.elements.p256dh.value = (j.keys && j.keys.p256dh) || '';
        forma.elements.auth.value = (j.keys && j.keys.auth) || '';
        forma.submit();
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        poruka('Ovaj pregledač ne može da prima obaveštenja. Na iPhone-u: dodajte aplikaciju na početni ekran i otvorite je odatle. Na Androidu: Chrome.', true);
        return;
    }
    var spreman = new Promise(function (resi, odbij) {
        navigator.serviceWorker.ready.then(resi);
        window.setTimeout(function () { odbij(new Error('Aplikacija još nije spremna – osvežite stranicu.')); }, 15000);
    });

    spreman.then(function (reg) {
        return reg.pushManager.getSubscription();
    }).then(function (sub) {
        if (sub && poznati.indexOf(sub.endpoint) !== -1) {
            poruka('✓ Obaveštenja su uključena na ovom telefonu.');
            iskljuci.hidden = false;
        } else if (Notification.permission === 'denied') {
            poruka('Obaveštenja su blokirana za ovu aplikaciju. Dozvolite ih: Chrome ⋮ → Podešavanja → Podešavanja sajta → Obaveštenja (ili u podešavanjima telefona), pa osvežite stranicu.', true);
        } else {
            poruka('Na ovom telefonu obaveštenja još nisu uključena.');
            ukljuci.hidden = false;
        }
    }).catch(function (g) { poruka(g.message, true); });

    ukljuci.addEventListener('click', function () {
        ukljuci.disabled = true;
        poruka('Uključujem… (dozvolite obaveštenja kad telefon pita)');
        Notification.requestPermission().then(function (dozvola) {
            if (dozvola !== 'granted') { throw new Error('Obaveštenja nisu dozvoljena – dodirnite ponovo i izaberite „Dozvoli“.'); }
            return spreman;
        }).then(function (reg) {
            return reg.pushManager.getSubscription().then(function (stara) {
                // postojeća pretplata sa drugim ključem servera ne bi radila – napravi novu
                if (stara && stara.options && stara.options.applicationServerKey && uB64(stara.options.applicationServerKey) !== kljuc) {
                    return stara.unsubscribe().then(function () { return null; });
                }
                return stara;
            }).then(function (stara) {
                return stara || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: uBajtove(kljuc) });
            });
        }).then(function (sub) {
            posalji('pretplata', sub);
        }).catch(function (g) {
            ukljuci.disabled = false;
            poruka('Nije uspelo: ' + g.message, true);
        });
    });

    iskljuci.addEventListener('click', function () {
        iskljuci.disabled = true;
        spreman.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
            if (!sub) { throw new Error('Na ovom telefonu nema pretplate.'); }
            return sub.unsubscribe().then(function () { posalji('odjava', sub); });
        }).catch(function (g) {
            iskljuci.disabled = false;
            poruka(g.message, true);
        });
    });
})();
