<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_in(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function require_login(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        json_out(['error' => 'Niste prijavljeni'], 401);
    }
}

function get_setting(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : $default;
}

function get_all_settings(): array {
    $rows = db()->query('SELECT skey, svalue FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['skey']] = $r['svalue'];
    return $out;
}

// Oznaka dokumenta po tipu: PON-2026-001, PRE-, AVR-, FAK-
function doc_prefix(string $type): string {
    return ['ponuda'=>'PON','predracun'=>'PRE','avansni'=>'AVR','faktura'=>'FAK'][$type] ?? 'DOC';
}

function next_doc_number(string $type, int $godina): array {
    $st = db()->prepare('SELECT COALESCE(MAX(broj),0)+1 FROM documents WHERE type=? AND godina=?');
    $st->execute([$type, $godina]);
    $broj = (int)$st->fetchColumn();
    $oznaka = doc_prefix($type) . '-' . $godina . '-' . str_pad($broj, 3, '0', STR_PAD_LEFT);
    return [$broj, $oznaka];
}
