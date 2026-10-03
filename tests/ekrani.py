#!/usr/bin/env python3
"""
Pravi test sajt sa uzorkom podataka i snima ekrane kao telefon.
Upotreba: python3 tests/ekrani.py IZLAZNI_FOLDER [tok1,tok2,...]
"""
import os
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa


def pripremi(site):
    """Instalira aplikaciju i ubacuje radnike."""
    c = Client(site.base)
    c.get("/install.php")
    c.post("/install.php", {"kljuc": INSTALL_KLJUC, "ime": "Vlasnik", "korisnicko_ime": ADMIN_USER,
                            "sifra": ADMIN_PASS, "sifra2": ADMIN_PASS, "katalog": "1"})
    for ime, pin in [("Marko", "1234"), ("Jelena", "4321"), ("Dragan", "1111")]:
        sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','%s','%s',1,'2026-01-01 00:00:00')" % (ime, php_hes(pin)))


if __name__ == "__main__":
    izlaz = sys.argv[1] if len(sys.argv) > 1 else "/tmp/ekrani"
    tokovi = sys.argv[2] if len(sys.argv) > 2 else ""
    os.makedirs(izlaz, exist_ok=True)
    reset_db()
    site = Site()
    try:
        pripremi(site)
        env = dict(os.environ, NODE_PATH="/opt/node-tools/node_modules")
        r = subprocess.run(["node", str(Path(__file__).parent / "ekrani.cjs"), site.base, izlaz, tokovi], env=env)
        problemi = site.php_problemi()
        if problemi:
            print("PHP problemi:", *problemi, sep="\n  ")
        sys.exit(r.returncode or (1 if problemi else 0))
    finally:
        site.stop()
