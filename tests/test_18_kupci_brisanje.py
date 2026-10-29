#!/usr/bin/env python3
"""Brisanje otkazanog spiska za utovar (i onog koji je bio utovaren pa otkazan – stanje se ne menja) i pregled
"Kupci po godini": ko je koliko robe uzeo u godini (prodaja + utovar − povrat), sa izvozom u Excel.
Brisanje ostalih statusa, brojevi Utovar 1, 2, 3 i „Obriši sve otkazane“: test_23_brisanje_utovara.py."""
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


def kupac_blok(o, naziv):
    m = re.search(r'<details class="kartica kupac-godina"[^>]*>(?:(?!</details>).)*?' + re.escape(naziv) + r'.*?</details>', o.text, re.S)
    return tekst(m.group(0)) if m else ""


def novi_spisak(kupac):
    adm.post("/admin/utovar.php", {"kupac": kupac, "datum": danas_bg(), "mesto_broj": "1"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    return sp, sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d" % sp)


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum10, hum50 = sku_id("Humovit", 10), sku_id("Humovit", 50)
    obl = sku_id("Beli oblutak", 20, "kg", "4-6 cm")
    adm.get("/admin/utovar.php")

    R.odeljak("Priprema: prodaja i utovar za dva kupca")
    for sku in (hum10, hum50, obl):
        sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, nastalo, uneto) VALUES ('proizvodnja', %d, 100, 1, NOW(), NOW())" % sku)
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Agrocentar", "sku_id": hum10, "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("Prodaja sačuvana" in poruke(o), "ručna prodaja: Agrocentar, 5 × Humovit 10 l", poruke(o))
    spA, mA = novi_spisak("Cvećara Flora")
    adm.post(SP, {"akcija": "dodaj_vise", "id": spA, "mesto_id": mA, "paleta_id": "0", "r_tekst[]": ["humovit 50", "oblutak beli 4-6"], "r_id[]": ["", ""],
                  "r_pal[]": ["1", ""], "r_pak[]": ["", ""], "r_kom[]": ["", "10"]})
    adm.post(SP, {"akcija": "status", "id": spA, "ocekivan": "nacrt", "novi": "za_utovar"})
    jelena.get("/radnik/utovar.php?id=%d" % spA)
    f = {"id": spA}
    for sid in sql("SELECT id FROM spisak_stavke WHERE spisak_id=%d" % spA).split():
        f["ok[%s]" % sid] = "1"
    o = jelena.post("/radnik/utovar.php", f)
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % spA) == "utovareno", "spisak za Cvećaru Flora je utovaren (40 × Humovit 50 l, 10 × oblutak)", poruke(o))
    # prošla godina
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, nastalo, uneto) SELECT 'prodaja', %d, 7, 1, id, '%d-06-15 10:00:00', NOW() FROM kupci WHERE naziv='Agrocentar'"
        % (hum10, int(danas_bg()[:4]) - 1))

    R.odeljak("Kupci po godini")
    godina = int(danas_bg()[:4])
    o = adm.get("/admin/kupci.php")
    R.provera(o.status == 200 and "Kupci po godini" in o.text and 'value="%d" selected' % godina in o.text and '<option value="%d"' % (godina - 1) in o.text,
              "stranica sa izborom godine (ova i prošla)")
    flora, agro = kupac_blok(o, "Cvećara Flora"), kupac_blok(o, "Agrocentar")
    R.provera("50 kom · 2.000 l · 200 kg" in flora and "Humovit · 50 l 40 2.000 l" in flora and "Beli oblutak · 4-6 cm · 20 kg 10 200 kg" in flora,
              "Cvećara Flora: 40 × Humovit 50 l = 2.000 l i 10 × oblutak = 200 kg", flora)
    R.provera("5 kom · 50 l" in agro and "Humovit · 10 l 5 50 l" in agro, "Agrocentar ove godine: samo 5 × Humovit 10 l (prodaja iz prošle godine se ne računa)", agro)
    R.provera(o.text.index("Cvećara Flora") < o.text.index("Agrocentar"), "kupci redom: ko je uzeo više")
    o = adm.get("/admin/kupci.php?godina=%d" % (godina - 1))
    R.provera("7 kom · 70 l" in kupac_blok(o, "Agrocentar") and "Cvećara Flora" not in o.text, "prošla godina: Agrocentar 7 × Humovit 10 l", kupac_blok(o, "Agrocentar"))
    o = adm.get("/admin/kupci.php?godina=%d&kupac=agro" % godina)
    R.provera("Agrocentar" in o.text and "Cvećara Flora" not in o.text, "pretraga po kupcu")
    o = adm.get("/admin/kupci.php?godina=%d&csv=1" % godina)
    R.provera(o.header("Content-Type").startswith("text/csv") and '"Godina";"Kupac";"Artikal"' in o.text and '%d;"Cvećara Flora";"Humovit";"";"50 l";40;2000;l' % godina in o.text,
              "izvoz u Excel (CSV)", o.text[:300])
    R.provera('href="/admin/kupci.php"' in adm.get("/admin/istorija.php").text and 'href="/admin/kupci.php"' in adm.get("/admin/stanje.php").text,
              "veza iz Istorije i iz menija ☰")
    o = jelena.get("/admin/kupci.php")
    R.provera(o.url.endswith("/radnik/index.php") and "Cvećara" not in o.text, "radnik ne vidi pregled kupaca")

    R.odeljak("Brisanje otkazanog spiska")
    spB, mB = novi_spisak("Bašta Zemun")
    adm.post(SP, {"akcija": "dodaj", "id": spB, "mesto_id": mB, "paleta_id": "0", "sku_id": hum10, "kom": "3"})
    adm.post(SP, {"akcija": "status", "id": spB, "ocekivan": "nacrt", "novi": "otkazano"})
    o = adm.get(SP + "?id=%d" % spB)
    oznaka = utovar(spB)
    R.provera('id="brisanje"' in o.text and "Obriši %s" % oznaka in o.text and "Ovo ne može da se vrati" in o.text and 'name="ocekivan" value="otkazano"' in o.text,
              "otkazan spisak ima dugme „Obriši %s“ (uz potvrdu)" % oznaka)
    o = adm.post(SP, {"akcija": "obrisi_spisak", "id": spB, "ocekivan": "otkazano"})
    R.provera(o.url.endswith("/admin/utovar.php?f=otkazano") and "Obrisan je %s (Bašta Zemun)." % oznaka in poruke(o)
              and sql("SELECT CONCAT((SELECT COUNT(*) FROM spiskovi WHERE id=%d), (SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d), (SELECT COUNT(*) FROM spisak_stavke WHERE spisak_id=%d))" % (spB, spB, spB)) == "000",
              "obrisan sa mestima i stavkama; nazad na listu spiskova", poruke(o))
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Spisak #%d: obrisan otkazan spisak%%'" % spB) == 1, "brisanje je u dnevniku")
    R.provera(adm.get(SP + "?id=%d" % spB).status == 404, "obrisan spisak više ne postoji")

    R.odeljak("Utovaren, pa otkazan spisak: brisanje ne menja stanje")
    pre = (stanje(hum50), stanje(obl))
    adm.post(SP, {"akcija": "status", "id": spA, "ocekivan": "utovareno", "novi": "otkazano"})
    R.provera((stanje(hum50), stanje(obl)) == (pre[0] + 40, pre[1] + 10), "otkazan utovar: roba vraćena na stanje (povrat)")
    posle_otkaza = (stanje(hum50), stanje(obl))
    R.provera("Cvećara Flora" not in adm.get("/admin/kupci.php").text, "otkazan utovar se ne računa kupcu")
    o = adm.post(SP, {"akcija": "obrisi_spisak", "id": spA})
    R.provera("utovar i povrat su označeni kao obrisani (stanje se ne menja)" in poruke(o) and sql_int("SELECT COUNT(*) FROM spiskovi WHERE id=%d" % spA) == 0
              and sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Spisak #%d: obrisan otkazan spisak (%%utovar i povrat (4 unosa) označeni kao obrisani%%'" % spA) == 1,
              "obrisan; utovar i povrat označeni kao obrisani", poruke(o))
    R.provera((stanje(hum50), stanje(obl)) == posle_otkaza, "stanje je isto kao posle otkazivanja")
    R.provera(sql("SELECT GROUP_CONCAT(DISTINCT CONCAT(obrisan, '|', IFNULL(spisak_id, 'NULL'))) FROM unosi WHERE napomena LIKE '%%obrisan otkazan spisak (Cvećara Flora, utovar %%'") == "1|NULL"
              and sql_int("SELECT COUNT(*) FROM unosi WHERE napomena LIKE '%%obrisan otkazan spisak (Cvećara Flora, utovar %%'") == 4,
              "unosi ostaju u istoriji kao obrisani (sa napomenom), bez veze na obrisan spisak")
    R.provera("Agrocentar" in adm.get("/admin/kupci.php").text, "ostali kupci ostaju u pregledu")
    o = adm.get("/admin/stanje.php")
    R.provera(o.status == 200, "Stanje radi posle brisanja")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
