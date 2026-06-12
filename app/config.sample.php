<?php
// ============================================
// KONFIGURACIJA - kopiraj ovaj fajl kao config.php
// i unesi svoje podatke iz cPanel-a (MySQL Databases)
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'cpanel_korisnik_ponude');   // ime baze iz cPanel-a
define('DB_USER', 'cpanel_korisnik_dbuser');   // MySQL korisnik iz cPanel-a
define('DB_PASS', 'lozinka-baze');             // lozinka MySQL korisnika

// Puna adresa aplikacije (bez / na kraju) - koristi se za javne linkove ponuda
define('APP_URL', 'https://ponude.aggroup.rs');
