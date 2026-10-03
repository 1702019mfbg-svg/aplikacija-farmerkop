#!/usr/bin/env python3
"""
Faza 6: aplikacija iz ZIP paketa pod pravim Apache serverom (.htaccess, zabrane, zaglavlja,
HTTPS preusmeravanje) i potpun tok: instalacija → prijava → unos → stanje.
"""
import http.client
import re
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa
from apache import ApacheSajt

R = Rezultat()
reset_db()


def sirov(port, putanja, host=None, zaglavlja=None, metoda="GET"):
    """Zahtev bez praćenja preusmeravanja; vraća (status, zaglavlja, telo)."""
    c = http.client.HTTPConnection("127.0.0.1", port, timeout=20)
    h = {"Host": host or "127.0.0.1:%d" % port}
    h.update(zaglavlja or {})
    c.request(metoda, putanja, headers=h)
    r = c.getresponse()
    telo = r.read().decode("utf-8", "replace")
    zag = {k.lower(): v for k, v in r.getheaders()}
    c.close()
    return r.status, zag, telo


# ── 0. Sadržaj paketa ────────────────────────────────────────────────────────
R.odeljak("Sadržaj ZIP paketa")
zip_putanja = ROOT / "dist" / "farmerkop-aplikacija.zip"
imena = zipfile.ZipFile(zip_putanja).namelist()
for obavezno in ["index.php", "login.php", "install.php", "config.php", ".htaccess", "inc/.htaccess", "manifest.webmanifest", "sw.js", "offline.html",
                 "assets/css/app.css", "assets/js/app.js", "assets/icons/icon-192.png", "assets/icons/icon-512.png", "assets/icons/icon-maskable-512.png",
                 "radnik/index.php", "radnik/prodaja.php", "admin/stanje.php", "admin/prodaja.php", "admin/istorija.php", "admin/radnici.php", "admin/podesavanja.php"]:
    R.provera(obavezno in imena, "u paketu je " + obavezno)
R.provera(not any(i.startswith(("tests/", "tools/", "dist/", ".git")) for i in imena), "u paketu nema testova ni alata")
R.provera(not any(i.endswith((".md", ".log", ".sql", ".sh", ".py")) for i in imena), "u paketu nema pomoćnih fajlova (.md, .log, .sql, .sh, .py)")
R.provera(all(not i.startswith("app/") for i in imena), "fajlovi su direktno u korenu paketa (bez dodatnog foldera)")
cfg = zipfile.ZipFile(zip_putanja).read("config.php").decode()
R.provera("UPISITE_IME_BAZE" in cfg and "PROMENI_OVO" in cfg and "DEBUG', true" not in cfg, "config.php u paketu sadrži samo prazna mesta za unos, bez tajni")

site = ApacheSajt(zip_putanja)
try:
    # ── 1. Server radi sa .htaccess ──────────────────────────────────────────
    R.odeljak("Apache + .htaccess")
    st, zag, telo = sirov(site.port, "/install.php")
    R.provera(st == 200 and "Instalacija" in telo, "install.php radi pod Apache-om (nema greške 500 zbog .htaccess)", (st, telo[:300], site.greske_apache()[-500:]))
    R.provera("Invalid command" not in site.greske_apache() and "not allowed here" not in site.greske_apache(), "Apache ne prijavljuje nepoznate direktive u .htaccess", site.greske_apache()[-400:])

    # ── 2. Zabrane ───────────────────────────────────────────────────────────
    R.odeljak("Zabranjeni fajlovi i folderi")
    for putanja in ["/inc/bootstrap.php", "/inc/db.php", "/inc/schema.php", "/inc/auth.php", "/inc/", "/inc/.htaccess", "/.htaccess"]:
        st, zag, telo = sirov(site.port, putanja)
        R.provera(st == 403, "%s → 403" % putanja, st)
    for ime in ["beleske.md", "izvoz.sql", "greska.log", "podesavanja.ini", "skripta.sh", "kopija.bak"]:
        (site.dir / ime).write_text("tajno")
        st, zag, telo = sirov(site.port, "/" + ime)
        R.provera(st == 403 and "tajno" not in telo, "/%s → 403" % ime, st)
    st, zag, telo = sirov(site.port, "/assets/")
    R.provera(st in (403, 404) and "Index of" not in telo, "spisak fajlova u folderu (/assets/) se ne prikazuje", st)
    st, zag, telo = sirov(site.port, "/radnik/")
    R.provera("Index of" not in telo, "ni /radnik/ nema spisak fajlova")
    st, zag, telo = sirov(site.port, "/config.php")
    R.provera(st == 200 and telo.strip() == "" and "DB_PASS" not in telo, "config.php se izvršava i ne prikazuje ništa (lozinka baze se ne vidi)", (st, telo[:100]))

    # ── 3. Tipovi fajlova i keširanje ────────────────────────────────────────
    R.odeljak("Tipovi fajlova i keširanje")
    st, zag, telo = sirov(site.port, "/manifest.webmanifest")
    R.provera(st == 200 and zag["content-type"].startswith("application/manifest+json"), "manifest.webmanifest: application/manifest+json", zag.get("content-type"))
    R.provera("no-cache" in zag.get("cache-control", ""), "manifest se ne kešira dugo", zag.get("cache-control"))
    st, zag, telo = sirov(site.port, "/sw.js")
    R.provera(st == 200 and "javascript" in zag["content-type"], "sw.js: JavaScript", zag.get("content-type"))
    R.provera("no-cache" in zag.get("cache-control", "") and "max-age=2592000" not in zag.get("cache-control", ""), "sw.js se nikad ne kešira dugo (inače se nove verzije ne bi preuzimale)", zag.get("cache-control"))
    st, zag, telo = sirov(site.port, "/assets/css/app.css")
    R.provera(zag["content-type"].startswith("text/css") and "max-age=2592000" in zag.get("cache-control", ""), "app.css: text/css, kešira se mesec dana", (zag.get("content-type"), zag.get("cache-control")))
    st, zag, telo = sirov(site.port, "/assets/icons/icon.svg")
    R.provera(zag["content-type"].startswith("image/svg+xml"), "icon.svg: image/svg+xml", zag.get("content-type"))
    st, zag, telo = sirov(site.port, "/assets/icons/icon-192.png")
    R.provera(zag["content-type"] == "image/png" and st == 200, "PNG ikone se isporučuju")
    R.provera(zag.get("x-content-type-options") == "nosniff", "statični fajlovi nose X-Content-Type-Options: nosniff")

    # ── 4. HTTPS ─────────────────────────────────────────────────────────────
    R.odeljak("HTTPS preusmeravanje")
    st, zag, telo = sirov(site.port, "/login.php?admin=1", host="app.farmerkop.rs")
    R.provera(st == 301 and zag["location"] == "https://app.farmerkop.rs/login.php?admin=1", "http://app.farmerkop.rs/… → 301 na https://", (st, zag.get("location")))
    st, zag, telo = sirov(site.port, "/", host="app.farmerkop.rs", zaglavlja={"X-Forwarded-Proto": "https"})
    R.provera(st != 301, "iza proksija koji već daje https (X-Forwarded-Proto) nema petlje preusmeravanja", st)
    st, zag, telo = sirov(site.port, "/install.php", host="localhost:%d" % site.port)
    R.provera(st == 200, "localhost se ne preusmerava (razvoj/testiranje)", st)

    # ── 5. Potpun tok pod Apache-om ──────────────────────────────────────────
    R.odeljak("Potpun tok pod Apache-om: instalacija, prijava, unos, stanje")
    c = Client(site.base)
    o = c.get("/install.php")
    R.provera("Veza sa bazom radi" in o.text, "instalacija vidi bazu")
    o = c.post("/install.php", {"kljuc": INSTALL_KLJUC, "ime": "Vlasnik", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS, "sifra2": ADMIN_PASS, "katalog": "1"})
    R.provera("Instalacija je uspešno završena" in o.text and "automatski obrisan" in o.text, "instalacija prošla, install.php se obrisao", o.text[:300])
    R.provera(not (site.dir / "install.php").exists(), "install.php ne postoji na disku")
    R.provera(c.get("/install.php").status == 404, "install.php posle instalacije → 404")
    sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','Marko','%s',1,NOW())" % php_hes("2468"))
    mid = sql_int("SELECT id FROM korisnici WHERE ime='Marko'")

    w = Client(site.base)
    o = w.get("/login.php")
    R.provera(o.status == 200 and "Marko" in o.text, "prijava radnika se prikazuje")
    csp = o.header("Content-Security-Policy") or ""
    R.provera("default-src 'self'" in csp and o.header("X-Frame-Options") == "DENY", "bezbednosna zaglavlja stižu i pod Apache-om")
    sc = [v for k, v in o.headers.items() if k.lower() == "set-cookie"]
    R.provera(any("FKSESIJA=" in s and "HttpOnly" in s and "SameSite=Lax" in s and "secure" not in s.lower() for s in sc), "preko običnog http kolačić nema Secure oznaku (inače prijava ne bi radila na testu)", sc)
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "2468"}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik se prijavio")
    hum10 = sku_id("Humovit", 10)
    w.get("/radnik/index.php")
    o = w.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": hum10, "kolicina": "2", "nacin": "palete", "kljuc": nov_kljuc()})
    R.provera("540 kom (2 palete)" in o.text, "unos 2 palete Humovita 10 l = 540 kom")
    a = prijavi_admina(site)
    o = a.get("/admin/stanje.php")
    R.provera(re.search(r'sku-stanje">540<', o.text) is not None, "administrator vidi stanje 540")

    # iza HTTPS-a (proksi/AutoSSL): Secure kolačić i HSTS
    s = Client(site.base)
    o = s.zahtev("GET", "/login.php", headers={"X-Forwarded-Proto": "https"})
    sc = [v for k, v in o.headers.items() if k.lower() == "set-cookie"]
    R.provera(any("secure" in x.lower() for x in sc), "preko https-a kolačić sesije dobija Secure oznaku", sc)
    R.provera("max-age" in (o.header("Strict-Transport-Security") or ""), "preko https-a šalje se HSTS", o.header("Strict-Transport-Security"))

    # CSV preko Apache-a
    o = a.get("/admin/izvoz.php?od=&do=")
    R.provera(o.header("Content-Type").startswith("text/csv") and o.body.startswith("﻿") and "Humovit" in o.body, "CSV izvoz radi pod Apache-om")

    # PWA fajlovi dostupni
    for putanja in ["/manifest.webmanifest", "/sw.js", "/offline.html", "/assets/icons/icon-512.png", "/assets/icons/icon-maskable-512.png", "/assets/icons/apple-touch-icon.png", "/assets/icons/favicon-32.png"]:
        R.provera(sirov(site.port, putanja)[0] == 200, "dostupno: " + putanja)

    R.odeljak("Uputstvo: zaboravljena šifra i ažuriranje")
    uput = (ROOT / "UPUTSTVO.md").read_text(encoding="utf-8")
    hes_iz_uputstva = re.search(r"\$2y\$10\$[./A-Za-z0-9]{53}", uput)
    R.provera(hes_iz_uputstva is not None, "uputstvo sadrži heš privremene šifre")
    sql("UPDATE korisnici SET hes='x', neuspesni_pokusaji=7, zakljucan_do='2099-01-01 00:00:00' WHERE uloga='admin'")
    # tačno kao u uputstvu: zalepi heš, neuspesni_pokusaji = 0, zakljucan_do = NULL
    sql("UPDATE korisnici SET hes='%s', neuspesni_pokusaji=0, zakljucan_do=NULL WHERE uloga='admin'" % hes_iz_uputstva.group(0))
    sql("DELETE FROM neuspele_prijave")
    c = Client(site.base); c.get("/login.php?admin=1")
    o = c.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "Privremena-2026"}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/admin/stanje.php"), "postupak iz uputstva: sa 'Privremena-2026' administrator može da se prijavi", o.text[-300:])

    az = zipfile.ZipFile(ROOT / "dist" / "farmerkop-azuriranje.zip")
    R.provera("config.php" not in az.namelist() and "install.php" not in az.namelist(), "paket za ažuriranje nema config.php ni install.php")
    R.provera(".htaccess" in az.namelist() and "inc/.htaccess" in az.namelist() and "admin/stanje.php" in az.namelist(), "paket za ažuriranje ima sve ostalo")
    config_pre = (site.dir / "config.php").read_text()
    az.extractall(site.dir)
    R.provera((site.dir / "config.php").read_text() == config_pre, "posle raspakivanja ažuriranja config.php je isti (podaci o bazi ostaju)")
    st_pre = sirov(site.port, "/login.php")[0]
    c = Client(site.base); c.get("/login.php?admin=1")
    o = c.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "Privremena-2026"}, slediti=False)
    R.provera(st_pre == 200 and o.status == 302 and "Humovit" in c.get("/admin/stanje.php").text, "posle ažuriranja aplikacija radi sa istim podacima")

    R.odeljak("Greške")
    R.provera("PHP Fatal" not in site.greske_apache(), "Apache log nema fatalnih PHP grešaka", site.greske_apache()[-300:])
    R.provera(not site.php_problemi(), "PHP ne prijavljuje upozorenja ni greške", site.php_problemi()[:5])
    R.provera("500" not in " ".join(re.findall(r" (500) ", (site.tmp / "access.log").read_text())), "nijedan zahtev nije završio sa greškom 500")
finally:
    site.stop()

sys.exit(R.kraj())
