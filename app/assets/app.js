// ============================================
// PROHORECA AG GROUP - Poslovi (v2)
// Posao je centralna stvar; dokumenti i uplate
// izlaze iz posla. PDV tretman odredjuje klijent.
// ============================================

let SETTINGS = {};
let CLIENTS = [];
let JOBS = [];
let pergolas = [], glasses = [];
let pergolaIdC = 0, glassIdC = 0;
let editingJobId = null;         // posao koji se menja u kalkulatoru
let jobsFilter = 'svi';
let currentJob = null;           // posao otvoren u detalju
let currentDoc = null;           // dokument otvoren u modalu

// ---------- HELPERS ----------
async function api(action, data) {
    const r = await fetch('api.php?action=' + action, { method: 'POST', body: JSON.stringify(data || {}) });
    if (r.status === 401) { location.href = 'login.php'; return {}; }
    return r.json();
}
async function apiGet(action, params) {
    const qs = params ? '&' + new URLSearchParams(params).toString() : '';
    const r = await fetch('api.php?action=' + action + qs);
    if (r.status === 401) { location.href = 'login.php'; return {}; }
    return r.json();
}

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
const fmtN = new Intl.NumberFormat('sr-RS', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function fmt(n) { return fmtN.format(parseFloat(n) || 0); }
function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return `${d.getDate().toString().padStart(2,'0')}.${(d.getMonth()+1).toString().padStart(2,'0')}.${d.getFullYear()}.`;
}
function showToast(msg, isErr) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' error' : '');
    setTimeout(() => t.classList.remove('show'), 2600);
}
function closeOverlay(id) { document.getElementById(id).classList.remove('show'); }
function openOverlay(id)  { document.getElementById(id).classList.add('show'); }
async function logout() { await api('logout', {}); location.href = 'login.php'; }

const TYPE_LABEL = { ponuda:'Ponuda', predracun:'Predračun', avansni:'Avansni račun', faktura:'Konačni račun' };
const JOB_ST = {
    ponuda:      { l:'PONUDA',        cls:'pl-ponuda',      step:0 },
    ugovoreno:   { l:'UGOVORENO',     cls:'pl-ugovoreno',   step:1 },
    realizacija: { l:'U REALIZACIJI', cls:'pl-realizacija', step:2 },
    zavrseno:    { l:'ZAVRŠENO',      cls:'pl-zavrseno',    step:3 },
    odbijeno:    { l:'ODBIJENO',      cls:'pl-odbijeno',    step:-1 },
};
function jobPill(s) {
    const i = JOB_ST[s] || { l:s, cls:'pl-ponuda' };
    return `<span class="pill ${i.cls}">${i.l}</span>`;
}
function pdvTag(tip, mode) {
    if (tip !== 'pravno') return '';
    return mode === 'cl10' ? '<span class="tag tag-cl10">ČL. 10</span>'
                           : '<span class="tag tag-pdv">PDV 20%</span>';
}

// ---------- INIT ----------
document.addEventListener('DOMContentLoaded', async () => {
    SETTINGS = await apiGet('settings_get');
    fillSettingsForm();
    await loadClients();
    resetCalculator(false);
    loadJobs();
});

// ---------- TABS ----------
function switchTab(name) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.querySelectorAll('.bnav-btn').forEach(b => b.classList.remove('active'));
    const nav = document.getElementById('bnav-' + name);
    if (nav) nav.classList.add('active');
    document.querySelector('.fab').style.display = ['poslovi','klijenti'].includes(name) ? 'block' : 'none';
    if (name === 'poslovi')  loadJobs();
    if (name === 'klijenti') renderClients();
    window.scrollTo(0, 0);
}

// ============================================
// POSLOVI - lista
// ============================================
async function loadJobs() {
    JOBS = await apiGet('jobs_list');
    renderJobs();
}
function setJobsFilter(btn) {
    jobsFilter = btn.dataset.f;
    btn.parentElement.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderJobs();
}
function jobMatch(j) {
    const q = document.getElementById('jobs-search').value.trim().toLowerCase();
    if (q && !((j.klijent || '') + ' ' + (j.klijent_mesto || '') + ' ' + (j.naziv || '') + ' ' + (j.oznake || '')).toLowerCase().includes(q)) return false;
    const rest = parseFloat(j.total) - parseFloat(j.uplaceno);
    if (jobsFilter === 'ponude')   return j.status === 'ponuda';
    if (jobsFilter === 'utoku')    return ['ugovoreno','realizacija'].includes(j.status);
    if (jobsFilter === 'duguju')   return ['ugovoreno','realizacija','zavrseno'].includes(j.status) && rest > 0.005;
    if (jobsFilter === 'zavrseni') return j.status === 'zavrseno';
    return true;
}
function renderJobs() {
    // objedinjen presek: ugovoreno / naplaceno / potrazivanja (EUR)
    let ugovoreno = 0, uplaceno = 0;
    JOBS.forEach(j => {
        if (!['ugovoreno','realizacija','zavrseno'].includes(j.status)) return;
        ugovoreno += parseFloat(j.total) || 0;
        uplaceno  += parseFloat(j.uplaceno) || 0;
    });
    document.getElementById('jobs-stats').innerHTML = `
        <div class="stat-box"><div class="stat-num">${fmt(ugovoreno)} €</div><div class="stat-label">Ugovoreno</div></div>
        <div class="stat-box stat-ok"><div class="stat-num">${fmt(uplaceno)} €</div><div class="stat-label">Naplaćeno</div></div>
        <div class="stat-box stat-hot"><div class="stat-num">${fmt(Math.max(0, ugovoreno - uplaceno))} €</div><div class="stat-label">Potraživanja</div></div>`;

    const rows = JOBS.filter(jobMatch);
    document.getElementById('jobs-list').innerHTML = rows.map(j => {
        const total = parseFloat(j.total) || 0, upl = parseFloat(j.uplaceno) || 0;
        const rest = total - upl;
        const pct = total > 0 ? Math.min(100, upl / total * 100) : 0;
        const prog = ['ugovoreno','realizacija','zavrseno'].includes(j.status) ? `
            <div class="progress-bar"><div class="progress-fill" style="width:${pct}%;"></div></div>
            <div class="lc-meta">Uplaćeno <strong style="color:var(--success);">${fmt(upl)} €</strong> · ${rest > 0.005 ? 'duguje <strong style="color:var(--warning);">' + fmt(rest) + ' €</strong>' : '<strong style="color:var(--success);">izmireno ✓</strong>'}</div>` : '';
        return `
        <div class="list-card" onclick="openJob(${j.id})">
            <div class="lc-head">
                <div>
                    <div class="lc-title">${esc(j.klijent || 'Bez klijenta')} ${pdvTag(j.klijent_tip, j.klijent_pdv_mode)}</div>
                    <div class="lc-meta">${esc(j.naziv || '')}${j.klijent_mesto ? ' · 📍 ' + esc(j.klijent_mesto) : ''}</div>
                </div>
                ${jobPill(j.status)}
            </div>
            <div class="lc-amount">${fmt(total)} € <span style="font-size:11px;color:var(--text-secondary);font-weight:600;">· ${fmtDate(j.datum)}</span></div>
            ${prog}
        </div>`;
    }).join('') || '<div class="empty-state"><div class="empty-icon">📋</div>Nema poslova za ovaj filter.<br>Kreni od <strong>+ Novi posao</strong>.</div>';
}

// ============================================
// DETALJ POSLA
// ============================================
async function openJob(id) {
    const job = await apiGet('job_get', { id });
    if (job.error) { showToast(job.error, true); return; }
    currentJob = job;
    document.getElementById('jd-title').textContent = job.klijent_naziv || 'Bez klijenta';
    document.getElementById('jd-sub').textContent = job.naziv || '';
    renderJobDetail();
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById('tab-posao').classList.add('active');
    document.querySelector('.fab').style.display = 'none';
    window.scrollTo(0, 0);
}

function renderJobDetail() {
    const j = currentJob;
    const st = JOB_ST[j.status];
    const total = parseFloat(j.total) || 0;
    const upl = parseFloat(j.uplaceno_eur) || 0;
    const rest = total - upl;

    // koraci statusa (klik menja status)
    const stepNames = [['ponuda','Ponuda'],['ugovoreno','Ugovoreno'],['realizacija','Realizacija'],['zavrseno','Završeno']];
    const steps = j.status === 'odbijeno'
        ? `<div class="lawnote" style="margin:0 0 12px;">Posao označen kao ODBIJEN. <button class="btn btn-sm btn-outline" style="margin-left:8px;" onclick="setJobStatus('ponuda')">Vrati u ponude</button></div>`
        : `<div class="steps">` + stepNames.map(([key, label], i) => `
            <div class="step ${i < st.step ? 'done' : i === st.step ? 'now' : ''}" onclick="setJobStatus('${key}')">
                <div class="dot">${i < st.step ? '✓' : i + 1}</div><div class="sl">${label}</div>
            </div>`).join('') + `</div>`;

    // klijent
    const kMeta = [j.klijent_tel ? '📞 ' + esc(j.klijent_tel) : '', j.klijent_mesto ? '📍 ' + esc(j.klijent_mesto) : '',
                   j.klijent_pib ? 'PIB ' + esc(j.klijent_pib) : ''].filter(Boolean).join(' · ');

    // stavke + obracun po PDV tretmanu
    const items = (j.items || []).map(it => `
        <div class="summary-row">
            <span class="sum-label">${esc(it.naziv)}${it.opis ? '<br><small>' + esc(it.opis) + '</small>' : ''}</span>
            <span class="sum-value" style="white-space:nowrap;">${fmt(it.iznos)} €</span>
        </div>`).join('');
    let obracun;
    if (j.pdv_mode === 'standard') {
        const rate = parseFloat(j.pdv_rate) || 20;
        const pdv = total - total / (1 + rate / 100);
        obracun = `
            <div class="summary-row"><span class="sum-label">Osnovica</span><span class="sum-value">${fmt(total - pdv)} €</span></div>
            <div class="summary-row"><span class="sum-label">PDV ${rate}%</span><span class="sum-value">${fmt(pdv)} €</span></div>
            <div class="summary-row total"><span class="sum-label">UKUPNO</span><span class="sum-value">${fmt(total)} €</span></div>
            <div class="subnote">Pravno lice — osnovica i PDV se iskazuju automatski na svakom dokumentu.</div>`;
    } else if (j.pdv_mode === 'cl10') {
        obracun = `
            <div class="summary-row total"><span class="sum-label">UKUPNO (bez PDV)</span><span class="sum-value">${fmt(total)} €</span></div>
            <div class="lawnote">⚖ ${esc(SETTINGS.pdv_cl10_napomena || 'Obrnuti obračun — PDV obračunava primalac (čl. 10 st. 2 t. 3 Zakona o PDV).')}</div>`;
    } else {
        obracun = `
            <div class="summary-row total"><span class="sum-label">UKUPNO</span><span class="sum-value">${fmt(total)} €</span></div>
            <div class="subnote">${j.klijent_tip === 'pravno' ? 'Firma nije u sistemu PDV-a — PDV se ne iskazuje.' : 'Fizičko lice — PDV se ne iskazuje na dokumentima.'}</div>`;
    }
    const discRow = parseFloat(j.disc_amount) > 0
        ? `<div class="summary-row"><span class="sum-label">Popust</span><span class="sum-value" style="color:var(--danger);">-${fmt(j.disc_amount)} €</span></div>` : '';

    // dokumenti
    const chips = (j.documents || []).map(d => `
        <span class="docchip ${d.status === 'storniran' ? 'storno' : ''}" onclick="openDoc(${d.id})">
            ${TYPE_LABEL[d.type]} <span>${esc(d.oznaka)}</span>
        </span>`).join('') || '<div class="subnote" style="margin:0 0 10px;">Još nema dokumenata.</div>';

    // uplate
    const pays = (j.payments || []).map(p => `
        <div class="summary-row">
            <span class="sum-label">${fmtDate(p.datum)} · ${esc(p.nacin)}${p.napomena ? ' — ' + esc(p.napomena) : ''}
                ${p.avansni_oznaka ? `<br><small style="color:var(--success);font-weight:700;">✓ izdat avansni ${esc(p.avansni_oznaka)}</small>` : ''}
            </span>
            <span class="sum-value" style="white-space:nowrap;">${fmt(p.iznos)} ${esc(p.valuta)}
                <button class="del-x" onclick="deletePayment(${p.id})">🗑</button>
            </span>
        </div>`).join('') || '<div class="subnote" style="margin:0;">Još nema uplata.</div>';

    // predlog: izdaj avansni na uplatu bez avansnog
    const noAvr = (j.payments || []).find(p => !p.avansni_doc_id);
    const suggest = noAvr && j.status !== 'zavrseno' && j.status !== 'odbijeno' ? `
        <div class="suggest">💡 Za uplatu od <strong>${fmt(noAvr.iznos)} ${esc(noAvr.valuta)}</strong> (${fmtDate(noAvr.datum)}) nije izdat avansni račun.
            <br><button onclick="issueAvansni(${noAvr.id})">Izdaj avansni račun</button>
        </div>` : '';

    document.getElementById('jd-body').innerHTML = `
        ${steps}
        <div class="card">
            <div class="lc-head">
                <div>
                    <div class="lc-title">${esc(j.klijent_naziv || 'Bez klijenta')} ${pdvTag(j.klijent_tip, j.klijent_pdv_mode)}</div>
                    <div class="lc-meta">${kMeta}</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:8px;">Stavke</div>
            ${items}
            ${discRow}
            ${obracun}
            ${j.rok ? `<div class="subnote">⏱ Rok: ${esc(j.rok)}</div>` : ''}
        </div>
        <div class="card">
            <div class="card-title" style="margin-bottom:10px;">Dokumenti</div>
            <div class="docchips">${chips}</div>
            <div class="actrow">
                <button class="btn btn-outline" onclick="openIssue('predracun')">+ Predračun</button>
                <button class="btn btn-accent" onclick="openIssue('faktura')">+ Konačni račun</button>
            </div>
            <div class="subnote">Avansni račun se izdaje na uplatu (dole). Konačni račun automatski odbija sve izdate avansne račune.</div>
        </div>
        <div class="card">
            <div class="card-header" style="margin-bottom:6px;">
                <span class="card-title">💰 Uplate</span>
                <button class="btn btn-success btn-sm" onclick="openPaymentForm()">+ Uplata</button>
            </div>
            ${pays}
            ${suggest}
            <div class="separator"></div>
            <div class="summary-row"><span class="sum-label">Uplaćeno</span><span class="sum-value" style="color:var(--success);">${fmt(upl)} €</span></div>
            <div class="summary-row"><span class="sum-label">Preostalo</span><span class="sum-value" style="color:${rest > 0.005 ? 'var(--warning)' : 'var(--success)'};">${fmt(Math.max(0, rest))} €</span></div>
        </div>
        <div class="actrow" style="margin-bottom:10px;">
            <button class="btn btn-outline" onclick="editJob()">✎ Izmeni posao</button>
            ${j.status === 'ponuda' ? `<button class="btn btn-danger" onclick="setJobStatus('odbijeno')">✕ Odbijena</button>` : ''}
        </div>
        <button class="btn btn-danger" onclick="deleteJob()">🗑 Obriši posao (sa dokumentima i uplatama)</button>`;
}

async function setJobStatus(status) {
    if (!currentJob || currentJob.status === status) return;
    const r = await api('job_status', { id: currentJob.id, status });
    if (r.error) { showToast(r.error, true); return; }
    showToast('Status: ' + JOB_ST[status].l);
    openJob(currentJob.id);
}

async function deleteJob() {
    if (!confirm('Obrisati ceo posao, sve njegove dokumente i uplate?')) return;
    const r = await api('job_delete', { id: currentJob.id });
    if (r.error) { showToast(r.error, true); return; }
    showToast('Posao obrisan');
    switchTab('poslovi');
}

// ============================================
// KALKULATOR (novi posao / izmena)
// ============================================
function startNewJob() {
    resetCalculator(false);
    switchTab('novi');
}
function cancelCalculator() {
    if (editingJobId) { const id = editingJobId; editingJobId = null; openJob(id); }
    else switchTab('poslovi');
}
function resetCalculator(confirmFirst) {
    if (confirmFirst && (pergolas.length || glasses.length) && !confirm('Odbaciti trenutni unos?')) return;
    editingJobId = null;
    pergolas = []; glasses = [];
    pergolaIdC = 0; glassIdC = 0;
    document.getElementById('nova-title').textContent = 'Novi posao';
    document.getElementById('np-datum').value = new Date().toISOString().split('T')[0];
    document.getElementById('np-disc-type').value = 'percent';
    document.getElementById('np-disc-val').value = '';
    document.getElementById('np-rok').value = '';
    document.getElementById('np-napomena').value = '';
    document.getElementById('np-client').value = '';
    renderPergolas(); renderGlasses(); calcSummary();
}

function srPergola(n) { return n === 1 ? '1 pergola' : (n >= 2 && n <= 4 ? n + ' pergole' : n + ' pergola'); }
function srSistem(n)  { return n === 1 ? '1 sistem'  : n + ' sistema'; }

// --- Pergole ---
function addPergola() {
    const id = ++pergolaIdC;
    pergolas.push({
        id, montaza: 'Naslonjena na objekat', tip: 'Ceradno platno',
        sirina: '', dubina: '', visina: '250', strane: '0',
        cenaM2: SETTINGS.price_pergola_m2 || '', cenaZatvM2: SETTINGS.price_close_m2 || '',
        total: 0, manualTotal: false
    });
    renderPergolas(); calcPergola(id);
}
function removePergola(id) { pergolas = pergolas.filter(p => p.id !== id); renderPergolas(); calcSummary(); }
function updPergola(id, f, v) {
    const p = pergolas.find(p => p.id === id); if (!p) return;
    p[f] = v; p.manualTotal = false;
    if (f === 'cenaM2')     { SETTINGS.price_pergola_m2 = v; api('settings_save', { price_pergola_m2: v }); }
    if (f === 'cenaZatvM2') { SETTINGS.price_close_m2 = v;   api('settings_save', { price_close_m2: v }); }
    calcPergola(id);
}
function updPergolaTotal(id) {
    const p = pergolas.find(p => p.id === id); if (!p) return;
    p.total = parseFloat(document.getElementById(`p-total-${id}`).value) || 0;
    p.manualTotal = true;
    calcSummary();
}

function renderPergolas() {
    const c = document.getElementById('np-pergole');
    const chk = (a, b) => a === b ? 'checked' : '';
    c.innerHTML = pergolas.map((p, idx) => `
    <div class="card">
        <div class="card-header">
            <span class="card-title">Pergola ${idx + 1}</span>
            <button class="card-remove" onclick="removePergola(${p.id})">×</button>
        </div>
        <div class="form-group">
            <label>Način montaže</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="pm-n-${p.id}" name="pm-${p.id}" value="Naslonjena na objekat" ${chk(p.montaza,'Naslonjena na objekat')} onchange="updPergola(${p.id},'montaza',this.value)"><label for="pm-n-${p.id}">Naslonjena</label></div>
                <div class="radio-option"><input type="radio" id="pm-s-${p.id}" name="pm-${p.id}" value="Samostojeća" ${chk(p.montaza,'Samostojeća')} onchange="updPergola(${p.id},'montaza',this.value)"><label for="pm-s-${p.id}">Samostojeća</label></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Širina (cm)</label><input type="number" min="0" value="${p.sirina}" oninput="updPergola(${p.id},'sirina',this.value)"></div>
            <div class="form-group"><label>Dubina (cm)</label><input type="number" min="0" value="${p.dubina}" oninput="updPergola(${p.id},'dubina',this.value)"></div>
        </div>
        <div class="calc-display"><div class="label">Kvadratura</div><div class="value"><span id="p-area-${p.id}">0.00</span> <span class="unit">m²</span></div></div>
        <div class="form-group">
            <label>Tip pergole</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="pt-c-${p.id}" name="pt-${p.id}" value="Ceradno platno" ${chk(p.tip,'Ceradno platno')} onchange="updPergola(${p.id},'tip',this.value)"><label for="pt-c-${p.id}">Ceradno platno</label></div>
                <div class="radio-option"><input type="radio" id="pt-b-${p.id}" name="pt-${p.id}" value="Bioklimatska" ${chk(p.tip,'Bioklimatska')} onchange="updPergola(${p.id},'tip',this.value)"><label for="pt-b-${p.id}">Bioklimatska</label></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Zatvaranje strana</label>
                <select onchange="updPergola(${p.id},'strane',this.value)">
                    ${[0,1,2,3,4].map(n => `<option value="${n}" ${chk(p.strane, String(n))}>${n} ${n===1?'strana':'strane'}</option>`).join('')}
                </select>
            </div>
            <div class="form-group"><label>Visina strana (cm)</label><input type="number" min="0" value="${p.visina}" oninput="updPergola(${p.id},'visina',this.value)"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Cena pergole (€/m²)</label><input type="number" min="0" step="0.01" value="${p.cenaM2}" oninput="updPergola(${p.id},'cenaM2',this.value)"></div>
            <div class="form-group"><label>Cena zatv. (€/m²)</label><input type="number" min="0" step="0.01" value="${p.cenaZatvM2}" oninput="updPergola(${p.id},'cenaZatvM2',this.value)"></div>
        </div>
        <div class="calc-row">
            <div class="calc-display"><div class="label">Cena pergole</div><div class="value" style="font-size:17px;"><span id="p-sub-${p.id}">0.00</span> <span class="unit">€</span></div></div>
            <div class="calc-display"><div class="label">Cena zatvaranja</div><div class="value" style="font-size:17px;"><span id="p-close-${p.id}">0.00</span> <span class="unit">€</span></div></div>
        </div>
        <div class="price-box" style="margin-top:8px;">
            <div class="label">Ukupno ova pergola (klikni da koriguješ)</div>
            <div class="price" style="font-size:22px;">
                <input type="number" id="p-total-${p.id}" class="editable-price" value="${p.total.toFixed(2)}" min="0" step="1" oninput="updPergolaTotal(${p.id})">
                <span class="currency">EUR</span>
            </div>
        </div>
    </div>`).join('');
    document.getElementById('np-pergola-badge').textContent = srPergola(pergolas.length);
    pergolas.forEach(p => calcPergola(p.id));
}

function calcPergola(id) {
    const p = pergolas.find(p => p.id === id); if (!p) return;
    const w = parseFloat(p.sirina) || 0, d = parseFloat(p.dubina) || 0;
    const h = parseFloat(p.visina) || 250, sides = parseInt(p.strane) || 0;
    const area = (w * d) / 10000;
    let sideArea = 0;
    if (sides > 0) {
        const L = [];
        if (w > 0) L.push(w, w);
        if (d > 0) L.push(d, d);
        for (let i = 0; i < Math.min(sides, L.length); i++) sideArea += (L[i] * h) / 10000;
    }
    const cP = area * (parseFloat(p.cenaM2) || 0);
    const cZ = sideArea * (parseFloat(p.cenaZatvM2) || 0);
    if (!p.manualTotal) p.total = cP + cZ;
    const el = document.getElementById(`p-area-${id}`);
    if (el) {
        el.textContent = area.toFixed(2);
        document.getElementById(`p-sub-${id}`).textContent = cP.toFixed(2);
        document.getElementById(`p-close-${id}`).textContent = cZ.toFixed(2);
        document.getElementById(`p-total-${id}`).value = p.total.toFixed(2);
    }
    calcSummary();
}

// --- Stakla ---
function addGlass() {
    const id = ++glassIdC;
    glasses.push({
        id, montaza: 'Ispod pergole', tip: 'Slajding', strane: '1',
        sirina: '', visina: '', polja: '1',
        cenaM2: SETTINGS.price_glass_m2 || '', total: 0, manualTotal: false
    });
    renderGlasses(); calcGlass(id);
}
function removeGlass(id) { glasses = glasses.filter(g => g.id !== id); renderGlasses(); calcSummary(); }
function updGlass(id, f, v) {
    const g = glasses.find(g => g.id === id); if (!g) return;
    g[f] = v; g.manualTotal = false;
    if (f === 'cenaM2') { SETTINGS.price_glass_m2 = v; api('settings_save', { price_glass_m2: v }); }
    calcGlass(id);
}
function updGlassTotal(id) {
    const g = glasses.find(g => g.id === id); if (!g) return;
    g.total = parseFloat(document.getElementById(`g-total-${id}`).value) || 0;
    g.manualTotal = true;
    calcSummary();
}

function renderGlasses() {
    const c = document.getElementById('np-stakla');
    const chk = (a, b) => a === b ? 'checked' : '';
    c.innerHTML = glasses.map((g, idx) => `
    <div class="card">
        <div class="card-header">
            <span class="card-title">Stakleni sistem ${idx + 1}</span>
            <button class="card-remove" onclick="removeGlass(${g.id})">×</button>
        </div>
        <div class="form-group">
            <label>Gde se montira</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="gm-p-${g.id}" name="gm-${g.id}" value="Ispod pergole" ${chk(g.montaza,'Ispod pergole')} onchange="updGlass(${g.id},'montaza',this.value)"><label for="gm-p-${g.id}">Ispod pergole</label></div>
                <div class="radio-option"><input type="radio" id="gm-f-${g.id}" name="gm-${g.id}" value="Fasada" ${chk(g.montaza,'Fasada')} onchange="updGlass(${g.id},'montaza',this.value)"><label for="gm-f-${g.id}">Fasada</label></div>
                <div class="radio-option"><input type="radio" id="gm-s-${g.id}" name="gm-${g.id}" value="Samostalno" ${chk(g.montaza,'Samostalno')} onchange="updGlass(${g.id},'montaza',this.value)"><label for="gm-s-${g.id}">Samostalno</label></div>
            </div>
        </div>
        <div class="form-group">
            <label>Broj strana za zatvaranje</label>
            <select onchange="updGlass(${g.id},'strane',this.value)">
                ${[1,2,3,4].map(n => `<option value="${n}" ${chk(g.strane, String(n))}>${n} ${n===1?'strana':'strane'}</option>`).join('')}
            </select>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label>Širina (cm)</label><input type="number" min="0" value="${g.sirina}" oninput="updGlass(${g.id},'sirina',this.value)"></div>
            <div class="form-group"><label>Visina (cm)</label><input type="number" min="0" value="${g.visina}" oninput="updGlass(${g.id},'visina',this.value)"></div>
            <div class="form-group"><label>Br. polja</label><input type="number" min="1" value="${g.polja}" oninput="updGlass(${g.id},'polja',this.value)"></div>
        </div>
        <div class="calc-display"><div class="label">Kvadratura</div><div class="value"><span id="g-area-${g.id}">0.00</span> <span class="unit">m²</span></div></div>
        <div class="form-group">
            <label>Tip stakla</label>
            <div class="radio-group">
                <div class="radio-option"><input type="radio" id="gt-s-${g.id}" name="gt-${g.id}" value="Slajding" ${chk(g.tip,'Slajding')} onchange="updGlass(${g.id},'tip',this.value)"><label for="gt-s-${g.id}">Slajding</label></div>
                <div class="radio-option"><input type="radio" id="gt-g-${g.id}" name="gt-${g.id}" value="Giljotina" ${chk(g.tip,'Giljotina')} onchange="updGlass(${g.id},'tip',this.value)"><label for="gt-g-${g.id}">Giljotina</label></div>
            </div>
        </div>
        <div class="form-group"><label>Cena stakla (€/m²)</label><input type="number" min="0" step="0.01" value="${g.cenaM2}" oninput="updGlass(${g.id},'cenaM2',this.value)"></div>
        <div class="price-box">
            <div class="label">Ukupno ovaj sistem (klikni da koriguješ)</div>
            <div class="price" style="font-size:22px;">
                <input type="number" id="g-total-${g.id}" class="editable-price" value="${g.total.toFixed(2)}" min="0" step="1" oninput="updGlassTotal(${g.id})">
                <span class="currency">EUR</span>
            </div>
        </div>
    </div>`).join('');
    document.getElementById('np-glass-badge').textContent = srSistem(glasses.length);
    glasses.forEach(g => calcGlass(g.id));
}

function calcGlass(id) {
    const g = glasses.find(g => g.id === id); if (!g) return;
    const area = ((parseFloat(g.sirina) || 0) * (parseFloat(g.visina) || 0) * (parseInt(g.polja) || 1)) / 10000;
    if (!g.manualTotal) g.total = area * (parseFloat(g.cenaM2) || 0);
    const el = document.getElementById(`g-area-${id}`);
    if (el) {
        el.textContent = area.toFixed(2);
        document.getElementById(`g-total-${id}`).value = g.total.toFixed(2);
    }
    calcSummary();
}

// --- Sumarno ---
function calcSummary() {
    const pT = pergolas.reduce((s, p) => s + (p.total || 0), 0);
    const gT = glasses.reduce((s, g) => s + (g.total || 0), 0);
    const sub = pT + gT;
    const dt = document.getElementById('np-disc-type').value;
    const dv = parseFloat(document.getElementById('np-disc-val').value) || 0;
    const disc = dv > 0 ? (dt === 'percent' ? sub * dv / 100 : dv) : 0;
    document.getElementById('np-sum-pergole').textContent = fmt(pT);
    document.getElementById('np-sum-staklo').textContent = fmt(gT);
    document.getElementById('np-sum-subtotal').textContent = fmt(sub);
    document.getElementById('np-disc-row').style.display = disc > 0 ? 'flex' : 'none';
    document.getElementById('np-sum-disc').textContent = fmt(disc);
    document.getElementById('np-sum-total').textContent = fmt(Math.max(0, sub - disc));
}

// --- Cuvanje posla ---
function buildItems() {
    const items = [];
    pergolas.forEach(p => {
        const area = ((parseFloat(p.sirina)||0) * (parseFloat(p.dubina)||0) / 10000).toFixed(2);
        let opis = `${p.sirina||0}×${p.dubina||0} cm = ${area} m²`;
        if (parseInt(p.strane) > 0) opis += `, zatvaranje: ${p.strane} ${p.strane==='1'?'strana':'strane'} (h=${p.visina||250} cm)`;
        items.push({
            kind: 'pergola',
            naziv: `Pergola — ${p.tip} (${p.montaza})`,
            opis,
            iznos: p.total || 0,
            meta: { ...p }
        });
    });
    glasses.forEach(g => {
        const area = ((parseFloat(g.sirina)||0) * (parseFloat(g.visina)||0) * (parseInt(g.polja)||1) / 10000).toFixed(2);
        items.push({
            kind: 'staklo',
            naziv: `Stakleni sistem — ${g.tip} (${g.montaza})`,
            opis: `${g.sirina||0}×${g.visina||0} cm, ${g.polja||1} polja = ${area} m²`,
            iznos: g.total || 0,
            meta: { ...g }
        });
    });
    return items;
}

function jobNaziv(items) {
    const p = items.filter(i => i.kind === 'pergola').length;
    const g = items.filter(i => i.kind === 'staklo').length;
    const parts = [];
    if (p) parts.push(srPergola(p));
    if (g) parts.push(srSistem(g) + ' stakla');
    return parts.join(' + ') || 'Posao';
}

async function saveJob() {
    const clientId = document.getElementById('np-client').value;
    if (!clientId) { showToast('⚠ Izaberi klijenta', true); return; }
    if (!pergolas.length && !glasses.length) { showToast('⚠ Dodaj pergolu ili stakleni sistem', true); return; }
    const pT = pergolas.reduce((s, p) => s + (p.total||0), 0);
    const gT = glasses.reduce((s, g) => s + (g.total||0), 0);
    const sub = pT + gT;
    const dt = document.getElementById('np-disc-type').value;
    const dv = parseFloat(document.getElementById('np-disc-val').value) || 0;
    const disc = dv > 0 ? (dt === 'percent' ? sub * dv / 100 : dv) : 0;
    const items = buildItems();
    const r = await api('job_save', {
        id: editingJobId,
        client_id: parseInt(clientId),
        naziv: jobNaziv(items),
        datum: document.getElementById('np-datum').value,
        items,
        calc_state: { pergolas, glasses, pergolaIdC, glassIdC },
        subtotal: sub, disc_type: dt, disc_val: dv, disc_amount: disc,
        total: Math.max(0, sub - disc),
        rok: document.getElementById('np-rok').value,
        napomena: document.getElementById('np-napomena').value,
    });
    if (r.error) { showToast(r.error, true); return; }
    showToast(`✓ Sačuvano${r.oznaka ? ': ' + r.oznaka : ''}`);
    const jobId = r.id;
    editingJobId = null;
    resetCalculator(false);
    openJob(jobId);
}

function editJob() {
    const j = currentJob;
    const cs = j.calc_state;
    if (cs && cs.pergolas) {
        pergolas = cs.pergolas; glasses = cs.glasses || [];
        pergolaIdC = cs.pergolaIdC || pergolas.length;
        glassIdC = cs.glassIdC || glasses.length;
    } else {
        pergolas = []; glasses = []; pergolaIdC = 0; glassIdC = 0;
    }
    editingJobId = j.id;
    document.getElementById('nova-title').textContent = 'Izmena posla';
    document.getElementById('np-client').value = j.client_id || '';
    document.getElementById('np-datum').value = j.datum;
    document.getElementById('np-disc-type').value = j.disc_type || 'percent';
    document.getElementById('np-disc-val').value = parseFloat(j.disc_val) > 0 ? j.disc_val : '';
    document.getElementById('np-rok').value = j.rok || '';
    document.getElementById('np-napomena').value = j.napomena || '';
    renderPergolas(); renderGlasses(); calcSummary();
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById('tab-novi').classList.add('active');
    document.querySelector('.fab').style.display = 'none';
    window.scrollTo(0, 0);
}

// ============================================
// IZDAVANJE DOKUMENATA
// ============================================
function openIssue(type) {
    document.getElementById('iss-type').value = type;
    document.getElementById('issue-title').textContent = 'Izdaj: ' + TYPE_LABEL[type];
    document.getElementById('iss-kurs').value = SETTINGS.kurs_eur || '117.2';
    document.getElementById('iss-note').textContent = type === 'faktura'
        ? 'Konačni račun automatski odbija sve izdate avansne račune i iskazuje razliku za uplatu.'
        : '';
    issRefresh();
    openOverlay('issue-overlay');
}

function issRefresh() {
    const valuta = document.querySelector('input[name="iss-valuta"]:checked').value;
    document.getElementById('iss-kurs-group').style.display = valuta === 'RSD' ? 'block' : 'none';
    if (!currentJob) return;
    const type = document.getElementById('iss-type').value;
    const kurs = valuta === 'RSD' ? (parseFloat(document.getElementById('iss-kurs').value) || 1) : 1;
    let total = parseFloat(currentJob.total) * kurs;
    if (type === 'faktura') {
        (currentJob.documents || []).forEach(d => {
            if (d.type !== 'avansni' || d.status === 'storniran') return;
            const avEur = d.valuta === 'RSD' ? parseFloat(d.total) / (parseFloat(d.kurs) > 1 ? parseFloat(d.kurs) : kurs) : parseFloat(d.total);
            total -= avEur * kurs;
        });
        document.getElementById('iss-preview-label').textContent = 'Za uplatu (posle odbitka avansa)';
    } else {
        document.getElementById('iss-preview-label').textContent = 'Iznos dokumenta';
    }
    document.getElementById('iss-preview-total').textContent = `${fmt(Math.max(0, total))} ${valuta}`;
}

async function doIssue() {
    const type = document.getElementById('iss-type').value;
    const valuta = document.querySelector('input[name="iss-valuta"]:checked').value;
    const kurs = valuta === 'RSD' ? (parseFloat(document.getElementById('iss-kurs').value) || 0) : 1;
    const r = await api('doc_issue', { job_id: currentJob.id, type, valuta, kurs });
    if (r.error) { showToast(r.error, true); return; }
    closeOverlay('issue-overlay');
    showToast(`✓ Izdat: ${r.oznaka}`);
    await openJob(currentJob.id);
    openDoc(r.id);
}

async function issueAvansni(paymentId) {
    const r = await api('doc_issue', { job_id: currentJob.id, type: 'avansni', payment_id: paymentId });
    if (r.error) { showToast(r.error, true); return; }
    showToast(`✓ Izdat avansni: ${r.oznaka}`);
    await openJob(currentJob.id);
    openDoc(r.id);
}

// ============================================
// DOC MODAL (pregled dokumenta)
// ============================================
async function openDoc(id) {
    const doc = await apiGet('doc_get', { id });
    if (doc.error) { showToast(doc.error, true); return; }
    currentDoc = doc;
    document.getElementById('doc-modal-title').textContent =
        `${TYPE_LABEL[doc.type]} ${doc.oznaka}` + (doc.status === 'storniran' ? ' — STORNIRAN' : '');
    document.getElementById('doc-preview').innerHTML = buildDocHtml(doc);
    renderDocActions(doc);
    document.getElementById('doc-modal').classList.add('show');
}
function closeDocModal() { document.getElementById('doc-modal').classList.remove('show'); currentDoc = null; }

function renderDocActions(doc) {
    const a = document.getElementById('doc-modal-actions');
    let btns = '';
    btns += `<button class="btn btn-accent" onclick="downloadDocPDF()">↓ PDF</button>`;
    btns += `<button class="btn btn-outline" onclick="shareDoc(${doc.id})">🔗 Link (WA/Viber)</button>`;
    btns += `<button class="btn btn-outline" onclick="copyDocText()">❐ Tekst</button>`;
    if (doc.type !== 'ponuda') {
        btns += `<button class="btn btn-outline" onclick="stornoDoc(${doc.id})">${doc.status === 'storniran' ? '↩ Vrati' : '⊘ Storno'}</button>`;
        btns += `<button class="btn btn-danger" onclick="deleteDoc(${doc.id})">🗑</button>`;
    }
    a.innerHTML = btns;
}

async function stornoDoc(id) {
    const r = await api('doc_storno', { id });
    if (r.error) { showToast(r.error, true); return; }
    showToast(r.status === 'storniran' ? 'Dokument storniran' : 'Dokument ponovo aktivan');
    closeDocModal();
    if (currentJob) openJob(currentJob.id);
}

async function deleteDoc(id) {
    if (!confirm('Obrisati ovaj dokument?')) return;
    const r = await api('doc_delete', { id });
    if (r.error) { showToast(r.error, true); return; }
    closeDocModal();
    showToast('Dokument obrisan');
    if (currentJob) openJob(currentJob.id);
}

async function shareDoc(id) {
    const r = await api('doc_share', { id });
    if (r.error) { showToast(r.error, true); return; }
    const text = `${SETTINGS.firma_naziv}\n${TYPE_LABEL[currentDoc.type]} ${currentDoc.oznaka}\nPogledajte na linku:\n${r.url}`;
    if (navigator.share) {
        try { await navigator.share({ title: SETTINGS.firma_naziv, text }); return; } catch(e) {}
    }
    await navigator.clipboard.writeText(text);
    showToast('Link kopiran — nalepi u Viber/WhatsApp');
}

// ---------- HTML pregled dokumenta ----------
function getClientInfo(doc) {
    return {
        naziv: doc.klijent_naziv || '—',
        tip: doc.klijent_tip || 'fizicko',
        tel: doc.klijent_tel || '',
        adresa: [doc.klijent_adresa, doc.klijent_mesto].filter(Boolean).join(', '),
        pib: doc.klijent_pib || '', mb: doc.klijent_mb || ''
    };
}

function totalLabel(doc) {
    return doc.type === 'faktura' && doc.items.some(i => i.kind === 'avans_minus') ? 'ZA UPLATU' : 'UKUPNO';
}

function buildDocHtml(doc) {
    const k = getClientInfo(doc);
    const val = doc.valuta;
    const isRacun = doc.type !== 'ponuda';
    const rows = doc.items.map((it, i) => `
        <tr>
            <td>${i+1}</td>
            <td>${esc(it.naziv)}${it.opis ? `<br><small style="color:#888">${esc(it.opis)}</small>` : ''}</td>
            <td style="text-align:right;font-weight:700;white-space:nowrap;${parseFloat(it.iznos) < 0 ? 'color:#e74c3c;' : ''}">${fmt(it.iznos)} ${val}</td>
        </tr>`).join('');

    let pdvBlock = '';
    if (doc.pdv_mode === 'standard' && parseFloat(doc.pdv_amount) > 0) {
        const osnovica = parseFloat(doc.total) - parseFloat(doc.pdv_amount);
        pdvBlock = `<div style="text-align:right;font-size:13px;color:#555;">Osnovica: ${fmt(osnovica)} ${val}<br>PDV (${parseFloat(doc.pdv_rate)}%): ${fmt(doc.pdv_amount)} ${val}</div>`;
    }
    let pdvNote = '';
    if (doc.pdv_mode === 'cl10' && doc.pdv_napomena) {
        pdvNote = `<div class="info-note" style="border:1px solid #e0b050;background:#fdf6e8;"><strong>⚖</strong> ${esc(doc.pdv_napomena)}</div>`;
    } else if (isRacun && doc.pdv_mode === 'none' && doc.pdv_napomena) {
        pdvNote = `<div class="info-note">${esc(doc.pdv_napomena)}</div>`;
    }

    return `
        <h2>${esc(SETTINGS.firma_naziv)}</h2>
        ${isRacun && SETTINGS.firma_pib ? `<div style="text-align:center;font-size:11px;color:#666;">PIB: ${esc(SETTINGS.firma_pib)} · MB: ${esc(SETTINGS.firma_mb)}${SETTINGS.firma_adresa ? ' · ' + esc(SETTINGS.firma_adresa) + ', ' + esc(SETTINGS.firma_mesto) : ''}</div>` : ''}
        <h3>${TYPE_LABEL[doc.type].toUpperCase()} br. ${esc(doc.oznaka)}${doc.status === 'storniran' ? ' — STORNIRAN' : ''}</h3>
        <div style="font-size:13px;margin-bottom:12px;">
            <strong>Datum:</strong> ${fmtDate(doc.datum)}<br>
            <strong>Klijent:</strong> ${esc(k.naziv)}${k.tip === 'pravno' ? ' (pravno lice)' : ''}<br>
            ${k.adresa ? `<strong>Adresa:</strong> ${esc(k.adresa)}<br>` : ''}
            ${k.tel ? `<strong>Telefon:</strong> ${esc(k.tel)}<br>` : ''}
            ${k.pib ? `<strong>PIB:</strong> ${esc(k.pib)} &nbsp; <strong>MB:</strong> ${esc(k.mb)}<br>` : ''}
        </div>
        <div class="section-header">SPECIFIKACIJA</div>
        <table>
            <tr><th>#</th><th>Opis</th><th style="text-align:right;">Iznos</th></tr>
            ${rows}
        </table>
        ${doc.items.some(i => i.kind === 'pergola') ? `<div class="info-note">U cenu pergola je uključeno: motor, automatika i LED rasveta. Montaža i transport su uračunati.</div>` : ''}
        ${glassNoteHtml(doc.items)}
        <div style="margin-top:14px;padding-top:10px;border-top:1px solid #ddd;">
            <div style="text-align:right;font-size:14px;">Međuzbir: ${fmt(doc.subtotal)} ${val}</div>
            ${parseFloat(doc.disc_amount) > 0 ? `<div style="text-align:right;color:#e74c3c;font-size:14px;">Popust: -${fmt(doc.disc_amount)} ${val}</div>` : ''}
            ${pdvBlock}
        </div>
        <div class="total-line">${totalLabel(doc)}: ${fmt(doc.total)} ${val}</div>
        ${doc.rok ? `<div style="margin-top:12px;font-size:13px;"><strong>Rok realizacije:</strong> ${esc(doc.rok)}</div>` : ''}
        ${doc.napomena ? `<div style="margin-top:8px;font-size:13px;"><strong>Napomena:</strong> ${esc(doc.napomena)}</div>` : ''}
        ${isRacun && SETTINGS.firma_ziro ? `<div class="info-note"><strong>Podaci za uplatu:</strong><br>Tekući račun: ${esc(SETTINGS.firma_ziro)}${SETTINGS.firma_banka ? ' (' + esc(SETTINGS.firma_banka) + ')' : ''}<br>Poziv na broj: ${esc(doc.oznaka)}</div>` : ''}
        ${pdvNote}
        <div class="footer-note">
            ${esc(SETTINGS.firma_naziv)} | Tel: ${esc(SETTINGS.kontakt_tel)} - ${esc(SETTINGS.kontakt_ime)}<br>
            ${doc.type === 'ponuda' ? `Ponuda važi ${esc(SETTINGS.ponuda_vazi_dana || '7')} dana od datuma izdavanja.` : ''}
        </div>`;
}

function glassNoteHtml(items) {
    const tips = items.filter(i => i.kind === 'staklo').map(i => i.meta?.tip);
    if (!tips.length) return '';
    const hasS = tips.includes('Slajding'), hasG = tips.includes('Giljotina');
    let n = '';
    if (hasS && hasG) n = 'Slajding i giljotina sistemi: duplo staklo 4+14+4mm. Giljotina: motor uključen u cenu.';
    else if (hasS) n = 'Slajding sistem: duplo staklo 4+14+4mm.';
    else if (hasG) n = 'Giljotina sistem: duplo staklo 4+14+4mm, motor uključen u cenu.';
    return n ? `<div class="info-note">${n} Montaža i transport su uračunati.</div>` : '';
}

// ---------- Kopiraj tekst (WA/Viber) ----------
async function copyDocText() {
    const doc = currentDoc;
    const k = getClientInfo(doc);
    const val = doc.valuta;
    let t = `*${SETTINGS.firma_naziv}*\n${TYPE_LABEL[doc.type]} br. ${doc.oznaka} — ${fmtDate(doc.datum)}\n━━━━━━━━━━━━━━━━━━━━\n\n`;
    t += `*Klijent:* ${k.naziv}\n`;
    if (k.tel) t += `*Tel:* ${k.tel}\n`;
    t += `\n`;
    doc.items.forEach((it, i) => {
        t += `${i+1}. ${it.naziv}\n`;
        if (it.opis) t += `   ${it.opis}\n`;
        t += `   *${parseFloat(it.iznos) < 0 ? '' : 'Cena: '}${fmt(it.iznos)} ${val}*\n\n`;
    });
    t += `━━━━━━━━━━━━━━━━━━━━\n`;
    t += `Međuzbir: ${fmt(doc.subtotal)} ${val}\n`;
    if (parseFloat(doc.disc_amount) > 0) t += `Popust: -${fmt(doc.disc_amount)} ${val}\n`;
    if (doc.pdv_mode === 'standard' && parseFloat(doc.pdv_amount) > 0) {
        t += `Osnovica: ${fmt(parseFloat(doc.total) - parseFloat(doc.pdv_amount))} ${val}\n`;
        t += `PDV (${parseFloat(doc.pdv_rate)}%): ${fmt(doc.pdv_amount)} ${val}\n`;
    }
    t += `\n*${totalLabel(doc)}: ${fmt(doc.total)} ${val}*\n\n`;
    if (doc.pdv_mode === 'cl10' && doc.pdv_napomena) t += `${doc.pdv_napomena}\n\n`;
    if (doc.rok) t += `Rok realizacije: ${doc.rok}\n`;
    if (doc.napomena) t += `Napomena: ${doc.napomena}\n`;
    if (doc.type === 'ponuda') t += `\nPonuda važi ${SETTINGS.ponuda_vazi_dana || 7} dana.\n`;
    t += `${SETTINGS.firma_naziv} | Tel: ${SETTINGS.kontakt_tel} - ${SETTINGS.kontakt_ime}`;
    try { await navigator.clipboard.writeText(t); showToast('Tekst kopiran!'); }
    catch { showToast('Greška pri kopiranju', true); }
}

// ---------- PDF ----------
function downloadDocPDF() {
    const doc = currentDoc;
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('p', 'mm', 'a4');
    pdf.addFileToVFS('DejaVu.ttf', window.PDF_FONT_NORMAL);
    pdf.addFont('DejaVu.ttf', 'DejaVu', 'normal');
    pdf.addFileToVFS('DejaVuB.ttf', window.PDF_FONT_BOLD);
    pdf.addFont('DejaVuB.ttf', 'DejaVu', 'bold');

    const W = 210, M = 15, CW = W - 2 * M;
    let y = 15;
    const k = getClientInfo(doc);
    const val = doc.valuta;
    const isRacun = doc.type !== 'ponuda';

    const ckPage = n => { if (y + n > 280) { pdf.addPage(); y = 15; } };
    const line = yy => { pdf.setDrawColor(212,165,116); pdf.setLineWidth(0.5); pdf.line(M, yy, W-M, yy); };

    // Header
    pdf.setFillColor(26,26,46); pdf.rect(0, 0, W, 36, 'F');
    pdf.setTextColor(212,165,116); pdf.setFontSize(19); pdf.setFont('DejaVu','bold');
    pdf.text(SETTINGS.firma_naziv, W/2, 14, { align:'center' });
    pdf.setFontSize(9); pdf.setFont('DejaVu','normal'); pdf.setTextColor(170,170,187);
    pdf.text(SETTINGS.firma_podnaslov || '', W/2, 21, { align:'center' });
    const l3 = [SETTINGS.firma_adresa && (SETTINGS.firma_adresa + ', ' + SETTINGS.firma_mesto),
                SETTINGS.firma_pib && ('PIB: ' + SETTINGS.firma_pib), SETTINGS.firma_mb && ('MB: ' + SETTINGS.firma_mb)]
                .filter(Boolean).join('  |  ');
    if (l3) pdf.text(l3, W/2, 27, { align:'center' });
    pdf.text(`Tel: ${SETTINGS.kontakt_tel} - ${SETTINGS.kontakt_ime}`, W/2, l3 ? 32 : 27, { align:'center' });
    y = 44;

    pdf.setTextColor(50,50,50); pdf.setFontSize(14); pdf.setFont('DejaVu','bold');
    pdf.text(TYPE_LABEL[doc.type].toUpperCase() + ' ' + doc.oznaka + (doc.status === 'storniran' ? ' — STORNIRAN' : ''), M, y);
    pdf.setFontSize(10); pdf.setFont('DejaVu','normal'); pdf.setTextColor(100,100,100);
    pdf.text('Datum: ' + fmtDate(doc.datum), W-M, y, { align:'right' });
    y += 7; line(y); y += 8;

    pdf.setFontSize(11); pdf.setTextColor(50,50,50); pdf.setFont('DejaVu','bold');
    pdf.text('Klijent:', M, y); y += 6;
    pdf.setFont('DejaVu','normal'); pdf.setFontSize(10);
    const kl = [k.naziv + (k.tip === 'pravno' ? ' (pravno lice)' : '')];
    if (k.adresa) kl.push(k.adresa);
    if (k.tel) kl.push('Tel: ' + k.tel);
    if (k.pib) kl.push('PIB: ' + k.pib + '   MB: ' + k.mb);
    kl.forEach(l => { pdf.text(l, M, y); y += 5.5; });
    y += 4;

    // Items table
    ckPage(30);
    pdf.setFillColor(26,26,46); pdf.rect(M, y-4, CW, 8, 'F');
    pdf.setTextColor(212,165,116); pdf.setFontSize(10); pdf.setFont('DejaVu','bold');
    pdf.text('SPECIFIKACIJA', M+4, y+1); y += 9;
    pdf.setFillColor(240,240,240); pdf.rect(M, y-4, CW, 7, 'F');
    pdf.setTextColor(80,80,80); pdf.setFontSize(8);
    pdf.text('#', M+2, y); pdf.text('Opis', M+10, y); pdf.text('Iznos', M+CW-3, y, { align:'right' });
    y += 6;
    pdf.setFont('DejaVu','normal'); pdf.setFontSize(9); pdf.setTextColor(50,50,50);

    doc.items.forEach((it, i) => {
        ckPage(14);
        pdf.text(String(i+1), M+2, y);
        const nameLines = pdf.splitTextToSize(it.naziv, CW - 50);
        pdf.text(nameLines, M+10, y);
        pdf.setFont('DejaVu','bold');
        if (parseFloat(it.iznos) < 0) pdf.setTextColor(200,60,50);
        pdf.text(`${fmt(it.iznos)} ${val}`, M+CW-3, y, { align:'right' });
        pdf.setTextColor(50,50,50);
        pdf.setFont('DejaVu','normal');
        y += nameLines.length * 4.5;
        if (it.opis) {
            pdf.setFontSize(7.5); pdf.setTextColor(130,130,130);
            const ol = pdf.splitTextToSize(it.opis, CW - 50);
            pdf.text(ol, M+10, y);
            y += ol.length * 4;
            pdf.setFontSize(9); pdf.setTextColor(50,50,50);
        }
        y += 3;
        pdf.setDrawColor(230,230,230); pdf.setLineWidth(0.2);
        pdf.line(M, y-2, W-M, y-2);
    });
    y += 4;

    // Totals
    ckPage(45);
    pdf.setFontSize(10); pdf.setTextColor(80,80,80);
    pdf.text('Međuzbir:', M, y);
    pdf.text(`${fmt(doc.subtotal)} ${val}`, W-M, y, { align:'right' }); y += 6;
    if (parseFloat(doc.disc_amount) > 0) {
        pdf.setTextColor(231,76,60);
        pdf.text('Popust:', M, y);
        pdf.text(`-${fmt(doc.disc_amount)} ${val}`, W-M, y, { align:'right' }); y += 6;
        pdf.setTextColor(80,80,80);
    }
    if (doc.pdv_mode === 'standard' && parseFloat(doc.pdv_amount) > 0) {
        const osn = parseFloat(doc.total) - parseFloat(doc.pdv_amount);
        pdf.text('Osnovica:', M, y);
        pdf.text(`${fmt(osn)} ${val}`, W-M, y, { align:'right' }); y += 6;
        pdf.text(`PDV (${parseFloat(doc.pdv_rate)}%):`, M, y);
        pdf.text(`${fmt(doc.pdv_amount)} ${val}`, W-M, y, { align:'right' }); y += 6;
    }
    y += 2;
    pdf.setFillColor(26,26,46); pdf.rect(M, y-5, CW, 12, 'F');
    pdf.setTextColor(212,165,116); pdf.setFontSize(13); pdf.setFont('DejaVu','bold');
    pdf.text(totalLabel(doc) + ':', M+4, y+2.5);
    pdf.text(`${fmt(doc.total)} ${val}`, W-M-4, y+2.5, { align:'right' });
    y += 15;

    pdf.setFont('DejaVu','normal'); pdf.setFontSize(9.5); pdf.setTextColor(50,50,50);
    if (doc.pdv_mode === 'cl10' && doc.pdv_napomena) {
        ckPage(18);
        const cl = pdf.splitTextToSize(doc.pdv_napomena, CW - 6);
        pdf.setFillColor(253,246,232); pdf.rect(M, y-3, CW, cl.length * 4 + 6, 'F');
        pdf.setFontSize(8.5); pdf.setTextColor(150,110,30);
        pdf.text(cl, M+3, y+2);
        y += cl.length * 4 + 8;
        pdf.setFontSize(9.5); pdf.setTextColor(50,50,50);
    }
    if (doc.rok) { ckPage(8); pdf.setFont('DejaVu','bold'); pdf.text('Rok realizacije: ', M, y); pdf.setFont('DejaVu','normal'); pdf.text(doc.rok, M+33, y); y += 7; }
    if (doc.napomena) {
        ckPage(16); pdf.setFont('DejaVu','bold'); pdf.text('Napomena:', M, y); pdf.setFont('DejaVu','normal');
        const nl = pdf.splitTextToSize(doc.napomena, CW - 28);
        pdf.text(nl, M+26, y); y += nl.length * 5 + 3;
    }
    if (isRacun && SETTINGS.firma_ziro) {
        ckPage(20);
        pdf.setFillColor(248,245,240); pdf.rect(M, y-3, CW, 17, 'F');
        pdf.setFontSize(8.5); pdf.setTextColor(80,80,80);
        pdf.setFont('DejaVu','bold'); pdf.text('Podaci za uplatu:', M+3, y+1); pdf.setFont('DejaVu','normal');
        pdf.text(`Tekući račun: ${SETTINGS.firma_ziro}${SETTINGS.firma_banka ? ' (' + SETTINGS.firma_banka + ')' : ''}`, M+3, y+6);
        pdf.text(`Poziv na broj: ${doc.oznaka}`, M+3, y+11);
        y += 20;
    }
    if (isRacun && doc.pdv_mode === 'none' && doc.pdv_napomena) {
        ckPage(12);
        pdf.setFontSize(7.5); pdf.setTextColor(120,120,120);
        const pl = pdf.splitTextToSize(doc.pdv_napomena, CW);
        pdf.text(pl, M, y); y += pl.length * 4 + 3;
    }

    // Footer
    y = Math.max(y + 8, 262); ckPage(18);
    line(y); y += 5;
    pdf.setFontSize(8); pdf.setTextColor(130,130,130); pdf.setFont('DejaVu','normal');
    pdf.text(`${SETTINGS.firma_naziv} | Tel: ${SETTINGS.kontakt_tel} - ${SETTINGS.kontakt_ime}`, W/2, y, { align:'center' }); y += 4;
    if (doc.type === 'ponuda') pdf.text(`Ponuda važi ${SETTINGS.ponuda_vazi_dana || 7} dana od datuma izdavanja.`, W/2, y, { align:'center' });

    pdf.save(`${doc.oznaka}_${k.naziv.replace(/\s+/g,'_')}.pdf`);
    showToast('PDF preuzet!');
}

// ============================================
// UPLATE
// ============================================
function openPaymentForm() {
    document.getElementById('pay-job-id').value = currentJob.id;
    document.getElementById('pay-datum').value = new Date().toISOString().split('T')[0];
    document.getElementById('pay-iznos').value = '';
    document.getElementById('pay-napomena').value = '';
    document.getElementById('pay-v-eur').checked = true;
    document.getElementById('pay-kurs').value = SETTINGS.kurs_eur || '117.2';
    payToggleKurs();
    openOverlay('payment-overlay');
}
function payToggleKurs() {
    const rsd = document.querySelector('input[name="pay-valuta"]:checked').value === 'RSD';
    document.getElementById('pay-kurs-group').style.display = rsd ? 'block' : 'none';
}
async function savePayment() {
    const jobId = parseInt(document.getElementById('pay-job-id').value);
    const valuta = document.querySelector('input[name="pay-valuta"]:checked').value;
    const r = await api('payment_save', {
        job_id: jobId,
        datum: document.getElementById('pay-datum').value,
        iznos: parseFloat(document.getElementById('pay-iznos').value) || 0,
        valuta,
        kurs: valuta === 'RSD' ? (parseFloat(document.getElementById('pay-kurs').value) || 0) : 1,
        nacin: document.getElementById('pay-nacin').value,
        napomena: document.getElementById('pay-napomena').value,
    });
    if (r.error) { showToast(r.error, true); return; }
    closeOverlay('payment-overlay');
    showToast('✓ Uplata zabeležena');
    openJob(jobId);
}
async function deletePayment(id) {
    if (!confirm('Obrisati uplatu?')) return;
    const r = await api('payment_delete', { id });
    if (r.error) { showToast(r.error, true); return; }
    openJob(currentJob.id);
}

// ============================================
// KLIJENTI
// ============================================
let clientsDebounce = null;
function debounceLoadClients() { clearTimeout(clientsDebounce); clientsDebounce = setTimeout(renderClients, 300); }

async function loadClients() {
    CLIENTS = await apiGet('clients_list');
    fillClientSelect();
}
function fillClientSelect() {
    const sel = document.getElementById('np-client');
    const cur = sel.value;
    sel.innerHTML = '<option value="">— izaberi klijenta —</option>' +
        CLIENTS.map(c => `<option value="${c.id}">${esc(c.naziv)}${c.mesto ? ' (' + esc(c.mesto) + ')' : ''}</option>`).join('');
    if (cur) sel.value = cur;
}
async function renderClients() {
    const q = document.getElementById('clients-search').value.trim();
    const list = q ? await apiGet('clients_list', { q }) : (CLIENTS.length ? CLIENTS : await apiGet('clients_list'));
    if (!q) CLIENTS = list;
    document.getElementById('clients-list').innerHTML = list.map(c => {
        const dug = parseFloat(c.dug_eur) || 0;
        const stanje = c.broj_poslova > 0
            ? (dug > 0.005 ? `duguje <strong style="color:var(--warning);">${fmt(dug)} €</strong>` : '<strong style="color:var(--success);">izmireno ✓</strong>')
            : '';
        return `
        <div class="list-card" onclick="openClientForm(${c.id}, false)">
            <div class="lc-head">
                <div>
                    <div class="lc-title">${esc(c.naziv)}</div>
                    <div class="lc-meta">
                        ${c.telefon ? '📞 ' + esc(c.telefon) : ''}${c.mesto ? ' · 📍 ' + esc(c.mesto) : ''}
                        ${c.tip === 'pravno' ? `<br>PIB: ${esc(c.pib)} · MB: ${esc(c.mb)}` : ''}
                        ${c.broj_poslova > 0 ? `<br>${c.broj_poslova} ${c.broj_poslova === 1 ? 'posao' : 'posla'} · ${stanje}` : ''}
                    </div>
                </div>
                <span class="badge ${c.tip === 'pravno' ? (c.pdv_mode === 'cl10' ? 'badge-orange' : 'badge-blue') : 'badge-gold'}">
                    ${c.tip === 'pravno' ? (c.pdv_mode === 'cl10' ? 'Čl. 10' : 'Pravno lice') : 'Fizičko lice'}</span>
            </div>
        </div>`;
    }).join('') || '<div class="empty-state"><div class="empty-icon">👥</div>Nema klijenata.</div>';
}

let clientFormReturnToOffer = false;
function openClientForm(id, returnToOffer) {
    clientFormReturnToOffer = !!returnToOffer;
    const c = id ? CLIENTS.find(x => x.id == id) : null;
    document.getElementById('client-form-title').textContent = c ? 'Izmena klijenta' : 'Novi klijent';
    document.getElementById('cf-id').value = c ? c.id : '';
    document.getElementById(c && c.tip === 'pravno' ? 'cf-tip-p' : 'cf-tip-f').checked = true;
    document.getElementById(c && c.pdv_mode === 'cl10' ? 'cf-pdv-c10' : 'cf-pdv-std').checked = true;
    document.getElementById('cf-naziv').value = c ? c.naziv : '';
    document.getElementById('cf-telefon').value = c ? c.telefon : '';
    document.getElementById('cf-email').value = c ? c.email : '';
    document.getElementById('cf-adresa').value = c ? c.adresa : '';
    document.getElementById('cf-mesto').value = c ? c.mesto : '';
    document.getElementById('cf-pib').value = c ? c.pib : '';
    document.getElementById('cf-mb').value = c ? c.mb : '';
    document.getElementById('cf-napomena').value = c ? (c.napomena || '') : '';
    toggleClientFields();
    openOverlay('client-overlay');
}
function toggleClientFields() {
    const pravno = document.querySelector('input[name="cf-tip"]:checked').value === 'pravno';
    document.getElementById('cf-pravno').style.display = pravno ? 'block' : 'none';
    document.getElementById('cf-naziv-label').textContent = pravno ? 'Naziv firme' : 'Ime i prezime';
}
async function saveClient() {
    const payload = {
        id: document.getElementById('cf-id').value || null,
        tip: document.querySelector('input[name="cf-tip"]:checked').value,
        pdv_mode: document.querySelector('input[name="cf-pdv"]:checked').value,
        naziv: document.getElementById('cf-naziv').value,
        telefon: document.getElementById('cf-telefon').value,
        email: document.getElementById('cf-email').value,
        adresa: document.getElementById('cf-adresa').value,
        mesto: document.getElementById('cf-mesto').value,
        pib: document.getElementById('cf-pib').value,
        mb: document.getElementById('cf-mb').value,
        napomena: document.getElementById('cf-napomena').value,
    };
    const r = await api('client_save', payload);
    if (r.error) { showToast(r.error, true); return; }
    closeOverlay('client-overlay');
    showToast('✓ Klijent sačuvan');
    await loadClients();
    renderClients();
    if (clientFormReturnToOffer) document.getElementById('np-client').value = r.id;
}

// ============================================
// PODESAVANJA
// ============================================
const SETTING_KEYS = ['firma_naziv','firma_podnaslov','firma_adresa','firma_mesto','firma_pib','firma_mb',
    'firma_ziro','firma_banka','kontakt_ime','kontakt_tel','kontakt_email','pdv_rate','pdv_napomena',
    'pdv_cl10_napomena','kurs_eur','ponuda_vazi_dana'];

function fillSettingsForm() {
    SETTING_KEYS.forEach(k => {
        const el = document.getElementById('s-' + k);
        if (el) el.value = SETTINGS[k] || '';
    });
    document.getElementById(SETTINGS.pdv_enabled === '1' ? 's-pdv-da' : 's-pdv-ne').checked = true;
}

async function changePassword() {
    const cur  = document.getElementById('s-pass-current').value;
    const np   = document.getElementById('s-pass-new').value;
    const np2  = document.getElementById('s-pass-new2').value;
    if (!cur)  { showToast('Unesi trenutnu lozinku', true); return; }
    if (!np)   { showToast('Unesi novu lozinku', true); return; }
    if (np !== np2) { showToast('Nove lozinke se ne poklapaju', true); return; }
    const r = await api('password_change', { current_pass: cur, new_pass: np });
    if (r.error) { showToast(r.error, true); return; }
    document.getElementById('s-pass-current').value = '';
    document.getElementById('s-pass-new').value = '';
    document.getElementById('s-pass-new2').value = '';
    showToast('✓ Lozinka promenjena');
}

async function saveSettings() {
    const payload = {};
    SETTING_KEYS.forEach(k => {
        const el = document.getElementById('s-' + k);
        if (el) payload[k] = el.value;
    });
    payload.pdv_enabled = document.querySelector('input[name="s-pdv"]:checked').value;
    const r = await api('settings_save', payload);
    if (r.error) { showToast(r.error, true); return; }
    Object.assign(SETTINGS, payload);
    showToast('✓ Podešavanja sačuvana');
}
