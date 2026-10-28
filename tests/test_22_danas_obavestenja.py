#!/usr/bin/env python3
"""Kartica "Danas" na početnoj (po artiklu: urađeno = proizvodnja, otišlo = utovar + prodaja + kućna prodaja − povrat,
u paletama) i obaveštenje na telefon uveče (Web Push, nadogradnja v12). Umesto pravog servisa za obaveštenja radi
lažni server na ovom računaru, a poruka se dešifruje NEZAVISNO, u Pythonu (RFC 8291 aes128gcm) i proverava se
potpis servera (VAPID, RFC 8292, ES256) – tako se vidi da će telefon razumeti poruku."""
import base64
import html as htmllib
import json
import os
import re
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

from cryptography.hazmat.primitives import hashes, hmac, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.asymmetric.utils import encode_dss_signature
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
OB = "/admin/obavestenja.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def kartica(o):
    m = re.search(r'<details class="kartica danas-kartica".*?</details>', o.text, re.S)
    return tekst(m.group(0)) if m else ""


def b64u(b):
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def b64u_dek(t):
    return base64.urlsafe_b64decode(t + "=" * (-len(t) % 4))


# ── "telefon": ključ pretplate kao u pregledaču ──
ua_priv = ec.generate_private_key(ec.SECP256R1())
ua_pub = ua_priv.public_key().public_bytes(serialization.Encoding.X962, serialization.PublicFormat.UncompressedPoint)
ua_auth = os.urandom(16)


def hm(kljuc, podaci):
    h = hmac.HMAC(kljuc, hashes.SHA256())
    h.update(podaci)
    return h.finalize()


def desifruj(telo):
    """RFC 8291 / RFC 8188 (aes128gcm), kako to radi pregledač na telefonu."""
    so, rs, idlen = telo[:16], int.from_bytes(telo[16:20], "big"), telo[20]
    as_pub = telo[21:21 + idlen]
    sifrovano = telo[21 + idlen:]
    ecdh = ua_priv.exchange(ec.ECDH(), ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), as_pub))
    ikm = hm(hm(ua_auth, ecdh), b"WebPush: info\x00" + ua_pub + as_pub + b"\x01")
    prk = hm(so, ikm)
    cek = hm(prk, b"Content-Encoding: aes128gcm\x00\x01")[:16]
    nonce = hm(prk, b"Content-Encoding: nonce\x00\x01")[:12]
    otvoreno = AESGCM(cek).decrypt(nonce, sifrovano, None).rstrip(b"\x00")
    assert otvoreno[-1] == 2 and rs >= len(sifrovano) and idlen == 65, "zapis/dopuna"
    return otvoreno[:-1]


def proveri_vapid(zaglavlje):
    """Potpis servera: JWT ES256 proveren javnim ključem iz zaglavlja. Vraća (zaglavlje JWT, tvrdnje, ključ)."""
    m = re.fullmatch(r"vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)", zaglavlje)
    h, c, s, k = m.groups()
    javni = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), b64u_dek(k))
    potpis = b64u_dek(s)
    javni.verify(encode_dss_signature(int.from_bytes(potpis[:32], "big"), int.from_bytes(potpis[32:], "big")),
                 (h + "." + c).encode(), ec.ECDSA(hashes.SHA256()))
    return json.loads(b64u_dek(h)), json.loads(b64u_dek(c)), k


# ── lažni servis za obaveštenja ──
servis_stanje = {"kod": 201, "zahtevi": []}


class Servis(BaseHTTPRequestHandler):
    def do_POST(self):
        telo = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        servis_stanje["zahtevi"].append({"putanja": self.path, "zaglavlja": {k.lower(): v for k, v in self.headers.items()}, "telo": telo})
        self.send_response(servis_stanje["kod"])
        self.send_header("Content-Length", "0")
        self.end_headers()

    def log_message(self, *a):
        pass


servis = HTTPServer(("127.0.0.1", 0), Servis)
threading.Thread(target=servis.serve_forever, daemon=True).start()
ENDPOINT = "http://127.0.0.1:%d/push/telefon-1" % servis.server_address[1]

site = Site(config_extra="define('PUSH_DOZVOLI_LOKALNO', true);")
try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    marko = prijavi_radnika(site, ids["Marko"], "1234")
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    for k in (marko, jelena):
        k.get("/radnik/index.php")
    h10, h50 = sku_id("Humovit", 10), sku_id("Humovit", 50)

    R.odeljak("Nadogradnja na verziju 12")
    verzija = lambda: sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")  # noqa: E731
    R.provera(verzija() == "12" and sql("SHOW TABLES LIKE 'push_pretplate'") == "push_pretplate", "nova instalacija: verzija 12, tabela push_pretplate")
    sql("DROP TABLE push_pretplate")
    sql("UPDATE podesavanja SET vrednost='11' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and sql("SHOW TABLES LIKE 'push_pretplate'") == "push_pretplate" and site.log().count("nadogradnja baze na verziju 12") == 1,
              "sa verzije 11: napravljena tabela push_pretplate")

    R.odeljak("Kartica „Danas“ na početnoj")
    o = adm.get("/admin/stanje.php")
    R.provera("Danas još nema ni proizvodnje ni robe koja je otišla" in kartica(o) and "urađeno 0 pal · otišlo 0 pal" in kartica(o), "prazan dan: poruka")
    marko.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": h50, "kolicina": "2", "nacin": "palete", "kljuc": nov_kljuc()})
    marko.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": h10, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})
    jelena.get("/radnik/prodaja.php")
    o = jelena.post("/radnik/prodaja.php", {"akcija": "dodaj", "sku_id": h10, "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    adm.get("/admin/prodaja.php")
    adm.post("/admin/prodaja.php", {"kupac": "Agrocentar", "sku_id": h50, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})
    for tip in ("utovar", "povrat"):          # utovar pa otkazan istog dana: ne računa se
        sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('%s', %d, 40, 1, NOW(), NOW())" % (tip, h50))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto, obrisan) VALUES ('proizvodnja', %d, 40, 1, NOW(), NOW(), 1)" % h50)
    o = adm.get("/admin/stanje.php")
    k = kartica(o)
    R.provera("Danas" in k and "urađeno 3 pal · otišlo 1 pal" in k, "gore: urađeno 3 pal · otišlo 1 pal", k[:200])
    R.provera("Artikal Urađeno Otišlo Humovit · 10 l 1 pal 5 kom Humovit · 50 l 2 pal 1 pal Ukupno 3 pal 350 kom 1 pal 45 kom" in k,
              "po artiklu (redom kao u katalogu): Humovit 10 l 1 pal / 5 kom, Humovit 50 l 2 pal / 1 pal; ukupno", k)
    R.provera(o.text.index('class="kartica danas-kartica"') < o.text.index('class="kat-naslov"') and "danas.js" in o.text,
              "kartica je iznad stanja i osvežava se sama (danas.js)")
    R.provera('href="/admin/proizvodnja.php"' in o.text and 'href="/admin/obavestenja.php"' in o.text and "Obaveštenje u 20h" in k, "veze: ko je šta uradio, obaveštenje u 20h")
    R.provera("danas-kartica" not in adm.get("/admin/stanje.php?nisko=1").text, "filter „ispod minimuma“: bez kartice")
    o = adm.get("/admin/danas.php")
    R.provera(o.header("X-Farmerkop-Deo") == "danas" and kartica(o) == k, "deo za osvežavanje vraća istu karticu")
    o = marko.get("/admin/danas.php")
    R.provera(o.header("X-Farmerkop-Deo") != "danas" and o.url.endswith("/radnik/index.php") and "danas-kartica" not in o.text, "radnik ne dobija karticu")

    R.odeljak("Obaveštenja: stranica i pretplata telefona")
    o = adm.get(OB)
    vapid = re.search(r'data-vapid="([\w-]+)"', o.text).group(1)
    cron_url = htmllib.unescape(re.search(r'wget -q -O /dev/null "([^"]+)"', o.text).group(1))
    R.provera(len(b64u_dek(vapid)) == 65 and b64u_dek(vapid)[0] == 4, "javni ključ servera (VAPID): 65 bajtova")
    R.provera(cron_url.startswith(site.base + "/cron.php?kljuc=") and "Zakazano slanje još nije podešeno" in tekst(o.text) and "Once Per Hour" in o.text,
              "uputstvo za cPanel sa tačnom komandom (wget …/cron.php?kljuc=…)", cron_url)
    R.provera('href="/admin/obavestenja.php"' in o.text and "🔔 Obaveštenja na telefon" in o.text, "meni ☰: Obaveštenja na telefon")
    for podaci, poruka, opis in [
        ({"endpoint": "https://zli.example.com/x", "p256dh": b64u(ua_pub), "auth": b64u(ua_auth)}, "Nepoznat servis", "tuđ server se ne prihvata"),
        ({"endpoint": ENDPOINT, "p256dh": b64u(ua_pub[:30]), "auth": b64u(ua_auth)}, "Neispravni ključevi", "neispravan ključ"),
    ]:
        o = adm.post(OB, dict(podaci, akcija="pretplata"))
        R.provera(poruka in poruke(o) and sql_int("SELECT COUNT(*) FROM push_pretplate") == 0, "odbijeno: " + opis, poruke(o))
    o = jelena.post(OB, {"akcija": "pretplata", "endpoint": ENDPOINT, "p256dh": b64u(ua_pub), "auth": b64u(ua_auth)})
    R.provera(sql_int("SELECT COUNT(*) FROM push_pretplate") == 0, "radnik ne može da se prijavi")
    o = adm.post(OB, {"akcija": "pretplata", "endpoint": ENDPOINT, "p256dh": b64u(ua_pub), "auth": b64u(ua_auth)})
    R.provera("uključena na ovom telefonu" in poruke(o) and sql_int("SELECT COUNT(*) FROM push_pretplate") == 1, "telefon je prijavljen", poruke(o))
    adm.post(OB, {"akcija": "pretplata", "endpoint": ENDPOINT, "p256dh": b64u(ua_pub), "auth": b64u(ua_auth)})
    R.provera(sql_int("SELECT COUNT(*) FROM push_pretplate") == 1, "isti telefon ponovo: ne pravi duplikat")
    o = adm.get(OB)
    poznati = json.loads(htmllib.unescape(re.search(r'<script type="application/json" id="push-poznati">(.*?)</script>', o.text, re.S).group(1)))
    R.provera(poznati == [ENDPOINT] and "Prijavljeni telefoni (1)" in tekst(o.text), "telefon je na spisku (i stranica zna da je ovaj telefon prijavljen)", poznati)

    R.odeljak("Probno obaveštenje: šifrovano i potpisano po standardu")
    o = adm.post(OB, {"akcija": "probno"})
    R.provera("Probno obaveštenje je poslato na 1 od 1" in poruke(o) and len(servis_stanje["zahtevi"]) == 1, "poslato servisu", poruke(o))
    z = servis_stanje["zahtevi"][-1]
    zg = z["zaglavlja"]
    R.provera(z["putanja"] == "/push/telefon-1" and zg.get("content-encoding") == "aes128gcm" and zg.get("ttl") == "43200" and zg.get("topic") == "dnevnizbir",
              "zaglavlja: aes128gcm, TTL 12 h, tema", zg)
    poruka = json.loads(desifruj(z["telo"]))
    R.provera(poruka == {"naslov": "Probno – Danas urađeno 3 pal · otišlo 1 pal", "tekst": "Urađeno: Humovit · 50 l 2 pal, Humovit · 10 l 1 pal\nOtišlo: Humovit · 50 l 1 pal, Humovit · 10 l 5 kom",
                         "url": "admin/stanje.php#danas", "oznaka": "probno"}, "poruka se dešifruje (nezavisno, u Pythonu): zbir dana", poruka)
    jwt_z, tvrdnje, k = proveri_vapid(zg["authorization"])
    R.provera(jwt_z == {"typ": "JWT", "alg": "ES256"} and k == vapid, "potpis servera (ES256) je ispravan, ključ je isti kao na stranici")
    R.provera(tvrdnje["aud"] == "http://127.0.0.1:%d" % servis.server_address[1] and time.time() < tvrdnje["exp"] <= time.time() + 24 * 3600
              and tvrdnje["sub"] == site.base + "/", "JWT: aud = servis, rok ≤ 24 h, sub = adresa aplikacije", tvrdnje)

    R.odeljak("Zakazano slanje (cron.php)")
    o = Client(site.base).get("/cron.php")
    R.provera(o.status == 403, "bez ključa: zabranjeno")
    o = Client(site.base).get("/cron.php?kljuc=pogresan")
    R.provera(o.status == 403, "pogrešan ključ: zabranjeno")
    putanja_cron = cron_url[len(site.base):]
    sql("REPLACE INTO podesavanja (kljuc, vrednost) VALUES ('obavestenje_sat', '24')")
    n = len(servis_stanje["zahtevi"])
    o = Client(site.base).get(putanja_cron)
    R.provera(o.status == 200 and "Još nije 24h" in o.text and len(servis_stanje["zahtevi"]) == n, "pre zadatog sata: ništa se ne šalje", o.text)
    R.provera("Zakazano slanje radi" in tekst(adm.get(OB).text), "stranica vidi da cron radi")
    sql("REPLACE INTO podesavanja (kljuc, vrednost) VALUES ('obavestenje_sat', '0')")
    o = Client(site.base).get(putanja_cron)
    R.provera("Dnevni zbir poslat na 1 od 1" in o.text and len(servis_stanje["zahtevi"]) == n + 1, "posle zadatog sata: dnevni zbir poslat", o.text)
    dn = json.loads(desifruj(servis_stanje["zahtevi"][-1]["telo"]))
    R.provera(dn["naslov"] == "Danas urađeno 3 pal · otišlo 1 pal" and dn["oznaka"] == "dnevni-zbir-" + danas_bg(), "dnevni zbir: naslov i oznaka dana", dn)
    o = Client(site.base).get(putanja_cron)
    R.provera("već poslat" in o.text and len(servis_stanje["zahtevi"]) == n + 1, "drugi put istog dana: ne šalje se ponovo", o.text)
    r = subprocess.run(["php", str(site.dir / "cron.php")], capture_output=True, text=True, timeout=60)
    R.provera(r.returncode == 0 and "već poslat" in r.stdout, "radi i iz komandne linije (php …/cron.php), bez ključa", r.stdout + r.stderr)

    R.odeljak("Greške servisa")
    sql("DELETE FROM podesavanja WHERE kljuc='dnevni_zbir_poslat'")
    servis_stanje["kod"] = 500
    o = Client(site.base).get(putanja_cron)
    R.provera("poslat na 0 od 1" in o.text and "500" in o.text and sql("SELECT COUNT(*) FROM podesavanja WHERE kljuc='dnevni_zbir_poslat'") == "0",
              "servis ne radi (500): nije označeno kao poslato – pokušaće ponovo za sat", o.text)
    R.provera("servis je odgovorio 500" in tekst(adm.get(OB).text), "greška piše uz telefon")
    servis_stanje["kod"] = 410
    o = Client(site.base).get(putanja_cron)
    R.provera("više nije prijavljen" in o.text and sql_int("SELECT COUNT(*) FROM push_pretplate") == 0, "telefon odjavljen kod servisa (410): uklanja se", o.text)
    servis_stanje["kod"] = 201
    o = Client(site.base).get(putanja_cron)
    R.provera("Nijedan telefon nije prijavljen" in o.text, "bez telefona: ništa se ne šalje", o.text)

    R.odeljak("Podešavanja i odjava")
    o = adm.post(OB, {"akcija": "sat", "sat": "21"})
    R.provera("svako veče u 21h" in poruke(o) and sql("SELECT vrednost FROM podesavanja WHERE kljuc='obavestenje_sat'") == "21", "sat dnevnog zbira: 21h", poruke(o))
    R.provera("Obaveštenje u 21h" in kartica(adm.get("/admin/stanje.php")), "kartica na početnoj pokazuje sat")
    o = adm.post(OB, {"akcija": "sat", "sat": "5"})
    R.provera("od 12 do 23" in poruke(o), "neispravan sat: odbijeno")
    adm.post(OB, {"akcija": "pretplata", "endpoint": ENDPOINT, "p256dh": b64u(ua_pub), "auth": b64u(ua_auth)})
    o = adm.post(OB, {"akcija": "odjava", "endpoint": ENDPOINT})
    R.provera("isključena na ovom telefonu" in poruke(o) and sql_int("SELECT COUNT(*) FROM push_pretplate") == 0, "odjava ovog telefona")
    o = marko.get(OB)
    R.provera(o.url.endswith("/radnik/index.php"), "radnik ne vidi stranicu obaveštenja")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()
    servis.shutdown()

sys.exit(R.kraj())
