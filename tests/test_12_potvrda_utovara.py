#!/usr/bin/env python3
"""Spisak za utovar – faze 3 i 4: potvrda utovara (radnik ili administrator), skidanje stanja jednom
nedeljivom operacijom (dupli klik, dva telefona), ispravka količine, minus, otkazivanje utovarenog spiska
(povrat), zaštita unosa sa spiska u Istoriji, štampa / PDF / deljenje."""
import concurrent.futures
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


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def broj_unosa(tip="utovar"):
    return sql_int("SELECT COUNT(*) FROM unosi WHERE tip='%s'" % tip)


def stavke_ids(sp):
    return [int(x) for x in sql("SELECT id FROM spisak_stavke WHERE spisak_id=%d ORDER BY id" % sp).split()]


def napravi_spisak(adm, kupac, stavke, ostalo=None, posalji=True):
    adm.get("/admin/utovar.php")
    adm.post("/admin/utovar.php", {"kupac": kupac, "datum": sql("SELECT CURDATE()"), "vozilo": "NS 1", "vozac": "Pera"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    for sku, polja in stavke:
        adm.post("/admin/spisak.php", dict({"akcija": "dodaj", "id": sp, "sku_id": sku}, **polja))
    if ostalo:
        adm.post("/admin/spisak.php", dict({"akcija": "ostalo", "id": sp}, **ostalo))
    if posalji:
        adm.post("/admin/spisak.php", {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    return sp


def potvrda(c, sp, izmene=None, bez=()):
    """POST potvrde: sve stavke štiklirane (osim `bez`), `izmene` = {stavka_id: {polje: vrednost}}."""
    f = {"id": sp}
    for sid in stavke_ids(sp):
        if sid not in bez:
            f["ok[%d]" % sid] = "1"
        for k, v in (izmene or {}).get(sid, {}).items():
            f["%s[%d]" % (k, sid)] = v
    return c.post("/radnik/utovar.php", f)


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    marko = prijavi_radnika(site, ids["Marko"], "1234")
    hum5, hum10 = sku_id("Humovit", 5), sku_id("Humovit", 10)
    obl13 = sku_id("Beli oblutak", 20, "kg", "1-3 cm")
    for sku, kol, nacin in [(hum10, "2", "palete"), (hum5, "100", "komadi"), (obl13, "3", "palete")]:
        jelena.get("/radnik/index.php")
        jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})
    R.provera((stanje(hum10), stanje(hum5), stanje(obl13)) == (540, 100, 150), "priprema: 540 / 100 / 150")

    sp1 = napravi_spisak(adm, "Agrocentar", [(hum10, {"pal": "1", "pak": "2"}), (hum5, {"kom": "100"})],
                         {"o_naziv": "Saksije", "o_kolicina": "40", "o_jedinica": "kom", "o_kg": "0,8"})
    nacrt = napravi_spisak(adm, "Nacrt d.o.o.", [(obl13, {"pal": "1"})], posalji=False)
    s_hum10, s_hum5, s_ost = stavke_ids(sp1)

    R.odeljak("Radnik: lista spiskova za utovar")
    o = jelena.get("/radnik/index.php")
    R.provera('href="/radnik/utovar.php"' in o.text and "<span>Utovar</span>" in o.text, "radnik ima karticu Utovar")
    o = jelena.get("/radnik/utovar.php")
    R.provera("Agrocentar" in o.text and "Nacrt d.o.o." not in o.text, "radnik vidi samo spiskove „Za utovar“ (ne nacrte)")
    R.provera("rezervisano" not in o.text and "stanje" not in tekst(o.text).lower().replace("stanje se", ""), "radnik ne vidi stanje zaliha")
    o = jelena.get("/radnik/utovar.php?id=%d" % nacrt)
    R.provera("više ne čeka utovar" in poruke(o) and "/radnik/utovar.php" in o.url, "nacrt ne može da se otvori za utovar")
    o = jelena.get("/radnik/utovar.php?id=%d" % sp1)
    R.provera(o.text.count('name="ok[') == 3 and "1 pal + 2 pak = 282 kom" in tekst(o.text), "spisak: 3 stavke za štikliranje, plan 1 pal + 2 pak = 282 kom")
    R.provera(o.text.count(" disabled") >= 3, "polja za drugačiju količinu su isključena dok se ne otvore")

    R.odeljak("Potvrda: sve mora biti štiklirano")
    o = potvrda(jelena, sp1, bez=(s_ost,))
    R.provera("Štiklirajte svaku stavku" in poruke(o) and "Saksije" in poruke(o) and broj_unosa() == 0, "nije štiklirano „Saksije“: ništa se ne upisuje", poruke(o))
    R.provera(o.text.count("checked") == 2, "posle greške štiklirane stavke ostaju štiklirane")
    o = potvrda(jelena, sp1, {s_hum10: {"pal": "x"}})
    R.provera("celi brojevi" in poruke(o) and broj_unosa() == 0, "neispravna količina: ništa se ne upisuje", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp1, "ok[%d]" % s_hum10: "1"}, csrf=False, slediti=False)
    R.provera(o.status == 403 and broj_unosa() == 0, "bez CSRF žetona: 403")

    R.odeljak("Potvrda utovara sa ispravkom količine")
    o = potvrda(jelena, sp1, {s_hum10: {"pal": "1", "pak": "0", "kom": ""}})
    R.provera("Utovar je potvrđen" in poruke(o) and "Humovit · 10 l: 270 umesto 282" in poruke(o), "potvrđeno; prijavljena razlika od plana", poruke(o))
    R.provera((stanje(hum10), stanje(hum5), stanje(obl13)) == (270, 0, 150), "stanje skinuto: Humovit 10 l 540 − 270 = 270, 5 l 100 − 100 = 0")
    R.provera(sql("SELECT status, utovario_id FROM spiskovi WHERE id=%d" % sp1) == "utovareno\t%d" % ids["Jelena"], "spisak je utovaren, pamti se ko je potvrdio")
    R.provera(sql("SELECT GROUP_CONCAT(utovareno ORDER BY id) FROM spisak_stavke WHERE spisak_id=%d" % sp1) == "270,100,40", "utovarene količine po stavkama (i „ostalo“)")
    R.provera(broj_unosa() == 2 and sql("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d AND kupac_id IS NOT NULL" % sp1) == "2", "2 kretanja „utovar“ (ostalo ne dira stanje), vezana za spisak i kupca")
    R.provera(sql("SELECT palete, IFNULL(paketi,'NULL') FROM unosi WHERE tip='utovar' AND sku_id=%d" % hum10) == "1\tNULL", "upisano je i kako je utovareno (1 paleta)")
    R.provera("/radnik/utovar.php" in o.url and "Agrocentar" in o.text and "Danas utovareno" in o.text, "radnik se vraća na listu; spisak je pod „Danas utovareno“")

    R.odeljak("Dupli klik i ponovno slanje")
    o = potvrda(jelena, sp1)
    R.provera("već potvrđen" in poruke(o) and broj_unosa() == 2 and stanje(hum10) == 270, "drugo slanje iste potvrde ne skida stanje ponovo", poruke(o))

    R.odeljak("Dva telefona istovremeno")
    sp3 = napravi_spisak(adm, "Brzi kupac", [(obl13, {"pal": "1"})])
    s_obl = stavke_ids(sp3)[0]
    klijenti = [jelena.kopiraj_sesiju(), marko, jelena.kopiraj_sesiju(), marko.kopiraj_sesiju()]
    for c in klijenti:
        c.get("/radnik/utovar.php?id=%d" % sp3)

    def posalji(c):
        return c.post("/radnik/utovar.php", {"id": sp3, "ok[%d]" % s_obl: "1"}).text

    with concurrent.futures.ThreadPoolExecutor(4) as ex:
        list(ex.map(posalji, klijenti))
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d" % sp3) == 1 and stanje(obl13) == 100, "4 istovremene potvrde: stanje je skinuto tačno jednom (150 − 50 = 100)", (sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d" % sp3), stanje(obl13)))

    R.odeljak("Utovar veći od stanja: dozvoljen, stanje u minusu")
    sp2 = napravi_spisak(adm, "Cvećara", [(hum5, {"kom": "30"}), (hum10, {"kom": "10"})])
    s2_hum5, s2_hum10 = stavke_ids(sp2)
    o = adm.get("/admin/spisak.php?id=%d" % sp2)
    R.provera("slobodno je samo 0 kom" in tekst(o.text), "administrator vidi upozorenje da nema Humovita 5 l")
    o = potvrda(marko, sp2, {s2_hum10: {"pal": "0", "pak": "0", "kom": "0"}})
    R.provera("Utovar je potvrđen" in poruke(o) and stanje(hum5) == -30, "radnik potvrđuje utovar i kad nema na stanju: Humovit 5 l = −30", poruke(o))
    R.provera(sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d AND sku_id=%d" % (sp2, hum10)) == 0 and stanje(hum10) == 270, "stavka sa 0 utovarenih ne skida ništa")
    o = adm.get("/admin/stanje.php")
    R.provera("u minusu" in o.text, "Stanje upozorava na minus")
    d = sql("SELECT detalji FROM dnevnik WHERE akcija='spisak' AND objekat_id=%d ORDER BY id DESC LIMIT 1" % sp2)
    R.provera("utovareno (potvrdio Marko)" in d and "Humovit · 10 l: 0 umesto 10" in d, "dnevnik: ko je potvrdio i razlike od plana", d)

    R.odeljak("Spisak vraćen u nacrt ili izmenjen dok je radnik na njemu")
    sp4 = napravi_spisak(adm, "Kasni kupac", [(obl13, {"pal": "1"})])
    jelena.get("/radnik/utovar.php?id=%d" % sp4)
    adm.post("/admin/spisak.php", {"akcija": "status", "id": sp4, "ocekivan": "za_utovar", "novi": "nacrt"})
    o = potvrda(jelena, sp4)
    R.provera("više ne čeka utovar" in poruke(o) and sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d" % sp4) == 0, "spisak vraćen u nacrt: potvrda se odbija", poruke(o))
    adm.post("/admin/spisak.php", {"akcija": "dodaj", "id": sp4, "sku_id": hum10, "kom": "5"})
    adm.post("/admin/spisak.php", {"akcija": "status", "id": sp4, "ocekivan": "nacrt", "novi": "za_utovar"})
    o = jelena.post("/radnik/utovar.php", {"id": sp4, "ok[%d]" % stavke_ids(sp4)[0]: "1"})
    R.provera("Štiklirajte" in poruke(o) and sql_int("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d" % sp4) == 0, "nova stavka (radnik je nije video) mora da se štiklira")

    R.odeljak("Administrator potvrđuje utovar")
    o = adm.get("/admin/spisak.php?id=%d" % sp4)
    R.provera("radnik/utovar.php?id=%d" % sp4 in o.text, "administrator ima dugme „Potvrdi utovar“")
    o = adm.get("/radnik/utovar.php?id=%d" % sp4)
    R.provera(o.status == 200 and o.text.count('name="ok[') == 2 and 'href="/admin/utovar.php"' in o.text, "administrator otvara potvrdu (sa svojom navigacijom)")
    o = potvrda(adm, sp4)
    R.provera("Utovar je potvrđen" in poruke(o) and o.url.endswith("/admin/spisak.php?id=%d" % sp4), "administrator potvrđuje i vraća se na spisak")

    R.odeljak("Unosi sa spiska u Istoriji")
    ut_id = sql_int("SELECT id FROM unosi WHERE tip='utovar' AND spisak_id=%d AND sku_id=%d" % (sp1, hum10))
    o = adm.get("/admin/istorija.php?od=&do=&tip=utovar")
    R.provera("Spisak #%d" % sp1 in o.text and 'admin/unos.php?id=%d' % ut_id not in o.text, "utovar u Istoriji vodi na spisak, nema Ispravi/Obriši")
    o = adm.post("/admin/istorija.php", {"akcija": "obrisi", "id": ut_id, "nazad": ""})
    R.provera("pripada spisku za utovar" in poruke(o) and sql("SELECT obrisan FROM unosi WHERE id=%d" % ut_id) == "0", "brisanje utovara iz Istorije se odbija")
    o = adm.get("/admin/unos.php?id=%d" % ut_id)
    R.provera(o.url.endswith("/admin/spisak.php?id=%d" % sp1) and "pripada spisku" in poruke(o), "ispravka utovara vodi na spisak")

    R.odeljak("Otkazivanje utovarenog spiska: roba se vraća (povrat)")
    o = adm.get("/admin/spisak.php?id=%d" % sp1)
    R.provera("Otkaži utovar (vrati robu na stanje)" in o.text and "utovareno 270 umesto 282" in tekst(o.text), "utovaren spisak: prikaz razlike i dugme za otkazivanje")
    pre10, pre5 = stanje(hum10), stanje(hum5)
    o = adm.post("/admin/spisak.php", {"akcija": "status", "id": sp1, "ocekivan": "utovareno", "novi": "otkazano"})
    R.provera("vraćena na stanje" in poruke(o) and (stanje(hum10), stanje(hum5)) == (pre10 + 270, pre5 + 100), "otkazan utovar: Humovit 10 l +270, 5 l +100", (pre10, pre5, stanje(hum10), stanje(hum5)))
    R.provera(broj_unosa("povrat") == 2 and sql("SELECT COUNT(*) FROM unosi WHERE spisak_id=%d AND obrisan=1" % sp1) == "0", "upisana su 2 povrata, ništa nije obrisano")
    o = adm.post("/admin/spisak.php", {"akcija": "status", "id": sp1, "ocekivan": "otkazano", "novi": "nacrt"})
    R.provera("ne može ponovo da se otvori" in poruke(o) and sql("SELECT status FROM spiskovi WHERE id=%d" % sp1) == "otkazano", "otkazan utovaren spisak se ne otvara ponovo")
    o = adm.post("/admin/spisak.php", {"akcija": "status", "id": sp1, "ocekivan": "utovareno", "novi": "otkazano"})
    R.provera(broj_unosa("povrat") == 2, "dvostruko otkazivanje ne vraća robu dvaput")

    R.odeljak("Štampa, PDF i deljenje")
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp2)
    t = tekst(o.text)
    R.provera(o.status == 200 and "Spisak za utovar #%d" % sp2 in t and "Cvećara" in t and "Farmerkop d.o.o." in t, "stranica za štampu: naslov, kupac, firma")
    R.provera("data-stampaj" in o.text and "Sačuvaj kao PDF" in t and "Utovario" in t and "Vozač" in t, "dugme Štampaj / PDF i mesta za potpise")
    R.provera("utovareno: 0" in t, "na štampi se vidi stvarno utovareno")
    m = re.search(r'href="https://wa\.me/\?text=([^"]+)"', o.text)
    deljenje = urllib.parse.unquote(htmllib.unescape(m.group(1))) if m else ""
    R.provera("Kupac: Cvećara" in deljenje and "1. Humovit · 5 l" in deljenje and "Vozilo: NS 1" in deljenje, "tekst za WhatsApp sadrži kupca, vozilo i stavke", deljenje[:300])
    R.provera('href="viber://forward?text=' in o.text and "data-podeli" in o.text, "deljenje preko Vibera i dugme Podeli")
    R.provera('class="donja-nav"' not in o.text, "štampa je bez navigacije")
    o = jelena.get("/admin/spisak_stampa.php?id=%d" % sp2)
    R.provera(o.status == 200, "radnik može da odštampa / podeli spisak sa utovara")
    o = jelena.get("/admin/spisak_stampa.php?id=%d" % nacrt)
    R.provera(o.status == 404, "radnik ne vidi nacrt ni na štampi")
    o = Client(site.base).get("/admin/spisak_stampa.php?id=%d" % sp2, slediti=False)
    R.provera(o.status == 302, "bez prijave: na prijavu")
    o = adm.get("/admin/spisak.php?id=%d" % sp2)
    R.provera("admin/spisak_stampa.php?id=%d" % sp2 in o.text, "spisak ima vezu „Štampa / PDF / Podeli“")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
