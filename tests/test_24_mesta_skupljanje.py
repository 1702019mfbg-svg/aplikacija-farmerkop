#!/usr/bin/env python3
"""Mesta na spisku: završeno mesto se skuplja (dodir na broj ga otvara), broj/kupac/adresa mesta se menjaju i dok
spisak čeka utovar, a broj koji već ima drugo mesto znači zamenu brojeva."""
import html as htmllib
import re
import sys
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


def otvorena(o):
    """Mesta čija je roba otvorena (id mesta), redom."""
    return [int(i) for i in re.findall(r'<details class="mesto-telo" id="mesto-(\d+)-telo" open>', o.text)]


def mesta(sp):
    return sql("SELECT GROUP_CONCAT(CONCAT(m.broj, '|', IFNULL(k.naziv, '')) ORDER BY m.redosled SEPARATOR ',') FROM spisak_mesta m "
               "LEFT JOIN kupci k ON k.id = m.kupac_id WHERE m.spisak_id=%d" % sp)


def dodaj(sp, mesto, artikal, kom):
    return adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": mesto, "paleta_id": "0",
                         "r_tekst[]": [artikal], "r_id[]": [""], "r_pal[]": [""], "r_pak[]": [""], "r_kom[]": [kom]})


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum10 = sku_id("Humovit", 10)
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 500, 1, NOW(), NOW())" % hum10)
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "Agrocentar", "datum": danas_bg(), "mesto_broj": "1"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m1 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)
    dodaj(sp, m1, "humovit 10", "10")

    R.odeljak("Nacrt: mesto na kom se radi je otvoreno, ostala su skupljena")
    o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "2", "novo_kupac": "Cvećara Flora"})
    m2 = sql_int("SELECT MAX(id) FROM spisak_mesta")
    R.provera(otvorena(o) == [m2], "dodato Mesto 2 (prazno, otvoreno); Mesto 1 je završeno i skupljeno", otvorena(o))
    blok1 = o.text.split('id="mesto-%d"' % m1)[1].split('id="mesto-%d"' % m2)[0]
    R.provera('<button type="button" class="mesto-krug" data-mesto-prekidac="mesto-%d-telo" aria-controls="mesto-%d-telo" aria-expanded="false"' % (m1, m1) in blok1
              and "1 stavka · ≈ 36 kg" in tekst(blok1) and "Prikaži robu" in tekst(blok1),
              "skupljeno mesto: broj (dugme za otvaranje), kupac i zbir u jednom redu", tekst(blok1)[:200])
    o = dodaj(sp, m2, "humovit 10", "20")
    R.provera(otvorena(o) == [m2], "posle čuvanja robe za Mesto 2: ono ostaje otvoreno", otvorena(o))
    o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "3", "novo_kupac": "Bašta Zemun"})
    m3 = sql_int("SELECT MAX(id) FROM spisak_mesta")
    dodaj(sp, m3, "humovit 10", "30")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera(otvorena(o) == [m3], "otvaranje spiska: otvoreno je najnovije mesto (3), 1 i 2 su skupljena", otvorena(o))
    o = adm.get(SP + "?id=%d&m=%d" % (sp, m1))
    R.provera(otvorena(o) == [m1], "?m=: otvoreno je to mesto", otvorena(o))
    s2 = sql_int("SELECT id FROM spisak_stavke WHERE mesto_id=%d" % m2)
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "kom[%d]" % s2: "25", "m_broj[%d]" % m1: "1", "m_broj[%d]" % m2: "2", "m_broj[%d]" % m3: "3"})
    R.provera("Sačuvane izmene (1)" in poruke(o) and otvorena(o) == [m2] and o.url.endswith("&o=%d#mesto-%d" % (m2, m2)),
              "ispravka količine u Mestu 2: posle čuvanja Mesto 2 ostaje otvoreno", (poruke(o), otvorena(o), o.url))
    adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "4"})
    m4 = sql_int("SELECT MAX(id) FROM spisak_mesta")
    o = adm.get(SP + "?id=%d&m=%d" % (sp, m1))
    R.provera(otvorena(o) == [m1, m4], "prazno mesto je uvek otvoreno (tu treba da se upiše roba)", otvorena(o))
    adm.post(SP, {"ukloni_mesto": m4, "id": sp, "akcija": "stavke"})

    R.odeljak("Spisak čeka utovar: sva mesta skupljena, broj mesta može da se menja")
    adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    o = adm.get(SP + "?id=%d" % sp)
    R.provera(otvorena(o) == [] and o.text.count('class="mesto-krug"') == 3, "Za utovar: sva tri mesta skupljena (dodir na broj ih otvara)", otvorena(o))
    blok3 = o.text.split('id="mesto-%d"' % m3)[1]
    R.provera('name="akcija" value="mesta"' in blok3 and 'name="m_broj[%d]"' % m3 in blok3 and 'form="izmene"' not in blok3[:4000]
              and "Dodaj robu" not in tekst(blok3[:3000]), "Za utovar: kod mesta je samo „Broj, kupac, adresa“ (sopstvena forma), roba se ne menja")
    rez_pre = adm.get("/admin/stanje.php").text.count("rezervisano 65")
    o = adm.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m3, "m_broj[%d]" % m3: "1"})
    R.provera("zamenjeni brojevi: Mesto 1 · Agrocentar je sada Mesto 3" in poruke(o) and mesta(sp) == "3|Agrocentar,2|Cvećara Flora,1|Bašta Zemun",
              "Mesto 3 → 1: Mesto 1 (Agrocentar) dobija broj 3 – zamena", (poruke(o), mesta(sp)))
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp) == "za_utovar" and rez_pre == 1 and adm.get("/admin/stanje.php").text.count("rezervisano 65") == 1,
              "spisak i dalje čeka utovar, rezervacija ista (65 kom)")
    R.provera(otvorena(o) == [m3] and o.url.endswith("&o=%d#mesto-%d" % (m3, m3)), "posle izmene otvoreno je izmenjeno mesto", (otvorena(o), o.url))
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Spisak #%d: izmenjeno mesto (zamenjeni brojevi: Mesto 1 · Agrocentar je sada Mesto 3)'" % sp) == 1,
              "izmena je u dnevniku")
    o = adm.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m2, "m_broj[%d]" % m2: "7", "m_kupac[%d]" % m2: "Cvećara Žika", "m_adresa[%d]" % m2: "Zemun, Glavna 5"})
    R.provera(mesta(sp) == "3|Agrocentar,7|Cvećara Žika,1|Bašta Zemun" and sql("SELECT adresa FROM spisak_mesta WHERE id=%d" % m2) == "Zemun, Glavna 5"
              and "Izmenjeno mesto." in poruke(o), "slobodan broj (7), drugi kupac i adresa", (poruke(o), mesta(sp)))
    o = adm.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m2, "m_broj[%d]" % m2: "0"})
    R.provera("broj mesta mora biti od 1 do 999" in poruke(o) and mesta(sp) == "3|Agrocentar,7|Cvećara Žika,1|Bašta Zemun", "broj 0: odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m2, "m_broj[%d]" % m2: "7"})
    R.provera("Nema izmena." in poruke(o), "bez promene: „Nema izmena.“", poruke(o))
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    t = tekst(o.text)
    R.provera(t.index("Mesto 3") < t.index("Mesto 7") < t.index("Mesto 1") and "Bašta Zemun" in t and "Cvećara Žika" in t,
              "radnik vidi nove brojeve i kupca (redosled tovarenja isti)")
    jelena.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m2, "m_broj[%d]" % m2: "9"})
    R.provera(mesta(sp) == "3|Agrocentar,7|Cvećara Žika,1|Bašta Zemun", "radnik ne može da menja mesta")

    R.odeljak("Utovaren spisak: mesta se više ne menjaju")
    f = {"id": sp}
    for sid in sql("SELECT id FROM spisak_stavke WHERE spisak_id=%d" % sp).split():
        f["ok[%s]" % sid] = "1"
    jelena.post("/radnik/utovar.php", f)
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp) == "utovareno", "utovar je potvrđen")
    o = adm.post(SP, {"akcija": "mesta", "id": sp, "mesto_id": m2, "m_broj[%d]" % m2: "9"})
    R.provera("Mesta mogu da se menjaju dok spisak nije utovaren" in poruke(o) and mesta(sp) == "3|Agrocentar,7|Cvećara Žika,1|Bašta Zemun", "utovaren: odbijeno", poruke(o))
    o = adm.get(SP + "?id=%d" % sp)
    R.provera(otvorena(o) == [] and 'name="akcija" value="mesta"' not in o.text, "utovaren: mesta skupljena, bez izmene mesta")

    R.odeljak("Spisak sa jednim mestom: mesto je otvoreno")
    adm.post("/admin/utovar.php", {"kupac": "Jedan kupac", "datum": danas_bg(), "mesto_broj": "5"})
    sp2 = sql_int("SELECT MAX(id) FROM spiskovi")
    mj = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp2)
    dodaj(sp2, mj, "humovit 10", "5")
    adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera(otvorena(adm.get(SP + "?id=%d" % sp2)) == [mj], "jedno mesto: otvoreno i kad spisak čeka utovar")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
