#!/usr/bin/env python3
"""Faza 3: administrator – kartice Stanje i Prodaja."""
import concurrent.futures
import html as htmllib
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()


def tekst(h):
    return htmllib.unescape(re.sub(r"<[^>]+>", "", h)).strip()


def stanje_redovi(html):
    """{ 'Artikal': {'oznake': [...], 'ukupno': '...', 'redovi': {'10 l': {...}} } } iz stranice Stanje."""
    izlaz = {}
    for blok in html.split('<details class="kartica artikal-kartica"')[1:]:
        ime = tekst(re.search(r"<h3>(.*?)</h3>", blok, re.S).group(1))
        ime = re.sub(r"\s+", " ", ime)
        glava_ukupno = tekst(re.search(r'<span class="ukupno">(.*?)</span>\s*</summary>', blok, re.S).group(1))
        redovi = {}
        for r in blok.split('<div class="sku-red')[1:]:
            klase = r.split('"', 1)[0]
            naziv = tekst(re.search(r'sku-ime">(.*?)</div>', r, re.S).group(1))
            naziv = re.sub(r"\s+", " ", naziv)
            st = tekst(re.search(r'sku-stanje">(.*?)</div>', r, re.S).group(1))
            pod = [tekst(x) for x in re.findall(r'class="sku-(?:pal|upoz)">(.*?)</div>', r, re.S)]
            danas = tekst(re.search(r'sku-meta">(.*?)</div>', r, re.S).group(1))
            redovi[naziv.replace(" isključen", "")] = {"nisko": "nisko" in klase, "stanje": st, "pod": pod, "danas": re.sub(r"\s+", " ", danas), "isključen": "isključen" in naziv}
        izlaz[ime.replace(" PL", "").replace(" isključen", "")] = {"ukupno": re.sub(r"\s+", " ", glava_ukupno), "redovi": redovi, "ime_puno": ime}
    return izlaz


try:
    ids = pripremi_sajt(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    marko = prijavi_radnika(site, ids["Marko"], "1234")
    adm = prijavi_admina(site)
    admin_id = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")

    def radnik_unos(c, sku, kol, nacin="komadi", strana="/radnik/index.php"):
        c.get(strana)
        return c.post(strana, {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})

    def prodaj(c, kupac, sku, kol, nacin="komadi", napomena="", kljuc=None, slediti=True):
        c.get("/admin/prodaja.php")
        return c.post("/admin/prodaja.php", {"kupac": kupac, "sku_id": sku, "kolicina": kol, "nacin": nacin, "napomena": napomena, "kljuc": kljuc or nov_kljuc()}, slediti=slediti)

    def poruke(o):
        return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))

    hum10 = sku_id("Humovit", 10)
    prem20 = sku_id("Humovit premium", 20)
    idea10 = sku_id("Idea", 10)
    hum5 = sku_id("Humovit", 5)
    malc_crv = sku_id("Malč Farmerkop", 50, "l", "Crveni")

    # ── 1. Stanje: prazno ────────────────────────────────────────────────────
    R.odeljak("Stanje – prazno")
    o = adm.get("/admin/stanje.php")
    R.provera(o.status == 200 and "Farmerkop · Vlasnik" in o.text, "stranica Stanje se otvara")
    R.provera(o.text.count('<div class="sku-red') == 29, "prikazano je 29 aktivnih SKU-ova (bez isključene Cmane)", o.text.count('<div class="sku-red'))
    st = stanje_redovi(o.text)
    R.provera(list(st.keys()) == ["Humovit", "Humovit premium", "Floris Savacoop", "Idea", "Malč Farmerkop", "Malč Floris Savacoop", "Beli oblutak"], "artikli idu redom iz kataloga", list(st.keys()))
    R.provera(list(st["Humovit"]["redovi"].keys()) == ["5 l", "10 l", "25 l", "50 l"], "Humovit po pakovanju: 5, 10, 25, 50 l", list(st["Humovit"]["redovi"].keys()))
    R.provera(list(st["Humovit premium"]["redovi"].keys()) == ["20 l", "50 l"], "Humovit premium: 20 i 50 l")
    R.provera(list(st["Idea"]["redovi"].keys()) == ["5 l", "10 l", "20 l", "25 l"], "Idea: 5, 10, 20, 25 l")
    R.provera(len(st["Malč Farmerkop"]["redovi"]) == 7 and "Crveni · 50 l" in st["Malč Farmerkop"]["redovi"], "malč po bojama", list(st["Malč Farmerkop"]["redovi"].keys()))
    R.provera("1-3 cm · 20 kg" in st["Beli oblutak"]["redovi"] and "4-7 cm (krupnija) · 20 kg" in st["Beli oblutak"]["redovi"], "oblutak po granulaciji u cm")
    R.provera("Cmana" not in o.text, "isključena Cmana se ne prikazuje")
    R.provera('znacka-pl">PL' in o.text, "PL oznaka se vidi")
    R.provera(all(r["stanje"] == "0" for a in st.values() for r in a["redovi"].values()), "sva stanja su 0")
    R.provera(all(not r["nisko"] for a in st.values() for r in a["redovi"].values()), "nema crvenih upozorenja (minimumi su 0)")

    # ── 2. Stanje posle proizvodnje i prodaje ────────────────────────────────
    R.odeljak("Stanje – proizvedeno i prodato")
    radnik_unos(jelena, hum10, "2", "palete")       # 540
    radnik_unos(jelena, prem20, "35")               # 35
    radnik_unos(marko, idea10, "1", "palete")       # 225
    o = adm.get("/admin/stanje.php")
    st = stanje_redovi(o.text)
    R.provera(st["Humovit"]["redovi"]["10 l"]["stanje"] == "540", "Humovit 10 l = 540", st["Humovit"]["redovi"]["10 l"])
    R.provera(st["Humovit"]["redovi"]["10 l"]["pod"] == ["= 2 pal"], "…što je 2 palete", st["Humovit"]["redovi"]["10 l"]["pod"])
    R.provera(st["Idea"]["redovi"]["10 l"]["stanje"] == "225" and st["Idea"]["redovi"]["10 l"]["pod"] == ["= 1 pal"], "Idea 10 l = 225 komada = 1 paleta")
    R.provera(st["Humovit premium"]["redovi"]["20 l"]["stanje"] == "35" and st["Humovit premium"]["redovi"]["20 l"]["pod"] == [], "Humovit premium 20 l = 35 (manje od palete, bez razlaganja)")
    R.provera(st["Humovit"]["ukupno"] == "540 kom", "zbir po artiklu Humovit = 540 kom", st["Humovit"]["ukupno"])
    R.provera("+540 / −0" in st["Humovit"]["redovi"]["10 l"]["danas"], "danas: +540 / −0", st["Humovit"]["redovi"]["10 l"]["danas"])
    R.provera("5.400 l" in st["Humovit"]["redovi"]["10 l"]["danas"], "ukupno litara = 5.400 l", st["Humovit"]["redovi"]["10 l"]["danas"])
    plocice = re.findall(r'<span class="broj">([\d.]+)</span>\s*<span class="opis">([^<]*)</span>', o.text)
    R.provera(plocice[0] == ("800", "proizvedeno danas (kom)") and plocice[1][0] == "0" and plocice[2][0] == "0", "pločice: proizvedeno 800, prodato 0, ispod minimuma 0", plocice)

    radnik_unos(jelena, hum10, "40", strana="/radnik/prodaja.php")     # kućna prodaja
    o = prodaj(adm, "Cvećara Žika", hum10, "100")
    R.provera("Prodaja sačuvana: Cvećara Žika – Humovit · 10 l – 100 kom" in poruke(o), "prodaja je sačuvana", poruke(o))
    o = adm.get("/admin/stanje.php")
    st = stanje_redovi(o.text)
    R.provera(st["Humovit"]["redovi"]["10 l"]["stanje"] == "400", "stanje = 540 − 40 (kućna) − 100 (prodaja) = 400", st["Humovit"]["redovi"]["10 l"])
    R.provera("+540 / −140" in st["Humovit"]["redovi"]["10 l"]["danas"], "danas prodato uključuje i kućnu prodaju (−140)", st["Humovit"]["redovi"]["10 l"]["danas"])
    plocice = re.findall(r'<span class="broj">([\d.]+)</span>', o.text)
    R.provera(plocice[:2] == ["800", "140"], "pločice: 800 proizvedeno, 140 prodato", plocice)

    sql("UPDATE unosi SET kolicina=600 WHERE tip='proizvodnja' AND sku_id=%d" % hum10)
    o = adm.get("/admin/stanje.php")
    R.provera(stanje_redovi(o.text)["Humovit"]["redovi"]["10 l"]["pod"] == ["= 1 pal + 31 pak + 4 kom"], "razlaganje na palete, pakete i ostatak: 460 kom = 1 pal + 31 pak + 4 kom (6 u paketu)", stanje_redovi(o.text)["Humovit"]["redovi"]["10 l"]["pod"])
    sql("UPDATE unosi SET kolicina=540 WHERE tip='proizvodnja' AND sku_id=%d" % hum10)

    # ── 3. Minimum zalihe ────────────────────────────────────────────────────
    R.odeljak("Crveno upozorenje ispod minimuma")
    sql("UPDATE sku SET min_zaliha=500 WHERE id=%d" % hum10)
    o = adm.get("/admin/stanje.php")
    st = stanje_redovi(o.text)
    red = st["Humovit"]["redovi"]["10 l"]
    R.provera(red["nisko"] and any("ispod minimuma (500)" in x for x in red["pod"]), "400 < 500 → crveni red sa tekstom 'ispod minimuma (500)'", red)
    R.provera("ispod min.: 1" in st["Humovit"]["ukupno"], "u zaglavlju artikla piše koliko pakovanja je ispod minimuma")
    R.provera(not st["Humovit"]["redovi"]["5 l"]["nisko"], "ostala pakovanja nisu crvena")
    R.provera("stat-upozorenje" in o.text and re.findall(r'<span class="broj">(\d+)</span>\s*<span class="opis">ispod minimuma', o.text) == ["1"], "pločica 'ispod minimuma' = 1 i crvena je")
    o = adm.get("/admin/stanje.php?nisko=1")
    st = stanje_redovi(o.text)
    R.provera(list(st.keys()) == ["Humovit"] and list(st["Humovit"]["redovi"].keys()) == ["10 l"], "filter 'samo ispod minimuma' prikazuje samo taj red", {k: list(v["redovi"]) for k, v in st.items()})
    sql("UPDATE sku SET min_zaliha=400 WHERE id=%d" % hum10)
    R.provera(not stanje_redovi(adm.get("/admin/stanje.php").text)["Humovit"]["redovi"]["10 l"]["nisko"], "stanje jednako minimumu NIJE ispod minimuma (400 = 400)")
    sql("UPDATE sku SET min_zaliha=401 WHERE id=%d" % hum10)
    R.provera(stanje_redovi(adm.get("/admin/stanje.php").text)["Humovit"]["redovi"]["10 l"]["nisko"], "stanje 400 < minimum 401 → ispod minimuma")
    sql("UPDATE sku SET min_zaliha=0 WHERE id=%d" % hum10)
    o = adm.get("/admin/stanje.php?nisko=1")
    R.provera("Nijedan artikal nije ispod minimuma" in o.text, "bez problema piše da je sve u redu")
    sql("UPDATE sku SET min_zaliha=100 WHERE id=%d" % idea10)    # 225 ≥ 100

    # ── 4. Isključeni artikli sa stanjem ─────────────────────────────────────
    R.odeljak("Isključeni artikli koji imaju stanje")
    cm = sku_id("Cmana supstrat", 10)
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 12, %d, NOW(), NOW())" % (cm, ids["Jelena"]))
    o = adm.get("/admin/stanje.php")
    R.provera("Cmana supstrat" in o.text and ">12<" in o.text and "isključen" in o.text, "isključena Cmana sa stanjem 12 se prikazuje sa oznakom 'isključen'")
    R.provera(o.text.count('<div class="sku-red') == 30, "30 redova (29 + Cmana 10 l)", o.text.count('<div class="sku-red'))
    sql("DELETE FROM unosi WHERE sku_id=%d" % cm)

    # ── 5. Prodaja: ekran ────────────────────────────────────────────────────
    R.odeljak("Prodaja – ekran")
    o = adm.get("/admin/prodaja.php")
    R.provera(o.status == 200 and "Nova prodaja" in o.text and "Poslednje prodaje" in o.text, "ekran Prodaja se otvara")
    kat = json.loads(re.search(r'<script type="application/json" data-katalog>(.*?)</script>', o.text, re.S).group(1))
    h = next(a for k in kat["kategorije"] for a in k["artikli"] if a["naziv"] == "Humovit")
    R.provera({s["p"]: s["st"] for s in h["sku"]}["10 l"] == 400, "administrator u izboru vidi stanje (Humovit 10 l = 400)")
    R.provera('data-stanje="1"' in o.text, "uključen je prikaz stanja")
    R.provera("Cvećara Žika" in o.text and 'data-kupac="Cvećara Žika"' in o.text, "raniji kupac je ponuđen (lista i dugme za brzi izbor)")
    R.provera("kućna prodaja" in o.text and "Jelena" in o.text and "Cvećara Žika" in o.text, "u listi su i prodaja i kućna prodaja radnika")

    # ── 6. Prodaja: unos ─────────────────────────────────────────────────────
    R.odeljak("Prodaja – unos")
    red = sql("SELECT tip, kolicina, korisnik_id, napomena, IFNULL(palete,'NULL') FROM unosi WHERE tip='prodaja' ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(red == ["prodaja", "100", str(admin_id), "NULL", "NULL"], "u bazi: tip prodaja, 100 kom, upisao administrator", red)
    R.provera(sql_int("SELECT COUNT(*) FROM kupci") == 1 and sql("SELECT poslednja_prodaja IS NOT NULL FROM kupci") == "1", "kupac je upamćen")
    o = prodaj(adm, "cvecara zika", hum10, "1", "palete", napomena="otpremnica 15")    # 270 → 400-270=130
    R.provera(sql_int("SELECT COUNT(*) FROM kupci") == 1, "isti kupac pisan malim slovima i bez kvačica nije duplikat", sql("SELECT naziv FROM kupci"))
    red = sql("SELECT kolicina, palete, napomena FROM unosi WHERE tip='prodaja' ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(red == ["270", "1", "otpremnica 15"], "prodaja po paletama: 1 paleta = 270 kom, napomena sačuvana", red)
    R.provera("Prodaja sačuvana" in poruke(o) and "270 kom (1 paleta)" in poruke(o), "poruka pominje paletu", poruke(o))
    R.provera('value="cvecara zika"' in o.text, "posle prodaje kupac ostaje upisan za sledeću stavku")
    R.provera(stanje(hum10) == 130, "stanje = 130", stanje(hum10))

    o = prodaj(adm, "Novi Kupac", idea10, "226")
    R.provera("tražite 226 kom, a na stanju je 225 kom" in poruke(o), "prodaja veća od stanja: poruka sa brojevima", poruke(o))
    R.provera(stanje(idea10) == 225 and sql_int("SELECT COUNT(*) FROM kupci WHERE naziv='Novi Kupac'") == 0, "ništa nije upisano, ni novi kupac")
    o = prodaj(adm, "Novi Kupac", idea10, "2", "palete")
    R.provera("tražite 450 kom, a na stanju je 225 kom" in poruke(o), "i prodaja paleta se proverava naspram stanja")
    o = prodaj(adm, "Novi Kupac", idea10, "225")
    R.provera(stanje(idea10) == 0 and "Prodaja sačuvana" in poruke(o), "može da se proda tačno sve što ima (stanje 0)")
    o = prodaj(adm, "Novi Kupac", idea10, "1")
    R.provera("na stanju je 0 kom" in poruke(o), "sa stanjem 0 prodaja se odbija")
    o = prodaj(adm, "Novi Kupac", malc_crv, "1")
    R.provera("na stanju je 0 kom" in poruke(o), "artikal koji nije proizveden ne može da se proda")

    R.odeljak("Prodaja – neispravan unos")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    for opis, kupac, sku, kol, nacin in [
        ("bez kupca", "", hum5, "1", "komadi"),
        ("samo razmaci umesto kupca", "    ", hum5, "1", "komadi"),
        ("bez artikla", "Neko", "", "1", "komadi"),
        ("nepostojeći artikal", "Neko", "99999", "1", "komadi"),
        ("količina 0", "Neko", hum10, "0", "komadi"),
        ("negativna količina", "Neko", hum10, "-3", "komadi"),
        ("količina slovima", "Neko", hum10, "dva", "komadi"),
        ("isključen artikal", "Neko", cm, "1", "komadi"),
    ]:
        o = prodaj(adm, kupac, sku, kol, nacin)
        R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre and "poruka-greska" in o.text, "odbijeno: " + opis, poruke(o))

    R.odeljak("Prodaja – dvostruko slanje i istovremene prodaje")
    k = nov_kljuc()
    radnik_unos(jelena, hum5, "100")
    prodaj(adm, "Dupli", hum5, "5", kljuc=k)
    o = prodaj(adm, "Dupli", hum5, "5", kljuc=k)
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE tip='prodaja' AND sku_id=%d" % hum5) == 1 and "već sačuvana" in poruke(o), "ista prodaja poslata dvaput = jedan unos")
    sql("DELETE FROM unosi WHERE sku_id=%d" % hum5)
    radnik_unos(jelena, hum5, "10")
    adm.get("/admin/prodaja.php")
    tok = adm.token

    def paralelno(i):
        c = adm.kopiraj_sesiju()
        return c.post("/admin/prodaja.php", {"kupac": "Trka %d" % i, "sku_id": hum5, "kolicina": "8", "nacin": "komadi", "kljuc": nov_kljuc()})

    with concurrent.futures.ThreadPoolExecutor(6) as ex:
        list(ex.map(paralelno, range(6)))
    R.provera(stanje(hum5) == 2, "6 istovremenih prodaja po 8 kom iz stanja 10: prošla je samo jedna", stanje(hum5))

    R.odeljak("Prodaja – sigurnost i ispis")
    ime_xss = "<b onmouseover=alert(1)>Pera</b>"
    radnik_unos(jelena, hum5, "50")
    o = prodaj(adm, ime_xss, hum5, "1")
    o = adm.get("/admin/prodaja.php")
    R.provera(ime_xss not in o.text and "&lt;b onmouseover=alert(1)&gt;Pera&lt;/b&gt;" in o.text, "ime kupca sa HTML kodom se ispisuje bezbedno (XSS)")
    dugo = "K" * 200
    prodaj(adm, dugo, hum5, "1")
    R.provera(sql_int("SELECT COUNT(*) FROM kupci WHERE CHAR_LENGTH(naziv)=120") == 1, "predugo ime kupca se skraćuje na 120 znakova")
    o = adm.post("/admin/prodaja.php", {"kupac": "X", "sku_id": hum5, "kolicina": "1", "nacin": "komadi"}, csrf=False)
    R.provera(o.status == 403, "prodaja bez CSRF žetona → 403")
    o = jelena.get("/admin/prodaja.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik ne može na Prodaju administratora")
    o = jelena.get("/admin/stanje.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik ne može na Stanje")
    o = Client(site.base).get("/admin/prodaja.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "neprijavljen ne može na Prodaju")

    R.odeljak("Prodaja – lista ne prikazuje obrisano")
    sql("UPDATE unosi SET obrisan=1 WHERE tip='prodaja' AND napomena='otpremnica 15'")
    o = adm.get("/admin/prodaja.php")
    R.provera("otpremnica 15" not in o.text, "obrisana prodaja nije u listi")

    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
