#!/usr/bin/env python3
"""
Pravi test sajt sa uzorkom podataka i snima ekrane kao telefon.
Upotreba: python3 tests/ekrani.py IZLAZNI_FOLDER [tok1,tok2,...]
"""
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa


def pripremi(site):
    return pripremi_sajt(site)


def demo_podaci(ids):
    """Realni uzorak: proizvodnja i prodaja za danas, sa dva artikla ispod minimuma."""
    admin = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")
    sql("INSERT IGNORE INTO kupci (naziv, poslednja_prodaja, napravljen) VALUES ('Cvećara Žika', NOW(), NOW()), ('Agrocentar Novi Sad', NOW() - INTERVAL 1 DAY, NOW()), ('Vrt i bašta Kraljevo', NOW() - INTERVAL 2 DAY, NOW())")
    kupac = sql_int("SELECT id FROM kupci WHERE naziv='Cvećara Žika'")

    def unos(tip, sku, kol, radnik, palete=None, minuta=60, kupac_id=None, napomena=None):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, palete, korisnik_id, kupac_id, napomena, nastalo, uneto) VALUES ('%s', %d, %d, %s, %d, %s, %s, DATE_SUB(NOW(), INTERVAL %d MINUTE), DATE_SUB(NOW(), INTERVAL %d MINUTE))" % (
            tip, sku, kol, palete if palete else "NULL", radnik, kupac_id if kupac_id else "NULL", ("'%s'" % napomena) if napomena else "NULL", minuta, minuta))

    J, D = ids["Jelena"], ids["Dragan"]
    unos("proizvodnja", sku_id("Humovit", 5), 900, J, 2, 240)
    unos("proizvodnja", sku_id("Humovit", 10), 270, J, 1, 200)
    unos("proizvodnja", sku_id("Humovit", 50), 80, D, None, 150)
    unos("proizvodnja", sku_id("Humovit premium", 20), 240, D, 2, 130)
    unos("proizvodnja", sku_id("Idea", 10), 225, J, 1, 120)
    unos("proizvodnja", sku_id("Idea", 5), 450, J, 1, 110)
    unos("proizvodnja", sku_id("Floris Savacoop", 20), 120, D, 1, 100)
    unos("proizvodnja", sku_id("Malč Farmerkop", 50, "l", "Crveni"), 40, D, 1, 90)
    unos("proizvodnja", sku_id("Malč Farmerkop", 50, "l", "Braon"), 80, D, 2, 85)
    unos("proizvodnja", sku_id("Malč Farmerkop", 50, "l", "Zeleni"), 23, D, None, 80)
    unos("proizvodnja", sku_id("Beli oblutak", 20, "kg", "1-3 cm"), 100, J, 2, 70)
    unos("proizvodnja", sku_id("Beli oblutak", 20, "kg", "2-4 cm"), 50, J, 1, 60)
    unos("prodaja", sku_id("Humovit", 5), 120, admin, None, 50, kupac, "otpremnica 41")
    unos("prodaja", sku_id("Malč Farmerkop", 50, "l", "Zeleni"), 20, admin, None, 45, kupac)
    unos("kucna_prodaja", sku_id("Humovit", 10), 15, D, None, 30, None, "komšija, gotovina")
    unos("kucna_prodaja", sku_id("Beli oblutak", 20, "kg", "1-3 cm"), 5, D, None, 20)
    sql("UPDATE sku SET min_zaliha=400 WHERE id=%d" % sku_id("Humovit", 10))
    sql("UPDATE sku SET min_zaliha=20 WHERE id=%d" % sku_id("Malč Farmerkop", 50, "l", "Zeleni"))
    sql("UPDATE sku SET min_zaliha=300 WHERE id=%d" % sku_id("Humovit", 5))
    sql("UPDATE sku SET min_zaliha=100 WHERE id=%d" % sku_id("Idea", 10))


def nazivi_tokova():
    """Nazivi tokova iz ekrani_tokovi.cjs, redom."""
    tekst = (Path(__file__).parent / "ekrani_tokovi.cjs").read_text(encoding="utf-8")
    return re.findall(r"^    async (\w+)\(page, p\)", tekst, re.M)


if __name__ == "__main__":
    izlaz = sys.argv[1] if len(sys.argv) > 1 else "/tmp/ekrani"
    trazeni = [t for t in (sys.argv[2] if len(sys.argv) > 2 else "").split(",") if t] or nazivi_tokova()
    os.makedirs(izlaz, exist_ok=True)
    kod = 0
    site = None
    try:
        reset_db()
        site = Site()
        env = dict(os.environ, NODE_PATH="/opt/node-tools/node_modules")
        for tok in trazeni:
            for tema in ("light", "dark"):
                # svaki tok i režim kreće od čiste baze sa istim podacima
                reset_db()
                ids = pripremi(site)
                if os.environ.get("DEMO", "1") == "1":
                    demo_podaci(ids)
                print("▶ tok %s (%s)" % (tok, tema), flush=True)
                r = subprocess.run(["node", str(Path(__file__).parent / "ekrani.cjs"), site.base, izlaz, tok, tema], env=env)
                kod = kod or r.returncode
        problemi = site.php_problemi()
        if problemi:
            print("PHP problemi:", *problemi, sep="\n  ")
        sys.exit(kod or (1 if problemi else 0))
    finally:
        if site:
            site.stop()
