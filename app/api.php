<?php
// ============================================
// API - svi pozivi idu na api.php?action=...
// ============================================
require_once __DIR__ . '/lib/db.php';
session_start();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ---------- AUTH ----------
        case 'setup_needed': {
            $n = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            json_out(['setup' => $n === 0]);
        }

        case 'setup': {
            $n = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($n > 0) json_out(['error' => 'Admin vec postoji'], 403);
            $d = json_in();
            $u = trim($d['username'] ?? '');
            $p = $d['password'] ?? '';
            if (strlen($u) < 3 || strlen($p) < 6) {
                json_out(['error' => 'Korisnicko ime min 3, lozinka min 6 karaktera'], 400);
            }
            $st = db()->prepare('INSERT INTO users (username, pass_hash, name) VALUES (?,?,?)');
            $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), trim($d['name'] ?? '')]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            json_out(['ok' => true]);
        }

        case 'login': {
            $d = json_in();
            $st = db()->prepare('SELECT * FROM users WHERE username = ?');
            $st->execute([trim($d['username'] ?? '')]);
            $user = $st->fetch();
            if (!$user || !password_verify($d['password'] ?? '', $user['pass_hash'])) {
                json_out(['error' => 'Pogresno korisnicko ime ili lozinka'], 401);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            json_out(['ok' => true, 'name' => $user['name']]);
        }

        case 'logout': {
            session_destroy();
            json_out(['ok' => true]);
        }

        // ---------- SETTINGS ----------
        case 'settings_get': {
            require_login();
            json_out(get_all_settings());
        }

        case 'settings_save': {
            require_login();
            $d = json_in();
            $allowed = ['firma_naziv','firma_podnaslov','firma_adresa','firma_mesto','firma_pib','firma_mb',
                        'firma_ziro','firma_banka','kontakt_ime','kontakt_tel','kontakt_email',
                        'pdv_enabled','pdv_rate','pdv_napomena','kurs_eur','ponuda_vazi_dana',
                        'price_pergola_m2','price_close_m2','price_glass_m2'];
            $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
            foreach ($allowed as $k) {
                if (array_key_exists($k, $d)) $st->execute([$k, (string)$d[$k]]);
            }
            json_out(['ok' => true]);
        }

        // ---------- CLIENTS ----------
        case 'clients_list': {
            require_login();
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $st = db()->prepare("SELECT * FROM clients WHERE naziv LIKE ? OR telefon LIKE ? OR mesto LIKE ? OR pib LIKE ? ORDER BY naziv LIMIT 200");
                $like = "%$q%";
                $st->execute([$like, $like, $like, $like]);
            } else {
                $st = db()->query('SELECT * FROM clients ORDER BY naziv LIMIT 500');
            }
            json_out($st->fetchAll());
        }

        case 'client_save': {
            require_login();
            $d = json_in();
            $naziv = trim($d['naziv'] ?? '');
            if ($naziv === '') json_out(['error' => 'Naziv / ime je obavezno'], 400);
            $fields = [
                $d['tip'] === 'pravno' ? 'pravno' : 'fizicko',
                $naziv,
                trim($d['telefon'] ?? ''), trim($d['email'] ?? ''),
                trim($d['adresa'] ?? ''), trim($d['mesto'] ?? ''),
                trim($d['pib'] ?? ''), trim($d['mb'] ?? ''),
                trim($d['napomena'] ?? ''),
            ];
            if (!empty($d['id'])) {
                $fields[] = (int)$d['id'];
                db()->prepare('UPDATE clients SET tip=?, naziv=?, telefon=?, email=?, adresa=?, mesto=?, pib=?, mb=?, napomena=? WHERE id=?')
                   ->execute($fields);
                json_out(['ok' => true, 'id' => (int)$d['id']]);
            }
            db()->prepare('INSERT INTO clients (tip, naziv, telefon, email, adresa, mesto, pib, mb, napomena) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute($fields);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'client_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT COUNT(*) FROM documents WHERE client_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                json_out(['error' => 'Klijent ima dokumente - ne moze se obrisati'], 400);
            }
            db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        // ---------- DOCUMENTS ----------
        case 'docs_list': {
            require_login();
            $where = []; $params = [];
            if (!empty($_GET['type']))   { $where[] = 'd.type = ?';   $params[] = $_GET['type']; }
            if (!empty($_GET['status'])) { $where[] = 'd.status = ?'; $params[] = $_GET['status']; }
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $where[] = '(d.oznaka LIKE ? OR c.naziv LIKE ? OR c.mesto LIKE ? OR c.telefon LIKE ?)';
                $like = "%$q%";
                array_push($params, $like, $like, $like, $like);
            }
            $sql = 'SELECT d.id, d.type, d.oznaka, d.datum, d.valuta, d.total, d.status, d.client_id, d.parent_id,
                           d.created_at, c.naziv AS klijent, c.mesto AS klijent_mesto, c.tip AS klijent_tip
                    FROM documents d LEFT JOIN clients c ON c.id = d.client_id';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY d.id DESC LIMIT 500';
            $st = db()->prepare($sql);
            $st->execute($params);
            json_out($st->fetchAll());
        }

        case 'doc_get': {
            require_login();
            $st = db()->prepare('SELECT d.*, c.naziv AS klijent_naziv, c.tip AS klijent_tip, c.telefon AS klijent_tel,
                                        c.adresa AS klijent_adresa, c.mesto AS klijent_mesto, c.pib AS klijent_pib,
                                        c.mb AS klijent_mb, c.email AS klijent_email,
                                        par.oznaka AS parent_oznaka
                                 FROM documents d
                                 LEFT JOIN clients c ON c.id = d.client_id
                                 LEFT JOIN documents par ON par.id = d.parent_id
                                 WHERE d.id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $doc = $st->fetch();
            if (!$doc) json_out(['error' => 'Dokument nije pronadjen'], 404);
            $doc['items'] = json_decode($doc['items'] ?? '[]', true) ?: [];
            $doc['calc_state'] = json_decode($doc['calc_state'] ?? 'null', true);
            // deca (predracuni/fakture nastale iz ove ponude)
            $st2 = db()->prepare('SELECT id, type, oznaka, total, valuta, status FROM documents WHERE parent_id = ? ORDER BY id');
            $st2->execute([$doc['id']]);
            $doc['children'] = $st2->fetchAll();
            json_out($doc);
        }

        case 'doc_save': {
            require_login();
            $d = json_in();
            $type = in_array($d['type'] ?? '', ['ponuda','predracun','avansni','faktura']) ? $d['type'] : 'ponuda';
            $datum = $d['datum'] ?: date('Y-m-d');
            $items = json_encode($d['items'] ?? [], JSON_UNESCAPED_UNICODE);
            $calc  = isset($d['calc_state']) ? json_encode($d['calc_state'], JSON_UNESCAPED_UNICODE) : null;
            $clientId = !empty($d['client_id']) ? (int)$d['client_id'] : null;

            if (!empty($d['id'])) {
                db()->prepare('UPDATE documents SET client_id=?, datum=?, valuta=?, kurs=?, items=?, calc_state=?,
                               subtotal=?, disc_type=?, disc_val=?, disc_amount=?, pdv_rate=?, pdv_amount=?, total=?,
                               avans_procenat=?, rok=?, napomena=? WHERE id=?')
                   ->execute([$clientId, $datum, $d['valuta'] ?? 'EUR', (float)($d['kurs'] ?? 1), $items, $calc,
                              (float)$d['subtotal'], $d['disc_type'] ?? 'percent', (float)($d['disc_val'] ?? 0),
                              (float)($d['disc_amount'] ?? 0), (float)($d['pdv_rate'] ?? 0), (float)($d['pdv_amount'] ?? 0),
                              (float)$d['total'], (float)($d['avans_procenat'] ?? 0),
                              trim($d['rok'] ?? ''), trim($d['napomena'] ?? ''), (int)$d['id']]);
                $st = db()->prepare('SELECT oznaka FROM documents WHERE id=?');
                $st->execute([(int)$d['id']]);
                json_out(['ok' => true, 'id' => (int)$d['id'], 'oznaka' => $st->fetchColumn()]);
            }

            $godina = (int)substr($datum, 0, 4);
            db()->beginTransaction();
            [$broj, $oznaka] = next_doc_number($type, $godina);
            db()->prepare('INSERT INTO documents (type, godina, broj, oznaka, client_id, datum, valuta, kurs, items, calc_state,
                           subtotal, disc_type, disc_val, disc_amount, pdv_rate, pdv_amount, total, avans_procenat, rok, napomena, status, parent_id)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$type, $godina, $broj, $oznaka, $clientId, $datum, $d['valuta'] ?? 'EUR', (float)($d['kurs'] ?? 1),
                          $items, $calc, (float)$d['subtotal'], $d['disc_type'] ?? 'percent', (float)($d['disc_val'] ?? 0),
                          (float)($d['disc_amount'] ?? 0), (float)($d['pdv_rate'] ?? 0), (float)($d['pdv_amount'] ?? 0),
                          (float)$d['total'], (float)($d['avans_procenat'] ?? 0), trim($d['rok'] ?? ''), trim($d['napomena'] ?? ''),
                          $type === 'ponuda' ? 'nacrt' : 'izdat',
                          !empty($d['parent_id']) ? (int)$d['parent_id'] : null]);
            $id = (int)db()->lastInsertId();
            db()->commit();
            json_out(['ok' => true, 'id' => $id, 'oznaka' => $oznaka]);
        }

        case 'doc_status': {
            require_login();
            $d = json_in();
            $valid = ['nacrt','poslato','prihvaceno','odbijeno','zavrseno','izdat','placen','storniran'];
            if (!in_array($d['status'] ?? '', $valid)) json_out(['error' => 'Nepoznat status'], 400);
            db()->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([$d['status'], (int)$d['id']]);
            json_out(['ok' => true]);
        }

        case 'doc_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT COUNT(*) FROM documents WHERE parent_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) json_out(['error' => 'Dokument ima povezane dokumente'], 400);
            db()->prepare('DELETE FROM payments WHERE document_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'doc_share': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT share_token FROM documents WHERE id = ?');
            $st->execute([$id]);
            $tok = $st->fetchColumn();
            if (!$tok) {
                $tok = bin2hex(random_bytes(8));
                db()->prepare('UPDATE documents SET share_token = ? WHERE id = ?')->execute([$tok, $id]);
            }
            json_out(['ok' => true, 'url' => APP_URL . '/p.php?t=' . $tok]);
        }

        // ---------- PAYMENTS ----------
        case 'payments_list': {
            require_login();
            $st = db()->prepare('SELECT * FROM payments WHERE document_id = ? ORDER BY datum, id');
            $st->execute([(int)($_GET['doc_id'] ?? 0)]);
            json_out($st->fetchAll());
        }

        case 'payment_save': {
            require_login();
            $d = json_in();
            $iznos = (float)($d['iznos'] ?? 0);
            if ($iznos <= 0) json_out(['error' => 'Iznos mora biti veci od 0'], 400);
            db()->prepare('INSERT INTO payments (document_id, datum, iznos, valuta, nacin, napomena) VALUES (?,?,?,?,?,?)')
               ->execute([(int)$d['document_id'], $d['datum'] ?: date('Y-m-d'), $iznos,
                          $d['valuta'] ?? 'EUR', trim($d['nacin'] ?? 'gotovina'), trim($d['napomena'] ?? '')]);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'payment_delete': {
            require_login();
            db()->prepare('DELETE FROM payments WHERE id = ?')->execute([(int)(json_in()['id'] ?? 0)]);
            json_out(['ok' => true]);
        }

        // ---------- UGOVORI (prihvacene ponude + uplate) ----------
        case 'contracts_list': {
            require_login();
            $rows = db()->query(
                "SELECT d.id, d.oznaka, d.datum, d.valuta, d.total, d.status, d.rok,
                        c.naziv AS klijent, c.mesto AS klijent_mesto, c.telefon AS klijent_tel,
                        COALESCE((SELECT SUM(p.iznos) FROM payments p WHERE p.document_id = d.id), 0) AS uplaceno
                 FROM documents d LEFT JOIN clients c ON c.id = d.client_id
                 WHERE d.type = 'ponuda' AND d.status IN ('prihvaceno','zavrseno')
                 ORDER BY FIELD(d.status,'prihvaceno','zavrseno'), d.id DESC"
            )->fetchAll();
            json_out($rows);
        }

        case 'dashboard': {
            require_login();
            $g = date('Y');
            $row = db()->query(
                "SELECT
                    (SELECT COUNT(*) FROM documents WHERE type='ponuda' AND godina=$g) AS ponuda_br,
                    (SELECT COUNT(*) FROM documents WHERE type='ponuda' AND status='poslato') AS na_cekanju,
                    (SELECT COUNT(*) FROM documents WHERE type='ponuda' AND status='prihvaceno') AS aktivni_ugovori,
                    (SELECT COALESCE(SUM(total),0) FROM documents WHERE type='ponuda' AND status IN ('prihvaceno','zavrseno') AND godina=$g) AS ugovoreno,
                    (SELECT COALESCE(SUM(p.iznos),0) FROM payments p JOIN documents d ON d.id=p.document_id WHERE d.godina=$g) AS naplaceno"
            )->fetch();
            json_out($row);
        }

        default:
            json_out(['error' => 'Nepoznata akcija'], 404);
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    json_out(['error' => 'Greska baze: ' . $e->getMessage()], 500);
}
