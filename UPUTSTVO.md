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
- **Utovar:** spiskovi koje je administrator poslao na utovar. Roba je složena **po mestima istovara** (zaokružen broj, npr. Mesto 8, 9, 5), a svaki artikal piše punim nazivom (npr. **Humovit · 50 l**, **Beli oblutak · 4-6 cm · 20 kg**). Radnik otvori spisak, **štiklira svaku stavku** kad je utovarena, a ako je utovareno **više ili manje** od plana (npr. šleper na licu mesta primi više) dodirne **Više ili manje od plana** i upiše stvarno (0 ako nije utovareno ništa). Zbirna paleta je kod radnika jedna grupa: jedan dodir na kvadratić palete štiklira sve artikle na njoj (ispravka količine u paleti je u ukupnim komadima). Ako u kamion ide i nešto čega nema na spisku: **Dodaj artikal kog nema na spisku** (za koje mesto; artikal: otkuca npr. „humovit“ i dodirne pakovanje 5 / 10 / 25 / 50 l; palete/paketi/komadi) – dodata stavka je odmah štiklirana i označena „dodato pri utovaru“. Na kraju **Potvrdi utovar** – tek tada se skida stanje, za stvarno utovarene količine. Dupli dodir ili dva telefona ne mogu da skinu stanje dvaput.

### Administrator (donja traka)
| Kartica | Čemu služi |
|---|---|
| **Stanje** | Stanje svakog artikla po pakovanju (komadi, palete, litri), danas proizvedeno i prodato, crveno kad padne ispod minimuma |
| **Utovar** | Spiskovi za utovar – jedan spisak je jedan kamion, roba po mestima istovara (Mesto 8, 9, 5 …). Odatle je i **Ručna prodaja** – samo za izuzetke (npr. neko dođe po par vreća bez spiska) |
| **Istorija** | Svi unosi sa filterima; ispravka i brisanje (uz zapis ko i kad); **Izvoz u Excel (CSV)** |
| **Radnici** | Dodavanje, PIN, gašenje i vraćanje pristupa, pregled ko je koliko proizveo po danima |
| **Podešavanja** | Artikli i varijante, pakovanja, minimum, komada u paketu i po paleti, težina komada, **popis stanja**, **uvoz stanja iz fajla**, promena šifre, dnevnik izmena |

**Kako se računa stanje:** proizvedeno − prodato (uključujući kućnu prodaju) ± korekcije iz popisa. Sve se čuva u komadima; palete i paketi su samo način unosa.

### Spisak za utovar
Jedan spisak je **jedan kamion**. Roba se upisuje **po mestima istovara** – kao zaokruženi brojevi na papiru (npr. 8, 9, 5, 4, 2), a svako mesto ima svoju robu.

1. **Utovar → Novi spisak za utovar:** datum utovara, vozilo, vozač, napomena i **prvo mesto istovara** – broj mesta (kako ga pišete na papiru, npr. 8) i kupac (nije obavezan) → **Napravi spisak**.
2. **Dodaj mesto** (dole na spisku): broj mesta (program predloži sledeći, vi upišite svoj), kupac i adresa/napomena nisu obavezni. Mesta dodajete koliko god treba. Na kartici mesta: **↑ ↓** menjaju redosled, kanta uklanja mesto sa svom robom, **Broj, kupac, adresa** menja podatke mesta.
3. **Dodaj robu za Mesto N** – više artikala odjednom: forma ima 3 reda (**Još jedan artikal** dodaje red). U svaki red kucajte naziv, npr. **humovit** – ispod se odmah pojave pakovanja (**5 l, 10 l, 25 l, 50 l**, a posebno Humovit premium 20 l, 50 l); **dodirnite pakovanje** i u polju piše pun naziv (*Humovit · 50 l*). Može i odmah tačno: „humovit 50“, „idea 10“, „oblutak beli 40-60“ (milimetri kao na papiru), „rizla bela sitna“ – kad je samo jedno moguće, ispod piše ✓ i naziv. Zatim **palete + paketi + komadi** u bilo kojoj kombinaciji (npr. 1 paleta + 2 paketa + 3 komada) – ispod piše ukupno komada i koliko ima na stanju. Popunite sve redove pa **Sačuvaj robu za Mesto N** – sve se čuva odjednom (prazni redovi se preskaču; ako neki red nije dobar, ne čuva se ništa i piše koji je red, a upisano ostaje). Isti artikal može da ide za više mesta.
4. **Zbirna paleta** (više različitih artikala na jednoj paleti): na kartici mesta **Zbirna paleta** → u redove upišite **artikal i koliko ide na jednu paletu**: komada (**25**), deo palete (**1/2** ili **pola** – pola palete rizle = 25 komada) ili paketa (**3 pak**); **Još jedan artikal** dodaje red. Artikal se bira isto kao gore (kucanje, pa dodir na pakovanje). Primer sa papira „1/2 rizla bela sitna + 1/2 oblutak beli 40-60“ upisuje se tako: **rizla bela sitna – 1/2** i **oblutak beli 40-60 – 1/2**. Ako treba više ovakvih paleta, upišite **Koliko ovakvih paleta** – program sam pomnoži. → **Dodaj zbirnu paletu**. Zbirna paleta se broji kao cela paleta. U postojeću zbirnu paletu možete i kasnije dodati artikal: *Dodaj robu* → **Gde ide: U zbirnu paletu**.
5. **Ostalo** (u *Dodaj robu*): roba koja se ne vodi u aplikaciji (naziv, količina, jedinica, kg po jedinici). Ide na spisak i u težinu tovara, ali ne menja stanje.
6. **Izmene:** stavka je kratak red (artikal · količina · težina). **Dodirnite stavku** – otvore se polja za količinu i kanta za brisanje; dodir na naziv zbirne palete menja naziv i broj paleta. Na kraju **Sačuvaj izmene**. Ako krenete sa stranice a niste sačuvali, telefon pita da li da napusti.
7. Gore se vidi **broj mesta**, **broj paleta** (i koliko je zbirnih) i **težina tovara** (okvirno, po težinama iz Podešavanja → Artikli → Kg/kom); svako mesto ima i svoj zbir.
8. Ako za neku stavku nema dovoljno slobodne robe (računa se ukupno po artiklu – za sva mesta i zbirne palete), spisak to pokaže žutim upozorenjem – to je samo upozorenje.
9. **Pošalji na utovar:** spisak dobija status *Za utovar* i količine su **rezervisane** (Stanje pokazuje *rezervisano* i *slobodno*). Mesto bez robe ne može na utovar (dodajte robu ili uklonite mesto). Stanje se **ne skida** dok radnik ne potvrdi utovar.
10. Za izmene: **Vrati u nacrt**, izmenite, pa ponovo pošaljite. **Otkaži spisak** ukida rezervaciju.
11. **Utovar potvrđuje radnik** (kartica Utovar na njegovom telefonu) – ili vi, dugmetom **Potvrdi utovar** na spisku. Stanje se skida za stvarno utovarenu količinu, a roba se vodi na **kupca svog mesta**. Ako je utovareno više nego što program misli da ima, stanje ode u minus i Stanje to pokaže crveno („uradite popis“).
12. **Utovaren spisak se više ne menja.** Ako je utovar bio greška: **Otkaži utovar (vrati robu na stanje)** – sve se vraća kao „povrat“. Ostale razlike ispravljate popisom.
13. **Brisanje:** otkazan spisak koji vam više ne treba obrišite dugmetom **Obriši spisak** (dole na spisku, uz potvrdu). Ako je bio utovaren pa otkazan, njegov utovar i povrat se u Istoriji označe kao obrisani – stanje ostaje isto. Brisanje se upisuje u Dnevnik izmena i ne može da se vrati.
14. **Štampa / PDF / Podeli:** spisak za štampu po mestima (zaokružen broj, kupac, zbir mesta), artikli punim nazivom, i mesta za potpis. PDF: *Štampaj / PDF* → kao štampač izaberite **Sačuvaj kao PDF**. **Podeli** šalje tekst spiska u Viber, WhatsApp ili SMS (po mestima).

Statusi: **Nacrt** (slobodno menjate, stanje se ne dira) → **Za utovar** (radnici vide, rezervisano) → **Utovareno** (tek tada se skida stanje) · **Otkazano**.
Sve promene spiskova su u *Dnevniku izmena*. Ako ručno unesete prodaju kupcu koji tog dana ima mesto na spisku, aplikacija upozori da se ista roba ne bi skinula dvaput.

### Spisak sa papira (prepišite ili izdiktirajte ceo spisak)
Kad je spisak napisan rukom (npr. otac napiše na papiru), ne morate da ga unosite stavku po stavku. Na spisku (nacrt) dodirnite **Spisak sa papira**:

1. U jedno polje **prepišite spisak kako je napisan** – ili ga na telefonu **izdiktirajte** (dodirnite mikrofon na tastaturi). Na primer:

   ```
   8 Agrocentar
   2 pal humovit 50
   humovit 10 - 30
   3 pak idea 5
   1/2 rizla bela sitna + 1/2 oblutak beli 40-60

   9
   1 pal idea 10
   2 t oblutak beli 4-6
   ```

   - Svako mesto počinje **brojem** u svom redu (**8**, **(8)** ili **Mesto 8**); iza broja može kupac, a red ispod broja može biti kupac ili adresa.
   - Svaki artikal u svom redu, sa količinom: **2 pal**, **3 pak**, **30 kom**, **2 t** (preračuna se u komade po težini), **1/2** ili **pola** (pola palete). Brojevi mogu i rečima („dve palete“). Broj bez oznake znači komade, a za **5 l i 10 l pakete** (kako ih otac piše: „humovit 10 - 30“ = 30 paketa); ako izaberete ispod polja, broj bez oznake znači palete. Ko želi baš komade, napiše „30 kom“.
   - **Zbirna paleta** u jednom redu, sa **+** između delova; „2x“ na početku znači dve takve palete.
2. **Pročitaj spisak** – aplikacija pokaže šta je razumela, mesto po mesto (iznad svakog reda sitno piše kako je bilo na papiru). Ništa još nije upisano.
3. **Proverite označeno** (žuti okvir): gde piše samo „humovit“ dodirnite pakovanje, upišite količinu koja nedostaje, a red koji ne treba (ili robu koja se ne vodi u aplikaciji – nju dodajte posle kao *Ostalo*) štiklirajte **Preskoči**.
4. **Sačuvaj sve na spisak** – sva mesta i sva roba se upisuju odjednom. Mesto sa istim brojem koje već postoji se dopunjuje (isti artikal dobija novu količinu), a novo mesto se dodaje. Ako nešto nije u redu, ništa se ne upisuje, a greška stoji uz svoj red.

### Slikaj spisak (aplikacija čita rukom pisan spisak sa slike)
Na **Spisak sa papira** je gore dugme **Slikaj spisak**: slikajte ceo papir (ili izaberite sliku iz galerije). Telefon sliku smanji i pošalje; AI servis (Anthropic, Claude) za pola minuta prepiše rukopis, a aplikacija pokaže isti pregled kao kad se spisak otkuca. **Uporedite svaki red sa papirom** – nečitke reči su označene (??) i žute – pa **Sačuvaj sve na spisak**. Prepisan tekst možete i ispraviti (**Izmeni tekst**). Slika se ne čuva na serveru.

**Uključivanje (jednom):**
1. Na **console.anthropic.com** napravite nalog, dodajte način plaćanja (Billing) i napravite ključ: **API Keys → Create Key**. Ključ počinje sa `sk-ant-`.
2. U cPanel → File Manager otvorite **config.php** (u folderu aplikacije) i dodajte red (ili obrišite `//` ispred postojećeg):
   `define('ANTHROPIC_API_KEY', 'sk-ant-…vaš ključ…');`
3. Sačuvajte. Dugme **Slikaj spisak** se odmah pojavljuje.

Ključ stoji samo na serveru (nikad se ne vidi u telefonu) – ne šaljite ga nikome i ne lepite ga u poruke. Čitanje jedne slike košta otprilike 4–6 dinara (plaća se na console.anthropic.com; tamo možete postaviti i mesečni limit). Bez ključa sve ostalo radi isto, a spisak se kuca ili diktira.

### Kupci po godini (ko je koliko uzeo)
Meni **☰** → **Kupci po godini** (ili Istorija → **Kupci po godini**): za izabranu godinu, svaki kupac i koliko je robe uzeo – po artiklu, u komadima, litrima i kilogramima; kupci sa najviše robe su prvi. Računa se ručna prodaja + utovar sa spiskova (na kupca mesta) − povrat; otkazani utovari i obrisani unosi se ne računaju, a kućna prodaja i mesta bez kupca su pod „Bez kupca“. Dodir na kupca otvara spisak artikala; **Izvoz u Excel (CSV)** daje celu godinu u tabeli. Podaci se čuvaju zauvek (iz istorije unosa), pa se može izabrati i svaka ranija godina.

### Kalkulacija (recepture i cena po džaku) – samo administrator
Otvara se dugmetom **☰** gore desno (u zaglavlju) → **Kalkulacija**. Radnici ne vide ni dugme ni podatke. Početni ekran (Stanje) i donja traka su isti kao pre.

**Uključivanje (jednom):** modul ima svoje tabele, koje pravi ručna migracija **`migracije/001_kalkulacija.sql`**: cPanel → **phpMyAdmin** → levo izaberite bazu aplikacije → kartica **Import** → izaberite fajl → **Go**. Migracija samo dodaje nove tabele (`calc_…`) – postojeće tabele, zalihe i unosi se ne menjaju, i može da se pokrene više puta bez štete. Dok se ne pokrene, Kalkulacija pokazuje ovo uputstvo.

Redom:
1. **Sirovine i cene** – za svaku sirovinu (crni treset, beli treset, perlit, NPK – ostale dodajete sami): **jedinica nabavke** (bala, džak, kg, m³) i **faktor** = koliko litara *gotovog* proizvoda daje jedna jedinica (beli treset je sabijen u bali; džak se puni posle mešanja i sleganja). Zatim **cena** po jedinici i od kog datuma važi. Nova cena = novi red; stare ostaju u istoriji (pogrešno upisana cena može da se obriše samo istog dana).
2. **Troškovi** – vreća, etiketa, paleta, folija, rad, struja, gorivo …: **po džaku** ili **po litru**, za sve veličine ili samo za jednu (vreća 5 l ≠ vreća 50 l; trošak upisan za veličinu zamenjuje istoimeni „za sve veličine“). Trošak koji više ne postoji: nova cena 0.
3. **Recepture** – proizvod, sirovine i udeo u **procentima zapremine gotovog proizvoda** (dozvoljene su 3 decimale, npr. NPK 0,125 %). Zbir se računa dok kucate i mora biti tačno **100 %**. **Izmeni** pravi **novu verziju** sa datumom od kog važi; stara ostaje u istoriji (verzija može da se isključi).
4. **Prodajne cene** – po proizvodu i veličini džaka, **sa ili bez PDV-a** (kako vam je lakše); marža se uvek računa bez PDV-a.
5. **Pregled kalkulacije** – za svaki proizvod i veličinu: trošak sirovina, ostali troškovi, ukupan trošak, prodajna cena bez PDV-a i **marža** (RSD i % od prodajne cene bez PDV-a). Dodir na red otvara razradu po sirovinama. Marža ispod praga (Podešavanja kalkulacije, dole na Pregledu) je crvena. Ako sirovini nedostaje cena ili faktor, ništa se ne računa kao nula – piše **„nedostaje cena za: …“**.
6. **Šta ako** – izaberite sirovinu ili trošak i promenu (u % ili RSD, npr. +10 ili −5): vidite novi trošak i maržu za sve proizvode pored današnjih, i (uz **ciljanu maržu**) prodajnu cenu potrebnu za nju – bez i sa PDV-om. Ništa se ne upisuje.

Formula: trošak sirovina = Σ udeo × V × (cena / litara gotovog po jedinici); ukupno = sirovine + troškovi po džaku + troškovi po litru × V. Uvek se uzimaju cene koje važe danas. Kalkulacija je po litru, pa proizvodi u kg (oblutak, rizla) nisu u njoj. U ovoj fazi kalkulacija **ne skida** sirovine sa zalihe i nije vezana za unos proizvodnje.

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

**Novo – Slikaj spisak, Kupci po godini, brisanje otkazanog spiska:** na Spisku sa papira dugme **Slikaj spisak** (radi kad u config.php upišete ključ – vidi *Slikaj spisak*); meni ☰ → **Kupci po godini**; otkazan spisak ima dugme **Obriši spisak**. Baza se ne menja.

**Novo – Kalkulacija (recepture i cena po džaku):** dugme **☰** gore desno (samo administrator) otvara modul Kalkulacija. Pre prve upotrebe pokrenite **ručno** migraciju `migracije/001_kalkulacija.sql` u phpMyAdmin-u (vidi *Kalkulacija* gore) – ona samo dodaje nove tabele. Ostatak aplikacije radi isto i bez nje.

**Novo – pun naziv, izbor pakovanja, više robe odjednom i spisak sa papira (verzija baze 9):** na spisku, kod radnika, na štampi i u poruci za Viber/WhatsApp opet piše **pun naziv proizvoda** (Humovit · 50 l); šifre (H50L) i „Kako piše na spisku“ su ukinute, a kolona sa šiframa se sama briše iz baze. Artikal se bira kucanjem: „humovit“ → dodir na **5 l, 10 l, 25 l ili 50 l**. U **Dodaj robu** se upisuje **više artikala odjednom** i čuva jednim dugmetom. Novo: **Spisak sa papira** – ceo rukom pisan spisak se prepiše ili izdiktira, aplikacija ga pretvori u mesta i robu, vi proverite i sačuvate.

**Samo Humovit ima šifru (verzija baze 8):** šifre su menjane u imena (B oblutak 4/6) – u verziji 9 su potpuno ukinute.

**Mesta istovara (verzija baze 7):** jedan spisak je jedan kamion sa **više mesta istovara** (Mesto 8, 9, 5 … – broj upisujete vi, kupac nije obavezan); svako mesto ima svoju robu i zbirne palete, a roba se pri utovaru vodi na kupca svog mesta. Postojeći spiskovi su dobili **Mesto 1** sa svojim kupcem. **Zbirna paleta se unosi odjednom** (npr. rizla bela sitna 1/2 + oblutak beli 40-60 1/2), a stavke su kratki redovi koji se otvaraju dodirom.

**Zbirna paleta (verzija baze 6):** na spisak za utovar može da se doda zbirna paleta – više različitih artikala složenih na jednu paletu (npr. 5 × 5 l, 5 × 10 l, 5 × 50 l), i više ovakvih paleta odjednom. Radnici je vide kao grupu i štikliraju jednim dodirom; na štampi i u tekstu za Viber/WhatsApp je posebno označena.

**Utovar šlepera na licu mesta (verzija baze 5):** radnik pri utovaru može da utovari više ili manje od plana i da doda artikal kog nema na spisku; vi na spisku, štampi i u Dnevniku izmena vidite šta je dodato i ko je dodao.

**Spisak za utovar, faze 3 i 4:** radnik potvrđuje utovar (kartica **Utovar**), stanje se skida tek tada; otkazivanje utovarenog spiska vraća robu (povrat); štampa, PDF i deljenje spiska. Unosi sa spiska se u Istoriji ne menjaju ručno (vode na spisak).

**Spisak za utovar, faza 2 (verzija baze 4):** kartica **Utovar** u donjoj traci (umesto Prodaje; ručna prodaja je unutra), pravljenje spiskova, rezervacija, broj paleta i težina tovara. Okvirne težine su upisane same: zemlja 5 l ≈ 1,8 kg (i srazmerno za ostala pakovanja), malč 50 l ≈ 15 kg, perlit 100 l ≈ 7 kg, keramzit 35 l ≈ 7 kg; rizla 50 komada po paleti. Težine koje ste već sami upisali se ne menjaju.

**Spisak za utovar, faza 1 (verzija baze 3):** novi artikli (crni i crveni oblutak u svim granulacijama; bela, crna i crvena rizla – sitnija i krupnija, 20 kg; perlit 5 l (20 u paketu) i 100 l; keramzit 5 l (8 u paketu) i 35 l), kolona **Kg/kom** (okvirna težina jednog komada, za težinu tovara) u *Podešavanja → Artikli → Uredi*, i priprema baze za spiskove za utovar. Ako ste neki od ovih artikala već ranije sami dodali, ne pravi se duplikat.

**Prethodno ažuriranje:** paketi (transportno pakovanje) kao treći način unosa, popis u paletama + paketima + komadima, uvoz stanja iz fajla, naziv „Cmana supstrat“, i zaštite u Podešavanjima artikala (potvrda pre „Isključi“, ne može da se sačuva artikal bez ijednog pakovanja, crvena oznaka „nema pakovanja“, dugme „Vrati artikal na stanje bez varijanti“). Komada u paketu se podešava po pakovanju: *Podešavanja → Artikli → Uredi → pakete, palete i minimum*.

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
