#!/usr/bin/env python3
"""
Nadogradnja već instalirane baze: stara verzija (commit 2b391c5, ono što je na hostingu) → nova.
Proverava da se baza sama nadogradi pri prvom otvaranju, jednom, bez gubitka podataka.
"""
import concurrent.futures
import io
import re
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

STARA_VERZIJA = "2b391c5"

R = Rezultat()
reset_db()

tmp = Path(tempfile.mkdtemp(prefix="fk_stara_"))
arhiva = subprocess.run(["git", "-C", str(ROOT), "archive", STARA_VERZIJA, "app"], capture_output=True, check=True).stdout
tarfile.open(fileobj=io.BytesIO(arhiva)).extractall(tmp)
stara_app = tmp / "app"

stari = Site(app_dir=stara_app)
novi = None
try:
    # ── 1. Stara verzija: instalacija i rad ──────────────────────────────────
    R.odeljak("Stara verzija (kakva je sada na hostingu)")
    c = Client(stari.base)
    c.get("/install.php")
    o = c.post("/install.php", {"kljuc": INSTALL_KLJUC, "ime": "Vlasnik", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS, "sifra2": ADMIN_PASS, "katalog": "1"})
    R.provera("Instalacija je uspešno završena" in o.text, "stara verzija se instalirala")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "1", "baza je na verziji 1")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND table_name='sku' AND column_name='po_paketu'" % DB_NAME) == 0, "stara baza nema kolonu po_paketu")
    R.provera(sql("SELECT naziv FROM artikli WHERE naziv LIKE 'C%mana%'") == "Čmana supstrat", "stari naziv je 'Čmana supstrat'")

    for ime, pin in [("Marko", "2468"), ("Jelena", "4321")]:
        sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','%s','%s',1,NOW())" % (ime, php_hes(pin)))
    jid = sql_int("SELECT id FROM korisnici WHERE ime='Jelena'")
    j = prijavi_radnika(stari, jid, "4321")
    hum10, hum5, idea10 = sku_id("Humovit", 10), sku_id("Humovit", 5), sku_id("Idea", 10)
    for sku, kol, nacin in [(hum10, "2", "palete"), (hum5, "17", "komadi"), (idea10, "1", "palete")]:
        j.get("/radnik/index.php")
        j.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})
    a = prijavi_admina(stari)
    a.get("/admin/prodaja.php")
    a.post("/admin/prodaja.php", {"kupac": "Cvećara Žika", "sku_id": hum10, "kolicina": "100", "nacin": "komadi", "kljuc": nov_kljuc()})
    # vlasnik je u međuvremenu sam podesio nešto
    sql("UPDATE sku SET po_paleti=230, min_zaliha=50 WHERE id=%d" % idea10)
    # i "nestao" mu je Humovit (kao u prijavi): isključeni su mu svi SKU-ovi
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    sql("UPDATE sku SET aktivan=0 WHERE artikal_id=%d" % hum_id)

    pre = {
        "unosi": sql("SELECT COUNT(*) FROM unosi"), "korisnici": sql("SELECT COUNT(*) FROM korisnici"), "sku": sql("SELECT COUNT(*) FROM sku"),
        "artikli": sql("SELECT COUNT(*) FROM artikli"), "kupci": sql("SELECT COUNT(*) FROM kupci"),
        "stanja": sql("SELECT sku_id, SUM(CASE WHEN tip IN ('prodaja','kucna_prodaja') THEN -kolicina ELSE kolicina END) FROM unosi WHERE obrisan=0 GROUP BY sku_id ORDER BY sku_id"),
        "hesevi": sql("SELECT GROUP_CONCAT(hes ORDER BY id) FROM korisnici"),
        "paleta": sql("SELECT GROUP_CONCAT(po_paleti ORDER BY id) FROM sku"),
    }
    R.provera(sql_int("SELECT COUNT(*) FROM unosi") == 4, "stara verzija ima 4 unosa")
    stari.stop()

    # ── 2. Nova verzija, prvo otvaranje: istovremeni zahtevi ─────────────────
    R.odeljak("Nova verzija: prvo otvaranje posle ažuriranja")
    novi = Site()
    def zahtev(i):
        c = Client(novi.base)
        return c.get("/login.php").status

    with concurrent.futures.ThreadPoolExecutor(8) as ex:
        statusi = list(ex.map(zahtev, range(8)))
    R.provera(statusi == [200] * 8, "8 istovremenih prvih zahteva: svi su uspeli (nema greške 500)", statusi)
    log = novi.log()
    R.provera(log.count("nadogradnja baze na verziju 2") == 1, "nadogradnja se izvršila tačno jednom", log.count("nadogradnja baze na verziju 2"))
    R.provera(not novi.php_problemi(), "bez PHP upozorenja pri nadogradnji", novi.php_problemi()[:3])

    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "2", "baza je sada na verziji 2")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND ((table_name='sku' AND column_name='po_paketu') OR (table_name='unosi' AND column_name='paketi'))" % DB_NAME) == 2, "dodate su kolone po_paketu i paketi")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%s' AND table_name='uvoz_mapiranje'" % DB_NAME) == 1, "dodata je tabela uvoz_mapiranje")

    # ── 3. Podaci su netaknuti ───────────────────────────────────────────────
    R.odeljak("Podaci posle nadogradnje")
    for ime, upit in [("unosi", "SELECT COUNT(*) FROM unosi"), ("korisnici", "SELECT COUNT(*) FROM korisnici"), ("sku", "SELECT COUNT(*) FROM sku"),
                      ("artikli", "SELECT COUNT(*) FROM artikli"), ("kupci", "SELECT COUNT(*) FROM kupci")]:
        R.provera(sql(upit) == pre[ime], "broj redova nepromenjen: " + ime, (sql(upit), pre[ime]))
    R.provera(sql("SELECT sku_id, SUM(CASE WHEN tip IN ('prodaja','kucna_prodaja') THEN -kolicina ELSE kolicina END) FROM unosi WHERE obrisan=0 GROUP BY sku_id ORDER BY sku_id") == pre["stanja"], "stanja svih artikala su ista")
    R.provera(sql("SELECT GROUP_CONCAT(hes ORDER BY id) FROM korisnici") == pre["hesevi"], "šifre i PIN-ovi (heševi) su isti")
    R.provera(sql("SELECT GROUP_CONCAT(po_paleti ORDER BY id) FROM sku") == pre["paleta"], "podešene palete su iste (i izmena vlasnika: Idea 10 l = 230)")
    R.provera(sql("SELECT po_paleti, min_zaliha FROM sku WHERE id=%d" % idea10) == "230\t50", "vlasnikova podešavanja (paleta 230, minimum 50) nisu prepisana")
    R.provera(sql("SELECT COUNT(*) FROM unosi WHERE paketi IS NOT NULL") == "0", "stari unosi nemaju pakete (NULL)")

    pp = {re.sub(r"(\d+)\.00", r"\1", r.split("\t")[0]): r.split("\t")[1] for r in sql(
        "SELECT CONCAT(a.naziv,' ',p.kolicina,' ',p.jedinica), IFNULL(s.po_paketu,'NULL') FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id WHERE a.naziv IN ('Humovit','Idea')").split("\n")}
    R.provera(pp["Humovit 5 l"] == "10" and pp["Humovit 10 l"] == "6" and pp["Idea 5 l"] == "10" and pp["Idea 10 l"] == "5", "komada u paketu postavljeno: Humovit 10/6, Idea 10/5 (čak i dok su isključeni SKU-ovi)", pp)
    R.provera(pp["Humovit 25 l"] == "NULL" and pp["Idea 20 l"] == "NULL", "ostala pakovanja bez paketa")
    R.provera(sql("SELECT naziv FROM artikli WHERE naziv LIKE 'C%mana%'") == "Cmana supstrat", "'Čmana supstrat' je preimenovana u 'Cmana supstrat'")

    # ── 4. Aplikacija radi ───────────────────────────────────────────────────
    R.odeljak("Aplikacija posle nadogradnje")
    j = prijavi_radnika(novi, jid, "4321")
    o = j.get("/radnik/index.php")
    R.provera(o.status == 200 and "Novi unos" in o.text, "radnik se prijavljuje starim PIN-om")
    a = prijavi_admina(novi)
    o = a.get("/admin/stanje.php")
    R.provera(o.status == 200 and re.search(r'sku-stanje">440<', o.text) is not None, "administrator vidi staro stanje (Humovit 10 l: 540 − 100 = 440)")
    R.provera("Humovit" in o.text and "isključen" in o.text, "isključen Humovit sa stanjem je i dalje vidljiv u Stanju")
    R.provera("1 pal + 28 pak + 2 kom" in o.text.replace("\n", " ") or "28 pak" in o.text, "stanje se razlaže i na pakete (440 = 1 pal + 28 pak + 2 kom)")
    o = a.get("/admin/istorija.php?od=&do=")
    R.provera(o.status == 200 and o.text.count("unos-red") >= 4, "istorija prikazuje stare unose")
    o = a.get("/admin/izvoz.php?od=&do=")
    R.provera("Paketi" in o.body.splitlines()[0] and len(o.body.splitlines()) == 5, "CSV izvoz radi sa novom kolonom (4 stara unosa)")

    # vraćanje nestalog Humovita (kao u prijavi) i unos po paketima
    a.get("/admin/artikal.php?id=%d" % hum_id)
    pak = [sql("SELECT id FROM pakovanja WHERE kolicina=%s AND jedinica='l'" % x) for x in ("5", "10", "25", "50")]
    o = a.post("/admin/artikal.php", {"akcija": "pakovanja", "id": hum_id, "pak[]": pak})
    R.provera("Pakovanja su sačuvana" in o.text, "nestali Humovit se vraća označavanjem pakovanja")
    j.get("/radnik/index.php")
    o = j.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": hum10, "kolicina": "5", "nacin": "paketi", "kljuc": nov_kljuc()})
    R.provera("30 kom (5 paketa)" in o.text, "radnik unosi 5 paketa Humovita 10 l = 30 komada")
    R.provera(sql("SELECT po_paleti, po_paketu FROM sku WHERE id=%d" % hum10) == "270\t6", "Humovit 10 l: paleta 270 i paket 6 sačuvani")

    # ── 5. Drugo pokretanje: bez ponovne nadogradnje ─────────────────────────
    R.odeljak("Ponovno pokretanje")
    novi.stop()
    novi = Site()
    Client(novi.base).get("/login.php")
    R.provera(novi.log().count("nadogradnja baze") == 0, "pri ponovnom pokretanju nadogradnja se ne ponavlja")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "2", "verzija ostaje 2")
    R.provera(not novi.php_problemi(), "bez PHP upozorenja", novi.php_problemi()[:3])

    # ── 6. Baza bez reda o verziji (stariji nepotpun zapis) ──────────────────
    R.odeljak("Nedostaje zapis o verziji")
    sql("DELETE FROM podesavanja WHERE kljuc='schema_verzija'")
    sql("ALTER TABLE sku DROP COLUMN po_paketu; ALTER TABLE unosi DROP COLUMN paketi")
    novi.stop()
    novi = Site()
    st = Client(novi.base).get("/login.php").status
    R.provera(st == 200 and sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "2", "bez zapisa o verziji tretira se kao verzija 1 i nadograđuje se", st)
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND table_name='sku' AND column_name='po_paketu'" % DB_NAME) == 1, "kolona je ponovo napravljena")
finally:
    for s in (stari, novi):
        if s:
            try:
                s.stop()
            except Exception:
                pass
    shutil.rmtree(tmp, ignore_errors=True)

sys.exit(R.kraj())
