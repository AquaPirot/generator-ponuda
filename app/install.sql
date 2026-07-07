-- ============================================
-- PROHORECA AG GROUP - Poslovi i ponude (v2)
-- Sema baze podataka (MySQL 5.7+ / MariaDB)
-- Uvezi kroz phpMyAdmin u PRAZAN database.
-- Za nadogradnju postojece baze koristi migrate-v2.sql!
-- ============================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    pass_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pdv_mode (samo za pravna lica): standard = PDV 20%, cl10 = obrnuti obracun cl.10 st.2 t.3
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip ENUM('fizicko','pravno') NOT NULL DEFAULT 'fizicko',
    pdv_mode ENUM('standard','cl10') NOT NULL DEFAULT 'standard',
    naziv VARCHAR(200) NOT NULL,
    telefon VARCHAR(50) DEFAULT '',
    email VARCHAR(150) DEFAULT '',
    adresa VARCHAR(200) DEFAULT '',
    mesto VARCHAR(100) DEFAULT '',
    pib VARCHAR(20) DEFAULT '',
    mb VARCHAR(20) DEFAULT '',
    napomena TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- POSAO = centralna stvar: klijent + stavke + cena.
-- Dokumenti i uplate se kace na posao.
-- status: ponuda | ugovoreno | realizacija | zavrseno | odbijeno
-- iznosi su UVEK u EUR (dokumenti mogu biti izdati u RSD po kursu)
CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    naziv VARCHAR(200) NOT NULL DEFAULT '',
    status ENUM('ponuda','ugovoreno','realizacija','zavrseno','odbijeno') NOT NULL DEFAULT 'ponuda',
    datum DATE NOT NULL,
    items MEDIUMTEXT,
    calc_state MEDIUMTEXT,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    disc_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    disc_val DECIMAL(14,2) NOT NULL DEFAULT 0,
    disc_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    rok VARCHAR(200) DEFAULT '',
    napomena TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_client (client_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOKUMENT = snimak posla u trenutku izdavanja (papir koji izlazi iz posla)
-- type: ponuda | predracun | avansni | faktura (= konacni racun)
-- status: izdat | storniran
-- pdv_mode: none | standard | cl10  (odredjuje se iz klijenta u trenutku izdavanja)
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NULL,
    type ENUM('ponuda','predracun','avansni','faktura') NOT NULL DEFAULT 'ponuda',
    godina SMALLINT NOT NULL,
    broj INT NOT NULL,
    oznaka VARCHAR(30) NOT NULL,
    client_id INT NULL,
    client_snapshot TEXT,
    datum DATE NOT NULL,
    valuta ENUM('EUR','RSD') NOT NULL DEFAULT 'EUR',
    kurs DECIMAL(10,4) NOT NULL DEFAULT 1,
    items MEDIUMTEXT,
    calc_state MEDIUMTEXT,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    disc_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    disc_val DECIMAL(14,2) NOT NULL DEFAULT 0,
    disc_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    pdv_mode VARCHAR(10) NOT NULL DEFAULT 'none',
    pdv_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    pdv_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    pdv_napomena TEXT,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    avans_procenat DECIMAL(5,2) NOT NULL DEFAULT 0,
    rok VARCHAR(200) DEFAULT '',
    napomena TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'izdat',
    share_token VARCHAR(40) DEFAULT NULL,
    parent_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_broj (type, godina, broj),
    UNIQUE KEY uniq_token (share_token),
    KEY idx_job (job_id),
    KEY idx_client (client_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UPLATA pripada poslu. kurs = vrednost 1 EUR u RSD na dan uplate (1 za EUR uplate).
-- avansni_doc_id = avansni racun izdat na ovu uplatu (ako postoji)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NULL,
    document_id INT NULL,
    avansni_doc_id INT NULL,
    datum DATE NOT NULL,
    iznos DECIMAL(14,2) NOT NULL,
    valuta ENUM('EUR','RSD') NOT NULL DEFAULT 'EUR',
    kurs DECIMAL(10,4) NOT NULL DEFAULT 1,
    nacin VARCHAR(50) DEFAULT 'gotovina',
    napomena VARCHAR(255) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_job (job_id),
    KEY idx_doc (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(50) PRIMARY KEY,
    svalue TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (skey, svalue) VALUES
    ('firma_naziv',   'PROHORECA AG GROUP'),
    ('firma_podnaslov','Pergole i stakleni sistemi'),
    ('firma_adresa',  ''),
    ('firma_mesto',   'Pirot'),
    ('firma_pib',     ''),
    ('firma_mb',      ''),
    ('firma_ziro',    ''),
    ('firma_banka',   ''),
    ('kontakt_ime',   'Aleksandar'),
    ('kontakt_tel',   '0648979242'),
    ('kontakt_email', ''),
    ('pdv_enabled',   '0'),
    ('pdv_rate',      '20'),
    ('pdv_napomena',  'Poreski obveznik nije u sistemu PDV-a. PDV nije obracunat u skladu sa cl. 33 Zakona o PDV-u.'),
    ('pdv_cl10_napomena', 'PDV obračunava primalac dobara/usluga kao poreski dužnik u skladu sa članom 10. stav 2. tačka 3. Zakona o PDV.'),
    ('kurs_eur',      '117.20'),
    ('ponuda_vazi_dana', '7'),
    ('price_pergola_m2', ''),
    ('price_close_m2',   ''),
    ('price_glass_m2',   '')
ON DUPLICATE KEY UPDATE skey = skey;
