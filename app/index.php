<?php
require_once __DIR__ . '/lib/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Poslovi — PROHORECA AG GROUP</title>
    <meta name="theme-color" content="#d4a574">
    <link rel="stylesheet" href="assets/style.css?v=2">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>

<div class="header">
    <div>
        <h1>PROHORECA AG GROUP</h1>
        <p>Poslovi · Ponude · Računi · Potraživanja</p>
    </div>
    <button class="logout" onclick="logout()">Odjava</button>
</div>

<!-- ===== POSLOVI (glavna lista) ===== -->
<div id="tab-poslovi" class="section active">
    <input type="text" class="search-input" id="jobs-search" placeholder="🔍 Ime, mesto ili broj dokumenta…" oninput="renderJobs()">
    <div class="stat-grid-3" id="jobs-stats"></div>
    <div class="filter-row" id="jobs-filter">
        <button class="filter-btn active" data-f="svi" onclick="setJobsFilter(this)">Svi</button>
        <button class="filter-btn" data-f="ponude" onclick="setJobsFilter(this)">Ponude</button>
        <button class="filter-btn" data-f="utoku" onclick="setJobsFilter(this)">U toku</button>
        <button class="filter-btn" data-f="duguju" onclick="setJobsFilter(this)">Duguju nam</button>
        <button class="filter-btn" data-f="zavrseni" onclick="setJobsFilter(this)">Završeni</button>
    </div>
    <div id="jobs-list"></div>
</div>

<!-- ===== DETALJ POSLA ===== -->
<div id="tab-posao" class="section">
    <div class="dethead">
        <button class="back" onclick="switchTab('poslovi')">‹</button>
        <div><div class="t" id="jd-title"></div><div class="s" id="jd-sub"></div></div>
    </div>
    <div id="jd-body"></div>
</div>

<!-- ===== NOVI POSAO (kalkulator) ===== -->
<div id="tab-novi" class="section">
    <div class="dethead">
        <button class="back" onclick="cancelCalculator()">‹</button>
        <div><div class="t" id="nova-title">Novi posao</div><div class="s">Kalkulator pergola i stakla</div></div>
    </div>

    <div class="card">
        <div class="form-group">
            <label>Klijent</label>
            <div class="client-pick">
                <select id="np-client"></select>
                <button class="btn btn-accent btn-sm" onclick="openClientForm(null, true)">+ Novi</button>
            </div>
        </div>
        <div class="form-group">
            <label>Datum</label>
            <input type="date" id="np-datum">
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 10px;">
        <h3 style="color:var(--accent);font-size:16px;">Pergole</h3>
        <span class="badge badge-gold" id="np-pergola-badge">0 pergola</span>
    </div>
    <div id="np-pergole"></div>
    <button class="btn-add" onclick="addPergola()">+ Dodaj pergolu</button>

    <div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 10px;">
        <h3 style="color:var(--accent);font-size:16px;">Stakleni sistemi</h3>
        <span class="badge badge-gold" id="np-glass-badge">0 sistema</span>
    </div>
    <div id="np-stakla"></div>
    <button class="btn-add" onclick="addGlass()">+ Dodaj stakleni sistem</button>

    <div class="card">
        <div class="summary-row"><span class="sum-label">Pergole</span><span class="sum-value"><span id="np-sum-pergole">0.00</span> EUR</span></div>
        <div class="summary-row"><span class="sum-label">Stakleni sistemi</span><span class="sum-value"><span id="np-sum-staklo">0.00</span> EUR</span></div>
        <div class="summary-row"><span class="sum-label">Međuzbir</span><span class="sum-value"><span id="np-sum-subtotal">0.00</span> EUR</span></div>
        <div class="separator"></div>
        <label style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;display:block;">Popust</label>
        <div class="form-row">
            <select id="np-disc-type" onchange="calcSummary()" style="padding:10px;background:var(--bg-input);border:1px solid rgba(212,165,116,0.2);border-radius:8px;color:var(--text-primary);">
                <option value="percent">%</option>
                <option value="fixed">EUR</option>
            </select>
            <input type="number" id="np-disc-val" placeholder="0" min="0" oninput="calcSummary()"
                   style="padding:10px 14px;background:var(--bg-input);border:1px solid rgba(212,165,116,0.2);border-radius:8px;color:var(--text-primary);font-size:16px;">
        </div>
        <div class="summary-row" id="np-disc-row" style="display:none;">
            <span class="sum-label">Popust</span>
            <span class="sum-value" style="color:var(--danger);">-<span id="np-sum-disc">0.00</span> EUR</span>
        </div>
        <div class="summary-row total"><span class="sum-label">UKUPNO</span><span class="sum-value"><span id="np-sum-total">0.00</span> EUR</span></div>
    </div>

    <div class="card">
        <div class="form-group">
            <label>Rok realizacije</label>
            <input type="text" id="np-rok" placeholder="npr. 30 radnih dana">
        </div>
        <div class="form-group">
            <label>Napomena</label>
            <textarea id="np-napomena" placeholder="Dodatne napomene..."></textarea>
        </div>
    </div>

    <button class="btn btn-success" onclick="saveJob()">💾 Sačuvaj posao</button>
    <button class="btn btn-danger" onclick="resetCalculator(true)">Odbaci / Isprazni</button>
</div>

<!-- ===== KLIJENTI ===== -->
<div id="tab-klijenti" class="section">
    <h2 class="section-title">Klijenti</h2>
    <button class="btn btn-accent" onclick="openClientForm(null, false)">+ Novi klijent</button>
    <input type="text" class="search-input" id="clients-search" placeholder="🔍 Pretraži klijente…" oninput="debounceLoadClients()">
    <div id="clients-list"></div>
</div>

<!-- ===== PODESAVANJA ===== -->
<div id="tab-podesavanja" class="section">
    <h2 class="section-title">Podešavanja</h2>
    <div class="card">
        <div class="card-title" style="margin-bottom:12px;">Podaci firme</div>
        <div class="form-group"><label>Naziv firme</label><input type="text" id="s-firma_naziv"></div>
        <div class="form-group"><label>Podnaslov / delatnost</label><input type="text" id="s-firma_podnaslov"></div>
        <div class="form-row">
            <div class="form-group"><label>Adresa</label><input type="text" id="s-firma_adresa"></div>
            <div class="form-group"><label>Mesto</label><input type="text" id="s-firma_mesto"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>PIB</label><input type="text" id="s-firma_pib"></div>
            <div class="form-group"><label>Matični broj</label><input type="text" id="s-firma_mb"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Tekući račun</label><input type="text" id="s-firma_ziro"></div>
            <div class="form-group"><label>Banka</label><input type="text" id="s-firma_banka"></div>
        </div>
    </div>
    <div class="card">
        <div class="card-title" style="margin-bottom:12px;">Kontakt</div>
        <div class="form-row">
            <div class="form-group"><label>Ime</label><input type="text" id="s-kontakt_ime"></div>
            <div class="form-group"><label>Telefon</label><input type="text" id="s-kontakt_tel"></div>
        </div>
        <div class="form-group"><label>Email</label><input type="email" id="s-kontakt_email"></div>
    </div>
    <div class="card">
        <div class="card-title" style="margin-bottom:12px;">PDV</div>
        <div class="form-group">
            <label>Firma je u sistemu PDV-a?</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="s-pdv-da" name="s-pdv" value="1"><label for="s-pdv-da">Da</label></div>
                <div class="radio-option"><input type="radio" id="s-pdv-ne" name="s-pdv" value="0" checked><label for="s-pdv-ne">Ne</label></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>PDV stopa (%)</label><input type="number" id="s-pdv_rate" step="0.01"></div>
            <div class="form-group"><label>Kurs EUR → RSD</label><input type="number" id="s-kurs_eur" step="0.0001"></div>
        </div>
        <div class="form-group"><label>Napomena kad firma NIJE u PDV sistemu</label><textarea id="s-pdv_napomena"></textarea></div>
        <div class="form-group">
            <label>Napomena — obrnuti obračun (čl. 10 st. 2 t. 3)</label>
            <textarea id="s-pdv_cl10_napomena"></textarea>
            <div class="subnote">Štampa se automatski na dokumentima klijenata označenih za obrnuti obračun. Tačnu formulaciju daje knjigovođa.</div>
        </div>
        <div class="form-group"><label>Ponuda važi (dana)</label><input type="number" id="s-ponuda_vazi_dana"></div>
    </div>
    <button class="btn btn-success" onclick="saveSettings()">💾 Sačuvaj podešavanja</button>

    <div class="card" style="margin-top:16px;">
        <div class="card-title" style="margin-bottom:12px;">Promena lozinke</div>
        <div class="form-group"><label>Trenutna lozinka</label><input type="password" id="s-pass-current" autocomplete="current-password"></div>
        <div class="form-row">
            <div class="form-group"><label>Nova lozinka</label><input type="password" id="s-pass-new" autocomplete="new-password"></div>
            <div class="form-group"><label>Ponovi novu</label><input type="password" id="s-pass-new2" autocomplete="new-password"></div>
        </div>
        <button class="btn btn-outline" onclick="changePassword()">🔑 Promeni lozinku</button>
    </div>
</div>

<!-- ===== DOC MODAL ===== -->
<div class="modal" id="doc-modal">
    <div class="modal-header">
        <span class="modal-title" id="doc-modal-title">Dokument</span>
        <button class="modal-close" onclick="closeDocModal()">×</button>
    </div>
    <div class="modal-body">
        <div class="preview-box" id="doc-preview"></div>
    </div>
    <div class="modal-actions" id="doc-modal-actions"></div>
</div>

<!-- ===== CLIENT FORM OVERLAY ===== -->
<div class="overlay" id="client-overlay">
    <div class="overlay-content">
        <h3 id="client-form-title">Novi klijent</h3>
        <input type="hidden" id="cf-id">
        <div class="form-group">
            <label>Tip klijenta</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="cf-tip-f" name="cf-tip" value="fizicko" checked onchange="toggleClientFields()"><label for="cf-tip-f">Fizičko lice</label></div>
                <div class="radio-option"><input type="radio" id="cf-tip-p" name="cf-tip" value="pravno" onchange="toggleClientFields()"><label for="cf-tip-p">Pravno lice</label></div>
            </div>
        </div>
        <div class="form-group"><label id="cf-naziv-label">Ime i prezime</label><input type="text" id="cf-naziv"></div>
        <div class="form-row">
            <div class="form-group"><label>Telefon</label><input type="tel" id="cf-telefon"></div>
            <div class="form-group"><label>Email</label><input type="email" id="cf-email"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Adresa</label><input type="text" id="cf-adresa"></div>
            <div class="form-group"><label>Mesto</label><input type="text" id="cf-mesto"></div>
        </div>
        <div id="cf-pravno" style="display:none;">
            <div class="form-row">
                <div class="form-group"><label>PIB</label><input type="text" id="cf-pib"></div>
                <div class="form-group"><label>Matični broj</label><input type="text" id="cf-mb"></div>
            </div>
            <div class="form-group">
                <label>PDV tretman</label>
                <div class="radio-group">
                    <div class="radio-option"><input type="radio" id="cf-pdv-std" name="cf-pdv" value="standard" checked><label for="cf-pdv-std">PDV 20%</label></div>
                    <div class="radio-option"><input type="radio" id="cf-pdv-c10" name="cf-pdv" value="cl10"><label for="cf-pdv-c10">Čl. 10 st. 2 t. 3</label></div>
                </div>
                <div class="subnote">Čl. 10: obrnuti obračun — PDV obračunava primalac (građevinski radovi za PDV obveznike). Na računu ide napomena, bez PDV-a.</div>
            </div>
        </div>
        <div class="form-group"><label>Napomena</label><textarea id="cf-napomena"></textarea></div>
        <button class="btn btn-success" onclick="saveClient()">💾 Sačuvaj</button>
        <button class="btn btn-outline" onclick="closeOverlay('client-overlay')">Otkaži</button>
    </div>
</div>

<!-- ===== IZDAVANJE DOKUMENTA (predracun / konacni) ===== -->
<div class="overlay" id="issue-overlay">
    <div class="overlay-content">
        <h3 id="issue-title">Izdaj dokument</h3>
        <input type="hidden" id="iss-type">
        <div class="form-group">
            <label>Valuta dokumenta</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="iss-v-rsd" name="iss-valuta" value="RSD" checked onchange="issRefresh()"><label for="iss-v-rsd">RSD (dinari)</label></div>
                <div class="radio-option"><input type="radio" id="iss-v-eur" name="iss-valuta" value="EUR" onchange="issRefresh()"><label for="iss-v-eur">EUR</label></div>
            </div>
        </div>
        <div class="form-group" id="iss-kurs-group">
            <label>Kurs EUR → RSD</label>
            <input type="number" id="iss-kurs" step="0.0001" oninput="issRefresh()">
        </div>
        <div class="calc-display">
            <div class="label" id="iss-preview-label">Iznos dokumenta</div>
            <div class="value" id="iss-preview-total">—</div>
        </div>
        <div class="subnote" id="iss-note" style="margin-top:0;"></div>
        <button class="btn btn-success" onclick="doIssue()">✓ Izdaj</button>
        <button class="btn btn-outline" onclick="closeOverlay('issue-overlay')">Otkaži</button>
    </div>
</div>

<!-- ===== PAYMENT OVERLAY ===== -->
<div class="overlay" id="payment-overlay">
    <div class="overlay-content">
        <h3>Nova uplata</h3>
        <input type="hidden" id="pay-job-id">
        <div class="form-row">
            <div class="form-group"><label>Datum</label><input type="date" id="pay-datum"></div>
            <div class="form-group"><label>Iznos</label><input type="number" id="pay-iznos" step="0.01" min="0"></div>
        </div>
        <div class="form-group">
            <label>Valuta uplate</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="pay-v-eur" name="pay-valuta" value="EUR" checked onchange="payToggleKurs()"><label for="pay-v-eur">EUR</label></div>
                <div class="radio-option"><input type="radio" id="pay-v-rsd" name="pay-valuta" value="RSD" onchange="payToggleKurs()"><label for="pay-v-rsd">RSD</label></div>
            </div>
        </div>
        <div class="form-group" id="pay-kurs-group" style="display:none;">
            <label>Kurs EUR → RSD (na dan uplate)</label>
            <input type="number" id="pay-kurs" step="0.0001">
        </div>
        <div class="form-group">
            <label>Način uplate</label>
            <select id="pay-nacin">
                <option value="gotovina">Gotovina</option>
                <option value="racun">Na račun</option>
                <option value="kartica">Kartica</option>
            </select>
        </div>
        <div class="form-group"><label>Napomena</label><input type="text" id="pay-napomena" placeholder="npr. prva rata"></div>
        <button class="btn btn-success" onclick="savePayment()">💾 Sačuvaj uplatu</button>
        <button class="btn btn-outline" onclick="closeOverlay('payment-overlay')">Otkaži</button>
    </div>
</div>

<div class="toast" id="toast"></div>

<button class="fab" onclick="startNewJob()">+ Novi posao</button>
<div class="bottom-nav">
    <button class="bnav-btn active" id="bnav-poslovi" onclick="switchTab('poslovi')"><span class="ic">📋</span>Poslovi</button>
    <button class="bnav-btn" id="bnav-klijenti" onclick="switchTab('klijenti')"><span class="ic">👥</span>Klijenti</button>
    <button class="bnav-btn" id="bnav-podesavanja" onclick="switchTab('podesavanja')"><span class="ic">⚙️</span>Podešavanja</button>
</div>

<script src="assets/fonts.js?v=1"></script>
<script src="assets/app.js?v=2"></script>
</body>
</html>
