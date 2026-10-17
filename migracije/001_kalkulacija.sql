-- ═══════════════════════════════════════════════════════════════════════════════════════════
-- Farmerkop – modul „Kalkulacija“, Faza 1 (recepture i cena po džaku)
--
-- Pokreće se RUČNO, jednom: cPanel → phpMyAdmin → izaberite bazu aplikacije → kartica „Import“
-- → izaberite ovaj fajl → „Go“ (ili kartica „SQL“ → nalepite ceo sadržaj → „Go“).
--
-- Bezbedno je:
--   • samo pravi NOVE tabele (prefiks calc_) – postojeće tabele se ne menjaju i ništa se ne briše;
--   • može da se pokrene i više puta (CREATE TABLE IF NOT EXISTS, početni podaci se ne dupliraju);
--   • veze (FOREIGN KEY) idu na postojeće tabele artikli, pakovanja, sku i korisnici, samo za čitanje –
--     postojeći red ne može da se obriše dok ga kalkulacija koristi (aplikacija ionako ne briše artikle).
--
-- Početni podaci: samo nazivi sirovina (crni treset, beli treset, perlit, NPK) – bez cena i faktora.
-- Stopa PDV-a je 20 % (opšta stopa); menja se u aplikaciji (Kalkulacija → Pregled → Podešavanja).
--
-- Faza 2 (kasnije): recepture se vezuju za unos proizvodnje preko calc_recipes.id
-- (npr. nova kolona u novoj tabeli calc_production_usage), a sirovine se skidaju sa zalihe.
-- ═══════════════════════════════════════════════════════════════════════════════════════════

-- Sirovine: jedinica nabavke (bala, džak, kg, m³ …) i koliko litara GOTOVOG proizvoda daje jedna jedinica
-- (faktor korekcije: beli treset je sabijen u balama, a džak se puni posle mešanja i sleganja).
CREATE TABLE IF NOT EXISTS calc_materials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    naziv VARCHAR(100) NOT NULL,
    jedinica VARCHAR(20) NULL DEFAULT NULL,
    litara_po_jedinici DECIMAL(12,3) NULL DEFAULT NULL,
    napomena VARCHAR(255) NULL DEFAULT NULL,
    aktivna TINYINT(1) NOT NULL DEFAULT 1,
    napravljen DATETIME NOT NULL,
    izmenjen DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_materials_naziv (naziv),
    CONSTRAINT ck_calc_materials_faktor CHECK (litara_po_jedinici IS NULL OR litara_po_jedinici > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Istorija nabavnih cena: promena cene = novi red, stari ostaje. Važi poslednja cena sa datumom ≤ danas.
CREATE TABLE IF NOT EXISTS calc_material_prices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    materijal_id INT UNSIGNED NOT NULL,
    cena DECIMAL(12,2) NOT NULL,
    vazi_od DATE NOT NULL,
    napomena VARCHAR(255) NULL DEFAULT NULL,
    napravio_id INT UNSIGNED NULL DEFAULT NULL,
    napravljen DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_material_prices (materijal_id, vazi_od),
    CONSTRAINT fk_calc_mp_materijal FOREIGN KEY (materijal_id) REFERENCES calc_materials (id),
    CONSTRAINT fk_calc_mp_korisnik FOREIGN KEY (napravio_id) REFERENCES korisnici (id),
    CONSTRAINT ck_calc_mp_cena CHECK (cena >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ambalaža i ostali troškovi (vreća, etiketa, paleta, folija, rad, struja, gorivo …): po džaku ili po litru.
-- Jedan trošak = isti naziv i ista veličina džaka; nova cena = novi red sa novim datumom.
-- pakovanje_id NULL = važi za sve veličine; inače samo za tu veličinu (vreća 5 l ≠ vreća 50 l).
CREATE TABLE IF NOT EXISTS calc_cost_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    naziv VARCHAR(100) NOT NULL,
    tip ENUM('dzak','litar') NOT NULL,
    iznos DECIMAL(12,4) NOT NULL,
    vazi_od DATE NOT NULL,
    pakovanje_id INT UNSIGNED NULL DEFAULT NULL,
    napomena VARCHAR(255) NULL DEFAULT NULL,
    napravio_id INT UNSIGNED NULL DEFAULT NULL,
    napravljen DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_cost_items (naziv, pakovanje_id, vazi_od),
    KEY idx_calc_ci_pakovanje (pakovanje_id),
    CONSTRAINT fk_calc_ci_pakovanje FOREIGN KEY (pakovanje_id) REFERENCES pakovanja (id),
    CONSTRAINT fk_calc_ci_korisnik FOREIGN KEY (napravio_id) REFERENCES korisnici (id),
    CONSTRAINT ck_calc_ci_iznos CHECK (iznos >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recepture po proizvodu (artikli), sa verzijama: izmena = nova verzija, stara ostaje u istoriji.
-- aktivna_od = vazi_od dok je verzija aktivna, NULL kad je isključena – jedinstveni ključ zato
-- ne dozvoljava dve AKTIVNE verzije istog proizvoda sa istim datumom.
CREATE TABLE IF NOT EXISTS calc_recipes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    artikal_id INT UNSIGNED NOT NULL,
    naziv VARCHAR(100) NOT NULL,
    vazi_od DATE NOT NULL,
    aktivna TINYINT(1) NOT NULL DEFAULT 1,
    aktivna_od DATE NULL DEFAULT NULL,
    prethodna_id INT UNSIGNED NULL DEFAULT NULL,
    napomena VARCHAR(255) NULL DEFAULT NULL,
    napravio_id INT UNSIGNED NULL DEFAULT NULL,
    napravljen DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_recipes_aktivna (artikal_id, aktivna_od),
    KEY idx_calc_recipes_artikal (artikal_id, vazi_od),
    CONSTRAINT fk_calc_recipes_artikal FOREIGN KEY (artikal_id) REFERENCES artikli (id),
    CONSTRAINT fk_calc_recipes_prethodna FOREIGN KEY (prethodna_id) REFERENCES calc_recipes (id),
    CONSTRAINT fk_calc_recipes_korisnik FOREIGN KEY (napravio_id) REFERENCES korisnici (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sastav recepture: udeo sirovine u procentima ZAPREMINE gotovog proizvoda. Zbir mora biti tačno 100 %
-- (to proverava aplikacija pri čuvanju, u istoj transakciji; baza proverava svaki udeo: > 0 i ≤ 100).
CREATE TABLE IF NOT EXISTS calc_recipe_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recept_id INT UNSIGNED NOT NULL,
    materijal_id INT UNSIGNED NOT NULL,
    udeo DECIMAL(7,3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_recipe_items (recept_id, materijal_id),
    KEY idx_calc_ri_materijal (materijal_id),
    CONSTRAINT fk_calc_ri_recept FOREIGN KEY (recept_id) REFERENCES calc_recipes (id),
    CONSTRAINT fk_calc_ri_materijal FOREIGN KEY (materijal_id) REFERENCES calc_materials (id),
    CONSTRAINT ck_calc_ri_udeo CHECK (udeo > 0 AND udeo <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prodajne cene po proizvodu i veličini džaka (sku = artikal + varijanta + pakovanje), sa istorijom.
-- sa_pdv: 1 = upisana cena je SA PDV-om (pdv_stopa je stopa u trenutku unosa); marže se računaju bez PDV-a.
CREATE TABLE IF NOT EXISTS calc_selling_prices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku_id INT UNSIGNED NOT NULL,
    cena DECIMAL(12,2) NOT NULL,
    sa_pdv TINYINT(1) NOT NULL DEFAULT 0,
    pdv_stopa DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    vazi_od DATE NOT NULL,
    napravio_id INT UNSIGNED NULL DEFAULT NULL,
    napravljen DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_selling_prices (sku_id, vazi_od),
    CONSTRAINT fk_calc_sp_sku FOREIGN KEY (sku_id) REFERENCES sku (id),
    CONSTRAINT fk_calc_sp_korisnik FOREIGN KEY (napravio_id) REFERENCES korisnici (id),
    CONSTRAINT ck_calc_sp_cena CHECK (cena >= 0),
    CONSTRAINT ck_calc_sp_pdv CHECK (pdv_stopa >= 0 AND pdv_stopa < 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Podešavanja modula (stopa PDV-a za nove prodajne cene, prag marže ispod kog je marža crvena).
CREATE TABLE IF NOT EXISTS calc_settings (
    kljuc VARCHAR(40) NOT NULL,
    vrednost VARCHAR(255) NOT NULL,
    PRIMARY KEY (kljuc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Početni podaci (samo ako ih još nema) ──
INSERT IGNORE INTO calc_materials (naziv, napravljen, izmenjen) VALUES
    ('Crni treset', NOW(), NOW()),
    ('Beli treset', NOW(), NOW()),
    ('Perlit', NOW(), NOW()),
    ('NPK', NOW(), NOW());

INSERT IGNORE INTO calc_settings (kljuc, vrednost) VALUES
    ('pdv_stopa', '20'),
    ('prag_marze', '0'),
    ('verzija', '1');
