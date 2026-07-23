<?php
require_once __DIR__ . '/lib/db.php';
session_start();
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
$setupNeeded = ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prijava — Generator ponuda</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#1a1a2e; color:#eee;
           min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
    .box { background:#16213e; border:1px solid rgba(212,165,116,0.3); border-radius:16px; padding:32px 24px; width:100%; max-width:380px; }
    h1 { color:#d4a574; font-size:20px; text-align:center; letter-spacing:1px; }
    p.sub { color:#aab; font-size:13px; text-align:center; margin:6px 0 24px; }
    label { display:block; font-size:13px; color:#aab; margin:14px 0 6px; }
    input { width:100%; padding:13px 14px; background:#0f3460; border:1px solid rgba(212,165,116,0.25); border-radius:8px;
            color:#eee; font-size:16px; }
    input:focus { outline:none; border-color:#d4a574; }
    button { width:100%; padding:15px; margin-top:22px; border:none; border-radius:10px; font-size:16px; font-weight:700;
             cursor:pointer; background:linear-gradient(135deg,#d4a574,#b8895c); color:#1a1a2e; }
    .err { background:rgba(231,76,60,0.15); border:1px solid rgba(231,76,60,0.4); color:#e74c3c; padding:10px;
           border-radius:8px; font-size:13px; margin-top:16px; display:none; text-align:center; }
</style>
</head>
<body>
<div class="box">
    <h1>PROHORECA AG GROUP</h1>
    <?php if ($setupNeeded): ?>
    <p class="sub">Prvo pokretanje — postavi lozinku</p>
    <form id="f">
        <label>Ime i prezime</label>
        <input type="text" id="name" autocomplete="name">
        <label>Lozinka (min 6 karaktera)</label>
        <input type="password" id="password" required autocomplete="new-password">
        <button type="submit">Napravi nalog i uđi</button>
        <div class="err" id="err"></div>
    </form>
    <script>
        document.getElementById('f').addEventListener('submit', async e => {
            e.preventDefault();
            const r = await fetch('api.php?action=setup', { method:'POST', body: JSON.stringify({
                name: document.getElementById('name').value,
                password: document.getElementById('password').value
            })});
            const d = await r.json();
            if (d.ok) location.href = 'index.php';
            else { const el = document.getElementById('err'); el.textContent = d.error; el.style.display = 'block'; }
        });
    </script>
    <?php else: ?>
    <p class="sub">Generator ponuda — prijava</p>
    <form id="f">
        <label>Lozinka</label>
        <input type="password" id="password" required autocomplete="current-password" autofocus>
        <button type="submit">Prijavi se</button>
        <div class="err" id="err"></div>
    </form>
    <script>
        document.getElementById('f').addEventListener('submit', async e => {
            e.preventDefault();
            const r = await fetch('api.php?action=login', { method:'POST', body: JSON.stringify({
                password: document.getElementById('password').value
            })});
            const d = await r.json();
            if (d.ok) location.href = 'index.php';
            else { const el = document.getElementById('err'); el.textContent = d.error; el.style.display = 'block'; }
        });
    </script>
    <?php endif; ?>
</div>
</body>
</html>
