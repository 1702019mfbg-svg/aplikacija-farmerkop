#!/usr/bin/env python3
"""Unos robe na spisku punim nazivom: kuca se "humovit", pa se bira pakovanje (5 / 10 / 25 / 50 l); više artikala
za jedno mesto se čuva odjednom; spisak sa papira (prepisan ili izdiktiran tekst → mesta i roba, provera, sačuvaj
sve). Nadogradnja v8 → v9 uklanja kratka imena (sku.sifra). Prikaz punog naziva na spisku, kod radnika i na štampi."""
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
PAPIR = "/admin/spisak_papir.php"


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def verzija():
    return sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")


def kolona_sifra():
    return sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND table_name='sku' AND column_name='sifra'" % DB_NAME)


def stavke(sp, mesto=None):
    """"sku_id:kolicina:palete:paketi:komadi" redom kako su upisane (bez zbirnih paleta)."""
    uslov = " AND mesto_id=%d" % mesto if mesto else ""
    r = sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina, ':', palete, ':', paketi, ':', komadi) ORDER BY id) FROM spisak_stavke "
               "WHERE spisak_id=%d AND paleta_id=0%s" % (sp, uslov))
    return "" if r == "NULL" else r


def podaci_za_unos(o):
    m = re.search(r'<script type="application/json" id="sku-podaci">(.*?)</script>', o.text, re.S)
    return json.loads(htmllib.unescape(m.group(1))) if m else []


def polja_forme(html, forma_id):
    """Sva polja forme kao rečnik (štiklirani kvadratići, skrivena i tekstualna polja) – kao da je forma poslata."""
    f = re.search(r'<form[^>]*id="%s"[^>]*>(.*?)</form>' % forma_id, html, re.S).group(1)
    polja = {}
    for m in re.finditer(r"<input\b([^>]*)>", f):
        a = {k: htmllib.unescape(v) for k, v in re.findall(r'([\w-]+)="([^"]*)"', m.group(1))}
        if "name" not in a or (a.get("type") == "checkbox" and not re.search(r"\schecked\b", m.group(1))):
            continue
        polja[a["name"]] = a.get("value", "")
    return polja


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum5, hum10, hum25, hum50 = (sku_id("Humovit", k) for k in (5, 10, 25, 50))
    hump50 = sku_id("Humovit premium", 50)
    idea5, idea10 = sku_id("Idea", 5), sku_id("Idea", 10)
    rizla = sku_id("Bela rizla", 20, "kg", "Sitnija")
    bo46, bo47, bo13 = (sku_id("Beli oblutak", 20, "kg", g) for g in ("4-6 cm", "4-7 cm (krupnija)", "1-3 cm"))

    R.odeljak("Nadogradnja v8 → v9: kratka imena (sku.sifra) se uklanjaju")
    R.provera(verzija() == "9" and kolona_sifra() == "0", "nova instalacija: verzija 9, bez kolone sifra")
    sql("ALTER TABLE sku ADD COLUMN sifra VARCHAR(40) NULL DEFAULT NULL, ADD UNIQUE KEY uq_sku_sifra (sifra); "
        "UPDATE sku SET sifra=CONCAT('S', id); UPDATE podesavanja SET vrednost='8' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "9" and kolona_sifra() == "0" and site.log().count("nadogradnja baze na verziju 9") == 1, "posle nadogradnje: kolona i indeks su uklonjeni")
    sql("ALTER TABLE sku ADD COLUMN sifra VARCHAR(20) NULL DEFAULT NULL; UPDATE podesavanja SET vrednost='7' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "9" and kolona_sifra() == "0", "sa verzije 7 (kolona bez indeksa): takođe uklonjena")
    sql("UPDATE podesavanja SET vrednost='8' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "9", "ponovljena nadogradnja bez kolone: bez greške")
    o = adm.get("/admin/artikal.php?id=%d" % sql_int("SELECT id FROM artikli WHERE naziv='Humovit'"))
    R.provera(o.status == 200 and "Kako piše na spisku" not in o.text, "Podešavanja artikla više nemaju „Kako piše na spisku“")

    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": "", "datum": danas_bg(), "mesto_broj": "8"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m8 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)

    R.odeljak("Izbor artikla: pun naziv, kucanjem")
    o = adm.get(SP + "?id=%d" % sp)
    forma = o.text.split('id="dodaj-%d"' % m8)[1].split("</details>")[0]
    R.provera(forma.count('name="r_tekst[]"') == 3 and forma.count('name="r_id[]"') == 3 and forma.count("data-biraj-ponuda") == 3,
              "forma „Dodaj robu“ ima 3 reda, svaki sa izborom artikla (tekst + izabrano pakovanje)")
    R.provera('value="dodaj_vise"' in forma and "data-roba-jos" in forma and "Sačuvaj robu za Mesto 8" in forma, "dugme „Još jedan artikal“ i jedno čuvanje za sve")
    R.provera("lista-sku" not in o.text and "H50L" not in o.text and "stavka-sifra" not in o.text, "nema šifri ni stare liste za kucanje")
    pod = podaci_za_unos(o)
    R.provera([hum50, "Humovit", "", "50 l", 40, 0, 0] in pod and [bo46, "Beli oblutak", "4-6 cm", "20 kg", 50, 0, 0] in pod and all(len(r) == 7 for r in pod),
              "podaci za izbor: artikal, varijanta, pakovanje, palete, paketi, stanje (administrator)", pod[:3])
    R.provera([r[0] for r in pod if r[1] == "Humovit"] == [hum5, hum10, hum25, hum50], "Humovit redom: 5, 10, 25, 50 l (kao u katalogu)")

    for unos, sku, opis in [("humovit 50", hum50, "humovit 50 → 50 l (a ne 5 l)"), ("humovit 5", hum5, "humovit 5 → 5 l"),
                            ("Humovit · 25 l", hum25, "pun naziv"), ("oblutak beli 40-60", bo46, "kao na papiru (milimetri)"),
                            ("rizla bela sitna", rizla, "rizla bela sitna"), ("b oblutak 4/6", bo46, "b oblutak 4/6"),
                            ("idea 10", idea10, "idea 10"), ("humovit premium 50", hump50, "humovit premium 50")]:
        sql("DELETE FROM spisak_stavke WHERE spisak_id=%d" % sp)
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": unos, "kom": "1"})
        R.provera(stavke(sp) == "%d:1:0:0:1" % sku, "„%s“: %s" % (unos, opis), poruke(o))
    sql("DELETE FROM spisak_stavke WHERE spisak_id=%d" % sp)
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "humovit", "kom": "1"})
    R.provera("„humovit“ – izaberite pakovanje: Humovit · 5 l, Humovit · 10 l, Humovit · 25 l, Humovit · 50 l" in poruke(o) and not stavke(sp),
              "samo „humovit“: traži izbor pakovanja (5, 10, 25, 50 l)", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "nešto treće", "sku_id": hum25, "kom": "1"})
    R.provera(stavke(sp) == "%d:1:0:0:1" % hum25, "izabrano pakovanje (id) ima prednost nad otkucanim")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "qqq9", "kom": "1"})
    R.provera("Nema artikla „qqq9“" in poruke(o), "nepoznato: odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "artikal": "Cmana supstrat 10 l", "kom": "1"})
    R.provera("je isključen" in poruke(o), "isključen artikal: odbijeno", poruke(o))
    sql("DELETE FROM spisak_stavke WHERE spisak_id=%d" % sp)

    R.odeljak("Više artikala odjednom")
    o = adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": "0",
                      "r_tekst[]": ["Humovit · 50 l", "idea 10", "rizla bela sitna", ""], "r_id[]": [str(hum50), "", "", ""],
                      "r_pal[]": ["2", "", "", ""], "r_pak[]": ["", "3", "", ""], "r_kom[]": ["", "", "10", ""]})
    R.provera(stavke(sp) == "%d:80:2:0:0,%d:15:0:3:0,%d:10:0:0:10" % (hum50, idea10, rizla), "tri artikla jednim čuvanjem (2 pal, 3 pak, 10 kom); prazan red se preskače", stavke(sp))
    R.provera("Dodato za Mesto 8: Humovit · 50 l – 80 kom, Idea · 10 l – 15 kom, Bela rizla · Sitnija · 20 kg – 10 kom" in poruke(o), "jedna poruka za sve", poruke(o))
    R.provera(o.url.endswith("&m=%d#mesto-%d" % (m8, m8)), "posle čuvanja stranica pokazuje to mesto", o.url)
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Spisak #%d: dodato za Mesto 8: Humovit · 50 l – 80 kom, Idea%%'" % sp) == 1, "u dnevniku je jedan zapis")
    pre = stavke(sp)
    o = adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": "0",
                      "r_tekst[]": ["humovit 5", "qqq9", ""], "r_id[]": ["", "", ""], "r_pal[]": ["1", "", ""], "r_pak[]": ["", "", ""], "r_kom[]": ["", "5", ""]})
    R.provera("Red 2: Nema artikla „qqq9“" in poruke(o) and stavke(sp) == pre, "greška u redu 2: ništa se ne upisuje (ni red 1)", poruke(o))
    R.provera('value="qqq9"' in o.text and 'value="humovit 5"' in o.text and re.search(r'name="r_kom\[\]"[^>]*value="5"', o.text)
              and re.search(r'id="dodaj-%d" open' % m8, o.text), "upisano ostaje u formi, forma je otvorena")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('value="qqq9"' not in o.text, "upisano se pamti samo za jedno otvaranje")
    for redovi, poruka, opis in [
        ({"r_tekst[]": ["humovit"], "r_kom[]": ["5"]}, "Red 1: „humovit“ – izaberite pakovanje", "nejasno: traži pakovanje"),
        ({"r_tekst[]": ["idea 5"], "r_kom[]": [""]}, "Red 1: upišite količinu", "bez količine"),
        ({"r_tekst[]": ["", ""], "r_kom[]": ["", ""]}, "Upišite bar jedan artikal", "prazna forma"),
        ({"r_tekst[]": ["", "idea 5"], "r_kom[]": ["4", "1"]}, "Red 1: Izaberite artikal", "količina bez artikla"),
        ({"r_tekst[]": ["humovit 25"], "r_pak[]": ["2"]}, "nije podešeno koliko komada ima u paketu", "paketi za artikal bez paketa"),
    ]:
        o = adm.post(SP, dict({"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": "0"}, **redovi))
        R.provera(poruka in poruke(o) and stavke(sp) == pre, "odbijeno: " + opis, poruke(o))
    o = adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": "0",
                      "r_tekst[]": ["humovit 10", "Humovit · 10 l", "Humovit · 50 l"], "r_id[]": ["", str(hum10), ""], "r_pal[]": ["1", "", "1"], "r_pak[]": ["", "2", ""], "r_kom[]": ["", "", ""]})
    R.provera(stavke(sp, m8).startswith("%d:40:1:0:0" % hum50) and stavke(sp).endswith("%d:282:1:2:0" % hum10),
              "isti artikal dva puta se sabira (1 pal + 2 pak = 282); postojeći dobija novu količinu", stavke(sp))
    R.provera("bilo je već na spisku – zamenjena količina" in poruke(o), "poruka kaže šta je zamenjeno", poruke(o))

    # u zbirnu paletu: količina za jednu paletu, bez celih paleta
    adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_naziv": "Mešovita", "p_broj": "2"})
    pid = sql_int("SELECT MAX(id) FROM spisak_palete")
    o = adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": pid, "r_tekst[]": ["humovit 5"], "r_pal[]": ["1"], "r_kom[]": [""]})
    R.provera("Red 1: u zbirnu paletu upišite pakete i/ili komade" in poruke(o), "u zbirnu paletu ne idu cele palete", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj_vise", "id": sp, "mesto_id": m8, "paleta_id": pid, "r_tekst[]": ["humovit 5", "idea 5"], "r_pak[]": ["2", ""], "r_kom[]": ["", "6"]})
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid) == "%d:40,%d:12" % (hum5, idea5)
              and o.url.endswith("&u=%d#paleta-%d" % (pid, pid)), "u zbirnu paletu × 2: 2 pak (20) i 6 kom → 40 i 12", poruke(o))
    R.provera("(Mešovita)" in poruke(o), "poruka kaže u koju paletu", poruke(o))

    R.odeljak("Zbirna paleta: izbor artikla u redovima")
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "p_naziv": "",
                      "z_artikal[]": ["rizla bela sitna", "bilo šta", ""], "z_id[]": ["", str(bo46), ""], "z_kol[]": ["1/2", "pola", ""]})
    pid2 = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % pid2) == "%d:25,%d:25" % (rizla, bo46),
              "1/2 rizla bela sitna + pola oblutka (izabrano dodirom)", poruke(o))
    R.provera("Bela rizla · Sitnija · 20 kg 25 + Beli oblutak · 4-6 cm · 20 kg 25 kom" in poruke(o), "poruka punim nazivima", poruke(o))
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_broj": "1", "z_artikal[]": ["oblutak"], "z_id[]": [""], "z_kol[]": ["1/2"]})
    R.provera("izaberite pakovanje" in poruke(o) and sql_int("SELECT MAX(id) FROM spisak_palete") == pid2 and 'value="oblutak"' in o.text,
              "nejasno („oblutak“): ništa se ne pravi, upisano ostaje", poruke(o))

    R.odeljak("Prikaz punim nazivom")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('<strong class="stavka-naziv">Humovit · 50 l</strong>' in o.text and '<strong class="stavka-naziv">Bela rizla · Sitnija · 20 kg</strong>' in o.text,
              "spisak: pun naziv proizvoda")
    R.provera('href="/admin/spisak_papir.php?id=%d"' % sp in o.text, "veza „Spisak sa papira“ na nacrtu")
    adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    R.provera("<strong>Humovit · 50 l</strong>" in o.text and 'name="novi_artikal"' in o.text and 'name="novi_sku"' in o.text and "data-biraj" in o.text,
              "radnik: pun naziv; dodavanje sa izborom pakovanja")
    pod = podaci_za_unos(o)
    R.provera(pod and all(len(r) == 6 for r in pod) and [hum50, "Humovit", "", "50 l", 40, 0] in pod, "radnik ne dobija stanje u podacima za izbor")
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "humovit", "novi_sku": "", "novi_kom": "4"})
    R.provera("izaberite pakovanje" in poruke(o), "radnik: samo „humovit“ traži pakovanje", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "Humovit · 25 l", "novi_sku": str(hum25), "novi_kom": "4"})
    R.provera("Dodato na spisak za Mesto 8: Humovit · 25 l – 4 kom" in poruke(o), "radnik dodaje izabrano pakovanje", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m8, "novi_artikal": "oblutak beli 60-100", "novi_kom": "3"})
    R.provera("Beli oblutak · 6-10 cm · 20 kg – 3 kom" in poruke(o), "radnik: otkucano kao na papiru", poruke(o))
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp)
    R.provera("Humovit · 50 l" in tekst(o.text) and "stampa-ime" not in o.text, "štampa: pun naziv")
    o = adm.get(PAPIR + "?id=%d" % sp)
    R.provera("samo dok je spisak nacrt" in poruke(o) and o.url.endswith(SP + "?id=%d" % sp), "spisak sa papira: samo za nacrt", o.url)

    R.odeljak("Spisak sa papira: tekst → pregled")
    adm.post("/admin/utovar.php", {"kupac": "", "datum": danas_bg(), "mesto_broj": "8"})
    sp2 = sql_int("SELECT MAX(id) FROM spiskovi")
    p8 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp2)
    adm.post(SP, {"akcija": "dodaj", "id": sp2, "mesto_id": p8, "paleta_id": "0", "sku_id": hum50, "kom": "1"})
    o = adm.get(PAPIR + "?id=%d" % sp2)
    R.provera('name="tekst"' in o.text and 'value="procitaj"' in o.text and "izdiktirajte" in o.text, "polje za tekst (prepisano ili izdiktirano)")
    papir = ("8 Agrocentar\n"
             "2 pal humovit 50\n"
             "humovit 10 - 30\n"
             "3 pak idea 5\n"
             "1/2 rizla bela sitna + 1/2 oblutak beli 40-60\n"
             "\n"
             "(9)\n"
             "Novi Sad, Futoški put 12\n"
             "jedna paleta idea 10\n"
             "2 t oblutak beli 4-6\n"
             "humovit\n"
             "saksije 30 kom\n")
    o = adm.post(PAPIR, {"akcija": "procitaj", "id": sp2, "tekst": papir, "bez_oznake": "kom"})
    R.provera('id="pregled"' in o.text and "Proverite šta je pročitano" in o.text and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sp2) == 1,
              "pročitano se prvo pokaže na proveru (ništa još nije upisano)")
    f = polja_forme(o.text, "pregled")
    R.provera((f["m[0][broj]"], f["m[0][kupac]"], f["m[1][broj]"], f["m[1][adresa]"]) == ("8", "Agrocentar", "9", "Novi Sad, Futoški put 12"),
              "mesta: 8 Agrocentar i (9) sa adresom", (f.get("m[0][broj]"), f.get("m[0][kupac]"), f.get("m[1][broj]"), f.get("m[1][adresa]")))
    R.provera((f["r[0][0][tekst]"], f["r[0][0][id]"], f["r[0][0][pal]"]) == ("Humovit · 50 l", str(hum50), "2"), "„2 pal humovit 50“ → Humovit · 50 l, 2 palete")
    R.provera((f["r[0][1][id]"], f["r[0][1][pak]"], f["r[0][1][kom]"]) == (str(hum10), "30", "") and (f["r[0][2][id]"], f["r[0][2][pak]"]) == (str(idea5), "3"),
              "„humovit 10 - 30“ → 30 paketa (5 i 10 l se pišu u paketima); „3 pak idea 5“ → 3 paketa")
    R.provera("30 paketa (5 i 10 l se pišu u paketima) = 180 kom" in tekst(o.text), "napomena: broj za 10 l je u paketima")
    R.provera((f["r[0][3][vrsta]"], f["r[0][3][d][0][id]"], f["r[0][3][d][0][kol]"], f["r[0][3][d][1][id]"], f["r[0][3][d][1][kol]"]) == ("zbirna", str(rizla), "1/2", str(bo46), "1/2"),
              "„1/2 rizla bela sitna + 1/2 oblutak beli 40-60“ → zbirna paleta, pola + pola")
    R.provera((f["r[1][0][id]"], f["r[1][0][pal]"]) == (str(idea10), "1") and (f["r[1][1][id]"], f["r[1][1][kom]"]) == (str(bo46), "100"),
              "„jedna paleta idea 10“ → 1 paleta; „2 t oblutak beli 4-6“ → 100 kom (20 kg)")
    R.provera("2 t ≈ 100 kom" in tekst(o.text) and "sa papira" not in f, "napomena: kako je preračunato")
    R.provera(f["r[1][2][id]"] == "" and f["r[1][3][id]"] == "" and "Proverite označeno (2)" in tekst(o.text) and o.text.count("papir-proveri") == 2,
              "„humovit“ (bez pakovanja i količine) i „saksije“ su označeni za proveru", tekst(o.text)[:300])

    R.odeljak("Spisak sa papira: provera i čuvanje")
    o = adm.post(PAPIR, dict(f, akcija="sacuvaj"))
    R.provera("Proverite označene redove (2)" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sp2) == 1 and stavke(sp2) == "%d:1:0:0:1" % hum50,
              "neispravni redovi: ništa se ne upisuje", poruke(o))
    R.provera(o.text.count("papir-greska") >= 2 and "izaberite pakovanje" in tekst(o.text) and "Nema artikla „saksije“" in tekst(o.text), "greška stoji uz svoj red")
    f = polja_forme(o.text, "pregled")
    f["r[1][2][id]"] = str(hum25)
    f["r[1][2][kom]"] = "10"
    f["r[1][3][preskoci]"] = "1"
    o = adm.post(PAPIR, dict(f, akcija="sacuvaj"))
    R.provera("Upisan spisak sa papira: Mesto 8 · Agrocentar (5 stavki), Mesto 9 (3 stavke)" in poruke(o), "sačuvano odjednom", poruke(o))
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(m.broj, '|', COALESCE(k.naziv, ''), '|', COALESCE(m.adresa, '')) ORDER BY m.redosled SEPARATOR ';') FROM spisak_mesta m "
                  "LEFT JOIN kupci k ON k.id=m.kupac_id WHERE m.spisak_id=%d" % sp2) == "8|Agrocentar|;9||Novi Sad, Futoški put 12",
              "postojeće Mesto 8 je dopunjeno kupcem, Mesto 9 je novo (sa adresom)")
    p9 = sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d AND broj=9" % sp2)
    R.provera(stavke(sp2, p8) == "%d:80:2:0:0,%d:180:0:30:0,%d:30:0:3:0" % (hum50, hum10, idea5), "Mesto 8: Humovit 50 l (zamenjen 1 → 2 pal), Humovit 10 l 30 pak = 180, Idea 5 l 3 pak", stavke(sp2, p8))
    R.provera(stavke(sp2, p9) == "%d:225:1:0:0,%d:100:0:0:100,%d:10:0:0:10" % (idea10, bo46, hum25), "Mesto 9: Idea 10 l 1 pal, oblutak 100 kom, Humovit 25 l 10 kom (ispravljeno)", stavke(sp2, p9))
    zp = sql_int("SELECT id FROM spisak_palete WHERE spisak_id=%d" % sp2)
    R.provera(sql("SELECT CONCAT(mesto_id, '|', naziv, '|', broj) FROM spisak_palete WHERE id=%d" % zp) == "%d|Zbirna paleta 1|1" % p8
              and sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d" % zp) == "%d:25,%d:25" % (rizla, bo46),
              "zbirna paleta za Mesto 8: 25 rizle + 25 oblutka")
    R.provera(o.url.endswith("&m=%d#mesto-%d" % (p8, p8)), "posle čuvanja: spisak, kod prvog mesta", o.url)
    o = adm.get(PAPIR + "?id=%d" % sp2)
    t = re.search(r'<textarea[^>]*name="tekst"[^>]*>(.*?)</textarea>', o.text, re.S)
    R.provera(t is not None and t.group(1).strip() == "", "posle čuvanja počinje se od praznog teksta")

    R.odeljak("Spisak sa papira: broj bez oznake, odustajanje, pristup")
    o = adm.post(PAPIR, {"akcija": "procitaj", "id": sp2, "tekst": "5\nidea 10 - 2\n30 kom humovit 5\n3 humovit 5 + 1/2 rizla bela sitna", "bez_oznake": "kom"})
    f = polja_forme(o.text, "pregled")
    R.provera((f["r[0][0][pak]"], f["r[0][1][kom]"], f["r[0][1][pak]"], f["r[0][2][d][0][kol]"]) == ("2", "30", "", "3 pak"),
              "5 i 10 l bez oznake = paketi (i u zbirnoj paleti); „30 kom“ ostaje komadi", f)
    adm.post(PAPIR, {"akcija": "odustani", "id": sp2})
    o = adm.post(PAPIR, {"akcija": "procitaj", "id": sp2, "tekst": "5\nidea 10 - 2", "bez_oznake": "pal"})
    f = polja_forme(o.text, "pregled")
    R.provera((f.get("m[0][broj]"), f.get("r[0][0][id]"), f.get("r[0][0][pal]"), f.get("r[0][0][kom]")) == ("5", str(idea10), "2", ""), "„broj bez oznake = paleta“: idea 10 - 2 → 2 palete", f)
    o = adm.post(PAPIR, {"akcija": "odustani", "id": sp2})
    R.provera("nije upisan" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sp2) == 2, "odustajanje: ništa se ne upisuje", poruke(o))
    o = adm.post(PAPIR, {"akcija": "procitaj", "id": sp2, "tekst": "   \n  ", "bez_oznake": "kom"})
    R.provera("nema ničega" in poruke(o) and 'name="tekst"' in o.text, "prazan tekst: poruka", poruke(o))
    o = adm.post(PAPIR, {"akcija": "procitaj", "id": sp2, "tekst": "1 pal humovit 50\n", "bez_oznake": "kom"})
    f = polja_forme(o.text, "pregled")
    R.provera(f.get("m[0][broj]") == "10", "roba bez broja mesta ide na sledeći slobodan broj (10)", f.get("m[0][broj]"))
    f["m[0][broj]"] = "abc"
    o = adm.post(PAPIR, dict(f, akcija="sacuvaj"))
    R.provera("Proverite označene redove (1)" in poruke(o) and "od 1 do 999" in tekst(o.text), "neispravan broj mesta: odbijeno", poruke(o))
    adm.post(PAPIR, {"akcija": "odustani", "id": sp2})
    o = jelena.get(PAPIR + "?id=%d" % sp2)
    R.provera('name="tekst"' not in o.text and "Proverite šta je pročitano" not in o.text, "radnik nema pristup")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
