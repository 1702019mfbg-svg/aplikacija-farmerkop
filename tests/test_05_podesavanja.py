#!/usr/bin/env python3
"""Faza 5: Radnici i Podešavanja (artikli, varijante, pakovanja, popis, šifra)."""
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


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def katalog(c):
    """Katalog koji vidi radnik: {artikal: {'varijante': [...], 'pakovanja': {...}}}"""
    o = c.get("/radnik/index.php")
    j = json.loads(re.search(r'<script type="application/json" data-katalog>(.*?)</script>', o.text, re.S).group(1))
    izlaz = {}
    for k in j["kategorije"]:
        for a in k["artikli"]:
            izlaz[a["naziv"]] = {"kat": k["naziv"], "varijante": [v["naziv"] for v in a["varijante"]],
                                 "sku": a["sku"], "pakovanja": sorted({s["p"] for s in a["sku"]}), "nv": a["nv"]}
    return izlaz


def redosled_artikala(c):
    o = c.get("/radnik/index.php")
    j = json.loads(re.search(r'<script type="application/json" data-katalog>(.*?)</script>', o.text, re.S).group(1))
    return [a["naziv"] for k in j["kategorije"] for a in k["artikli"]]


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    admin_id = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")

    # ═════════════ RADNICI ═════════════
    R.odeljak("Radnici – ekran")
    o = adm.get("/admin/radnici.php")
    R.provera(o.status == 200 and "Novi radnik" in o.text and "Marko" in o.text and "Jelena" in o.text and "Dragan" in o.text, "ekran Radnici se otvara i prikazuje radnike")
    R.provera("data-slucajni-pin" in o.text, "ima dugme 'Slučajan PIN'")

    R.odeljak("Radnici – dodavanje")
    def novi(ime, pin):
        adm.get("/admin/radnici.php")
        return adm.post("/admin/radnici.php", {"akcija": "novi", "ime": ime, "pin": pin})

    o = novi("Petar P.", "4821")
    R.provera("Radnik „Petar P.“ je dodat" in poruke(o), "radnik je dodat", poruke(o))
    r = sql("SELECT uloga, aktivan, hes FROM korisnici WHERE ime='Petar P.'").split("\t")
    R.provera(r[0] == "radnik" and r[1] == "1" and r[2].startswith("$2y$") and "4821" not in r[2], "u bazi: radnik, aktivan, PIN je bcrypt heš")
    R.provera("4821" not in sql("SELECT GROUP_CONCAT(hes) FROM korisnici") and "4821" not in sql("SELECT GROUP_CONCAT(detalji) FROM dnevnik"), "PIN se ne nalazi u bazi ni u dnevniku kao tekst")
    petar_id = sql_int("SELECT id FROM korisnici WHERE ime='Petar P.'")
    petar = prijavi_radnika(site, petar_id, "4821")
    R.provera("Petar P." in petar.get("/radnik/index.php").text, "novi radnik može da se prijavi svojim PIN-om")
    R.provera("Petar P." in Client(site.base).get("/login.php").text, "i pojavljuje se na listi za prijavu")

    for ime, pin, deo in [("A", "4821", "najmanje 2 slova"), ("Novi Radnik", "482", "tačno 4 cifre"), ("Novi Radnik", "48a1", "tačno 4 cifre"),
                          ("Novi Radnik", "", "tačno 4 cifre"), ("Novi Radnik", "0000", "previše lak"), ("Novi Radnik", "1111", "previše lak"),
                          ("Novi Radnik", "1234", "previše lak"), ("Novi Radnik", "4321", "previše lak"), ("Novi Radnik", "2345", "previše lak"),
                          ("Novi Radnik", "9876", "previše lak"), ("Petar P.", "7391", "Već postoji"), ("petar p.", "7391", "Već postoji"),
                          ("Vlasnik", "7391", "Već postoji")]:
        pre = sql_int("SELECT COUNT(*) FROM korisnici")
        o = novi(ime, pin)
        R.provera(deo in poruke(o) and sql_int("SELECT COUNT(*) FROM korisnici") == pre, "odbijeno: ime=%r pin=%r" % (ime, pin), poruke(o))
    o = novi("<b>Hak</b>", "7391")
    R.provera("&lt;b&gt;Hak&lt;/b&gt;" in adm.get("/admin/radnici.php").text and "<b>Hak</b>" not in adm.get("/admin/radnici.php").text, "ime sa HTML kodom se bezbedno ispisuje (XSS)")
    sql("DELETE FROM korisnici WHERE ime='<b>Hak</b>'")

    R.odeljak("Radnici – promena imena i PIN-a")
    pet_sesija = petar_id
    adm.get("/admin/radnici.php")
    o = adm.post("/admin/radnici.php", {"akcija": "izmeni", "id": petar_id, "ime": "Petar Petrović", "pin": "7391"})
    R.provera("ime je promenjeno" in poruke(o).lower() and "PIN je promenjen" in poruke(o), "ime i PIN su promenjeni", poruke(o))
    R.provera(petar.get("/radnik/index.php", slediti=False).status == 302, "promena PIN-a odjavljuje radnika sa svih uređaja")
    c = Client(site.base); c.get("/login.php")
    o = c.post("/login.php", {"tip": "radnik", "radnik_id": petar_id, "pin": "4821"})
    R.provera("Pogrešan PIN" in o.text, "stari PIN više ne važi")
    R.provera(prijavi_radnika(site, petar_id, "7391").get("/radnik/index.php").status == 200, "novi PIN važi")
    R.provera("Petar Petrović" in Client(site.base).get("/login.php").text, "novo ime je na listi za prijavu")
    o = adm.post("/admin/radnici.php", {"akcija": "izmeni", "id": petar_id, "ime": "Petar Petrović", "pin": "1111"})
    R.provera("previše lak" in poruke(o), "i pri izmeni se odbija lak PIN")
    o = adm.post("/admin/radnici.php", {"akcija": "izmeni", "id": petar_id, "ime": "Marko", "pin": ""})
    R.provera("Već postoji korisnik" in poruke(o), "ne može se uzeti ime drugog radnika")
    o = adm.post("/admin/radnici.php", {"akcija": "izmeni", "id": petar_id, "ime": "Petar Petrović", "pin": ""})
    R.provera("Ništa nije promenjeno" in poruke(o), "bez promene – poruka 'ništa nije promenjeno'")

    R.odeljak("Radnici – gašenje i vraćanje pristupa")
    petar = prijavi_radnika(site, petar_id, "7391")
    petar.get("/radnik/index.php")
    petar.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku_id("Humovit", 10), "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    adm.get("/admin/radnici.php")
    o = adm.post("/admin/radnici.php", {"akcija": "status", "id": petar_id})
    R.provera(sql("SELECT aktivan FROM korisnici WHERE id=%d" % petar_id) == "0" and "odjavljen je odmah" in poruke(o), "pristup je isključen", poruke(o))
    R.provera(petar.get("/radnik/index.php", slediti=False).status == 302, "isključeni radnik je odjavljen odmah, usred rada")
    R.provera("Petar Petrović" not in Client(site.base).get("/login.php").text, "nestaje sa liste za prijavu")
    c = Client(site.base); c.get("/login.php")
    o = c.post("/login.php", {"tip": "radnik", "radnik_id": petar_id, "pin": "7391"})
    R.provera("isključen" in o.text, "ne može da se prijavi ni tačnim PIN-om")
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE korisnik_id=%d" % petar_id) == 1, "njegovi unosi ostaju sačuvani")
    o = adm.get("/admin/radnici.php")
    R.provera("radnik-ugasen" in o.text and "Vrati pristup" in o.text, "isključen radnik je označen, ima dugme 'Vrati pristup'")
    o = adm.post("/admin/radnici.php", {"akcija": "status", "id": petar_id})
    R.provera(sql("SELECT aktivan FROM korisnici WHERE id=%d" % petar_id) == "1", "pristup je vraćen")
    R.provera(prijavi_radnika(site, petar_id, "7391").get("/radnik/index.php").status == 200, "radnik ponovo može da se prijavi")
    o = adm.post("/admin/radnici.php", {"akcija": "status", "id": admin_id})
    R.provera(sql("SELECT aktivan FROM korisnici WHERE id=%d" % admin_id) == "1", "administrator ne može da se isključi preko ovog ekrana")
    o = adm.post("/admin/radnici.php", {"akcija": "status", "id": 99999})
    R.provera("nije pronađen" in poruke(o), "nepostojeći radnik – poruka, bez greške")

    R.odeljak("Radnici – otključavanje")
    sql("DELETE FROM neuspele_prijave")
    c = Client(site.base); c.get("/login.php")
    for i in range(5):
        c.post("/login.php", {"tip": "radnik", "radnik_id": petar_id, "pin": "5555"})
    o = adm.get("/admin/radnici.php")
    R.provera("zaključan do" in o.text and "Otključaj" in o.text, "zaključan radnik se vidi sa dugmetom 'Otključaj'")
    o = adm.post("/admin/radnici.php", {"akcija": "otkljucaj", "id": petar_id})
    R.provera(prijavi_radnika(site, petar_id, "7391").get("/radnik/index.php").status == 200, "posle otključavanja radnik može da se prijavi")
    sql("DELETE FROM neuspele_prijave")

    R.odeljak("Radnici – ko je koliko proizveo")
    sql("DELETE FROM unosi")
    J, M, D = ids["Jelena"], ids["Marko"], ids["Dragan"]
    hum10 = sku_id("Humovit", 10)

    def unos(radnik, kom, dana, tip="proizvodnja", obrisan=0):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto, obrisan) VALUES ('%s', %d, %d, %d, DATE_SUB(NOW(), INTERVAL %d DAY), NOW(), %d)" % (tip, hum10, kom, radnik, dana, obrisan))
    unos(J, 100, 0); unos(J, 50, 0); unos(M, 30, 0); unos(J, 70, 1); unos(D, 20, 1); unos(M, 999, 1, obrisan=1)
    unos(J, 10, 2, tip="kucna_prodaja"); unos(M, 400, 20); unos(petar_id, 5, 3)
    o = adm.get("/admin/radnici.php")
    R.provera("Danas proizvedeno: <strong>150 kom</strong>" in o.text.replace("\n", " ").replace("  ", " ") or "150 kom" in o.text, "kartica radnika: danas proizvedeno (Jelena 150)")
    tbl = re.search(r"<table.*?</table>", o.text, re.S).group(0)
    redovi = [[tekst(c) for c in re.findall(r"<t[hd][^>]*>(.*?)</t[hd]>", r, re.S)] for r in re.findall(r"<tr>(.*?)</tr>", tbl, re.S)]
    zag = redovi[0]
    def celija(red, ime):
        return red[zag.index(ime)]
    R.provera(zag[0] == "Dan" and zag[-1] == "Ukupno" and {"Jelena", "Marko", "Dragan", "Petar Petrović"} <= set(zag), "tabela: kolone po radnicima + ukupno", zag)
    danas_red = redovi[1]
    R.provera(celija(danas_red, "Jelena") == "150" and celija(danas_red, "Marko") == "30" and celija(danas_red, "Dragan") == "–" and celija(danas_red, "Ukupno") == "180", "današnji red: Jelena 150, Marko 30, Dragan –, ukupno 180", danas_red)
    juce_red = redovi[2]
    R.provera(celija(juce_red, "Jelena") == "70" and celija(juce_red, "Dragan") == "20" and celija(juce_red, "Marko") == "–" and celija(juce_red, "Ukupno") == "90", "juče: obrisan unos (999) i kućna prodaja se ne računaju", juce_red)
    R.provera(len(redovi) == 1 + 3 + 1, "prikazani su samo dani sa proizvodnjom (danas, juče, pre 3 dana) + zbir", len(redovi))
    ukupno_red = redovi[-1]
    R.provera(celija(ukupno_red, "Jelena") == "220" and celija(ukupno_red, "Marko") == "30" and celija(ukupno_red, "Ukupno") == "275", "ukupno za period (14 dana): Jelena 220, Marko 30, svi 275 – unos od pre 20 dana nije uključen", ukupno_red)
    R.provera("tip=proizvodnja&amp;radnik=%d&amp;od=" % J in o.text, "broj u tabeli vodi na istoriju sa filterom")
    o = adm.get("/admin/radnici.php?od=%s&do=%s" % (sql("SELECT DATE_SUB(CURDATE(), INTERVAL 30 DAY)"), sql("SELECT DATE_SUB(CURDATE(), INTERVAL 10 DAY)")))
    tbl2 = re.search(r"<table.*?</table>", o.text, re.S).group(0)
    red2 = [[tekst(c) for c in re.findall(r"<t[hd][^>]*>(.*?)</t[hd]>", r, re.S)] for r in re.findall(r"<tr>(.*?)</tr>", tbl2, re.S)]
    R.provera(len(red2) == 3 and red2[1][red2[0].index("Marko")] == "400" and red2[-1][-1] == "400", "stariji period: vidi se samo Markov unos od pre 20 dana (400)", red2)
    o = adm.get("/admin/radnici.php?od=glupost&do=2026-99-99")
    R.provera(o.status == 200 and "<table" in o.text, "neispravan period se zamenjuje podrazumevanim (14 dana)")
    o = adm.get("/admin/radnici.php?od=2026-12-31&do=2026-01-01")
    R.provera(o.status == 200, "obrnut period ne ruši stranicu")
    o = adm.get("/admin/radnici.php?od=1990-01-01&do=2099-01-01")
    R.provera(o.status == 200 and not site.php_problemi(), "preširok period se ograničava")

    R.odeljak("Radnici – sigurnost")
    pre = sql_int("SELECT COUNT(*) FROM korisnici")
    o = adm.post("/admin/radnici.php", {"akcija": "novi", "ime": "Bez Žetona", "pin": "7391"}, csrf=False)
    R.provera(o.status == 403 and sql_int("SELECT COUNT(*) FROM korisnici") == pre, "dodavanje bez CSRF žetona → 403")
    for strana in ["/admin/radnici.php", "/admin/podesavanja.php", "/admin/artikli.php", "/admin/artikal.php?id=1", "/admin/pakovanja.php", "/admin/popis.php", "/admin/sifra.php"]:
        o = jelena.get(strana, slediti=False)
        R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik ne može na " + strana)
        o = Client(site.base).get(strana, slediti=False)
        R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "neprijavljen ne može na " + strana)
    o = jelena.post("/admin/radnici.php", {"akcija": "novi", "ime": "Hakerisan", "pin": "7391"}, slediti=False)
    R.provera(sql_int("SELECT COUNT(*) FROM korisnici WHERE ime='Hakerisan'") == 0, "radnik ne može da doda radnika")
    d = sql("SELECT GROUP_CONCAT(detalji SEPARATOR ' | ') FROM dnevnik WHERE objekat='korisnik'")
    R.provera("Dodat radnik: Petar P." in d and "Isključen pristup radniku: Petar Petrović" in d and "Promenjen PIN radniku" in d, "promene radnika su u dnevniku", d[:300])

    # ═════════════ PODEŠAVANJA – HUB ═════════════
    R.odeljak("Podešavanja – početna")
    o = adm.get("/admin/podesavanja.php")
    R.provera(o.status == 200 and all(x in o.text for x in ["Artikli, boje i granulacije", "Pakovanja", "Popis i korekcija stanja", "Promena administratorske šifre", "Dnevnik izmena"]), "podešavanja imaju sve stavke")

    # ═════════════ ARTIKLI ═════════════
    R.odeljak("Artikli – pregled i redosled")
    o = adm.get("/admin/artikli.php")
    R.provera(o.status == 200 and "Humovit" in o.text and "Zemlja za cveće" in o.text and "Dekorativni oblutak" in o.text, "pregled artikala po kategorijama")
    R.provera(redosled_artikala(jelena) == ["Humovit", "Humovit premium", "Floris Savacoop", "Idea", "Malč Farmerkop", "Malč Floris Savacoop", "Beli oblutak"], "redosled kakav vide radnici")
    idea_id = sql_int("SELECT id FROM artikli WHERE naziv='Idea'")
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    adm.get("/admin/artikli.php")
    adm.post("/admin/artikli.php", {"akcija": "art_pomeri", "id": idea_id, "smer": "gore"})
    R.provera(redosled_artikala(jelena)[:4] == ["Humovit", "Humovit premium", "Idea", "Floris Savacoop"], "Idea pomerena iznad Floris Savacoop", redosled_artikala(jelena)[:4])
    adm.post("/admin/artikli.php", {"akcija": "art_pomeri", "id": idea_id, "smer": "dole"})
    R.provera(redosled_artikala(jelena)[:4] == ["Humovit", "Humovit premium", "Floris Savacoop", "Idea"], "i vraćena nazad")
    adm.post("/admin/artikli.php", {"akcija": "art_pomeri", "id": hum_id, "smer": "gore"})
    R.provera(redosled_artikala(jelena)[0] == "Humovit", "prvi artikal se ne može pomeriti iznad prvog")

    R.odeljak("Artikli – uključivanje i isključivanje")
    adm.post("/admin/artikli.php", {"akcija": "art_status", "id": idea_id})
    R.provera("Idea" not in redosled_artikala(jelena), "isključen artikal nestaje iz izbora radnika")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 33, %d, NOW(), NOW())" % (sku_id("Idea", 10), J))
    o = adm.get("/admin/stanje.php")
    R.provera("Idea" in o.text and "isključen" in o.text, "ali stanje isključenog artikla ostaje vidljivo dok ga ima")
    o = jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku_id("Idea", 10), "kolicina": "1", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("Izaberite artikal" in poruke(o), "radnik ne može da unese isključen artikal ni direktnim slanjem")
    adm.post("/admin/artikli.php", {"akcija": "art_status", "id": idea_id})
    R.provera("Idea" in redosled_artikala(jelena), "uključen artikal se vraća u izbor")
    cm_id = sql_int("SELECT id FROM artikli WHERE naziv='Čmana supstrat'")
    adm.post("/admin/artikli.php", {"akcija": "art_status", "id": cm_id})
    R.provera("Čmana supstrat" in redosled_artikala(jelena) and katalog(jelena)["Čmana supstrat"]["pakovanja"] == ["10 l", "20 l", "50 l"], "Čmana se uključuje jednim klikom, sa pakovanjima 10, 20, 50 l")
    adm.post("/admin/artikli.php", {"akcija": "art_status", "id": cm_id})
    sql("DELETE FROM unosi WHERE kolicina=33")

    R.odeljak("Artikli – kategorije")
    kat_ob = sql_int("SELECT id FROM kategorije WHERE naziv='Dekorativni oblutak'")
    o = adm.post("/admin/artikli.php", {"akcija": "nova_kategorija", "naziv": "Đubrivo"})
    R.provera("Kategorija „Đubrivo“ je dodata" in poruke(o), "nova kategorija")
    o = adm.post("/admin/artikli.php", {"akcija": "nova_kategorija", "naziv": "đubrivo"})
    R.provera("već postoji" in poruke(o), "duplikat kategorije se odbija")
    o = adm.post("/admin/artikli.php", {"akcija": "nova_kategorija", "naziv": "x"})
    R.provera("Upišite naziv" in poruke(o), "prekratak naziv se odbija")
    dub_id = sql_int("SELECT id FROM kategorije WHERE naziv='Đubrivo'")
    adm.post("/admin/artikli.php", {"akcija": "kat_izmeni", "id": dub_id, "naziv": "Đubriva"})
    R.provera(sql("SELECT naziv FROM kategorije WHERE id=%d" % dub_id) == "Đubriva", "preimenovanje kategorije")
    adm.post("/admin/artikli.php", {"akcija": "kat_pomeri", "id": dub_id, "smer": "gore"})
    R.provera(sql("SELECT naziv FROM kategorije ORDER BY redosled, id").split("\n")[-2] == "Đubriva", "pomeranje kategorije", sql("SELECT naziv FROM kategorije ORDER BY redosled, id"))
    adm.post("/admin/artikli.php", {"akcija": "kat_status", "id": kat_ob})
    R.provera("Beli oblutak" not in redosled_artikala(jelena), "isključena kategorija sakriva svoje artikle")
    adm.post("/admin/artikli.php", {"akcija": "kat_status", "id": kat_ob})
    R.provera("Beli oblutak" in redosled_artikala(jelena), "uključena kategorija vraća artikle")

    R.odeljak("Artikli – novi artikal")
    o = adm.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "Crni oblutak", "kategorija_id": kat_ob, "oznaka": "", "nv": "Granulacija"}, slediti=False)
    R.provera(o.status == 302 and "/admin/artikal.php?id=" in o.lanac[0][1], "posle dodavanja vodi na stranicu artikla")
    crni_id = sql_int("SELECT id FROM artikli WHERE naziv='Crni oblutak'")
    R.provera(sql("SELECT naziv_varijante FROM artikli WHERE id=%d" % crni_id) == "Granulacija" and sql("SELECT IFNULL(oznaka,'NULL') FROM artikli WHERE id=%d" % crni_id) == "NULL", "sačuvano: izbor se zove Granulacija, bez oznake")
    R.provera("Crni oblutak" not in redosled_artikala(jelena), "novi artikal bez pakovanja se ne nudi radnicima")
    o = adm.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "crni oblutak", "kategorija_id": kat_ob})
    R.provera("već postoji artikal" in poruke(o), "duplikat artikla u istoj kategoriji se odbija")
    o = adm.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "Test", "kategorija_id": 99999})
    R.provera("Izaberite kategoriju" in poruke(o), "nepostojeća kategorija se odbija")

    # ═════════════ ARTIKAL ═════════════
    R.odeljak("Artikal – pakovanja, paleta i minimum")
    o = adm.get("/admin/artikal.php?id=%d" % idea_id)
    R.provera(o.status == 200 and "Osnovno" in o.text, "stranica artikla se otvara")
    cekirana = re.findall(r'name="pak\[\]" value="(\d+)" checked', o.text)
    R.provera(len(cekirana) == 4, "Idea ima 4 označena pakovanja", cekirana)
    po = {m[0]: m[1] for m in re.findall(r'name="po\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
    idea10 = sku_id("Idea", 10)
    R.provera(po[str(idea10)] == "225", "Idea 10 l: 225 komada po paleti", po)
    def vrednosti(artikal_id, **izmene):
        """POST 'vrednosti' sa trenutnim vrednostima i izmenama {sku_id: (po, min)}"""
        o = adm.get("/admin/artikal.php?id=%d" % artikal_id)
        po = {m[0]: m[1] for m in re.findall(r'name="po\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
        mn = {m[0]: m[1] for m in re.findall(r'name="min\[(\d+)\]"[^>]*value="(\d*)"', o.text)}
        podaci = {"akcija": "vrednosti", "id": artikal_id}
        for k in po:
            podaci["po[%s]" % k] = po[k]
            podaci["min[%s]" % k] = mn[k]
        for k, (p, m) in izmene.items():
            podaci["po[%s]" % k] = p
            podaci["min[%s]" % k] = m
        return adm.post("/admin/artikal.php", podaci)

    o = vrednosti(idea_id, **{str(idea10): ("230", "100")})
    R.provera("Sačuvano" in poruke(o) and sql("SELECT po_paleti, min_zaliha FROM sku WHERE id=%d" % idea10) == "230\t100", "paleta i minimum su sačuvani")
    jelena.get("/radnik/index.php")
    jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": idea10, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})
    R.provera(sql("SELECT kolicina FROM unosi WHERE sku_id=%d ORDER BY id DESC LIMIT 1" % idea10) == "230", "nova paleta važi odmah: 1 paleta Ideje 10 l = 230 komada")
    st = adm.get("/admin/stanje.php")
    R.provera(re.search(r'sku-red nisko', st.text) is None, "stanje 230 ≥ minimum 100 – nema upozorenja")
    vrednosti(idea_id, **{str(idea10): ("230", "300")})
    st = adm.get("/admin/stanje.php")
    R.provera("ispod minimuma (300)" in st.text, "minimum 300 > stanje 230 → crveno upozorenje")
    for losa, opis in [(("0", "5"), "paleta 0"), (("abc", "5"), "paleta slova"), (("70000", "5"), "paleta veća od 65535"), (("-3", "5"), "negativna paleta"),
                       (("10", "-1"), "negativan minimum"), (("10", "x"), "minimum slova"), (("10", "1.5"), "minimum decimalan")]:
        pre = sql("SELECT po_paleti, min_zaliha FROM sku WHERE id=%d" % idea10)
        o = vrednosti(idea_id, **{str(idea10): losa})
        R.provera("poruka-greska" in o.text and sql("SELECT po_paleti, min_zaliha FROM sku WHERE id=%d" % idea10) == pre, "odbijeno: " + opis, poruke(o))
    o = vrednosti(idea_id, **{str(idea10): ("", "")})
    R.provera(sql("SELECT IFNULL(po_paleti,'NULL'), min_zaliha FROM sku WHERE id=%d" % idea10) == "NULL\t0", "prazna paleta = nije podešena, prazan minimum = 0")
    o = jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": idea10, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})
    R.provera("nije podešeno koliko komada ide na paletu" in poruke(o), "bez podešene palete radnik ne može da unese palete (samo komade)")
    vrednosti(idea_id, **{str(idea10): ("225", "0")})
    sql("DELETE FROM unosi WHERE sku_id=%d" % idea10)

    R.odeljak("Artikal – izbor pakovanja")
    def pak_id(oznaka):
        kol, jed = oznaka.replace(",", ".").split()
        return sql("SELECT id FROM pakovanja WHERE kolicina=%s AND jedinica='%s'" % (kol, jed))
    pak_ids = {x: pak_id(x) for x in ["5 l", "10 l", "20 l", "25 l", "50 l", "20 kg"]}
    def sacuvaj_pakovanja(artikal_id, lista):
        adm.get("/admin/artikal.php?id=%d" % artikal_id)
        return adm.post("/admin/artikal.php", {"akcija": "pakovanja", "id": artikal_id, "pak[]": [pak_id(x) for x in lista]})
    sacuvaj_pakovanja(idea_id, ["5 l", "10 l", "20 l", "25 l", "50 l"])
    R.provera(katalog(jelena)["Idea"]["pakovanja"] == ["10 l", "20 l", "25 l", "5 l", "50 l"], "dodato pakovanje 50 l", katalog(jelena)["Idea"]["pakovanja"])
    R.provera(sql("SELECT IFNULL(po_paleti,'NULL') FROM sku WHERE id=%d" % sku_id("Idea", 50)) == "NULL", "novo pakovanje nema podešenu paletu dok se ne upiše")
    br_sku = sql_int("SELECT COUNT(*) FROM sku WHERE artikal_id=%d" % idea_id)
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 77, %d, NOW(), NOW())" % (sku_id("Idea", 5), J))
    sacuvaj_pakovanja(idea_id, ["10 l", "20 l", "25 l", "50 l"])
    R.provera("5 l" not in katalog(jelena)["Idea"]["pakovanja"], "uklonjeno pakovanje 5 l nestaje iz izbora radnika")
    o = adm.get("/admin/stanje.php")
    R.provera("5 l" in o.text and stanje(sku_id("Idea", 5)) == 77, "ali stanje (77) ostaje vidljivo u Stanju")
    sacuvaj_pakovanja(idea_id, ["5 l", "10 l", "20 l", "25 l", "50 l"])
    R.provera(sql_int("SELECT COUNT(*) FROM sku WHERE artikal_id=%d" % idea_id) == br_sku and sql("SELECT po_paleti FROM sku WHERE id=%d" % sku_id("Idea", 5)) == "450", "ponovo dodato 5 l vraća isti SKU (bez duplikata) sa sačuvanom paletom 450")
    sql("DELETE FROM unosi WHERE sku_id=%d" % sku_id("Idea", 5))
    sacuvaj_pakovanja(idea_id, ["5 l", "10 l", "20 l", "25 l"])
    sacuvaj_pakovanja(crni_id, ["20 kg"])

    R.odeljak("Artikal – varijante")
    mf = sql_int("SELECT id FROM artikli WHERE naziv='Malč Farmerkop'")
    adm.get("/admin/artikal.php?id=%d" % mf)
    o = adm.post("/admin/artikal.php", {"akcija": "var_nova", "id": mf, "naziv": "Plavi"})
    R.provera("„Plavi“ je dodato" in poruke(o), "nova boja je dodata", poruke(o))
    plavi_sku = sql("SELECT s.po_paleti, s.min_zaliha, s.aktivan FROM sku s JOIN varijante v ON v.id=s.varijanta_id WHERE v.naziv='Plavi'")
    R.provera(plavi_sku == "40\t0\t1", "nova boja odmah dobija pakovanje 50 l sa 40 po paleti", plavi_sku)
    R.provera(katalog(jelena)["Malč Farmerkop"]["varijante"][-1] == "Plavi" and len(katalog(jelena)["Malč Farmerkop"]["varijante"]) == 8, "radnici vide 8 boja, Plavi je poslednji")
    o = adm.post("/admin/artikal.php", {"akcija": "var_nova", "id": mf, "naziv": "plavi"})
    R.provera("već postoji" in poruke(o), "duplikat boje se odbija")
    vid = sql_int("SELECT id FROM varijante WHERE naziv='Plavi'")
    adm.post("/admin/artikal.php", {"akcija": "var_izmeni", "id": mf, "vid": vid, "naziv": "Svetlo plavi"})
    R.provera("Svetlo plavi" in katalog(jelena)["Malč Farmerkop"]["varijante"], "preimenovanje boje")
    adm.post("/admin/artikal.php", {"akcija": "var_pomeri", "id": mf, "vid": vid, "smer": "gore"})
    R.provera(katalog(jelena)["Malč Farmerkop"]["varijante"][-2] == "Svetlo plavi", "pomeranje boje")
    adm.post("/admin/artikal.php", {"akcija": "var_status", "id": mf, "vid": vid})
    R.provera("Svetlo plavi" not in katalog(jelena)["Malč Farmerkop"]["varijante"], "isključena boja nestaje iz izbora")
    o = adm.get("/admin/artikal.php?id=%d" % mf)
    R.provera("(isključeno)" in o.text, "…a u podešavanjima je označena kao isključena")
    adm.post("/admin/artikal.php", {"akcija": "var_status", "id": mf, "vid": vid})
    o = adm.post("/admin/artikal.php", {"akcija": "var_izmeni", "id": mf, "vid": vid, "naziv": "Crveni"})
    R.provera("već postoji" in poruke(o), "ne može se dati naziv koji već postoji")
    o = adm.post("/admin/artikal.php", {"akcija": "var_izmeni", "id": mf, "vid": sql_int("SELECT id FROM varijante WHERE naziv='Braon' AND artikal_id<>%d LIMIT 1" % mf), "naziv": "Hak"})
    R.provera(sql("SELECT COUNT(*) FROM varijante WHERE naziv='Hak'") == "0", "varijanta drugog artikla se ne može menjati preko ovog artikla")

    R.odeljak("Artikal – prvi put dobija varijante")
    adm.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "Perlit", "kategorija_id": kat_ob, "nv": ""})
    perlit = sql_int("SELECT id FROM artikli WHERE naziv='Perlit'")
    sacuvaj_pakovanja(perlit, ["5 l", "10 l"])
    R.provera(katalog(jelena)["Perlit"]["pakovanja"] == ["10 l", "5 l"] and katalog(jelena)["Perlit"]["varijante"] == [], "Perlit bez varijanti: pakovanja 5 l i 10 l")
    sql("UPDATE sku SET po_paleti=100 WHERE artikal_id=%d AND pakovanje_id=%s" % (perlit, pak_ids["5 l"]))
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 10, %d, NOW(), NOW())" % (sku_id("Perlit", 5), J))
    adm.get("/admin/artikal.php?id=%d" % perlit)
    o = adm.post("/admin/artikal.php", {"akcija": "var_nova", "id": perlit, "naziv": "Fini", "nv": "Frakcija"})
    R.provera("Ranije stanje bez izbora ostaje vidljivo" in poruke(o), "upozorenje da staro stanje ostaje vidljivo", poruke(o))
    k = katalog(jelena)["Perlit"]
    R.provera(k["varijante"] == ["Fini"] and k["nv"] == "Frakcija" and k["pakovanja"] == ["10 l", "5 l"], "izbor se zove 'Frakcija', varijanta 'Fini' dobija ista pakovanja", k)
    R.provera(sql("SELECT s.po_paleti FROM sku s JOIN varijante v ON v.id=s.varijanta_id WHERE v.naziv='Fini' AND s.pakovanje_id=%s" % pak_ids["5 l"]) == "100", "paleta se prenosi na novu varijantu (100)")
    st = adm.get("/admin/stanje.php")
    R.provera("Perlit" in st.text and "isključen" in st.text, "staro stanje bez izbora (10) je u Stanju kao 'isključen'")
    o = adm.get("/admin/artikal.php?id=%d" % perlit)
    R.provera("stara pakovanja bez izbora" in o.text.lower() or "Bez izbora" not in o.text, "u podešavanjima artikla staro stanje bez izbora je objašnjeno ili nije prikazano")

    # ═════════════ PAKOVANJA ═════════════
    R.odeljak("Pakovanja")
    o = adm.get("/admin/pakovanja.php")
    R.provera(o.status == 200 and all(x in o.text for x in ["5 l", "10 l", "20 l", "25 l", "50 l", "20 kg"]), "spisak pakovanja")
    def novo_pak(k, j):
        adm.get("/admin/pakovanja.php")
        return adm.post("/admin/pakovanja.php", {"akcija": "novo", "kolicina": k, "jedinica": j})
    o = novo_pak("15", "l")
    R.provera("Pakovanje 15 l je dodato" in poruke(o), "novo pakovanje 15 l")
    o = novo_pak("2,5", "l")
    R.provera("Pakovanje 2,5 l je dodato" in poruke(o) and sql("SELECT COUNT(*) FROM pakovanja WHERE jedinica='l' AND kolicina=2.5") == "1", "decimalno pakovanje sa zarezom: 2,5 l")
    o = novo_pak("5", "kg")
    R.provera("Pakovanje 5 kg je dodato" in poruke(o), "isti broj, druga jedinica (5 kg) je dozvoljen")
    for k, j, deo in [("5", "l", "već postoji"), ("5.00", "l", "već postoji"), ("0", "l", "veći od nule"), ("-3", "l", "veći od nule"), ("abc", "l", "veći od nule"),
                      ("1001", "l", "veći od nule"), ("", "l", "veći od nule"), ("5", "m3", "jedinicu")]:
        pre = sql_int("SELECT COUNT(*) FROM pakovanja")
        o = novo_pak(k, j)
        R.provera(deo in poruke(o) and sql_int("SELECT COUNT(*) FROM pakovanja") == pre, "odbijeno: %r %r" % (k, j), poruke(o))
    o = adm.get("/admin/artikal.php?id=%d" % idea_id)
    R.provera("15 l" in o.text and "2,5 l" in o.text, "nova pakovanja se nude na stranici artikla")
    sacuvaj_pakovanja(idea_id, ["5 l", "10 l", "15 l", "20 l", "25 l"])
    R.provera("15 l" in katalog(jelena)["Idea"]["pakovanja"], "Idea sada ima i 15 l")
    pid25 = pak_ids["25 l"]
    adm.post("/admin/pakovanja.php", {"akcija": "status", "id": pid25})
    k = katalog(jelena)
    R.provera("25 l" not in k["Idea"]["pakovanja"] and "25 l" not in k["Humovit"]["pakovanja"], "isključeno pakovanje 25 l nestaje kod svih artikala")
    adm.post("/admin/pakovanja.php", {"akcija": "status", "id": pid25})
    k = katalog(jelena)
    R.provera("25 l" in k["Idea"]["pakovanja"] and "25 l" in k["Humovit"]["pakovanja"], "uključeno ponovo – vraćeno sa starim podešavanjima")
    sacuvaj_pakovanja(idea_id, ["5 l", "10 l", "20 l", "25 l"])

    # ═════════════ POPIS ═════════════
    R.odeljak("Popis – korekcija stanja")
    sql("DELETE FROM unosi")
    hum5, hum25, prem20 = sku_id("Humovit", 5), sku_id("Humovit", 25), sku_id("Humovit premium", 20)
    unos(J, 540, 0)         # Humovit 10 l
    sql("UPDATE unosi SET sku_id=%d WHERE kolicina=540" % hum10)
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 35, %d, NOW(), NOW())" % (prem20, J))
    o = adm.get("/admin/popis.php")
    R.provera(o.status == 200 and "Prebrojano stanje" in o.text and "Napomena (obavezna)" in o.text, "ekran Popis se otvara")
    R.provera(len(re.findall(r'name="prebrojano\[\d+\]"', o.text)) == sql_int("SELECT COUNT(*) FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id WHERE s.aktivan=1 AND a.aktivan=1 AND p.aktivan=1 AND (s.varijanta_id=0 OR s.varijanta_id IN (SELECT id FROM varijante WHERE aktivan=1))"), "polje za svaki aktivan artikal i pakovanje", len(re.findall(r'name="prebrojano\[\d+\]"', o.text)))
    R.provera("sada: <strong>540</strong>" in o.text.replace("\n", ""), "uz svako polje piše trenutno stanje")

    def popis(napomena, **vrednosti):
        adm.get("/admin/popis.php")
        podaci = {"napomena": napomena}
        for k, v in vrednosti.items():
            podaci["prebrojano[%s]" % k] = v
        return adm.post("/admin/popis.php", podaci)

    o = popis("Popis 30.09.", **{str(hum10): "520"})
    R.provera("Popis je sačuvan" in poruke(o) and "540 → 520" in poruke(o) and "−20" in poruke(o), "popis: 540 → 520 (−20)", poruke(o))
    R.provera(stanje(hum10) == 520, "stanje je sada 520")
    red = sql("SELECT tip, kolicina, korisnik_id, napomena FROM unosi WHERE sku_id=%d AND tip='korekcija'" % hum10).split("\t")
    R.provera(red[0] == "korekcija" and red[1] == "-20" and red[2] == str(admin_id) and red[3].startswith("Popis 30.09.") and "bilo 540" in red[3] and "prebrojano 520" in red[3], "upisana korekcija −20 sa napomenom i podacima 'bilo/prebrojano'", red)
    o = popis("Popis 30.09.", **{str(hum10): "520"})
    R.provera("ništa nije menjano" in poruke(o) and sql_int("SELECT COUNT(*) FROM unosi WHERE tip='korekcija'") == 1, "isto stanje – ništa se ne upisuje")
    o = popis("Oštećene vreće", **{str(hum10): "530", str(prem20): "30", str(hum5): ""})
    R.provera(stanje(hum10) == 530 and stanje(prem20) == 30 and stanje(hum5) == 0 and sql_int("SELECT COUNT(*) FROM unosi WHERE tip='korekcija'") == 3, "više artikala odjednom; prazno polje se preskače")
    o = popis("Nula", **{str(prem20): "0"})
    R.provera(stanje(prem20) == 0, "može da se postavi stanje 0")
    o = popis("Nov artikal", **{str(hum5): "12"})
    R.provera(stanje(hum5) == 12, "može da se podigne stanje artikla koji je imao 0")
    for napomena, vr, deo in [("", "5", "Upišite napomenu"), ("ab", "5", "Upišite napomenu"), ("Popis", "-5", "ceo broj"), ("Popis", "abc", "ceo broj"), ("Popis", "1.5", "ceo broj"), ("Popis", "", "nijedno")]:
        pre = sql_int("SELECT COUNT(*) FROM unosi")
        o = popis(napomena, **{str(hum10): vr})
        R.provera(deo in poruke(o) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "odbijeno: napomena=%r vrednost=%r" % (napomena, vr), poruke(o))
    # stanje se promenilo dok je admin gledao stranicu: ishod je ipak tačno prebrojano
    adm.get("/admin/popis.php")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 100, %d, NOW(), NOW())" % (hum10, J))
    adm.post("/admin/popis.php", {"napomena": "Trka", "prebrojano[%d]" % hum10: "400"})
    R.provera(stanje(hum10) == 400, "ako se stanje promenilo u međuvremenu, posle popisa je tačno prebrojano (400)", stanje(hum10))
    o = adm.get("/admin/popis.php")
    R.provera("Poslednje korekcije" in o.text and "Trka" in o.text and "Oštećene vreće" in o.text, "lista poslednjih korekcija")
    o = adm.get("/admin/istorija.php?tip=korekcija&od=&do=")
    R.provera("Korekcija" in o.text and o.text.count('znacka-korekcija') >= 5, "korekcije su u Istoriji kao posebna vrsta")
    cm10 = sku_id("Čmana supstrat", 10)
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 12, %d, NOW(), NOW())" % (cm10, J))
    o = adm.get("/admin/popis.php")
    R.provera('name="prebrojano[%d]"' % cm10 in o.text, "isključen artikal sa stanjem se može popisati")
    o = popis("Popis", **{"99999": "5"})
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE sku_id=99999") == 0 and o.status == 200, "nepostojeći SKU se ignoriše")
    o = adm.post("/admin/popis.php", {"napomena": "Popis", "prebrojano[%d]" % hum10: "1"}, csrf=False)
    R.provera(o.status == 403 and stanje(hum10) == 400, "popis bez CSRF žetona → 403")
    R.provera("Popis" in sql("SELECT GROUP_CONCAT(detalji) FROM dnevnik WHERE objekat='podesavanje'"), "popis je u dnevniku podešavanja")
    # popis prikazan u dnevniku
    o = adm.get("/admin/dnevnik.php")
    R.provera("Popis" in o.text and "Podešavanje" in o.text, "dnevnik prikazuje i popis i podešavanja")

    # ═════════════ ŠIFRA ═════════════
    R.odeljak("Promena šifre")
    a2 = prijavi_admina(site)             # drugi uređaj
    def sifra(trenutna, nova, potvrda):
        adm.get("/admin/sifra.php")
        return adm.post("/admin/sifra.php", {"trenutna": trenutna, "nova": nova, "potvrda": potvrda})
    NOVA = "Nova-Tajna-2026"
    for tr, no, po, deo in [("pogresna", NOVA, NOVA, "nije tačna"), (ADMIN_PASS, "kratka", "kratka", "najmanje 8"), (ADMIN_PASS, NOVA, NOVA + "x", "ne poklapaju"),
                            (ADMIN_PASS, ADMIN_PASS, ADMIN_PASS, "drugačija")]:
        o = sifra(tr, no, po)
        R.provera(deo in poruke(o), "odbijeno: " + deo, poruke(o))
    sql("UPDATE korisnici SET korisnicko_ime='vlasnik-admin' WHERE uloga='admin'")
    o = sifra(ADMIN_PASS, "Vlasnik-Admin", "Vlasnik-Admin")
    R.provera("isto što i korisničko ime" in poruke(o), "odbijeno: šifra jednaka korisničkom imenu", poruke(o))
    sql("UPDATE korisnici SET korisnicko_ime='%s' WHERE uloga='admin'" % ADMIN_USER)
    o = sifra(ADMIN_PASS, NOVA, NOVA)
    R.provera("Šifra je promenjena" in poruke(o), "šifra je promenjena", poruke(o))
    R.provera(adm.get("/admin/stanje.php", slediti=False).status == 200, "trenutni uređaj ostaje prijavljen")
    R.provera(a2.get("/admin/stanje.php", slediti=False).status == 302, "ostali uređaji su odjavljeni")
    c = Client(site.base); c.get("/login.php?admin=1")
    o = c.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS})
    R.provera("Pogrešno korisničko ime ili šifra" in o.text, "stara šifra više ne radi")
    sql("DELETE FROM neuspele_prijave")
    c = Client(site.base); c.get("/login.php?admin=1")
    o = c.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": NOVA}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/admin/stanje.php"), "nova šifra radi")
    R.provera(NOVA not in sql("SELECT hes FROM korisnici WHERE uloga='admin'") and sql("SELECT hes FROM korisnici WHERE uloga='admin'").startswith("$2y$"), "nova šifra je sačuvana kao heš")
    R.provera("Promenjena administratorska šifra" in sql("SELECT GROUP_CONCAT(detalji) FROM dnevnik") and NOVA not in sql("SELECT GROUP_CONCAT(detalji) FROM dnevnik"), "promena šifre je u dnevniku (bez same šifre)")
    adm = c
    # 5 pogrešnih pokušaja u nizu odjavljuje
    for i in range(5):
        adm.get("/admin/sifra.php")
        adm.post("/admin/sifra.php", {"trenutna": "x" + str(i), "nova": "Neka-Druga-1234", "potvrda": "Neka-Druga-1234"})
    o = adm.get("/admin/stanje.php", slediti=False)
    R.provera(o.status == 302, "posle 5 pogrešnih unosa trenutne šifre administrator se odjavljuje")

    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
