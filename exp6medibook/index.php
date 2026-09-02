<?php
/**
 * index.php — Doctor Appointment Booking Page
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php'; // provides $pdo

// Load doctors for server-side render
$doctors_seed = [];
try {
    $doctors_seed = $pdo->query(
        'SELECT id, name, specialty FROM doctors WHERE available = 1 ORDER BY specialty, name'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // JS will load them via AJAX as fallback
}

$time_slots = [
    '09:00' => '09:00 AM', '09:30' => '09:30 AM',
    '10:00' => '10:00 AM', '10:30' => '10:30 AM',
    '11:00' => '11:00 AM', '11:30' => '11:30 AM',
    '12:00' => '12:00 PM',
    '14:00' => '02:00 PM', '14:30' => '02:30 PM',
    '15:00' => '03:00 PM', '15:30' => '03:30 PM',
    '16:00' => '04:00 PM', '16:30' => '04:30 PM',
    '17:00' => '05:00 PM',
];

// Helper: get 2-letter initials from a doctor name
function get_initials(string $name): string {
    $parts  = explode(' ', $name);
    $result = '';
    foreach ($parts as $p) {
        if (!empty($p) && ctype_alpha($p[0])) {
            $result .= strtoupper($p[0]);
            if (strlen($result) >= 2) break;
        }
    }
    return $result ?: 'DR';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>MediBook — Doctor Appointment</title>
  <meta name="description" content="Book a verified doctor appointment quickly and securely."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link rel="stylesheet" href="style.css"/>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏥</text></svg>"/>
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
    <div class="header-badge">
      <span class="pulse-dot"></span>
      System Online
    </div>
  </div>
</header>

<!-- ═══ HERO ══════════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-tag">✦ Validated &amp; Secure Booking</div>
  <h1>Book Your <em>Doctor</em><br>Appointment</h1>
  <p>Choose your specialist, pick a time, and we handle the rest — with real-time validation every step of the way.</p>
  <div class="hero-stats">
    <div class="stat">
      <div class="stat-number"><?= count($doctors_seed) ?: '5' ?>+</div>
      <div class="stat-label">Specialists</div>
    </div>
    <div class="stat">
      <div class="stat-number"><?= count($time_slots) ?></div>
      <div class="stat-label">Daily Slots</div>
    </div>
    <div class="stat">
      <div class="stat-number">100%</div>
      <div class="stat-label">Validated</div>
    </div>
  </div>
</section>

<!-- ═══ MAIN ══════════════════════════════════════════════════ -->
<main class="main-wrapper">

  <!-- Steps -->
  <div class="steps-bar">
    <div class="step-item active" id="step1"><div class="step-circle">1</div><div class="step-label">Doctor</div></div>
    <div class="step-item"        id="step2"><div class="step-circle">2</div><div class="step-label">Details</div></div>
    <div class="step-item"        id="step3"><div class="step-circle">3</div><div class="step-label">Schedule</div></div>
    <div class="step-item"        id="step4"><div class="step-circle">4</div><div class="step-label">Confirm</div></div>
  </div>

  <!-- Alert area -->
  <div id="alert-container"></div>

  <!-- ── Booking Form Card ──────────────────────────────────── -->
  <div class="card" id="form-card">
    <div class="card-header">
      <div class="card-header-icon">📋</div>
      <div>
        <h2>Appointment Request</h2>
        <p>Fields marked ✦ are required</p>
      </div>
    </div>
    <div class="card-body">
      <form id="appt-form" novalidate>

        <!-- Step 1: Doctor selection -->
        <div class="section-label">Choose Your Specialist</div>

        <div class="doctor-cards" id="doctor-cards">
          <?php if (!empty($doctors_seed)): ?>
            <?php foreach ($doctors_seed as $doc): ?>
            <div class="doctor-card" data-id="<?= (int)$doc['id'] ?>" onclick="selectDoctor(this, <?= (int)$doc['id'] ?>)">
              <input type="radio" name="doctor_id" value="<?= (int)$doc['id'] ?>"/>
              <div class="doctor-avatar"><?= get_initials($doc['name']) ?></div>
              <div class="doctor-name"><?= htmlspecialchars($doc['name']) ?></div>
              <div class="doctor-specialty"><?= htmlspecialchars($doc['specialty']) ?></div>
              <div class="doctor-check">✓</div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="color:var(--text-mute);font-size:.88rem;grid-column:1/-1;padding:.5rem 0">
              Loading doctors…
            </div>
          <?php endif; ?>
        </div>
        <div class="field-error" id="doctor_id-err" style="margin-top:.5rem"></div>

        <div class="divider">Patient Information</div>

        <!-- Step 2: Patient details -->
        <div class="form-grid">
          <div class="form-group">
            <label for="full_name">Full Name <span class="req">✦</span></label>
            <input type="text" id="full_name" name="full_name"
                   placeholder="e.g. Priya Krishnan" autocomplete="name"/>
            <div class="field-error" id="full_name-err"></div>
          </div>

          <div class="form-group">
            <label for="email">Email Address <span class="req">✦</span></label>
            <input type="email" id="email" name="email"
                   placeholder="you@example.com" autocomplete="email"/>
            <div class="field-error" id="email-err"></div>
          </div>

          <div class="form-group">
            <label for="phone">Phone Number <span class="req">✦</span></label>
            <input type="tel" id="phone" name="phone"
                   placeholder="+91 98400 00000" autocomplete="tel"/>
            <div class="field-error" id="phone-err"></div>
          </div>

          <div class="form-group">
            <label for="dob">Date of Birth</label>
            <input type="date" id="dob" name="dob"
                   max="<?= date('Y-m-d') ?>" min="1900-01-01"/>
            <div class="field-hint">Optional — helps the doctor prepare.</div>
            <div class="field-error" id="dob-err"></div>
          </div>
        </div>

        <div class="divider">Schedule</div>

        <!-- Step 3: Date & time -->
        <div class="form-grid">
          <div class="form-group">
            <label for="appt_date">Preferred Date <span class="req">✦</span></label>
            <input type="date" id="appt_date" name="appt_date"
                   min="<?= date('Y-m-d') ?>"
                   max="<?= date('Y-m-d', strtotime('+6 months')) ?>"/>
            <div class="field-error" id="appt_date-err"></div>
          </div>

          <div class="form-group span-2">
            <label>Time Slot <span class="req">✦</span></label>
            <div class="time-slots">
              <?php foreach ($time_slots as $val => $label): ?>
              <div class="time-slot">
                <input type="radio" name="appt_time"
                       id="time_<?= str_replace(':','', $val) ?>"
                       value="<?= $val ?>"/>
                <label for="time_<?= str_replace(':','', $val) ?>"><?= $label ?></label>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="field-error" id="appt_time-err"></div>
          </div>

          <div class="form-group span-2">
            <label for="reason">Reason for Visit <span style="font-weight:400;font-size:.72rem;color:var(--text-mute);letter-spacing:0;text-transform:none">(optional)</span></label>
            <textarea id="reason" name="reason" rows="4"
                      placeholder="Briefly describe your symptoms or reason… (leave blank if not applicable)"></textarea>
            <div class="field-hint">Optional — helps the doctor prepare better for your visit.</div>
            <div class="field-error" id="reason-err"></div>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">
          <span class="btn-icon">🗓</span>
          <span id="btn-text">Request Appointment</span>
        </button>

      </form>
    </div>
  </div>

  <!-- ── Confirmation (shown after success) ─────────────────── -->
  <div id="confirm-section" style="display:none">
    <div class="confirm-card">
      <div class="confirm-icon">✅</div>
      <div class="confirm-id" id="appt-id-badge">APPT #—</div>
      <h2>Booking Confirmed!</h2>
      <p>Your appointment is pending doctor confirmation.<br>You will receive an email shortly.</p>
      <div class="confirm-details" id="confirm-details"></div>
      <a href="index.php" class="btn-new">➕ Book Another Appointment</a>
    </div>
  </div>

  <!-- ── Recent Appointments ────────────────────────────────── -->
  <div style="margin-top:4rem">
    <div class="section-label" style="margin-bottom:1.2rem">Recent Appointments</div>
    <div class="card">
      <div class="card-body" style="padding:0">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Patient</th><th>Doctor</th>
                <th>Specialty</th><th>Date</th><th>Time</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="recent-tbody">
              <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-mute)">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</main>

<!-- ═══ FOOTER ════════════════════════════════════════════════ -->
<footer class="site-footer">
  <p>© <?= date('Y') ?> <span>MediBook</span> — Doctor Appointment Portal &nbsp;|&nbsp; Built with PHP &amp; MySQL</p>
</footer>

<!-- ═══ JAVASCRIPT ════════════════════════════════════════════ -->
<script>
/* ── Doctor selection ─────────────────────────────────────── */
function selectDoctor(card, id) {
  document.querySelectorAll('.doctor-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  card.querySelector('input[type="radio"]').checked = true;
  clearFieldError('doctor_id');
  setStep(2);
}

/* ── Step indicator ───────────────────────────────────────── */
function setStep(n) {
  for (let i = 1; i <= 4; i++) {
    const el = document.getElementById('step' + i);
    el.classList.remove('active','done');
    if (i < n)  el.classList.add('done');
    if (i === n) el.classList.add('active');
  }
}

/* ── Field error helpers ──────────────────────────────────── */
function showFieldError(field, msg) {
  const errEl = document.getElementById(field + '-err');
  const inp   = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
  if (errEl) { errEl.textContent = msg; errEl.classList.add('show'); }
  if (inp)   inp.classList.add('error');
}
function clearFieldError(field) {
  const errEl = document.getElementById(field + '-err');
  const inp   = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
  if (errEl) { errEl.textContent = ''; errEl.classList.remove('show'); }
  if (inp)   inp.classList.remove('error');
}
function clearAllErrors() {
  document.querySelectorAll('.field-error').forEach(e => { e.textContent=''; e.classList.remove('show'); });
  document.querySelectorAll('input.error,select.error,textarea.error').forEach(e => e.classList.remove('error'));
}

/* ── Alert banner ─────────────────────────────────────────── */
function showAlert(type, title, msg) {
  const icons  = { success:'✅', error:'❌', warn:'⚠️' };
  const box    = document.getElementById('alert-container');
  box.innerHTML = `
    <div class="alert alert-${type}">
      <div class="alert-icon">${icons[type]||'ℹ️'}</div>
      <div class="alert-body">
        <div class="alert-title">${title}</div>
        <div class="alert-msg">${msg}</div>
      </div>
    </div>`;
  box.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

/* ── Recent appointments table ────────────────────────────── */
function loadRecent() {
  fetch('process.php?action=recent')
    .then(r => r.json())
    .then(data => {
      const tbody = document.getElementById('recent-tbody');
      if (!data.appointments?.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-mute)">No appointments yet.</td></tr>';
        return;
      }
      tbody.innerHTML = data.appointments.map(a => {
        const d     = new Date(a.appt_date + 'T00:00:00');
        const dateF = d.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
        const [h,m] = a.appt_time.split(':');
        const timeF = (+h >= 12)
          ? `${(+h===12?12:+h-12).toString().padStart(2,'0')}:${m} PM`
          : `${h}:${m} AM`;

        return `<tr id="row-${a.id}">
          <td>#${a.id}</td>
          <td>${esc(a.full_name)}</td>
          <td>${esc(a.doctor)}</td>
          <td>${esc(a.specialty)}</td>
          <td>${dateF}</td>
          <td>${timeF}</td>
          <td><span class="badge badge-${a.status}">${a.status}</span></td>
        </tr>`;
      }).join('');
    })
    .catch(() => {
      document.getElementById('recent-tbody').innerHTML =
        '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--error)">Could not load appointments.</td></tr>';
    });
}

/* ── AJAX doctor loader (fallback if PHP couldn't load) ────── */
function loadDoctorsIfNeeded() {
  const container = document.getElementById('doctor-cards');
  if (!container || container.querySelectorAll('.doctor-card').length > 0) return;
  fetch('process.php?action=doctors')
    .then(r => r.json())
    .then(data => {
      if (!data.doctors?.length) {
        container.innerHTML = '<div style="color:var(--error);font-size:.88rem;padding:.5rem 0">No doctors available.</div>';
        return;
      }
      const initials = n => n.split(' ').filter(p=>p&&/[a-zA-Z]/.test(p[0])).map(p=>p[0].toUpperCase()).slice(0,2).join('');
      container.innerHTML = data.doctors.map(d =>
        `<div class="doctor-card" data-id="${d.id}" onclick="selectDoctor(this, ${d.id})">
          <input type="radio" name="doctor_id" value="${d.id}"/>
          <div class="doctor-avatar">${initials(d.name)}</div>
          <div class="doctor-name">${esc(d.name)}</div>
          <div class="doctor-specialty">${esc(d.specialty)}</div>
          <div class="doctor-check">✓</div>
        </div>`
      ).join('');
    });
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

/* ── Form submit ──────────────────────────────────────────── */
document.getElementById('appt-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  clearAllErrors();
  document.getElementById('alert-container').innerHTML = '';

  if (!document.querySelector('[name="doctor_id"]:checked')) {
    showFieldError('doctor_id', 'Please select a doctor.');
    setStep(1);
    return;
  }

  const btn     = document.getElementById('submit-btn');
  const btnText = document.getElementById('btn-text');
  btn.classList.add('loading');
  btnText.textContent = 'Validating…';

  try {
    const res  = await fetch('process.php', {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body:    new FormData(this),
    });
    const data = await res.json();

    if (data.status === 'success') {
      setStep(4);
      document.getElementById('form-card').style.display      = 'none';
      document.getElementById('confirm-section').style.display = 'block';
      document.getElementById('appt-id-badge').textContent    = `APPT #${data.appointment_id}`;

      const d     = new Date(data.appt_date + 'T00:00:00');
      const dateF = d.toLocaleDateString('en-IN', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
      const [h,m] = data.appt_time.split(':');
      const timeF = (+h >= 12)
        ? `${(+h===12?12:+h-12).toString().padStart(2,'0')}:${m} PM`
        : `${h}:${m} AM`;

      document.getElementById('confirm-details').innerHTML = `
        <div class="detail-item"><div class="detail-label">Patient</div><div class="detail-value">${esc(data.patient)}</div></div>
        <div class="detail-item"><div class="detail-label">Doctor</div><div class="detail-value">${esc(data.doctor)}</div></div>
        <div class="detail-item"><div class="detail-label">Specialty</div><div class="detail-value">${esc(data.specialty)}</div></div>
        <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value">⏳ Pending Confirmation</div></div>
        <div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">${dateF}</div></div>
        <div class="detail-item"><div class="detail-label">Time</div><div class="detail-value">${timeF}</div></div>`;

      document.getElementById('confirm-section').scrollIntoView({ behavior:'smooth' });
      loadRecent();

    } else if (data.status === 'validation') {
      showAlert('warn', 'Please Fix These Errors', data.message);
      if (data.errors) {
        Object.entries(data.errors).forEach(([f, msg]) => showFieldError(f, msg));
        const firstKey = Object.keys(data.errors)[0];
        const firstEl  = document.getElementById(firstKey) || document.querySelector(`[name="${firstKey}"]`);
        if (firstEl) firstEl.focus();
      }
    } else {
      showAlert('error', 'Error', data.message || 'Something went wrong. Please try again.');
    }
  } catch {
    showAlert('error', 'Network Error', 'Could not reach the server. Check your connection.');
  } finally {
    btn.classList.remove('loading');
    btnText.textContent = 'Request Appointment';
  }
});

/* ── Input blur validation (live feedback) ───────────────── */
['full_name','email','phone','appt_date'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('blur', () => { if (el.value.trim()) clearFieldError(id); });
});

document.getElementById('appt_date').addEventListener('change', function() {
  if (this.value) setStep(3);
});

/* ── Init ─────────────────────────────────────────────────── */
loadDoctorsIfNeeded();
loadRecent();
</script>
</body>
</html>