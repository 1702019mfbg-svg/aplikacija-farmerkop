#!/usr/bin/env python3
"""Kako artikal piše na spisku: samo Humovit ima šifru (H5L … H50L), ostali artikli pišu se imenom (B oblutak 4/6,
B rizla sitnija, Idea 10 l). Predlog pri instalaciji i nadogradnji (v7 → v8: stare šifre BO46, BRS postaju imena,
ručno upisano ostaje), izmena u Podešavanjima, nove varijante i pakovanja dobijaju ime, unos kucanjem (h50 → H50L,
"oblutak beli 40-60", "rizla bela sitna"), zbirna paleta odjednom (1/2, pola, 3 pak), čuvanje upisanog kad unos ne
uspe, prikaz na spisku, kod radnika, na štampi i u tekstu za deljenje."""
import html as htmllib
import json
import re
import sys
import urllib.parse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
SP = "/admin/spisak.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def sifra(sku):
    return sql("SELECT sifra FROM sku WHERE id=%d" % sku)


def sifre_artikla(naziv):
    return sql("SELECT GROUP_CONCAT(s.sifra ORDER BY s.varijanta_id, p.jedinica, p.kolicina SEPARATOR '|') FROM sku s JOIN artikli a ON a.id=s.artikal_id "
               "JOIN pakovanja p ON p.id=s.pakovanje_id WHERE a.naziv='%s'" % naziv)


def verzija():
    return sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum5, hum10, hum25, hum50 = (sku_id("Humovit", k) for k in (5, 10, 25, 50))
    rizla = sku_id("Bela rizla", 20, "kg", "Sitnija")
    bo46, bo47, bo13 = (sku_id("Beli oblutak", 20, "kg", g) for g in ("4-6 cm", "4-7 cm (krupnija)", "1-3 cm"))
    perlit5 = sku_id("Perlit", 5)
    malc_c = sku_id("Malč Farmerkop", 50, "l", "Crveni")

    R.odeljak("Kako piše na spisku – predlog pri instalaciji")
    R.provera(sifre_artikla("Humovit") == "H5L|H10L|H25L|H50L", "samo Humovit ima šifru: H5L, H10L, H25L, H50L", sifre_artikla("Humovit"))
    R.provera(sifre_artikla("Beli oblutak") == "B oblutak 1/3|B oblutak 2/4|B oblutak 4/6|B oblutak 4/7|B oblutak 6/10",
              "beli oblutak: B oblutak 4/6 (kao na papiru), ne BO46", sifre_artikla("Beli oblutak"))
    R.provera(sifre_artikla("Crni oblutak").split("|")[2] == "Crni oblutak 4/6" and sifre_artikla("Crveni oblutak").split("|")[2] == "Crveni oblutak 4/6",
              "crni i crveni oblutak ostaju celim imenom (oba počinju sa C)")
    R.provera(sifre_artikla("Bela rizla") == "B rizla sitnija|B rizla krupnija" and sifre_artikla("Crna rizla") == "Crna rizla sitnija|Crna rizla krupnija",
              "rizla: B rizla sitnija, Crna rizla sitnija …", (sifre_artikla("Bela rizla"), sifre_artikla("Crna rizla")))
    R.provera(sifre_artikla("Humovit premium") == "Humovit premium 20 l|Humovit premium 50 l" and sifre_artikla("Idea") == "Idea 5 l|Idea 10 l|Idea 20 l|Idea 25 l",
              "ostali imenom i pakovanjem: Humovit premium 20 l, Idea 10 l")
    R.provera(sifre_artikla("Malč Farmerkop").split("|")[:3] == ["Malč Farmerkop crveni", "Malč Farmerkop braon", "Malč Farmerkop žuti"],
              "malč imenom i bojom (jedno pakovanje, bez 50 l)", sifre_artikla("Malč Farmerkop"))
    R.provera(sifre_artikla("Perlit") == "Perlit 5 l|Perlit 100 l" and sifre_artikla("Keramzit") == "Keramzit 5 l|Keramzit 35 l", "Perlit 5 l, Keramzit 35 l")
    R.provera(sql_int("SELECT COUNT(*) FROM sku WHERE sifra IS NULL") == 0 and sql_int("SELECT COUNT(DISTINCT sifra) FROM sku") == sql_int("SELECT COUNT(*) FROM sku"),
              "svaki artikal ima ime za spisak i nijedno se ne ponavlja")
    R.provera(sql_int("SELECT COUNT(*) FROM sku WHERE sifra REGEXP '^[A-Z]{2}[0-9]+$'") == 0, "nema šifri tipa BO46")

    R.odeljak("Nadogradnja sa verzije 6: kolona i predlog")
    sql("ALTER TABLE sku DROP INDEX uq_sku_sifra, DROP COLUMN sifra; UPDATE podesavanja SET vrednost='6' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "8" and sifre_artikla("Humovit") == "H5L|H10L|H25L|H50L" and sifra(bo46) == "B oblutak 4/6"
              and sql_int("SELECT COUNT(*) FROM sku WHERE sifra IS NULL") == 0, "posle nadogradnje: H50L i B oblutak 4/6")

    R.odeljak("Nadogradnja sa verzije 7: šifre BO46, BRS postaju imena, ručno upisano ostaje")
    # baza kakva je bila u verziji 7: kratke šifre velikim slovima (BO46, BRS, MFCRV …), polje od 20 znakova
    sql("UPDATE sku s JOIN artikli a ON a.id=s.artikal_id SET s.sifra=CONCAT('X', s.id) WHERE a.naziv <> 'Humovit'; "
        "ALTER TABLE sku MODIFY sifra VARCHAR(20) NULL DEFAULT NULL; "
        "UPDATE sku SET sifra='BO46' WHERE id=%d; UPDATE sku SET sifra='BO13' WHERE id=%d; UPDATE sku SET sifra='BRS' WHERE id=%d; "
        "UPDATE sku SET sifra='MFCRV' WHERE id=%d; UPDATE sku SET sifra='Moj perlit' WHERE id=%d; "
        "UPDATE podesavanja SET vrednost='7' WHERE kljuc='schema_verzija'" % (bo46, bo13, rizla, malc_c, perlit5))
    Client(site.base).get("/login.php")
    R.provera(verzija() == "8" and site.log().count("nadogradnja baze na verziju 8") == 2, "nadogradnja 8 se izvršila")
    R.provera((sifra(bo46), sifra(bo13), sifra(rizla), sifra(malc_c)) == ("B oblutak 4/6", "B oblutak 1/3", "B rizla sitnija", "Malč Farmerkop crveni"),
              "BO46 → B oblutak 4/6, BRS → B rizla sitnija, MFCRV → Malč Farmerkop crveni", (sifra(bo46), sifra(rizla), sifra(malc_c)))
    R.provera(sifra(perlit5) == "Moj perlit" and sifra(hum50) == "H50L", "ručno upisano (Moj perlit) i Humovit (H50L) ostaju")
    R.provera(sql_int("SELECT COUNT(*) FROM sku WHERE sifra REGEXP '^X[0-9]+$'") == 0 and sifra(sku_id("Crni oblutak", 20, "kg", "4-6 cm")) == "Crni oblutak 4/6",
              "i sve ostale šifre iz verzije 7 su postale imena")
    R.provera(sql("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema='%s' AND table_name='sku' AND column_name='sifra'" % DB_NAME) == "40",
              "polje je dovoljno dugo za imena (40 znakova)")
    sql("UPDATE sku SET sifra='Perlit 5 l' WHERE id=%d" % perlit5)

    R.odeljak("Izmena u Podešavanjima („Kako piše na spisku“)")
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    A = "/admin/artikal.php"
    o = adm.get(A + "?id=%d" % hum_id)
    R.provera('id="sifre"' in o.text and "Kako piše na spisku" in o.text and 'name="sifra[%d]"' % hum50 in o.text and 'value="H50L"' in o.text,
              "artikal ima deo „Kako piše na spisku“")
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum50: "  Humovit   50 ", "sifra[%d]" % hum25: "H25L"})
    R.provera("Sačuvano" in poruke(o) and sifra(hum50) == "Humovit 50", "upisano se čuva bez suvišnih razmaka („Humovit 50“)", poruke(o))
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum5: "H10L", "sifra[%d]" % hum10: "H5L"})
    R.provera("Sačuvano" in poruke(o) and (sifra(hum5), sifra(hum10)) == ("H10L", "H5L"), "zamena dva imena u jednom čuvanju (H5L ↔ H10L)", poruke(o))
    adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum5: "H5L", "sifra[%d]" % hum10: "H10L", "sifra[%d]" % hum50: "H50L"})
    R.provera((sifra(hum5), sifra(hum10), sifra(hum50)) == ("H5L", "H10L", "H50L"), "i nazad")
    for polja, poruka, opis in [
        ({"sifra[%d]" % hum50: "B oblutak 4/6"}, "već piše kod drugog artikla (Beli oblutak · 4-6 cm · 20 kg)", "ime drugog artikla"),
        ({"sifra[%d]" % hum50: "b oblutak 4-6"}, "već piše kod drugog artikla", "isto, drugačije napisano"),
        ({"sifra[%d]" % hum50: "H5L"}, "već piše kod drugog artikla", "ime drugog pakovanja koje se ne menja"),
        ({"sifra[%d]" % hum50: "X1", "sifra[%d]" % hum25: "x 1"}, "upisano dva puta", "isto dva puta"),
        ({"sifra[%d]" % hum50: "  "}, "ne može biti prazno", "prazno"),
        ({"sifra[%d]" % hum50: "H" * 41}, "do 40 znakova", "predugačko"),
        ({"sifra[%d]" % hum50: "H50L!"}, "do 40 znakova", "nedozvoljen znak"),
    ]:
        o = adm.post(A, dict({"akcija": "sifre", "id": hum_id}, **polja))
        R.provera(poruka in poruke(o) and sifra(hum50) == "H50L" and sifra(hum25) == "H25L", "odbijeno: " + opis, poruke(o))
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum50: "H50L"})
    R.provera("Nema izmena" in poruke(o), "bez izmena: „Nema izmena“")
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Izmenjeno kako piše na spisku, artikal Humovit%'") >= 2, "izmena je u dnevniku")

    # novo pakovanje i nova boja dobijaju ime same
    pak20 = sql_int("SELECT id FROM pakovanja WHERE kolicina=20 AND jedinica='l'")
    paks = sql("SELECT GROUP_CONCAT(DISTINCT pakovanje_id) FROM sku WHERE artikal_id=%d AND aktivan=1" % hum_id).split(",")
    adm.post(A, {"akcija": "pakovanja", "id": hum_id, "pak[]": paks + [str(pak20)]})
    R.provera(sql("SELECT sifra FROM sku WHERE artikal_id=%d AND pakovanje_id=%d" % (hum_id, pak20)) == "H20L", "novo pakovanje Humovita 20 l dobija šifru H20L")
    malc_id = sql_int("SELECT id FROM artikli WHERE naziv='Malč Farmerkop'")
    adm.post(A, {"akcija": "var_nova", "id": malc_id, "naziv": "Plavi"})
    R.provera(sql("SELECT s.sifra FROM sku s JOIN varijante v ON v.id=s.varijanta_id WHERE v.naziv='Plavi' AND v.artikal_id=%d" % malc_id) == "Malč Farmerkop plavi",
              "nova boja malča dobija ime „Malč Farmerkop plavi“")

    R.odeljak("Unos na spisku: kako piše, naziv ili kao na papiru")
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "Agrocentar", "datum": danas_bg(), "mesto_broj": "8"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m8 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('<option value="H50L">Humovit · 50 l</option>' in o.text and '<option value="B oblutak 4/6">Beli oblutak · 4-6 cm · 20 kg</option>' in o.text,
              "lista za kucanje: H50L, B oblutak 4/6 …")
    pod = json.loads(htmllib.unescape(re.search(r'<script type="application/json" id="sku-podaci">(.*?)</script>', o.text, re.S).group(1)))
    R.provera(["H50L", "Humovit · 50 l", hum50, 40, 0, 0] in pod and all(len(r) == 6 for r in pod), "podaci za prepoznavanje (sa stanjem za administratora)")
    for unos, sku, opis in [("h50l", hum50, "šifra malim slovima"), ("h50", hum50, "početak šifre (h50 → H50L)"), ("h5", hum5, "h5 → H5L, a ne H50L"),
                            ("Humovit 25", hum25, "naziv"), ("b oblutak 4/6", bo46, "kako piše na spisku"),
                            ("oblutak beli 40-60", bo46, "kao na papiru: oblutak beli 40-60 (milimetri)"),
                            ("rizla bela sitna", rizla, "kao na papiru: rizla bela sitna"), ("oblutak beli 1-3", bo13, "reči u bilo kom redu")]:
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": unos, "kom": "1"})
        R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp, sku)) == 1, "„%s“: %s" % (unos, opis), poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "oblutak beli 4-7", "kom": "2"})
    R.provera("Dodato za Mesto 8 · Agrocentar: B oblutak 4/7 – 2 kom" in poruke(o), "poruka: ime kako piše na spisku", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "h25", "kom": "2"})
    R.provera("H25L (Humovit · 25 l)" in poruke(o), "uz šifru Humovita poruka piše i pun naziv", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "bo46", "kom": "1"})
    R.provera("Nema artikla „bo46“" in poruke(o), "„bo46“ se više ne prepoznaje (nema takvih šifri)", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "b oblutak 4", "kom": "1"})
    R.provera("nije jasno" in poruke(o) and "B oblutak 4/6, B oblutak 4/7" in poruke(o), "„b oblutak 4“: nije jasno – nudi 4/6 i 4/7", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "qqq9", "kom": "5"})
    R.provera("Nema artikla „qqq9“" in poruke(o), "nepoznato: odbijeno", poruke(o))
    R.provera(o.url.endswith("&m=%d#dodaj-%d" % (m8, m8)) and 'value="qqq9"' in o.text and re.search(r'name="kom"[^>]*value="5"', o.text), "upisano ostaje u formi posle greške", o.url)
    R.provera(re.search(r'id="dodaj-%d" open' % m8, o.text) is not None, "forma ostaje otvorena")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('value="qqq9"' not in o.text, "upisano se pamti samo za jedno otvaranje")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "Cmana supstrat 10 l", "kom": "1"})
    R.provera("je isključen" in poruke(o), "isključen artikal (Cmana): odbijeno", poruke(o))

    R.odeljak("Zbirna paleta odjednom")
    stavki = sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp)
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "p_naziv": "",
                      "z_artikal[]": ["rizla bela sitna", "oblutak beli 40-60", "", ""], "z_kol[]": ["1/2", "pola", "", ""]})
    pid = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera("Napravljena zbirna paleta „Zbirna paleta 1“ za Mesto 8 · Agrocentar: B rizla sitnija 25 + B oblutak 4/6 25 kom" in poruke(o),
              "kao na papiru: 1/2 rizla bela sitna + 1/2 oblutak beli 40-60", poruke(o))
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina, ':', paketi, ':', komadi) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid) == "%d:25:0:25,%d:25:0:25" % (rizla, bo46),
              "na paleti: 25 rizle + 25 oblutka (1/2 od 50 po paleti)")
    R.provera(o.url.endswith("&m=%d#paleta-%d" % (m8, pid)), "posle pravljenja stranica pokazuje novu paletu", o.url)
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "2", "p_naziv": "Mešovita",
                      "z_artikal[]": ["H5L", "h10l", "H10L"], "z_kol[]": ["3 pak", "5", "7"]})
    pid2 = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera(sql("SELECT naziv, broj FROM spisak_palete WHERE id=%d" % pid2) == "Mešovita\t2"
              and sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina, ':', paketi, ':', komadi) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid2) == "%d:60:3:0,%d:24:0:12" % (hum5, hum10),
              "2 ovakve palete: 3 paketa H5L (30 × 2 = 60), isti artikal u dva reda se sabira (5 + 7 = 12 × 2 = 24)", poruke(o))
    R.provera("na svakoj" in poruke(o), "poruka kaže da je to na svakoj paleti", poruke(o))
    for redovi, kol, poruka, opis in [
        (["B rizla sitnija", "qqq9"], ["1/2", "5"], "Nema artikla", "nepoznat artikal u redu"),
        (["Perlit 5 l"], ["1/2"], "nije podešeno koliko komada ide na paletu", "deo palete za artikal bez palete"),
        (["B rizla sitnija"], ["2/1"], "1/2, 1/3 ili 3/4", "više od cele palete"),
        (["B rizla sitnija"], ["abc"], "upišite kao broj komada", "nerazumljiva količina"),
        (["H50L"], ["2 pak"], "ne pakuje u pakete", "paketi za artikal bez paketa"),
        (["", ""], ["", ""], "Upišite bar jedan artikal", "prazna forma"),
        (["", "B rizla sitnija"], ["5", "1"], "Upišite artikal uz količinu „5“", "količina bez artikla"),
        (["H%dL" % k for k in (5, 10, 25, 50)] * 6, ["1"] * 24, "najviše 20 artikala", "više od 20 redova"),
    ]:
        o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "z_artikal[]": redovi, "z_kol[]": kol})
        R.provera(poruka in poruke(o) and sql_int("SELECT MAX(id) FROM spisak_palete") == pid2, "odbijeno (ništa nije napravljeno): " + opis, poruke(o))
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "0", "z_artikal[]": ["B rizla sitnija"], "z_kol[]": ["5"]})
    R.provera("od 1 do 999" in poruke(o), "0 ovakvih paleta: odbijeno")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "3", "p_naziv": "Moja", "z_artikal[]": ["B rizla sitnija", "xx77"], "z_kol[]": ["1/2", "4"]})
    R.provera(o.url.endswith("&m=%d&z=1#zbirna-%d" % (m8, m8)) and re.search(r'id="zbirna-%d" open' % m8, o.text) and 'value="xx77"' in o.text and 'value="1/2"' in o.text
              and re.search(r'name="p_broj"[^>]*value="3"', o.text) and 'value="Moja"' in o.text, "posle greške zbirna forma ostaje otvorena sa upisanim", o.url)
    R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp) == stavki + 4, "greške nisu upisale ništa")

    R.odeljak("Prikaz: Humovit šifrom (pun naziv sitno), ostali imenom")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera(re.search(r'<strong class="stavka-sifra">H50L</strong>', o.text) and re.search(r'<span class="stavka-ime">Humovit · 50 l', o.text), "spisak: H50L, a ispod sitno Humovit · 50 l")
    R.provera('<strong class="stavka-sifra">B oblutak 4/6</strong>' in o.text and '<span class="stavka-ime">Beli oblutak' not in o.text, "spisak: B oblutak 4/6, bez dodatnog reda")
    R.provera(o.text.count('<details class="stavka-uredi">') == sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp), "svaka stavka se otvara dodirom za izmenu")
    R.provera('<details class="zbirna-uredi">' in o.text and "Mešovita (2 palete)" in tekst(o.text), "zbirna paleta: naziv i broj paleta, izmena na dodir")
    adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    R.provera(re.search(r'<strong>H50L <small class="utovar-ime">Humovit · 50 l</small></strong>', o.text) and "<strong>B oblutak 4/6</strong>" in o.text,
              "radnik: H50L sa punim nazivom sitno, B oblutak 4/6 samo imenom")
    pod = json.loads(htmllib.unescape(re.search(r'<script type="application/json" id="sku-podaci">(.*?)</script>', o.text, re.S).group(1)))
    R.provera(all(len(r) == 5 for r in pod) and ["H50L", "Humovit · 50 l", hum50, 40, 0] in pod, "radnik ne dobija stanje u podacima za unos")
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "oblutak beli 60-100", "novi_kom": "4"})
    R.provera("Dodato na spisak za Mesto 8 · Agrocentar: B oblutak 6/10 – 4 kom" in poruke(o), "radnik dodaje kao na papiru („oblutak beli 60-100“)", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "b oblutak 4", "novi_kom": "4"})
    R.provera("nije jasno" in poruke(o), "radnik: nejasno se odbija", poruke(o))
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp)
    R.provera(re.search(r'<strong>H50L</strong> <small class="stampa-ime">Humovit · 50 l</small>', o.text) and "<strong>B oblutak 4/6</strong></td>" in o.text,
              "štampa: H50L sa punim nazivom, B oblutak 4/6 imenom")
    m = re.search(r'href="https://wa\.me/\?text=([^"]+)"', o.text)
    deljenje = urllib.parse.unquote(htmllib.unescape(m.group(1))) if m else ""
    R.provera("H50L – 1 kom" in deljenje and "B rizla sitnija – 25 kom" in deljenje and "B oblutak 4/6 – 25 kom" in deljenje and "Humovit · 50 l" not in deljenje,
              "tekst za Viber/WhatsApp: H50L, B rizla sitnija, B oblutak 4/6", deljenje[:600])

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
