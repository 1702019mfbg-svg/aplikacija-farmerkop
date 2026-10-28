#!/usr/bin/env python3
"""Nadogradnja v11: mešalice malča. Radnik upisuje koliko je mešalica ofarbao, po boji (boje malča iz kataloga, bez
neobojenog), koliko god puta dnevno – sabira se; stanje se ne menja. Briše samo svoj poslednji unos u roku od
10 minuta. Administrator vidi ko je koliko uradio (period, po boji i po danima), briše pogrešan unos (dnevnik)
i izvozi u Excel. Norma se ne postavlja."""
import html as htmllib
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
RM = "/radnik/mesalice.php"
AM = "/admin/mesalice.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def verzija():
    return sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")


def boje(o):
    return [htmllib.unescape(b) for b in re.findall(r'<input type="radio" name="boja" value="([^"]*)"', o.text)]


def mesalice(uslov="1=1"):
    """"radnik:boja:broj" redom upisa (bez obrisanih)."""
    r = sql("SELECT GROUP_CONCAT(CONCAT(k.ime, ':', m.boja, ':', m.broj) ORDER BY m.id) FROM mesalice m JOIN korisnici k ON k.id=m.korisnik_id "
            "WHERE m.obrisan=0 AND %s" % uslov)
    return "" if r == "NULL" else r


def upisi(klijent, boja, broj, kljuc=None):
    return klijent.post(RM, {"akcija": "dodaj", "boja": boja, "broj": str(broj), "kljuc": kljuc or nov_kljuc()})


try:
    ids = pripremi_sajt(site)

    R.odeljak("Nadogradnja na verziju 11")
    R.provera(verzija() == "12" and sql("SHOW TABLES LIKE 'mesalice'") == "mesalice", "nova instalacija: verzija 12, tabela mesalice")
    sql("DROP TABLE mesalice")
    sql("UPDATE podesavanja SET vrednost='10' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and sql("SHOW TABLES LIKE 'mesalice'") == "mesalice" and site.log().count("nadogradnja baze na verziju 11") == 1,
              "sa verzije 10: napravljena je tabela mesalice (nadogradnja 11), verzija 12")
    sql("UPDATE podesavanja SET vrednost='10' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and site.log().count("nadogradnja baze na verziju 11") == 2, "ponovljena nadogradnja: bez greške")

    marko = prijavi_radnika(site, ids["Marko"], "1234")
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    adm = prijavi_admina(site)
    malc = [sku_id("Malč Farmerkop", 50, "l", b) for b in ("Crveni", "Crni", "Braon")]
    stanja_pre = [stanje(s) for s in malc]
    unosa_pre = sql_int("SELECT COUNT(*) FROM unosi")

    R.odeljak("Radnik: ekran Mešalice")
    o = marko.get("/radnik/index.php")
    R.provera('href="/radnik/mesalice.php"' in o.text and ">Mešalice<" in o.text, "svaki radnik ima Mešalice u meniju")
    R.provera('href="/radnik/mesalice.php"' in jelena.get("/radnik/prodaja.php").text, "i Jelena (svi radnici)")
    o = marko.get(RM)
    R.provera(o.status == 200 and "Upiši mešalice" in o.text and 'name="broj"' in o.text and 'value="1"' in o.text and "data-mesalice-delta" in o.text,
              "forma: boja, broj (počinje od 1) i dugmad −1 / +1")
    R.provera(boje(o) == ["Crveni", "Braon", "Žuti", "Crni", "Narandžasti", "Zeleni"], "boje malča iz kataloga, redom, bez duplikata i bez neobojenog", boje(o))
    R.provera(re.search(r'value="Crveni" required[^>]*>\s*<span><i class="boja-tacka" data-boja="crven"', o.text) and 'data-boja="zut"' in o.text,
              "uz boju je tačkica te boje")
    R.provera(not re.search(r'name="boja"[^>]*checked', o.text), "prvi put: nijedna boja nije unapred izabrana")
    R.provera("Danas još nema unosa" in o.text, "danas još nema unosa")

    R.odeljak("Upis: koliko god puta, sabira se")
    o = upisi(marko, "Crveni", 3)
    R.provera("Sačuvano: Crveni – 3 mešalice." in poruke(o) and mesalice() == "Marko:Crveni:3", "3 mešalice crvenog", poruke(o))
    k = nov_kljuc()
    upisi(marko, "Crveni", 2, k)
    o = upisi(marko, "Crveni", 2, k)
    R.provera("već sačuvan" in poruke(o) and mesalice() == "Marko:Crveni:3,Marko:Crveni:2", "ista forma poslata dva puta: upisano jednom", poruke(o))
    o = upisi(marko, "Crni", 1)
    R.provera("Sačuvano: Crni – 1 mešalica." in poruke(o), "posle svake mešalice može novi unos", poruke(o))
    t = tekst(o.text)
    R.provera("6 danas mešalica" in t and "3 unosa danas" in t and "Crveni 5 · Crni 1" in t, "danas: 6 mešalica (Crveni 5, Crni 1) u 3 unosa", t[:300])
    R.provera(re.search(r'name="boja" value="Crni" required checked', o.text), "poslednja boja ostaje izabrana")
    R.provera([stanje(s) for s in malc] == stanja_pre and sql_int("SELECT COUNT(*) FROM unosi") == unosa_pre, "stanje se ne menja (nema novih unosa)")
    for boja, broj, poruka, opis in [("", 1, "Izaberi boju", "bez boje"), ("Neobojeni", 1, "Izaberi boju", "neobojeni se ne farba"),
                                     ("<b>Plava</b>", 1, "Izaberi boju", "boja koje nema u katalogu"), ("Crveni", 0, "od 1 do 99", "nula"),
                                     ("Crveni", 100, "od 1 do 99", "više od 99"), ("Crveni", "dve", "od 1 do 99", "nije broj")]:
        o = upisi(marko, boja, broj)
        R.provera(poruka in poruke(o) and mesalice() == "Marko:Crveni:3,Marko:Crveni:2,Marko:Crni:1", "odbijeno: " + opis, poruke(o))
    o = upisi(jelena, "Žuti", 4)
    R.provera("Žuti – 4 mešalice" in poruke(o) and "Crni" not in tekst(o.text).split("Moje današnje mešalice")[1], "Jelena vidi samo svoje unose", poruke(o))

    R.odeljak("Radnik briše samo poslednji unos, u roku od 10 minuta")
    o = marko.get(RM)
    R.provera(o.text.count('value="obrisi"') == 1 and "Obrisati ovaj unos (Crni, 1 mešalica)?" in o.text, "dugme Obriši samo uz poslednji unos")
    stari = sql_int("SELECT MIN(id) FROM mesalice WHERE korisnik_id=%d" % ids["Marko"])
    o = marko.post(RM, {"akcija": "obrisi", "id": stari})
    R.provera("više ne može da se obriše" in poruke(o) and mesalice("m.id=%d" % stari) != "", "stariji unos: ne može", poruke(o))
    jelenin = sql_int("SELECT MAX(id) FROM mesalice WHERE korisnik_id=%d" % ids["Jelena"])
    o = marko.post(RM, {"akcija": "obrisi", "id": jelenin})
    R.provera("više ne može da se obriše" in poruke(o) and mesalice("m.id=%d" % jelenin) != "", "tuđi unos: ne može", poruke(o))
    poslednji = sql_int("SELECT MAX(id) FROM mesalice WHERE korisnik_id=%d" % ids["Marko"])
    o = marko.post(RM, {"akcija": "obrisi", "id": poslednji})
    R.provera("Unos je obrisan" in poruke(o) and mesalice("k.ime='Marko'") == "Marko:Crveni:3,Marko:Crveni:2", "poslednji unos: obrisan", poruke(o))
    R.provera(sql("SELECT CONCAT(obrisan, '|', obrisao_id) FROM mesalice WHERE id=%d" % poslednji) == "1|%d" % ids["Marko"]
              and sql_int("SELECT COUNT(*) FROM dnevnik WHERE objekat='mesalica' AND objekat_id=%d AND akcija='brisanje_radnik'" % poslednji) == 1,
              "ostaje kao obrisan, uz zapis u dnevniku")
    sql("UPDATE mesalice SET uneto = DATE_SUB(uneto, INTERVAL 11 MINUTE) WHERE korisnik_id=%d" % ids["Marko"])
    o = marko.get(RM)
    R.provera('value="obrisi"' not in o.text, "posle 10 minuta nema dugmeta Obriši")
    o = marko.post(RM, {"akcija": "obrisi", "id": sql_int("SELECT MAX(id) FROM mesalice WHERE korisnik_id=%d AND obrisan=0" % ids["Marko"])})
    R.provera("više ne može da se obriše" in poruke(o), "posle 10 minuta: ne može", poruke(o))

    R.odeljak("Administrator: pregled")
    sql("INSERT INTO mesalice (korisnik_id, boja, broj, nastalo, uneto) VALUES (%d, 'Braon', 7, DATE_SUB(NOW(), INTERVAL 40 DAY), DATE_SUB(NOW(), INTERVAL 40 DAY))" % ids["Marko"])
    o = adm.get(AM)
    t = tekst(o.text)
    R.provera(o.status == 200 and 'aria-current="true">Ova nedelja<' in o.text and "Ukupno: 9 mešalica" in t and "Crveni 5 · Žuti 4" in t,
              "ova nedelja: ukupno 9 (Crveni 5, Žuti 4); stari unos se ne računa", t[:400])
    R.provera(re.search(r"Marko</strong> – 5 mešalica", o.text) and re.search(r"Jelena</strong> – 4 mešalice", o.text)
              and o.text.index('data-radnik="%d"' % ids["Marko"]) < o.text.index('data-radnik="%d"' % ids["Jelena"]), "po radniku, ko je uradio više prvi")
    danas = danas_bg()
    R.provera("%s.%s.%s." % (danas[8:10], danas[5:7], danas[:4]) in t and "Svi unosi (3)" in t, "po danima i svi unosi (3)")
    R.provera("norm" not in t.lower(), "nema norme")
    stari_od = sql("SELECT DATE(DATE_SUB(NOW(), INTERVAL 41 DAY))")
    o = adm.get(AM + "?od=%s&do=%s" % (stari_od, danas))
    R.provera("Ukupno: 16 mešalica" in tekst(o.text) and "Braon 7" in tekst(o.text), "drugi period (od–do): i stari unos (Braon 7)", tekst(o.text)[:300])
    o = adm.get(AM + "?od=%s&do=%s&csv=1" % (stari_od, danas))
    redovi = o.text.lstrip("﻿").strip().split("\r\n")
    R.provera(o.header("Content-Type").startswith("text/csv") and redovi[0] == '"Datum";"Vreme";"Radnik";"Boja";"Mešalica"' and len(redovi) == 5
              and redovi[1].endswith(';"Marko";"Braon";7') and redovi[-1].endswith(';"Jelena";"Žuti";4'), "izvoz u Excel (CSV), redom po vremenu", redovi)
    o = adm.get(AM + "?od=abc&do=%s" % danas)
    R.provera(o.status == 200 and 'aria-current="true">Ova nedelja<' in o.text, "neispravan datum: ova nedelja")
    zuti = sql_int("SELECT id FROM mesalice WHERE boja='Žuti'")
    o = adm.post(AM + "?od=%s&do=%s" % (danas, danas), {"akcija": "obrisi", "id": zuti})
    R.provera("obrisan" in poruke(o) and o.url.endswith("?od=%s&do=%s#unosi" % (danas, danas)) and "Ukupno: 5 mešalica" in tekst(o.text), "administrator briše pogrešan unos", poruke(o))
    o = adm.get("/admin/dnevnik.php")
    R.provera("Mešalice: Jelena – 4 × Žuti" in tekst(o.text) and 'href="/admin/mesalice.php"' in o.text, "brisanje je u dnevniku izmena")
    o = adm.post(AM, {"akcija": "obrisi", "id": zuti})
    R.provera("već obrisan" in poruke(o), "ponovno brisanje: poruka", poruke(o))

    R.odeljak("Radnici, meni ☰ i pristup")
    o = adm.get("/admin/radnici.php")
    R.provera("Mešalice danas: 5" in tekst(o.text) and 'href="/admin/mesalice.php"' in o.text, "Radnici: Marko – mešalice danas: 5; veza na pregled")
    R.provera('href="/admin/mesalice.php"' in adm.get("/admin/stanje.php").text, "meni ☰: Mešalice")
    o = marko.get(AM)
    R.provera(o.url.endswith("/radnik/index.php") and "Jelena" not in o.text, "radnik ne vidi pregled")
    o = marko.post(AM, {"akcija": "obrisi", "id": stari})
    R.provera(mesalice("m.id=%d" % stari) != "", "radnik ne može da briše preko pregleda")
    o = adm.get(RM)
    R.provera(o.url.endswith("/admin/stanje.php"), "administrator nema radnički ekran Mešalice", o.url)

    R.odeljak("Boje prate katalog")
    sql("UPDATE varijante v JOIN artikli a ON a.id=v.artikal_id SET v.aktivan=0 WHERE a.naziv='Malč Farmerkop' AND v.naziv='Žuti'")
    a = sql_int("SELECT id FROM artikli WHERE naziv='Malč Farmerkop'")
    sql("INSERT INTO varijante (artikal_id, naziv, redosled, aktivan) VALUES (%d, 'Plavi', 99, 1)" % a)
    o = marko.get(RM)
    R.provera(boje(o) == ["Crveni", "Braon", "Crni", "Narandžasti", "Zeleni", "Plavi"] and 'data-boja="plav"' in o.text, "isključena boja nestaje, nova se pojavi", boje(o))
    o = upisi(marko, "Žuti", 1)
    R.provera("Izaberi boju" in poruke(o), "isključena boja ne može da se upiše", poruke(o))

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
