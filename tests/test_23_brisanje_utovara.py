#!/usr/bin/env python3
"""Spiskovi za utovar: brojevi Utovar 1, 2, 3 … redom, bez rupa (posle brisanja se prenumerišu), najnoviji gore,
brisanje bilo kog spiska (kanta na listi i na stranici spiska), utovaren spisak – „vrati robu na stanje“ ili
„roba je otišla“, zaštita kad se status promenio u međuvremenu i „Obriši sve otkazane“."""
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
SP = "/admin/spisak.php"
UT = "/admin/utovar.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def kartice(o):
    """Spiskovi na listi, redom: [(id, "Utovar N")]."""
    return [(int(i), n) for i, n in re.findall(r'<a class="spisak-link" href="/admin/spisak\.php\?id=(\d+)"><strong>(Utovar \d+)</strong>', o.text)]


def novi_spisak(kupac, datum=None):
    o = adm.post(UT, {"kupac": kupac, "datum": datum or danas_bg(), "mesto_broj": "1"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    return sp, sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp), o


def dodaj(sp, mesto, artikal, kom):
    adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": mesto, "paleta_id": "0",
                  "r_tekst[]": [artikal], "r_id[]": [""], "r_pal[]": [""], "r_pak[]": [""], "r_kom[]": [kom]})


def status(sp, ocekivan, novi):
    return adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": ocekivan, "novi": novi})


def utovari(sp):
    jelena.get("/radnik/utovar.php?id=%d" % sp)
    f = {"id": sp}
    for sid in sql("SELECT id FROM spisak_stavke WHERE spisak_id=%d" % sp).split():
        f["ok[%s]" % sid] = "1"
    return jelena.post("/radnik/utovar.php", f)


def postoji(sp):
    return sql_int("SELECT COUNT(*) FROM spiskovi WHERE id=%d" % sp) == 1


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum10, hum50 = sku_id("Humovit", 10), sku_id("Humovit", 50)
    for sku in (hum10, hum50):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 200, 1, NOW(), NOW())" % sku)
    adm.get(UT)

    R.odeljak("Brojevi: Utovar 1, 2, 3 …, najnoviji gore")
    juce = (date.fromisoformat(danas_bg()) - timedelta(days=1)).isoformat()
    sps = []
    for i, kupac in enumerate(["Prvi kupac", "Drugi kupac", "Treći kupac", "Četvrti kupac", "Peti kupac"]):
        sp, _, o = novi_spisak(kupac, juce if i == 2 else None)
        sps.append(sp)
        R.provera("Utovar %d je napravljen sa mestom „Mesto 1 · %s“" % (i + 1, kupac) in poruke(o), "%d. spisak: Utovar %d" % (i + 1, i + 1), poruke(o))
    o = adm.get(UT)
    R.provera(kartice(o) == [(sps[4], "Utovar 5"), (sps[3], "Utovar 4"), (sps[2], "Utovar 3"), (sps[1], "Utovar 2"), (sps[0], "Utovar 1")],
              "lista: najnoviji gore, redom kako su pravljeni (i spisak sa jučerašnjim datumom je na svom mestu)", kartice(o))
    R.provera('class="spisak-pod">#' not in o.text and "Prvi kupac" in o.text, "na kartici piše Utovar N i kupac (nema više #broj)")
    o = adm.get(SP + "?id=%d" % sps[2])
    R.provera("<title>Utovar 3 · " in o.text, "stranica spiska: naslov Utovar 3")
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sps[2])
    R.provera("<h1>Utovar 3</h1>" in o.text and "spisak za utovar" in tekst(o.text) and "Farmerkop – Utovar 3, spisak za utovar" in htmllib.unescape(o.text),
              "štampa i tekst za deljenje: Utovar 3")

    R.odeljak("Brisanje nacrta kantom sa liste: ostali se prenumerišu")
    o = adm.get(UT)
    kartica = o.text.split('href="/admin/spisak.php?id=%d"' % sps[1])[1].split('class="spisak-kartica')[0]
    R.provera('name="akcija" value="obrisi"' in kartica and 'name="ocekivan" value="nacrt"' in kartica
              and 'data-potvrda="Obrisati Utovar 2 (Drugi kupac)? Ovo ne može da se vrati."' in kartica and 'aria-label="Obriši Utovar 2"' in kartica,
              "kartica ima kantu sa potvrdom „Obrisati Utovar 2 (Drugi kupac)?“")
    o = adm.post(UT, {"akcija": "obrisi", "id": sps[1], "ocekivan": "nacrt", "f": "aktivni"})
    R.provera("Obrisan je Utovar 2 (Drugi kupac). Utovari posle njega dobili su broj manje" in poruke(o) and o.url.endswith(UT) and not postoji(sps[1])
              and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sps[1]) == 0, "nacrt obrisan jednim dodirom (i potvrdom), poruka i nazad na listu", poruke(o))
    R.provera(kartice(o) == [(sps[4], "Utovar 4"), (sps[3], "Utovar 3"), (sps[2], "Utovar 2"), (sps[0], "Utovar 1")], "brojevi su opet redom: 4, 3, 2, 1 (bez rupe)", kartice(o))
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Spisak #%d: obrisan nacrt (Utovar 2, Drugi kupac, 0 stavki)'" % sps[1]) == 1, "brisanje je u dnevniku")

    R.odeljak("Spisak za utovar: brisanje ukida rezervaciju")
    m3 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sps[2])
    dodaj(sps[2], m3, "humovit 10", "30")
    status(sps[2], "nacrt", "za_utovar")
    R.provera("Treći kupac" in jelena.get("/radnik/utovar.php").text and "rezervisano 30" in adm.get("/admin/stanje.php").text, "radnik vidi spisak, 30 kom je rezervisano")
    o = adm.post(UT, {"akcija": "obrisi", "id": sps[2], "ocekivan": "za_utovar", "f": "aktivni"})
    R.provera("Obrisan je Utovar 2 (Treći kupac). Rezervacija je ukinuta, radnici ga više ne vide." in poruke(o) and not postoji(sps[2]), "obrisan spisak za utovar", poruke(o))
    R.provera("Treći kupac" not in jelena.get("/radnik/utovar.php").text and "rezervisano 30" not in adm.get("/admin/stanje.php").text and stanje(hum10) == 200,
              "radnik ga više ne vidi, rezervacija ukinuta, stanje isto")

    R.odeljak("Status se promenio u međuvremenu: ništa se ne briše")
    sp4, m4 = sps[3], sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sps[3])
    dodaj(sp4, m4, "humovit 10", "30")
    status(sp4, "nacrt", "za_utovar")
    o = utovari(sp4)
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp4) == "utovareno" and stanje(hum10) == 170, "radnik je potvrdio utovar (Humovit 10 l: 200 − 30)", poruke(o))
    o = adm.post(UT, {"akcija": "obrisi", "id": sp4, "ocekivan": "za_utovar", "f": "aktivni"})
    R.provera("u međuvremenu je promenio status (sada: Utovareno)" in poruke(o) and postoji(sp4) and stanje(hum10) == 170,
              "brisanje sa stare stranice (video „za utovar“) se odbija – spisak je u međuvremenu utovaren", poruke(o))
    o = adm.post(SP, {"akcija": "obrisi_spisak", "id": sp4, "ocekivan": "utovareno"})
    R.provera("izaberite da li se roba vraća na stanje" in poruke(o) and postoji(sp4), "utovaren bez izbora šta je sa robom: odbijeno", poruke(o))

    R.odeljak("Utovaren: obriši i vrati robu na stanje")
    o = adm.get(UT + "?f=utovareno")
    kartica = o.text.split('href="/admin/spisak.php?id=%d"' % sp4)[1].split('class="spisak-kartica')[0]
    R.provera('href="/admin/spisak.php?id=%d#brisanje"' % sp4 in kartica and 'name="akcija" value="obrisi"' not in kartica,
              "kanta na utovarenom vodi na stranicu spiska (tamo se bira šta je sa robom)")
    o = adm.get(SP + "?id=%d" % sp4)
    R.provera('id="brisanje"' in o.text and "Obriši i vrati robu na stanje" in o.text and "Obriši, roba je otišla" in o.text
              and o.text.count('name="akcija" value="obrisi_spisak"') == 2, "stranica spiska: dva dugmeta za brisanje")
    o = adm.post(SP, {"akcija": "obrisi_spisak", "id": sp4, "ocekivan": "utovareno", "nacin": "vrati"})
    R.provera("Obrisan je Utovar 2 (Četvrti kupac). Roba je vraćena na stanje." in poruke(o) and o.url.endswith(UT + "?f=utovareno") and not postoji(sp4),
              "obrisan, nazad na listu utovarenih", poruke(o))
    R.provera(stanje(hum10) == 200, "Humovit 10 l: vraćeno 30 (opet 200)", stanje(hum10))
    R.provera(sql("SELECT CONCAT(obrisan, '|', IFNULL(spisak_id, 'NULL')) FROM unosi WHERE tip='utovar' AND napomena LIKE 'Mesto 1 · obrisan spisak (Četvrti kupac, utovar %% – roba vraćena na stanje'") == "1|NULL",
              "unos utovara je označen kao obrisan, sa napomenom")
    d = sql("SELECT detalji FROM dnevnik WHERE akcija='spisak' AND objekat_id=%d ORDER BY id DESC LIMIT 1" % sp4)
    R.provera(d.startswith("Spisak #%d: obrisan utovaren spisak (Utovar 2, Četvrti kupac, 1 stavka); roba vraćena na stanje" % sp4), "u dnevniku", d)

    R.odeljak("Utovaren: obriši, roba je otišla")
    sp5, m5 = sps[4], sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sps[4])
    dodaj(sp5, m5, "humovit 50", "10")
    status(sp5, "nacrt", "za_utovar")
    utovari(sp5)
    R.provera(stanje(hum50) == 190, "Peti kupac: utovareno 10 × Humovit 50 l")
    o = adm.post(SP, {"akcija": "obrisi_spisak", "id": sp5, "ocekivan": "utovareno", "nacin": "ostaje"})
    R.provera("Obrisan je Utovar 2 (Peti kupac). Stanje se ne menja (roba je otišla); unosi utovara ostaju u Istoriji." in poruke(o) and not postoji(sp5),
              "obrisan; poruka da stanje ostaje", poruke(o))
    R.provera(stanje(hum50) == 190, "stanje ostaje 190 (roba je otišla)", stanje(hum50))
    R.provera(sql("SELECT CONCAT(u.obrisan, '|', IFNULL(u.spisak_id, 'NULL'), '|', k.naziv) FROM unosi u JOIN kupci k ON k.id = u.kupac_id WHERE u.tip='utovar' AND u.sku_id=%d" % hum50) == "0|NULL|Peti kupac",
              "unos utovara ostaje (nije obrisan), vodi se na kupca, bez veze na obrisan spisak")
    o = adm.get("/admin/istorija.php?od=&do=&tip=utovar")
    red = tekst(o.text.split("Humovit · 50 l")[1].split("</li>")[0])
    R.provera("Peti kupac" in red and "Mesto 1 · spisak obrisan (Peti kupac, utovar" in red and "roba je otišla" in red and "Ispravi" in red, "u Istoriji: kupac, napomena, može da se ispravi", red)
    R.provera("Peti kupac" in adm.get("/admin/kupci.php").text, "Kupci po godini: i dalje se računa kupcu")

    R.odeljak("Obriši sve otkazane")
    spX, mX, _ = novi_spisak("Kupac X")
    dodaj(spX, mX, "humovit 50", "5")
    status(spX, "nacrt", "za_utovar")
    utovari(spX)
    status(spX, "utovareno", "otkazano")                         # utovaren pa otkazan: povrat
    spY, _, _ = novi_spisak("Kupac Y")
    status(spY, "nacrt", "otkazano")
    spZ, mZ, _ = novi_spisak("Kupac Z")
    dodaj(spZ, mZ, "humovit 10", "5")
    pre = (stanje(hum10), stanje(hum50))
    o = adm.get(UT + "?f=otkazano")
    R.provera("Obriši sve otkazane (2)" in o.text and 'data-potvrda="Obrisati sve otkazane spiskove (2)? Ovo ne može da se vrati."' in o.text, "dugme „Obriši sve otkazane (2)“ uz potvrdu")
    R.provera("Obriši sve otkazane" not in adm.get(UT).text, "na listi aktivnih nema tog dugmeta")
    o = adm.post(UT, {"akcija": "obrisi_otkazane", "f": "otkazano"})
    R.provera("Obrisano otkazanih spiskova: 2." in poruke(o) and o.url.endswith(UT + "?f=otkazano") and not postoji(spX) and not postoji(spY) and postoji(spZ),
              "obrisana oba otkazana, nacrt ostaje", poruke(o))
    R.provera((stanje(hum10), stanje(hum50)) == pre, "stanje se ne menja")
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id IS NULL AND obrisan=1 AND napomena LIKE '%%obrisan otkazan spisak (Kupac X, utovar %%'") == 2,
              "utovar i povrat Kupca X su označeni kao obrisani")
    o = adm.get(UT + "?f=svi")
    R.provera(kartice(o) == [(spZ, "Utovar 2"), (sps[0], "Utovar 1")], "svi: Utovar 2 (Kupac Z), Utovar 1 (Prvi kupac)", kartice(o))
    o = adm.post(UT, {"akcija": "obrisi_otkazane", "f": "otkazano"})
    R.provera("Nema otkazanih spiskova." in poruke(o), "ponovo: nema otkazanih", poruke(o))

    R.odeljak("Radnik ne može da briše")
    jelena.get("/radnik/utovar.php")
    jelena.post(UT, {"akcija": "obrisi", "id": spZ, "ocekivan": "nacrt"})
    jelena.post(SP, {"akcija": "obrisi_spisak", "id": spZ, "ocekivan": "nacrt"})
    R.provera(postoji(spZ), "spisak je i dalje tu")

    R.odeljak("Dnevnik i Istorija: Utovar N umesto #broja")
    o = adm.get("/admin/dnevnik.php")
    R.provera('>Utovar 2</a>' in o.text and "obrisan spisak" in o.text and "Spisak #" not in o.text and "spisak #" not in o.text,
              "dnevnik: veza „Utovar 2“ za postojeći spisak, „obrisan spisak“ za obrisan, bez #broja")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, napomena, nastalo, uneto) VALUES "
        "('proizvodnja', %d, 1, 1, 'Spisak #77 – mesto 8', NOW(), NOW()), ('proizvodnja', %d, 1, 1, 'Otkazan spisak #77', NOW(), NOW())" % (hum10, hum10))
    o = adm.get("/admin/istorija.php?od=&do=")
    t = tekst(o.text)
    R.provera("· Mesto 8" in t and "· Otkazan spisak" in t and "#77" not in t, "stare napomene bez starog broja spiska („Mesto 8“, „Otkazan spisak“)")
    status(spZ, "nacrt", "za_utovar")
    o = jelena.get("/radnik/utovar.php")
    R.provera("<strong>Utovar 2</strong>" in o.text and "Kupac Z" in o.text and "#%d" % spZ not in tekst(o.text), "radnik: kartica Utovar 2, Kupac Z")
    R.provera("<title>Utovar 2 · " in jelena.get("/radnik/utovar.php?id=%d" % spZ).text, "radnik: stranica utovara „Utovar 2“")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
