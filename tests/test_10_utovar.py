#!/usr/bin/env python3
"""Spisak za utovar – faza 1: nova šema (v3), novi artikli, težina po komadu, utovar/povrat u računu stanja,
rezervisano i slobodno na Stanju, nadogradnja sa verzije koja je sada na hostingu (v2)."""
import csv
import html as htmllib
import io
import re
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

VERZIJA_NA_HOSTINGU = "adbeed0"

R = Rezultat()


def tekst(h):
    return htmllib.unescape(re.sub(r"<[^>]+>", " ", h))


def poruke(o):
    return " | ".join(tekst(m).strip() for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def kolona(tabela, ime):
    return sql_int("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='%s' AND table_name='%s' AND column_name='%s'" % (DB_NAME, tabela, ime)) == 1


def sku_opis(artikal):
    """{'varijanta pakovanje': (po_paleti, po_paketu)} za artikal."""
    out = {}
    for red in sql("SELECT IFNULL(v.naziv,'-'), CONCAT(p.kolicina+0,' ',p.jedinica), IFNULL(s.po_paleti,'NULL'), IFNULL(s.po_paketu,'NULL') "
                   "FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id LEFT JOIN varijante v ON v.id=s.varijanta_id "
                   "WHERE a.naziv='%s' AND s.aktivan=1" % artikal).split("\n"):
        if red:
            v, pak, po, pp = red.split("\t")
            pak = re.sub(r"(\d+)\.00\b", r"\1", pak)
            out[((v if v != "-" else "") + " " + pak).strip()] = (po, pp)
    return out


site = None
stari = novi = None
tmp = None
try:
    # ═══ A. Nova instalacija ═══════════════════════════════════════════════
    reset_db()
    site = Site()
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    A = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")

    R.odeljak("Šema baze (verzija 3)")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "11", "schema_verzija = 11")
    for t in ("spiskovi", "spisak_stavke"):
        R.provera(sql_int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%s' AND table_name='%s'" % (DB_NAME, t)) == 1, "postoji tabela " + t)
    R.provera(kolona("sku", "tezina_kg") and kolona("unosi", "spisak_id"), "kolone sku.tezina_kg i unosi.spisak_id")
    R.provera(kolona("spisak_stavke", "naziv") and kolona("spisak_stavke", "jedinica"), "stavka spiska može biti „ostalo“ (naziv i jedinica)")
    tip = sql("SELECT column_type FROM information_schema.columns WHERE table_schema='%s' AND table_name='unosi' AND column_name='tip'" % DB_NAME)
    R.provera("'utovar'" in tip and "'povrat'" in tip, "vrste kretanja: utovar i povrat", tip)

    R.odeljak("Novi artikli")
    gran = ["1-3 cm", "2-4 cm", "4-6 cm", "4-7 cm (krupnija)", "6-10 cm"]
    for boja in ("Crni", "Crveni"):
        R.provera(sku_opis(boja + " oblutak") == {g + " 20 kg": ("50", "NULL") for g in gran}, "%s oblutak: 5 granulacija, 20 kg, 50 po paleti" % boja, sku_opis(boja + " oblutak"))
        R.provera(sql("SELECT k.naziv FROM artikli a JOIN kategorije k ON k.id=a.kategorija_id WHERE a.naziv='%s oblutak'" % boja) == "Dekorativni oblutak", "%s oblutak je u kategoriji Dekorativni oblutak" % boja)
    for boja in ("Bela", "Crna", "Crvena"):
        R.provera(sku_opis(boja + " rizla") == {"Sitnija 20 kg": ("50", "NULL"), "Krupnija 20 kg": ("50", "NULL")}, "%s rizla: sitnija i krupnija, 20 kg, 50 po paleti" % boja, sku_opis(boja + " rizla"))
    R.provera(sql("SELECT naziv_varijante FROM artikli WHERE naziv='Bela rizla'") == "Granulacija", "kod rizle se bira Granulacija")
    R.provera(sku_opis("Perlit") == {"5 l": ("NULL", "20"), "100 l": ("NULL", "NULL")}, "Perlit: 5 l (20 u paketu) i 100 l", sku_opis("Perlit"))
    R.provera(sku_opis("Keramzit") == {"5 l": ("NULL", "8"), "35 l": ("NULL", "NULL")}, "Keramzit: 5 l (8 u paketu) i 35 l", sku_opis("Keramzit"))
    kat = sql("SELECT GROUP_CONCAT(naziv ORDER BY redosled SEPARATOR '|') FROM kategorije")
    R.provera(kat == "Zemlja za cveće|Malč|Dekorativni oblutak|Dekorativna rizla|Perlit|Keramzit", "redosled kategorija", kat)
    o = jelena.get("/radnik/index.php")
    R.provera(all(x in o.text for x in ("Crveni oblutak", "Crna rizla", "Perlit", "Keramzit")), "radnik vidi nove artikle")
    perlit5 = sku_id("Perlit", 5)
    jelena.get("/radnik/index.php")
    o = jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": perlit5, "kolicina": "3", "nacin": "paketi", "kljuc": nov_kljuc()})
    R.provera(stanje(perlit5) == 60, "Perlit 5 l: 3 paketa × 20 = 60 komada", stanje(perlit5))

    R.odeljak("Težina jednog komada (Podešavanja → Artikli)")
    hum_id = sql_int("SELECT id FROM artikli WHERE naziv='Humovit'")
    hum5, hum10, hum50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 50)
    o = adm.get("/admin/artikal.php?id=%d" % hum_id)
    R.provera("<span>Kg/kom</span>" in o.text and 'name="kg[%d]"' % hum50 in o.text, "matrica ima kolonu Kg/kom")
    o = adm.post("/admin/artikal.php", {"akcija": "vrednosti", "id": hum_id, "kg[%d]" % hum50: "12,5", "kg[%d]" % hum5: "1.6", "kg[%d]" % hum10: ""})
    R.provera(sql("SELECT IFNULL(tezina_kg,'NULL') FROM sku WHERE id IN (%d,%d,%d) ORDER BY FIELD(id,%d,%d,%d)" % (hum50, hum5, hum10, hum50, hum5, hum10)).split() == ["12.50", "1.60", "NULL"], "upisano 12,5 i 1.6 kg; prazno = nije upisano")
    R.provera('value="12,5"' in o.text and 'value="1,6"' in o.text, "težina se prikazuje sa zarezom")
    R.provera(sql("SELECT po_paleti, po_paketu FROM sku WHERE id=%d" % hum10) == "270\t6", "čuvanje samo težine ne briše paletu i paket")
    for losa in ("abc", "0", "-2", "12345", "1,234"):
        o = adm.post("/admin/artikal.php", {"akcija": "vrednosti", "id": hum_id, "kg[%d]" % hum50: losa})
        R.provera("Težina jednog komada mora biti" in poruke(o) and sql("SELECT tezina_kg FROM sku WHERE id=%d" % hum50) == "12.50", "neispravna težina „%s“ se odbija" % losa)
    obl = sql_int("SELECT id FROM artikli WHERE naziv='Beli oblutak'")
    o = adm.get("/admin/artikal.php?id=%d" % obl)
    R.provera(re.search(r'name="kg\[\d+\]"[^>]*value=""[^>]*placeholder="20"', o.text) is not None, "kod pakovanja od 20 kg težina se podrazumeva (20)")

    R.odeljak("Utovar i povrat u računu stanja")
    # Faza 3 će upisivati ove redove; ovde ih upisujemo direktno da proverimo račun stanja i prikaze.
    adm.get("/admin/prodaja.php")
    sql("INSERT INTO kupci (naziv, napravljen) VALUES ('Agrocentar', NOW())")
    kupac = sql_int("SELECT id FROM kupci WHERE naziv='Agrocentar'")
    sql("INSERT INTO spiskovi (kupac_id, datum, status, napravio_id, napravljen, izmenjen) VALUES (%d, CURDATE(), 'utovareno', %d, NOW(), NOW())" % (kupac, A))
    sp1 = sql_int("SELECT MAX(id) FROM spiskovi")
    jelena.get("/radnik/index.php")
    jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": hum10, "kolicina": "1", "nacin": "palete", "kljuc": nov_kljuc()})   # 270
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto) VALUES ('utovar', %d, 300, %d, %d, %d, 'Spisak', NOW(), NOW())" % (hum10, ids["Jelena"], kupac, sp1))
    R.provera(stanje(hum10) == -30, "proizvedeno 270, utovareno 300 → stanje −30")
    o = adm.get("/admin/stanje.php")
    blok = o.text[o.text.index("Humovit"):]
    blok10 = blok[blok.index(">10 l<"):][:900]
    R.provera('sku-stanje">−30<' in blok10 and "u minusu" in blok10, "Stanje prikazuje −30 i upozorenje „u minusu“", tekst(blok10)[:200])
    R.provera(re.search(r'<span class="broj">300</span>\s*<span class="opis">prodato danas', o.text) is not None, "utovar se računa u „prodato danas“")
    R.provera("ispod minimuma" in o.text and re.search(r'<span class="broj">1</span>\s*<span class="opis">ispod minimuma', o.text) is not None, "minus se broji kao upozorenje")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto) VALUES ('povrat', %d, 50, %d, %d, %d, 'Otkazan', NOW(), NOW())" % (hum10, A, kupac, sp1))
    R.provera(stanje(hum10) == 20, "povrat 50 vraća stanje na 20")
    o = adm.get("/admin/istorija.php?od=&do=")
    R.provera("Utovar" in o.text and "Povrat (otkazan utovar)" in o.text, "istorija prikazuje utovar i povrat")
    R.provera("−300 kom" in o.text and "+50 kom" in o.text, "utovar se prikazuje kao −300, povrat kao +50")
    R.provera(re.search(r'<span class="broj">250</span><span class="opis">prodato i utovareno', o.text) is not None, "zbir: utovareno 300 − povrat 50 = 250")
    o = adm.get("/admin/istorija.php?od=&do=&tip=utovar")
    R.provera(o.status == 200 and o.text.count("unos-red") == 1, "filter po vrsti „utovar“ radi")
    o = adm.get("/admin/izvoz.php?od=&do=")
    redovi = list(csv.reader(io.StringIO(o.body.lstrip("\ufeff")), delimiter=";"))
    zag = redovi[0]
    ut = [r for r in redovi if "Utovar" in r[zag.index("Vrsta")]]
    R.provera(len(ut) == 1 and ut[0][zag.index("Promena stanja (kom)")] == "-300", "CSV: utovar menja stanje za −300", ut)
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Cvećara", "sku_id": hum10, "kolicina": "21", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("Nema dovoljno" in poruke(o) and stanje(hum10) == 20, "ručna prodaja i dalje ne može preko stanja", poruke(o))

    R.odeljak("Rezervisano i slobodno")
    sql("INSERT INTO spiskovi (kupac_id, datum, status, napravio_id, napravljen, izmenjen) VALUES (%d, CURDATE(), 'za_utovar', %d, NOW(), NOW())" % (kupac, A))
    sp2 = sql_int("SELECT MAX(id) FROM spiskovi")
    sql("INSERT INTO spisak_stavke (spisak_id, sku_id, kolicina, komadi) VALUES (%d, %d, 15, 15), (%d, %d, 10, 10)" % (sp2, hum10, sp2, hum5))
    sql("INSERT INTO spisak_stavke (spisak_id, sku_id, naziv, jedinica, tezina_kg, kolicina) VALUES (%d, NULL, 'Saksije 30 cm', 'kom', 0.8, 40)" % sp2)
    sql("INSERT INTO spiskovi (kupac_id, datum, status, napravio_id, napravljen, izmenjen) VALUES (%d, CURDATE(), 'nacrt', %d, NOW(), NOW())" % (kupac, A))
    sp3 = sql_int("SELECT MAX(id) FROM spiskovi")
    sql("INSERT INTO spisak_stavke (spisak_id, sku_id, kolicina, komadi) VALUES (%d, %d, 999, 999)" % (sp3, hum10))
    o = adm.get("/admin/stanje.php")
    blok = o.text[o.text.index("Humovit"):]
    b10 = tekst(blok[blok.index(">10 l<"):][:1200])
    b5 = tekst(blok[blok.index(">5 l<"):][:1200])
    R.provera("rezervisano 15" in b10 and re.search(r"slobodno\s+5\b", b10), "Humovit 10 l: stanje 20, rezervisano 15, slobodno 5 (nacrt se ne računa)", b10[:300])
    R.provera("rezervisano 10" in b5 and re.search(r"slobodno\s+−10\b", b5) and "tekst-crveno" in blok[blok.index(">5 l<"):][:1200], "Humovit 5 l: rezervisano 10 bez stanja → slobodno −10 (crveno)", b5[:300])
    R.provera("Saksije" not in o.text, "„ostalo“ sa spiska ne utiče na Stanje")
    sql("UPDATE spiskovi SET status='otkazano' WHERE id=%d" % sp2)
    o = adm.get("/admin/stanje.php")
    R.provera("rezervisano" not in o.text, "otkazan spisak više ništa ne rezerviše")

    R.odeljak("Popis posle minusa")
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, kupac_id, spisak_id, napomena, nastalo, uneto) VALUES ('utovar', %d, 25, %d, %d, %d, 'Spisak', NOW(), NOW())" % (hum10, ids["Jelena"], kupac, sp1))
    R.provera(stanje(hum10) == -5, "stanje −5")
    adm.get("/admin/popis.php")
    o = adm.post("/admin/popis.php", {"napomena": "Popis posle utovara", "kom[%d]" % hum10: "12"})
    R.provera(stanje(hum10) == 12 and "−5 → 12" in poruke(o).replace("-", "−"), "popis iz minusa postavlja tačno prebrojano (−5 → 12)", poruke(o))
    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške (nova instalacija)", site.php_problemi()[:3])
    site.stop()
    site = None

    # ═══ B. Nadogradnja sa verzije na hostingu (v2) ════════════════════════
    R.odeljak("Nadogradnja: verzija sa hostinga (v2) → v3")
    reset_db()
    tmp = Path(tempfile.mkdtemp(prefix="fk_v2_"))
    arhiva = subprocess.run(["git", "-C", str(ROOT), "archive", VERZIJA_NA_HOSTINGU, "app"], capture_output=True, check=True).stdout
    tarfile.open(fileobj=io.BytesIO(arhiva)).extractall(tmp)
    stari = Site(app_dir=tmp / "app")
    ids = pripremi_sajt(stari)
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "2", "stara verzija je na v2")
    j = prijavi_radnika(stari, ids["Jelena"], "4321")
    hum10 = sku_id("Humovit", 10)
    j.get("/radnik/index.php")
    j.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": hum10, "kolicina": "12", "nacin": "paketi", "kljuc": nov_kljuc()})
    a = prijavi_admina(stari)
    a.get("/admin/prodaja.php")
    a.post("/admin/prodaja.php", {"kupac": "Cvećara Žika", "sku_id": hum10, "kolicina": "2", "nacin": "paketi", "kljuc": nov_kljuc()})
    a.get("/admin/uvoz.php")
    a.post("/admin/uvoz.php", {"akcija": "ucitaj", "tekst": "Naziv\tKol\nHumovit 5 l\t40\n"})
    o = a.get("/admin/uvoz.php")
    v = re.search(r'name="v" value="([0-9a-f]+)"', o.text).group(1)
    i = re.search(r'name="sku\[(\d+)\]"', o.text).group(1)
    a.post("/admin/uvoz.php", {"akcija": "uvezi", "v": v, "kol_naziv": "0", "kol_kolicina": "1", "kol_sifra": "-1", "zaglavlje": "1", "jedinica": "komadi",
                               "format": "sr", "napomena": "Početno", "zapamti": "1", "sku[%s]" % i: str(sku_id("Humovit", 5)), "kraj": "1"})
    # vlasnik je ručno već dodao Perlit (sa svojim pakovanjem) – ne sme da se duplira
    a.get("/admin/artikli.php")
    a.post("/admin/artikli.php", {"akcija": "novi_artikal", "naziv": "Perlit", "kategorija_id": sql_int("SELECT id FROM kategorije WHERE naziv='Malč'"), "nv": ""})
    pre = {
        "stanja": sql("SELECT sku_id, SUM(CASE WHEN tip IN ('prodaja','kucna_prodaja') THEN -kolicina ELSE kolicina END) FROM unosi WHERE obrisan=0 GROUP BY sku_id ORDER BY sku_id"),
        "mapiranje": sql("SELECT GROUP_CONCAT(CONCAT(kljuc,'=',sku_id) ORDER BY kljuc) FROM uvoz_mapiranje"),
        "sku": sql("SELECT GROUP_CONCAT(CONCAT(id,':',IFNULL(po_paleti,''),':',IFNULL(po_paketu,''),':',aktivan) ORDER BY id) FROM sku"),
        "max_art": sql("SELECT MAX(id) FROM artikli"),
    }
    pre["unosi"] = sql("SELECT GROUP_CONCAT(CONCAT(id,':',tip,':',sku_id,':',kolicina,':',IFNULL(paketi,'')) ORDER BY id) FROM unosi")
    R.provera(stanje(hum10) == 60 and stanje(sku_id("Humovit", 5)) == 40, "na staroj verziji: Humovit 10 l = 72 − 12 = 60, Humovit 5 l = 40 (uvoz)")
    stari.stop()
    stari = None

    novi = Site()
    Client(novi.base).get("/login.php")
    R.provera(sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'") == "11", "posle prvog otvaranja baza je na v11")
    R.provera(novi.log().count("nadogradnja baze na verziju 3") == 1 and novi.log().count("verziju 4") == 1 and novi.log().count("verziju 5") == 1 and novi.log().count("verziju 6") == 1 and novi.log().count("verziju 7") == 1 and novi.log().count("verziju 8") == 1 and novi.log().count("verziju 9") == 1 and novi.log().count("verziju 10") == 1 and novi.log().count("verziju 11") == 1 and "verziju 2" not in novi.log(), "izvršene su samo nadogradnje 3–11")
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(id,':',tip,':',sku_id,':',kolicina,':',IFNULL(paketi,'')) ORDER BY id) FROM unosi") == pre["unosi"], "svi stari unosi su isti")
    R.provera(sql("SELECT sku_id, SUM(CASE WHEN tip IN ('prodaja','kucna_prodaja') THEN -kolicina ELSE kolicina END) FROM unosi WHERE obrisan=0 GROUP BY sku_id ORDER BY sku_id") == pre["stanja"], "stanja su ista")
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(kljuc,'=',sku_id) ORDER BY kljuc) FROM uvoz_mapiranje") == pre["mapiranje"], "zapamćena uparivanja uvoza su ista")
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(id,':',IFNULL(po_paleti,''),':',IFNULL(po_paketu,''),':',aktivan) ORDER BY id) FROM sku WHERE id <= %s" % pre["sku"].split(",")[-1].split(":")[0]) == pre["sku"], "postojeći SKU-ovi (palete, paketi) su isti")
    R.provera(sql_int("SELECT COUNT(*) FROM artikli WHERE naziv='Perlit'") == 1, "Perlit koji je vlasnik već dodao se ne duplira")
    R.provera(sql_int("SELECT COUNT(*) FROM artikli WHERE id > %s" % pre["max_art"]) == 7, "dodato je 7 novih artikala (bez Perlita; uz Humovit S u staroj ambalaži)")
    R.provera(sql("SELECT COUNT(*) FROM pakovanja WHERE (kolicina=35 OR kolicina=100) AND jedinica='l'") == "2", "dodata su pakovanja 35 l i 100 l")
    a = prijavi_admina(novi)
    o = a.get("/admin/stanje.php")
    R.provera(o.status == 200 and re.search(r'sku-stanje">60<', o.text) is not None, "Stanje posle nadogradnje: Humovit 10 l = 60")
    j = prijavi_radnika(novi, ids["Jelena"], "4321")
    o = j.get("/radnik/index.php")
    R.provera("Crveni oblutak" in o.text and "Keramzit" in o.text, "radnik posle nadogradnje vidi nove artikle")
    R.provera(not novi.php_problemi(), "PHP nije prijavio upozorenja ni greške (nadogradnja)", novi.php_problemi()[:3])
finally:
    for s in (site, stari, novi):
        if s:
            try:
                s.stop()
            except Exception:
                pass
    if tmp:
        shutil.rmtree(tmp, ignore_errors=True)

sys.exit(R.kraj())
