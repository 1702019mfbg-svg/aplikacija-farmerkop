#!/usr/bin/env python3
"""Faza 4: Istorija – filteri, ispravka, brisanje/vraćanje, dnevnik izmena, CSV izvoz."""
import csv
import html as htmllib
import io
import re
import sys
import urllib.parse
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


def redovi_liste(o):
    """Lista unosa sa stranice Istorija: [(id, tekst reda)]."""
    izlaz = []
    for li in re.findall(r"<li( class=\"unos-obrisan\")?>\s*<div class=\"unos-red\">(.*?)</li>", o.text, re.S):
        obrisan, sadrzaj = li
        m = re.search(r'name="id" value="(\d+)"', sadrzaj)
        if not m:
            m = re.search(r"unos\.php\?id=(\d+)", sadrzaj)
        izlaz.append((int(m.group(1)) if m else 0, re.sub(r"\s+", " ", tekst(sadrzaj)), bool(obrisan)))
    return izlaz


def ploce(o):
    return re.findall(r'<span class="broj">([\d.]+)</span>\s*<span class="opis">([^<]*)</span>', o.text)


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    admin_id = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")
    J, M, D = ids["Jelena"], ids["Marko"], ids["Dragan"]
    hum10, hum25, hum5 = sku_id("Humovit", 10), sku_id("Humovit", 25), sku_id("Humovit", 5)
    idea10 = sku_id("Idea", 10)
    prem20 = sku_id("Humovit premium", 20)
    malc = sku_id("Malč Farmerkop", 50, "l", "Crveni")

    kupci = {}

    def kupac(naziv):
        if naziv not in kupci:
            sql("INSERT INTO kupci (naziv, napravljen) VALUES ('%s', NOW())" % naziv.replace("'", "''"))
            kupci[naziv] = sql_int("SELECT id FROM kupci WHERE naziv='%s'" % naziv.replace("'", "''"))
        return kupci[naziv]

    def unos(tip, sku, kol, radnik, dana_unazad=0, sat="10:00:00", palete=None, kupac_naziv=None, napomena=None):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, palete, korisnik_id, kupac_id, napomena, nastalo, uneto) VALUES ('%s', %d, %d, %s, %d, %s, %s, "
            "CONCAT(DATE_SUB(CURDATE(), INTERVAL %d DAY), ' %s'), CONCAT(DATE_SUB(CURDATE(), INTERVAL %d DAY), ' %s'))" % (
                tip, sku, kol, palete or "NULL", radnik, kupac(kupac_naziv) if kupac_naziv else "NULL",
                ("'%s'" % napomena.replace("'", "''")) if napomena else "NULL", dana_unazad, sat, dana_unazad, sat))
        return sql_int("SELECT MAX(id) FROM unosi")

    # Stanje: zalihe dovoljne za prodaje
    a1 = unos("proizvodnja", hum10, 540, J, 0, "07:30:00", 2)
    a2 = unos("proizvodnja", hum10, 270, J, 0, "09:15:00", 1)
    a3 = unos("proizvodnja", idea10, 225, M, 0, "08:00:00", 1)
    a4 = unos("prodaja", hum10, 100, admin_id, 0, "11:00:00", kupac_naziv="Cvećara Žika", napomena="otpremnica 15")
    a5 = unos("kucna_prodaja", hum10, 15, D, 0, "11:30:00", napomena="komšija")
    a6 = unos("korekcija", hum5, 5, admin_id, 0, "12:00:00", napomena="popis: pronađeno 5")
    b1 = unos("proizvodnja", prem20, 120, J, 1, "10:00:00", 1)
    b2 = unos("prodaja", prem20, 20, admin_id, 1, "13:00:00", kupac_naziv="Agrocentar", napomena="100% plaćeno_odmah")
    c1 = unos("proizvodnja", malc, 40, D, 3, "09:00:00", 1)
    d1 = unos("proizvodnja", hum25, 120, J, 10, "09:00:00", 1)
    e1 = unos("proizvodnja", hum25, 240, J, 40, "09:00:00", 2)

    # ── 1. Osnovni prikaz i zbirovi ──────────────────────────────────────────
    R.odeljak("Istorija – osnovno")
    o = adm.get("/admin/istorija.php")
    R.provera(o.status == 200 and "Dnevnik izmena" in o.text and "Izvoz u Excel (CSV)" in o.text, "ekran Istorija se otvara, ima izvoz i dnevnik")
    r = redovi_liste(o)
    id_lista = [x[0] for x in r]
    R.provera(set(id_lista) == {a1, a2, a3, a4, a5, a6, b1, b2, c1}, "podrazumevano: poslednjih 7 dana (bez unosa starih 10 i 40 dana)", id_lista)
    R.provera(id_lista == [a6, a5, a4, a2, a3, a1, b2, b1, c1] or id_lista[:2] == [a6, a5], "najnoviji unosi su prvi", id_lista)
    pl = ploce(o)
    R.provera([p[0] for p in pl] == ["1.195", "135", "9"], "zbirovi: proizvedeno 1.195 (540+270+225+120+40), prodato 135 (100+15+20), 9 unosa (korekcija se ne računa u prva dva)", pl)

    # ── 2. Filter po vrsti ───────────────────────────────────────────────────
    R.odeljak("Filter po vrsti")
    for tip, ocekivano in [("proizvodnja", {a1, a2, a3, b1, c1}), ("prodaja", {a4, b2}), ("kucna_prodaja", {a5}), ("korekcija", {a6})]:
        o = adm.get("/admin/istorija.php?tip=" + tip)
        R.provera({x[0] for x in redovi_liste(o)} == ocekivano, "tip=%s" % tip, [x[0] for x in redovi_liste(o)])
    o = adm.get("/admin/istorija.php?tip=prodaja")
    R.provera(re.search(r'aria-current="true"[^>]*>\s*Prodaja\s*</a>', o.text) is not None, "izabrani čip za vrstu je označen")

    # ── 3. Filter po periodu ─────────────────────────────────────────────────
    R.odeljak("Filter po periodu")
    danas = sql("SELECT CURDATE()")
    juce = sql("SELECT DATE_SUB(CURDATE(), INTERVAL 1 DAY)")
    pre3 = sql("SELECT DATE_SUB(CURDATE(), INTERVAL 3 DAY)")
    pre10 = sql("SELECT DATE_SUB(CURDATE(), INTERVAL 10 DAY)")
    o = adm.get("/admin/istorija.php?od=%s&do=%s" % (danas, danas))
    R.provera({x[0] for x in redovi_liste(o)} == {a1, a2, a3, a4, a5, a6}, "samo danas")
    o = adm.get("/admin/istorija.php?od=%s&do=%s" % (juce, juce))
    R.provera({x[0] for x in redovi_liste(o)} == {b1, b2}, "samo juče (granice dana su tačne)")
    o = adm.get("/admin/istorija.php?od=&do=")
    R.provera({x[0] for x in redovi_liste(o)} == {a1, a2, a3, a4, a5, a6, b1, b2, c1, d1, e1}, "'Sve' prikazuje i najstarije unose")
    o = adm.get("/admin/istorija.php?od=%s&do=" % pre10)
    R.provera(d1 in {x[0] for x in redovi_liste(o)} and e1 not in {x[0] for x in redovi_liste(o)}, "samo 'od' datuma")
    o = adm.get("/admin/istorija.php?od=&do=%s" % pre3)
    R.provera({x[0] for x in redovi_liste(o)} == {c1, d1, e1}, "samo 'do' datuma (uključuje taj dan)", [x[0] for x in redovi_liste(o)])
    o = adm.get("/admin/istorija.php?od=nije-datum&do=2026-13-45")
    R.provera(o.status == 200 and not site.php_problemi(), "neispravni datumi se ignorišu bez greške")

    # ── 4. Radnik, artikal, pretraga ─────────────────────────────────────────
    R.odeljak("Filter po radniku, artiklu i pretraga")
    o = adm.get("/admin/istorija.php?radnik=%d&od=&do=" % J)
    R.provera({x[0] for x in redovi_liste(o)} == {a1, a2, b1, d1, e1}, "radnik = Jelena")
    o = adm.get("/admin/istorija.php?radnik=%d&od=&do=" % D)
    R.provera({x[0] for x in redovi_liste(o)} == {a5, c1}, "radnik = Dragan (kućna prodaja i malč)")
    o = adm.get("/admin/istorija.php?radnik=%d&od=&do=" % admin_id)
    R.provera({x[0] for x in redovi_liste(o)} == {a4, a6, b2}, "administrator je takođe u filteru (prodaje i popis)")
    art_hum = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    o = adm.get("/admin/istorija.php?artikal=%d&od=&do=" % art_hum)
    R.provera({x[0] for x in redovi_liste(o)} == {a1, a2, a4, a5, a6, d1, e1}, "artikal = Humovit (sva pakovanja)")
    o = adm.get("/admin/istorija.php?artikal=%d&radnik=%d&tip=proizvodnja&od=&do=" % (art_hum, J))
    R.provera({x[0] for x in redovi_liste(o)} == {a1, a2, d1, e1}, "kombinacija: Humovit + Jelena + proizvodnja")
    o = adm.get("/admin/istorija.php?q=Žika&od=&do=")
    R.provera({x[0] for x in redovi_liste(o)} == {a4}, "pretraga po kupcu")
    o = adm.get("/admin/istorija.php?q=komšija&od=&do=")
    R.provera({x[0] for x in redovi_liste(o)} == {a5}, "pretraga po napomeni")
    o = adm.get("/admin/istorija.php?q=100%25&od=&do=")
    R.provera({x[0] for x in redovi_liste(o)} == {b2}, "'%' u pretrazi se tretira kao običan znak", [x[0] for x in redovi_liste(o)])
    o = adm.get("/admin/istorija.php?q=plaćeno_odmah&od=&do=")
    R.provera({x[0] for x in redovi_liste(o)} == {b2}, "'_' u pretrazi se tretira kao običan znak")
    o = adm.get("/admin/istorija.php?q=%25&od=&do=")
    R.provera(len(redovi_liste(o)) == 1, "pretraga samo '%' ne vraća sve")

    R.odeljak("Neispravni filteri ne ruše stranicu")
    for upit in ["tip=' OR 1=1 --", "radnik=1 OR 1=1", "artikal=abc", "q='; DROP TABLE unosi; --", "strana=-5", "strana=99999", "obrisani=da", "tip[]=x", "od[]=x"]:
        o = adm.get("/admin/istorija.php?" + urllib.parse.quote(upit, safe="=&[]"))
        R.provera(o.status == 200 and sql_int("SELECT COUNT(*) FROM unosi") == 11, "filter '%s' je bezbedan" % upit)
    R.provera(not site.php_problemi(), "nema PHP upozorenja posle čudnih filtera", site.php_problemi()[:3])
    o = adm.get("/admin/istorija.php?q=%3Cscript%3Ealert(1)%3C/script%3E")
    R.provera("<script>alert(1)</script>" not in o.text and "&lt;script&gt;alert(1)&lt;/script&gt;" in o.text, "pretraga se bezbedno ispisuje u polju (XSS)")

    # ── 5. Paginacija ────────────────────────────────────────────────────────
    R.odeljak("Paginacija")
    for i in range(100):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 1, %d, DATE_SUB(NOW(), INTERVAL %d MINUTE), NOW())" % (hum5, J, i + 1))
    o1 = adm.get("/admin/istorija.php?tip=proizvodnja")
    o2 = adm.get("/admin/istorija.php?tip=proizvodnja&strana=2")
    o3 = adm.get("/admin/istorija.php?tip=proizvodnja&strana=3")
    ukupno_proizv = sql_int("SELECT COUNT(*) FROM unosi WHERE tip='proizvodnja' AND nastalo >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")
    R.provera(len(redovi_liste(o1)) == 40 and len(redovi_liste(o2)) == 40, "po 40 unosa po strani")
    R.provera(len(redovi_liste(o3)) == ukupno_proizv - 80, "poslednja strana ima ostatak (%d)" % (ukupno_proizv - 80), len(redovi_liste(o3)))
    R.provera("Strana 1 od 3" in tekst(o1.text) and "Novije" not in o1.text and "Starije" in o1.text, "prva strana: samo 'Starije'")
    R.provera("Strana 3 od 3" in tekst(o3.text) and "Starije" not in o3.text and "Novije" in o3.text, "poslednja strana: samo 'Novije'")
    R.provera("tip=proizvodnja" in o1.text.split("Starije")[0].rsplit("href=", 1)[1], "linkovi stranica čuvaju filtere")
    ids1 = {x[0] for x in redovi_liste(o1)}
    ids2 = {x[0] for x in redovi_liste(o2)}
    R.provera(not (ids1 & ids2), "strane se ne preklapaju")
    o = adm.get("/admin/istorija.php?tip=proizvodnja&strana=999")
    R.provera("Strana 3 od 3" in tekst(o.text), "prevelik broj strane se svodi na poslednju")
    sql("DELETE FROM unosi WHERE kolicina=1 AND sku_id=%d AND korisnik_id=%d AND tip='proizvodnja'" % (hum5, J))

    # ── 6. Ispravka ──────────────────────────────────────────────────────────
    R.odeljak("Ispravka unosa")
    o = adm.get("/admin/unos.php?id=%d" % a2)
    R.provera(o.status == 200 and "Izmena" in o.text and "Unos nije menjan" in o.text, "stranica za ispravku se otvara")
    R.provera('data-sku="%d"' % hum10 in o.text and 'data-kolicina="1"' in o.text and 'data-nacin="palete"' in o.text, "unos je unapred popunjen (1 paleta)")
    R.provera(adm.get("/admin/unos.php?id=999999").status == 404, "nepostojeći unos → 404")

    def sacuvaj(c, uid, **kw):
        c.get("/admin/unos.php?id=%d" % uid)
        stari = db_unos(uid)
        if stari["palete"] != "NULL":
            kol, nacin = stari["palete"], "palete"      # kao u pravoj formi: unos po paletama ostaje po paletama
        else:
            kol, nacin = stari["kolicina"], "komadi"
        podaci = {"id": uid, "akcija": "sacuvaj", "sku_id": stari["sku_id"], "kolicina": kol, "nacin": nacin,
                  "nastalo": stari["nastalo"].replace(" ", "T")[:16], "napomena": stari["napomena"] if stari["napomena"] != "NULL" else ""}
        if stari["tip"] == "prodaja":
            podaci["kupac"] = sql("SELECT naziv FROM kupci WHERE id=%s" % stari["kupac_id"])
        if "kolicina" in kw and "nacin" not in kw:
            podaci["nacin"] = "komadi"
        podaci.update({k: v for k, v in kw.items()})
        return c.post("/admin/unos.php", podaci)

    def db_unos(uid):
        kolone = ["id", "tip", "sku_id", "kolicina", "palete", "kupac_id", "napomena", "nastalo", "obrisan", "korisnik_id"]
        vr = sql("SELECT %s FROM unosi WHERE id=%d" % (", ".join("IFNULL(%s,'NULL')" % k for k in kolone), uid)).split("\t")
        return dict(zip(kolone, vr))

    o = sacuvaj(adm, a2, kolicina="3", nacin="palete")      # 3 palete × 270 = 810
    u = db_unos(a2)
    R.provera(u["kolicina"] == "810" and u["palete"] == "3", "ispravka u paletama: 3 × 270 = 810", u)
    R.provera("Izmena je sačuvana" in poruke(o), "poruka o izmeni", poruke(o))
    R.provera(u["korisnik_id"] == str(J), "unos je i dalje Jelenin (menja se sadržaj, ne autor)")
    d = sql("SELECT akcija, objekat, objekat_id, korisnik_id, detalji FROM dnevnik ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(d[:4] == ["izmena", "unos", str(a2), str(admin_id)], "izmena je u dnevniku: ko (administrator) i šta", d[:4])
    R.provera('"kolicina":270' in d[4] and '"kolicina":810' in d[4], "dnevnik čuva staru (270) i novu (810) vrednost", d[4][:200])
    o = adm.get("/admin/unos.php?id=%d" % a2)
    R.provera("Količina: 270 → 810 kom" in tekst(o.text) and "Palete: 1 → 3" in tekst(o.text), "stranica pokazuje istoriju izmena", tekst(o.text)[-400:])
    R.provera("Vlasnik" in o.text, "…i ko je menjao")
    o = adm.get("/admin/istorija.php")
    R.provera("izmenjeno ×1" in o.text, "u listi stoji oznaka 'izmenjeno ×1'")
    o = adm.get("/admin/dnevnik.php")
    R.provera("Izmenjeno" in o.text and "unos #%d" % a2 in o.text and "Količina: 270 → 810 kom" in tekst(o.text), "dnevnik izmena prikazuje promenu")

    br_dnevnik = sql_int("SELECT COUNT(*) FROM dnevnik")
    o = sacuvaj(adm, a2)
    R.provera("Ništa nije promenjeno" in poruke(o) and sql_int("SELECT COUNT(*) FROM dnevnik") == br_dnevnik, "snimanje bez promene ne pravi zapis u dnevniku")

    o = sacuvaj(adm, a2, napomena="proveriti sa Draganom", nastalo="2026-10-03T09:20")
    u = db_unos(a2)
    R.provera(u["napomena"] == "proveriti sa Draganom" and u["nastalo"].endswith("09:20:00"), "izmena napomene i vremena", u)

    R.odeljak("Ispravka – pravila o stanju")
    # stanje Humovit 10 l: 540 + 810 − 100 − 15 = 1235
    R.provera(stanje(hum10) == 540 + 810 - 115, "stanje pre testa", stanje(hum10))
    o = sacuvaj(adm, a4, kolicina="1336")                    # stanje 1235 + stara prodaja 100 = 1335 je najviše što može
    R.provera("ispod nule" in poruke(o) and db_unos(a4)["kolicina"] == "100", "povećanje prodaje preko stanja je odbijeno", poruke(o))
    o = sacuvaj(adm, a4, kolicina="1335")
    R.provera(db_unos(a4)["kolicina"] == "1335" and stanje(hum10) == 0, "prodaja može do tačno nule", (db_unos(a4)["kolicina"], stanje(hum10)))
    o = sacuvaj(adm, a4, kolicina="50")
    R.provera(stanje(hum10) == 540 + 810 - 50 - 15, "smanjenje prodaje vraća stanje", stanje(hum10))
    o = sacuvaj(adm, a1, kolicina="1")                       # proizvodnja 540 → 1: stanje 1285−539 = 746 ok
    R.provera(db_unos(a1)["kolicina"] == "1", "smanjenje proizvodnje je dozvoljeno dok stanje ostaje ≥ 0")
    o = sacuvaj(adm, a2, kolicina="1")                       # 810 → 1: stanje 746−809 < 0
    R.provera("ispod nule" in poruke(o) and db_unos(a2)["kolicina"] == "810", "smanjenje proizvodnje ispod prodatog je odbijeno", poruke(o))
    sql("UPDATE unosi SET kolicina=540 WHERE id=%d" % a1)

    o = sacuvaj(adm, a4, kupac="Vrt i bašta Kraljevo", kolicina="50")
    R.provera(sql("SELECT naziv FROM kupci WHERE id=%s" % db_unos(a4)["kupac_id"]) == "Vrt i bašta Kraljevo", "izmena kupca")
    R.provera("Kupac: Cvećara Žika → Vrt i bašta Kraljevo" in tekst(adm.get("/admin/unos.php?id=%d" % a4).text), "dnevnik pamti promenu kupca")
    o = sacuvaj(adm, a4, kupac="")
    R.provera("Upišite kupca" in poruke(o), "prodaja bez kupca se ne snima")

    R.odeljak("Ispravka – promena artikla")
    sql("UPDATE unosi SET kolicina=540 WHERE id=%d" % a1)
    pre_hum10, pre_hum25 = stanje(hum10), stanje(hum25)
    o = sacuvaj(adm, a2, sku_id=hum25, kolicina="810")
    R.provera(db_unos(a2)["sku_id"] == str(hum25), "unos je prebačen na drugo pakovanje")
    R.provera(stanje(hum10) == pre_hum10 - 810 and stanje(hum25) == pre_hum25 + 810, "stanja oba artikla su ispravljena", (stanje(hum10), stanje(hum25)))
    R.provera("Artikal: Humovit · 10 l → Humovit · 25 l" in tekst(adm.get("/admin/unos.php?id=%d" % a2).text), "dnevnik pamti promenu artikla")
    # vrati nazad pa prebaci unos koji je delimično prodat
    sacuvaj(adm, a2, sku_id=hum10, kolicina="810")
    pre = stanje(hum10)
    sql("UPDATE unosi SET sku_id=%d WHERE id=%d" % (hum10, a2))
    sql("UPDATE unosi SET kolicina=900 WHERE id=%d" % a4)       # prodaja 900 → stanje hum10 = 540+810−900−15 = 435
    o = sacuvaj(adm, a2, sku_id=hum25, kolicina="810")         # izlazi 810 iz hum10 → −375
    R.provera("starog artikla bi palo ispod nule" in poruke(o) and db_unos(a2)["sku_id"] == str(hum10), "prebacivanje proizvodnje koja je već prodata je odbijeno", poruke(o))
    sql("UPDATE unosi SET kolicina=50 WHERE id=%d" % a4)
    o = sacuvaj(adm, a4, sku_id=idea10, kolicina="300")        # idea10 ima 225
    R.provera("nema dovoljno na stanju" in poruke(o).lower() and db_unos(a4)["sku_id"] == str(hum10), "prodaja prebačena na artikal sa manjim stanjem je odbijena", poruke(o))
    o = sacuvaj(adm, a2, sku_id=sku_id("Čmana supstrat", 10))
    R.provera("Izaberite artikal" in poruke(o), "ne može se prebaciti na isključen artikal")
    o = sacuvaj(adm, a2, kolicina="0")
    R.provera("količinu" in poruke(o).lower(), "količina 0 je odbijena")

    R.odeljak("Ispravka – datum i korekcija")
    for losa, opis in [("nije-datum", "nevažeći format"), ("1999-01-01T10:00", "pre 2020"), ("2099-01-01T10:00", "daleka budućnost")]:
        o = sacuvaj(adm, a3, nastalo=losa)
        R.provera("poruka-greska" in o.text, "datum odbijen: " + opis, poruke(o))
    o = adm.get("/admin/unos.php?id=%d" % a6)
    R.provera("Količina popisa se ne menja ovde" in o.text and 'data-izbor' not in o.text, "korekcija: nema izmene količine ni artikla")
    o = adm.post("/admin/unos.php", {"id": a6, "akcija": "sacuvaj", "sku_id": idea10, "kolicina": "999", "nastalo": db_unos(a6)["nastalo"].replace(" ", "T")[:16], "napomena": "popis ispravljen"})
    u = db_unos(a6)
    R.provera(u["kolicina"] == "5" and u["sku_id"] == str(hum5) and u["napomena"] == "popis ispravljen", "i kad se pokuša, korekcija menja samo napomenu", u)

    # ── 7. Brisanje i vraćanje ───────────────────────────────────────────────
    R.odeljak("Brisanje i vraćanje")
    sql("UPDATE unosi SET kolicina=100 WHERE id=%d" % a4)
    sql("DELETE FROM dnevnik")
    pre = stanje(hum10)
    adm.get("/admin/istorija.php")
    o = adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": a4, "nazad": "tip=prodaja"})
    R.provera("Unos je obrisan" in poruke(o) and db_unos(a4)["obrisan"] == "1", "brisanje prodaje")
    R.provera(stanje(hum10) == pre + 100, "obrisana prodaja vraća količinu na stanje")
    d = sql("SELECT akcija, korisnik_id FROM dnevnik ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(d == ["brisanje", str(admin_id)], "brisanje je u dnevniku (ko i kad)", d)
    R.provera(sql("SELECT obrisao_id FROM unosi WHERE id=%d" % a4) == str(admin_id) and sql("SELECT obrisan_u IS NOT NULL FROM unosi WHERE id=%d" % a4) == "1", "zabeleženo ko je obrisao i kad")
    o = adm.get("/admin/istorija.php?tip=prodaja")
    R.provera(a4 not in {x[0] for x in redovi_liste(o)}, "obrisan unos nije u uobičajenom prikazu")
    o = adm.get("/admin/istorija.php?tip=prodaja&obrisani=1")
    lista = redovi_liste(o)
    R.provera(any(x[0] == a4 and x[2] for x in lista) and "Vrati unos" in o.text and "Obrisao: Vlasnik" in tekst(o.text), "u prikazu obrisanih unos je precrtan, sa 'Obrisao' i dugmetom 'Vrati unos'")
    R.provera([p[0] for p in ploce(o)][1] != "", "zbirovi ne računaju obrisane")
    o = adm.get("/admin/unos.php?id=%d" % a4)
    R.provera("Obrisan" in o.text and "Vrati unos" in o.text and "Sačuvaj izmene" not in o.text, "obrisan unos se ne može ispravljati, može da se vrati")
    o = adm.post("/admin/unos.php", {"id": a4, "akcija": "sacuvaj", "sku_id": hum10, "kolicina": "5", "nastalo": "2026-10-03T10:00", "kupac": "x"})
    R.provera("ne postoji ili je obrisan" in poruke(o), "POST ispravke za obrisan unos je odbijen")
    o = adm.post("/admin/istorija.php", {"akcija": "vrati", "id": a4, "nazad": "tip=prodaja&obrisani=1"})
    R.provera("Unos je vraćen" in poruke(o) and db_unos(a4)["obrisan"] == "0" and stanje(hum10) == pre, "vraćanje unosa")
    R.provera(sql("SELECT akcija FROM dnevnik ORDER BY id DESC LIMIT 1") == "povracaj", "vraćanje je u dnevniku")

    o = adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": a2, "nazad": "x=1"})   # a2: 810 proizvodnje, već delimično prodato?
    sql("UPDATE unosi SET obrisan=0, obrisan_u=NULL, obrisao_id=NULL WHERE id=%d" % a2)
    # brisanje proizvodnje čiji su komadi prodati
    sql("UPDATE unosi SET kolicina=1200 WHERE id=%d" % a4)       # prodaja 1200: stanje = 540+810−1200−15 = 135
    o = adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": a2, "nazad": "x=1"})
    R.provera("manje od nule" in poruke(o) and db_unos(a2)["obrisan"] == "0", "brisanje proizvodnje koja je već prodata je odbijeno", poruke(o))
    # vraćanje prodaje kad nema dovoljno stanja
    sql("UPDATE unosi SET kolicina=100 WHERE id=%d" % a4)
    adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": a4, "nazad": "x=1"})
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('kucna_prodaja', %d, %d, %d, NOW(), NOW())" % (hum10, stanje(hum10), D))   # potroši sve
    o = adm.post("/admin/istorija.php", {"akcija": "vrati", "id": a4, "nazad": "x=1"})
    R.provera("ne može da se vrati" in poruke(o) and db_unos(a4)["obrisan"] == "1", "vraćanje prodaje bez dovoljno stanja je odbijeno", poruke(o))
    sql("DELETE FROM unosi WHERE tip='kucna_prodaja' AND kolicina > 100 AND korisnik_id=%d" % D)

    R.odeljak("Brisanje koje je uradio radnik se vidi u dnevniku")
    jelena.get("/radnik/index.php")
    jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": hum5, "kolicina": "7", "nacin": "komadi", "kljuc": nov_kljuc()})
    rid = sql_int("SELECT MAX(id) FROM unosi")
    jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": rid})
    o = adm.get("/admin/dnevnik.php")
    R.provera("Obrisao radnik" in o.text and "Jelena" in o.text and "unos #%d" % rid in o.text, "dnevnik prikazuje brisanje koje je uradila Jelena")

    # ── 8. Bezbednost ────────────────────────────────────────────────────────
    R.odeljak("Bezbednost Istorije")
    for strana, podaci in [("/admin/istorija.php", {"akcija": "obrisi", "id": a3, "nazad": "x"}), ("/admin/unos.php", {"id": a3, "akcija": "obrisi"}),
                           ("/admin/unos.php", {"id": a3, "akcija": "sacuvaj"})]:
        o = adm.post(strana, podaci, csrf=False)
        R.provera(o.status == 403 and db_unos(a3)["obrisan"] == "0", "%s bez CSRF žetona → 403" % strana)
    for strana in ["/admin/istorija.php", "/admin/unos.php?id=%d" % a3, "/admin/izvoz.php", "/admin/dnevnik.php"]:
        o = jelena.get(strana, slediti=False)
        R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik ne može na %s" % strana)
        o = Client(site.base).get(strana, slediti=False)
        R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "neprijavljen ne može na %s" % strana)
    o = jelena.post("/admin/istorija.php", {"akcija": "obrisi", "id": a3, "nazad": "x"}, slediti=False)
    R.provera(db_unos(a3)["obrisan"] == "0", "radnik ne može da obriše unos preko admin stranice")
    o = adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": a3, "nazad": "https://zlo.example/?x=1"}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].startswith("/admin/istorija.php?"), "povratna adresa ne može da vodi van aplikacije", o.lanac)
    sql("UPDATE unosi SET obrisan=0, obrisan_u=NULL WHERE id=%d" % a3)

    # ── 9. CSV izvoz ─────────────────────────────────────────────────────────
    R.odeljak("CSV izvoz")
    sql("UPDATE unosi SET kolicina=810, palete=3, sku_id=%d, obrisan=0, obrisan_u=NULL, obrisao_id=NULL WHERE id=%d" % (hum10, a2))
    sql("UPDATE unosi SET kolicina=100, obrisan=0, obrisan_u=NULL, obrisao_id=NULL WHERE id=%d" % a4)
    sql("UPDATE unosi SET kolicina=540 WHERE id=%d" % a1)
    kupac("=HYPERLINK(\"http://zlo.example\")")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, napomena, nastalo, uneto) VALUES ('prodaja', %d, 1, %d, %d, '+1;\"navodnici\" i\nnovi red', NOW(), NOW())" % (hum25, admin_id, kupci["=HYPERLINK(\"http://zlo.example\")"]))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, napomena, nastalo, uneto) VALUES ('proizvodnja', %d, 3, %d, '@SUM(A1)', NOW(), NOW())" % (idea10, M))
    o = adm.get("/admin/izvoz.php?od=&do=")
    R.provera(o.status == 200 and o.header("Content-Type").startswith("text/csv") and "charset=utf-8" in o.header("Content-Type"), "Content-Type: text/csv; charset=utf-8", o.header("Content-Type"))
    R.provera(re.fullmatch(r'attachment; filename="farmerkop-istorija-\d{4}-\d{2}-\d{2}\.csv"', o.header("Content-Disposition") or "") is not None, "preuzima se kao fajl farmerkop-istorija-DATUM.csv", o.header("Content-Disposition"))
    sirovo = o.body
    R.provera(sirovo.startswith("﻿"), "fajl počinje BOM-om (Excel prepoznaje UTF-8 i kvačice)")
    R.provera("\r\n" in sirovo, "redovi se završavaju sa CRLF")
    tabela = list(csv.reader(io.StringIO(sirovo.lstrip("﻿")), delimiter=";"))
    zag = tabela[0]
    R.provera(zag[:9] == ["ID", "Datum", "Vreme", "Dan", "Vrsta", "Artikal", "Boja / granulacija", "Pakovanje", "Količina (kom)"], "zaglavlje na srpskom", zag)
    R.provera(len(tabela) - 1 == sql_int("SELECT COUNT(*) FROM unosi WHERE obrisan=0"), "broj redova = broj neobrisanih unosa", (len(tabela) - 1, sql_int("SELECT COUNT(*) FROM unosi WHERE obrisan=0")))
    R.provera(all(len(r) == len(zag) for r in tabela), "svaki red ima isti broj kolona (i sa ; i novim redom u napomeni)")
    po_id = {int(r[0]): dict(zip(zag, r)) for r in tabela[1:]}
    r2 = po_id[a2]
    R.provera(r2["Vrsta"] == "Proizvodnja" and r2["Artikal"] == "Humovit" and r2["Pakovanje"] == "10 l" and r2["Količina (kom)"] == "810" and r2["Promena stanja (kom)"] == "810" and r2["Palete"] == "3" and r2["Ukupno (l ili kg)"] == "8100", "red proizvodnje: količina, palete, ukupno litara", r2)
    R.provera(re.fullmatch(r"\d{2}\.\d{2}\.\d{4}\.", r2["Datum"]) and re.fullmatch(r"\d{2}:\d{2}", r2["Vreme"]) and r2["Dan"] in ("ponedeljak", "utorak", "sreda", "četvrtak", "petak", "subota", "nedelja"), "datum DD.MM.GGGG., vreme HH:MM i dan u nedelji", (r2["Datum"], r2["Vreme"], r2["Dan"]))
    r4 = po_id[a4]
    R.provera(r4["Vrsta"] == "Prodaja" and r4["Promena stanja (kom)"] == "-100" and r4["Količina (kom)"] == "100", "prodaja: količina 100, promena stanja −100", r4)
    R.provera(po_id[a5]["Vrsta"] == "Kućna prodaja" and po_id[a5]["Radnik / unos"] == "Dragan", "kućna prodaja sa imenom radnika")
    R.provera(po_id[a6]["Vrsta"] == "Korekcija (popis)" and po_id[a6]["Promena stanja (kom)"] == "5", "korekcija popisa")
    c1r = po_id[c1]
    R.provera(c1r["Artikal"] == "Malč Farmerkop" and c1r["Boja / granulacija"] == "Crveni" and c1r["Pakovanje"] == "50 l", "malč sa bojom")
    R.provera(po_id[b2]["Napomena"] == "100% plaćeno_odmah" and po_id[b2]["Kupac"] == "Agrocentar", "kupac i napomena sa specijalnim znacima")
    kolone_formule = [r for r in tabela[1:] if r[zag.index("Kupac")].startswith("'=") or r[zag.index("Napomena")].startswith("'")]
    R.provera(len(kolone_formule) == 2, "ćelije koje počinju sa = + @ dobijaju apostrof (zaštita od Excel formula)", kolone_formule)
    nap = [r[zag.index("Napomena")] for r in tabela[1:] if "navodnici" in r[zag.index("Napomena")]]
    R.provera(nap == ["'+1;\"navodnici\" i\nnovi red"], "navodnici, tačka-zarez i novi red u napomeni su ispravno upakovani", nap)
    R.provera("Vrt i bašta Kraljevo" in sirovo and "plaćeno_odmah" in sirovo, "slova sa kvačicama (š, ć) su ispravna")
    R.provera(all(re.fullmatch(r"-?\d+", r[zag.index("Količina (kom)")]) for r in tabela[1:]), "količine su čisti brojevi (Excel ih sabira)")

    R.odeljak("CSV – filteri važe i za izvoz")
    o = adm.get("/admin/izvoz.php?tip=prodaja&od=&do=")
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    R.provera(all(r[4] == "Prodaja" for r in t[1:]) and len(t) > 2, "tip=prodaja izvozi samo prodaje", {r[4] for r in t[1:]})
    o = adm.get("/admin/izvoz.php?od=%s&do=%s" % (juce, juce))
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    R.provera({int(r[0]) for r in t[1:]} == {b1, b2}, "period juče izvozi samo juče")
    o = adm.get("/admin/izvoz.php?radnik=%d&artikal=%d&od=&do=" % (J, art_hum))
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    R.provera({r[12] for r in t[1:]} == {"Jelena"} and {r[5] for r in t[1:]} == {"Humovit"}, "radnik + artikal")
    o = adm.get("/admin/izvoz.php?q=bašta&od=&do=")
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    R.provera(len(t) == 2 and t[1][13] == "Vrt i bašta Kraljevo", "pretraga po kupcu")
    o = adm.get("/admin/izvoz.php?obrisani=1&od=&do=")
    t = list(csv.reader(io.StringIO(o.body.lstrip("﻿")), delimiter=";"))
    R.provera(t[0][-1] == "Obrisano" and any(r[-1] == "da" for r in t[1:]) and len(t) - 1 == sql_int("SELECT COUNT(*) FROM unosi"), "sa obrisanim: dodatna kolona 'Obrisano' (da/ne)")
    o = adm.get("/admin/izvoz.php?od=&do=&tip=nepostojeci")
    R.provera(o.status == 200 and len(o.body.splitlines()) > 5, "nepoznat tip se ignoriše")
    o = adm.get("/admin/istorija.php?tip=prodaja&od=%s&do=%s" % (juce, juce))
    R.provera("izvoz.php?tip=prodaja&amp;od=%s&amp;do=%s" % (juce, juce) in o.text, "dugme za izvoz nosi trenutne filtere", re.findall(r'href="([^"]*izvoz[^"]*)"', o.text))

    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
