<?php
// ============================================
// JAVNA STRANICA PONUDE - klijent otvara link
// ponude.aggroup.rs/p.php?t=TOKEN
// ============================================
require_once __DIR__ . '/lib/db.php';

$tok = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$doc = null;
if ($tok) {
    $st = db()->prepare('SELECT d.*, c.naziv AS k_naziv, c.tip AS k_tip, c.telefon AS k_tel, c.adresa AS k_adresa,
                                c.mesto AS k_mesto, c.pib AS k_pib, c.mb AS k_mb
                         FROM documents d LEFT JOIN clients c ON c.id = d.client_id WHERE d.share_token = ?');
    $st->execute([$tok]);
    $doc = $st->fetch();
}
$S = get_all_settings();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tipNaziv = ['ponuda'=>'PONUDA','predracun'=>'PREDRAČUN','avansni'=>'AVANSNI RAČUN','faktura'=>'KONAČNI RAČUN'];
?>
<!DOCTYPE html>
<html lang="sr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $h($S['firma_naziv'] ?? 'Ponuda') ?><?= $doc ? ' — ' . $h($doc['oznaka']) : '' ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; }
    .top { background:linear-gradient(135deg,#16213e,#1a1a2e); padding:20px; text-align:center; border-bottom:3px solid #d4a574; }
    .top h1 { color:#d4a574; font-size:20px; letter-spacing:2px; }
    .top p { color:#aab; font-size:12px; margin-top:4px; }
    .wrap { max-width:640px; margin:0 auto; padding:16px; }
    .doc { background:#fff; color:#333; border-radius:14px; padding:24px 18px; font-size:14px; line-height:1.7; }
    .doc h2 { color:#1a1a2e; font-size:17px; text-align:center; }
    .doc h3 { color:#b8895c; font-size:14px; text-align:center; margin-bottom:16px; }
    .sh { color:#1a1a2e; font-weight:700; font-size:15px; margin:16px 0 8px; padding-bottom:4px; border-bottom:2px solid #d4a574; }
    table { width:100%; border-collapse:collapse; margin:8px 0; font-size:13px; }
    th { background:#1a1a2e; color:#d4a574; padding:8px 6px; text-align:left; font-size:12px; }
    td { padding:8px 6px; border-bottom:1px solid #eee; }
    .tot { text-align:right; font-size:18px; font-weight:800; color:#1a1a2e; margin-top:16px; padding-top:12px; border-top:2px solid #d4a574; }
    .note { font-size:12px; color:#666; background:#f8f5f0; padding:8px 10px; border-radius:6px; margin:8px 0; }
    .foot { font-size:12px; color:#888; margin-top:16px; text-align:center; }
    .btn { display:block; width:100%; padding:16px; margin-top:14px; border:none; border-radius:10px; font-size:16px; font-weight:700;
           cursor:pointer; background:linear-gradient(135deg,#d4a574,#b8895c); color:#1a1a2e; text-align:center; text-decoration:none; }
    .err { text-align:center; padding:80px 20px; color:#aab; font-size:16px; }
    .right { text-align:right; }
</style>
</head>
<body>
<div class="top">
    <h1><?= $h($S['firma_naziv'] ?? '') ?></h1>
    <p><?= $h($S['firma_podnaslov'] ?? '') ?></p>
</div>
<div class="wrap">
<?php if (!$doc): ?>
    <div class="err">😕<br><br>Ponuda nije pronađena ili je link istekao.<br>Kontaktirajte nas: <?= $h($S['kontakt_tel'] ?? '') ?></div>
<?php else:
    $items = json_decode($doc['items'] ?? '[]', true) ?: [];
    $kl = $doc['client_snapshot'] ? json_decode($doc['client_snapshot'], true) : null;
    $kNaziv = $kl['naziv'] ?? $doc['k_naziv'] ?? '—';
    $val = $doc['valuta'];
    $d = new DateTime($doc['datum']);
?>
    <div class="doc" id="doc">
        <h2><?= $h($S['firma_naziv']) ?></h2>
        <h3><?= $tipNaziv[$doc['type']] ?? 'PONUDA' ?> br. <?= $h($doc['oznaka']) ?></h3>
        <div style="font-size:13px;margin-bottom:12px;">
            <strong>Datum:</strong> <?= $d->format('d.m.Y.') ?><br>
            <strong>Klijent:</strong> <?= $h($kNaziv) ?><br>
            <?php if (!empty($doc['k_mesto'])): ?><strong>Mesto:</strong> <?= $h($doc['k_mesto']) ?><br><?php endif; ?>
        </div>
        <div class="sh">SPECIFIKACIJA</div>
        <table>
            <tr><th>#</th><th>Opis</th><th class="right">Iznos</th></tr>
            <?php foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= $h($it['naziv'] ?? '') ?><?php if (!empty($it['opis'])): ?><br><small style="color:#888"><?= $h($it['opis']) ?></small><?php endif; ?></td>
                <td class="right" style="font-weight:700;<?= (float)($it['iznos'] ?? 0) < 0 ? 'color:#e74c3c;' : '' ?>"><?= number_format((float)($it['iznos'] ?? 0), 2, ',', '.') ?> <?= $val ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div style="margin-top:12px;padding-top:10px;border-top:1px solid #ddd;">
            <div class="right" style="font-size:14px;">Međuzbir: <?= number_format((float)$doc['subtotal'], 2, ',', '.') ?> <?= $val ?></div>
            <?php if ((float)$doc['disc_amount'] > 0): ?>
            <div class="right" style="color:#e74c3c;font-size:14px;">Popust: -<?= number_format((float)$doc['disc_amount'], 2, ',', '.') ?> <?= $val ?></div>
            <?php endif; ?>
            <?php if (($doc['pdv_mode'] ?? 'none') === 'standard' && (float)$doc['pdv_amount'] > 0): ?>
            <div class="right" style="font-size:14px;">Osnovica: <?= number_format((float)$doc['total'] - (float)$doc['pdv_amount'], 2, ',', '.') ?> <?= $val ?></div>
            <div class="right" style="font-size:14px;">PDV (<?= rtrim(rtrim(number_format((float)$doc['pdv_rate'],2,',','.'),'0'),',') ?>%): <?= number_format((float)$doc['pdv_amount'], 2, ',', '.') ?> <?= $val ?></div>
            <?php endif; ?>
        </div>
        <?php
            $items_have_avans = false;
            foreach ($items as $it) { if (($it['kind'] ?? '') === 'avans_minus') { $items_have_avans = true; break; } }
            $totLabel = ($doc['type'] === 'faktura' && $items_have_avans) ? 'ZA UPLATU' : 'UKUPNO';
        ?>
        <div class="tot"><?= $totLabel ?>: <?= number_format((float)$doc['total'], 2, ',', '.') ?> <?= $val ?></div>
        <?php if (($doc['pdv_mode'] ?? '') === 'cl10' && !empty($doc['pdv_napomena'])): ?>
        <div class="note" style="border:1px solid #e0b050;background:#fdf6e8;">⚖ <?= $h($doc['pdv_napomena']) ?></div>
        <?php endif; ?>
        <?php if ($doc['rok']): ?><div style="margin-top:12px;font-size:13px;"><strong>Rok realizacije:</strong> <?= $h($doc['rok']) ?></div><?php endif; ?>
        <?php if ($doc['napomena']): ?><div style="margin-top:8px;font-size:13px;"><strong>Napomena:</strong> <?= nl2br($h($doc['napomena'])) ?></div><?php endif; ?>
        <?php if ($doc['type'] !== 'ponuda' && !empty($S['firma_ziro'])): ?>
        <div class="note"><strong>Podaci za uplatu:</strong><br>
            Tekući račun: <?= $h($S['firma_ziro']) ?><?= !empty($S['firma_banka']) ? ' (' . $h($S['firma_banka']) . ')' : '' ?><br>
            Poziv na broj: <?= $h($doc['oznaka']) ?>
        </div>
        <?php endif; ?>
        <?php if (($doc['pdv_mode'] ?? 'none') === 'none' && !empty($doc['pdv_napomena']) && $doc['type'] !== 'ponuda'): ?>
        <div class="note"><?= $h($doc['pdv_napomena']) ?></div>
        <?php endif; ?>
        <div class="foot">
            <?= $h($S['firma_naziv']) ?> | Tel: <?= $h($S['kontakt_tel']) ?> - <?= $h($S['kontakt_ime']) ?><br>
            <?php if ($doc['type'] === 'ponuda'): ?>Ponuda važi <?= $h($S['ponuda_vazi_dana'] ?? '7') ?> dana od datuma izdavanja.<?php endif; ?>
        </div>
    </div>
    <a class="btn" href="tel:<?= $h($S['kontakt_tel']) ?>">📞 Pozovite nas: <?= $h($S['kontakt_tel']) ?></a>
    <button class="btn" onclick="window.print()" style="background:transparent;border:2px solid #d4a574;color:#d4a574;">🖨 Štampaj / Sačuvaj PDF</button>
<?php endif; ?>
</div>
</body>
</html>
