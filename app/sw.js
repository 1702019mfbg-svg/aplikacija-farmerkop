/* Farmerkop – service worker (PWA).
 *
 * - Stranice (HTML) se NIKAD ne keširaju: podaci su uvek sveži i ništa osetljivo ne ostaje na telefonu.
 * - Statični fajlovi (stil, skripte, ikone) se keširaju da se aplikacija brže otvara.
 * - Bez internet veze prikazuje se stranica "Nema veze" (unosi se ne mogu sačuvati bez veze).
 */
'use strict';

const VERZIJA = 'farmerkop-v1';
const BAZA = new URL(self.registration.scope).pathname;   // npr. "/" ili "/pogon/"
const OFFLINE = BAZA + 'offline.html';
const OSNOVA = [OFFLINE, BAZA + 'assets/icons/icon-192.png', BAZA + 'assets/icons/icon.svg'];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(VERZIJA)
            .then((kes) => kes.addAll(OSNOVA))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((imena) => Promise.all(imena.filter((i) => i !== VERZIJA).map((i) => caches.delete(i))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const zahtev = e.request;
    if (zahtev.method !== 'GET') { return; }
    const url = new URL(zahtev.url);
    if (url.origin !== self.location.origin) { return; }

    // Otvaranje stranice: samo mreža; ako je nema, stranica "Nema veze".
    if (zahtev.mode === 'navigate') {
        e.respondWith(fetch(zahtev).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Statični fajlovi: prvo keš, a u pozadini osveži.
    if (url.pathname.startsWith(BAZA + 'assets/')) {
        e.respondWith(
            caches.open(VERZIJA).then(async (kes) => {
                const keshirano = await kes.match(zahtev);
                const mreza = fetch(zahtev)
                    .then((odgovor) => {
                        if (odgovor.ok) { kes.put(zahtev, odgovor.clone()); }
                        return odgovor;
                    })
                    .catch(() => keshirano);
                return keshirano || mreza;
            })
        );
    }
});
