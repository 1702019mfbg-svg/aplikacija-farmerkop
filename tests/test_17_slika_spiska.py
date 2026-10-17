#!/usr/bin/env python3
"""Slikaj spisak: fotografija rukom pisanog spiska ide AI servisu (Anthropic Messages API), prepisan tekst ide u
isti pregled kao otkucan spisak. Umesto pravog servisa radi lažni server na ovom računaru: proverava se kako izgleda
zahtev (ključ, model, slika smanjena na 2000 px, katalog u uputstvu) i šta aplikacija radi sa odgovorima i greškama."""
import base64
import html as htmllib
import json
import re
import struct
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
PAPIR = "/admin/spisak_papir.php"
PROCITANO = "8 Agrocentar\n2 pal humovit 50\n?? humovit\n1/2 rizla bela sitna + 1/2 oblutak beli 40-60\n\n9\n30 humovit 10"

# ── lažni AI servis ──
stanje = {"odgovor": None, "zahtevi": []}


class Servis(BaseHTTPRequestHandler):
    def do_POST(self):
        telo = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        stanje["zahtevi"].append({"putanja": self.path, "zaglavlja": {k.lower(): v for k, v in self.headers.items()}, "telo": json.loads(telo)})
        kod, odg = stanje["odgovor"]
        b = json.dumps(odg).encode()
        self.send_response(kod)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def log_message(self, *a):
        pass


def poruka_servisa(tekst, stop="end_turn"):
    return (200, {"id": "msg_test", "type": "message", "role": "assistant", "model": "claude-opus-5-5", "stop_reason": stop,
                  "content": [{"type": "thinking", "thinking": "", "signature": "x"}, {"type": "text", "text": tekst}]})


servis = HTTPServer(("127.0.0.1", 0), Servis)
threading.Thread(target=servis.serve_forever, daemon=True).start()
URL = "http://127.0.0.1:%d/v1/messages" % servis.server_address[1]


def slika(sirina, visina):
    """JPEG zadate veličine (PHP GD)."""
    r = subprocess.run(["php", "-r", "$i = imagecreatetruecolor(%d, %d); imagefill($i, 0, 0, imagecolorallocate($i, 240, 235, 220)); "
                        "imagestring($i, 5, 20, 20, '8 Agrocentar', 0); ob_start(); imagejpeg($i, null, 80); echo ob_get_clean();" % (sirina, visina)],
                       capture_output=True)
    return r.stdout


def velicina_jpeg(b):
    i = 2
    while i < len(b):
        if b[i] != 0xFF:
            i += 1
            continue
        marker = b[i + 1]
        if marker in (0xC0, 0xC1, 0xC2):
            visina, sirina = struct.unpack(">HH", b[i + 5:i + 9])
            return sirina, visina
        i += 2 + struct.unpack(">H", b[i + 2:i + 4])[0]
    return None


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


site = Site()
site2 = None
try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "", "datum": danas_bg(), "mesto_broj": "8"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")

    R.odeljak("Bez ključa: uputstvo umesto dugmeta")
    o = adm.get(PAPIR + "?id=%d" % sp)
    R.provera("Slikaj spisak" in o.text and "ANTHROPIC_API_KEY" in o.text and 'name="slika"' not in o.text and 'name="tekst"' in o.text,
              "bez ključa: piše šta treba upisati u config.php; kucanje radi kao pre")
    o = adm.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "spisak.jpg", slika(800, 600)))
    R.provera("nije uključeno" in poruke(o), "slanje slike bez ključa: jasna poruka", poruke(o))
    site.stop()

    site2 = Site(config_extra="define('ANTHROPIC_API_KEY', 'sk-ant-test-123');\ndefine('ANTHROPIC_API_URL', '%s');" % URL)
    adm = prijavi_admina(site2)
    jelena = prijavi_radnika(site2, ids["Jelena"], "4321")

    R.odeljak("Slikaj spisak: dugme i zahtev servisu")
    o = adm.get(PAPIR + "?id=%d" % sp)
    R.provera('enctype="multipart/form-data"' in o.text and 'name="slika"' in o.text and 'accept="image/*"' in o.text and "slika_spiska.js" in o.text,
              "dugme „Slikaj spisak“ (kamera ili galerija) i skripta koja smanjuje sliku")
    R.provera("sk-ant-test-123" not in o.text, "ključ se nikad ne šalje pregledaču")
    stanje["odgovor"] = poruka_servisa(PROCITANO)
    velika = slika(4000, 3000)
    o = adm.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "IMG_2041.jpg", velika))
    z = stanje["zahtevi"][-1]
    R.provera(len(stanje["zahtevi"]) == 1 and z["putanja"] == "/v1/messages", "jedan zahtev servisu")
    R.provera(z["zaglavlja"].get("x-api-key") == "sk-ant-test-123" and z["zaglavlja"].get("anthropic-version") == "2023-06-01"
              and z["zaglavlja"].get("anthropic-beta") == "server-side-fallback-2026-07-01", "zaglavlja: ključ, verzija API-ja, rezervni model", z["zaglavlja"])
    t = z["telo"]
    R.provera(t["model"] == "claude-opus-5-5" and t["fallbacks"] == "default" and "thinking" not in t and t["output_config"] == {"effort": "medium"},
              "model claude-opus-5-5, rezervni model po potrebi, bez isključivanja razmišljanja", t.get("model"))
    blok = t["messages"][0]["content"][0]
    jpeg = base64.b64decode(blok["source"]["data"])
    R.provera(blok["type"] == "image" and blok["source"]["media_type"] == "image/jpeg" and velicina_jpeg(jpeg) == (2000, 1500),
              "slika 4000 × 3000 je na serveru smanjena na 2000 × 1500 (JPEG)", velicina_jpeg(jpeg))
    R.provera("Humovit · 50 l" in t["system"] and "Beli oblutak · 4-6 cm · 20 kg" in t["system"] and "`??`" in t["system"] and "Never guess a quantity" in t["system"],
              "uputstvo: katalog sa punim nazivima, ?? za nečitko, bez pogađanja količina")

    R.odeljak("Pročitano sa slike → isti pregled kao otkucan spisak")
    R.provera('id="pregled"' in o.text and "pročitan sa slike" in poruke(o) and "uporedite svaki red" in poruke(o), "pregled, uz upozorenje da se svaki red proveri", poruke(o))
    f = {m.group(1): htmllib.unescape(m.group(2)) for m in re.finditer(r'name="([^"]+)"[^>]*?value="([^"]*)"', o.text)}
    R.provera((f.get("m[0][broj]"), f.get("m[0][kupac]"), f.get("r[0][0][id]"), f.get("r[0][0][pal]")) == ("8", "Agrocentar", str(sku_id("Humovit", 50)), "2"),
              "Mesto 8 · Agrocentar: 2 pal Humovit 50 l", f.get("r[0][0][tekst]"))
    R.provera(f.get("r[0][1][id]") == "" and "papir-proveri" in o.text, "„?? humovit“ (nečitko) je označeno za proveru")
    R.provera(f.get("r[0][2][vrsta]") == "zbirna" and f.get("m[1][broj]") == "9" and f.get("r[1][0][pak]") == "30", "zbirna paleta i Mesto 9: 30 humovit 10 = 30 paketa")
    o = adm.get(PAPIR + "?id=%d&tekst=1" % sp)
    R.provera("?? humovit" in htmllib.unescape(o.text) and "30 humovit 10" in htmllib.unescape(o.text), "„Izmeni tekst“: prepisan tekst može da se ispravi")
    R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp) == 0, "ništa nije upisano dok se ne sačuva")
    R.provera(not list(Path(site2.dir).rglob("*.jpg")), "slika se ne čuva na serveru")

    R.odeljak("Greške")
    for odgovor, poruka, opis in [
        ((401, {"type": "error", "error": {"type": "authentication_error", "message": "invalid x-api-key"}}), "Ključ za čitanje slike nije ispravan", "pogrešan ključ"),
        ((529, {"type": "error", "error": {"type": "overloaded_error", "message": "Overloaded"}}), "trenutno zauzet", "servis preopterećen"),
        (poruka_servisa("", "refusal"), "nije pročitao ovu sliku", "servis odbio"),
        (poruka_servisa("   "), "nije pročitan nijedan red", "prazan prepis"),
    ]:
        stanje["odgovor"] = odgovor
        o = adm.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "s.jpg", slika(800, 600)))
        R.provera(poruka in poruke(o) and 'name="slika"' in o.text, opis + ": poruka, ostaje se na slikanju", poruke(o))
    n = len(stanje["zahtevi"])
    o = adm.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "spisak.txt", b"ovo nije slika"))
    R.provera("To nije slika" in poruke(o) and len(stanje["zahtevi"]) == n, "fajl koji nije slika: odbijen pre slanja servisu", poruke(o))
    o = adm.post(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp})
    R.provera("Izaberite ili slikajte spisak" in poruke(o), "bez slike: poruka")
    servis.shutdown()
    servis.server_close()
    o = adm.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "s.jpg", slika(800, 600)))
    R.provera("nije uspeo da se poveže" in poruke(o), "servis nedostupan: poruka", poruke(o))

    R.odeljak("Pristup")
    o = jelena.post_fajl(PAPIR + "?id=%d" % sp, {"akcija": "slika", "id": sp}, ("slika", "s.jpg", slika(800, 600)))
    R.provera(o.url.endswith("/radnik/index.php") and len(stanje["zahtevi"]) == n, "radnik ne može da šalje slike")

    R.provera(not site2.php_problemi(), "PHP nije prijavio upozorenja ni greške", site2.php_problemi()[:3])
finally:
    site.stop()
    if site2:
        site2.stop()

sys.exit(R.kraj())
