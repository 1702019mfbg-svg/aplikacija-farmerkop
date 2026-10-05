#!/usr/bin/env python3
"""Uvoz stanja iz fajla: čitanje CSV-a / nalepljene tabele, uparivanje sa artiklima, jedinice, zaštite, pamćenje."""
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

UVOZ = "/admin/uvoz.php"


def tekst(h):
    return htmllib.unescape(re.sub(r"<[^>]+>", "", h)).strip()


def poruke(o):
    return " | ".join(tekst(m) for m in re.findall(r'<div class="poruka[^>]*>(.*?)</div>', o.text, re.S))


def redovi(o):
    """Redovi pregleda: [{i, stanje, naziv, sku, oznaka}]."""
    out = []
    for blok in o.text.split('<div class="uvoz-red ')[1:]:
        out.append({
            "stanje": re.match(r'uvoz-(\w+)"', blok).group(1),
            "naziv": htmllib.unescape(re.search(r"<strong>(.*?)</strong>", blok, re.S).group(1)),
            "i": int(re.search(r'name="sku\[(\d+)\]"', blok).group(1)),
            "sku": int(re.search(r'<option value="(\d+)" selected>', blok).group(1)),
            "oznaka": tekst(re.search(r"data-uvoz-oznaka>(.*?)</span>", blok, re.S).group(1)),
            "ceo": blok,
        })
    return out


def po_nazivu(o):
    return {r["naziv"]: r for r in redovi(o)}


def forma(o, **izmene):
    """Vrednosti forme pregleda, kakve bi poslao pregledač (uz izmene: sku_<i>=id, ostalo po imenu)."""
    f = {"v": re.search(r'name="v" value="([0-9a-f]+)"', o.text).group(1), "kraj": "1", "akcija": "uvezi"}
    for ime in ("kol_naziv", "kol_kolicina", "kol_sifra", "jedinica", "format"):
        m = re.search(r'<select class="polje" id="%s" name="%s">(.*?)</select>' % (ime, ime), o.text, re.S)
        f[ime] = re.search(r'<option value="(-?\w+)" selected>', m.group(1)).group(1)
    if 'name="zaglavlje" value="1" checked' in o.text:
        f["zaglavlje"] = "1"
    f["napomena"] = htmllib.unescape(re.search(r'name="napomena"[^>]*value="([^"]*)"', o.text).group(1))
    if 'name="zapamti" value="1" checked' in o.text:
        f["zapamti"] = "1"
    for r in redovi(o):
        f["sku[%d]" % r["i"]] = str(r["sku"])
    for k, v in izmene.items():
        if k.startswith("sku_"):
            f["sku[%s]" % k[4:]] = str(v)
        elif v is None:
            f.pop(k, None)
        else:
            f[k] = v
    return f


def stat(o, opis):
    m = re.search(r'<span class="broj">([\d.]+)</span><span class="opis">%s</span>' % opis, o.text)
    return int(m.group(1).replace(".", "")) if m else None


def ucitaj(c, tekst_=None, fajl=None, ime="stanje.csv"):
    c.get(UVOZ)
    if fajl is not None:
        return c.post_fajl(UVOZ, {"akcija": "ucitaj"}, ("fajl", ime, fajl))
    return c.post(UVOZ, {"akcija": "ucitaj", "tekst": tekst_})


def osvezi(c, o, **izmene):
    f = forma(o, **izmene)
    f["akcija"] = "osvezi"
    return c.post(UVOZ, f)


def uvezi(c, o, **izmene):
    return c.post(UVOZ, forma(o, **izmene))


def brojevi(upit):
    return sql(upit)


try:
    ids = pripremi_sajt(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    adm = prijavi_admina(site)
    A = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")

    hum5, hum10, hum25, hum50 = sku_id("Humovit", 5), sku_id("Humovit", 10), sku_id("Humovit", 25), sku_id("Humovit", 50)
    prem20 = sku_id("Humovit premium", 20)
    flo10 = sku_id("Floris Savacoop", 10)
    idea5, idea10 = sku_id("Idea", 5), sku_id("Idea", 10)
    malc_cr = sku_id("Malč Farmerkop", 50, "l", "Crveni")
    malc_zu = sku_id("Malč Farmerkop", 50, "l", "Žuti")
    obl13 = sku_id("Beli oblutak", 20, "kg", "1-3 cm")
    obl47 = sku_id("Beli oblutak", 20, "kg", "4-7 cm (krupnija)")
    cmana10 = sku_id("Cmana supstrat", 10)

    # Postojeće stanje artikla kojeg nema u fajlu – mora da ostane netaknuto.
    sql("INSERT INTO unosi (tip, sku_id, kolicina, korisnik_id, napomena, nastalo, uneto) VALUES ('korekcija', %d, 77, %d, 'test', NOW(), NOW())" % (idea5, A))
    R.provera(stanje(idea5) == 77, "priprema: Idea 5 l ima 77 komada")

    CSV = ("Šifra;Naziv;JM;Stanje;Cena\r\n"
           "1001;Humovit 5 l;kom;1.250;100,00\r\n"
           "1002;HUMOVIT 10L;kom;540;120,00\r\n"
           "1003;Humovit 25 l;kom;120;300,00\r\n"
           "1004;Humovit premium 20 l;kom;240;300,00\r\n"
           "1005;Floris Savacoop PL 10 l;kom;270;200,00\r\n"
           "1006;Idea 10 l;kom;225;190,00\r\n"
           "1007;Malč Farmerkop Crveni 50 l;kom;80;500,00\r\n"
           "1008;Хумовит 50 л;kom;40;500,00\r\n"
           "1009;Beli oblutak 1-3 cm 20 kg;kom;100;700,00\r\n"
           "1010;Beli oblutak 4-7 cm krupnija 20 kg;kom;50;700,00\r\n"
           "1011;Kanta za zalivanje 10 l;kom;15;90,00\r\n"
           "1012;Cmana supstrat 10 l;kom;9;190,00\r\n")
    ocekivano = {
        "Humovit 5 l": (hum5, 1250), "HUMOVIT 10L": (hum10, 540), "Humovit 25 l": (hum25, 120),
        "Humovit premium 20 l": (prem20, 240), "Floris Savacoop PL 10 l": (flo10, 270), "Idea 10 l": (idea10, 225),
        "Malč Farmerkop Crveni 50 l": (malc_cr, 80), "Хумовит 50 л": (hum50, 40),
        "Beli oblutak 1-3 cm 20 kg": (obl13, 100), "Beli oblutak 4-7 cm krupnija 20 kg": (obl47, 50),
    }

    # ── 1. Pristup ───────────────────────────────────────────────────────────
    R.odeljak("Pristup: samo administrator")
    anon = Client(site.base)
    o = anon.get(UVOZ, slediti=False)
    R.provera(o.status == 302 and "login" in (o.header("Location") or ""), "bez prijave: preusmerenje na prijavu")
    o = jelena.get(UVOZ)
    R.provera("1. Fajl ili nalepljena tabela" not in o.text and "/radnik/" in o.url, "radnik ne vidi uvoz (vraća se na svoj ekran)", o.url)
    o = jelena.post(UVOZ, {"akcija": "ucitaj", "tekst": "Humovit 5 l\t5\nIdea 5 l\t5"})
    R.provera(sql("SELECT COUNT(*) FROM uvoz_mapiranje") == "0" and "Učitano" not in o.text, "radnik ne može da učita fajl")
    o = adm.get(UVOZ)
    R.provera("1. Fajl ili nalepljena tabela" in o.text and 'name="fajl"' in o.text and 'name="tekst"' in o.text and 'enctype="multipart/form-data"' in o.text, "administrator vidi korak 1: fajl i polje za lepljenje")
    R.provera("admin/uvoz.php?primer=1" in o.text, "ponuđen je primer fajla")
    R.provera("admin/uvoz.php" in adm.get("/admin/podesavanja.php").text, "Podešavanja imaju stavku Uvoz stanja")
    R.provera("admin/uvoz.php" in adm.get("/admin/popis.php").text, "Popis ima vezu ka uvozu")
    o = adm.post(UVOZ, {"akcija": "ucitaj", "tekst": "x\t1"}, csrf=False, slediti=False)
    R.provera(o.status == 403, "bez CSRF žetona: 403")

    R.odeljak("Primer fajla")
    o = adm.get(UVOZ + "?primer=1")
    R.provera((o.header("Content-Type") or "").startswith("text/csv") and "attachment" in (o.header("Content-Disposition") or ""), "primer se preuzima kao CSV")
    R.provera(o.text.startswith("﻿Šifra;Naziv;Stanje"), "primer ima BOM i zaglavlje", o.text[:40])
    primer = o.text.encode("utf-8")
    o = ucitaj(adm, fajl=primer, ime="primer.csv")
    st = {r["stanje"] for r in redovi(o)}
    R.provera(len(redovi(o)) == 10 and st <= {"prepoznato"}, "svih 10 redova iz primera se upari samo", [(r["naziv"], r["stanje"]) for r in redovi(o)])
    adm.post(UVOZ, {"akcija": "odustani"})

    # ── 2. Učitavanje UTF-8 fajla i pretpostavka kolona ─────────────────────
    R.odeljak("Učitavanje CSV-a (UTF-8, tačka-zarez, zaglavlje)")
    o = ucitaj(adm, fajl=("﻿" + CSV).encode("utf-8"), ime="bluesoft stanje.csv")
    f = forma(o)
    R.provera("Učitano: bluesoft stanje.csv" in tekst(o.text) and "13 redova" in tekst(o.text) and "tačka-zarez" in o.text, "ime fajla, broj redova i razdvajač su prikazani")
    R.provera((f["kol_sifra"], f["kol_naziv"], f["kol_kolicina"], f.get("zaglavlje")) == ("0", "1", "3", "1"), "kolone pogođene po zaglavlju: šifra=1., naziv=2., količina=4.", f)
    R.provera(f["jedinica"] == "komadi" and f["format"] == "sr" and f["napomena"].startswith("Uvoz stanja ") and f.get("zapamti") == "1", "podrazumevano: komadi, srpski brojevi, napomena, pamćenje uključeno")
    pr = po_nazivu(o)
    R.provera(len(pr) == 12, "12 redova podataka (zaglavlje nije red)", len(pr))
    for naziv, (sid, kol) in ocekivano.items():
        R.provera(pr[naziv]["sku"] == sid and pr[naziv]["stanje"] == "prepoznato", "uparen: %s" % naziv, (pr[naziv]["sku"], sid, pr[naziv]["stanje"]))
    R.provera(pr["Kanta za zalivanje 10 l"]["sku"] == 0 and pr["Kanta za zalivanje 10 l"]["stanje"] == "nema" and "nije prepoznato" in pr["Kanta za zalivanje 10 l"]["oznaka"], "nepoznat proizvod nije uparen")
    R.provera(pr["Cmana supstrat 10 l"]["sku"] == 0 and pr["Cmana supstrat 10 l"]["stanje"] == "nema", "isključen artikal (Cmana) se ne uparuje")
    R.provera(stat(o, "artikala se uvozi") == 10 and stat(o, "preskočeno") == 2 and stat(o, "sa greškom") == 0, "zbir: 10 se uvozi, 2 preskočena, 0 grešaka")
    R.provera("= 1.250 kom" in pr["Humovit 5 l"]["ceo"] and "u programu sada: 0 kom" in pr["Humovit 5 l"]["ceo"], "red prikazuje 1.250 kom i trenutno stanje")
    R.provera(stat(o, "preskočeno") == 2 and "u programu sada: 77" not in o.text, "artikal van fajla se ne prikazuje")

    # ── 3. Osvežavanje i ručne izmene ───────────────────────────────────────
    R.odeljak("Osveži pregled i ručni izbor artikla")
    o2 = osvezi(adm, o)
    R.provera(po_nazivu(o2)["Kanta za zalivanje 10 l"]["stanje"] == "nema" and po_nazivu(o2)["Humovit 5 l"]["stanje"] == "prepoznato", "osvežavanje bez izmena ne menja oznake")
    k_i = pr["Kanta za zalivanje 10 l"]["i"]
    o2 = osvezi(adm, o2, **{"sku_%d" % k_i: idea5})
    r = po_nazivu(o2)["Kanta za zalivanje 10 l"]
    R.provera(r["sku"] == idea5 and r["stanje"] == "rucno" and "izabrano" in r["oznaka"], "ručno izabran artikal ostaje posle osvežavanja", r["stanje"])
    h_i = pr["Humovit 5 l"]["i"]
    o2 = osvezi(adm, o2, **{"sku_%d" % h_i: 0})
    R.provera(po_nazivu(o2)["Humovit 5 l"]["stanje"] == "preskoceno" and po_nazivu(o2)["Kanta za zalivanje 10 l"]["stanje"] == "rucno", "preskočen red je označen, raniji ručni izbor se pamti")
    R.provera(stat(o2, "artikala se uvozi") == 10 - 1 + 1 and stat(o2, "preskočeno") == 2, "zbir se menja: -Humovit 5 l, +Kanta", (stat(o2, "artikala se uvozi"), stat(o2, "preskočeno")))
    o2 = osvezi(adm, o2, **{"sku_%d" % h_i: hum5})
    R.provera(po_nazivu(o2)["Humovit 5 l"]["stanje"] == "prepoznato", "vraćen izbor jednak automatskom ponovo je „prepoznato“")
    o2 = osvezi(adm, o2, **{"sku_%d" % k_i: cmana10})
    R.provera(po_nazivu(o2)["Kanta za zalivanje 10 l"]["sku"] == 0 and po_nazivu(o2)["Kanta za zalivanje 10 l"]["stanje"] == "preskoceno", "isključen artikal ne može da se izabere (red se preskače)")
    o2 = osvezi(adm, o2, **{"sku_%d" % k_i: idea5})
    o3 = osvezi(adm, o2, kol_sifra="-1")
    R.provera(po_nazivu(o3)["Kanta za zalivanje 10 l"]["stanje"] == "nema" and forma(o3)["kol_sifra"] == "-1", "promena kolona poništava ručne izbore (redovi su drugačije upareni)")
    o3 = osvezi(adm, o3, kol_sifra="0")
    o = o3
    o_pre = osvezi(adm, o, kol_naziv="3", kol_kolicina="2")
    R.provera(stat(o_pre, "artikala se uvozi") == 0, "pogrešna kolona za naziv (brojevi): ništa se ne upari, nema pogrešnog uvoza", stat(o_pre, "artikala se uvozi"))
    o = osvezi(adm, o_pre, kol_naziv="1", kol_kolicina="3")
    o_isto = osvezi(adm, o, kol_naziv="3", kol_kolicina="3")
    R.provera("moraju biti različite" in poruke(o_isto), "ista kolona za naziv i količinu: odbijeno", poruke(o_isto))
    o = adm.get(UVOZ)

    # ── 4. Zaštite pri uvozu ────────────────────────────────────────────────
    R.odeljak("Zaštite pre uvoza")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    f = forma(o)
    f.pop("kraj")
    r1 = adm.post(UVOZ, f)
    R.provera("nepotpuna" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "forma bez završnog polja (server odsekao polja): ne uvozi", poruke(r1))
    r1 = uvezi(adm, o, napomena="ab")
    R.provera("Upišite napomenu" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "kratka napomena: ne uvozi", poruke(r1))
    r1 = uvezi(adm, o, jedinica="paketi")
    R.provera("posle poslednjeg pregleda" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "promenjena jedinica bez osvežavanja: ne uvozi", poruke(r1))
    o = adm.get(UVOZ)
    r1 = uvezi(adm, o, v="00000000")
    R.provera("posle poslednjeg pregleda" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "zastarela forma (druga kartica): ne uvozi", poruke(r1))
    o = adm.get(UVOZ)
    r1 = adm.post(UVOZ, forma(o), csrf=False, slediti=False)
    R.provera(r1.status == 403 and sql_int("SELECT COUNT(*) FROM unosi") == pre, "uvoz bez CSRF žetona: 403")
    r1 = uvezi(jelena, o)
    R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre, "radnik ne može da pokrene uvoz")
    r1 = adm.post(UVOZ, dict(forma(o), **{"sku[9999]": str(hum5), "sku[abc]": "1", "sku[0]": "-4"}))
    R.provera("Stanje je uvezeno" in poruke(r1), "izmišljeni redovi i vrednosti u formi se ignorišu (uvoz prolazi)", poruke(r1))

    # ── 5. Uspešan uvoz ─────────────────────────────────────────────────────
    R.odeljak("Uvoz: stanje se postavlja, istorija i dnevnik")
    for naziv, (sid, kol) in ocekivano.items():
        R.provera(stanje(sid) == kol, "stanje posle uvoza: %s = %d" % (naziv, kol), stanje(sid))
    R.provera(stanje(idea5) == 77, "artikal koga nema u fajlu ostaje netaknut (Idea 5 l = 77)")
    R.provera(stanje(cmana10) == 0 and sql("SELECT COUNT(*) FROM unosi WHERE sku_id=%d" % cmana10) == "0", "neuparen red nije ništa upisao")
    R.provera("Promenjeno artikala: 10" in poruke(r1) and "preskočenih redova: 2" in poruke(r1), "poruka: 10 promenjeno, 2 preskočena", poruke(r1))
    R.provera(r1.url.endswith("/admin/stanje.php"), "posle uvoza se otvara Stanje", r1.url)
    R.provera(sql("SELECT COUNT(*) FROM unosi WHERE tip='korekcija' AND napomena LIKE 'Uvoz stanja%%'") == "10", "10 korekcija u istoriji sa napomenom")
    R.provera(sql("SELECT napomena FROM unosi WHERE sku_id=%d AND napomena LIKE 'Uvoz%%'" % hum5).endswith("[bilo 0, prebrojano 1250]"), "korekcija pamti staro i novo stanje")
    R.provera("Uvoz stanja iz fajla" in sql("SELECT detalji FROM dnevnik WHERE objekat='podesavanje' ORDER BY id DESC LIMIT 1"), "upis u dnevnik izmena")
    R.provera(sql("SELECT COUNT(*) FROM uvoz_mapiranje") == "20", "zapamćeno 20 uparivanja (naziv i šifra za 10 redova)", sql("SELECT COUNT(*) FROM uvoz_mapiranje"))
    R.provera("1. Fajl ili nalepljena tabela" in adm.get(UVOZ).text, "posle uvoza forma je vraćena na korak 1")
    o = adm.get("/admin/stanje.php")
    R.provera("1.250" in o.text, "Stanje prikazuje novu količinu")

    R.odeljak("Ponovni uvoz istog fajla: zapamćena uparivanja, bez promena")
    o = ucitaj(adm, fajl=CSV.encode("utf-8"))
    pr = po_nazivu(o)
    R.provera(all(pr[n]["stanje"] == "memorija" and "zapamćeno" in pr[n]["oznaka"] for n in ocekivano), "svi upareni redovi su „zapamćeno“")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    r1 = uvezi(adm, o)
    R.provera("ništa nije menjano" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "isto stanje: ništa se ne upisuje", poruke(r1))

    # ── 6. Nalepljena tabela, duplikati, greške u količini, pamćenje ────────
    R.odeljak("Nalepljena tabela bez zaglavlja: duplikati se sabiraju")
    o = ucitaj(adm, "Zemlja Humus petica\t300\nZemlja Humus petica\t20\nIdea 10 l\t3x\n")
    f = forma(o)
    R.provera("zaglavlje" not in f and (f["kol_naziv"], f["kol_kolicina"]) == ("0", "1") and "tabulator" in o.text, "bez zaglavlja: naziv=1., količina=2., razdvajač tabulator", f)
    pr = redovi(o)
    R.provera([r["stanje"] for r in pr] == ["nema", "nema", "pogresno"], "nepoznat naziv nije uparen; Idea 10 l sa količinom „3x“ je upareno ali pogrešno", [r["stanje"] for r in pr])
    i0, i1, i2 = [r["i"] for r in pr]
    o = osvezi(adm, o, **{"sku_%d" % i0: hum5, "sku_%d" % i1: hum5})
    R.provera(stat(o, "sa greškom") == 1 and "Količina nije broj" in o.text, "red sa „3x“ je označen kao greška")
    R.provera("njihove količine se sabiraju" in tekst(o.text), "prikazano je upozorenje o sabiranju duplikata")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    r1 = uvezi(adm, o)
    R.provera("Idea 10 l: količina nije broj" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "greška u količini sprečava ceo uvoz", poruke(r1))
    o = adm.get(UVOZ)
    R.provera(po_nazivu(o)["Zemlja Humus petica"]["sku"] == hum5, "ručni izbori su sačuvani i posle neuspelog uvoza")
    o = osvezi(adm, o, **{"sku_%d" % i2: 0})
    r1 = uvezi(adm, o, napomena="Popis radnja")
    R.provera(stanje(hum5) == 320 and stanje(idea10) == 225, "dva reda istog artikla: 300 + 20 = 320; Idea 10 l neizmenjena", (stanje(hum5), stanje(idea10)))
    R.provera(sql("SELECT COUNT(*) FROM unosi WHERE sku_id=%d AND napomena LIKE 'Popis radnja%%'" % hum5) == "1", "duplikati daju jednu korekciju")
    R.provera(sql("SELECT sku_id FROM uvoz_mapiranje WHERE kljuc='n:zemlja humus petica'") == str(hum5), "izbor „Zemlja Humus petica“ → Humovit 5 l je zapamćen")
    o = ucitaj(adm, "Zemlja Humus petica\t10")
    R.provera(po_nazivu(o)["Zemlja Humus petica"]["stanje"] == "memorija" and po_nazivu(o)["Zemlja Humus petica"]["sku"] == hum5, "sledeći put se red upari iz memorije")
    adm.post(UVOZ, {"akcija": "odustani"})

    R.odeljak("Bez pamćenja uparivanja")
    n_pre = sql_int("SELECT COUNT(*) FROM uvoz_mapiranje")
    o = ucitaj(adm, "Poseban naziv\t5")
    o = osvezi(adm, o, **{"sku_%d" % redovi(o)[0]["i"]: idea5, "zapamti": None})
    r1 = uvezi(adm, o, **{"zapamti": None})
    R.provera(stanje(idea5) == 5 and sql_int("SELECT COUNT(*) FROM uvoz_mapiranje") == n_pre, "bez kvačice se uparivanje ne pamti", sql_int("SELECT COUNT(*) FROM uvoz_mapiranje"))

    # ── 7. Jedinice: paketi i palete ────────────────────────────────────────
    R.odeljak("Količina u paketima i paletama")
    o = ucitaj(adm, "Naziv\tKol\nHumovit 5 l\t12\nHumovit 10 l\t2\nHumovit 25 l\t1\n")
    R.provera(forma(o).get("zaglavlje") == "1", "tekstualni prvi red je prepoznat kao zaglavlje")
    o = osvezi(adm, o, jedinica="paketi")
    pr = po_nazivu(o)
    R.provera("= 120 kom" in pr["Humovit 5 l"]["ceo"] and "= 12 kom" in pr["Humovit 10 l"]["ceo"], "12 paketa × 10 = 120 kom; 2 paketa × 6 = 12 kom")
    R.provera(stat(o, "sa greškom") == 1 and "Nije podešeno koliko komada ima u paketu" in o.text, "Humovit 25 l nema paket: greška")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    r1 = uvezi(adm, o)
    R.provera(sql_int("SELECT COUNT(*) FROM unosi") == pre and "nije podešeno" in poruke(r1), "red sa greškom blokira uvoz", poruke(r1))
    o = adm.get(UVOZ)
    o = osvezi(adm, o, **{"sku_%d" % po_nazivu(o)["Humovit 25 l"]["i"]: 0})
    r1 = uvezi(adm, o)
    R.provera(stanje(hum5) == 120 and stanje(hum10) == 12 and stanje(hum25) == 120, "paketi pretvoreni u komade: 120 i 12; preskočen red se ne dira (120)", (stanje(hum5), stanje(hum10), stanje(hum25)))
    o = ucitaj(adm, "Naziv\tKol\nHumovit 5 l\t2\nIdea 10 l\t2\nFloris Savacoop 10 l\t1\n")
    o = osvezi(adm, o, jedinica="palete")
    pr = po_nazivu(o)
    R.provera("= 900 kom" in pr["Humovit 5 l"]["ceo"] and "= 450 kom" in pr["Idea 10 l"]["ceo"] and "= 270 kom" in pr["Floris Savacoop 10 l"]["ceo"], "palete: 2×450=900, Idea 2×225=450, Floris 1×270")
    r1 = uvezi(adm, o)
    R.provera((stanje(hum5), stanje(idea10), stanje(flo10)) == (900, 450, 270), "stanje postavljeno po paletama", (stanje(hum5), stanje(idea10), stanje(flo10)))

    # ── 8. Formati brojeva ──────────────────────────────────────────────────
    R.odeljak("Formati brojeva")
    o = ucitaj(adm, "Naziv\tKol\nHumovit 5 l\t120,000\nHumovit 10 l\t1.250\nIdea 5 l\t12,5\nIdea 10 l\t-3\nHumovit 25 l\t1,250\n")
    pr = po_nazivu(o)
    R.provera("= 120 kom" in pr["Humovit 5 l"]["ceo"], "srpski: 120,000 = 120")
    R.provera("= 1.250 kom" in pr["Humovit 10 l"]["ceo"], "srpski: 1.250 = 1250")
    R.provera("Količina nije ceo broj" in pr["Idea 5 l"]["ceo"], "12,5 nije ceo broj: greška")
    R.provera("Količina je negativna" in pr["Idea 10 l"]["ceo"], "negativna količina: greška")
    R.provera("Količina nije ceo broj" in pr["Humovit 25 l"]["ceo"], "srpski: 1,250 = 1,25 → greška (ne tiho 1250)")
    o = osvezi(adm, o, format="en")
    pr = po_nazivu(o)
    R.provera("= 1.250 kom" in pr["Humovit 25 l"]["ceo"] and "= 120.000 kom" in pr["Humovit 5 l"]["ceo"], "engleski: 1,250 = 1250 i 120,000 = 120000 (pregled to pokazuje)")
    adm.post(UVOZ, {"akcija": "odustani"})
    r = subprocess.run(["php", "-r", """
        require '%s/inc/uvoz.php';
        $t = ['120'=>120,'1 250'=>1250,'1.234,5'=>1234.5,'1,234.5'=>1234.5,'1.234.567'=>1234567,'0,5'=>0.5,'0.500'=>0.5,'+7'=>7,'1.23.4'=>null,'abc'=>null,'1e3'=>null,''=>null];
        foreach ($t as $k => $v) { $x = uvoz_broj((string)$k); if ($x !== ($v === null ? null : (float)$v)) { echo "RAZLIKA [$k] ", var_export($x, true), "\\n"; } }
        echo "gotovo";
    """ % str(APP).replace("\\", "/")], capture_output=True, text=True)
    R.provera(r.stdout.strip() == "gotovo", "uvoz_broj: tabela slučajeva", r.stdout + r.stderr)

    # ── 9. Kodiranja i neispravni fajlovi ───────────────────────────────────
    R.odeljak("Windows-1250 (srpski Excel) i neispravni fajlovi")
    o = ucitaj(adm, fajl="Naziv;Stanje\r\nMalč Farmerkop Žuti 50 l;7\r\n".encode("cp1250"), ime="excel.csv")
    r = redovi(o)
    R.provera(len(r) == 1 and r[0]["naziv"] == "Malč Farmerkop Žuti 50 l" and r[0]["sku"] == malc_zu, "Windows-1250: č, ž i Ž se ispravno čitaju i upare", [(x["naziv"], x["sku"]) for x in r])
    adm.post(UVOZ, {"akcija": "odustani"})
    o = ucitaj(adm, fajl=b"PK\x03\x04" + b"\x00" * 200, ime="stanje.xlsx")
    R.provera("nije tekstualni fajl" in poruke(o) and "Učitano" not in o.text, "xlsx / zip se odbija sa objašnjenjem", poruke(o))
    o = ucitaj(adm, fajl=b"\xd0\xcf\x11\xe0" + b"\x00" * 100, ime="stanje.xls")
    R.provera("nije tekstualni fajl" in poruke(o), "stari .xls se odbija")
    o = ucitaj(adm, fajl=b"", ime="prazan.csv")
    R.provera("prazan" in poruke(o) and "Učitano" not in o.text, "prazan fajl se odbija", poruke(o))
    o = ucitaj(adm, "   \n  ")
    R.provera("Izaberite fajl ili nalepite" in poruke(o), "prazno polje za lepljenje se odbija", poruke(o))
    o = ucitaj(adm, "samo jedna kolona\nbez razdvajača\n")
    R.provera("Ne prepoznajem kolone" in poruke(o), "jedna kolona: objašnjenje", poruke(o))
    o = ucitaj(adm, fajl=(b"Naziv;Stanje\n" + b"Humovit 5 l;1\n" * 90000), ime="veliki.csv")
    R.provera("prevelik" in poruke(o), "fajl veći od 1 MB se odbija", poruke(o))
    o = ucitaj(adm, "Naziv\tKol\n" + "".join("Humovit 5 l %d\t1\n" % i for i in range(601)))
    R.provera("Previše redova" in poruke(o), "601 red podataka se odbija", poruke(o))
    o = ucitaj(adm, "Naziv\tKol\n" + "".join("Artikal %d\t1\n" % i for i in range(600)))
    R.provera(len(redovi(o)) == 600 and stat(o, "preskočeno") == 600, "600 redova podataka je dozvoljeno i prikazuje se")
    pre = sql_int("SELECT COUNT(*) FROM unosi")
    r1 = uvezi(adm, o)
    R.provera("Nijedan red nije uparen" in poruke(r1) and sql_int("SELECT COUNT(*) FROM unosi") == pre, "600 poslatih polja stiže celo; bez uparenih redova ništa se ne uvozi", poruke(r1))
    adm.post(UVOZ, {"akcija": "odustani"})

    R.odeljak("Bezbednost prikaza")
    o = ucitaj(adm, "Naziv\tKol\n<script>alert(1)</script> Humovit 5 l\t5\n\"Humovit\"\" 10 l\"\t5\n")
    R.provera("<script>alert(1)" not in o.text and "&lt;script&gt;alert(1)&lt;/script&gt; Humovit 5 l" in o.text, "HTML iz fajla se ispisuje bezbedno (escape)")
    R.provera(po_nazivu(o)["<script>alert(1)</script> Humovit 5 l"]["sku"] == hum5, "prepoznavanje radi i uz dodatne reči i znake")
    o = adm.post(UVOZ, {"akcija": "odustani"})
    R.provera("1. Fajl ili nalepljena tabela" in o.text and "Uvoz je otkazan" in poruke(o), "Odustani vraća na korak 1 bez promene stanja")
    r1 = adm.post(UVOZ, {"akcija": "osvezi", "kraj": "1"})
    R.provera("Nema učitanog fajla" in poruke(r1), "osvežavanje bez učitanog fajla: poruka")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
