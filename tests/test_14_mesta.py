#!/usr/bin/env python3
"""Mesta istovara: jedan utovar (kamion) za više kupaca – Mesto 8, 9, 5 … (broj upisuje vlasnik, kupac nije
obavezan), roba po mestima (i zbirna paleta pola rizle + pola oblutka), pomeranje, izmene i uklanjanje mesta,
radnik (dodavanje za izabrano mesto), potvrda (kupac po mestu), štampa i deljenje po mestima, otkazivanje,
ručna prodaja, nadogradnja baze sa verzije 6 (postojeći spisak dobija Mesto 1 sa svojim kupcem)."""
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


def mesta(sp):
    """Mesta spiska redom, red po mestu: "broj|kupac|adresa"."""
    return sql("SELECT CONCAT(m.broj, '|', COALESCE(k.naziv, ''), '|', COALESCE(m.adresa, '')) FROM spisak_mesta m LEFT JOIN kupci k ON k.id = m.kupac_id "
               "WHERE m.spisak_id=%d ORDER BY m.redosled, m.id" % sp)


def redom(sp):
    return sql("SELECT GROUP_CONCAT(broj ORDER BY redosled, id) FROM spisak_mesta WHERE spisak_id=%d" % sp)


def mesto_id(sp, broj):
    return sql_int("SELECT id FROM spisak_mesta WHERE spisak_id=%d AND broj=%d" % (sp, broj))


def verzija():
    return sql("SELECT vrednost FROM podesavanja WHERE kljuc='schema_verzija'")


try:
    ids = pripremi_sajt(site)
    adm = prijavi_admina(site)
    jelena = prijavi_radnika(site, ids["Jelena"], "4321")
    hum10, hum50 = sku_id("Humovit", 10), sku_id("Humovit", 50)
    rizla = sku_id("Bela rizla", 20, "kg", "Sitnija")
    oblutak = sku_id("Beli oblutak", 20, "kg", "4-6 cm")

    # ═══ A. Nadogradnja sa verzije 6: postojeći spiskovi dobijaju Mesto 1 ═══
    R.odeljak("Nadogradnja baze sa verzije 6 (spisak = jedan kupac)")
    sql("ALTER TABLE spisak_stavke ADD UNIQUE KEY uq_stavka_paleta (spisak_id, paleta_id, sku_id), DROP INDEX uq_stavka_mesto, DROP COLUMN mesto_id; "
        "ALTER TABLE spisak_palete DROP COLUMN mesto_id; DROP TABLE spisak_mesta; "
        "ALTER TABLE spiskovi MODIFY kupac_id INT UNSIGNED NOT NULL; "
        "UPDATE podesavanja SET vrednost='6' WHERE kljuc='schema_verzija'")
    admin_id = sql_int("SELECT id FROM korisnici WHERE uloga='admin'")
    sql("INSERT INTO kupci (naziv, napravljen) VALUES ('Stari kupac', NOW()), ('Drugi stari', NOW())")
    k1, k2 = sql_int("SELECT id FROM kupci WHERE naziv='Stari kupac'"), sql_int("SELECT id FROM kupci WHERE naziv='Drugi stari'")
    sql("INSERT INTO spiskovi (kupac_id, datum, status, napravio_id, napravljen, izmenjen) VALUES "
        "(%d, CURDATE(), 'za_utovar', %d, NOW(), NOW()), (%d, CURDATE(), 'nacrt', %d, NOW(), NOW())" % (k1, admin_id, k2, admin_id))
    s1, s2 = sql_int("SELECT MIN(id) FROM spiskovi"), sql_int("SELECT MAX(id) FROM spiskovi")
    sql("INSERT INTO spisak_palete (spisak_id, naziv, broj, redosled) VALUES (%d, 'Zbirna paleta 1', 2, 10)" % s1)
    p1 = sql_int("SELECT MAX(id) FROM spisak_palete")
    sql("INSERT INTO spisak_stavke (spisak_id, paleta_id, sku_id, kolicina, palete, paketi, komadi, redosled) VALUES "
        "(%d, 0, %d, 270, 1, 0, 0, 10), (%d, %d, %d, 12, 0, 0, 6, 20), (%d, %d, %d, 10, 0, 0, 5, 30), (%d, 0, %d, 40, 1, 0, 0, 10)"
        % (s1, hum10, s1, p1, hum10, s1, p1, hum50, s2, hum50))
    R.provera(not sql("SHOW TABLES LIKE 'spisak_mesta'") and verzija() == "6", "priprema: baza je kao verzija 6, sa dva stara spiska")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and all(site.log().count("nadogradnja baze na verziju %d" % v) == 1 for v in (7, 8, 9, 10, 11, 12)), "posle prvog otvaranja: verzija 12 (nadogradnje 7–12 po jednom)")
    R.provera(sql("SELECT spisak_id, broj, kupac_id FROM spisak_mesta ORDER BY spisak_id") == "%d\t1\t%d\n%d\t1\t%d" % (s1, k1, s2, k2),
              "svaki stari spisak ima Mesto 1 sa svojim kupcem")
    m_s1 = mesto_id(s1, 1)
    R.provera(sql("SELECT GROUP_CONCAT(DISTINCT mesto_id) FROM spisak_stavke WHERE spisak_id=%d" % s1) == str(m_s1)
              and sql("SELECT mesto_id FROM spisak_palete WHERE id=%d" % p1) == str(m_s1), "stavke i zbirna paleta su prešle na to mesto")
    kljucevi = sql("SELECT GROUP_CONCAT(DISTINCT index_name ORDER BY index_name) FROM information_schema.statistics WHERE table_schema='%s' AND table_name='spisak_stavke'" % DB_NAME)
    R.provera("uq_stavka_mesto" in kljucevi and "uq_stavka_paleta" not in kljucevi, "novi jedinstveni ključ: isti artikal sme za više mesta", kljucevi)
    R.provera(sql("SELECT is_nullable FROM information_schema.columns WHERE table_schema='%s' AND table_name='spiskovi' AND column_name='kupac_id'" % DB_NAME) == "YES",
              "kupac na spisku više nije obavezan")
    o = adm.get(SP + "?id=%d" % s1)
    t = tekst(o.text)
    R.provera(o.status == 200 and "Mesto 1" in t and "Stari kupac" in t and "Zbirna paleta 1" in t, "stari spisak se otvara: Mesto 1 · Stari kupac, sa zbirnom paletom")
    o = adm.get("/admin/stanje.php")
    blok = o.text[re.search(r"<h3>\s*Humovit\s*<", o.text).start():]
    R.provera("rezervisano 282" in tekst(blok[blok.index(">10 l<"):][:1500]), "rezervacija starog spiska radi: Humovit 10 l 270 + 12 = 282")
    sql("UPDATE podesavanja SET vrednost='6' WHERE kljuc='schema_verzija'")
    Client(site.base).get("/login.php")
    R.provera(verzija() == "12" and sql_int("SELECT COUNT(*) FROM spisak_mesta") == 2, "ponovljena nadogradnja ne pravi duplikate")
    adm.post(SP, {"akcija": "status", "id": s1, "ocekivan": "za_utovar", "novi": "otkazano"})
    adm.post(SP, {"akcija": "status", "id": s2, "ocekivan": "nacrt", "novi": "otkazano"})

    for sku, kol, nacin in [(hum10, "2", "palete"), (hum50, "2", "palete"), (rizla, "1", "palete"), (oblutak, "1", "palete")]:
        jelena.get("/radnik/index.php")
        jelena.post("/radnik/index.php", {"akcija": "dodaj", "sku_id": sku, "kolicina": kol, "nacin": nacin, "kljuc": nov_kljuc()})
    pre = (stanje(hum10), stanje(hum50), stanje(rizla), stanje(oblutak))
    R.provera(pre == (540, 80, 50, 50), "priprema stanja: Humovit 10 l 540, 50 l 80, rizla 50, oblutak 50", pre)

    # ═══ B. Utovar sa više mesta ═══
    R.odeljak("Novi utovar: prvo mesto 8, bez kupca")
    adm.get("/admin/utovar.php")
    o = adm.post("/admin/utovar.php", {"kupac": "", "datum": danas_bg(), "vozilo": "NS 100-AA", "vozac": "Pera", "mesto_broj": "8"})
    sp = sql_int("SELECT MAX(id) FROM spiskovi")
    m8 = mesto_id(sp, 8)
    R.provera("%s je napravljen sa mestom „Mesto 8“" % utovar(sp) in poruke(o) and mesta(sp) == "8||", "utovar je napravljen sa prvim mestom: Mesto 8, bez kupca", poruke(o))
    R.provera(sql("SELECT kupac_id IS NULL FROM spiskovi WHERE id=%d" % sp) == "1", "kupac se vodi na mestu, ne na spisku")
    R.provera(re.search(r'id="dodaj-%d" open' % m8, o.text) is not None, "prazno mesto ima otvorenu formu za robu")
    R.provera('value="9"' in o.text.split('id="novo_broj"')[1][:200], "Dodaj mesto: predložen je sledeći broj (9)")

    R.odeljak("Dodaj mesto: broj po izboru, kupac nije obavezan")
    o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "9", "novo_kupac": "Agrocentar <b>NS</b>", "novo_adresa": "Novi Sad, Futoški put 12"})
    m9 = mesto_id(sp, 9)
    R.provera("Dodato Mesto 9 · Agrocentar <b>NS</b> – sada dodajte robu za to mesto" in poruke(o), "dodato Mesto 9 sa kupcem i adresom", poruke(o))
    R.provera(o.url.endswith("&m=%d#dodaj-%d" % (m9, m9)) and re.search(r'id="dodaj-%d" open' % m9, o.text), "posle dodavanja mesta otvara se forma za robu tog mesta", o.url)
    R.provera("Agrocentar &lt;b&gt;NS&lt;/b&gt;" in o.text and "<b>NS</b>" not in o.text, "kupac se ispisuje bezbedno")
    adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "5", "novo_kupac": "", "novo_adresa": ""})
    m5 = mesto_id(sp, 5)
    o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "8"})
    R.provera("Mesto 8 već postoji" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sp) == 3, "isti broj dva puta: odbijeno", poruke(o))
    for b in ("0", "1000", "a1", ""):
        o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": b})
        R.provera("od 1 do 999" in poruke(o), "broj mesta „%s“: odbijeno" % b, poruke(o))
    R.provera(mesta(sp) == "8||\n9|Agrocentar <b>NS</b>|Novi Sad, Futoški put 12\n5||", "mesta su redom kako su dodata: 8, 9, 5", mesta(sp))
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('value="10"' in o.text.split('id="novo_broj"')[1][:200], "sledeći predlog: 10 (najveći broj + 1)")

    R.odeljak("Roba po mestima (kao na papiru)")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": "0", "sku_id": hum50, "pal": "1"})
    R.provera("Dodato za Mesto 8: Humovit · 50 l – 40 kom" in poruke(o), "Mesto 8: 1 paleta Humovita 50 l", poruke(o))
    R.provera(o.url.endswith("&m=%d#mesto-%d" % (m8, m8)), "posle dodavanja se vidi isto mesto (i forma za još robe)", o.url)
    o = adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m8, "p_naziv": "", "p_broj": "1"})
    pid = sql_int("SELECT MAX(id) FROM spisak_palete")
    R.provera(sql("SELECT mesto_id, naziv, broj FROM spisak_palete WHERE id=%d" % pid) == "%d\tZbirna paleta 1\t1" % m8, "zbirna paleta je napravljena za Mesto 8")
    R.provera("za Mesto 8" in poruke(o) and o.url.endswith("&m=%d&u=%d#dodaj-%d" % (m8, pid, m8)), "posle pravljenja palete u „Gde ide“ je izabrana ta paleta", o.url)
    for sku in (rizla, oblutak):
        o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m8, "paleta_id": pid, "sku_id": sku, "kom": "25"})
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(sku_id, ':', kolicina) ORDER BY id) FROM spisak_stavke WHERE paleta_id=%d AND mesto_id=%d" % (pid, m8)) == "%d:25,%d:25" % (rizla, oblutak),
              "zbirna paleta: 1/2 rizla bela sitna + 1/2 oblutak beli 4-6 cm (po 25 kom)")
    adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m9, "paleta_id": "0", "sku_id": hum10, "pal": "1"})
    adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m5, "paleta_id": "0", "sku_id": hum10, "pak": "3"})
    R.provera(sql("SELECT GROUP_CONCAT(CONCAT(mesto_id, ':', kolicina) ORDER BY id) FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d" % (sp, hum10)) == "%d:270,%d:18" % (m9, m5),
              "isti artikal za dva mesta: dve stavke (Mesto 9: 270, Mesto 5: 18)")
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m9, "paleta_id": "0", "sku_id": hum10, "pal": "2"})
    R.provera("je već bila na spisku za Mesto 9" in poruke(o) and sql_int("SELECT kolicina FROM spisak_stavke WHERE mesto_id=%d AND sku_id=%d" % (m9, hum10)) == 540,
              "isti artikal na istom mestu: zamenjuje se količina", poruke(o))
    adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m9, "paleta_id": "0", "sku_id": hum10, "pal": "1"})
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "paleta_id": "0", "sku_id": hum50, "kom": "1"})
    R.provera("Izaberite mesto" in poruke(o), "bez mesta (a spisak ima više mesta): odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m9, "paleta_id": pid, "sku_id": hum50, "kom": "1"})
    R.provera("nije na tom mestu" in poruke(o), "zbirna paleta drugog mesta: odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m_s1, "paleta_id": "0", "sku_id": hum50, "kom": "1"})
    R.provera("To mesto više nije na spisku" in poruke(o), "mesto sa drugog spiska: odbijeno", poruke(o))
    o = adm.post(SP, {"akcija": "ostalo", "id": sp, "mesto_id": m5, "o_naziv": "Saksije 30 cm", "o_kolicina": "40", "o_jedinica": "kom"})
    R.provera("Dodato za Mesto 5 (ostalo): Saksije 30 cm – 40 kom" in poruke(o) and sql("SELECT mesto_id FROM spisak_stavke WHERE spisak_id=%d AND sku_id IS NULL" % sp) == str(m5),
              "ostalo ide za izabrano mesto", poruke(o))

    R.odeljak("Prikaz po mestima")
    o = adm.get(SP + "?id=%d" % sp)
    t = tekst(o.text)
    poz = [o.text.index('id="mesto-%d"' % m) for m in (m8, m9, m5)]
    R.provera(poz == sorted(poz) and re.findall(r'class="mesto-krug" aria-hidden="true">(\d+)<', o.text) == ["8", "9", "5"], "kartice mesta redom: 8, 9, 5 (broj u krugu)")
    R.provera(o.text.count('class="mesto-dodaj"') == 3 and o.text.count('name="mesto_id" value="%d"' % m9) == 2, "svako mesto ima svoje forme (roba i ostalo, zbirna paleta)")
    R.provera('id="paleta_id_%d"' % m8 in o.text and 'id="paleta_id_%d"' % m9 not in o.text, "„Gde ide“ postoji samo kod mesta koje ima zbirnu paletu")
    R.provera("3 mesta: 8, 9, 5" in t and re.search(r'<span class="broj">3</span><span class="opis">mesta istovara', o.text), "naslov: 3 mesta: 8, 9, 5; zbir: 3 mesta istovara")
    # Mesto 8: Humovit 50 l 40 × 18 kg + rizla 25 × 20 + oblutak 25 × 20 = 720 + 500 + 500 = 1.720 kg; 1 paleta + 1 zbirna
    R.provera("3 stavke · 2 palete · ≈ 1.720 kg" in t, "zbir za Mesto 8: 3 stavke, 2 palete, ≈ 1.720 kg", re.findall(r"\d+ stavk\w ·[^·]{0,30}·[^A-Z]{0,30}", t))
    R.provera("1 stavka · 1 paleta · ≈ 972 kg" in t, "zbir za Mesto 9: 1 stavka, 1 paleta, ≈ 972 kg")
    R.provera('form="izmene"' in o.text and re.search(r'<form method="post" id="izmene"[^>]*>\s*<input type="hidden" name="csrf"', o.text), "izmene na svim mestima idu kroz jednu formu")
    R.provera(o.text.index('name="akcija" value="stavke" tabindex="-1"') < o.text.index('name="pomeri_mesto"'), "Enter u polju čuva izmene (skriveno dugme je prvo), ne pomera i ne briše")
    o = adm.get("/admin/utovar.php")
    t = tekst(o.text)
    R.provera("3 mesta: 8, 9, 5" in t and "Agrocentar <b>NS</b>" in t, "lista utovara: brojevi mesta i kupci", re.findall(r"3 mesta[^#]{0,80}", t))

    R.odeljak("Pomeranje mesta")
    o = adm.post(SP, {"id": sp, "pomeri_mesto": "%d:gore" % m5})
    R.provera("Pomereno Mesto 5 gore" in poruke(o) and redom(sp) == "8,5,9", "Mesto 5 gore: 8, 5, 9", (poruke(o), redom(sp)))
    R.provera(o.url.endswith("#mesto-%d" % m5), "posle pomeranja stranica je na tom mestu", o.url)
    o = adm.post(SP, {"id": sp, "pomeri_mesto": "%d:gore" % m8})
    R.provera("Nema izmena" in poruke(o) and redom(sp) == "8,5,9", "prvo mesto ne ide više gore")
    o = adm.post(SP, {"id": sp, "pomeri_mesto": "%d:dole" % m5})
    R.provera(redom(sp) == "8,9,5", "i nazad dole: 8, 9, 5")
    o = adm.get(SP + "?id=%d" % sp)
    glava8 = o.text[o.text.index('id="mesto-%d"' % m8):o.text.index('id="mesto-%d"' % m9)]
    glava5 = o.text[o.text.index('id="mesto-%d"' % m5):]
    R.provera('value="%d:gore"' % m8 not in glava8 and 'value="%d:dole"' % m8 in glava8 and 'value="%d:dole"' % m5 not in glava5[:3000], "prvo mesto nema „gore“, poslednje nema „dole“")

    R.odeljak("Izmena mesta: broj, kupac, adresa")
    s5 = sql_int("SELECT id FROM spisak_stavke WHERE mesto_id=%d AND sku_id=%d" % (m5, hum10))
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "m_broj[%d]" % m5: "4", "m_kupac[%d]" % m8: "Cvećara Flora", "m_adresa[%d]" % m9: "",
                      "m_broj[%d]" % m8: "8", "m_broj[%d]" % m9: "9", "m_kupac[%d]" % m9: "Agrocentar <b>NS</b>"})
    R.provera("Sačuvane izmene (3)" in poruke(o) and mesta(sp) == "8|Cvećara Flora|\n9|Agrocentar <b>NS</b>|\n4||", "Mesto 5 → 4, Mesto 8 dobija kupca, Mestu 9 obrisana adresa", (poruke(o), mesta(sp)))
    n_kupaca = sql_int("SELECT COUNT(*) FROM kupci")
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "m_broj[%d]" % m8: "9", "m_kupac[%d]" % m8: "Neko Treći", "kom[%d]" % s5: "7"})
    R.provera("Dva mesta ne mogu imati isti broj (9)" in poruke(o) and mesta(sp) == "8|Cvećara Flora|\n9|Agrocentar <b>NS</b>|\n4||", "dva mesta sa brojem 9: odbijeno", poruke(o))
    R.provera(sql_int("SELECT COUNT(*) FROM kupci") == n_kupaca and sql_int("SELECT kolicina FROM spisak_stavke WHERE id=%d" % s5) == 18,
              "greška poništava celu izmenu (ni novi kupac ni količina nisu upisani)")
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "m_kupac[%d]" % m5: "Neko Treći", "pl_broj[%d]" % pid: "0"})
    R.provera("od 1 do 999" in poruke(o) and sql_int("SELECT kupac_id IS NULL FROM spisak_mesta WHERE id=%d" % m5) == 1 and sql_int("SELECT COUNT(*) FROM kupci") == n_kupaca,
              "greška na paleti poništava i već upisanu izmenu mesta", poruke(o))
    o = adm.post(SP, {"akcija": "stavke", "id": sp, "m_broj[%d]" % m5: "x"})
    R.provera("Mesto 4: broj mesta mora biti od 1 do 999" in poruke(o), "neispravan broj pri izmeni: odbijeno", poruke(o))

    R.odeljak("Uklanjanje mesta")
    adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "2"})
    m2 = mesto_id(sp, 2)
    adm.post(SP, {"akcija": "nova_paleta", "id": sp, "mesto_id": m2})
    pid2 = sql_int("SELECT MAX(id) FROM spisak_palete")
    adm.post(SP, {"akcija": "dodaj", "id": sp, "mesto_id": m2, "paleta_id": pid2, "sku_id": hum50, "kom": "3"})
    R.provera(sql("SELECT naziv FROM spisak_palete WHERE id=%d" % pid2) == "Zbirna paleta 2", "druga zbirna paleta na kamionu je „Zbirna paleta 2“")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('data-potvrda="Ukloniti Mesto 2 sa svom robom (1 stavka)?"' in o.text, "uklanjanje mesta traži potvrdu i kaže koliko robe ima")
    o = adm.post(SP, {"id": sp, "ukloni_mesto": m2})
    R.provera("Uklonjeno Mesto 2 sa svom robom (1 stavka)" in poruke(o)
              and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE id=%d" % m2) + sql_int("SELECT COUNT(*) FROM spisak_palete WHERE id=%d" % pid2)
              + sql_int("SELECT COUNT(*) FROM spisak_stavke WHERE mesto_id=%d" % m2) == 0, "mesto je uklonjeno sa paletom i robom", poruke(o))
    o = adm.post(SP, {"id": sp, "ukloni_mesto": m_s1})
    R.provera("To mesto više nije na spisku" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE id=%d" % m_s1) == 1, "mesto drugog spiska se ne može ukloniti odavde")

    R.odeljak("Slanje na utovar: prazno mesto se ne šalje")
    adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "3"})
    m3 = mesto_id(sp, 3)
    o = adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera("Mesto 3 nema nijednu stavku" in poruke(o) and sql("SELECT status FROM spiskovi WHERE id=%d" % sp) == "nacrt", "mesto bez robe: spisak ostaje nacrt", poruke(o))
    adm.post(SP, {"id": sp, "ukloni_mesto": m3})
    o = adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "nacrt", "novi": "za_utovar"})
    R.provera(sql("SELECT status FROM spiskovi WHERE id=%d" % sp) == "za_utovar", "spisak je poslat na utovar", poruke(o))
    o = adm.post(SP, {"akcija": "dodaj_mesto", "id": sp, "novo_broj": "7"})
    R.provera("samo dok je nacrt" in poruke(o) and sql_int("SELECT COUNT(*) FROM spisak_mesta WHERE spisak_id=%d" % sp) == 3, "na spisak Za utovar se ne dodaje mesto")
    o = adm.get(SP + "?id=%d" % sp)
    R.provera('form="izmene"' not in o.text and 'class="mesto-dodaj"' not in o.text and 'id="novo-mesto"' not in o.text, "Za utovar: mesta se samo prikazuju")

    R.odeljak("Radnik: utovar po mestima")
    o = jelena.get("/radnik/utovar.php")
    R.provera("3 mesta: 8, 9, 4" in tekst(o.text) and "Cvećara Flora, Agrocentar <b>NS</b>" in tekst(o.text), "radnik u listi vidi brojeve mesta i kupce")
    o = jelena.get("/radnik/utovar.php?id=%d" % sp)
    R.provera(re.findall(r'<h3 id="mesto-\d+-naslov">Mesto (\d+)</h3>', o.text) == ["8", "9", "4"], "radnik vidi robu po mestima, redom 8, 9, 4")
    R.provera('id="novi_mesto"' in o.text and "— za koje mesto? —" in o.text, "pri dodavanju artikla bira se mesto")
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_sku": hum50, "novi_kom": "2"})
    R.provera("Izaberite za koje mesto je roba" in poruke(o), "dodavanje bez izabranog mesta: odbijeno", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m9, "novi_sku": hum50, "novi_kom": "2"})
    R.provera("Dodato na spisak za Mesto 9 · Agrocentar <b>NS</b>: Humovit · 50 l – 2 kom" in poruke(o)
              and sql("SELECT mesto_id, dodao_id FROM spisak_stavke WHERE spisak_id=%d AND sku_id=%d AND mesto_id=%d" % (sp, hum50, m9)) == "%d\t%d" % (m9, ids["Jelena"]),
              "radnik je dodao Humovit 50 l za Mesto 9", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m9, "novi_sku": hum50, "novi_kom": "5"})
    R.provera("već na spisku za Mesto 9" in poruke(o), "isti artikal na istom mestu: koristi se „Više ili manje od plana“", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m5, "novi_sku": hum50, "novi_kom": "2"})
    R.provera("za Mesto 4" in poruke(o), "isti artikal za drugo mesto: može", poruke(o))
    o = jelena.post("/radnik/utovar.php", {"id": sp, "akcija": "dodaj", "novi_mesto": m_s1, "novi_sku": hum50, "novi_kom": "2"})
    R.provera("Izaberite za koje mesto" in poruke(o), "mesto sa drugog spiska: odbijeno", poruke(o))

    R.odeljak("Potvrda: roba se vodi na kupca svog mesta")
    f = {"id": sp, "akcija": "potvrdi"}
    for stid in sql("SELECT id FROM spisak_stavke WHERE spisak_id=%d" % sp).split("\n"):
        f["ok[%s]" % stid] = "1"
    o = jelena.post("/radnik/utovar.php", f)
    R.provera("Utovar je potvrđen: %s, 3 mesta: 8, 9, 4." % utovar(sp) in poruke(o), "utovar je potvrđen", poruke(o))
    unosi = sql("SELECT u.sku_id, COALESCE(k.naziv, '-'), u.kolicina, u.napomena FROM unosi u LEFT JOIN kupci k ON k.id = u.kupac_id "
                "WHERE u.spisak_id=%d AND u.tip='utovar'" % sp).split("\n")
    ocekivano = [
        (hum50, "Cvećara Flora", 40, "Mesto 8"),
        (rizla, "Cvećara Flora", 25, "Mesto 8 – Zbirna paleta 1"),
        (oblutak, "Cvećara Flora", 25, "Mesto 8 – Zbirna paleta 1"),
        (hum10, "Agrocentar <b>NS</b>", 270, "Mesto 9"),
        (hum50, "Agrocentar <b>NS</b>", 2, "Mesto 9"),
        (hum10, "-", 18, "Mesto 4"),
        (hum50, "-", 2, "Mesto 4"),
    ]
    R.provera(sorted(unosi) == sorted("%d\t%s\t%d\t%s" % r for r in ocekivano), "svaki artikal je skinut na kupca svog mesta (mesto 4 bez kupca)", unosi)
    R.provera(sql_int("SELECT COUNT(*) FROM kupci WHERE naziv IN ('Cvećara Flora', 'Agrocentar <b>NS</b>') AND poslednja_prodaja IS NOT NULL") == 2, "oba kupca su zapamćena kao skorašnja")
    posle = (stanje(hum10), stanje(hum50), stanje(rizla), stanje(oblutak))
    R.provera(posle == (540 - 288, 80 - 44, 25, 25), "stanje: Humovit 10 l −288, 50 l −44, rizla −25, oblutak −25", posle)
    o = jelena.get("/radnik/utovar.php")
    R.provera("Danas utovareno" in o.text and "3 mesta: 8, 9, 4" in tekst(o.text), "radnik: „Danas utovareno“ prikazuje utovar sa mestima")

    R.odeljak("Ručna prodaja kupcu sa mesta današnjeg utovara")
    adm.get("/admin/prodaja.php")
    o = adm.post("/admin/prodaja.php", {"kupac": "Agrocentar <b>NS</b>", "sku_id": hum10, "kolicina": "5", "nacin": "komadi", "kljuc": nov_kljuc()})
    R.provera("Prodaja sačuvana" in poruke(o) and "danas ima spisak za utovar (%s, utovaren)" % utovar(sp) in poruke(o), "upozorenje: kupac je na mestu današnjeg utovara", poruke(o))

    R.odeljak("Štampa i deljenje po mestima")
    o = adm.get("/admin/spisak_stampa.php?id=%d" % sp)
    t = tekst(o.text)
    R.provera(o.text.count('class="stampa-mesto"') == 3 and "Mesta istovara 8, 9, 4" in t, "štampa: tri mesta, svako sa svojim naslovom; u zaglavlju 8, 9, 4")
    R.provera(t.index("Mesto 8 – Cvećara Flora") < t.index("Mesto 9 – Agrocentar <b>NS</b>") < t.index("Mesto 4"), "štampa: mesta redom kao na spisku")
    R.provera("Primio" not in t and "Utovario" in t, "kod više mesta nema jednog potpisa „Primio“")
    R.provera(re.search(r"Mesto 9 – Agrocentar <b>NS</b> [^#]{0,30}≈ 1\.008 kg", t) is not None, "zbir za Mesto 9 (270 × 3,6 + 2 × 18 = 1.008 kg) je u naslovu mesta", re.findall(r"Mesto 9[^#]{0,80}", t)[:1])
    m = re.search(r'href="https://wa\.me/\?text=([^"]+)"', o.text)
    deljenje = urllib.parse.unquote(htmllib.unescape(m.group(1))) if m else ""
    R.provera("\n\nMESTO 8 – Cvećara Flora\n  1. Humovit · 50 l – 1 pal = 40 kom\n  ZBIRNA PALETA – Zbirna paleta 1:\n     2. Bela rizla · Sitnija · 20 kg – 25 kom\n     3. Beli oblutak · 4-6 cm · 20 kg – 25 kom" in deljenje,
              "tekst za Viber/WhatsApp: Mesto 8 sa kupcem, pa zbirna paleta", deljenje[:500])
    R.provera("MESTO 9 – Agrocentar <b>NS</b>\n" in deljenje and "\n\nMESTO 4\n" in deljenje and deljenje.index("MESTO 8") < deljenje.index("MESTO 9") < deljenje.index("MESTO 4"),
              "tekst: sva tri mesta, redom", deljenje)

    R.odeljak("Otkazivanje utovara: povrat na kupca svog mesta")
    o = adm.post(SP, {"akcija": "status", "id": sp, "ocekivan": "utovareno", "novi": "otkazano"})
    povrat = sql("SELECT u.sku_id, COALESCE(k.naziv, '-'), u.kolicina FROM unosi u LEFT JOIN kupci k ON k.id = u.kupac_id "
                 "WHERE u.spisak_id=%d AND u.tip='povrat' ORDER BY u.sku_id, u.kolicina" % sp).split("\n")
    R.provera(len(povrat) == 7 and "%d\tAgrocentar <b>NS</b>\t270" % hum10 in povrat and "%d\t-\t18" % hum10 in povrat, "povrat je po artiklu i kupcu (7 redova)", povrat)
    R.provera((stanje(hum10), stanje(hum50), stanje(rizla), stanje(oblutak)) == (540 - 5, 80, 50, 50), "stanje je vraćeno (osim ručne prodaje 5 kom)")

    R.provera(not site.php_problemi(), "PHP nije prijavio upozorenja ni greške", site.php_problemi()[:3])
finally:
    site.stop()

sys.exit(R.kraj())
