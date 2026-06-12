-- ============================================
-- PROHORECA AG GROUP - Generator ponuda
-- Sema baze podataka (MySQL 5.7+ / MariaDB)
-- Uvezi kroz phpMyAdmin u prazni database
-- ============================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    pass_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip ENUM('fizicko','pravno') NOT NULL DEFAULT 'fizicko',
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

-- type: ponuda | predracun | avansni | faktura
-- status (ponuda): nacrt | poslato | prihvaceno | odbijeno | zavrseno
-- status (ostalo): izdat | placen | storniran
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    pdv_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    pdv_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    avans_procenat DECIMAL(5,2) NOT NULL DEFAULT 0,
    rok VARCHAR(200) DEFAULT '',
    napomena TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'nacrt',
    share_token VARCHAR(40) DEFAULT NULL,
    parent_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_broj (type, godina, broj),
    UNIQUE KEY uniq_token (share_token),
    KEY idx_client (client_id),
    KEY idx_status (status),
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    datum DATE NOT NULL,
    iznos DECIMAL(14,2) NOT NULL,
    valuta ENUM('EUR','RSD') NOT NULL DEFAULT 'EUR',
    nacin VARCHAR(50) DEFAULT 'gotovina',
    napomena VARCHAR(255) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    ('kurs_eur',      '117.20'),
    ('ponuda_vazi_dana', '7'),
    ('price_pergola_m2', ''),
    ('price_close_m2',   ''),
    ('price_glass_m2',   '')
ON DUPLICATE KEY UPDATE skey = skey;
