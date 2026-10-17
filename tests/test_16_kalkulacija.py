#!/usr/bin/env python3
"""Modul Kalkulacija (Faza 1): ručna migracija (migracije/001_kalkulacija.sql), pristup samo za administratora,
sirovine i istorija cena, troškovi, recepture sa verzijama (zbir 100 %), prodajne cene, pregled sa maržom,
"šta ako" – i da početni ekran sa zalihama i postojeće tabele ostaju isti kao pre."""
import html as htmllib
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()
site = Site()
MIGRACIJA = ROOT / "migracije" / "001_kalkulacija.sql"
STRANE = ["/admin/kalkulacija.php", "/admin/kalk_sirovine.php", "/admin/kalk_troskovi.php", "/admin/kalk_recepture.php",
          "/admin/kalk_cene.php", "/admin/kalk_staako.php"]


def tekst(h):
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def glavni(o):
    return re.search(r'<main class="sadrzaj">(.*)</main>', o.text, re.S).group(1)


def donja_nav(o):
    m = re.search(r'<nav class="donja-nav"[^>]*>(.*?)</nav>', o.text, re.S)
    return re.findall(r"<span>([^<]+)</span>", m.group(1)) if m else []


def migracija():
    r = subprocess.run(["mysql", DB_NAME], stdin=open(MIGRACIJA, "rb"), capture_output=True)
    return r.returncode, r.stderr.decode()


def sema_postojecih():
    """Kolone i ključevi svih tabela koje nisu calc_ (da se vidi da migracija ništa ne menja)."""
    return sql("SELECT GROUP_CONCAT(CONCAT_WS(':', table_name, column_name, column_type, is_nullable, IFNULL(column_default, '-'), column_key) "
               "ORDER BY table_name, ordinal_position SEPARATOR '|') FROM information_schema.columns "
               "WHERE table_schema = DATABASE() AND table_name NOT LIKE 'calc\\_%'")


def red_pregleda(o, sku):
    m = re.search(r'<details class="kalk-red" data-sku="%d">(.*?)</details>' % sku, o.text, re.S)
    return tekst(m.group(1)) if m else ""


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum10, hum50 = sku_id("Humovit", 10), sku_id("Humovit", 50)
    idea10 = sku_id("Idea", 10)
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    idea_id = sql_int("SELECT id FROM artikli WHERE naziv='Idea'")
    pak50 = sql_int("SELECT id FROM pakovanja WHERE kolicina=50 AND jedinica='l'")

    R.odeljak("Pre migracije: početni ekran i poruka umesto greške")
    stanje_pre = glavni(adm.get("/admin/stanje.php"))
    sema_pre = sema_postojecih()
    o = adm.get("/admin/stanje.php")
    R.provera(donja_nav(o) == ["Stanje", "Utovar", "Istorija", "Radnici", "Podešavanja"], "donja navigacija je ista (Stanje prvo, bez Kalkulacije)", donja_nav(o))
    R.provera('data-glavni-meni' in o.text and 'href="/admin/kalkulacija.php"' in o.text and "kalk" not in glavni(o).lower(),
              "administrator: Kalkulacija je u meniju ☰ u zaglavlju, a ne na početnom ekranu")
    o = adm.get("/admin/kalkulacija.php")
    R.provera(o.status == 200 and "Modul Kalkulacija još nije uključen" in o.text and "001_kalkulacija.sql" in o.text, "bez migracije: uputstvo, ne greška")
    o = jelena.get("/radnik/index.php")
    R.provera("data-glavni-meni" not in o.text and "kalkulacija" not in o.text.lower(), "radnik ne vidi meni ni stavku Kalkulacija")

    R.odeljak("Migracija (ručno, može više puta)")
    kod, greska = migracija()
    R.provera(kod == 0, "migracija je prošla", greska)
    kod, greska = migracija()
    R.provera(kod == 0, "ponovno pokretanje ne pravi grešku", greska)
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'calc\\_%'") == 7, "7 novih tabela calc_")
    R.provera(sql("SELECT GROUP_CONCAT(naziv ORDER BY id) FROM calc_materials") == "Crni treset,Beli treset,Perlit,NPK"
              and sql_int("SELECT COUNT(*) FROM calc_material_prices") == 0 and sql_int("SELECT COUNT(*) FROM calc_materials WHERE litara_po_jedinici IS NOT NULL") == 0,
              "početni podaci: 4 sirovine, bez cena i faktora (ne dupliraju se)")
    R.provera(sema_postojecih() == sema_pre, "postojeće tabele su potpuno iste (kolone, tipovi, ključevi)")
    R.provera(sql("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name LIKE 'calc\\_%' "
                  "AND referenced_table_name IN ('artikli', 'pakovanja', 'sku', 'korisnici')") == "7", "veze na postojeće tabele: artikli, pakovanja, sku, korisnici")
    crni, beli, perlit = (sql_int("SELECT id FROM calc_materials WHERE naziv='%s'" % n) for n in ("Crni treset", "Beli treset", "Perlit"))

    R.odeljak("Pristup: samo administrator")
    for s in STRANE:
        o = jelena.get(s)
        R.provera(o.url.endswith("/radnik/index.php") and "Kalkulacija" not in o.text and "RSD" not in o.text, "radnik: %s → vraćen na svoj ekran" % s, o.url)
    jelena.post("/admin/kalk_sirovine.php", {"akcija": "cena", "id": crni, "cena": "1", "vazi_od": danas_bg()})
    R.provera(sql_int("SELECT COUNT(*) FROM calc_material_prices") == 0, "radnik ne može da upiše cenu ni zaobilaznim putem")
    for s in STRANE:
        o = adm.get(s)
        R.provera(o.status == 200 and 'class="kalk-meni"' in o.text, "administrator: %s se otvara" % s, o.status)

    R.odeljak("Sirovine i cene")
    S = "/admin/kalk_sirovine.php"
    o = adm.post(S, {"akcija": "izmeni", "id": crni, "naziv": "Crni treset", "jedinica": "bala", "faktor": "300", "napomena": ""})
    R.provera(sql("SELECT CONCAT(jedinica, '|', litara_po_jedinici) FROM calc_materials WHERE id=%d" % crni) == "bala|300.000", "crni treset: bala, 300 l gotovog po bali", poruke(o))
    adm.post(S, {"akcija": "izmeni", "id": perlit, "naziv": "Perlit", "jedinica": "džak", "faktor": "100", "napomena": ""})
    for polja, poruka, opis in [({"faktor": "0"}, "veći od 0", "faktor 0"), ({"faktor": "-3"}, "veći od 0", "negativan faktor"),
                                ({"naziv": "Perlit"}, "već postoji", "isti naziv kao druga sirovina")]:
        o = adm.post(S, dict({"akcija": "izmeni", "id": crni, "naziv": "Crni treset", "jedinica": "bala", "faktor": "300", "napomena": ""}, **polja))
        R.provera(poruka in poruke(o) and sql("SELECT litara_po_jedinici FROM calc_materials WHERE id=%d" % crni) == "300.000", "odbijeno: " + opis, poruke(o))
    o = adm.post(S, {"akcija": "cena", "id": crni, "cena": "1.200,00", "vazi_od": "2026-01-01"})
    R.provera(sql("SELECT cena FROM calc_material_prices WHERE materijal_id=%d" % crni) == "1200.00", "cena 1.200,00 RSD po bali", poruke(o))
    o = adm.post(S, {"akcija": "cena", "id": crni, "cena": "1.100", "vazi_od": "2026-01-01"})
    R.provera("već postoji cena" in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_material_prices WHERE materijal_id=%d" % crni) == 1, "dve cene za isti datum: odbijeno", poruke(o))
    for cena, opis in [("-5", "negativna cena"), ("abc", "slova"), ("", "prazno")]:
        o = adm.post(S, {"akcija": "cena", "id": perlit, "cena": cena, "vazi_od": danas_bg()})
        R.provera("Cena mora biti broj" in poruke(o), "odbijeno: " + opis, poruke(o))
    o = adm.post(S, {"akcija": "cena", "id": perlit, "cena": "900", "vazi_od": "2026-13-45"})
    R.provera("Izaberite datum" in poruke(o), "neispravan datum: odbijeno")
    adm.post(S, {"akcija": "cena", "id": perlit, "cena": "900", "vazi_od": "2026-01-01"})
    adm.post(S, {"akcija": "cena", "id": perlit, "cena": "950", "vazi_od": "2099-01-01"})
    o = adm.get(S)
    R.provera("1.200,00 RSD" in o.text and "4,0000 RSD/l" in o.text and "9,0000 RSD/l" in o.text, "cena po litru gotovog: 1.200 / 300 = 4, 900 / 100 = 9 RSD/l")
    R.provera("zakazano" in o.text and sql_int("SELECT COUNT(*) FROM calc_material_prices WHERE materijal_id=%d" % perlit) == 2, "buduća cena je u istoriji (zakazano), danas važi 900")
    zakazana = sql_int("SELECT id FROM calc_material_prices WHERE vazi_od='2099-01-01'")
    o = adm.post(S, {"akcija": "obrisi_cenu", "cena_id": zakazana})
    R.provera("Obrisana" in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_material_prices WHERE materijal_id=%d" % perlit) == 1, "pogrešno upisana cena (danas) može da se obriše")
    sql("UPDATE calc_material_prices SET napravljen = '2026-01-01 10:00:00' WHERE materijal_id=%d" % crni)
    o = adm.post(S, {"akcija": "obrisi_cenu", "cena_id": sql_int("SELECT id FROM calc_material_prices WHERE materijal_id=%d" % crni)})
    R.provera("se ne briše" in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_material_prices WHERE materijal_id=%d" % crni) == 1, "starija cena se ne briše (istorija)")
    o = adm.post(S, {"akcija": "nova", "naziv": "Kokos", "jedinica": "blok", "faktor": "70", "napomena": ""})
    R.provera(sql_int("SELECT COUNT(*) FROM calc_materials WHERE naziv='Kokos'") == 1, "nova sirovina", poruke(o))
    R.provera(sql_int("SELECT COUNT(*) FROM dnevnik WHERE detalji LIKE 'Kalkulacija: cena Crni treset%'") == 1, "promene su u Dnevniku izmena")

    R.odeljak("Troškovi (po džaku, po litru, po veličini)")
    T = "/admin/kalk_troskovi.php"
    o = adm.post(T, {"akcija": "trosak", "naziv": "Vreća", "tip": "dzak", "pakovanje_id": pak50, "iznos": "25", "vazi_od": "2026-01-01"})
    R.provera("Vreća (50 l): 25,0000 RSD po džaku" in poruke(o), "vreća za 50 l: 25 RSD po džaku", poruke(o))
    adm.post(T, {"akcija": "trosak", "naziv": "Rad", "tip": "litar", "pakovanje_id": "0", "iznos": "0,5", "vazi_od": "2026-01-01"})
    o = adm.post(T, {"akcija": "trosak", "naziv": "Rad", "tip": "litar", "pakovanje_id": "0", "iznos": "0,7", "vazi_od": "2026-01-01"})
    R.provera("već ima iznos" in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_cost_items WHERE naziv='Rad'") == 1, "isti trošak, isti datum: odbijeno (i za „sve veličine“)", poruke(o))
    o = adm.post(T, {"akcija": "trosak", "naziv": "Rad", "tip": "litar", "pakovanje_id": "0", "iznos": "-1", "vazi_od": danas_bg()})
    R.provera("Iznos mora biti broj" in poruke(o), "negativan iznos: odbijeno")
    adm.post(T, {"akcija": "trosak", "naziv": "Rad", "tip": "litar", "pakovanje_id": "0", "iznos": "9", "vazi_od": "2099-01-01"})
    o = adm.get(T)
    R.provera("0,5000 RSD po litru" in tekst(o.text) and "zakazano" in o.text, "važi 0,50 RSD/l; buduća promena je zakazana")

    R.odeljak("Recepture (zbir 100 %, verzije)")
    RC = "/admin/kalk_recepture.php"
    o = adm.get(RC)
    R.provera("data-recept-zbir" in o.text and 'name="u[]"' in o.text, "forma sa živim zbirom procenata")
    for m, u, poruka, opis in [([crni, perlit], ["60", "39"], "Zbir udela je 99,000 %", "zbir 99 %"),
                               ([crni, perlit], ["60", "40,5"], "višak 0,500 %", "zbir 100,5 %"),
                               ([crni, crni], ["60", "40"], "upisana dva puta", "ista sirovina dva puta"),
                               ([crni, perlit], ["60", "-40"], "udeo mora biti broj veći od 0", "negativan udeo"),
                               ([], [], "bar jednu sirovinu", "prazna receptura")]:
        o = adm.post(RC, {"akcija": "sacuvaj", "artikal_id": hum_id, "naziv": "", "vazi_od": "2026-01-01", "m[]": [str(x) for x in m], "u[]": u})
        R.provera(poruka in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_recipes") == 0, "odbijeno: " + opis, poruke(o))
    o = adm.post(RC, {"akcija": "sacuvaj", "artikal_id": hum_id, "naziv": "", "vazi_od": "2026-01-01", "m[]": [str(crni), str(perlit), ""], "u[]": ["60", "40", ""]})
    rec1 = sql_int("SELECT MAX(id) FROM calc_recipes")
    R.provera("Sačuvana receptura „Humovit od 01.01.2026.“" in poruke(o)
              and sql("SELECT GROUP_CONCAT(CONCAT(materijal_id, ':', udeo) ORDER BY materijal_id) FROM calc_recipe_items WHERE recept_id=%d" % rec1) == "%d:60.000,%d:40.000" % (crni, perlit),
              "Humovit: crni treset 60 % + perlit 40 %", poruke(o))
    o = adm.post(RC, {"akcija": "sacuvaj", "artikal_id": hum_id, "naziv": "Druga", "vazi_od": "2026-01-01", "m[]": [str(crni)], "u[]": ["100"]})
    R.provera("već postoji verzija od 01.01.2026." in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_recipes") == 1, "dve verzije za isti datum: odbijeno", poruke(o))
    o = adm.post(RC, {"akcija": "sacuvaj", "artikal_id": hum_id, "naziv": "Humovit 2099", "vazi_od": "2099-01-01", "m[]": [str(crni)], "u[]": ["100"]})
    rec2 = sql_int("SELECT MAX(id) FROM calc_recipes")
    R.provera(sql("SELECT CONCAT(prethodna_id, '|', aktivna) FROM calc_recipes WHERE id=%d" % rec2) == "%d|1" % rec1, "izmena = nova verzija (pamti prethodnu); stara ostaje", poruke(o))
    o = adm.get(RC)
    R.provera("važi danas" in o.text and "zakazana" in o.text and "Crni treset – 60 %" in tekst(o.text), "važi verzija od 01.01.2026., nova je zakazana")
    adm.post(RC, {"akcija": "aktivna", "recept_id": rec2, "vrednost": "0"})
    R.provera(sql("SELECT CONCAT(aktivna, '|', IFNULL(aktivna_od, 'NULL')) FROM calc_recipes WHERE id=%d" % rec2) == "0|NULL", "verzija može da se isključi")
    o = adm.post(RC, {"akcija": "sacuvaj", "artikal_id": hum_id, "naziv": "Ispravljena", "vazi_od": "2099-01-01", "m[]": [str(crni), str(perlit)], "u[]": ["99,875", "0,125"]})
    R.provera("Sačuvana" in poruke(o) and sql_int("SELECT COUNT(*) FROM calc_recipes WHERE artikal_id=%d AND vazi_od='2099-01-01'" % hum_id) == 2, "posle isključivanja isti datum može ponovo (99,875 + 0,125 = 100 %)", poruke(o))
    o = adm.post(RC, {"akcija": "aktivna", "recept_id": rec2, "vrednost": "1"})
    R.provera("prvo nju isključite" in poruke(o), "ne mogu dve uključene verzije za isti datum", poruke(o))
    kod = subprocess.run(["mysql", DB_NAME, "-e", "UPDATE calc_recipes SET aktivna_od='2099-01-01' WHERE id=%d" % rec2], capture_output=True).returncode
    R.provera(kod != 0, "i baza to odbija (jedinstven ključ artikal + datum aktivne verzije)")
    kod = subprocess.run(["mysql", DB_NAME, "-e", "INSERT INTO calc_recipe_items (recept_id, materijal_id, udeo) VALUES (%d, %d, 0)" % (rec1, beli)], capture_output=True).returncode
    R.provera(kod != 0, "baza odbija udeo 0 (CHECK)")
    adm.post(RC, {"akcija": "sacuvaj", "artikal_id": idea_id, "naziv": "Idea", "vazi_od": "2026-01-01", "m[]": [str(crni), str(beli)], "u[]": ["50", "50"]})

    R.odeljak("Prodajne cene (sa ili bez PDV-a)")
    C = "/admin/kalk_cene.php"
    o = adm.post(C, {"akcija": "cena", "sku_id": hum50, "cena": "600", "sa_pdv": "1", "vazi_od": "2026-01-01"})
    R.provera("Humovit · 50 l: 600,00 RSD sa PDV-om" in poruke(o) and sql("SELECT CONCAT(cena, '|', sa_pdv, '|', pdv_stopa) FROM calc_selling_prices WHERE sku_id=%d" % hum50) == "600.00|1|20.00",
              "Humovit 50 l: 600 RSD sa PDV-om (20 %)", poruke(o))
    o = adm.post(C, {"akcija": "cena", "sku_id": hum50, "cena": "650", "sa_pdv": "1", "vazi_od": "2026-01-01"})
    R.provera("već ima cenu" in poruke(o), "dve prodajne cene za isti datum: odbijeno")
    o = adm.post(C, {"akcija": "cena", "sku_id": idea10, "cena": "300", "sa_pdv": "0", "vazi_od": "2026-01-01"})
    o = adm.get(C)
    R.provera("bez PDV-a: 500,00 RSD" in tekst(o.text), "prikaz: 600 sa PDV-om = 500,00 bez PDV-a")

    R.odeljak("Pregled kalkulacije")
    o = adm.get("/admin/kalkulacija.php")
    r50 = red_pregleda(o, hum50)
    R.provera("300,00 RSD" in r50 and "50,00 RSD" in r50 and "350,00 RSD" in r50 and "500,00 RSD" in r50 and "150,00 RSD" in r50 and "30,0 %" in r50,
              "Humovit 50 l: sirovine 300, ostalo 50 (vreća 25 + rad 0,50 × 50), ukupno 350, cena 500 bez PDV, marža 150 = 30 %", r50)
    R.provera("Crni treset 60 % 30,00 4,0000 120,00 RSD" in r50 and "Perlit 40 % 20,00 9,0000 180,00 RSD" in r50, "razrada po sirovinama (litara, RSD/l, iznos)", r50)
    r10 = red_pregleda(o, hum10)
    R.provera("60,00 RSD" in r10 and "65,00 RSD" in r10 and "nema prodajne cene" in r10, "Humovit 10 l: sirovine 60, + rad 5 = 65; nema prodajne cene", r10)
    ri = red_pregleda(o, idea10)
    R.provera("nedostaje cena za: Beli treset" in ri and "faktor" in ri and "0,00" not in ri.split("Sirovine")[0], "Idea: nedostaje cena (i faktor) za beli treset – nije računato kao 0", ri)
    R.provera(re.search(r'data-sku="%d">.*?kalk-red-marza kalk-broj kalk-marza-ok' % hum50, o.text, re.S), "marža 30 % iznad praga 0 %: zelena")
    o = adm.post("/admin/kalkulacija.php", {"akcija": "podesavanja", "pdv_stopa": "20", "prag_marze": "35"})
    R.provera(re.search(r'data-sku="%d">.*?kalk-red-marza kalk-broj kalk-marza-niska' % hum50, o.text, re.S), "prag 35 %: marža 30 % je crvena", poruke(o))
    o = adm.post("/admin/kalkulacija.php", {"akcija": "podesavanja", "pdv_stopa": "120", "prag_marze": "35"})
    R.provera("od 0 do 99" in poruke(o), "PDV 120 %: odbijeno")

    R.odeljak("Šta ako")
    o = adm.get("/admin/kalk_staako.php?predmet=s%d&nacin=pct&vrednost=10&cilj=40" % crni)
    red = tekst(re.search(r'<tr data-sku="%d">(.*?)</tr>' % hum50, o.text, re.S).group(1))
    R.provera("Crni treset: 1.200,00 RSD → 1.320,00 RSD" in poruke(o), "treset +10 %: 1.200 → 1.320 po bali", poruke(o))
    R.provera(red.startswith("50 l 350,00 RSD 362,00 RSD +12,00 RSD 30,0 %") and "27,6 %" in red, "Humovit 50 l: 350 → 362 RSD, marža 30,0 % → 27,6 %", red)
    R.provera("603,33 RSD 724,00 RSD" in red, "za maržu 40 %: 603,33 RSD bez PDV-a (724,00 sa PDV-om)", red)
    o = adm.get("/admin/kalk_staako.php?predmet=t%d&nacin=iznos&vrednost=-25&cilj=" % sql_int("SELECT id FROM calc_cost_items WHERE naziv='Vreća'"))
    red = tekst(re.search(r'<tr data-sku="%d">(.*?)</tr>' % hum50, o.text, re.S).group(1))
    R.provera("350,00 RSD 325,00 RSD −25,00 RSD" in red, "vreća −25 RSD: 350 → 325", red)
    o = adm.get("/admin/kalk_staako.php?predmet=s%d&nacin=pct&vrednost=-150" % crni)
    R.provera("ne može biti manja od nule" in o.text, "promena ispod nule: odbijeno")
    o = adm.get("/admin/kalk_staako.php?predmet=s%d&nacin=pct&vrednost=5" % beli)
    R.provera("nema cenu" in o.text, "sirovina bez cene: poruka")
    R.provera(sql("SELECT cena FROM calc_material_prices WHERE materijal_id=%d" % crni) == "1200.00", "„šta ako“ ništa ne upisuje u bazu")

    R.odeljak("Početni ekran sa zalihama je isti kao pre")
    o = adm.get("/admin/stanje.php")
    R.provera(glavni(o) == stanje_pre, "Stanje (početni ekran): sadržaj potpuno isti kao pre migracije i unosa u kalkulaciju")
    R.provera(donja_nav(o) == ["Stanje", "Utovar", "Istorija", "Radnici", "Podešavanja"], "donja navigacija ista")
    o = adm.get("/")
    R.provera(o.url.endswith("/admin/stanje.php"), "administrator i dalje počinje od Stanja", o.url)

    R.odeljak("Računanje (php tests/kalkulacija_test.php)")
    r = subprocess.run(["php", str(ROOT / "tests" / "kalkulacija_test.php")], capture_output=True, text=True)
    R.provera(r.returncode == 0 and "0 palo" in r.stdout, "testovi formule prolaze (60/40, faktor ≠ 1, nedostaje cena, zbir 100 %)", r.stdout[-300:])

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
