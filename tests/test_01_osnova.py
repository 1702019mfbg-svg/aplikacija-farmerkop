#!/usr/bin/env python3
"""Faza 1: instalacija, prijava, zaključavanje, CSRF, sesija, zaglavlja."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa

R = Rezultat()
reset_db()

# ── 0. config.php sa podrazumevanim (neupisanim) podacima ───────────────────
R.odeljak("install.php pre nego što je config.php popunjen")
prazan = Site(placeholder_config=True)
try:
    c0 = Client(prazan.base)
    o = c0.get("/install.php")
    R.provera(o.status == 200 and "još nisu upisani podaci o bazi" in o.text, "prikazuje uputstvo da se popuni config.php", o.text[:300])
    R.provera("INSTALL_KLJUC" in o.text, "traži izmenu INSTALL_KLJUC")
finally:
    prazan.stop()

site = Site()
c = Client(site.base)
try:
    # ── 1. Pre instalacije ───────────────────────────────────────────────────
    R.odeljak("Pre instalacije (prazna baza)")
    o = c.get("/", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/install.php"), "/ vodi na install.php dok aplikacija nije instalirana", o.lanac)
    o = c.get("/login.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/install.php"), "login.php vodi na install.php dok nije instalirano", o.lanac)

    # ── 2. Instalacija ───────────────────────────────────────────────────────
    R.odeljak("Instalacija")
    o = c.get("/install.php")
    R.provera("Veza sa bazom radi" in o.text and "Prvi administratorski nalog" in o.text, "install.php prikazuje formu", o.text[:400])
    forma = {"kljuc": INSTALL_KLJUC, "ime": "Vlasnik", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS, "sifra2": ADMIN_PASS, "katalog": "1"}

    o = c.post("/install.php", dict(forma), csrf=False)
    R.provera(o.status == 403, "POST bez CSRF žetona je odbijen (403)", o.status)
    o = c.post("/install.php", dict(forma, kljuc="pogresan-kljuc"))
    R.provera("Instalacioni ključ nije tačan" in o.text, "pogrešan instalacioni ključ je odbijen")
    R.provera(sql_int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%s'" % DB_NAME) == 0, "pogrešan ključ ne pravi tabele")
    o = c.post("/install.php", dict(forma, sifra2="druga-sifra-123"))
    R.provera("ne poklapaju" in o.text, "različite šifre su odbijene")
    o = c.post("/install.php", dict(forma, sifra="kratka", sifra2="kratka"))
    R.provera("najmanje 8 znakova" in o.text, "prekratka šifra je odbijena")
    o = c.post("/install.php", dict(forma, korisnicko_ime="a b"))
    R.provera("Korisničko ime može imati" in o.text, "nevažeće korisničko ime je odbijeno")

    o = c.post("/install.php", dict(forma))
    R.provera("Instalacija je uspešno završena" in o.text, "instalacija uspešna", o.text[:600])
    R.provera("automatski obrisan" in o.text, "install.php se sam obrisao")
    R.provera(not (site.dir / "install.php").exists(), "fajl install.php više ne postoji na disku")
    o = c.get("/install.php")
    R.provera(o.status == 404, "install.php posle instalacije vraća 404", o.status)

    tabele = sql("SELECT table_name FROM information_schema.tables WHERE table_schema='%s' ORDER BY 1" % DB_NAME).split()
    for t in ["korisnici", "sesije", "neuspele_prijave", "podesavanja", "kategorije", "artikli", "varijante", "pakovanja", "sku", "kupci", "unosi", "dnevnik"]:
        R.provera(t in tabele, "tabela %s postoji" % t)

    R.odeljak("Početni katalog")
    R.provera(sql_int("SELECT COUNT(*) FROM kategorije") == 3, "3 kategorije")
    R.provera(sql_int("SELECT COUNT(*) FROM artikli") == 8, "8 artikala (Humovit, Humovit premium, Floris, Idea, Čmana, Malč x2, Oblutak)")
    R.provera(sql_int("SELECT COUNT(*) FROM pakovanja") == 6, "6 pakovanja (5,10,20,25,50 l i 20 kg)")
    R.provera(sql_int("SELECT COUNT(*) FROM varijante") == 15, "15 varijanti (7 + 3 boje malča, 5 granulacija)")
    R.provera(sql_int("SELECT COUNT(*) FROM sku") == 17 + 10 + 5, "32 SKU-a")

    def po_paleti(artikal, kolicina, jedinica="l", varijanta=None):
        q = ("SELECT s.po_paleti FROM sku s JOIN artikli a ON a.id=s.artikal_id JOIN pakovanja p ON p.id=s.pakovanje_id "
             "LEFT JOIN varijante v ON v.id=s.varijanta_id WHERE a.naziv='%s' AND p.kolicina=%s AND p.jedinica='%s'" % (artikal, kolicina, jedinica))
        if varijanta:
            q += " AND v.naziv='%s'" % varijanta
        return sql(q)

    for art, kol, ocekivano in [
        ("Humovit", 5, "450"), ("Humovit", 10, "270"), ("Humovit", 25, "120"), ("Humovit", 50, "40"),
        ("Humovit premium", 20, "120"), ("Humovit premium", 50, "40"),
        ("Floris Savacoop", 5, "450"), ("Floris Savacoop", 10, "270"), ("Floris Savacoop", 20, "120"), ("Floris Savacoop", 50, "40"),
        ("Idea", 5, "450"), ("Idea", 10, "225"), ("Idea", 20, "120"), ("Idea", 25, "120"),
        ("Čmana supstrat", 10, "270"),
    ]:
        R.provera(po_paleti(art, kol) == ocekivano, "%s %s l: %s komada na paleti" % (art, kol, ocekivano), po_paleti(art, kol))
    R.provera(po_paleti("Malč Farmerkop", 50, varijanta="Crveni") == "40", "Malč Farmerkop 50 l: 40 na paleti")
    R.provera(po_paleti("Beli oblutak", 20, "kg", "1-3 cm") == "50", "Beli oblutak 20 kg: 50 na paleti")
    R.provera(sql("SELECT COUNT(*) FROM sku s JOIN artikli a ON a.id=s.artikal_id WHERE a.naziv='Humovit' AND s.pakovanje_id=(SELECT id FROM pakovanja WHERE kolicina=20 AND jedinica='l')") == "0", "Humovit nema pakovanje 20 l")
    R.provera(sql("SELECT aktivan FROM artikli WHERE naziv='Čmana supstrat'") == "0", "Čmana supstrat je isključena")
    R.provera(sql("SELECT oznaka FROM artikli WHERE naziv='Idea'") == "PL", "Idea ima oznaku PL")
    R.provera(sql("SELECT GROUP_CONCAT(naziv ORDER BY redosled SEPARATOR '|') FROM varijante WHERE artikal_id=(SELECT id FROM artikli WHERE naziv='Malč Farmerkop')") == "Crveni|Braon|Žuti|Crni|Narandžasti|Zeleni|Neobojeni", "boje malča Farmerkop")
    R.provera(sql("SELECT GROUP_CONCAT(naziv ORDER BY redosled SEPARATOR '|') FROM varijante WHERE artikal_id=(SELECT id FROM artikli WHERE naziv='Beli oblutak')") == "1-3 cm|2-4 cm|4-6 cm|4-7 cm (krupnija)|6-10 cm", "granulacije oblutka u cm")

    R.odeljak("Bezbednost podataka u bazi")
    hes = sql("SELECT hes FROM korisnici WHERE uloga='admin'")
    R.provera(hes.startswith("$2y$") and ADMIN_PASS not in hes, "šifra administratora je heš (bcrypt), ne tekst")
    R.provera(ADMIN_PASS not in sql("SELECT GROUP_CONCAT(podaci) FROM sesije"), "šifra se ne nalazi ni u sesijama")

    R.odeljak("Početna i prijava posle instalacije")
    o = Client(site.base).get("/", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "/ vodi na login.php kad niko nije prijavljen", o.lanac)
    o = Client(site.base).get("/login.php")
    R.provera(o.status == 200 and "Prijava" in o.text and "Farmerkop" in o.text, "login.php se otvara")
    R.provera("Još nema upisanih radnika" in o.text, "bez radnika piše uputstvo")

    # ── 3. Prijava administratora ────────────────────────────────────────────
    R.odeljak("Prijava administratora")
    sql("DELETE FROM neuspele_prijave")
    a = Client(site.base)
    a.get("/login.php?admin=1")
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "pogresna"})
    R.provera("Pogrešno korisničko ime ili šifra" in o.text, "pogrešna šifra je odbijena")
    R.provera("Preostalo" not in o.text, "za administratora se ne otkriva broj preostalih pokušaja")
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": "nepostojeci", "sifra": "pogresna"})
    R.provera("Pogrešno korisničko ime ili šifra" in o.text, "nepostojeće korisničko ime daje istu poruku")
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "' OR '1'='1"})
    R.provera("Pogrešno korisničko ime ili šifra" in o.text and not site.php_problemi(), "SQL injection pokušaj ne prolazi i ne pravi grešku")
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": "' OR 1=1 -- ", "sifra": "x"})
    R.provera("Pogrešno korisničko ime ili šifra" in o.text, "SQL injection u korisničkom imenu ne prolazi")

    a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "pogresna"})
    a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "pogresna"})
    R.provera(sql_int("SELECT neuspesni_pokusaji FROM korisnici WHERE uloga='admin'") == 4, "brojač pogrešnih pokušaja je 4",
              sql("SELECT neuspesni_pokusaji FROM korisnici WHERE uloga='admin'"))
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": "pogresna"})
    R.provera("zaključan na 10 min" in o.text, "posle 5 pogrešnih pokušaja nalog se zaključava na 10 min", o.text[-600:])
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS})
    R.provera("privremeno zaključan" in o.text, "ispravna šifra ne pomaže dok je nalog zaključan")
    R.provera(a.get("/admin/stanje.php", slediti=False).status == 302, "i dalje nije prijavljen")

    sql("UPDATE korisnici SET zakljucan_do='2000-01-01 00:00:00' WHERE uloga='admin'")
    sid_pre = (a.cookie() or object()).value if a.cookie() else None
    o = a.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/admin/stanje.php"), "posle isteka zaključavanja prijava uspeva", o.lanac)
    sid_posle = a.cookie().value
    R.provera(sid_pre != sid_posle, "identifikator sesije se menja posle prijave (zaštita od fiksacije)")
    R.provera(sql_int("SELECT neuspesni_pokusaji FROM korisnici WHERE uloga='admin'") == 0, "uspešna prijava vraća brojač na 0")
    o = a.get("/admin/stanje.php")
    R.provera(o.status == 200 and "Zdravo, Vlasnik" in o.text, "administrator vidi admin stranicu")
    R.provera("Podešavanja" in o.text and "Istorija" in o.text and "Radnici" in o.text and "Prodaja" in o.text and "Stanje" in o.text, "donja navigacija ima svih 5 kartica")

    # ── 4. Radnici ───────────────────────────────────────────────────────────
    R.odeljak("Prijava radnika (ime + PIN)")
    sql("DELETE FROM neuspele_prijave")
    sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','Marko','%s',1,'2026-01-01 00:00:00')" % php_hes("1234"))
    sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','Jelena','%s',1,'2026-01-01 00:00:00')" % php_hes("4321"))
    sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','Ugasen','%s',0,'2026-01-01 00:00:00')" % php_hes("1111"))
    sql("INSERT INTO korisnici (uloga, ime, hes, aktivan, napravljen) VALUES ('radnik','<img src=x onerror=alert(1)>','%s',1,'2026-01-01 00:00:00')" % php_hes("2222"))
    mid = sql_int("SELECT id FROM korisnici WHERE ime='Marko'")
    jid = sql_int("SELECT id FROM korisnici WHERE ime='Jelena'")
    ugasen_id = sql_int("SELECT id FROM korisnici WHERE ime='Ugasen'")

    w = Client(site.base)
    o = w.get("/login.php")
    R.provera("Marko" in o.text and "Jelena" in o.text, "lista imena prikazuje aktivne radnike")
    R.provera("Ugasen" not in o.text, "isključen radnik nije na listi")
    R.provera("&lt;img src=x onerror=alert(1)&gt;" in o.text and "<img src=x onerror" not in o.text, "ime sa HTML kodom je bezbedno ispisano (XSS)")
    R.provera("data-cifra" in o.text, "ima krupnu tastaturu za PIN")

    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "0000"})
    R.provera("Pogrešan PIN. Preostalo pokušaja: 4." in o.text, "pogrešan PIN: preostaje 4 pokušaja", o.text[-500:])
    R.provera(f'value="{mid}" required checked' in o.text, "izabrano ime ostaje označeno posle greške")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "12"})
    R.provera("tačno 4 cifre" in o.text, "PIN kraći od 4 cifre je odbijen")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "12ab"})
    R.provera("tačno 4 cifre" in o.text, "PIN sa slovima je odbijen")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": "", "pin": "1234"})
    R.provera("Izaberite svoje ime" in o.text, "bez izabranog imena nema prijave")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": ugasen_id, "pin": "1111"})
    R.provera("isključen" in o.text, "isključen radnik ne može da se prijavi ni sa tačnim PIN-om")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": "99999", "pin": "1234"})
    R.provera("Pogrešan PIN" in o.text, "nepostojeći radnik – opšta poruka")

    for i in range(3):
        o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "9999"})
    R.provera("Preostalo pokušaja: 1." in o.text, "posle 4 pogrešna ostaje 1 pokušaj")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "9999"})
    R.provera("zaključan na 10 min" in o.text, "peti pogrešan PIN zaključava radnika na 10 min")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "1234"})
    R.provera("privremeno zaključan" in o.text, "tačan PIN ne pomaže tokom zaključavanja")
    o = w.post("/login.php", {"tip": "radnik", "radnik_id": jid, "pin": "4321"}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "zaključavanje jednog radnika ne smeta drugom", o.lanac)

    R.odeljak("Postepeno duže zaključavanje")
    sql("UPDATE korisnici SET zakljucan_do='2000-01-01 00:00:00' WHERE id=%d" % mid)
    sql("DELETE FROM neuspele_prijave")
    w2 = Client(site.base)
    for i in range(5):
        w2.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "9999"})
    razlika = sql_int("SELECT TIMESTAMPDIFF(MINUTE, (SELECT MAX(vreme) FROM neuspele_prijave), zakljucan_do) FROM korisnici WHERE id=%d" % mid)
    R.provera(razlika in (19, 20), "drugo zaključavanje traje duplo duže (≈20 min)", razlika)
    sql("UPDATE korisnici SET zakljucan_do=NULL, neuspesni_pokusaji=0 WHERE id=%d" % mid)
    sql("DELETE FROM neuspele_prijave")

    R.odeljak("Uloge i pristup")
    o = w.get("/admin/stanje.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/radnik/index.php"), "radnik ne može na admin stranice – vraća se na svoju", o.lanac)
    o = w.get("/radnik/index.php")
    R.provera("Farmerkop · Jelena" in o.text and "Novi unos" in o.text, "radnik vidi svoju stranicu")
    R.provera("Kućna prodaja" in o.text and "Proizvodnja" in o.text, "radnik ima navigaciju: Proizvodnja i Kućna prodaja")
    R.provera("Stanje" not in o.text.split("<nav")[1] if "<nav" in o.text else True, "radnik nema karticu Stanje")
    o = Client(site.base).get("/radnik/index.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "neprijavljen ne vidi radnik stranicu")
    o = Client(site.base).get("/admin/stanje.php", slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "neprijavljen ne vidi admin stranicu")
    o = w.get("/login.php", slediti=False)
    R.provera(o.status == 302, "prijavljen korisnik sa /login.php ide na svoju početnu")

    # ── 5. Zaglavlja i kolačić ───────────────────────────────────────────────
    R.odeljak("Zaglavlja i kolačić")
    o = Client(site.base).get("/login.php")
    csp = o.header("Content-Security-Policy") or ""
    R.provera("default-src 'self'" in csp and "script-src 'self'" in csp and "frame-ancestors 'none'" in csp, "Content-Security-Policy je podešen", csp)
    R.provera(o.header("X-Frame-Options") == "DENY", "X-Frame-Options: DENY")
    R.provera(o.header("X-Content-Type-Options") == "nosniff", "X-Content-Type-Options: nosniff")
    R.provera("no-store" in (o.header("Cache-Control") or ""), "stranice se ne keširaju (no-store)")
    sc = [v for k, v in o.headers.items() if k.lower() == "set-cookie"]
    R.provera(any("FKSESIJA=" in s and "HttpOnly" in s and "SameSite=Lax" in s for s in sc), "kolačić sesije: HttpOnly + SameSite=Lax", sc)
    R.provera(not any("<script" in l for l in [o.text]) or "<script src=" in o.text, "nema inline skripti (CSP)")
    R.provera("onclick=" not in o.text and "onsubmit=" not in o.text, "nema inline događaja")

    # ── 6. CSRF i odjava ─────────────────────────────────────────────────────
    R.odeljak("CSRF i odjava")
    anon = Client(site.base)
    anon.get("/login.php")
    o = anon.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "1234"}, csrf=False)
    R.provera(o.status == 403 and "Sesija je istekla" in o.text, "prijava bez CSRF žetona → 403")
    o = anon.post("/login.php", {"tip": "radnik", "radnik_id": mid, "pin": "1234", "csrf": "0" * 64}, csrf=False)
    R.provera(o.status == 403, "prijava sa pogrešnim žetonom → 403")
    R.provera(anon.get("/radnik/index.php", slediti=False).status == 302, "ni posle toga nije prijavljen")
    o = w.get("/logout.php", slediti=False)
    R.provera(o.status == 302 and w.get("/radnik/index.php").status == 200, "GET na logout.php ne odjavljuje (sprečava odjavu preko tuđeg linka)")
    o = w.post("/logout.php", {}, csrf=False, slediti=False)
    R.provera(o.status == 403, "odjava bez žetona je odbijena")
    o = w.get("/radnik/index.php")
    w.token = o.csrf()
    o = w.post("/logout.php", {}, slediti=False)
    R.provera(o.status == 302 and o.lanac[0][1].endswith("/login.php"), "odjava sa žetonom radi")
    o = w.get("/radnik/index.php", slediti=False)
    R.provera(o.status == 302, "posle odjave stranica traži prijavu")
    R.provera(sql_int("SELECT COUNT(*) FROM sesije WHERE podaci LIKE '%%uid|i:%d;%%'" % jid) == 0, "sesija je obrisana iz baze")

    # ── 7. Istek sesije i isključenje naloga ─────────────────────────────────
    R.odeljak("Istek sesije")
    s = Client(site.base)
    s.get("/login.php")
    s.post("/login.php", {"tip": "radnik", "radnik_id": jid, "pin": "4321"})
    R.provera(s.get("/radnik/index.php").status == 200, "radnik je prijavljen")
    sql("UPDATE sesije SET podaci = REGEXP_REPLACE(podaci, 'poslednje\\\\|i:[0-9]+;', CONCAT('poslednje|i:', UNIX_TIMESTAMP() - 61*60, ';')) WHERE podaci LIKE '%%uid|i:%d;%%'" % jid)
    o = s.get("/radnik/index.php", slediti=False)
    R.provera(o.status == 302, "posle 61 min neaktivnosti radnik je odjavljen", o.status)
    o = s.get("/login.php")
    R.provera("neaktivnosti" in o.text, "prijava objašnjava da je istekla sesija zbog neaktivnosti")

    sql("UPDATE sesije SET podaci = ''")  # čist početak
    s2 = Client(site.base)
    s2.get("/login.php")
    s2.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS})
    sql("UPDATE sesije SET podaci = REGEXP_REPLACE(podaci, 'poslednje\\\\|i:[0-9]+;', CONCAT('poslednje|i:', UNIX_TIMESTAMP() - 31*60, ';')) WHERE podaci LIKE '%uloga|s:5:\"admin\"%'")
    R.provera(s2.get("/admin/stanje.php", slediti=False).status == 302, "administrator se odjavljuje posle 30 min neaktivnosti")
    # a unutar 29 min ostaje prijavljen
    s2.get("/login.php")
    s2.post("/login.php", {"tip": "admin", "korisnicko_ime": ADMIN_USER, "sifra": ADMIN_PASS})
    sql("UPDATE sesije SET podaci = REGEXP_REPLACE(podaci, 'poslednje\\\\|i:[0-9]+;', CONCAT('poslednje|i:', UNIX_TIMESTAMP() - 29*60, ';')) WHERE podaci LIKE '%uloga|s:5:\"admin\"%'")
    R.provera(s2.get("/admin/stanje.php", slediti=False).status == 200, "posle 29 min administrator je još prijavljen")

    R.odeljak("Isključenje naloga važi odmah")
    s3 = Client(site.base)
    s3.get("/login.php")
    s3.post("/login.php", {"tip": "radnik", "radnik_id": jid, "pin": "4321"})
    R.provera(s3.get("/radnik/index.php", slediti=False).status == 200, "radnik prijavljen")
    sql("UPDATE korisnici SET aktivan=0 WHERE id=%d" % jid)
    R.provera(s3.get("/radnik/index.php", slediti=False).status == 302, "isključenom radniku sesija prestaje odmah")
    sql("UPDATE korisnici SET aktivan=1 WHERE id=%d" % jid)

    # ── 8. Ograničenje po IP adresi ──────────────────────────────────────────
    R.odeljak("Zaštita od pogađanja sa jednog uređaja")
    sql("DELETE FROM neuspele_prijave")
    sql("UPDATE korisnici SET neuspesni_pokusaji=0, zakljucan_do=NULL")
    ip = Client(site.base)
    ip.get("/login.php")
    for i in range(30):
        ip.post("/login.php", {"tip": "radnik", "radnik_id": 100000 + i, "pin": "0000"})
    o = ip.post("/login.php", {"tip": "radnik", "radnik_id": jid, "pin": "4321"})
    R.provera("Previše pogrešnih pokušaja sa ovog uređaja" in o.text, "posle 30 promašaja sa iste IP adrese blokira se i ispravan PIN")
    sql("DELETE FROM neuspele_prijave")
    o = ip.post("/login.php", {"tip": "radnik", "radnik_id": jid, "pin": "4321"}, slediti=False)
    R.provera(o.status == 302, "kad blokada prođe, prijava ponovo radi")

    # ── 9. PHP greške ────────────────────────────────────────────────────────
    R.odeljak("Greške na serveru")
    R.provera(not site.php_problemi(), "u logu PHP servera nema upozorenja ni grešaka", site.php_problemi()[:5])
finally:
    site.stop()

sys.exit(R.kraj())
