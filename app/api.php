<?php
// ============================================
// API v2 - poslovi kao centralna stvar
// Svi pozivi: api.php?action=...
// ============================================
require_once __DIR__ . '/lib/db.php';
session_start();

$action = $_GET['action'] ?? '';

// ---------- pomocne ----------

function fetch_client(?int $id): ?array {
    if (!$id) return null;
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function fetch_job(int $id): ?array {
    $st = db()->prepare(
        'SELECT j.*, c.naziv AS klijent_naziv, c.tip AS klijent_tip, c.pdv_mode AS klijent_pdv_mode,
                c.telefon AS klijent_tel, c.email AS klijent_email, c.adresa AS klijent_adresa,
                c.mesto AS klijent_mesto, c.pib AS klijent_pib, c.mb AS klijent_mb
         FROM jobs j LEFT JOIN clients c ON c.id = j.client_id WHERE j.id = ?');
    $st->execute([$id]);
    $j = $st->fetch();
    if (!$j) return null;
    $j['items'] = json_decode($j['items'] ?? '[]', true) ?: [];
    $j['calc_state'] = json_decode($j['calc_state'] ?? 'null', true);
    return $j;
}

// Ukupno uplaceno po poslu, u EUR
function job_paid_eur(int $jobId, float $fallbackKurs): float {
    $st = db()->prepare('SELECT iznos, valuta, kurs FROM payments WHERE job_id = ?');
    $st->execute([$jobId]);
    $sum = 0.0;
    foreach ($st->fetchAll() as $p) {
        $sum += to_eur((float)$p['iznos'], $p['valuta'], (float)$p['kurs'], $fallbackKurs);
    }
    return round($sum, 2);
}

// Izdaje dokument kao snimak posla. Vraca [id, oznaka].
// $opts: valuta, kurs, payment (red iz payments za avansni), datum
function issue_document(array $job, string $type, array $opts = []): array {
    $S = get_all_settings();
    $client = fetch_client($job['client_id'] ? (int)$job['client_id'] : null);
    [$pdvMode, $pdvRate] = pdv_treatment($client, $S);

    $valuta = ($opts['valuta'] ?? 'EUR') === 'RSD' ? 'RSD' : 'EUR';
    $kurs   = $valuta === 'RSD' ? max(1.0, (float)($opts['kurs'] ?? 0) ?: (float)($S['kurs_eur'] ?? 117.2)) : 1.0;
    $datum  = $opts['datum'] ?? date('Y-m-d');
    $godina = (int)substr($datum, 0, 4);
    $k = fn(float $x) => round($x * $kurs, 2);

    $items = is_array($job['items']) ? $job['items'] : (json_decode($job['items'] ?? '[]', true) ?: []);
    $avansPct = 0.0;

    if ($type === 'avansni') {
        $pay = $opts['payment'];
        // iznos avansnog = iznos uplate, u valuti dokumenta
        $payEur = to_eur((float)$pay['iznos'], $pay['valuta'], (float)$pay['kurs'], (float)($S['kurs_eur'] ?? 117.2));
        $total = round($payEur * $kurs, 2);
        $jobTotalEur = (float)$job['total'];
        $avansPct = $jobTotalEur > 0 ? round($payEur / $jobTotalEur * 100, 1) : 0;
        $items = [[
            'kind'  => 'avans',
            'naziv' => 'Avans po ponudi za: ' . ($job['naziv'] ?: 'ugovoreni posao'),
            'opis'  => 'Uplata od ' . date('d.m.Y.', strtotime($pay['datum']))
                     . ' — ukupna vrednost posla: ' . number_format($k($jobTotalEur), 2, ',', '.') . ' ' . $valuta,
            'iznos' => $total,
        ]];
        $subtotal = $total; $discAmount = 0.0; $discVal = 0.0; $discType = 'percent';
    } elseif ($type === 'faktura') {
        // konacni racun: puna specifikacija - izdati avansni racuni = razlika za uplatu
        $items = array_map(fn($it) => array_merge($it, ['iznos' => $k((float)$it['iznos'])]), $items);
        $subtotal = $k((float)$job['subtotal']);
        $discAmount = $k((float)$job['disc_amount']);
        $discVal = (float)$job['disc_val']; $discType = $job['disc_type'];
        $total = $k((float)$job['total']);
        $st = db()->prepare("SELECT oznaka, total, valuta, kurs FROM documents
                             WHERE job_id = ? AND type = 'avansni' AND status <> 'storniran'");
        $st->execute([(int)$job['id']]);
        foreach ($st->fetchAll() as $av) {
            $avEur = to_eur((float)$av['total'], $av['valuta'], (float)$av['kurs'], (float)($S['kurs_eur'] ?? 117.2));
            $avDoc = round($avEur * $kurs, 2);
            $items[] = [
                'kind'  => 'avans_minus',
                'naziv' => 'Umanjenje — avansni račun ' . $av['oznaka'],
                'opis'  => '',
                'iznos' => -$avDoc,
            ];
            $total = round($total - $avDoc, 2);
        }
        if ($total < 0) $total = 0.0;
    } else {
        // ponuda / predracun: puna specifikacija
        $items = array_map(fn($it) => array_merge($it, ['iznos' => $k((float)$it['iznos'])]), $items);
        $subtotal = $k((float)$job['subtotal']);
        $discAmount = $k((float)$job['disc_amount']);
        $discVal = (float)$job['disc_val']; $discType = $job['disc_type'];
        $total = $k((float)$job['total']);
    }

    $pdvAmount = $pdvMode === 'standard' ? pdv_included($total, $pdvRate) : 0.0;
    $pdvNapomena = '';
    if ($pdvMode === 'cl10') $pdvNapomena = $S['pdv_cl10_napomena'] ?? '';
    elseif ($pdvMode === 'none' && ($S['pdv_enabled'] ?? '0') !== '1') $pdvNapomena = $S['pdv_napomena'] ?? '';

    $snapshot = $client ? json_encode([
        'naziv' => $client['naziv'], 'tip' => $client['tip'], 'telefon' => $client['telefon'],
        'adresa' => $client['adresa'], 'mesto' => $client['mesto'], 'pib' => $client['pib'], 'mb' => $client['mb'],
    ], JSON_UNESCAPED_UNICODE) : null;

    db()->beginTransaction();
    [$broj, $oznaka] = next_doc_number($type, $godina);
    db()->prepare('INSERT INTO documents (job_id, type, godina, broj, oznaka, client_id, client_snapshot, datum,
                   valuta, kurs, items, subtotal, disc_type, disc_val, disc_amount,
                   pdv_mode, pdv_rate, pdv_amount, pdv_napomena, total, avans_procenat, rok, napomena, status)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([(int)$job['id'], $type, $godina, $broj, $oznaka,
                  $job['client_id'] ?: null, $snapshot, $datum,
                  $valuta, $kurs, json_encode($items, JSON_UNESCAPED_UNICODE),
                  $subtotal, $discType, $discVal, $discAmount,
                  $pdvMode, $pdvMode === 'standard' ? $pdvRate : 0, $pdvAmount, $pdvNapomena,
                  $total, $avansPct, $job['rok'] ?? '', '', 'izdat']);
    $id = (int)db()->lastInsertId();
    db()->commit();
    return [$id, $oznaka];
}

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
            $p = $d['password'] ?? '';
            if (strlen($p) < 6) json_out(['error' => 'Lozinka mora imati min. 6 karaktera'], 400);
            $st = db()->prepare('INSERT INTO users (username, pass_hash, name) VALUES (?,?,?)');
            $st->execute(['admin', password_hash($p, PASSWORD_DEFAULT), trim($d['name'] ?? '')]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            json_out(['ok' => true]);
        }

        case 'login': {
            $d = json_in();
            $user = db()->query('SELECT * FROM users LIMIT 1')->fetch();
            if (!$user || !password_verify($d['password'] ?? '', $user['pass_hash'])) {
                json_out(['error' => 'Pogrešna lozinka'], 401);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            json_out(['ok' => true, 'name' => $user['name']]);
        }

        case 'logout': {
            session_destroy();
            json_out(['ok' => true]);
        }

        case 'password_change': {
            require_login();
            $d = json_in();
            $new = $d['new_pass'] ?? '';
            if (strlen($new) < 6) json_out(['error' => 'Nova lozinka mora imati min. 6 karaktera'], 400);
            $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
            $st->execute([$_SESSION['user_id']]);
            if (!password_verify($d['current_pass'] ?? '', $st->fetchColumn())) {
                json_out(['error' => 'Trenutna lozinka nije ispravna'], 401);
            }
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
               ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
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
                        'pdv_enabled','pdv_rate','pdv_napomena','pdv_cl10_napomena','kurs_eur','ponuda_vazi_dana',
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
                $rows = $st->fetchAll();
            } else {
                $rows = db()->query('SELECT * FROM clients ORDER BY naziv LIMIT 500')->fetchAll();
            }
            // dug po klijentu (ugovoreni/aktivni/zavrseni poslovi)
            $S = get_all_settings();
            $fallback = (float)($S['kurs_eur'] ?? 117.2);
            $jobs = db()->query("SELECT id, client_id, total FROM jobs WHERE status IN ('ugovoreno','realizacija','zavrseno')")->fetchAll();
            $pays = db()->query('SELECT job_id, iznos, valuta, kurs FROM payments')->fetchAll();
            $paid = [];
            foreach ($pays as $p) {
                if (!$p['job_id']) continue;
                $paid[$p['job_id']] = ($paid[$p['job_id']] ?? 0) + to_eur((float)$p['iznos'], $p['valuta'], (float)$p['kurs'], $fallback);
            }
            $dug = []; $brojPoslova = [];
            foreach ($jobs as $j) {
                if (!$j['client_id']) continue;
                $cid = $j['client_id'];
                $brojPoslova[$cid] = ($brojPoslova[$cid] ?? 0) + 1;
                $dug[$cid] = ($dug[$cid] ?? 0) + max(0, (float)$j['total'] - ($paid[$j['id']] ?? 0));
            }
            foreach ($rows as &$r) {
                $r['dug_eur'] = round($dug[$r['id']] ?? 0, 2);
                $r['broj_poslova'] = $brojPoslova[$r['id']] ?? 0;
            }
            json_out($rows);
        }

        case 'client_save': {
            require_login();
            $d = json_in();
            $naziv = trim($d['naziv'] ?? '');
            if ($naziv === '') json_out(['error' => 'Naziv / ime je obavezno'], 400);
            $fields = [
                $d['tip'] === 'pravno' ? 'pravno' : 'fizicko',
                ($d['pdv_mode'] ?? '') === 'cl10' ? 'cl10' : 'standard',
                $naziv,
                trim($d['telefon'] ?? ''), trim($d['email'] ?? ''),
                trim($d['adresa'] ?? ''), trim($d['mesto'] ?? ''),
                trim($d['pib'] ?? ''), trim($d['mb'] ?? ''),
                trim($d['napomena'] ?? ''),
            ];
            if (!empty($d['id'])) {
                $fields[] = (int)$d['id'];
                db()->prepare('UPDATE clients SET tip=?, pdv_mode=?, naziv=?, telefon=?, email=?, adresa=?, mesto=?, pib=?, mb=?, napomena=? WHERE id=?')
                   ->execute($fields);
                json_out(['ok' => true, 'id' => (int)$d['id']]);
            }
            db()->prepare('INSERT INTO clients (tip, pdv_mode, naziv, telefon, email, adresa, mesto, pib, mb, napomena) VALUES (?,?,?,?,?,?,?,?,?,?)')
               ->execute($fields);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'client_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT COUNT(*) FROM jobs WHERE client_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                json_out(['error' => 'Klijent ima poslove — ne može se obrisati'], 400);
            }
            db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        // ---------- POSLOVI ----------
        case 'jobs_list': {
            require_login();
            $S = get_all_settings();
            $fallback = (float)($S['kurs_eur'] ?? 117.2);
            $rows = db()->query(
                "SELECT j.id, j.naziv, j.status, j.datum, j.total, j.rok, j.client_id,
                        c.naziv AS klijent, c.tip AS klijent_tip, c.pdv_mode AS klijent_pdv_mode, c.mesto AS klijent_mesto
                 FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
                 ORDER BY FIELD(j.status,'realizacija','ugovoreno','ponuda','zavrseno','odbijeno'), j.id DESC LIMIT 1000"
            )->fetchAll();
            $pays = db()->query('SELECT job_id, iznos, valuta, kurs FROM payments')->fetchAll();
            $paid = [];
            foreach ($pays as $p) {
                if (!$p['job_id']) continue;
                $paid[$p['job_id']] = ($paid[$p['job_id']] ?? 0) + to_eur((float)$p['iznos'], $p['valuta'], (float)$p['kurs'], $fallback);
            }
            $docs = db()->query("SELECT job_id, oznaka FROM documents WHERE job_id IS NOT NULL")->fetchAll();
            $oznake = [];
            foreach ($docs as $dd) $oznake[$dd['job_id']] = trim(($oznake[$dd['job_id']] ?? '') . ' ' . $dd['oznaka']);
            foreach ($rows as &$r) {
                $r['uplaceno'] = round($paid[$r['id']] ?? 0, 2);
                $r['oznake'] = $oznake[$r['id']] ?? '';
            }
            json_out($rows);
        }

        case 'job_get': {
            require_login();
            $job = fetch_job((int)($_GET['id'] ?? 0));
            if (!$job) json_out(['error' => 'Posao nije pronađen'], 404);
            $st = db()->prepare('SELECT id, type, oznaka, datum, valuta, kurs, total, status, pdv_mode
                                 FROM documents WHERE job_id = ? ORDER BY id');
            $st->execute([$job['id']]);
            $job['documents'] = $st->fetchAll();
            $st = db()->prepare('SELECT p.*, d.oznaka AS avansni_oznaka
                                 FROM payments p LEFT JOIN documents d ON d.id = p.avansni_doc_id
                                 WHERE p.job_id = ? ORDER BY p.datum, p.id');
            $st->execute([$job['id']]);
            $job['payments'] = $st->fetchAll();
            $S = get_all_settings();
            $job['uplaceno_eur'] = job_paid_eur((int)$job['id'], (float)($S['kurs_eur'] ?? 117.2));
            $client = fetch_client($job['client_id'] ? (int)$job['client_id'] : null);
            [$job['pdv_mode'], $job['pdv_rate']] = pdv_treatment($client, $S);
            json_out($job);
        }

        case 'job_save': {
            require_login();
            $d = json_in();
            $datum = $d['datum'] ?: date('Y-m-d');
            $items = json_encode($d['items'] ?? [], JSON_UNESCAPED_UNICODE);
            $calc  = isset($d['calc_state']) ? json_encode($d['calc_state'], JSON_UNESCAPED_UNICODE) : null;
            $clientId = !empty($d['client_id']) ? (int)$d['client_id'] : null;
            $vals = [$clientId, trim($d['naziv'] ?? ''), $datum, $items, $calc,
                     (float)$d['subtotal'], $d['disc_type'] ?? 'percent', (float)($d['disc_val'] ?? 0),
                     (float)($d['disc_amount'] ?? 0), (float)$d['total'],
                     trim($d['rok'] ?? ''), trim($d['napomena'] ?? '')];

            if (!empty($d['id'])) {
                $vals[] = (int)$d['id'];
                db()->prepare('UPDATE jobs SET client_id=?, naziv=?, datum=?, items=?, calc_state=?,
                               subtotal=?, disc_type=?, disc_val=?, disc_amount=?, total=?, rok=?, napomena=? WHERE id=?')
                   ->execute($vals);
                $jobId = (int)$d['id'];
                // osvezi snimak ponude (zadrzava broj/oznaku)
                $client = fetch_client($clientId);
                $S = get_all_settings();
                [$pdvMode, $pdvRate] = pdv_treatment($client, $S);
                $pdvAmount = $pdvMode === 'standard' ? pdv_included((float)$d['total'], $pdvRate) : 0.0;
                $pdvNapomena = $pdvMode === 'cl10' ? ($S['pdv_cl10_napomena'] ?? '')
                             : ((($S['pdv_enabled'] ?? '0') !== '1') ? ($S['pdv_napomena'] ?? '') : '');
                $st = db()->prepare("SELECT id, oznaka FROM documents WHERE job_id = ? AND type = 'ponuda' ORDER BY id LIMIT 1");
                $st->execute([$jobId]);
                $pon = $st->fetch();
                if ($pon) {
                    db()->prepare('UPDATE documents SET client_id=?, datum=?, items=?, subtotal=?, disc_type=?,
                                   disc_val=?, disc_amount=?, pdv_mode=?, pdv_rate=?, pdv_amount=?, pdv_napomena=?,
                                   total=?, rok=? WHERE id=?')
                       ->execute([$clientId, $datum, $items, (float)$d['subtotal'], $d['disc_type'] ?? 'percent',
                                  (float)($d['disc_val'] ?? 0), (float)($d['disc_amount'] ?? 0),
                                  $pdvMode, $pdvMode === 'standard' ? $pdvRate : 0, $pdvAmount, $pdvNapomena,
                                  (float)$d['total'], trim($d['rok'] ?? ''), (int)$pon['id']]);
                    json_out(['ok' => true, 'id' => $jobId, 'oznaka' => $pon['oznaka']]);
                }
                json_out(['ok' => true, 'id' => $jobId, 'oznaka' => '']);
            }

            db()->prepare("INSERT INTO jobs (client_id, naziv, datum, items, calc_state,
                           subtotal, disc_type, disc_val, disc_amount, total, rok, napomena, status)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'ponuda')")
               ->execute($vals);
            $jobId = (int)db()->lastInsertId();
            // svaki posao odmah dobija svoju PONUDU kao dokument
            $job = fetch_job($jobId);
            [$docId, $oznaka] = issue_document($job, 'ponuda', ['datum' => $datum]);
            json_out(['ok' => true, 'id' => $jobId, 'oznaka' => $oznaka, 'doc_id' => $docId]);
        }

        case 'job_status': {
            require_login();
            $d = json_in();
            $valid = ['ponuda','ugovoreno','realizacija','zavrseno','odbijeno'];
            if (!in_array($d['status'] ?? '', $valid)) json_out(['error' => 'Nepoznat status'], 400);
            db()->prepare('UPDATE jobs SET status = ? WHERE id = ?')->execute([$d['status'], (int)$d['id']]);
            json_out(['ok' => true]);
        }

        case 'job_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            db()->prepare('DELETE FROM payments WHERE job_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM documents WHERE job_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        // ---------- DOKUMENTI ----------
        case 'doc_issue': {
            require_login();
            $d = json_in();
            $type = $d['type'] ?? '';
            if (!in_array($type, ['predracun','avansni','faktura'])) json_out(['error' => 'Nepoznat tip dokumenta'], 400);
            $job = fetch_job((int)($d['job_id'] ?? 0));
            if (!$job) json_out(['error' => 'Posao nije pronađen'], 404);

            $opts = ['valuta' => $d['valuta'] ?? 'EUR', 'kurs' => (float)($d['kurs'] ?? 0)];
            if ($type === 'avansni') {
                $st = db()->prepare('SELECT * FROM payments WHERE id = ? AND job_id = ?');
                $st->execute([(int)($d['payment_id'] ?? 0), (int)$job['id']]);
                $pay = $st->fetch();
                if (!$pay) json_out(['error' => 'Uplata nije pronađena'], 404);
                if ($pay['avansni_doc_id']) json_out(['error' => 'Za ovu uplatu je već izdat avansni račun'], 400);
                $opts['payment'] = $pay;
                // avansni u valuti uplate ako nije receno drugacije
                if (empty($d['valuta'])) $opts['valuta'] = $pay['valuta'];
                if ($opts['valuta'] === 'RSD' && !$opts['kurs']) $opts['kurs'] = (float)$pay['kurs'];
            }
            [$docId, $oznaka] = issue_document($job, $type, $opts);
            if ($type === 'avansni') {
                db()->prepare('UPDATE payments SET avansni_doc_id = ? WHERE id = ?')
                   ->execute([$docId, (int)$d['payment_id']]);
            }
            json_out(['ok' => true, 'id' => $docId, 'oznaka' => $oznaka]);
        }

        case 'doc_get': {
            require_login();
            $st = db()->prepare('SELECT d.*, c.naziv AS klijent_naziv, c.tip AS klijent_tip, c.telefon AS klijent_tel,
                                        c.adresa AS klijent_adresa, c.mesto AS klijent_mesto, c.pib AS klijent_pib,
                                        c.mb AS klijent_mb, j.naziv AS job_naziv
                                 FROM documents d
                                 LEFT JOIN clients c ON c.id = d.client_id
                                 LEFT JOIN jobs j ON j.id = d.job_id
                                 WHERE d.id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $doc = $st->fetch();
            if (!$doc) json_out(['error' => 'Dokument nije pronađen'], 404);
            $doc['items'] = json_decode($doc['items'] ?? '[]', true) ?: [];
            json_out($doc);
        }

        case 'doc_storno': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT status FROM documents WHERE id = ?');
            $st->execute([$id]);
            $cur = $st->fetchColumn();
            if ($cur === false) json_out(['error' => 'Dokument nije pronađen'], 404);
            $new = $cur === 'storniran' ? 'izdat' : 'storniran';
            db()->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([$new, $id]);
            json_out(['ok' => true, 'status' => $new]);
        }

        case 'doc_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT type FROM documents WHERE id = ?');
            $st->execute([$id]);
            $type = $st->fetchColumn();
            if ($type === false) json_out(['error' => 'Dokument nije pronađen'], 404);
            if ($type === 'ponuda') {
                json_out(['error' => 'Ponuda se briše brisanjem celog posla'], 400);
            }
            db()->prepare('UPDATE payments SET avansni_doc_id = NULL WHERE avansni_doc_id = ?')->execute([$id]);
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

        // ---------- UPLATE ----------
        case 'payment_save': {
            require_login();
            $d = json_in();
            $iznos = (float)($d['iznos'] ?? 0);
            if ($iznos <= 0) json_out(['error' => 'Iznos mora biti veći od 0'], 400);
            $jobId = (int)($d['job_id'] ?? 0);
            if (!$jobId) json_out(['error' => 'Nedostaje posao'], 400);
            $valuta = ($d['valuta'] ?? 'EUR') === 'RSD' ? 'RSD' : 'EUR';
            $kurs = $valuta === 'RSD'
                  ? max(1.0, (float)($d['kurs'] ?? 0) ?: (float)(get_setting('kurs_eur', '117.2')))
                  : 1.0;
            db()->prepare('INSERT INTO payments (job_id, datum, iznos, valuta, kurs, nacin, napomena) VALUES (?,?,?,?,?,?,?)')
               ->execute([$jobId, $d['datum'] ?: date('Y-m-d'), $iznos, $valuta, $kurs,
                          trim($d['nacin'] ?? 'gotovina'), trim($d['napomena'] ?? '')]);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'payment_delete': {
            require_login();
            $id = (int)(json_in()['id'] ?? 0);
            $st = db()->prepare('SELECT avansni_doc_id FROM payments WHERE id = ?');
            $st->execute([$id]);
            $av = $st->fetchColumn();
            if ($av) json_out(['error' => 'Prvo obriši ili storniraj avansni račun izdat na ovu uplatu'], 400);
            db()->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        default:
            json_out(['error' => 'Nepoznata akcija'], 404);
    }
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    json_out(['error' => 'Greska baze: ' . $e->getMessage()], 500);
}
