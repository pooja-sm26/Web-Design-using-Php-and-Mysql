<?php
/**
 * doctor_dashboard.php — Doctor Portal
 * Doctors can: select their profile, accept pending cases,
 * mark appointments as viewed, and mark as diagnosed.
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

// Load all available doctors for the selector
$doctors = [];
try {
    $doctors = $pdo->query(
        'SELECT id, name, specialty FROM doctors WHERE available = 1 ORDER BY specialty, name'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

function get_initials(string $name): string {
    $parts = explode(' ', $name);
    $r = '';
    foreach ($parts as $p) {
        if (!empty($p) && ctype_alpha($p[0])) { $r .= strtoupper($p[0]); if (strlen($r) >= 2) break; }
    }
    return $r ?: 'DR';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>MediBook — Doctor Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link rel="stylesheet" href="style.css"/>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🩺</text></svg>"/>
  <style>
    /* ── Dashboard-specific styles ─────────────────────────── */
    .dash-layout {
      max-width: 1200px;
      margin: 0 auto;
      padding: 2.5rem 2rem 5rem;
      position: relative;
      z-index: 1;
    }

    /* Doctor selector strip */
    .doc-selector-wrap {
      background: var(--white);
      border: 1px solid rgba(201,168,76,.15);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      padding: 1.75rem 2rem;
      margin-bottom: 2rem;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
    }
    .doc-selector-wrap label {
      font-size: .75rem;
      font-weight: 600;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--navy-mid);
      white-space: nowrap;
    }
    .doc-select {
      flex: 1;
      min-width: 220px;
      max-width: 340px;
      font-size: .95rem;
      padding: .7rem 2.4rem .7rem 1rem;
      border: 1.5px solid var(--ivory-dark);
      border-radius: var(--radius-sm);
      background: var(--ivory);
      color: var(--text-main);
      cursor: pointer;
      font-family: 'DM Sans', sans-serif;
    }
    .doc-select:focus { border-color: var(--gold); outline: none; box-shadow: 0 0 0 3px rgba(201,168,76,.15); }
    .doc-active-badge {
      display: none;
      align-items: center;
      gap: .6rem;
      background: linear-gradient(135deg, var(--navy), var(--navy-light));
      color: var(--gold-light);
      border-radius: 100px;
      padding: .45rem 1.2rem .45rem .7rem;
      font-size: .82rem;
      font-weight: 500;
    }
    .doc-active-badge.show { display: flex; }
    .doc-avatar-sm {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: rgba(201,168,76,.25);
      display: grid; place-items: center;
      font-size: .78rem; font-weight: 700;
      color: var(--gold-light);
      font-family: 'Cormorant Garamond', serif;
    }

    /* Stat cards row */
    .stat-cards {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1rem;
      margin-bottom: 2rem;
    }
    .stat-card {
      background: var(--white);
      border-radius: var(--radius-md);
      border: 1px solid rgba(201,168,76,.1);
      box-shadow: var(--shadow-sm);
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .stat-icon {
      width: 44px; height: 44px;
      border-radius: var(--radius-sm);
      display: grid; place-items: center;
      font-size: 1.3rem;
      flex-shrink: 0;
    }
    .stat-icon.pending  { background: #fef3c7; }
    .stat-icon.accepted { background: #d1fae5; }
    .stat-icon.viewed   { background: #dbeafe; }
    .stat-icon.diagnosed{ background: #ede9fe; }
    .stat-info {}
    .stat-num  { font-size: 1.65rem; font-weight: 600; color: var(--navy); line-height: 1; font-family: 'Cormorant Garamond', serif; }
    .stat-lbl  { font-size: .72rem; color: var(--text-mute); font-weight: 500; margin-top: .2rem; letter-spacing: .05em; text-transform: uppercase; }

    /* Tab bar */
    .tab-bar {
      display: flex;
      gap: 4px;
      margin-bottom: 1.5rem;
      border-bottom: 2px solid var(--ivory-dark);
    }
    .tab-btn {
      padding: .7rem 1.4rem;
      font-size: .85rem;
      font-weight: 600;
      border: none;
      background: none;
      cursor: pointer;
      color: var(--text-mute);
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
      display: flex;
      align-items: center;
      gap: .5rem;
      font-family: 'DM Sans', sans-serif;
      transition: var(--trans);
    }
    .tab-btn:hover { color: var(--navy); }
    .tab-btn.active { color: var(--navy); border-bottom-color: var(--gold); }
    .tab-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px; height: 20px;
      border-radius: 10px;
      font-size: .68rem;
      font-weight: 700;
      padding: 0 5px;
    }
    .tab-btn.active .tab-count,
    .tab-btn:hover .tab-count { background: var(--navy); color: var(--gold-light); }
    .tab-count { background: var(--ivory-dark); color: var(--text-mute); }

    /* Appointment cards grid */
    .appt-grid {
      display: flex;
      flex-direction: column;
      gap: .75rem;
    }

    .appt-row {
      background: var(--white);
      border: 1px solid var(--ivory-dark);
      border-left: 4px solid transparent;
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-sm);
      padding: 1.25rem 1.5rem;
      display: grid;
      grid-template-columns: 3rem 1fr auto;
      gap: 1rem;
      align-items: center;
      transition: box-shadow var(--trans), border-color var(--trans);
    }
    .appt-row:hover { box-shadow: var(--shadow-md); }
    .appt-row.pending  { border-left-color: #f59e0b; }
    .appt-row.accepted { border-left-color: #10b981; }
    .appt-row.viewed   { border-left-color: #3b82f6; }
    .appt-row.diagnosed{ border-left-color: #8b5cf6; }

    .appt-num {
      width: 36px; height: 36px;
      border-radius: 50%;
      background: var(--ivory);
      border: 1.5px solid var(--ivory-dark);
      display: grid; place-items: center;
      font-size: .75rem; font-weight: 700;
      color: var(--text-mute);
      flex-shrink: 0;
    }

    .appt-body { min-width: 0; }
    .appt-name {
      font-size: 1rem;
      font-weight: 600;
      color: var(--navy);
      margin-bottom: .25rem;
      display: flex;
      align-items: center;
      gap: .6rem;
      flex-wrap: wrap;
    }
    .appt-meta {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem 1.2rem;
      font-size: .78rem;
      color: var(--text-sub);
    }
    .appt-meta span { display: flex; align-items: center; gap: .3rem; }
    .appt-reason {
      margin-top: .5rem;
      font-size: .82rem;
      color: var(--text-sub);
      background: var(--ivory);
      border-radius: var(--radius-sm);
      padding: .45rem .75rem;
      border-left: 2px solid var(--gold-pale);
      font-style: italic;
    }
    .appt-reason.empty { color: var(--text-mute); font-style: normal; }

    /* Action button cluster */
    .appt-actions {
      display: flex;
      flex-direction: column;
      gap: .4rem;
      align-items: flex-end;
      flex-shrink: 0;
    }

    .act-btn {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .42rem 1rem;
      border-radius: 100px;
      font-size: .78rem;
      font-weight: 600;
      border: 1.5px solid transparent;
      cursor: pointer;
      font-family: 'DM Sans', sans-serif;
      transition: var(--trans);
      white-space: nowrap;
    }
    .act-btn:disabled { opacity: .45; cursor: not-allowed; transform: none !important; }

    .act-btn.accept {
      background: #ecfdf5; border-color: #6ee7b7; color: #065f46;
    }
    .act-btn.accept:hover:not(:disabled) {
      background: #d1fae5; border-color: #10b981;
      transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,.2);
    }
    .act-btn.view {
      background: #eff6ff; border-color: #93c5fd; color: #1d4ed8;
    }
    .act-btn.view:hover:not(:disabled) {
      background: #dbeafe; border-color: #3b82f6;
      transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,.2);
    }
    .act-btn.diagnose {
      background: #f5f3ff; border-color: #c4b5fd; color: #6d28d9;
    }
    .act-btn.diagnose:hover:not(:disabled) {
      background: #ede9fe; border-color: #8b5cf6;
      transform: translateY(-1px); box-shadow: 0 4px 12px rgba(139,92,246,.2);
    }
    .act-btn.cancel {
      background: #fff1f2; border-color: #fda4af; color: #9f1239;
    }
    .act-btn.cancel:hover:not(:disabled) {
      background: #ffe4e6; border-color: #fb7185;
      transform: translateY(-1px);
    }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: var(--text-mute);
    }
    .empty-state .icon { font-size: 3rem; margin-bottom: .75rem; display: block; }
    .empty-state h3 { font-size: 1.1rem; font-weight: 500; color: var(--text-sub); margin-bottom: .4rem; }
    .empty-state p  { font-size: .88rem; }

    /* Loading skeleton */
    .skeleton-row {
      height: 88px;
      background: linear-gradient(90deg, var(--ivory) 25%, var(--ivory-dark) 50%, var(--ivory) 75%);
      background-size: 200% 100%;
      animation: shimmer 1.4s infinite;
      border-radius: var(--radius-md);
    }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

    /* Toast notification */
    .toast-stack {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: .5rem;
      z-index: 9999;
    }
    .toast-msg {
      display: flex;
      align-items: center;
      gap: .75rem;
      background: var(--navy);
      color: var(--white);
      padding: .85rem 1.25rem;
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-lg);
      font-size: .88rem;
      font-weight: 500;
      min-width: 260px;
      max-width: 360px;
      animation: slideIn .3s ease both;
      border-left: 3px solid var(--gold);
    }
    .toast-msg.success { border-left-color: #10b981; }
    .toast-msg.error   { border-left-color: #f43f5e; }
    @keyframes slideIn { from{opacity:0;transform:translateX(24px)} to{opacity:1;transform:translateX(0)} }

    /* No-doctor overlay */
    .no-doctor-overlay {
      text-align: center;
      padding: 4rem 2rem;
      background: var(--white);
      border-radius: var(--radius-lg);
      border: 1px dashed var(--ivory-dark);
      box-shadow: var(--shadow-sm);
    }
    .no-doctor-overlay .icon { font-size: 3.5rem; display: block; margin-bottom: 1rem; }
    .no-doctor-overlay h3 {
      font-family: 'Cormorant Garamond', serif;
      font-size: 1.6rem;
      font-weight: 400;
      color: var(--navy);
      margin-bottom: .5rem;
    }
    .no-doctor-overlay p { color: var(--text-mute); font-size: .9rem; }

    /* Responsive */
    @media (max-width: 768px) {
      .stat-cards { grid-template-columns: 1fr 1fr; }
      .appt-row   { grid-template-columns: 1fr; }
      .appt-num   { display: none; }
      .appt-actions { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
      .doc-selector-wrap { gap: .75rem; }
    }
    @media (max-width: 480px) {
      .stat-cards { grid-template-columns: 1fr 1fr; }
      .dash-layout { padding: 1.5rem 1rem 4rem; }
    }
  </style>
</head>
<body>

<!-- ═══ HEADER ════════════════════════════════════════════════ -->
<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="logo">
      <div class="logo-icon">🏥</div>
      <div class="logo-text">
        <span>MediBook</span>
        <span>Appointment Portal</span>
      </div>
    </a>
    <div style="display:flex;align-items:center;gap:1rem">
      <a href="index.php" style="color:rgba(255,255,255,.55);font-size:.8rem;text-decoration:none;font-weight:500;letter-spacing:.06em;transition:color .2s"
         onmouseover="this.style.color='var(--gold-light)'" onmouseout="this.style.color='rgba(255,255,255,.55)'">
        ← Patient Booking
      </a>
      <div class="header-badge">
        <span class="pulse-dot"></span>
        Doctor Portal
      </div>
    </div>
  </div>
</header>

<!-- ═══ HERO (compact) ═══════════════════════════════════════ -->
<section class="hero" style="padding:3rem 2rem 2.5rem">
  <div class="hero-tag">🩺 Doctor Dashboard</div>
  <h1 style="font-size:clamp(1.8rem,4vw,2.8rem)">Your <em>Patient</em> Queue</h1>
  <p style="font-size:.95rem;margin-bottom:0">Accept incoming appointments, mark cases as viewed, and record diagnoses — all in one place.</p>
</section>

<!-- ═══ MAIN ══════════════════════════════════════════════════ -->
<main class="dash-layout">

  <!-- Doctor selector -->
  <div class="doc-selector-wrap">
    <label for="doc-pick">Logged in as</label>
    <select id="doc-pick" class="doc-select" onchange="onDoctorChange(this)">
      <option value="">— Select your profile —</option>
      <?php foreach ($doctors as $d): ?>
      <option value="<?= (int)$d['id'] ?>"
              data-name="<?= htmlspecialchars($d['name']) ?>"
              data-init="<?= get_initials($d['name']) ?>"
              data-spec="<?= htmlspecialchars($d['specialty']) ?>">
        <?= htmlspecialchars($d['name']) ?> — <?= htmlspecialchars($d['specialty']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <div class="doc-active-badge" id="doc-badge">
      <div class="doc-avatar-sm" id="doc-init">DR</div>
      <span id="doc-label">—</span>
    </div>
  </div>

  <!-- Stats row -->
  <div class="stat-cards" id="stat-cards">
    <div class="stat-card"><div class="stat-icon pending">⏳</div><div class="stat-info"><div class="stat-num" id="cnt-pending">—</div><div class="stat-lbl">Pending</div></div></div>
    <div class="stat-card"><div class="stat-icon accepted">✅</div><div class="stat-info"><div class="stat-num" id="cnt-accepted">—</div><div class="stat-lbl">Accepted</div></div></div>
    <div class="stat-card"><div class="stat-icon viewed">👁</div><div class="stat-info"><div class="stat-num" id="cnt-viewed">—</div><div class="stat-lbl">Viewed</div></div></div>
    <div class="stat-card"><div class="stat-icon diagnosed">🔬</div><div class="stat-info"><div class="stat-num" id="cnt-diagnosed">—</div><div class="stat-lbl">Diagnosed</div></div></div>
  </div>

  <!-- Tab bar -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="pending"   onclick="switchTab('pending',this)">⏳ Pending   <span class="tab-count" id="tc-pending">0</span></button>
    <button class="tab-btn"        data-tab="confirmed" onclick="switchTab('confirmed',this)">✅ Accepted  <span class="tab-count" id="tc-confirmed">0</span></button>
    <button class="tab-btn"        data-tab="viewed"    onclick="switchTab('viewed',this)">👁 Viewed    <span class="tab-count" id="tc-viewed">0</span></button>
    <button class="tab-btn"        data-tab="diagnosed" onclick="switchTab('diagnosed',this)">🔬 Diagnosed <span class="tab-count" id="tc-diagnosed">0</span></button>
    <button class="tab-btn"        data-tab="all"       onclick="switchTab('all',this)">📋 All       <span class="tab-count" id="tc-all">0</span></button>
  </div>

  <!-- Appointment list -->
  <div class="appt-grid" id="appt-grid">
    <div class="no-doctor-overlay">
      <span class="icon">🩺</span>
      <h3>Select your doctor profile</h3>
      <p>Use the dropdown above to load your patient queue.</p>
    </div>
  </div>

</main>

<!-- ═══ FOOTER ════════════════════════════════════════════════ -->
<footer class="site-footer">
  <p>© <?= date('Y') ?> <span>MediBook</span> — Doctor Dashboard &nbsp;|&nbsp; Built with PHP &amp; MySQL</p>
</footer>

<!-- Toast container -->
<div class="toast-stack" id="toast-stack"></div>

<!-- ═══ JAVASCRIPT ════════════════════════════════════════════ -->
<script>
let currentDoctorId  = null;
let currentTab       = 'pending';
let allAppointments  = [];

/* ── Utility ──────────────────────────────────────────────── */
function esc(s) {
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function fmtDate(d) {
  return new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtTime(t) {
  const [h, m] = t.split(':');
  return +h >= 12 ? `${(+h===12?12:+h-12).toString().padStart(2,'0')}:${m} PM`
                  : `${h}:${m} AM`;
}

/* ── Toast ────────────────────────────────────────────────── */
function toast(msg, type = 'success') {
  const icons = { success: '✓', error: '✕', info: 'ℹ' };
  const el = document.createElement('div');
  el.className = `toast-msg ${type}`;
  el.innerHTML = `<span style="font-size:1rem">${icons[type]||'ℹ'}</span><span>${esc(msg)}</span>`;
  document.getElementById('toast-stack').appendChild(el);
  setTimeout(() => { el.style.opacity='0'; el.style.transform='translateX(24px)'; el.style.transition='.3s ease'; setTimeout(()=>el.remove(),350); }, 3000);
}

/* ── Doctor change ────────────────────────────────────────── */
function onDoctorChange(sel) {
  const opt = sel.options[sel.selectedIndex];
  currentDoctorId = sel.value ? parseInt(sel.value) : null;

  const badge = document.getElementById('doc-badge');
  if (currentDoctorId) {
    document.getElementById('doc-init').textContent  = opt.dataset.init;
    document.getElementById('doc-label').textContent = opt.dataset.name + ' · ' + opt.dataset.spec;
    badge.classList.add('show');
    loadAppointments();
  } else {
    badge.classList.remove('show');
    resetCounts();
    document.getElementById('appt-grid').innerHTML = `
      <div class="no-doctor-overlay">
        <span class="icon">🩺</span>
        <h3>Select your doctor profile</h3>
        <p>Use the dropdown above to load your patient queue.</p>
      </div>`;
  }
}

/* ── Tab switch ───────────────────────────────────────────── */
function switchTab(tab, btn) {
  currentTab = tab;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderGrid();
}

/* ── Stats update ─────────────────────────────────────────── */
function updateCounts(appts) {
  const counts = { pending:0, confirmed:0, viewed:0, diagnosed:0, cancelled:0, completed:0 };
  appts.forEach(a => { if (counts[a.status] !== undefined) counts[a.status]++; });
  document.getElementById('cnt-pending').textContent   = counts.pending;
  document.getElementById('cnt-accepted').textContent  = counts.confirmed;
  document.getElementById('cnt-viewed').textContent    = counts.viewed;
  document.getElementById('cnt-diagnosed').textContent = counts.diagnosed;
  document.getElementById('tc-pending').textContent    = counts.pending;
  document.getElementById('tc-confirmed').textContent  = counts.confirmed;
  document.getElementById('tc-viewed').textContent     = counts.viewed;
  document.getElementById('tc-diagnosed').textContent  = counts.diagnosed;
  document.getElementById('tc-all').textContent        = appts.length;
}

function resetCounts() {
  ['cnt-pending','cnt-accepted','cnt-viewed','cnt-diagnosed',
   'tc-pending','tc-confirmed','tc-viewed','tc-diagnosed','tc-all']
    .forEach(id => document.getElementById(id).textContent = '—');
}

/* ── Load appointments from server ───────────────────────── */
function loadAppointments() {
  if (!currentDoctorId) return;

  const grid = document.getElementById('appt-grid');
  grid.innerHTML = ['','',''].map(()=>`<div class="skeleton-row"></div>`).join('');

  fetch(`process.php?action=doctor_appointments&doctor_id=${currentDoctorId}`)
    .then(r => r.json())
    .then(data => {
      if (data.status !== 'success') { toast('Failed to load appointments', 'error'); return; }
      allAppointments = data.appointments || [];
      updateCounts(allAppointments);
      renderGrid();
    })
    .catch(() => toast('Network error — could not load appointments.', 'error'));
}

/* ── Render filtered grid ─────────────────────────────────── */
function renderGrid() {
  const grid = document.getElementById('appt-grid');
  const list = currentTab === 'all'
    ? allAppointments
    : allAppointments.filter(a => a.status === currentTab);

  if (!list.length) {
    const labels = { pending:'pending cases', confirmed:'accepted cases', viewed:'viewed cases', diagnosed:'diagnosed cases', all:'appointments' };
    grid.innerHTML = `
      <div class="empty-state">
        <span class="icon">📭</span>
        <h3>No ${labels[currentTab] || 'records'}</h3>
        <p>${currentTab === 'pending' ? 'All caught up! No new appointments waiting.' : 'Nothing in this category yet.'}</p>
      </div>`;
    return;
  }

  grid.innerHTML = list.map((a, i) => {
    const reasonHtml = a.reason
      ? `<div class="appt-reason">💬 ${esc(a.reason)}</div>`
      : `<div class="appt-reason empty">No reason provided</div>`;

    const actions = buildActions(a);

    return `<div class="appt-row ${esc(a.status)}" id="appt-row-${a.id}">
      <div class="appt-num">${i+1}</div>
      <div class="appt-body">
        <div class="appt-name">
          ${esc(a.full_name)}
          <span class="badge badge-${esc(a.status)}">${esc(a.status)}</span>
        </div>
        <div class="appt-meta">
          <span>📅 ${fmtDate(a.appt_date)}</span>
          <span>🕐 ${fmtTime(a.appt_time)}</span>
          ${a.phone ? `<span>📞 ${esc(a.phone)}</span>` : ''}
          ${a.email ? `<span>✉ ${esc(a.email)}</span>` : ''}
        </div>
        ${reasonHtml}
      </div>
      <div class="appt-actions" id="actions-${a.id}">${actions}</div>
    </div>`;
  }).join('');
}

/* ── Build action buttons per status ─────────────────────── */
function buildActions(a) {
  const id = a.id;
  switch (a.status) {
    case 'pending':
      return `
        <button class="act-btn accept"   onclick="doAction(${id},'confirmed')">✅ Accept Case</button>
        <button class="act-btn diagnose" onclick="doAction(${id},'diagnosed')">🔬 Diagnose</button>
        <button class="act-btn cancel"   onclick="doAction(${id},'cancelled')">✕ Decline</button>`;
    case 'confirmed':
      return `
        <button class="act-btn view"     onclick="doAction(${id},'viewed')">👁 Mark Viewed</button>
        <button class="act-btn diagnose" onclick="doAction(${id},'diagnosed')">🔬 Diagnose</button>
        <button class="act-btn cancel"   onclick="doAction(${id},'cancelled')">✕ Cancel</button>`;
    case 'viewed':
      return `
        <button class="act-btn diagnose" onclick="doAction(${id},'diagnosed')">🔬 Mark Diagnosed</button>
        <button class="act-btn cancel"   onclick="doAction(${id},'cancelled')">✕ Cancel</button>`;
    case 'diagnosed':
      return `<span style="font-size:.78rem;color:var(--text-mute);padding:.4rem .8rem">🏁 Case closed</span>`;
    case 'cancelled':
      return `<span style="font-size:.78rem;color:var(--text-mute);padding:.4rem .8rem">🚫 Cancelled</span>`;
    default:
      return `<span style="font-size:.78rem;color:var(--text-mute)">—</span>`;
  }
}

/* ── Perform a status action ──────────────────────────────── */
async function doAction(id, newStatus) {
  // Disable all buttons in this row
  const actDiv = document.getElementById(`actions-${id}`);
  actDiv.querySelectorAll('.act-btn').forEach(b => { b.disabled = true; b.style.opacity='.45'; });

  const fd = new FormData();
  fd.append('appt_id', id);
  fd.append('status', newStatus);

  try {
    const res  = await fetch('process.php?action=update_status', { method:'POST', body:fd });
    const data = await res.json();

    if (data.status === 'success') {
      const labels = {
        confirmed: 'Case accepted ✅',
        viewed:    'Marked as viewed 👁',
        diagnosed: 'Case diagnosed 🔬',
        cancelled: 'Appointment cancelled',
      };
      toast(labels[newStatus] || 'Status updated', newStatus === 'cancelled' ? 'info' : 'success');

      // Update local data and re-render
      const appt = allAppointments.find(a => a.id === id);
      if (appt) appt.status = newStatus;
      updateCounts(allAppointments);
      renderGrid();
    } else {
      toast(data.message || 'Update failed', 'error');
      actDiv.querySelectorAll('.act-btn').forEach(b => { b.disabled=false; b.style.opacity=''; });
    }
  } catch {
    toast('Network error — please try again.', 'error');
    actDiv.querySelectorAll('.act-btn').forEach(b => { b.disabled=false; b.style.opacity=''; });
  }
}
</script>
</body>
</html>