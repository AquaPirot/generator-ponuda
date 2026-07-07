-- ============================================
-- NADOGRADNJA POSTOJECE BAZE NA v2 (poslovi)
-- Pokreni JEDNOM kroz phpMyAdmin -> kartica SQL.
-- Postojeci podaci (klijenti, dokumenti, uplate, podesavanja) se cuvaju.
-- ============================================

SET NAMES utf8mb4;

-- 1) Klijenti: PDV tretman za pravna lica
ALTER TABLE clients
    ADD COLUMN pdv_mode ENUM('standard','cl10') NOT NULL DEFAULT 'standard' AFTER tip;

-- 2) Nova centralna tabela: POSLOVI
CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    src_doc_id INT NULL,
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

-- 3) Dokumenti: veza sa poslom + PDV snimak
ALTER TABLE documents
    ADD COLUMN job_id INT NULL AFTER id,
    ADD COLUMN pdv_mode VARCHAR(10) NOT NULL DEFAULT 'none' AFTER pdv_rate,
    ADD COLUMN pdv_napomena TEXT AFTER pdv_amount,
    ADD KEY idx_job (job_id);

UPDATE documents SET pdv_mode = 'standard' WHERE pdv_amount > 0;

-- 4) Uplate: veza sa poslom + kurs + veza sa izdatim avansnim
ALTER TABLE payments
    ADD COLUMN job_id INT NULL AFTER id,
    ADD COLUMN avansni_doc_id INT NULL AFTER document_id,
    ADD COLUMN kurs DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER valuta,
    ADD KEY idx_job (job_id);

-- 5) Svaka postojeca ponuda postaje POSAO
INSERT INTO jobs (src_doc_id, client_id, naziv, status, datum, items, calc_state,
                  subtotal, disc_type, disc_val, disc_amount, total, rok, napomena, created_at)
SELECT d.id, d.client_id, CONCAT('Posao po ponudi ', d.oznaka),
       CASE d.status WHEN 'prihvaceno' THEN 'realizacija'
                     WHEN 'zavrseno'   THEN 'zavrseno'
                     WHEN 'odbijeno'   THEN 'odbijeno'
                     ELSE 'ponuda' END,
       d.datum, d.items, d.calc_state,
       d.subtotal, d.disc_type, d.disc_val, d.disc_amount, d.total, d.rok, d.napomena, d.created_at
FROM documents d WHERE d.type = 'ponuda';

-- 6) Samostalni racuni (bez ponude) takodje postaju poslovi (iznos preracunat u EUR)
INSERT INTO jobs (src_doc_id, client_id, naziv, status, datum, items, calc_state,
                  subtotal, disc_type, disc_val, disc_amount, total, rok, napomena, created_at)
SELECT d.id, d.client_id, CONCAT('Posao po dokumentu ', d.oznaka),
       CASE WHEN d.status = 'placen' THEN 'zavrseno' ELSE 'ugovoreno' END,
       d.datum, d.items, d.calc_state,
       CASE WHEN d.valuta='RSD' AND d.kurs > 1 THEN ROUND(d.subtotal / d.kurs, 2) ELSE d.subtotal END,
       d.disc_type, d.disc_val,
       CASE WHEN d.valuta='RSD' AND d.kurs > 1 THEN ROUND(d.disc_amount / d.kurs, 2) ELSE d.disc_amount END,
       CASE WHEN d.valuta='RSD' AND d.kurs > 1 THEN ROUND(d.total / d.kurs, 2) ELSE d.total END,
       d.rok, d.napomena, d.created_at
FROM documents d WHERE d.type <> 'ponuda' AND d.parent_id IS NULL;

-- 7) Povezi dokumente sa poslovima
UPDATE documents d JOIN jobs j ON j.src_doc_id = d.id SET d.job_id = j.id;
UPDATE documents d JOIN jobs j ON j.src_doc_id = d.parent_id
    SET d.job_id = j.id WHERE d.parent_id IS NOT NULL AND d.job_id IS NULL;

-- 8) Statusi dokumenata: sada samo izdat | storniran
UPDATE documents SET status = 'izdat' WHERE status NOT IN ('storniran');

-- 9) Povezi uplate sa poslovima + kurs sa dokumenta
UPDATE payments p JOIN documents d ON d.id = p.document_id
    SET p.job_id = d.job_id,
        p.kurs = CASE WHEN p.valuta = 'RSD' AND d.kurs > 1 THEN d.kurs ELSE 1 END;

-- 10) Novo podesavanje: tekst napomene za obrnuti obracun (cl. 10)
INSERT INTO settings (skey, svalue) VALUES
    ('pdv_cl10_napomena', 'PDV obračunava primalac dobara/usluga kao poreski dužnik u skladu sa članom 10. stav 2. tačka 3. Zakona o PDV.')
ON DUPLICATE KEY UPDATE skey = skey;

-- 11) Pomocna kolona vise nije potrebna
ALTER TABLE jobs DROP COLUMN src_doc_id;
