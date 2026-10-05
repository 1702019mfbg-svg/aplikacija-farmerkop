#!/usr/bin/env python3
"""Paketi (transportno pakovanje), Popis sa paletama/paketima/komadima i zaštite u podešavanju artikala."""
import csv
import html as htmllib
import io
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


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def katalog(c, strana="/radnik/index.php"):
    o = c.get(strana)
    j = json.loads(re.search(r'<script type="application/json" data-katalog>(.*?)</script>', o.text, re.S).group(1))
    return {a["naziv"]: a for k in j["kategorije"] for a in k["artikli"]}


def pak_id(oznaka):
    kol, jed = oznaka.replace(",", ".").split()
    return sql("SELECT id FROM pakovanja WHERE kolicina=%s AND jedinica='%s'" % (kol, jed))


try:
    ids = pripremi_sajt(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    adm = prijavi_admina(site)
    J = ids["Jelena"]
    hum5, hum10, hum25, hum50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 25), sku_id("Humovit", 50)
    idea5, idea10 = sku_id("Idea", 5), sku_id("Idea", 10)

    def unesi(c, sku, kol, nacin, strana="/radnik/index.php"):
        c.get(strana)
        return c.post(strana, {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})

    def red_unosa(uid=None):
        q = "SELECT tip, kolicina, IFNULL(palete,'NULL'), IFNULL(paketi,'NULL') FROM unosi ORDER BY id DESC LIMIT 1"
        return sql(q).split("\t")

    # ── 1. Stanje baze posle nove instalacije ────────────────────────────────
    R.odeljak("Nova instalacija: kolone, vrednosti, verzija")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "2", "schema_verzija = 2")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND ((table_name='sku' AND column_name='po_paketu') OR (table_name='unosi' AND column_name='paketi'))" % DB_NAME) == 2, "postoje kolone sku.po_paketu i unosi.paketi")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%s' AND table_name='uvoz_mapiranje'" % DB_NAME) == 1, "postoji tabela uvoz_mapiranje")
    pp = {re.sub(r"(\d+)\.00", r"\1", r.split("\t")[0]): r.split("\t")[1] for r in sql(
        "SELECT CONCAT(a.naziv,' ',p.kolicina+0,' ',p.jedinica), IFNULL(s.po_paketu,'NULL') FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id WHERE a.naziv IN ('Humovit','Idea')").split("\n")}
    R.provera(pp == {"Humovit 5 l": "10", "Humovit 10 l": "6", "Humovit 25 l": "NULL", "Humovit 50 l": "NULL",
                     "Idea 5 l": "10", "Idea 10 l": "5", "Idea 20 l": "NULL", "Idea 25 l": "NULL"}, "komada u paketu: Humovit 5 l=10, 10 l=6; Idea 5 l=10, 10 l=5", pp)
    R.provera(sql("SELECT COUNT(*) FROM sku s JOIN artikli a ON a.id=s.artikal_id WHERE a.naziv NOT IN ('Humovit','Idea') AND s.po_paketu IS NOT NULL") == "0", "ostali artikli nemaju paket dok se ne podesi")
    R.provera(sql("SELECT COUNT(*) FROM sku WHERE po_paketu IS NOT NULL AND po_paleti IS NOT NULL AND po_paleti % po_paketu <> 0") == "0", "paleta je uvek ceo broj paketa (Humovit: 45 paketa, Idea: 45 paketa)")
    R.provera(sql("SELECT po_paleti/po_paketu FROM sku WHERE id=%d" % idea10) == "45.0000", "Idea 10 l: 225 komada = 45 paketa × 5")
    R.provera(sql("SELECT naziv FROM artikli WHERE naziv LIKE 'C%mana%'") == "Cmana supstrat", "artikal se zove Cmana (bez kvačice)")

    # ── 2. Radnik: unos po paketima ──────────────────────────────────────────
    R.odeljak("Radnik – unos po paketima")
    kat = katalog(jelena)
    h = kat["Humovit"]
    R.provera({s["p"]: s["pp"] for s in h["sku"]} == {"5 l": 10, "10 l": 6, "25 l": 0, "50 l": 0}, "izbor radnika nosi komada u paketu: Humovit 5 l=10, 10 l=6", {s["p"]: s["pp"] for s in h["sku"]})
    R.provera({s["p"]: s["pp"] for s in kat["Idea"]["sku"]} == {"5 l": 10, "10 l": 5, "20 l": 0, "25 l": 0}, "Idea 5 l=10, 10 l=5")
    o = jelena.get("/radnik/index.php")
    R.provera('data-nacin-vrednost="paketi"' in o.text and 'data-nacin-vrednost="palete"' in o.text and 'data-nacin-vrednost="komadi"' in o.text, "ekran ima tri načina: Palete, Paketi, Komadi")

    o = unesi(jelena, hum10, "12", "paketi")
    R.provera(red_unosa() == ["proizvodnja", "72", "NULL", "12"], "Humovit 10 l: 12 paketa = 72 komada (6 u paketu), upisano 12 paketa", red_unosa())
    R.provera("72 kom (12 paketa)" in poruke(o), "poruka: 72 kom (12 paketa)", poruke(o))
    R.provera("12 paketa" in o.text and ">72 kom<" in o.text, "u listi stoji 72 kom i 12 paketa")
    o = unesi(jelena, hum5, "3", "paketi")
    R.provera(red_unosa() == ["proizvodnja", "30", "NULL", "3"], "Humovit 5 l: 3 paketa = 30 komada (10 u paketu)")
    o = unesi(jelena, idea10, "9", "paketi")
    R.provera(red_unosa() == ["proizvodnja", "45", "NULL", "9"], "Idea 10 l: 9 paketa = 45 komada (5 u paketu)")
    o = unesi(jelena, idea5, "4", "paketi")
    R.provera(red_unosa() == ["proizvodnja", "40", "NULL", "4"], "Idea 5 l: 4 paketa = 40 komada")
    o = unesi(jelena, idea10, "1", "palete")
    R.provera(red_unosa() == ["proizvodnja", "225", "1", "NULL"], "1 paleta Idee 10 l = 225 komada (isto kao 45 paketa)")
    o = unesi(jelena, idea10, "45", "paketi")
    R.provera(red_unosa()[1] == "225", "45 paketa Idee 10 l = 225 komada = 1 paleta")
    o = unesi(jelena, hum10, "7", "komadi")
    R.provera(red_unosa() == ["proizvodnja", "7", "NULL", "NULL"], "komadi i dalje rade: 7 komada, bez paketa")

    R.odeljak("Radnik – neispravan unos po paketima")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    o = unesi(jelena, hum25, "2", "paketi")
    R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre and "nije podešeno koliko komada ima u paketu" in poruke(o), "artikal bez podešenog paketa: unos po paketima se odbija", poruke(o))
    o = unesi(jelena, hum10, "2000", "paketi")
    R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre and "prevelika" in poruke(o), "2000 paketa × 6 = 12000 komada > 10000 se odbija", poruke(o))
    for kol in ["0", "-2", "abc", "", "1.5"]:
        o = unesi(jelena, hum10, kol, "paketi")
        R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre and "poruka-greska" in o.text, "odbijeno: paketi=%r" % kol)

    for n, t in [(1, "1 paket"), (2, "2 paketa"), (4, "4 paketa"), (5, "5 paketa"), (11, "11 paketa"), (21, "21 paket"), (22, "22 paketa")]:
        o = unesi(jelena, hum10, str(n), "paketi")
        R.provera(t + ")" in poruke(o), "množina: " + t)
    sql("DELETE FROM unosi")

    # ── 3. Prodaja po paketima ───────────────────────────────────────────────
    R.odeljak("Prodaja po paketima")
    unesi(jelena, hum10, "1", "palete")          # 270
    unesi(jelena, hum10, "5", "paketi", "/radnik/prodaja.php")
    R.provera(red_unosa() == ["kucna_prodaja", "30", "NULL", "5"] and stanje(hum10) == 240, "kućna prodaja 5 paketa = 30 komada, stanje 240")
    o = unesi(jelena, hum10, "41", "paketi", "/radnik/prodaja.php")     # 246 > 240
    R.provera("Nema dovoljno na stanju" in poruke(o) and "240" not in poruke(o) and stanje(hum10) == 240, "kućna prodaja preko stanja: odbijeno, bez otkrivanja stanja", poruke(o))
    o = unesi(jelena, hum10, "40", "paketi", "/radnik/prodaja.php")     # 240 = tačno
    R.provera(stanje(hum10) == 0, "može da se proda tačno sve (40 paketa = 240)")
    unesi(jelena, hum10, "3", "palete")          # 810
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Cvećara", "sku_id": hum10, "kolicina": "10", "nacin": "paketi", "kljuc": nov_kljuc()})
    R.provera("60 kom (10 paketa)" in poruke(o) and red_unosa()[3] == "10", "administrator prodaje 10 paketa = 60 komada", poruke(o))
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Cvećara", "sku_id": hum10, "kolicina": "200", "nacin": "paketi", "kljuc": nov_kljuc()})
    R.provera("tražite 1.200 kom, a na stanju je 750 kom" in poruke(o), "administrator vidi tačne brojeve kad nema dovoljno", poruke(o))
    o = adm.get("/admin/prodaja.php")
    R.provera("10 paketa" in o.text, "u listi prodaje piše 10 paketa")

    # ── 4. Prikaz stanja ─────────────────────────────────────────────────────
    R.odeljak("Stanje: palete + paketi + komadi")
    sql("DELETE FROM unosi")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 460, %d, NOW(), NOW())" % (hum10, J))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 450, %d, NOW(), NOW())" % (hum5, J))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 233, %d, NOW(), NOW())" % (idea10, J))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 37, %d, NOW(), NOW())" % (hum25, J))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 14, %d, NOW(), NOW())" % (hum50, J))
    o = adm.get("/admin/stanje.php")

    def razlaganje_za(artikal_pak):
        blok = o.text.split("sku-red")
        for b in blok:
            if re.search(r'sku-ime">\s*%s\b' % re.escape(artikal_pak), b) and "Humovit" in o.text:
                m = re.search(r'sku-pal">(.*?)</div>', b, re.S)
                return tekst(m.group(1)) if m else ""
        return None
    # artikli su u zasebnim <details>; razlaganje čitamo po stanju
    rows = {}
    for det in o.text.split('<details class="kartica artikal-kartica"')[1:]:
        ime = re.sub(r"\s+", " ", tekst(re.search(r"<h3>(.*?)</h3>", det, re.S).group(1))).split(" PL")[0].strip()
        for r in det.split('<div class="sku-red')[1:]:
            nm = re.sub(r"\s+", " ", tekst(re.search(r'sku-ime">(.*?)</div>', r, re.S).group(1)))
            pal = re.search(r'sku-pal">(.*?)</div>', r, re.S)
            rows[(ime, nm)] = tekst(pal.group(1)) if pal else ""
    R.provera(rows[("Humovit", "10 l")] == "= 1 pal + 31 pak + 4 kom", "Humovit 10 l: 460 kom = 1 pal + 31 pak + 4 kom", rows[("Humovit", "10 l")])
    R.provera(rows[("Humovit", "5 l")] == "= 1 pal", "Humovit 5 l: 450 kom = 1 pal", rows[("Humovit", "5 l")])
    R.provera(rows[("Idea", "10 l")] == "= 1 pal + 1 pak + 3 kom", "Idea 10 l: 233 kom = 1 pal + 1 pak + 3 kom (225 + 5 + 3)", rows[("Idea", "10 l")])
    R.provera(rows[("Humovit", "25 l")] == "= 0" or rows[("Humovit", "25 l")] == "", "artikal bez paketa ni paleta većeg od stanja: bez razlaganja", rows[("Humovit", "25 l")])
    R.provera(rows[("Humovit", "50 l")] == "", "Humovit 50 l: 14 komada < paleta (40), bez razlaganja")

    # ── 5. Istorija, ispravka, CSV ───────────────────────────────────────────
    R.odeljak("Istorija, ispravka i izvoz za unose po paketima")
    sql("DELETE FROM unosi")
    unesi(jelena, hum10, "12", "paketi")
    uid = sql_int("SELECT MAX(id) FROM unosi")
    o = adm.get("/admin/istorija.php")
    R.provera("12 paketa" in o.text and "+72 kom" in o.text, "Istorija: +72 kom i 12 paketa")
    o = adm.get("/admin/unos.php?id=%d" % uid)
    R.provera('data-nacin="paketi"' in o.text and 'data-kolicina="12"' in o.text and 'data-sku="%d"' % hum10 in o.text, "ispravka: unos je popunjen kao 12 paketa")
    o = adm.post("/admin/unos.php", {"id": uid, "akcija": "sacuvaj", "sku_id": hum10, "kolicina": "20", "nacin": "paketi",
                                       "nastalo": sql("SELECT DATE_FORMAT(nastalo,'%Y-%m-%dT%H:%i') FROM unosi WHERE id=" + str(uid)), "napomena": ""})
    R.provera(red_unosa() == ["proizvodnja", "120", "NULL", "20"], "ispravka po paketima: 20 paketa = 120 komada", red_unosa())
    o = adm.get("/admin/unos.php?id=%d" % uid)
    R.provera("Paketi: 12 → 20" in tekst(o.text) and "Količina: 72 → 120 kom" in tekst(o.text), "dnevnik: Paketi 12 → 20 i Količina 72 → 120", tekst(o.text)[-300:])
    o = adm.post("/admin/unos.php", {"id": uid, "akcija": "sacuvaj", "sku_id": hum10, "kolicina": "1", "nacin": "palete",
                                       "nastalo": sql("SELECT DATE_FORMAT(nastalo,'%Y-%m-%dT%H:%i') FROM unosi WHERE id=" + str(uid)), "napomena": ""})
    R.provera(red_unosa() == ["proizvodnja", "270", "1", "NULL"], "prelazak sa paketa na palete: paketi se brišu, 1 paleta = 270", red_unosa())
    adm.post("/admin/unos.php", {"id": uid, "akcija": "sacuvaj", "sku_id": hum10, "kolicina": "12", "nacin": "paketi",
                                   "nastalo": sql("SELECT DATE_FORMAT(nastalo,'%Y-%m-%dT%H:%i') FROM unosi WHERE id=" + str(uid)), "napomena": ""})
    o = adm.get("/admin/izvoz.php?od=&do=")
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    red = dict(zip(t[0], t[1]))
    R.provera(red["Količina (kom)"] == "72" and red["Paketi"] == "12" and red["Palete"] == "", "CSV: 72 komada, 12 paketa, bez paleta", red)

    # ── 6. Podešavanje artikla: komada u paketu ─────────────────────────────
    R.odeljak("Podešavanje: komada u paketu")
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    o = adm.get("/admin/artikal.php?id=%d" % hum_id)
    vr = {m[0]: m[1] for m in re.findall(r'name="pp\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
    R.provera(vr[str(hum10)] == "6" and vr[str(hum5)] == "10" and vr[str(hum25)] == "", "stranica artikla prikazuje komada u paketu (10, 6, prazno)", vr)

    def snimi_vrednosti(artikal_id, izmene, bez_pp=False):
        o = adm.get("/admin/artikal.php?id=%d" % artikal_id)
        po = {m[0]: m[1] for m in re.findall(r'name="po\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
        pq = {m[0]: m[1] for m in re.findall(r'name="pp\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
        mn = {m[0]: m[1] for m in re.findall(r'name="min\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
        podaci = {"akcija": "vrednosti", "id": artikal_id}
        for k in po:
            podaci["po[%s]" % k] = po[k]
            podaci["min[%s]" % k] = mn[k]
            if not bez_pp:
                podaci["pp[%s]" % k] = pq[k]
        for k, v in izmene.items():
            for ime, vred in zip(("pp", "po", "min"), v):
                if vred is not None:
                    podaci["%s[%s]" % (ime, k)] = vred
        return adm.post("/admin/artikal.php", podaci)

    o = snimi_vrednosti(hum_id, {str(hum25): ("4", None, None)})
    R.provera(sql("SELECT po_paketu FROM sku WHERE id=%d" % hum25) == "4" and "Sačuvano" in poruke(o), "komada u paketu za Humovit 25 l = 4")
    R.provera("nije deljiva" not in poruke(o), "120 je deljivo sa 4 – bez upozorenja", poruke(o))
    o = snimi_vrednosti(hum_id, {str(hum10): ("7", None, None)})
    R.provera("nije deljiva" in poruke(o) and sql("SELECT po_paketu FROM sku WHERE id=%d" % hum10) == "7", "270 nije deljivo sa 7: upozorenje, ali se čuva kako je upisano", poruke(o))
    snimi_vrednosti(hum_id, {str(hum10): ("6", None, None)})
    for losa, opis in [("0", "nula"), ("abc", "slova"), ("70000", "preveliko"), ("-1", "negativno"), ("1.5", "decimalno")]:
        pre = sql("SELECT po_paketu FROM sku WHERE id=%d" % hum10)
        o = snimi_vrednosti(hum_id, {str(hum10): (losa, None, None)})
        R.provera("poruka-greska" in o.text and sql("SELECT po_paketu FROM sku WHERE id=%d" % hum10) == pre, "odbijeno: komada u paketu = " + opis, poruke(o))
    snimi_vrednosti(hum_id, {str(hum25): ("", None, None)})
    R.provera(sql("SELECT IFNULL(po_paketu,'NULL') FROM sku WHERE id=%d" % hum25) == "NULL", "prazno polje = paket nije podešen")
    o = snimi_vrednosti(hum_id, {}, bez_pp=True)
    R.provera(sql("SELECT po_paketu FROM sku WHERE id=%d" % hum10) == "6", "stara forma bez polja 'pp' ne briše podešene pakete")
    o = unesi(jelena, hum25, "1", "paketi")
    R.provera("nije podešeno koliko komada ima u paketu" in poruke(o), "bez podešenog paketa radnik ne može po paketima")

    R.odeljak("Novo pakovanje i nova varijanta nasleđuju paket")
    adm.get("/admin/artikal.php?id=%d" % hum_id)
    adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": hum_id, "pak[]": [pak_id("5 l"), pak_id("10 l"), pak_id("25 l")]})
    adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": hum_id, "pak[]": [pak_id("5 l"), pak_id("10 l"), pak_id("25 l"), pak_id("50 l")]})
    R.provera(sql("SELECT po_paketu FROM sku WHERE id=%d" % hum10) == "6" and sql("SELECT po_paleti FROM sku WHERE id=%d" % hum10) == "270", "uklanjanje i vraćanje pakovanja čuva paket i paletu")
    mf = sql_int("SELECT id FROM artikli WHERE naziv='Malč Farmerkop'")
    sql("UPDATE sku SET po_paketu=5 WHERE artikal_id=%d" % mf)
    adm.get("/admin/artikal.php?id=%d" % mf)
    adm.post("/admin/artikal.php", {"akcija": "var_nova", "id": mf, "naziv": "Plavi"})
    R.provera(sql("SELECT s.po_paketu FROM sku s JOIN varijante v ON v.id=s.varijanta_id WHERE v.naziv='Plavi'") == "5", "nova boja nasleđuje i komada u paketu")

    # ── 7. Popis: palete + paketi + komadi ───────────────────────────────────
    R.odeljak("Popis sa paletama, paketima i komadima")
    sql("DELETE FROM unosi")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 100, %d, NOW(), NOW())" % (hum10, J))
    o = adm.get("/admin/popis.php")
    R.provera('name="pal[%d]"' % hum10 in o.text and 'name="pak[%d]"' % hum10 in o.text and 'name="kom[%d]"' % hum10 in o.text, "za Humovit 10 l ima polja: paleta, paketa, komada")
    R.provera('name="pak[%d]"' % hum50 not in o.text and 'name="pal[%d]"' % hum50 in o.text, "za Humovit 50 l (bez paketa) nema polja za pakete")
    R.provera("popis.js" in o.text and 'data-po="270"' in o.text and 'data-pp="6"' in o.text, "ekran računa ukupno u hodu (data-po / data-pp)")

    def popis(napomena, **v):
        adm.get("/admin/popis.php")
        podaci = {"napomena": napomena}
        podaci.update(v)
        return adm.post("/admin/popis.php", podaci)

    o = popis("Početno stanje", **{"pal[%d]" % hum10: "1", "pak[%d]" % hum10: "3", "kom[%d]" % hum10: "2"})
    R.provera(stanje(hum10) == 290 and "100 → 290" in poruke(o), "1 paleta + 3 paketa + 2 komada = 270 + 18 + 2 = 290; razlika +190", (stanje(hum10), poruke(o)))
    R.provera(sql("SELECT kolicina FROM unosi WHERE tip='korekcija' AND sku_id=%d" % hum10) == "190", "upisana korekcija +190")
    o = popis("Samo paketi", **{"pak[%d]" % hum10: "10"})
    R.provera(stanje(hum10) == 60, "samo paketi: 10 × 6 = 60", stanje(hum10))
    o = popis("Samo paleta", **{"pal[%d]" % hum10: "2"})
    R.provera(stanje(hum10) == 540, "samo palete: 2 × 270 = 540")
    o = popis("Nula", **{"pal[%d]" % hum10: "0", "pak[%d]" % hum10: "0", "kom[%d]" % hum10: "0"})
    R.provera(stanje(hum10) == 0, "sve nule = stanje 0")
    o = popis("Ukupno pobeđuje", **{"prebrojano[%d]" % hum10: "77", "pal[%d]" % hum10: "9"})
    R.provera(stanje(hum10) == 77, "ako je upisano ukupno komada, ono važi (stari način)")
    o = popis("Više artikala", **{"pal[%d]" % hum5: "1", "pak[%d]" % idea5: "2", "kom[%d]" % idea5: "3", "kom[%d]" % hum25: "9"})
    R.provera(stanje(hum5) == 450 and stanje(idea5) == 23 and stanje(hum25) == 9, "više artikala odjednom (450, 23, 9)", (stanje(hum5), stanje(idea5), stanje(hum25)))
    for opis, v, deo in [("slova", {"pal[%d]" % hum10: "x"}, "celi brojevi"), ("negativno", {"kom[%d]" % hum10: "-3"}, "celi brojevi"),
                         ("decimalno", {"pak[%d]" % hum10: "1.5"}, "celi brojevi"),
                         ("paketi bez podešenog paketa", {"pak[%d]" % hum25: "2"}, "nije podešeno koliko komada ima u paketu")]:
        pre = sql_int("SELECT COUNT(*) FROM unosi")
        o = popis("Provera", **v)
        R.provera(deo in poruke(o) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "odbijeno: " + opis, poruke(o))
    sql("UPDATE sku SET po_paleti=NULL WHERE id=%d" % hum50)
    o = popis("Provera", **{"pal[%d]" % hum50: "1"})
    R.provera("nije podešeno koliko komada ide na paletu" in poruke(o) or 'name="pal[%d]"' % hum50 not in adm.get("/admin/popis.php").text, "paleta bez podešene palete se ne prima")
    sql("UPDATE sku SET po_paleti=40 WHERE id=%d" % hum50)
    o = popis("", **{"kom[%d]" % hum10: "5"})
    R.provera("Upišite napomenu" in poruke(o) and stanje(hum10) == 77, "napomena je obavezna i za nove načine unosa")
    o = popis("Bez vrednosti")
    R.provera("nijedno prebrojano" in poruke(o), "bez ijedne vrednosti: poruka")

    # ── 8. Zaštite ───────────────────────────────────────────────────────────
    R.odeljak("Zaštite: artikal ne sme nestati nehotice")
    o = adm.get("/admin/artikli.php")
    R.provera(len(re.findall(r'data-potvrda="Isključiti artikal', o.text)) >= 7, "svako dugme 'Isključi' uz artikal traži potvrdu", len(re.findall(r'data-potvrda="Isključiti artikal', o.text)))
    R.provera('data-potvrda="Isključiti celu kategoriju' in o.text, "isključivanje kategorije traži potvrdu")
    R.provera("Cmana supstrat" in o.text and "Čmana" not in o.text, "u spisku je 'Cmana supstrat'")

    pre = sql("SELECT GROUP_CONCAT(id, ':', aktivan ORDER BY id) FROM sku WHERE artikal_id=%d" % hum_id)
    adm.get("/admin/artikal.php?id=%d" % hum_id)
    o = adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": hum_id})
    R.provera("bar jedno pakovanje" in poruke(o) and sql("SELECT GROUP_CONCAT(id, ':', aktivan ORDER BY id) FROM sku WHERE artikal_id=%d" % hum_id) == pre, "čuvanje bez ijednog pakovanja je odbijeno, ništa se ne menja", poruke(o))
    R.provera("Humovit" in katalog(jelena), "Humovit je i dalje u izboru radnika")

    # artikal bez pakovanja (npr. posle ručne greške) mora biti jasno označen
    sql("UPDATE sku SET aktivan=0 WHERE artikal_id=%d" % hum_id)
    o = adm.get("/admin/artikli.php")
    R.provera("nema pakovanja – ne nudi se za unos" in o.text, "u spisku artikala crvena oznaka: nema pakovanja")
    o = adm.get("/admin/artikal.php?id=%d" % hum_id)
    R.provera("Ovaj artikal se ne nudi za unos" in o.text and "poruka-greska" in o.text, "na stranici artikla crveno upozorenje")
    R.provera("Humovit" not in katalog(jelena), "radnik ga u tom stanju ne vidi (zato je upozorenje bitno)")
    adm.get("/admin/artikal.php?id=%d" % hum_id)
    adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": hum_id, "pak[]": [pak_id("5 l"), pak_id("10 l"), pak_id("25 l"), pak_id("50 l")]})
    k = katalog(jelena)["Humovit"]
    R.provera({s["p"]: (s["po"], s["pp"]) for s in k["sku"]} == {"5 l": (450, 10), "10 l": (270, 6), "25 l": (120, 0), "50 l": (40, 0)}, "ponovnim štikliranjem Humovit se vraća sa svim paletama i paketima", {s["p"]: (s["po"], s["pp"]) for s in k["sku"]})

    R.odeljak("Povratak na artikal bez varijanti")
    adm.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "Perlit", "kategorija_id": sql_int("SELECT id FROM kategorije WHERE naziv='Malč'"), "nv": ""})
    perlit = sql_int("SELECT id FROM artikli WHERE naziv='Perlit'")
    adm.get("/admin/artikal.php?id=%d" % perlit)
    adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": perlit, "pak[]": [pak_id("5 l"), pak_id("10 l")]})
    adm.post("/admin/artikal.php", {"akcija": "var_nova", "id": perlit, "naziv": "Fini", "nv": "Frakcija"})
    R.provera(katalog(jelena)["Perlit"]["varijante"] == [{"id": sql_int("SELECT id FROM varijante WHERE naziv='Fini'"), "naziv": "Fini"}], "Perlit sada ima varijantu 'Fini'")
    o = adm.get("/admin/artikal.php?id=%d" % perlit)
    R.provera("Vrati artikal na stanje bez varijanti" in o.text, "ponuđeno je dugme za povratak na stanje bez varijanti")
    o = adm.post("/admin/artikal.php", {"akcija": "bez_varijanti", "id": perlit})
    R.provera("vraćen na stanje bez varijanti" in poruke(o), "povratak je urađen", poruke(o))
    k = katalog(jelena)["Perlit"]
    R.provera(k["varijante"] == [] and sorted(s["p"] for s in k["sku"]) == ["10 l", "5 l"], "radnici opet vide Perlit bez varijanti, sa 5 l i 10 l", k["varijante"])
    o = adm.get("/admin/artikal.php?id=%d" % perlit)
    R.provera("(isključeno)" in o.text and "Vrati artikal na stanje bez varijanti" not in o.text, "varijanta 'Fini' je isključena (ne obrisana), a dugme za povratak se više ne nudi")
    # varijanta se može ponovo uključiti i artikal radi sa varijantama
    vid = sql_int("SELECT id FROM varijante WHERE naziv='Fini'")
    adm.post("/admin/artikal.php", {"akcija": "var_status", "id": perlit, "vid": vid})
    R.provera(sql("SELECT aktivan FROM varijante WHERE id=%d" % vid) == "1", "isključena varijanta se može ponovo uključiti")
    # isključivanjem svih varijanti i čuvanjem pakovanja artikal se vraća i bez dugmeta
    adm.post("/admin/artikal.php", {"akcija": "var_status", "id": perlit, "vid": vid})
    adm.get("/admin/artikal.php?id=%d" % perlit)
    adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": perlit, "pak[]": [pak_id("5 l"), pak_id("10 l")]})
    R.provera(katalog(jelena)["Perlit"]["varijante"] == [], "i čuvanjem pakovanja posle isključenja svih varijanti artikal se vraća u upotrebu")

    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
