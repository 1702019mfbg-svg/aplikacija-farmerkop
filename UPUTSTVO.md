# Farmerkop – uputstvo za postavljanje i korišćenje

Aplikacija za evidenciju proizvodnje, prodaje i zaliha. Radi u pregledaču telefona i može da se doda na početni ekran kao prava aplikacija.

**Vreme:** oko 20–30 minuta, jednom. Ne treba vam nikakvo programersko znanje, samo cPanel nalog.

**Šta ćete imati na kraju:** adresu `https://app.farmerkop.rs`, administratorski nalog, radnike sa PIN-ovima i ikonu *Farmerkop* na telefonima.

---

## Šta vam treba

- Pristup cPanel-u vašeg hostinga (korisničko ime i lozinka koje ste dobili od hosting firme)
- Fajl **`farmerkop-aplikacija.zip`** (nalazi se u folderu `dist` na GitHub-u, a dobili ste ga i direktno)
- Domen `farmerkop.rs` mora biti na tom hostingu

> **Važno:** Svuda gde piše *„upišite“*, lozinke i nazive **zapišite** (u beležnicu ili menadžer lozinki). Biće vam potrebni.

---

## Korak 1 – Napravite poddomen `app.farmerkop.rs`

1. Prijavite se u **cPanel**.
2. Pronađite **Domains** (Domeni). Na starijim cPanel-ima to se zove **Subdomains**.
3. Kliknite **Create A New Domain** (ili **Create** kod Subdomains).
4. U polje domena upišite: `app.farmerkop.rs`
   (na starijem izgledu: Subdomain = `app`, Domain = `farmerkop.rs`).
5. Ako postoji kvačica **„Share document root“**, **isključite je**.
6. Document Root (folder) ostavite kakav je predložen, na primer `app.farmerkop.rs`. **Zapamtite ga**, biće vam potreban u 5. koraku.
7. Kliknite **Submit** / **Create**.

## Korak 2 – Uključite HTTPS (katanac)

Bez ovoga telefon neće dozvoliti da aplikaciju dodate na početni ekran.

1. U cPanel-u otvorite **SSL/TLS Status**.
2. Pored `app.farmerkop.rs` kliknite **Run AutoSSL** (ako postoji dugme).
3. Sačekajte nekoliko minuta (ponekad do sat vremena) dok pored poddomena ne bude zeleni katanac.

## Korak 3 – Proverite PHP verziju

1. U cPanel-u otvorite **Select PHP Version** (ili **MultiPHP Manager**).
2. Za `app.farmerkop.rs` izaberite **PHP 8.1 ili noviju** (najmanje 8.0).
3. Ako vidite spisak dodataka (Extensions), proverite da su uključeni: **pdo_mysql** (ili *nd_pdo_mysql*), **mbstring**, **json**. Obično su već uključeni.

## Korak 4 – Napravite bazu podataka

1. U cPanel-u otvorite **MySQL® Database Wizard** (Čarobnjak za baze).
2. **Korak 1 – ime baze:** upišite npr. `aplikacija` → **Next Step**.
   cPanel dodaje prefiks, pa će pun naziv izgledati ovako: `farmerk_aplikacija`. **Zapišite pun naziv.**
3. **Korak 2 – korisnik baze:** upišite korisničko ime npr. `appuser` (pun naziv će biti `farmerk_appuser`) i lozinku.
   Najbolje kliknite **Password Generator** i **zapišite lozinku**. Kliknite **Create User**.
4. **Korak 3 – privilegije:** označite **ALL PRIVILEGES** → **Next Step**.

Sada imate tri podatka:

| Šta | Primer | Vaš podatak |
|---|---|---|
| Ime baze | `farmerk_aplikacija` | |
| Korisnik baze | `farmerk_appuser` | |
| Lozinka baze | (ono što ste zapisali) | |

## Korak 5 – Otpremite aplikaciju

1. U cPanel-u otvorite **File Manager**.
2. U gornjem desnom uglu kliknite **Settings** i označite **Show Hidden Files (dotfiles)** → **Save**.
   *(Ovo je važno! Aplikacija ima skriven fajl `.htaccess` koji mora da stigne na server.)*
3. U levom stablu otvorite folder poddomena iz 1. koraka (npr. `app.farmerkop.rs`).
4. Kliknite **Upload** i izaberite **`farmerkop-aplikacija.zip`**. Sačekajte da se otpremi, pa **Go Back**.
5. Desni klik na `farmerkop-aplikacija.zip` → **Extract** → **Extract File(s)**.
6. **Provera:** u tom folderu sada morate direktno videti: `index.php`, `login.php`, `install.php`, `config.php`, `.htaccess`, `sw.js` i foldere `admin`, `radnik`, `inc`, `assets`.
   Ako ste umesto toga dobili jedan dodatni folder, otvorite ga, označite sve (Select All), **Move** u nadređeni folder.
7. Zip možete obrisati (desni klik → Delete).

## Korak 6 – Upišite podatke o bazi (`config.php`)

1. U File Manager-u kliknite desnim tasterom na **`config.php`** → **Edit** (ako pita za kodiranje, kliknite *Edit*).
2. Zamenite tekst između navodnika vašim podacima:

   ```php
   define('DB_HOST', 'localhost');                       // ne menjajte
   define('DB_NAME', 'farmerk_aplikacija');              // pun naziv baze
   define('DB_USER', 'farmerk_appuser');                 // pun naziv korisnika
   define('DB_PASS', 'OVDE_VASA_LOZINKA_BAZE');          // lozinka baze

   define('INSTALL_KLJUC', 'krompir2026');               // izmislite svoju reč (najmanje 8 znakova)
   ```

   Pazite da ostanu **navodnici** i **tačka-zarez** na kraju svakog reda.
3. Kliknite **Save Changes**.

> `INSTALL_KLJUC` je privremena „šifra za instalaciju“. Samo vi znate tu reč, pa niko drugi ne može da pokrene instalaciju.

## Korak 7 – Instalacija

1. U pregledaču otvorite: **`https://app.farmerkop.rs/install.php`**
2. Treba da vidite zeleno **„Veza sa bazom radi“**. Ako vidite upozorenje, pročitajte ga, najčešće je pogrešno upisano ime/korisnik/lozinka baze u `config.php`.
3. Popunite formu:
   - **Instalacioni ključ** – reč iz `config.php`
   - **Ime za prikaz** – npr. *Vlasnik*
   - **Korisničko ime** – npr. `vlasnik` (samo slova bez kvačica, cifre)
   - **Šifra** – najmanje 8 znakova (preporuka: rečenica ili tri reči, npr. `Zemlja-za-cvece-2026`)
   - Ostavite kvačicu na **„Ubaci početni katalog“**
4. Kliknite **Instaliraj**.
5. Videćete **„Instalacija je uspešno završena“**. Fajl `install.php` se sam briše. Ako piše da nije uspelo da ga obriše, **obrišite ga ručno** u File Manager-u.

## Korak 8 – Prvo podešavanje (administrator)

1. Otvorite `https://app.farmerkop.rs`, kliknite **Prijava administratora** i prijavite se.
2. **Radnici** → dodajte svakog radnika: ime i PIN od 4 cifre (dugme *Slučajan PIN* predlaže PIN). Svakom radniku **lično** recite njegov PIN.
3. **Podešavanja → Artikli:** proverite katalog.
   - Humovit, Humovit premium, Floris Savacoop, Idea, malč (po bojama), beli oblutak (po granulaciji) su već unesena.
   - Kliknite **Uredi** pored artikla da podesite **komada po paleti** i **minimum zalihe** (ispod njega se pali crveno upozorenje).
   - **Cmana supstrat** je isključena, jednim klikom **Uključi** kad krene proizvodnja.
4. Ako već imate zalihu u magacinu: **Podešavanja → Popis** i upišite prebrojano stanje.

---

## Dodavanje na početni ekran (Android)

1. Na telefonu otvorite **Chrome** i idite na `https://app.farmerkop.rs`.
2. Dodirnite **⋮** (tri tačke gore desno) → **Instaliraj aplikaciju** (ili **Dodaj na početni ekran**) → **Instaliraj**.
3. Na početnom ekranu pojavljuje se ikona **Farmerkop** i otvara se kao prava aplikacija, preko celog ekrana.

*(iPhone: Safari → dugme Podeli → „Dodaj na početni ekran“.)*

Radnik se prijavljuje tako što **dodirne svoje ime** i ukuca **PIN**. Ne treba ništa više.

---

## Kako se koristi

### Radnik
- **Proizvodnja:** dodirni artikal → (boju ili granulaciju, ako je ima) → pakovanje → izaberi **Palete**, **Pakete** ili **Komade** → upiši broj ili koristi dugmiće **−10 / −1 / +1 / +10** (kod paleta −5 / −1 / +1 / +5) → **Sačuvaj**.
  Dugme **Paketi** postoji samo za pakovanja kojima je podešeno koliko komada ide u paket (Humovit 5 l = 10, Humovit 10 l = 6, Idea 5 l = 10, Idea 10 l = 5). Program sam preračuna u komade (npr. 12 paketa × 6 = 72 kom).
- Na dnu je lista njegovih današnjih unosa i ukupan broj za danas.
- **Pogrešan unos?** Dugme **Obriši** stoji uz poslednji unos još 10 minuta.
- **Kućna prodaja:** prodaja na licu mesta, isto kao proizvodnja. Radnik ne vidi koliko robe ima; ako unese više nego što ima, program ga odbija.

### Administrator (donja traka)
| Kartica | Čemu služi |
|---|---|
| **Stanje** | Stanje svakog artikla po pakovanju (komadi, palete, litri), danas proizvedeno i prodato, crveno kad padne ispod minimuma |
| **Prodaja** | Unos prodaje (kupac, artikal, količina). Program ne dozvoljava da se proda više nego što ima. Raniji kupci se pamte |
| **Istorija** | Svi unosi sa filterima; ispravka i brisanje (uz zapis ko i kad); **Izvoz u Excel (CSV)** |
| **Radnici** | Dodavanje, PIN, gašenje i vraćanje pristupa, pregled ko je koliko proizveo po danima |
| **Podešavanja** | Artikli i varijante, pakovanja, minimum, komada u paketu i po paleti, **popis stanja**, **uvoz stanja iz fajla**, promena šifre, dnevnik izmena |

**Kako se računa stanje:** proizvedeno − prodato (uključujući kućnu prodaju) ± korekcije iz popisa. Sve se čuva u komadima; palete i paketi su samo način unosa.

### Početno stanje (popis) – kako krenuti sa praćenjem
Pre nego što radnici počnu da unose, upišite koliko trenutno imate. Postoje dva načina, oba u **Podešavanja**:

**A) Popis – ručno (Podešavanja → Popis i korekcija stanja)**
1. Upišite napomenu, npr. „Početno stanje 06.10.“ (obavezna).
2. Kod svakog artikla upišite koliko ste **prebrojali**: **paleta**, **paketa** i/ili **komada** (npr. 3 palete + 2 paketa + 4 komada). Ispod polja program odmah ispiše ukupno komada i razliku u odnosu na sadašnje stanje.
3. Polja koja ostavite prazna ostaju kako jesu → **Sačuvaj popis**. Razlika se upisuje u Istoriju kao „Korekcija (popis)“.

**B) Uvoz stanja iz fajla (Podešavanja → Uvoz stanja iz fajla)** – za stanje iz Bluesofta ili Excela
1. U Bluesoftu izvezite stanje zaliha u **CSV** (ili Excel pa *Sačuvaj kao → CSV*). Može i bez fajla: označite tabelu, kopirajte je i **nalepite** u polje.
2. Otvorite *Uvoz stanja iz fajla* → izaberite fajl ili nalepite tabelu → **Učitaj**. (Dugme „Preuzmi primer fajla“ pokazuje kako izgleda.)
3. **Kolone:** program sam pogodi koja je kolona naziv, a koja količina (po zaglavlju). Ako pogodi pogrešno, izaberite pravu pa kliknite **Osveži pregled**. Izaberite i u čemu je količina u fajlu: **komadima, paketima ili paletama**. Brojevi: „1.250,00“ je srpski format (podrazumevano), „1,250.00“ engleski.
4. **Pregled redova:** svaki red iz fajla je upareno sa jednim vašim artiklom (npr. „HUMOVIT 10L“ → Humovit · 10 l). Oznake: **prepoznato**, **zapamćeno** (ranije ste ga ručno izabrali), **proveri!** (delimično poklapanje – proverite!), **nije prepoznato**. Pogrešno uparen ili neprepoznat red ispravite izborom artikla u listi; red koji ne treba izaberite **preskoči**. Ispod svakog reda piše šta je stanje sada i kakvo će biti posle uvoza.
5. Upišite napomenu → **Uvezi stanje** → potvrda. Stanje svakog uparenog artikla postaje tačno ono iz fajla (isto kao popis). Artikli kojih nema u fajlu ostaju kako jesu. Ako se isti artikal pojavi u više redova (npr. više skladišta), količine se sabiraju.
6. Kvačica **Zapamti uparivanja** čini da sledeći put isti nazivi iz Bluesofta budu odmah upareni.

Najviše 600 redova odjednom: ako izvoz ima mnogo drugih artikala, izvezite samo Farmerkop proizvode ili obrišite suvišne redove u Excelu. Excel fajlove (.xlsx) program ne čita direktno – koristite CSV ili nalepite tabelu.

> Posle uvoza otvorite **Stanje** i proverite nekoliko artikala. Greška se ispravlja novim popisom ili uvozom; ništa se ne briše – sve je u Istoriji i Dnevniku izmena.

**Ništa se ne briše zauvek:** obrisan unos ostaje u bazi, može da se vrati, a u dnevniku izmena piše ko ga je i kada obrisao.

### Bezbednost
- Posle **5 pogrešnih PIN-ova / šifara** nalog se zaključava (10 minuta, svaki sledeći put duplo duže). Administrator ga može odmah otključati u kartici *Radnici*.
- Radnik se automatski odjavljuje posle 60 minuta neaktivnosti, administrator posle 30.
- Šifre i PIN-ovi su u bazi sačuvani samo u šifrovanom obliku, nikad kao običan tekst.

---

## Održavanje

### Rezervna kopija (jednom nedeljno!)
cPanel → **Backup** → **Download a MySQL Database Backup** → kliknite na ime baze (preuzima se fajl). Sačuvajte ga na računaru ili u oblaku.
*(Isto može i: phpMyAdmin → izaberite bazu → Export.)*

### Zaboravljena šifra administratora
1. cPanel → **phpMyAdmin** → izaberite vašu bazu → tabelu **`korisnici`** → **Browse**.
2. Kod reda gde je `uloga` = `admin` kliknite **Edit**.
3. U polje **`hes`** zalepite ovo (to je privremena šifra **`Privremena-2026`**):

   ```
   $2y$10$.2g/BfLK7TnbAGoZ0SnvpesSjGdfU9yl24Xtu/cveGuIqzVF0sk6y
   ```
4. U poljima `neuspesni_pokusaji` upišite `0`, a polje `zakljucan_do` ostavite prazno (NULL) → **Go**.
5. Prijavite se sa šifrom `Privremena-2026` i **odmah** je promenite: *Podešavanja → Promena administratorske šifre*.

### Radnik je zaboravio PIN
*Radnici* → kod radnika **Izmeni ime ili PIN** → upišite novi PIN → **Sačuvaj**.

### Radnik je otišao iz firme
*Radnici* → **Isključi pristup**. Odjavljuje se odmah, a njegovi unosi ostaju u istoriji.

### Ažuriranje aplikacije (kad dobijete novu verziju)
Koristite **`farmerkop-azuriranje.zip`** (ne `farmerkop-aplikacija.zip`!). Otpremite ga u isti folder i uradite **Extract** (ako pita da li da prepiše fajlove – da). On **ne dira** vaš `config.php`, pa ostaju vaši podaci o bazi.
Bazu **ne treba ručno menjati**: pri prvom otvaranju posle ažuriranja aplikacija sama dodaje nove kolone i tabele, a svi vaši unosi i stanje ostaju netaknuti. Savet: pre ažuriranja preuzmite rezervnu kopiju baze (gore).

**Šta je novo u ovoj verziji:** paketi (transportno pakovanje) kao treći način unosa, popis u paletama + paketima + komadima, uvoz stanja iz fajla, naziv „Cmana supstrat“, i zaštite u Podešavanjima artikala (potvrda pre „Isključi“, ne može da se sačuva artikal bez ijednog pakovanja, crvena oznaka „nema pakovanja“, dugme „Vrati artikal na stanje bez varijanti“). Komada u paketu se podešava po pakovanju: *Podešavanja → Artikli → Uredi → pakete, palete i minimum*.

---

## Ako nešto ne radi

| Šta vidite | Šta da uradite |
|---|---|
| **„Nema veze sa bazom“** | Proverite `DB_NAME`, `DB_USER`, `DB_PASS` u `config.php` (pun naziv sa prefiksom, bez razmaka). Proverite da je korisnik dodat bazi sa **ALL PRIVILEGES** |
| **„Došlo je do greške“ / belina / greška 500** | Proverite PHP verziju (Korak 3). Privremeno u `config.php` dodajte red `define('DEBUG', true);` – stranica će ispisati razlog; zatim ga vratite na `false`. Greške se vide i u cPanel → **Errors** |
| **Ne nudi „Instaliraj aplikaciju“** | Mora da bude otvoren **https** (zeleni katanac, Korak 2) i Chrome. Osvežite stranicu, probajte opet kroz ⋮ meni |
| **Stranice se otvaraju bez izgleda / 404** | Fajlovi nisu u pravom folderu, ili `.htaccess` nije stigao (uključite *Show Hidden Files* i ponovite Korak 5) |
| **Stalno traži prijavu** | Radnik: istek posle 60 min neaktivnosti. Može da se produži u `config.php`: `define('SESIJA_RADNIK_MIN', 120);` |
| **„Sesija je istekla“ posle slanja** | Stranica je bila predugo otvorena; osvežite je i pokušajte ponovo |
| **CSV u Excelu ima čudna slova** | Excel → *Podaci → Iz teksta/CSV* → kodiranje **UTF-8**, razdvajač **tačka-zarez** |
| **Pogrešno vreme** | U `config.php` vremenska zona je `Europe/Belgrade`; promenite samo ako treba (`VREMENSKA_ZONA`) |
| **Bez interneta** | Prikaže se „Nema internet veze“. Unosi se ne čuvaju bez veze; pokušajte ponovo kad se vrati signal |

---

## Podešavanja koja se mogu menjati (`config.php`)

Odkomentarišite (obrišite `//` na početku) i promenite broj:

| Podešavanje | Znači | Podrazumevano |
|---|---|---|
| `SESIJA_RADNIK_MIN` | Odjava radnika posle toliko minuta neaktivnosti | 60 |
| `SESIJA_ADMIN_MIN` | isto za administratora | 30 |
| `MAX_POKUSAJA` | Broj pogrešnih pokušaja pre zaključavanja | 5 |
| `ZAKLJUCAVANJE_MIN` | Trajanje prvog zaključavanja (svako sledeće duplo duže) | 10 |
| `BRISANJE_RADNIK_MIN` | Koliko minuta radnik može da obriše svoj poslednji unos | 10 |
| `MAX_KOLICINA_UNOS` | Najviše komada u jednom unosu (zaštita od slučajne greške) | 10000 |
