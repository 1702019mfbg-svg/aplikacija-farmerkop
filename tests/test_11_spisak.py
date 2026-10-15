#!/usr/bin/env python3
"""Spisak za utovar – faza 2 (administrator): pravljenje spiska, stavke (kombinovane jedinice i „ostalo“),
zbir paleta i težine, upozorenje kad nema dovoljno slobodne robe, statusi, rezervacija na Stanju."""
import html as htmllib
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def stavke(sp):
    return sql("SELECT IFNULL(sku_id,'NULL'), IFNULL(naziv,''), kolicina, palete, paketi, komadi FROM spisak_stavke WHERE spisak_id=%d ORDER BY id" % sp)


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum5, hum10, hum50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 50)
    obl13 = sku_id("Beli oblutak", 20, "kg", "1-3 cm")
    perlit5, cmana10 = sku_id("Perlit", 5), sku_id("Cmana supstrat", 10)
    for sku, kol, nacin in [(hum10, "2", "palete"), (hum5, "100", "komadi"), (obl13, "3", "palete")]:
        jelena.get("/radnik/index.php")
        jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})
    stanje_pre = {s: stanje(s) for s in (hum5, hum10, obl13)}
    R.provera(stanje_pre == {hum5: 100, hum10: 540, obl13: 150}, "priprema: Humovit 5 l = 100, 10 l = 540, oblutak 1-3 = 150", stanje_pre)

    R.odeljak("Okvirne težine i paleta rizle (verzija 4)")
    tez = dict(r.split("\t") for r in sql(
        "SELECT CONCAT(a.naziv,' ',p.kolicina+0,' ',p.jedinica), IFNULL(s.tezina_kg,'NULL') FROM sku s JOIN artikli a ON a.id=s.artikal_id "
        "JOIN pakovanja p ON p.id=s.pakovanje_id WHERE s.varijanta_id=0 OR a.naziv='Malč Farmerkop'").split("\n"))
    tez = {re.sub(r"(\d+)\.00 ", r"\1 ", k): v for k, v in tez.items()}
    R.provera(tez["Humovit 5 l"] == "1.80" and tez["Humovit 10 l"] == "3.60" and tez["Humovit 50 l"] == "18.00" and tez["Idea 25 l"] == "9.00", "zemlja: 5 l = 1,8 kg i srazmerno (10 l = 3,6; 25 l = 9; 50 l = 18)", tez)
    R.provera(tez["Humovit premium 20 l"] == "7.20" and tez["Cmana supstrat 10 l"] == "3.60", "i ostale zemlje (premium 20 l = 7,2; Cmana 10 l = 3,6)")
    R.provera(tez["Malč Farmerkop 50 l"] == "15.00", "malč 50 l = 15 kg")
    R.provera(tez["Perlit 100 l"] == "7.00" and tez["Perlit 5 l"] == "0.35", "perlit 100 l = 7 kg (5 l = 0,35)")
    R.provera(tez["Keramzit 35 l"] == "7.00" and tez["Keramzit 5 l"] == "1.00", "keramzit 35 l = 7 kg (5 l = 1)")
    R.provera(sql("SELECT COUNT(*) FROM sku s JOIN artikli a ON a.id=s.artikal_id WHERE a.naziv LIKE '%rizla' AND s.po_paleti=50") == "6", "rizla: 50 komada po paleti")
    R.provera(sql("SELECT IFNULL(tezina_kg,'NULL') FROM sku WHERE id=%d" % obl13) == "NULL", "oblutak (20 kg) nema upisanu težinu – uzima se 20 kg")

    R.odeljak("Navigacija i pristup")
    o = adm.get("/admin/stanje.php")
    nav = o.text[o.text.index('<nav class="donja-nav"'):]
    R.provera("admin/utovar.php" in nav and "<span>Utovar</span>" in nav and "admin/prodaja.php" not in nav, "u donjoj traci je Utovar (umesto Prodaja)")
    o = adm.get("/admin/utovar.php")
    R.provera("Novi spisak za utovar" in o.text and "<details class=\"kartica\" open" in o.text, "prazan Utovar: forma za novi spisak je otvorena")
    R.provera("admin/prodaja.php" in o.text and "Ručna prodaja" in o.text, "ručna prodaja (izuzetak) je dostupna sa stranice Utovar")
    o = jelena.get("/admin/utovar.php")
    R.provera("/radnik/" in o.url, "radnik ne vidi spiskove administratora")
    R.provera(adm.get("/admin/spisak.php?id=999").status == 404, "nepostojeći spisak: 404")
    o = adm.post("/admin/utovar.php", {"kupac": "X", "datum": "2026-10-20"}, csrf=False, slediti=False)
    R.provera(o.status == 403, "bez CSRF žetona: 403")

    R.odeljak("Novi spisak")
    o = adm.post("/admin/utovar.php", {"kupac": "", "datum": "2026-10-20"})
    R.provera("Upišite ili izaberite kupca" in poruke(o) and sql("SELECT COUNT(*) FROM spiskovi") == "0", "bez kupca: odbijeno")
    o = adm.post("/admin/utovar.php", {"kupac": "Agrocentar", "datum": "2026-02-31"})
    R.provera("datum" in poruke(o) and 'value="Agrocentar"' in o.text, "neispravan datum: odbijeno, upisano ostaje u formi")
    danas = sql("SELECT CURDATE()")
    o = adm.post("/admin/utovar.php", {"kupac": "Agrocentar <b>NS</b>", "datum": danas, "vozilo": "NS 123-AB", "vozac": "Pera", "napomena": "otpremnica 55"})
    sp1 = sql_int("SELECT MAX(id) FROM spiskovi")
    R.provera(o.url.endswith("/admin/spisak.php?id=%d" % sp1) and "Spisak #%d je napravljen" % sp1 in poruke(o), "spisak je napravljen i otvoren")
    R.provera(sql("SELECT status, vozilo, vozac, napomena FROM spiskovi WHERE id=%d" % sp1) == "nacrt\tNS 123-AB\tPera\totpremnica 55", "status nacrt, vozilo, vozač, napomena")
    R.provera(sql_int("SELECT COUNT(*) FROM kupci WHERE naziv='Agrocentar <b>NS</b>'") == 1, "novi kupac je zapamćen")
    R.provera("Agrocentar &lt;b&gt;NS&lt;/b&gt;" in o.text and "<b>NS</b>" not in o.text, "naziv kupca se ispisuje bezbedno")

    R.odeljak("Stavke: kombinovan unos")
    SP = "/admin/spisak.php"
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": hum10, "pal": "1", "pak": "2", "kom": "3"})
    R.provera(stavke(sp1) == "%d\t\t285\t1\t2\t3" % hum10, "1 paleta + 2 paketa + 3 komada Humovita 10 l = 270 + 12 + 3 = 285", stavke(sp1))
    R.provera("1 pal + 2 pak + 3 kom = 285 kom" in tekst(o.text), "spisak prikazuje unos i ukupno komada")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": perlit5, "pal": "1"})
    R.provera("nije podešeno koliko komada ide na paletu" in poruke(o), "palete za artikal bez palete: odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": hum10, "pal": "", "pak": "", "kom": ""})
    R.provera("Upišite količinu" in poruke(o), "bez količine: odbijeno")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": cmana10, "kom": "5"})
    R.provera("Izaberite artikal" in poruke(o), "isključen artikal (Cmana) ne može na spisak")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": hum10, "pal": "1", "kom": "15"})
    R.provera("zamenjena novom količinom" in poruke(o) and stavke(sp1) == "%d\t\t285\t1\t0\t15" % hum10, "isti artikal ponovo: zamenjuje količinu (ne duplira)", stavke(sp1))
    adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": obl13, "pal": "2"})
    adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": perlit5, "pak": "3"})
    R.provera(sql_int("SELECT kolicina FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp1, perlit5)) == 60, "perlit 5 l: 3 paketa × 20 = 60")

    R.odeljak("Stavke: ostalo (roba koja se ne vodi)")
    o = adm.post(SP, {"akcija": "ostalo", "id": sp1, "o_naziv": "Saksije <i>30</i>", "o_kolicina": "40", "o_jedinica": "kom", "o_kg": "0,8"})
    R.provera(sql("SELECT naziv, jedinica, tezina_kg, kolicina FROM spisak_stavke WHERE spisak_id=%d AND sku_id IS NULL" % sp1) == "Saksije <i>30</i>\tkom\t0.80\t40", "ostalo: naziv, jedinica, kg po jedinici, količina")
    R.provera("ostalo – ne vodi se na stanju" in o.text and "<i>30</i>" not in o.text, "ostalo je označeno i bezbedno ispisano")
    for polja, poruka in [({"o_naziv": "", "o_kolicina": "1"}, "naziv"), ({"o_naziv": "Kanta", "o_kolicina": "0"}, "Količina mora"), ({"o_naziv": "Kanta", "o_kolicina": "2", "o_kg": "abc"}, "Težina mora")]:
        o = adm.post(SP, dict({"akcija": "ostalo", "id": sp1}, **polja))
        R.provera(poruka in poruke(o), "ostalo – odbijeno: %s" % poruka, poruke(o))

    R.odeljak("Zbir: palete i težina")
    o = adm.get(SP + "?id=%d" % sp1)
    t = tekst(o.text)
    # palete: 285/270 + 100/50 = 3,06; perlit nema paletu. kg: 285*3,6 + 100*20 + 60*0,35 + 40*0,8 = 1026 + 2000 + 21 + 32 = 3079
    R.provera("3,1 paleta (+1 bez palete)" in t, "ukupno ≈ 3,1 paleta (perlit bez palete se navodi posebno)", re.search(r"\d+ stavki.{0,80}", t))
    R.provera("3.079 kg težina" in t, "ukupna težina 3.079 kg (zemlja po kg/kom, oblutak 20 kg, perlit, ostalo)", re.search(r"paleta.{0,80}", t))
    o = adm.get("/admin/utovar.php")
    R.provera("Agrocentar" in o.text and "4 stavke" in tekst(o.text) and "3.079 kg" in tekst(o.text), "lista spiskova prikazuje kupca, broj stavki i težinu")

    R.odeljak("Upozorenje kad nema dovoljno slobodne robe")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": hum5, "kom": "150"})
    R.provera("Upozorenje:" in poruke(o) and "slobodno je samo 100 kom" in tekst(o.text), "150 kom Humovita 5 l, a ima 100: upozorenje")
    R.provera("slobodno je samo 0 kom" in tekst(o.text), "i perlit, kog nema na stanju, ima upozorenje")
    hum5_st = sql_int("SELECT id FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp1, hum5))
    o = adm.post(SP, {"akcija": "stavke", "id": sp1, "kom[%d]" % hum5_st: "80"})
    R.provera("Izmenjene količine" in poruke(o) and "slobodno je samo 100" not in tekst(o.text) and sql_int("SELECT kolicina FROM spisak_stavke WHERE id=%d" % hum5_st) == 80, "izmena na 80: upozorenje za Humovit 5 l nestaje")
    o = adm.post(SP, {"akcija": "stavke", "id": sp1, "kom[%d]" % hum5_st: "0"})
    R.provera("ne može biti 0" in poruke(o) and sql_int("SELECT kolicina FROM spisak_stavke WHERE id=%d" % hum5_st) == 80, "količina 0 se odbija (za brisanje je Ukloni)")
    o = adm.post(SP, {"akcija": "stavke", "id": sp1})
    R.provera("Nema izmena" in poruke(o), "čuvanje bez izmena: poruka „Nema izmena“")
    o = adm.post(SP, {"id": sp1, "ukloni": str(hum5_st)})
    R.provera("Uklonjena stavka Humovit · 5 l" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE id=%d" % hum5_st) == 0, "stavka je uklonjena")

    R.odeljak("Statusi i rezervacija")
    R.provera({s: stanje(s) for s in stanje_pre} == stanje_pre, "dok je nacrt, stanje se ne dira")
    o = adm.get("/admin/stanje.php")
    R.provera("rezervisano" not in o.text, "nacrt ništa ne rezerviše")
    o = adm.post(SP, {"akcija": "status", "id": sp1, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp1) == "za_utovar" and "poslat na utovar" in poruke(o), "nacrt → Za utovar")
    o = adm.get("/admin/stanje.php")
    blok = o.text[re.search(r"<h3>\s*Humovit\s*<", o.text).start():]
    b10 = tekst(blok[blok.index(">10 l<"):][:1500])
    R.provera("rezervisano 285" in b10 and "slobodno 255" in b10, "Stanje: Humovit 10 l rezervisano 285, slobodno 255", b10[:200])
    R.provera({s: stanje(s) for s in stanje_pre} == stanje_pre, "Za utovar ne skida stanje")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp1, "sku_id": hum5, "kom": "5"})
    R.provera("samo dok je nacrt" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp1, hum5)) == 0, "spisak Za utovar ne može da se menja")
    o = adm.post(SP, {"akcija": "status", "id": sp1, "ocekivan": "nacrt", "novi": "otkazano"})
    R.provera("u međuvremenu promenjen" in poruke(o) and sql("SELECT status FROM spiskovi WHERE id=%d" % sp1) == "za_utovar", "zastarela forma (druga kartica) ne menja status")
    o = adm.post(SP, {"akcija": "status", "id": sp1, "ocekivan": "za_utovar", "novi": "utovareno"})
    R.provera("nije moguća" in poruke(o) and sql("SELECT status FROM spiskovi WHERE id=%d" % sp1) == "za_utovar", "administrator ne može da označi „utovareno“ bez potvrde radnika")

    # drugi spisak za isti artikal: slobodno je samo 255
    adm.post("/admin/utovar.php", {"kupac": "Cvećara Žika", "datum": danas})
    sp2 = sql_int("SELECT MAX(id) FROM spiskovi")
    o = adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera("nema nijednu stavku" in poruke(o), "prazan spisak ne može na utovar")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp2, "sku_id": hum10, "pal": "1", "pak": "5"})
    R.provera("slobodno je samo 255 kom" in tekst(o.text), "drugi spisak: 300 kom, a slobodno je 255 (285 rezervisano na prvom)")
    o = adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp2) == "za_utovar", "upozorenje ne zabranjuje slanje na utovar")
    o = adm.get("/admin/stanje.php")
    blok = o.text[re.search(r"<h3>\s*Humovit\s*<", o.text).start():]
    b10 = blok[blok.index(">10 l<"):][:1500]
    R.provera("rezervisano 585" in tekst(b10) and "tekst-crveno" in b10 and "−45" in tekst(b10), "Stanje: rezervisano 585, slobodno −45 (crveno)", tekst(b10)[:200])

    o = adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "za_utovar", "novi": "nacrt"})
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp2) == "nacrt" and "vraćen u nacrt" in poruke(o), "Za utovar → nacrt (za izmene)")
    o = adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "nacrt", "novi": "otkazano"})
    R.provera(sql("SELECT status, otkazao_id IS NOT NULL FROM spiskovi WHERE id=%d" % sp2) == "otkazano\t1", "nacrt → otkazano (pamti se ko je otkazao)")
    o = adm.get(SP + "?id=%d" % sp2)
    R.provera("Otkazao" in o.text and "Vrati u nacrt" in o.text, "otkazan spisak može ponovo da se otvori")
    o = adm.post(SP, {"akcija": "status", "id": sp2, "ocekivan": "otkazano", "novi": "nacrt"})
    R.provera(sql("SELECT status, otkazao_id IS NULL FROM spiskovi WHERE id=%d" % sp2) == "nacrt\t1", "otkazano → nacrt")

    o = adm.get("/admin/utovar.php")
    R.provera("Aktivni (2)" in tekst(o.text) and "Otkazano (0)" in tekst(o.text), "filteri spiskova sa brojevima")
    o = adm.get("/admin/utovar.php?f=otkazano")
    R.provera("Nema spiskova" in o.text, "filter Otkazano: prazno")

    R.odeljak("Ručna prodaja kupcu koji danas ima spisak")
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Agrocentar <b>NS</b>", "sku_id": hum10, "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("Prodaja sačuvana" in poruke(o) and "danas ima spisak za utovar #%d" % sp1 in poruke(o), "prodaja je sačuvana, uz upozorenje o mogućem duplom skidanju", poruke(o))
    o = adm.post("/admin/prodaja.php", {"kupac": "Neki drugi", "sku_id": hum10, "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("danas ima spisak" not in poruke(o), "kupac bez spiska: bez upozorenja")
    o = adm.get("/admin/prodaja.php")
    R.provera('aria-current="page"' in o.text and 'href="/admin/utovar.php" class="nav-stavka aktivna"' in o.text, "ručna prodaja je pod karticom Utovar")

    R.odeljak("Dnevnik izmena")
    d = sql("SELECT GROUP_CONCAT(detalji SEPARATOR ' | ') FROM dnevnik WHERE akcija='spisak'")
    R.provera("Spisak #%d: napravljen" % sp1 in d and "dodato Humovit · 10 l" in d and "poslat na utovar" in d and "otkazan" in d and "uklonjena stavka" in d, "sve promene spiskova su u dnevniku", d[:400])
    o = adm.get("/admin/dnevnik.php")
    R.provera("Spisak za utovar" in o.text, "Dnevnik izmena prikazuje vrstu „Spisak za utovar“")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
