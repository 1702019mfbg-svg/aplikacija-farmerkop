#!/usr/bin/env python3
"""Faza 2: ekran radnika – proizvodnja, kućna prodaja, palete, brisanje u roku od 10 minuta."""
import concurrent.futures
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
try:
    ids = pripremi_sajt(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    marko = prijavi_radnika(site, ids["Marko"], "1234")

    def unesi(c, sku, kol, nacin="komadi", strana="/radnik/index.php", kljuc=None, napomena=None, slediti=True):
        """Pošalje formu kao pregledač: prvo otvori stranicu (da dobije žeton), pa pošalje POST."""
        c.get(strana)
        podaci = {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": kljuc or nov_kljuc()}
        if napomena is not None:
            podaci["napomena"] = napomena
        return c.post(strana, podaci, slediti=slediti)

    def broj_unosa(uslov="1=1"):
        return sql_int("SELECT COUNT(*) FROM unosi WHERE %s" % uslov)

    # ── 1. Ekran ─────────────────────────────────────────────────────────────
    R.odeljak("Ekran Proizvodnja")
    o = jelena.get("/radnik/index.php")
    R.provera(o.status == 200 and "Novi unos" in o.text, "ekran se otvara")
    R.provera("Moji današnji unosi" in o.text and "Danas još nema unosa" in o.text, "lista današnjih unosa je prazna na početku")
    R.provera("data-sacuvaj" in o.text and "data-delta=\"-10\"" in o.text and "data-delta=\"10\"" in o.text and "data-delta=\"-1\"" in o.text and "data-delta=\"1\"" in o.text, "ima dugmiće −10, −1, +1, +10 i dugme za čuvanje")
    R.provera('name="kolicina"' in o.text, "ima polje za upis količine")
    R.provera('data-nacin-vrednost="palete"' in o.text and 'data-nacin-vrednost="komadi"' in o.text, "ima izbor: palete / komadi")
    kat = re.search(r'<script type="application/json" data-katalog>(.*?)</script>', o.text, re.S)
    import json
    katalog = json.loads(kat.group(1)) if kat else {}
    nazivi = [a["naziv"] for k in katalog.get("kategorije", []) for a in k["artikli"]]
    R.provera(nazivi == ["Humovit", "Humovit premium", "Floris Savacoop", "Idea", "Malč Farmerkop", "Malč Floris Savacoop", "Beli oblutak"], "u izboru su svi aktivni artikli, bez Cmane", nazivi)
    idea = next(a for k in katalog["kategorije"] for a in k["artikli"] if a["naziv"] == "Idea")
    po_idea = {s["p"]: s["po"] for s in idea["sku"]}
    R.provera(po_idea == {"5 l": 450, "10 l": 225, "20 l": 120, "25 l": 120}, "Idea: 5 l=450, 10 l=225, 20 l=120, 25 l=120 komada na paleti", po_idea)
    hum = next(a for k in katalog["kategorije"] for a in k["artikli"] if a["naziv"] == "Humovit")
    R.provera({s["p"]: s["po"] for s in hum["sku"]} == {"5 l": 450, "10 l": 270, "25 l": 120, "50 l": 40}, "Humovit: 5 l=450, 10 l=270, 25 l=120, 50 l=40")
    malc = next(a for k in katalog["kategorije"] for a in k["artikli"] if a["naziv"] == "Malč Farmerkop")
    R.provera(malc["nv"] == "Boja" and [v["naziv"] for v in malc["varijante"]] == ["Crveni", "Braon", "Žuti", "Crni", "Narandžasti", "Zeleni", "Neobojeni"], "malč nudi izbor boje")
    obl = next(a for k in katalog["kategorije"] for a in k["artikli"] if a["naziv"] == "Beli oblutak")
    R.provera(obl["nv"] == "Granulacija" and len(obl["varijante"]) == 5 and all(s["po"] == 50 for s in obl["sku"]), "oblutak: 5 granulacija, 50 komada na paleti")
    R.provera('"st"' not in kat.group(1), "radnik u podacima nema stanje (st)")

    # ── 2. Unos komada ───────────────────────────────────────────────────────
    R.odeljak("Unos komada")
    premium20 = sku_id("Humovit premium", 20)
    o = unesi(jelena, premium20, "35")
    R.provera("Sačuvano: Humovit premium · 20 l – 35 kom" in o.text, "poruka o uspešnom čuvanju", re.findall(r'poruka[^>]*>([^<]*)', o.text))
    red = sql("SELECT tip, kolicina, IFNULL(palete,'NULL'), korisnik_id FROM unosi ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(red == ["proizvodnja", "35", "NULL", str(ids["Jelena"])], "upisano: proizvodnja, 35 kom, bez paleta, radnik Jelena", red)
    R.provera("Humovit premium · 20 l" in o.text and ">35 kom<" in o.text, "unos je u listi današnjih unosa")
    R.provera('<span class="broj">35</span>' in o.text, "ukupno za danas = 35")
    R.provera('data-sku="%d"' % premium20 in o.text, "posle čuvanja isti artikal ostaje izabran")

    # ── 3. Unos paleta ───────────────────────────────────────────────────────
    R.odeljak("Unos paleta")
    hum5 = sku_id("Humovit", 5)
    o = unesi(jelena, hum5, "2", nacin="palete")
    R.provera("900 kom (2 palete)" in o.text, "2 palete Humovita 5 l = 900 komada", re.findall(r'poruka[^>]*>([^<]*)', o.text))
    red = sql("SELECT kolicina, palete FROM unosi ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(red == ["900", "2"], "u bazi: 900 komada, 2 palete", red)
    idea10 = sku_id("Idea", 10)
    o = unesi(jelena, idea10, "1", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "225", "Idea 10 l: 1 paleta = 225 komada (ne 270)")
    hum10 = sku_id("Humovit", 10)
    unesi(jelena, hum10, "1", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "270", "Humovit 10 l: 1 paleta = 270 komada")
    hum25 = sku_id("Humovit", 25)
    unesi(jelena, hum25, "1", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "120", "Humovit 25 l: 1 paleta = 120 komada")
    hum50 = sku_id("Humovit", 50)
    unesi(jelena, hum50, "1", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "40", "Humovit 50 l: 1 paleta = 40 komada")
    malc_crv = sku_id("Malč Farmerkop", 50, "l", "Crveni")
    unesi(jelena, malc_crv, "3", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "120", "Malč 50 l: 3 palete = 120 komada (40 po paleti)")
    obl13 = sku_id("Beli oblutak", 20, "kg", "1-3 cm")
    unesi(jelena, obl13, "2", nacin="palete")
    R.provera(sql("SELECT kolicina FROM unosi ORDER BY id DESC LIMIT 1") == "100", "Beli oblutak 20 kg: 2 palete = 100 komada (50 po paleti)")
    o = unesi(jelena, obl13, "7", nacin="komadi")
    R.provera(sql("SELECT kolicina, IFNULL(palete,'NULL') FROM unosi ORDER BY id DESC LIMIT 1") == "7\tNULL", "isti artikal u komadima: 7 kom, bez paleta")

    # množina reči "paleta"
    for n, tekst in [(1, "1 paleta"), (2, "2 palete"), (5, "5 paleta"), (11, "11 paleta"), (21, "21 paleta"), (22, "22 palete")]:
        o = unesi(jelena, hum50, str(n), nacin="palete")
        R.provera(tekst + ")" in o.text, "množina: %s" % tekst)

    sql("DELETE FROM unosi")
    R.odeljak("Neispravni unosi se odbijaju")
    cekaj = [
        ("količina 0", dict(kol="0")),
        ("negativna količina", dict(kol="-5")),
        ("slova umesto broja", dict(kol="abc")),
        ("prazna količina", dict(kol="")),
        ("decimalna količina", dict(kol="1.5")),
        ("preveliki broj komada", dict(kol="10001")),
        ("preveliki broj paleta (50 pal × 450 = 22500)", dict(kol="50", nacin="palete", sku=hum5)),
        ("bez artikla", dict(sku="")),
        ("nepostojeći artikal", dict(sku="999999")),
        ("artikal koji nije broj", dict(sku="1 OR 1=1")),
        ("isključen artikal (Cmana)", dict(sku=sku_id("Cmana supstrat", 10))),
    ]
    for opis, p in cekaj:
        pre = broj_unosa()
        o = unesi(jelena, p.get("sku", premium20), p.get("kol", "5"), nacin=p.get("nacin", "komadi"))
        R.provera(broj_unosa() == pre and 'poruka-greska' in o.text, "odbijeno: " + opis, re.findall(r'poruka[^>]*>([^<]*)', o.text))
    o = jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": premium20, "kolicina": "5", "nacin": "kilogrami", "kljuc": nov_kljuc()})
    R.provera(broj_unosa() == 1 and sql("SELECT palete FROM unosi") == "NULL", "nepoznat način unosa se tretira kao komadi")
    sql("DELETE FROM unosi")

    sql("UPDATE sku SET po_paleti=NULL WHERE id=%d" % premium20)
    o = unesi(jelena, premium20, "2", nacin="palete")
    R.provera(broj_unosa() == 0 and "nije podešeno koliko komada ide na paletu" in o.text, "paleta za artikal bez podešene palete se odbija")
    sql("UPDATE sku SET po_paleti=120 WHERE id=%d" % premium20)

    # ── 4. Zaštita od duplog slanja ──────────────────────────────────────────
    R.odeljak("Dupli dodir / ponovno slanje")
    k = nov_kljuc()
    unesi(jelena, premium20, "10", kljuc=k)
    o = unesi(jelena, premium20, "10", kljuc=k)
    R.provera(broj_unosa() == 1 and "već sačuvan" in o.text, "ista forma poslata dvaput upisuje samo jedan unos")
    sql("DELETE FROM unosi")
    k = nov_kljuc()
    jelena.get("/radnik/index.php")
    tok = jelena.token

    def posalji(_):
        c = jelena.kopiraj_sesiju()
        return c.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": premium20, "kolicina": "10", "nacin": "komadi", "kljuc": k})

    with concurrent.futures.ThreadPoolExecutor(6) as ex:
        list(ex.map(posalji, range(6)))
    R.provera(broj_unosa() == 1, "6 istovremenih slanja iste forme = 1 unos", broj_unosa())
    sql("DELETE FROM unosi")

    # ── 5. Svako vidi samo svoje ─────────────────────────────────────────────
    R.odeljak("Svaki radnik vidi samo svoje")
    unesi(jelena, premium20, "10")
    unesi(marko, premium20, "77")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 999, %d, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY))" % (premium20, ids["Jelena"]))
    o = jelena.get("/radnik/index.php")
    R.provera(">10 kom<" in o.text and ">77 kom<" not in o.text, "Jelena vidi svoj unos, ne i Markov")
    R.provera('<span class="broj">10</span>' in o.text, "Jelenin ukupan broj za danas je 10 (ne računa tuđe ni stare unose)")
    o = marko.get("/radnik/index.php")
    R.provera(">77 kom<" in o.text and ">10 kom<" not in o.text, "Marko vidi samo svoj unos")
    R.provera(">999 kom<" not in o.text, "unosi od pre dva dana se ne prikazuju")
    sql("DELETE FROM unosi")

    # ── 6. Brisanje poslednjeg unosa u roku od 10 minuta ──────────────────────
    R.odeljak("Brisanje svog poslednjeg unosa")
    unesi(jelena, premium20, "11")
    unesi(jelena, premium20, "22")
    prvi = sql_int("SELECT id FROM unosi WHERE kolicina=11")
    drugi = sql_int("SELECT id FROM unosi WHERE kolicina=22")
    o = jelena.get("/radnik/index.php")
    R.provera(o.text.count('value="obrisi"') == 1 and 'name="id" value="%d"' % drugi in o.text, "dugme Obriši postoji samo uz poslednji unos")
    R.provera("Greška? Možeš da obrišeš još oko" in o.text, "piše koliko je vremena ostalo")

    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": prvi})
    R.provera("više ne može da se obriše" in o.text and broj_unosa("obrisan=0") == 2, "nije dozvoljeno brisanje unosa koji nije poslednji")
    o = marko.post("/radnik/index.php", {"akcija": "obrisi", "id": drugi})
    R.provera(broj_unosa("obrisan=0") == 2, "Marko ne može da obriše Jelenin unos")
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": drugi}, csrf=False)
    R.provera(o.status == 403 and broj_unosa("obrisan=0") == 2, "brisanje bez CSRF žetona je odbijeno")

    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": drugi})
    R.provera("Unos je obrisan" in o.text and broj_unosa("obrisan=0") == 1, "poslednji unos je obrisan")
    R.provera(">22 kom<" not in o.text and ">11 kom<" in o.text, "obrisan unos nestaje iz liste")
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE id=%d AND obrisan=1 AND obrisao_id=%d AND obrisan_u IS NOT NULL" % (drugi, ids["Jelena"])) == 1, "brisanje je 'meko' (ostaje u bazi, sa zabeleženim ko i kad)")
    d = sql("SELECT akcija, objekat, objekat_id, korisnik_id FROM dnevnik ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(d == ["brisanje_radnik", "unos", str(drugi), str(ids["Jelena"])], "brisanje je upisano u dnevnik izmena", d)
    R.provera(stanje(premium20) == 11, "stanje se računa bez obrisanog unosa", stanje(premium20))
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": drugi})
    R.provera("više ne može da se obriše" in o.text, "ponovno brisanje istog unosa ne radi")

    # posle 10 minuta
    sql("UPDATE unosi SET uneto = DATE_SUB(uneto, INTERVAL 11 MINUTE) WHERE id=%d" % prvi)
    o = jelena.get("/radnik/index.php")
    R.provera('value="obrisi"' not in o.text, "posle 10 minuta dugme Obriši nestaje")
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": prvi})
    R.provera("više ne može da se obriše" in o.text and broj_unosa("obrisan=0") == 1, "posle 10 minuta brisanje se odbija i na serveru")
    sql("UPDATE unosi SET uneto = DATE_SUB(uneto, INTERVAL -3 MINUTE) WHERE id=%d" % prvi)   # 8 min stari
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": prvi})
    R.provera(broj_unosa("obrisan=0") == 0, "unos star 8 minuta još može da se obriše")
    sql("DELETE FROM unosi")
    sql("DELETE FROM dnevnik")

    R.odeljak("Brisanje koje bi napravilo negativno stanje")
    unesi(jelena, premium20, "10")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('prodaja', %d, 8, %d, NOW(), NOW())" % (premium20, 1))
    # Jelenin poslednji unos je i dalje onaj od 10 komada (prodaja je upisana od administratora)
    pid = sql_int("SELECT id FROM unosi WHERE tip='proizvodnja'")
    o = jelena.post("/radnik/index.php", {"akcija": "obrisi", "id": pid})
    R.provera(broj_unosa("obrisan=0 AND tip='proizvodnja'") == 1 and "stanje postalo manje od nule" in o.text, "ne može da se obriše proizvodnja čiji su komadi već prodati", re.findall(r'poruka[^>]*>([^<]*)', o.text))
    R.provera(stanje(premium20) == 2, "stanje ostaje nepromenjeno (2)")
    sql("DELETE FROM unosi")

    # ── 7. Kućna prodaja ─────────────────────────────────────────────────────
    R.odeljak("Kućna prodaja")
    KP = "/radnik/prodaja.php"
    o = jelena.get(KP)
    R.provera(o.status == 200 and "Nova prodaja na licu mesta" in o.text and "Napomena" in o.text, "ekran Kućna prodaja se otvara")
    R.provera("Danas još nema prodaje" in o.text, "lista prodaje je prazna")
    R.provera('"st"' not in o.text, "radnik na ekranu prodaje ne vidi stanje")

    unesi(jelena, hum5, "3", nacin="palete")             # 1350 kom stanje
    unesi(marko, hum5, "1", nacin="komadi")              # +1 = 1351
    pre = stanje(hum5)
    R.provera(pre == 1351, "početno stanje 1351", pre)
    o = unesi(jelena, hum5, "20", strana=KP, napomena="komšija Pera, gotovina")
    R.provera("Prodaja sačuvana: Humovit · 5 l – 20 kom" in o.text, "kućna prodaja je sačuvana", re.findall(r'poruka[^>]*>([^<]*)', o.text))
    red = sql("SELECT tip, kolicina, korisnik_id, IFNULL(kupac_id,'NULL'), napomena FROM unosi ORDER BY id DESC LIMIT 1").split("\t")
    R.provera(red == ["kucna_prodaja", "20", str(ids["Jelena"]), "NULL", "komšija Pera, gotovina"], "u bazi: tip kucna_prodaja, napomena sačuvana", red)
    R.provera(stanje(hum5) == pre - 20, "kućna prodaja smanjuje stanje")
    R.provera(">20 kom<" in o.text and "komšija Pera" in o.text, "prodaja je u listi današnje prodaje")
    R.provera('<span class="broj">20</span>' in o.text, "ukupno prodato danas = 20")
    o = jelena.get("/radnik/index.php")
    R.provera(">20 kom<" not in o.text, "kućna prodaja se ne meša sa listom proizvodnje")

    o = unesi(jelena, hum5, "1", nacin="palete", strana=KP)
    R.provera(sql("SELECT kolicina, palete FROM unosi ORDER BY id DESC LIMIT 1") == "450\t1", "kućna prodaja može i po paletama (450 kom)")
    R.provera(stanje(hum5) == pre - 20 - 450, "stanje posle prodaje paleta", stanje(hum5))

    R.odeljak("Prodaja veća od stanja")
    ostalo = stanje(hum5)
    pre_n = broj_unosa()
    o = unesi(jelena, hum5, str(ostalo + 1), strana=KP)
    R.provera(broj_unosa() == pre_n and "Nema dovoljno na stanju" in o.text, "prodaja veća od stanja se odbija", re.findall(r'poruka[^>]*>([^<]*)', o.text))
    bez_tokena = re.sub(r'value="[0-9a-f]{32,64}"', "", re.sub(r'<script.*?</script>', '', o.text, flags=re.S))
    R.provera(str(ostalo) not in " ".join(re.findall(r'poruka[^>]*>([^<]*)', o.text)) and str(ostalo) not in re.sub(r"<[^>]+>", " ", bez_tokena), "poruka ni stranica ne otkrivaju radniku stanje")
    o = unesi(jelena, hum5, str(ostalo), strana=KP)
    R.provera(stanje(hum5) == 0 and "Prodaja sačuvana" in o.text, "može da se proda tačno koliko ima (stanje 0)")
    o = unesi(jelena, hum5, "1", strana=KP)
    R.provera(stanje(hum5) == 0 and "Nema dovoljno na stanju" in o.text, "sa stanjem 0 ništa ne može da se proda")
    o = unesi(jelena, premium20, "1", strana=KP)
    R.provera(stanje(premium20) == 0 and "Nema dovoljno" in o.text, "artikal koji nikad nije proizveden ne može da se proda")

    R.odeljak("Dve istovremene prodaje ne probijaju stanje")
    unesi(jelena, hum25, "10")
    pre_n = broj_unosa("tip='kucna_prodaja'")

    def prodaj(i):
        c = (jelena if i % 2 == 0 else marko).kopiraj_sesiju()
        return c.post(KP, {"akcija": "dodaj", "sku_id": hum25, "kolicina": "8", "nacin": "komadi", "kljuc": nov_kljuc()})

    with concurrent.futures.ThreadPoolExecutor(6) as ex:
        list(ex.map(prodaj, range(6)))
    R.provera(stanje(hum25) == 2, "od 6 istovremenih prodaja po 8 kom (stanje 10) uspela je tačno jedna", stanje(hum25))
    R.provera(broj_unosa("tip='kucna_prodaja'") == pre_n + 1, "upisana je samo jedna prodaja")

    R.odeljak("Brisanje kućne prodaje")
    o = jelena.get(KP)
    R.provera(o.text.count('value="obrisi"') <= 1, "najviše jedno dugme Obriši")
    unesi(marko, hum10, "50")
    unesi(jelena, hum10, "5", strana=KP)
    kp_id = sql_int("SELECT id FROM unosi WHERE tip='kucna_prodaja' AND sku_id=%d" % hum10)
    st_pre = stanje(hum10)
    o = jelena.get(KP)
    R.provera('name="id" value="%d"' % kp_id in o.text, "dugme Obriši je uz poslednju kućnu prodaju")
    o = jelena.post(KP, {"akcija": "obrisi", "id": kp_id})
    R.provera(stanje(hum10) == st_pre + 5 and "Unos je obrisan" in o.text, "brisanje kućne prodaje vraća količinu na stanje")

    # ── 8. Bezbednost ────────────────────────────────────────────────────────
    R.odeljak("Bezbednost ekrana radnika")
    unesi(jelena, hum5, "5")     # roba na stanju, da prodaja može da prođe
    o = unesi(jelena, hum5, "1", strana=KP, napomena="<script>alert(1)</script>")
    R.provera(sql("SELECT napomena FROM unosi ORDER BY id DESC LIMIT 1") == "<script>alert(1)</script>", "napomena sa HTML kodom je sačuvana kao običan tekst")
    o2 = jelena.get(KP)
    R.provera("<script>alert(1)</script>" not in o2.text and "&lt;script&gt;alert(1)&lt;/script&gt;" in o2.text, "napomena sa HTML kodom se u listi ispisuje bezbedno (XSS)")
    o = Client(site.base).post("/radnik/index.php", {"akcija": "dodaj", "sku_id": premium20, "kolicina": "5", "nacin": "komadi"}, csrf=False, slediti=False)
    R.provera(o.status in (302, 403) and broj_unosa("kolicina=5 AND sku_id=%d AND tip='proizvodnja'" % premium20) == 0, "neprijavljen ne može da upisuje")
    adm = prijavi_admina(site)
    o = adm.get("/radnik/index.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/admin/stanje.php"), "administrator je sa radničkog ekrana vraćen na svoj")
    o = jelena.get("/radnik/index.php?s=abc")
    R.provera('data-sku="0"' in o.text, "pogrešan ?s= parametar se ignoriše")
    o = jelena.get("/radnik/index.php?s=%d" % sku_id("Cmana supstrat", 10))
    R.provera('data-sku="0"' in o.text, "?s= sa isključenim artiklom se ignoriše")
    o = jelena.get("/radnik/index.php?s=%d" % hum50)
    R.provera('data-sku="%d"' % hum50 in o.text, "?s= sa važećim artiklom ga unapred bira")

    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
