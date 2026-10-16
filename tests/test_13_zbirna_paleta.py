#!/usr/bin/env python3
"""Zbirna paleta na spisku za utovar: više artikala na jednoj paleti (npr. 5 × 5 l, 5 × 10 l, 5 × 50 l),
broj ovakvih paleta, izmene, zbir paleta i težine, upozorenje po artiklu, potvrda kod radnika,
štampa i deljenje, nadogradnja baze sa verzije 5."""
import html as htmllib
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


def linije(sp, pid=None):
    q = "SELECT sku_id, kolicina, palete, paketi, komadi FROM spisak_stavke WHERE spisak_id=%d" % sp
    if pid is not None:
        q += " AND paleta_id=%d" % pid
    return sql(q + " ORDER BY id")


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum5, hum10, hum50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 50)
    idea5 = sku_id("Idea", 5)

    R.odeljak("Nadogradnja baze sa verzije 5 (kakva je pre zbirnih paleta)")
    sql("ALTER TABLE spisak_stavke ADD UNIQUE KEY uq_stavka (spisak_id, sku_id), DROP INDEX uq_stavka_mesto, DROP COLUMN paleta_id, DROP COLUMN mesto_id; "
        "DROP TABLE spisak_palete; DROP TABLE spisak_mesta; ALTER TABLE spiskovi MODIFY kupac_id INT UNSIGNED NOT NULL; "
        "UPDATE podesavanja SET vrednost='5' WHERE kljuc='schema_verzija'")
    R.provera(not sql("SHOW TABLES LIKE 'spisak_palete'"), "priprema: baza je kao verzija 5")
    Client(site.base).get("/login.php")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "8", "posle prvog otvaranja: verzija 8")
    R.provera(sql("SHOW TABLES LIKE 'spisak_palete'") == "spisak_palete", "napravljena je tabela zbirnih paleta")
    kljucevi = sql("SELECT GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) FROM information_schema.statistics WHERE table_schema='%s' AND table_name='spisak_stavke'" % DB_NAME)
    R.provera("uq_stavka_mesto" in kljucevi and "uq_stavka," not in kljucevi + "," and "uq_stavka_paleta" not in kljucevi and sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND table_name='spisak_stavke' AND column_name IN ('paleta_id', 'mesto_id')" % DB_NAME) == 2,
              "stavka ima paleta_id i mesto_id i novi jedinstveni ključ (isti artikal sme i posebno i u zbirnoj paleti)", kljucevi)
    R.provera(all(site.log().count("nadogradnja baze na verziju %d" % v) == 1 for v in (6, 7, 8)), "nadogradnje 6, 7 i 8 su se izvršile po jednom")

    for sku, kol, nacin in [(hum10, "2", "palete"), (hum5, "100", "komadi"), (hum50, "1", "palete")]:
        jelena.get("/radnik/index.php")
        jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})
    R.provera((stanje(hum10), stanje(hum5), stanje(hum50)) == (540, 100, 40), "priprema stanja: 540 / 100 / 40")

    R.odeljak("Zbirna paleta: pravljenje i dodavanje artikala")
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "Cvećara Flora", "datum": danas_bg(), "vozilo": "SU 777-AA"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('class="mesto-zbirna"' in o.text and 'name="paleta_id" value="0"' in o.text and 'data-odrediste' not in o.text, "bez zbirnih paleta nema izbora „Gde ide“")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "p_naziv": "", "p_broj": "1"})
    pid = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera(sql("SELECT naziv, broj FROM spisak_palete WHERE id=%d" % pid) == "Zbirna paleta 1\t1", "napravljena „Zbirna paleta 1“ (1 paleta)")
    R.provera("&u=%d" % pid in o.url and re.search(r'<option value="%d" data-broj="1" selected>U zbirnu paletu: Zbirna paleta 1</option>' % pid, o.text), "posle pravljenja je u „Gde ide“ izabrana nova paleta", o.url)
    R.provera("Prazna – dodajte artikle" in o.text, "prazna paleta je prikazana sa uputstvom")
    for sku in (hum5, hum10, hum50):
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": pid, "sku_id": sku, "kom": "5"})
    R.provera(linije(sp, pid) == "%d\t5\t0\t0\t5\n%d\t5\t0\t0\t5\n%d\t5\t0\t0\t5" % (hum5, hum10, hum50), "u zbirnoj paleti: 5 × Humovit 5 l, 5 × 10 l, 5 × 50 l", linije(sp, pid))
    R.provera("&u=%d" % pid in o.url, "posle dodavanja ostaje izabrana ista zbirna paleta")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": pid, "sku_id": hum5, "pal": "1"})
    R.provera("ne cele palete" in poruke(o), "u zbirnu paletu se ne upisuju cele palete", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": pid, "sku_id": hum10, "pak": "1", "kom": "2"})
    R.provera("zamenjena novom količinom" in poruke(o) and sql("SELECT kolicina, paketi, komadi FROM spisak_stavke WHERE paleta_id=%d AND sku_id=%d" % (pid, hum10)) == "8\t1\t2", "Humovit 10 l u paleti: 1 paket + 2 kom = 8 (zamena, ne duplikat)")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": "0", "sku_id": hum10, "pal": "1"})
    R.provera(sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp, hum10)) == 2, "isti artikal može i kao posebna stavka (1 paleta) i u zbirnoj paleti")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": "99999", "sku_id": hum5, "kom": "1"})
    R.provera("Zbirna paleta ne postoji" in poruke(o), "nepostojeća zbirna paleta: odbijeno")
    o = adm.get(SP + "?id=%d" % sp)
    t = tekst(o.text)
    R.provera("Zbirna paleta · 3 artikla" in t, "zaglavlje palete: 3 artikla", re.search(r"Zbirna paleta ·.{0,40}", t))
    R.provera("1 pak + 2 kom = 8 kom" in t, "plan stavke u paleti: 1 pak + 2 kom = 8 kom")
    # palete: posebna stavka 270/270 = 1 + 1 zbirna = 2; kg: 270×3,6 + 5×1,8 + 8×3,6 + 5×18 = 972 + 9 + 28,8 + 90 = 1.099,8
    R.provera(re.search(r'<span class="broj">2</span><span class="opis">paleta \(zbirnih: 1\)', o.text) is not None, "ukupno 2 palete (od toga 1 zbirna)")
    R.provera("1.099,8 kg" in t, "težina tovara 1.099,8 kg", re.search(r"paleta.{0,60}", t))

    R.odeljak("Broj ovakvih paleta i izmene")
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "pl_naziv[%d]" % pid: "Mešovita <b>Flora</b>", "pl_broj[%d]" % pid: "3"})
    R.provera("Sačuvane izmene" in poruke(o) and sql("SELECT naziv, broj FROM spisak_palete WHERE id=%d" % pid) == "Mešovita <b>Flora</b>\t3", "preimenovana i postavljena na 3 ovakve palete", poruke(o))
    R.provera(sql("SELECT GROUP_CONCAT(kolicina ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid) == "15,24,15", "količine × 3: 15, 24, 15")
    o = adm.get(SP + "?id=%d" % sp)
    t = tekst(o.text)
    R.provera("5 kom na paleti × 3 = 15 kom" in t and "1 pak + 2 kom na paleti × 3 = 24 kom" in t, "plan: „5 kom na paleti × 3 = 15 kom“")
    R.provera("Mešovita &lt;b&gt;Flora&lt;/b&gt;" in o.text and "<b>Flora</b>" not in o.text, "naziv palete se ispisuje bezbedno")
    R.provera(re.search(r'<span class="broj">4</span><span class="opis">paleta \(zbirnih: 3\)', o.text) is not None, "ukupno 4 palete (3 zbirne + 1)")
    s5 = sql_int("SELECT id FROM spisak_stavke WHERE paleta_id=%d AND sku_id=%d" % (pid, hum5))
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "kom[%d]" % s5: "4"})
    R.provera(sql("SELECT kolicina, komadi FROM spisak_stavke WHERE id=%d" % s5) == "12\t4", "izmena u paleti: 4 kom na paleti × 3 = 12")
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "pl_broj[%d]" % pid: "0"})
    R.provera("od 1 do 999" in poruke(o) and sql("SELECT broj FROM spisak_palete WHERE id=%d" % pid) == "3", "0 paleta se odbija")
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "pl_broj[%d]" % pid: "1"})
    R.provera(sql("SELECT GROUP_CONCAT(kolicina ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid) == "4,8,5", "nazad na 1 paletu: 4, 8, 5")
    adm.post(SP, {"akcija": "stavke", "id": sp, "pl_broj[%d]" % pid: "3"})

    R.odeljak("Upozorenje gleda ukupno po artiklu")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": "0", "sku_id": hum50, "kom": "30"})
    R.provera("slobodno je samo 40 kom" in tekst(o.text) and "na spisku ukupno 45 kom" in tekst(o.text), "Humovit 50 l: 15 u paletama + 30 posebno = 45 > 40 → upozorenje", re.findall(r"slobodno je samo[^<]{0,80}", o.text))

    R.odeljak("Druga zbirna paleta i uklanjanje")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "p_naziv": "", "p_broj": "2"})
    pid2 = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera(sql("SELECT naziv, broj FROM spisak_palete WHERE id=%d" % pid2) == "Zbirna paleta 2\t2", "druga: „Zbirna paleta 2“ (2 palete)")
    adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": pid2, "sku_id": idea5, "pak": "1"})
    R.provera(sql("SELECT kolicina FROM spisak_stavke WHERE paleta_id=%d" % pid2) == "20", "Idea 5 l: 1 paket (10) × 2 palete = 20")
    o = adm.post(SP, {"id": sp, "ukloni_paletu": str(pid2)})
    R.provera("Uklonjena zbirna paleta „Zbirna paleta 2“" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_palete WHERE id=%d" % pid2) == 0 and sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE paleta_id=%d" % pid2) == 0, "zbirna paleta je uklonjena sa svim artiklima")

    R.odeljak("Slanje na utovar i rezervacija")
    o = adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp) == "za_utovar", "spisak je poslat na utovar")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "p_broj": "1"})
    R.provera("samo dok je nacrt" in poruke(o), "na spisak Za utovar ne može nova zbirna paleta")
    o = adm.get("/admin/stanje.php")
    blok = o.text[re.search(r"<h3>\s*Humovit\s*<", o.text).start():]
    b10 = tekst(blok[blok.index(">10 l<"):][:1500])
    R.provera("rezervisano 294" in b10, "Stanje: Humovit 10 l rezervisano 270 + 24 = 294", b10[:200])

    R.odeljak("Radnik: zbirna paleta je jedna grupa")
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    t = tekst(o.text)
    R.provera('data-cek-grupa="%d"' % pid in o.text and o.text.count('data-grupa="%d"' % pid) == 3, "zbirna paleta ima kvadratić za celu paletu i 3 artikla u grupi")
    R.provera("složiti 3 palete, na svaku: 3 artikla" in t, "radnik vidi koliko paleta da složi i šta ide na svaku", re.search(r"zbirna paleta – .{0,60}", t))
    R.provera("komada ukupno (sve palete)" in t, "ispravka u zbirnoj paleti je u ukupnim komadima")
    stavke = dict((int(r.split("\t")[0]), r.split("\t")) for r in sql("SELECT id, sku_id, paleta_id FROM spisak_stavke WHERE spisak_id=%d" % sp).split("\n"))
    f = {"id": sp, "akcija": "potvrdi"}
    for stid in stavke:
        f["ok[%d]" % stid] = "1"
    s10p = sql_int("SELECT id FROM spisak_stavke WHERE paleta_id=%d AND sku_id=%d" % (pid, hum10))
    f["kom[%d]" % s10p] = "20"
    o = jelena.post("/radnik/utovar.php", f)
    R.provera("Utovar je potvrđen" in poruke(o) and "H10L (Mešovita <b>Flora</b>): 20 umesto 24" in poruke(o), "potvrđeno; razlika u paleti je označena nazivom palete", poruke(o))
    R.provera((stanje(hum10), stanje(hum5), stanje(hum50)) == (540 - 270 - 20, 100 - 12, 40 - 15 - 30), "stanje: Humovit 10 l −290, 5 l −12, 50 l −45 (minus dozvoljen)", (stanje(hum10), stanje(hum5), stanje(hum50)))
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d AND napomena LIKE '%%– Mešovita%%'" % sp) == 3, "utovar iz zbirne palete u Istoriji nosi naziv palete")

    R.odeljak("Štampa i deljenje")
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp)
    t = tekst(o.text)
    R.provera("Zbirna paleta: Mešovita <b>Flora</b> · 3 palete" in t and 'class="stampa-grupa"' in o.text, "štampa: red sa nazivom zbirne palete i brojem paleta")
    R.provera(o.text.count('class="stampa-u-paleti"') == 3, "3 reda pod zbirnom paletom")
    m = re.search(r'href="https://wa\.me/\?text=([^"]+)"', o.text)
    deljenje = urllib.parse.unquote(htmllib.unescape(m.group(1))) if m else ""
    R.provera("ZBIRNA PALETA – Mešovita <b>Flora</b> (3 palete):" in deljenje and "   " in deljenje, "tekst za Viber/WhatsApp grupiše zbirnu paletu", deljenje[:400])
    R.provera("(zbirnih: 3)" in deljenje, "u zbiru se navode zbirne palete")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
