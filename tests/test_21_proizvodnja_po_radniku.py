#!/usr/bin/env python3
"""Proizvodnja po radniku: koliko je ko napravio (podrazumevano danas), po artiklu, u paletama (cele palete + paketi +
komadi, iz broja komada na paleti) i komadima, ukupno po artiklu (ko je koliko), unosi za proveru, drugi period,
izvoz u Excel. Obrisani unosi i prodaja se ne računaju; artikal bez podešene palete ide u komadima."""
import html as htmllib
import re
import sys
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
STR = "/admin/proizvodnja.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def radnik_blok(o, kid):
    m = re.search(r'<details class="kartica proizvodnja-radnik" data-radnik="%d".*?</table>' % kid, o.text, re.S)
    return tekst(m.group(0)) if m else ""


def artikli_blok(o):
    m = re.search(r'id="naslov-artikli".*?</table>', o.text, re.S)
    return tekst(m.group(0)) if m else ""


def proizvedi(klijent, sku, kolicina, nacin):
    return klijent.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": str(kolicina), "nacin": nacin, "kljuc": nov_kljuc()})


try:
    ids = pripremi_sajt(site)
    marko = prijavi_radnika(site, ids["Marko"], "1234")
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    dragan = prijavi_radnika(site, ids["Dragan"], "1111")
    adm = prijavi_admina(site)
    for k in (marko, jelena, dragan):
        k.get("/radnik/index.php")          # žeton forme posle prijave
    h5, h10, h50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 50)
    hp20 = sku_id("Humovit premium", 20)
    crveni = sku_id("Malč Farmerkop", 50, "l", "Crveni")

    R.odeljak("Priprema: proizvodnja danas, juče, obrisan unos, prodaja")
    proizvedi(marko, h50, 2, "palete")
    proizvedi(marko, h10, 1, "palete")
    proizvedi(marko, h50, 1, "palete")
    proizvedi(marko, h10, 3, "paketi")
    proizvedi(jelena, h50, 15, "komadi")
    proizvedi(jelena, crveni, 1, "palete")
    o = proizvedi(jelena, h5, 1, "palete")
    jelenin = sql_int("SELECT MAX(id) FROM unosi WHERE korisnik_id=%d" % ids["Jelena"])
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": jelenin})
    R.provera("obrisan" in poruke(o), "Jelena briše pogrešan unos (1 paleta Humovit 5 l)", poruke(o))
    sql("UPDATE sku SET po_paleti=NULL WHERE id=%d" % hp20)
    proizvedi(dragan, hp20, 30, "komadi")
    juce = (date.fromisoformat(danas_bg()) - timedelta(days=1)).isoformat()
    sql("INSERT INTO unosi (tip, sku_id, kolicina, palete, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 900, 2, %d, '%s 12:00:00', NOW())"
        % (h5, ids["Dragan"], juce))
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Agrocentar", "sku_id": h50, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})
    R.provera("Prodaja sačuvana" in poruke(o), "prodaja (ne računa se u proizvodnju)", poruke(o))

    R.odeljak("Danas: palete po radniku i artiklu")
    o = adm.get(STR)
    t = tekst(o.text)
    R.provera(o.status == 200 and 'aria-current="true">Danas<' in o.text, "otvara se za danas")
    R.provera("Danas: ≈ 5,4 paleta" in t and "493 kom" in t and "3 radnika" in t and "30 kom artikala bez podešene palete" in t,
              "ukupno danas ≈ 5,4 paleta (493 kom, 3 radnika; 30 kom bez palete)", t[:400])
    marko_t = radnik_blok(o, ids["Marko"])
    R.provera("Marko – ≈ 4,1 paleta" in marko_t and "408 kom · 4 unosa" in marko_t, "Marko: ≈ 4,1 paleta, 408 kom, 4 unosa", marko_t[:200])
    R.provera("Humovit · 10 l 1 pal + 3 pak 288 Humovit · 50 l 3 pal 120" in marko_t, "Marko: Humovit 10 l = 1 pal + 3 pak (288), Humovit 50 l = 3 pal (120), redom kao u katalogu", marko_t)
    jelena_t = radnik_blok(o, ids["Jelena"])
    R.provera("Jelena – ≈ 1,4 paleta" in jelena_t and "Humovit · 50 l 15 kom 15" in jelena_t and "Malč Farmerkop · Crveni · 50 l 1 pal 40" in jelena_t,
              "Jelena: 15 kom Humovita 50 l i 1 paleta crvenog malča (obrisan unos se ne računa)", jelena_t)
    dragan_t = radnik_blok(o, ids["Dragan"])
    R.provera("Humovit premium · 20 l 30 kom 30" in dragan_t, "artikal bez podešene palete: u komadima", dragan_t)
    poz = [o.text.index('data-radnik="%d"' % ids[i]) for i in ("Marko", "Jelena", "Dragan")]
    R.provera(poz == sorted(poz), "ko je napravio više paleta je prvi")
    art = artikli_blok(o)
    R.provera("Humovit · 50 l Marko 3 pal · Jelena 15 kom 3 pal + 15 kom 135" in art and "Humovit · 10 l Marko 1 pal + 3 pak 1 pal + 3 pak 288" in art,
              "po artiklu: Humovit 50 l = 3 pal + 15 kom (Marko 3 pal, Jelena 15 kom)", art)
    R.provera("40 kom (1 paleta)" in o.text and "80 kom (2 palete)" in o.text and "18 kom (3 paketa)" in o.text, "unosi: kako je upisano (palete, paketi)")
    R.provera('href="/admin/istorija.php?tip=proizvodnja&amp;radnik=%d&amp;od=%s&amp;do=%s"' % (ids["Marko"], danas_bg(), danas_bg()) in o.text, "veza na Istoriju (ispravka)")
    R.provera("Agrocentar" not in t, "prodaja se ne računa")

    R.odeljak("Drugi period i izvoz")
    o = adm.get(STR + "?od=%s&do=%s" % (juce, juce))
    R.provera('aria-current="true">Juče<' in o.text and "Ukupno: 2 palete" in tekst(o.text) and "Humovit · 5 l 2 pal 900" in radnik_blok(o, ids["Dragan"])
              and "Marko" not in tekst(o.text), "juče: samo Dragan, 2 palete Humovita 5 l", tekst(o.text)[:300])
    o = adm.get(STR + "?od=%s&do=%s" % (danas_bg(), juce))
    R.provera("Ukupno: ≈ 7,4 paleta" in tekst(o.text) and "1.393 kom" in tekst(o.text), "od–do (obrnuti datumi se zamene): juče + danas", tekst(o.text)[:300])
    o = adm.get(STR + "?od=2020-01-01&do=2020-01-02")
    R.provera("U ovom periodu nema proizvodnje" in o.text, "period bez proizvodnje: poruka")
    o = adm.get(STR + "?csv=1")
    redovi = o.text.lstrip("﻿").strip().split("\r\n")
    R.provera(o.header("Content-Type").startswith("text/csv") and redovi[0].startswith('"Od";"Do";"Radnik";"Artikal"') and len(redovi) == 6
              and any(r.endswith(';"Marko";"Humovit";"";"10 l";288;1,07;"1 pal + 3 pak"') for r in redovi), "izvoz u Excel: po radniku i artiklu", redovi)

    R.odeljak("Veze i pristup")
    R.provera('href="/admin/proizvodnja.php"' in adm.get("/admin/stanje.php").text, "meni ☰: Proizvodnja po radniku")
    o = adm.get("/admin/radnici.php")
    R.provera('href="/admin/proizvodnja.php"' in o.text and "Danas – palete po radniku" in o.text, "Radnici: dugme „Danas – palete po radniku“")
    o = marko.get(STR)
    R.provera(o.url.endswith("/radnik/index.php") and "Jelena" not in o.text, "radnik ne vidi pregled")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
