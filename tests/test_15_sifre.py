#!/usr/bin/env python3
"""Kratke šifre artikala (H50L = Humovit 50 l): predlog pri instalaciji i nadogradnji, izmena u Podešavanjima,
nove varijante i pakovanja dobijaju šifru, unos na spisku kucanjem šifre ili naziva (h50 → H50L, h5 → H5L),
zbirna paleta odjednom (BRS 1/2 + BO46 pola, 3 pak), čuvanje upisanog kad unos ne uspe, prikaz šifre na spisku,
kod radnika, na štampi i u tekstu za deljenje."""
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
    return sql("SELECT GROUP_CONCAT(s.sifra ORDER BY s.varijanta_id, p.jedinica, p.kolicina) FROM sku s JOIN artikli a ON a.id=s.artikal_id "
               "JOIN pakovanja p ON p.id=s.pakovanje_id WHERE a.naziv='%s'" % naziv)


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum5, hum10, hum25, hum50 = (sku_id("Humovit", k) for k in (5, 10, 25, 50))
    rizla = sku_id("Bela rizla", 20, "kg", "Sitnija")
    bo46, bo47, bo13 = (sku_id("Beli oblutak", 20, "kg", g) for g in ("4-6 cm", "4-7 cm (krupnija)", "1-3 cm"))
    perlit5 = sku_id("Perlit", 5)

    R.odeljak("Šifre pri instalaciji")
    R.provera(sifre_artikla("Humovit") == "H5L,H10L,H25L,H50L", "Humovit: H5L, H10L, H25L, H50L", sifre_artikla("Humovit"))
    R.provera(sifre_artikla("Humovit premium") == "HP20L,HP50L", "Humovit premium: HP20L, HP50L")
    R.provera(sifre_artikla("Beli oblutak") == "BO13,BO24,BO46,BO47,BO610", "beli oblutak po granulaciji: BO13 … BO610 (jedno pakovanje, bez 20KG)", sifre_artikla("Beli oblutak"))
    R.provera(sifre_artikla("Bela rizla") == "BRS,BRK" and sifre_artikla("Crna rizla") == "CRNRS,CRNRK" and sifre_artikla("Crvena rizla") == "CRVRS,CRVRK",
              "rizla: BRS/BRK, crna CRNRS, crvena CRVRS (isti početak se produži)", (sifre_artikla("Crna rizla"), sifre_artikla("Crvena rizla")))
    R.provera(sifre_artikla("Malč Farmerkop").split(",")[:3] == ["MFCRV", "MFB", "MFZU"], "malč po boji: crveni MFCRV, braon MFB, žuti MFZU", sifre_artikla("Malč Farmerkop"))
    R.provera(sifre_artikla("Perlit") == "P5L,P100L" and sifre_artikla("Keramzit") == "K5L,K35L", "perlit P5L/P100L, keramzit K5L/K35L")
    R.provera(sql_int("SELECT COUNT(*) FROM sku WHERE sifra IS NULL") == 0 and sql_int("SELECT COUNT(DISTINCT sifra) FROM sku") == sql_int("SELECT COUNT(*) FROM sku"),
              "svaki artikal ima šifru i nijedna se ne ponavlja")

    R.odeljak("Nadogradnja: šifre se dodaju postojećim artiklima")
    sql("ALTER TABLE sku DROP INDEX uq_sku_sifra, DROP COLUMN sifra; UPDATE podesavanja SET vrednost='6' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "7" and sifre_artikla("Humovit") == "H5L,H10L,H25L,H50L"
              and sql_int("SELECT COUNT(*) FROM sku WHERE sifra IS NULL") == 0, "posle nadogradnje svi artikli imaju iste predložene šifre")

    R.odeljak("Izmena šifara u Podešavanjima")
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    oblutak_id = sql_int("SELECT id FROM artikli WHERE naziv='Beli oblutak'")
    o = adm.get("/admin/artikal.php?id=%d" % hum_id)
    R.provera('id="sifre"' in o.text and 'name="sifra[%d]"' % hum50 in o.text and 'value="H50L"' in o.text, "artikal ima deo „Šifre za spisak“")
    A = "/admin/artikal.php"
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum50: " h 50 ", "sifra[%d]" % hum25: "H25L"})
    R.provera("Šifra je sačuvana" in poruke(o) and sifra(hum50) == "H50", "„ h 50 “ se čuva kao H50 (velika slova, bez razmaka)", poruke(o))
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum5: "H10L", "sifra[%d]" % hum10: "H5L"})
    R.provera("Šifre su sačuvane" in poruke(o) and (sifra(hum5), sifra(hum10)) == ("H10L", "H5L"), "zamena dve šifre u jednom čuvanju (H5L ↔ H10L)", poruke(o))
    adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum5: "H5L", "sifra[%d]" % hum10: "H10L", "sifra[%d]" % hum50: "H50L"})
    R.provera((sifra(hum5), sifra(hum10), sifra(hum50)) == ("H5L", "H10L", "H50L"), "i nazad")
    for polja, poruka, opis in [
        ({"sifra[%d]" % hum50: "BO46"}, "već zauzeta (Beli oblutak · 4-6 cm · 20 kg)", "šifra drugog artikla"),
        ({"sifra[%d]" % hum50: "H5L"}, "već zauzeta", "šifra drugog pakovanja koje se ne menja"),
        ({"sifra[%d]" % hum50: "X1", "sifra[%d]" % hum25: "x1"}, "upisana dva puta", "ista šifra dva puta"),
        ({"sifra[%d]" % hum50: "  "}, "ne može biti prazna", "prazna šifra"),
        ({"sifra[%d]" % hum50: "ABCDEFGHIJKLMNOPQ"}, "do 16 slova", "predugačka šifra"),
    ]:
        o = adm.post(A, dict({"akcija": "sifre", "id": hum_id}, **polja))
        R.provera(poruka in poruke(o) and sifra(hum50) == "H50L" and sifra(hum25) == "H25L", "odbijeno: " + opis, poruke(o))
    o = adm.post(A, {"akcija": "sifre", "id": hum_id, "sifra[%d]" % hum50: "H50L"})
    R.provera("Nema izmena" in poruke(o), "bez izmena: „Nema izmena“")
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Izmenjene šifre artikla Humovit%'") >= 2, "izmena šifara je u dnevniku")

    # novo pakovanje i nova boja dobijaju šifru same
    pak20 = sql_int("SELECT id FROM pakovanja WHERE kolicina=20 AND jedinica='l'")
    paks = sql("SELECT GROUP_CONCAT(DISTINCT pakovanje_id) FROM sku WHERE artikal_id=%d AND aktivan=1" % hum_id).split(",")
    adm.post(A, {"akcija": "pakovanja", "id": hum_id, "pak[]": paks + [str(pak20)]})
    R.provera(sql("SELECT sifra FROM sku WHERE artikal_id=%d AND pakovanje_id=%d" % (hum_id, pak20)) == "H20L", "novo pakovanje Humovita 20 l dobija šifru H20L")
    malc_id = sql_int("SELECT id FROM artikli WHERE naziv='Malč Farmerkop'")
    adm.post(A, {"akcija": "var_nova", "id": malc_id, "naziv": "Plavi"})
    R.provera(sql("SELECT s.sifra FROM sku s JOIN varijante v ON v.id=s.varijanta_id WHERE v.naziv='Plavi' AND v.artikal_id=%d" % malc_id) == "MFP", "nova boja malča Plavi dobija šifru MFP")

    R.odeljak("Unos na spisku: šifra ili naziv")
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "Agrocentar", "datum": danas_bg(), "mesto_broj": "8"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m8 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('<option value="H50L">Humovit · 50 l</option>' in o.text and 'list="lista-sku"' in o.text, "lista šifara za kucanje (H50L – Humovit · 50 l)")
    pod = json.loads(htmllib.unescape(re.search(r'<script type="application/json" id="sku-podaci">(.*?)</script>', o.text, re.S).group(1)))
    R.provera(["H50L", "Humovit · 50 l", hum50, 40, 0, 0] in pod and all(len(r) == 6 for r in pod), "podaci za prepoznavanje šifre (sa stanjem za administratora)")
    for unos, sku, opis in [("h50l", hum50, "tačna šifra malim slovima"), ("h50", hum50, "početak šifre (h50 → H50L)"), ("h5", hum5, "h5 → H5L, a ne H50L"),
                            ("Humovit 25", hum25, "naziv"), ("rizla bela sit", rizla, "reči naziva u bilo kom redu"), ("bo 4-6", bo46, "BO 4-6 → BO46")]:
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": unos, "kom": "1"})
        R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp, sku)) == 1, "„%s“: %s" % (unos, opis), poruke(o))
    R.provera("Dodato za Mesto 8 · Agrocentar: BO46 (Beli oblutak · 4-6 cm · 20 kg) – 1 kom" in poruke(o), "poruka potvrđuje šifru i pun naziv", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "bo4", "kom": "1"})
    R.provera("nije jasno" in poruke(o) and "BO46, BO47" in poruke(o), "„bo4“: nije jasno – nudi BO46, BO47", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "qqq9", "kom": "5"})
    R.provera("Nema artikla sa šifrom ili nazivom „qqq9“" in poruke(o), "nepoznata šifra: odbijeno", poruke(o))
    R.provera(o.url.endswith("&m=%d#dodaj-%d" % (m8, m8)) and 'value="qqq9"' in o.text and re.search(r'name="kom"[^>]*value="5"', o.text), "upisano ostaje u formi posle greške", o.url)
    R.provera(re.search(r'id="dodaj-%d" open' % m8, o.text) is not None, "forma ostaje otvorena")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('value="qqq9"' not in o.text, "upisano se pamti samo za jedno otvaranje")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "CS10L", "kom": "1"})
    R.provera("je isključen" in poruke(o), "šifra isključenog artikla (Cmana): odbijeno", poruke(o))

    R.odeljak("Zbirna paleta odjednom")
    stavki = sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp)
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "p_naziv": "",
                      "z_artikal[]": ["BRS", "bo46", "", ""], "z_kol[]": ["1/2", "pola", "", ""]})
    pid = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera("Napravljena zbirna paleta „Zbirna paleta 1“ za Mesto 8 · Agrocentar: BRS 25 + BO46 25 kom" in poruke(o), "pola rizle i pola oblutka u jednom koraku", poruke(o))
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
        (["BRS", "qqq9"], ["1/2", "5"], "Nema artikla", "nepoznata šifra u redu"),
        (["P5L"], ["1/2"], "nije podešeno koliko komada ide na paletu", "deo palete za artikal bez palete"),
        (["BRS"], ["2/1"], "1/2, 1/3 ili 3/4", "više od cele palete"),
        (["BRS"], ["abc"], "upišite kao broj komada", "nerazumljiva količina"),
        (["H50L"], ["2 pak"], "ne pakuje u pakete", "paketi za artikal bez paketa"),
        (["", ""], ["", ""], "Upišite bar jedan artikal", "prazna forma"),
        (["", "BRS"], ["5", "1"], "Upišite šifru artikla uz količinu „5“", "količina bez šifre"),
        (["H%dL" % k for k in (5, 10, 25, 50)] * 6, ["1"] * 24, "najviše 20 artikala", "više od 20 redova"),
    ]:
        o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "z_artikal[]": redovi, "z_kol[]": kol})
        R.provera(poruka in poruke(o) and sql_int("SELECT MAX(id) FROM spisak_palete") == pid2, "odbijeno (ništa nije napravljeno): " + opis, poruke(o))
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "0", "z_artikal[]": ["BRS"], "z_kol[]": ["5"]})
    R.provera("od 1 do 999" in poruke(o), "0 ovakvih paleta: odbijeno")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "3", "p_naziv": "Moja", "z_artikal[]": ["BRS", "xx77"], "z_kol[]": ["1/2", "4"]})
    R.provera(o.url.endswith("&m=%d&z=1#zbirna-%d" % (m8, m8)) and re.search(r'id="zbirna-%d" open' % m8, o.text) and 'value="xx77"' in o.text and 'value="1/2"' in o.text
              and re.search(r'name="p_broj"[^>]*value="3"', o.text) and 'value="Moja"' in o.text, "posle greške zbirna forma ostaje otvorena sa upisanim", o.url)
    R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp) == stavki + 4, "greške nisu upisale ništa")

    R.odeljak("Prikaz: šifra na spisku, pun naziv sitno")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera(re.search(r'<strong class="stavka-sifra">H50L</strong>', o.text) and re.search(r'<span class="stavka-ime">Humovit · 50 l', o.text), "spisak: H50L, a ispod sitno Humovit · 50 l")
    R.provera(o.text.count('<details class="stavka-uredi">') == sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d" % sp), "svaka stavka se otvara dodirom za izmenu")
    R.provera('<details class="zbirna-uredi">' in o.text and "Mešovita (2 palete)" in tekst(o.text), "zbirna paleta: naziv i broj paleta, izmena na dodir")
    adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    R.provera(re.search(r'<strong>H50L <small class="utovar-ime">Humovit · 50 l</small></strong>', o.text), "radnik: šifra i pun naziv sitno")
    pod = json.loads(htmllib.unescape(re.search(r'<script type="application/json" id="sku-podaci">(.*?)</script>', o.text, re.S).group(1)))
    R.provera(all(len(r) == 5 for r in pod) and ["H50L", "Humovit · 50 l", hum50, 40, 0] in pod, "radnik ne dobija stanje u podacima za šifre")
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "bo13", "novi_kom": "4"})
    R.provera("Dodato na spisak za Mesto 8 · Agrocentar: BO13 (Beli oblutak · 1-3 cm · 20 kg) – 4 kom" in poruke(o), "radnik dodaje artikal kucanjem šifre", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "bo4", "novi_kom": "4"})
    R.provera("nije jasno" in poruke(o), "radnik: nejasna šifra se odbija", poruke(o))
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp)
    R.provera(re.search(r'<strong>H50L</strong> <small class="stampa-ime">Humovit · 50 l</small>', o.text), "štampa: šifra i pun naziv")
    m = re.search(r'href="https://wa\.me/\?text=([^"]+)"', o.text)
    deljenje = urllib.parse.unquote(htmllib.unescape(m.group(1))) if m else ""
    R.provera("H50L – 1 kom" in deljenje and "     1. BRS – 25 kom" not in deljenje and "BRS – 25 kom" in deljenje and "Humovit · 50 l" not in deljenje,
              "tekst za Viber/WhatsApp: samo šifre", deljenje[:600])

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
