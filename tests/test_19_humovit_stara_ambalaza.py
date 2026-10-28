#!/usr/bin/env python3
"""Nadogradnja v10: "Humovit S (stara ambalaža)" – Humovit 25 l i 50 l u staroj ambalaži kao poseban artikal (sa paletama,
paketima i težinom kao Humovit), odmah posle Humovita; unos, stanje, izbor kucanjem ("humovit s 50") i spisak sa papira."""
import html as htmllib
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
STARA = "Humovit S (stara ambalaža)"
SP = "/admin/spisak.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def verzija():
    return sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")


try:
    ids = pripremi_sajt(site)
    R.odeljak("Nadogradnja na verziju 10")
    R.provera(verzija() == "12", "nova instalacija je na verziji 12")
    sql("UPDATE podesavanja SET vrednost='9' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and site.log().count("nadogradnja baze na verziju 10") == 1, "posle prvog otvaranja: nadogradnja 10 (i dalje), verzija 12")
    hum = sql("SELECT CONCAT(id, '|', kategorija_id, '|', redosled) FROM artikli WHERE naziv='Humovit'").split("|")
    st = sql("SELECT CONCAT(id, '|', kategorija_id, '|', redosled, '|', aktivan) FROM artikli WHERE naziv='%s'" % STARA).split("|")
    R.provera(st[1:] == hum[1:] + ["1"], "Humovit S (stara ambalaža): ista kategorija kao Humovit, uključen", (st, hum))
    opis = sql("SELECT GROUP_CONCAT(CONCAT(p.kolicina + 0, ' ', p.jedinica, ':', IFNULL(s.po_paleti, '-'), ':', IFNULL(s.po_paketu, '-'), ':', IFNULL(s.tezina_kg, '-')) "
               "ORDER BY p.kolicina SEPARATOR ', ') FROM sku s JOIN pakovanja p ON p.id=s.pakovanje_id WHERE s.artikal_id=%s" % st[0])
    kao_humovit = sql("SELECT GROUP_CONCAT(CONCAT(p.kolicina + 0, ' ', p.jedinica, ':', IFNULL(s.po_paleti, '-'), ':', IFNULL(s.po_paketu, '-'), ':', IFNULL(s.tezina_kg, '-')) "
                      "ORDER BY p.kolicina SEPARATOR ', ') FROM sku s JOIN pakovanja p ON p.id=s.pakovanje_id WHERE s.artikal_id=%s AND p.kolicina IN (25, 50)" % hum[0])
    R.provera(opis == kao_humovit and opis.startswith("25.00 l:120:") and ", 50.00 l:40:" in opis, "pakovanja 25 l i 50 l, palete/paketi/težina kao kod Humovita: " + opis, kao_humovit)
    sql("UPDATE podesavanja SET vrednost='9' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(sql_int("SELECT COUNT(*) FROM artikli WHERE naziv='%s'" % STARA) == 1, "ponovljena nadogradnja ne pravi duplikat")
    s25, s50 = sku_id(STARA, 25), sku_id(STARA, 50)
    h50 = sku_id("Humovit", 50)

    R.odeljak("Unos i stanje")
    marko = prijavi_radnika(site, ids["Marko"], "1234")
    o = marko.get("/radnik/index.php")
    R.provera(STARA in htmllib.unescape(o.text), "radnik vidi Humovit S (stara ambalaža) za unos")
    o = marko.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": s50, "kolicina": "2", "nacin": "palete", "kljuc": nov_kljuc()})
    R.provera(stanje(s50) == 80 and stanje(h50) == 0, "2 palete stare ambalaže 50 l = 80 kom, odvojeno od Humovita 50 l", poruke(o))
    adm = prijavi_admina(site)
    o = adm.get("/admin/stanje.php")
    t = tekst(o.text.split('class="kat-naslov"', 1)[1])          # lista stanja (bez kartice „Danas“ iznad nje)
    R.provera(STARA in t and t.index("Humovit") < t.index(STARA) < t.index("Humovit premium"), "Stanje: odmah posle Humovita, pre Humovita premium")

    R.odeljak("Spisak: kucanje i spisak sa papira")
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "", "datum": danas_bg(), "mesto_broj": "1"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m1 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)
    for unos, sku, opis in [("humovit s 50", s50, "„humovit s 50“ → stara ambalaža 50 l"), ("humovit stara 25", s25, "„humovit stara 25“ → stara ambalaža 25 l"),
                            ("humovit 50", h50, "„humovit 50“ i dalje je nova ambalaža")]:
        sql("DELETE FROM spisak_stavke WHERE spisak_id=%d" % sp)
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m1, "paleta_id": "0", "artikal": unos, "kom": "1"})
        R.provera(sql("SELECT sku_id FROM spisak_stavke WHERE spisak_id=%d" % sp) == str(sku), opis, poruke(o))
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('"Humovit S (stara ambal\\u017ea)"' in o.text or "Humovit S (stara ambalaža)" in htmllib.unescape(o.text), "u podacima za izbor (dugmad 25 l / 50 l)")
    o = adm.post("/admin/spisak_papir.php", {"akcija": "procitaj", "id": sp, "tekst": "1\n2 pal humovit s 50\n1 pal humovit 50", "bez_oznake": "kom"})
    f = {m.group(1): htmllib.unescape(m.group(2)) for m in re.finditer(r'name="([^"]+)"[^>]*?value="([^"]*)"', o.text)}
    R.provera((f.get("r[0][0][id]"), f.get("r[0][0][pal]"), f.get("r[0][1][id]")) == (str(s50), "2", str(h50)), "spisak sa papira: „humovit s 50“ = stara, „humovit 50“ = nova", f)

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
