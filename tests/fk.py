"""
Pomoćni alati za testove Farmerkop aplikacije (samo standardna biblioteka Pythona).

- Site:   kopira app/ u privremeni folder, upisuje test config.php i pokreće PHP server
- Client: HTTP klijent sa kolačićima koji prati preusmeravanja i sam nosi CSRF žeton
- sql():  upit direktno nad test bazom (za proveru stanja u bazi)
"""
import http.cookiejar
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
APP = ROOT / "app"

DB_NAME = "farmerkop_test"
DB_USER = "fk"
DB_PASS = "fk_test_pass"
INSTALL_KLJUC = "test-kljuc-123"
ADMIN_USER = "vlasnik"
ADMIN_PASS = "Tajna-lozinka-1"


# ─── Baza ──────────────────────────────────────────────────────────────────

def sql(upit, db=DB_NAME):
    """Izvrši SQL preko mysql CLI i vrati tekst (kolone odvojene tabulatorom)."""
    cmd = ["mysql", "-N", "-B", "-e", upit]
    if db:
        cmd.append(db)
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError("SQL greška: %s\n%s" % (upit, r.stderr))
    return r.stdout.strip()


def sql_int(upit):
    return int(sql(upit) or 0)


def reset_db():
    sql("DROP DATABASE IF EXISTS %s; CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" % (DB_NAME, DB_NAME), db=None)


def php_hes(tajna):
    """bcrypt heš preko PHP-a (isti algoritam kao u aplikaciji)."""
    r = subprocess.run(["php", "-r", "echo password_hash($argv[1], PASSWORD_DEFAULT);", tajna], capture_output=True, text=True)
    return r.stdout.strip()


# ─── Server ────────────────────────────────────────────────────────────────

def slobodan_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


class Site:
    def __init__(self, config_extra="", placeholder_config=False):
        self.tmp = Path(tempfile.mkdtemp(prefix="fk_site_"))
        self.dir = self.tmp / "site"
        shutil.copytree(APP, self.dir)
        if not placeholder_config:
            (self.dir / "config.php").write_text(
                "<?php\n"
                "define('DB_HOST','127.0.0.1');\n"
                "define('DB_NAME','%s');\n"
                "define('DB_USER','%s');\n"
                "define('DB_PASS','%s');\n"
                "define('INSTALL_KLJUC','%s');\n"
                "define('DEBUG', true);\n%s\n" % (DB_NAME, DB_USER, DB_PASS, INSTALL_KLJUC, config_extra)
            )
        self.port = slobodan_port()
        self.base = "http://127.0.0.1:%d" % self.port
        self.logfile = self.tmp / "php.log"
        env = dict(os.environ, PHP_CLI_SERVER_WORKERS="4")
        self.proc = subprocess.Popen(
            ["php", "-S", "127.0.0.1:%d" % self.port, "-t", str(self.dir)],
            stdout=open(self.logfile, "w"), stderr=subprocess.STDOUT, env=env,
        )
        for _ in range(50):
            try:
                socket.create_connection(("127.0.0.1", self.port), timeout=0.2).close()
                break
            except OSError:
                time.sleep(0.1)

    def log(self):
        return self.logfile.read_text(errors="replace")

    def php_problemi(self):
        """Linije u logu servera koje liče na PHP upozorenja ili greške."""
        out = []
        for l in self.log().splitlines():
            if re.search(r"PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught|Stack trace", l):
                out.append(l)
        return out

    def stop(self):
        try:
            self.proc.send_signal(signal.SIGTERM)
            self.proc.wait(timeout=5)
        except Exception:
            self.proc.kill()
        shutil.rmtree(self.tmp, ignore_errors=True)


# ─── HTTP klijent ──────────────────────────────────────────────────────────

class _BezPreusmeravanja(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Odgovor:
    def __init__(self, status, headers, body, url, lanac):
        self.status = status
        self.headers = headers
        self.body = body
        self.url = url
        self.lanac = lanac  # [(status, location), ...]

    @property
    def text(self):
        return self.body

    def ima(self, tekst):
        return tekst in self.body

    def header(self, ime):
        return self.headers.get(ime)

    def csrf(self):
        m = re.search(r'name="csrf" value="([0-9a-f]{64})"', self.body)
        return m.group(1) if m else None


class Client:
    def __init__(self, base):
        self.base = base
        self.cj = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), _BezPreusmeravanja)
        self.token = None

    def cookie(self, ime="FKSESIJA"):
        for c in self.cj:
            if c.name == ime:
                return c
        return None

    def _jedan(self, metoda, url, podaci, headers):
        body = None
        if podaci is not None:
            body = urllib.parse.urlencode(podaci, doseq=True).encode()
        req = urllib.request.Request(url, data=body, method=metoda, headers=headers or {})
        try:
            r = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as e:
            r = e
        return r.status if hasattr(r, "status") else r.code, r.headers, r.read().decode("utf-8", "replace")

    def zahtev(self, metoda, putanja, podaci=None, headers=None, slediti=True):
        if not putanja.startswith("http"):
            putanja = urllib.parse.quote(putanja, safe="/?&=%:#[]@!$'()*+,;~-._")   # kvačice u adresi
        url = putanja if putanja.startswith("http") else self.base + putanja
        lanac = []
        for _ in range(8):
            status, hdr, body = self._jedan(metoda, url, podaci, headers)
            if status in (301, 302, 303, 307, 308) and hdr.get("Location"):
                lanac.append((status, hdr["Location"]))
                if not slediti:
                    break
                url = urllib.parse.urljoin(url, hdr["Location"])
                if status in (301, 302, 303):
                    metoda, podaci = "GET", None
                continue
            break
        o = Odgovor(status, hdr, body, url, lanac)
        t = o.csrf()
        if t:
            self.token = t
        return o

    def get(self, putanja, **kw):
        return self.zahtev("GET", putanja, **kw)

    def post(self, putanja, podaci=None, csrf=True, **kw):
        podaci = dict(podaci or {})
        if csrf and "csrf" not in podaci:
            if self.token is None:
                self.get("/login.php")
            podaci["csrf"] = self.token
        return self.zahtev("POST", putanja, podaci=podaci, **kw)

    def kopiraj_sesiju(self):
        n = Client(self.base)
        for c in self.cj:
            n.cj.set_cookie(c)
        n.token = self.token
        return n


# ─── Mini okvir za testove ─────────────────────────────────────────────────

class Rezultat:
    def __init__(self):
        self.ok = 0
        self.greske = []

    def provera(self, uslov, opis, detalj=""):
        if uslov:
            self.ok += 1
            print("  ✓ " + opis)
        else:
            self.greske.append(opis)
            print("  ✗ " + opis + (("\n      " + str(detalj)[:600]) if detalj else ""))

    def odeljak(self, naslov):
        print("\n▶ " + naslov)

    def kraj(self):
        print("\n%d provera prošlo, %d palo" % (self.ok, len(self.greske)))
        if self.greske:
            print("PALE PROVERE:")
            for g in self.greske:
                print("  - " + g)
        return 0 if not self.greske else 1


# ─── Priprema sajta za testove ─────────────────────────────────────────────

def pripremi_sajt(site, radnici=(("Marko", "1234"), ("Jelena", "4321"), ("Dragan", "1111"))):
    """Instalira aplikaciju (sa početnim katalogom) i dodaje radnike. Vraća {ime: id}."""
    c = Client(site.base)
    c.get("/install.php")
    o = c.post("/install.php", {"kljuc": INSTALL_KLJUC, "ime": "Vlasnik", "korisnicko_ime": ADMIN_USER,
                                "sifra": ADMIN_PASS, "sifra2": ADMIN_PASS, "katalog": "1"})
    assert "Instalacija je uspešno završena" in o.text, o.text[:500]
    ids = {}
    for ime, pin in radnici:
        sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','%s','%s',1,'2026-01-01 00:00:00')" % (ime, php_hes(pin)))
        ids[ime] = sql_int("SELECT id FROM korisnici WHERE ime='%s'" % ime)
    return ids


def sku_id(artikal, kolicina, jedinica="l", varijanta=None):
    """ID SKU-a po nazivu artikla, pakovanju i (opciono) varijanti."""
    q = ("SELECT s.id FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id "
         "LEFT JOIN varijante v ON v.id=s.varijanta_id WHERE a.naziv='%s' AND p.kolicina=%s AND p.jedinica='%s'" % (artikal, kolicina, jedinica))
    if varijanta:
        q += " AND v.naziv='%s'" % varijanta
    r = sql(q)
    if not r:
        raise KeyError("nema SKU: %s %s %s %s" % (artikal, kolicina, jedinica, varijanta))
    return int(r.split()[0])


def stanje(sku):
    return sql_int("SELECT COALESCE(SUM(CASE WHEN tip IN ('prodaja','kucna_prodaja') THEN -kolicina ELSE kolicina END),0) FROM unosi WHERE sku_id=%d AND obrisan=0" % sku)


def prijavi_radnika(site, ime_id, pin):
    c = Client(site.base)
    c.get("/login.php")
    o = c.post("/login.php", {"tip": "radnik", "radnik_id": ime_id, "pin": pin}, slediti=False)
    assert o.status == 302, "prijava radnika nije uspela: " + o.text[:300]
    return c


def prijavi_admina(site):
    c = Client(site.base)
    c.get("/login.php?admin=1")
    o = c.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS}, slediti=False)
    assert o.status == 302, "prijava admina nije uspela: " + o.text[:300]
    return c


def nov_kljuc():
    import secrets
    return secrets.token_hex(16)
