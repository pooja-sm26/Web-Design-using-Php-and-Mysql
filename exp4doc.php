<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "doctor_rating_system";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

$allowed_sorts = ['rating','experience'];

/* ════════════════════════════════════════
   AJAX — search / sort
════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sort  = in_array($_GET['sort'] ?? '', $allowed_sorts) ? $_GET['sort'] : 'rating';
    $order = (($_GET['order'] ?? '') === 'asc') ? 'ASC' : 'DESC';

    $highlight_specialty = '';
    $mode = 'all';

    if ($query !== '') {
        $like = "%$query%";
        $stmt = $pdo->prepare("SELECT DISTINCT specialty FROM doctors WHERE specialty LIKE ? LIMIT 5");
        $stmt->execute([$like]);
        $match_specs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($match_specs)) {
            $ph   = implode(',', array_fill(0, count($match_specs), '?'));
            $stmt = $pdo->prepare("SELECT * FROM doctors WHERE specialty IN ($ph) ORDER BY $sort $order LIMIT 100");
            $stmt->execute($match_specs);
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cs      = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE specialty IN ($ph)");
            $cs->execute($match_specs);
            $total   = $cs->fetchColumn();
            $mode    = 'specialty_group';
            $highlight_specialty = $match_specs[0];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM doctors WHERE name LIKE ? OR specialty LIKE ? OR hospital LIKE ? OR experience LIKE ? OR total_reviews LIKE ? ORDER BY $sort $order LIMIT 100");
            $stmt->execute([$like,$like,$like,$like,$like]);
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total   = count($doctors);
            $mode    = 'regular';
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM doctors ORDER BY $sort $order LIMIT 100");
        $stmt->execute();
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total   = count($doctors);
    }

    header('Content-Type: application/json');
    echo json_encode(['doctors'=>$doctors,'total'=>$total,'sort'=>$sort,
        'order'=>strtolower($order),'mode'=>$mode,'highlight_specialty'=>$highlight_specialty]);
    exit;
}

/* ════════════════════════════════════════
   AJAX — add doctor (POST)
════════════════════════════════════════ */
if (isset($_POST['ajax_add']) && $_POST['ajax_add'] === '1') {
    $errs = [];
    $name       = trim($_POST['name']          ?? '');
    $specialty  = trim($_POST['specialty']     ?? '');
    $custom_sp  = trim($_POST['custom_sp']     ?? '');
    $hospital   = trim($_POST['hospital']      ?? '');
    $city       = trim($_POST['city']          ?? '');
    $rating     = trim($_POST['rating']        ?? '');
    $reviews    = trim($_POST['total_reviews'] ?? '');
    $experience = trim($_POST['experience']    ?? '');

    if ($specialty === '__other__') {
        if ($custom_sp === '') $errs[] = 'Enter a custom specialty name.';
        else $specialty = $custom_sp;
    }
    if (!$name)       $errs[] = 'Doctor name is required.';
    if (!$specialty)  $errs[] = 'Specialty is required.';
    if (!$hospital)   $errs[] = 'Hospital is required.';
    if (!$city)       $errs[] = 'City is required.';
    if ($rating===''||!is_numeric($rating)||$rating<0||$rating>5) $errs[]='Rating must be between 0 and 5.';
    if ($reviews===''||!ctype_digit($reviews))                    $errs[]='Total reviews must be a whole number.';
    if ($experience===''||!ctype_digit($experience))              $errs[]='Experience must be a whole number.';

    if (empty($errs)) {
        $stmt = $pdo->prepare("INSERT INTO doctors (name,specialty,hospital,rating,total_reviews,experience,city) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$name,$specialty,$hospital,round((float)$rating,2),(int)$reviews,(int)$experience,$city]);
        $newId  = $pdo->lastInsertId();
        $newDoc = $pdo->query("SELECT * FROM doctors WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
        $total  = $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
        $newSpecialties = $pdo->query("SELECT DISTINCT specialty FROM doctors ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true,'doctor'=>$newDoc,'total'=>$total,'specialties'=>$newSpecialties]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'errors'=>$errs]);
    }
    exit;
}

/* ════════════════════════════════════════
   Page load — initial data
════════════════════════════════════════ */
$stmt = $pdo->prepare("SELECT * FROM doctors ORDER BY rating DESC LIMIT 100");
$stmt->execute();
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_doctors   = $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
$specialty_stats = $pdo->query("SELECT specialty,COUNT(*) as cnt FROM doctors GROUP BY specialty ORDER BY cnt DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$specialties     = $pdo->query("SELECT DISTINCT specialty FROM doctors ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
$hospitals       = $pdo->query("SELECT DISTINCT hospital FROM doctors ORDER BY hospital")->fetchAll(PDO::FETCH_COLUMN);
$cities          = $pdo->query("SELECT DISTINCT city FROM doctors ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);

/* PHP helper — renders one table row */
function rowHtml($d, $hi) {
    $f = floor($d['rating']); $h = ($d['rating']-$f) >= 0.5;
    $stars = '';
    for($i=1;$i<=5;$i++) $stars .= $i<=$f ? '<i class="fas fa-star"></i>' : ($h&&$i==$f+1 ? '<i class="fas fa-star-half-alt"></i>' : '<i class="far fa-star"></i>');
    $hiClass = ($hi && strtolower($d['specialty'])===strtolower($hi)) ? ' hi' : '';
    return '<tr>
      <td><div class="dc"><div class="av">'.strtoupper(substr(htmlspecialchars($d['name']),0,1)).'</div><span class="dn">'.htmlspecialchars($d['name']).'</span></div></td>
      <td><span class="stag'.$hiClass.'">'.htmlspecialchars($d['specialty']).'</span></td>
      <td><span class="stars-d">'.$stars.'</span><span class="rv">'.number_format($d['rating'],1).'</span></td>
      <td>'.htmlspecialchars($d['hospital']).'</td>
      <td>'.(int)$d['experience'].' yrs</td>
      <td>'.number_format((int)$d['total_reviews']).'</td>
    </tr>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Rating System</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
/* ════ RESET & VARS ════ */
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --navy:#0f1b35;--blue:#1a56db;--sky:#38bdf8;--teal:#0d9488;
  --emerald:#10b981;--amber:#f59e0b;--rose:#f43f5e;
  --surface:#ffffff;--surface2:#f4f7ff;--border:#e2e8f0;
  --text:#0f172a;--muted:#64748b;
  --shadow-sm:0 2px 8px rgba(15,27,53,.08);
  --shadow-md:0 8px 32px rgba(15,27,53,.14);
  --r:14px;--r-lg:22px;
}
body{font-family:'Outfit',sans-serif;background:var(--surface2);min-height:100vh;color:var(--text)}

/* ════ NAV ════ */
.nav{
  position:sticky;top:0;z-index:100;
  background:var(--navy);
  padding:0 36px;height:64px;
  display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 4px 24px rgba(15,27,53,.45);
}
.nav-brand{display:flex;align-items:center;gap:12px;color:#fff;text-decoration:none}
.pulse-box{width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,var(--sky),var(--blue));
  display:flex;align-items:center;justify-content:center;font-size:1.1em;
  animation:pulseAnim 2s infinite}
@keyframes pulseAnim{0%,100%{box-shadow:0 0 0 0 rgba(56,189,248,.4)}60%{box-shadow:0 0 0 8px rgba(56,189,248,0)}}
.brand-name{font-size:1.3em;font-weight:800;letter-spacing:-.5px;color:#fff}
.brand-name em{color:var(--sky);font-style:normal}

.btn-add{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 22px;border-radius:50px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--emerald),var(--teal));
  color:#fff;font-size:.92em;font-weight:700;font-family:'Outfit',sans-serif;
  box-shadow:0 4px 18px rgba(16,185,129,.4);transition:all .25s;
}
.btn-add:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(16,185,129,.5)}

/* ════ HERO ════ */
.hero{
  background:linear-gradient(135deg,var(--navy) 0%,#1e3a8a 55%,#1d4ed8 100%);
  padding:44px 36px 38px;color:#fff;position:relative;overflow:hidden;
}
.hero::after{content:'';position:absolute;right:-80px;top:-60px;
  width:320px;height:320px;border-radius:50%;
  background:radial-gradient(circle,rgba(56,189,248,.18),transparent 70%);}
.hero-inner{max-width:680px;position:relative;z-index:1}
.hero h1{font-size:2.4em;font-weight:800;letter-spacing:-.8px;line-height:1.1;margin-bottom:10px}
.hero h1 span{color:var(--sky)}
.hero p{font-size:1.05em;opacity:.82}
.hero-stats{display:flex;gap:28px;margin-top:22px;flex-wrap:wrap}
.hstat strong{font-size:2em;font-weight:800;line-height:1;font-family:'JetBrains Mono',monospace;display:block}
.hstat span{font-size:.75em;opacity:.7;text-transform:uppercase;letter-spacing:.5px}

/* ════ FLASH ════ */
.flash{
  margin:16px 36px 0;padding:14px 20px;border-radius:var(--r);
  display:none;align-items:center;gap:12px;font-weight:600;font-size:.92em;
}
.flash.show{display:flex;animation:fslide .3s ease}
.flash.success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.flash.error  {background:#ffe4e6;color:#9f1239;border:1px solid #fda4af}
@keyframes fslide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

/* ════ SORT NOTE BANNER ════ */
.sort-note{
  margin:16px 36px 0;
  padding:11px 18px;
  border-radius:var(--r);
  background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(56,189,248,.1));
  border:1px solid rgba(245,158,11,.35);
  display:flex;align-items:center;gap:10px;
  font-size:.86em;font-weight:600;color:#92400e;
}
.sort-note i{color:var(--amber);font-size:1em;flex-shrink:0}

/* ════ CONTROLS ════ */
.controls{background:var(--surface);padding:22px 36px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:14px}
.search-row{display:flex;gap:14px;align-items:center}
.search-wrap{flex:1;position:relative}
.search-wrap .ico{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
#searchInput{
  width:100%;padding:13px 18px 13px 46px;
  border:2px solid var(--border);border-radius:50px;
  font-size:.97em;font-family:'Outfit',sans-serif;
  background:var(--surface2);color:var(--text);outline:none;transition:all .22s;
}
#searchInput:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(26,86,219,.1)}
.spinner-txt{display:none;color:var(--muted);font-size:.88em;white-space:nowrap;align-items:center;gap:6px}
.spinner-txt.show{display:flex}

.count-pill{
  display:flex;align-items:center;gap:10px;padding:10px 22px;
  background:linear-gradient(135deg,var(--blue),var(--sky));
  border-radius:50px;color:#fff;white-space:nowrap;
  box-shadow:var(--shadow-sm);
}
.count-pill strong{font-size:1.35em;font-family:'JetBrains Mono',monospace;font-weight:700}
.count-pill span{font-size:.8em;opacity:.9}

.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{padding:7px 16px;border-radius:50px;font-size:.82em;font-weight:600;
  cursor:pointer;border:2px solid transparent;transition:all .2s;text-decoration:none}
.chip-all{background:var(--emerald);color:#fff}
.chip-all:hover{background:var(--teal);transform:translateY(-1px)}
.chip-sp{background:var(--surface2);color:var(--blue);border-color:var(--blue)}
.chip-sp:hover{background:var(--blue);color:#fff;transform:translateY(-1px)}

/* ════ TABLE ════ */
.table-section{padding:28px 36px 52px}
.table-wrap{border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--shadow-md);background:var(--surface)}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(135deg,var(--navy),#1e3a8a)}
th{padding:17px 18px;text-align:left;color:#fff;font-weight:700;font-size:.85em;
  letter-spacing:.3px;white-space:nowrap;}
/* only sortable columns get pointer cursor */
th.sortable{cursor:pointer;user-select:none;transition:background .2s}
th.sortable:hover{background:rgba(255,255,255,.1)}
th .si{margin-left:5px;opacity:.5;font-size:.75em;transition:opacity .2s}
th .si.on{opacity:1;color:var(--amber)}
td{padding:17px 18px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.91em}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover{background:var(--surface2)}

@keyframes rowPop{
  0%  {background:rgba(16,185,129,.18);transform:scale(1.005);}
  100%{background:transparent;transform:scale(1);}
}
.new-row{animation:rowPop .9s ease forwards}

.dc{display:flex;align-items:center;gap:12px}
.av{width:42px;height:42px;border-radius:12px;
  background:linear-gradient(135deg,var(--blue),var(--sky));
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:800;font-size:1em;flex-shrink:0}
.dn{font-weight:700}
.stag{padding:4px 12px;border-radius:50px;font-size:.78em;font-weight:700;color:#fff;white-space:nowrap;
  background:linear-gradient(135deg,var(--blue),var(--sky))}
.stag.hi{background:linear-gradient(135deg,var(--emerald),var(--teal))}
.stars-d{color:var(--amber);font-size:.95em;margin-right:6px}
.rv{font-weight:800;font-family:'JetBrains Mono',monospace}
.no-res{text-align:center;padding:70px 40px;color:var(--muted)}
.no-res i{font-size:3.5em;margin-bottom:14px;display:block;color:var(--border)}

/* ════ OVERLAY & PANEL ════ */
.overlay{
  display:none;position:fixed;inset:0;
  background:rgba(15,27,53,.55);z-index:200;
  backdrop-filter:blur(4px);opacity:0;transition:opacity .3s;
}
.overlay.open{display:block}
.overlay.vis{opacity:1}

.panel{
  position:fixed;right:0;top:0;bottom:0;
  width:min(500px,100vw);background:var(--surface);z-index:201;
  transform:translateX(100%);
  transition:transform .35s cubic-bezier(.22,1,.36,1);
  display:flex;flex-direction:column;
  box-shadow:-12px 0 60px rgba(15,27,53,.28);
}
.panel.open{transform:translateX(0)}

.panel-head{
  padding:22px 26px;
  background:linear-gradient(135deg,var(--navy),#1e3a8a);
  color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.panel-head h2{font-size:1.12em;font-weight:800;display:flex;align-items:center;gap:10px}
.panel-head h2 i{color:var(--sky)}
.btn-close{
  width:36px;height:36px;border-radius:10px;border:none;cursor:pointer;
  background:rgba(255,255,255,.12);color:#fff;font-size:1.1em;
  display:flex;align-items:center;justify-content:center;transition:background .2s;
}
.btn-close:hover{background:rgba(255,255,255,.22)}

.panel-body{flex:1;overflow-y:auto;padding:24px 26px}
.panel-body::-webkit-scrollbar{width:5px}
.panel-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}

.sec-lbl{
  font-size:.72em;font-weight:800;text-transform:uppercase;letter-spacing:1px;
  color:var(--blue);margin:22px 0 12px;display:flex;align-items:center;gap:8px;
}
.sec-lbl:first-child{margin-top:0}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--border)}

.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.fg label{font-size:.85em;font-weight:700;color:var(--text)}
.fg label .req{color:var(--rose);margin-left:2px}
.fg input,.fg select{
  padding:11px 14px;border:2px solid var(--border);border-radius:var(--r);
  font-size:.92em;font-family:'Outfit',sans-serif;color:var(--text);
  background:var(--surface2);outline:none;transition:all .22s;width:100%;
}
.fg input:focus,.fg select:focus{
  border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(26,86,219,.1);
}
.fg select{cursor:pointer;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2364748b'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;background-size:16px;padding-right:36px;
}
.fg .ferr{color:var(--rose);font-size:.78em;display:none;margin-top:2px}
.fg.err input,.fg.err select{border-color:var(--rose)!important;background:#fff5f7!important}
.fg.err .ferr{display:block}

#csp-wrap{
  display:none;margin-top:8px;
  animation:slideDown .22s ease;
}
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
#p-csp{border:2px dashed var(--blue)!important;background:#f0f6ff!important}
#p-csp:focus{background:#fff!important}

.g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.sw{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.sp{display:flex;flex-direction:row-reverse;gap:3px}
.sp input{display:none}
.sp label{font-size:1.75em;color:var(--border);cursor:pointer;transition:color .12s;line-height:1}
.sp input:checked ~ label,
.sp label:hover,
.sp label:hover ~ label{color:var(--amber)}
.ri{
  width:80px!important;text-align:center;font-weight:700;font-size:1em!important;
  border:2px solid var(--border)!important;border-radius:var(--r)!important;
  background:var(--surface2)!important;
}
.ri:focus{border-color:var(--blue)!important;background:#fff!important}

.perr{
  background:#ffe4e6;border:1px solid #fda4af;border-radius:var(--r);
  padding:12px 16px;margin-bottom:16px;display:none;color:#9f1239;font-size:.85em;
}
.perr.show{display:block;animation:fslide .25s ease}
.perr ul{padding-left:16px;margin-top:4px}
.perr ul li{margin-bottom:2px}

.panel-foot{
  padding:18px 26px;border-top:1px solid var(--border);
  display:flex;gap:12px;flex-shrink:0;background:var(--surface);
}
.btn-save{
  flex:1;padding:13px;border:none;border-radius:50px;cursor:pointer;
  background:linear-gradient(135deg,var(--blue),var(--sky));
  color:#fff;font-size:.97em;font-weight:800;font-family:'Outfit',sans-serif;
  box-shadow:0 4px 18px rgba(26,86,219,.35);transition:all .25s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn-save:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 28px rgba(26,86,219,.45)}
.btn-save:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-disc{
  padding:12px 22px;border-radius:50px;border:2px solid var(--border);
  background:transparent;cursor:pointer;font-size:.9em;font-weight:700;
  font-family:'Outfit',sans-serif;color:var(--muted);transition:all .22s;
}
.btn-disc:hover{border-color:var(--muted);color:var(--text)}

@media(max-width:768px){
  .nav,.hero,.controls,.table-section{padding-left:16px;padding-right:16px}
  .hero h1{font-size:1.7em}
  th,td{padding:12px 10px;font-size:.83em}
  .g2{grid-template-columns:1fr}
  .panel{width:100vw}
  .flash,.sort-note{margin:14px 16px 0}
}
</style>
</head>
<body>

<!-- ═══ NAV ═══ -->
<nav class="nav">
  <a class="nav-brand" href="#">
    <div class="pulse-box"><i class="fas fa-heartbeat" style="color:#fff"></i></div>
    <span class="brand-name">Doc<em>Rate</em></span>
  </a>
  <button class="btn-add" onclick="openPanel()">
    <i class="fas fa-user-plus"></i> Add Doctor
  </button>
</nav>

<!-- ═══ HERO ═══ -->
<div class="hero">
  <div class="hero-inner">
    <h1>Find &amp; Rate<br><span>Top Doctors</span></h1>
    <p>Live search, sort, and add doctors — all in one place.</p>
    <div class="hero-stats">
      <div class="hstat">
        <strong id="heroTotal"><?php echo $total_doctors; ?></strong>
        <span>Doctors Listed</span>
      </div>
      <?php foreach(array_slice($specialty_stats,0,3) as $s): ?>
      <div class="hstat">
        <strong><?php echo $s['cnt']; ?></strong>
        <span><?php echo htmlspecialchars($s['specialty']); ?>s</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ FLASH ═══ -->
<div class="flash" id="flash">
  <i class="fas fa-check-circle" id="flash-icon"></i>
  <span id="flash-msg"></span>
</div>

<!-- ═══ SORT NOTE ═══ -->
<div class="sort-note">
  <i class="fas fa-info-circle"></i>
  Doctor rankings are determined by <strong>&nbsp;Rating&nbsp;</strong> and <strong>&nbsp;Experience&nbsp;</strong> — click either column header to sort.
</div>

<!-- ═══ CONTROLS ═══ -->
<div class="controls">
  <div class="search-row">
    <div class="search-wrap">
      <i class="fas fa-search ico"></i>
      <input type="text" id="searchInput" placeholder="Search by name, specialty, hospital…" autocomplete="off">
    </div>
    <div class="spinner-txt" id="spinner"><i class="fas fa-circle-notch fa-spin"></i> Searching…</div>
    <div class="count-pill" id="countPill">
      <strong><?php echo $total_doctors; ?></strong>
      <span>Doctors</span>
    </div>
  </div>
  <div class="chips" id="chips">
    <a href="#" class="chip chip-all" onclick="quickSearch('');return false;"><i class="fas fa-list"></i> All (<?php echo $total_doctors; ?>)</a>
    <?php foreach($specialty_stats as $s): ?>
    <a href="#" class="chip chip-sp" onclick="quickSearch('<?php echo htmlspecialchars($s['specialty'],ENT_QUOTES); ?>');return false;">
      <?php echo htmlspecialchars($s['specialty']); ?> <b>(<?php echo $s['cnt']; ?>)</b>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══ TABLE ═══ -->
<div class="table-section">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Doctor</th>
          <th>Specialty</th>
          <th class="sortable" onclick="sortTable('rating')">Rating <i class="fas fa-sort si" id="si-rating"></i></th>
          <th>Hospital</th>
          <th class="sortable" onclick="sortTable('experience')">Experience <i class="fas fa-sort si" id="si-experience"></i></th>
          <th>Reviews</th>
        </tr>
      </thead>
      <tbody id="tbody">
        <?php foreach($doctors as $d) echo rowHtml($d,''); ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ OVERLAY ═══ -->
<div class="overlay" id="overlay" onclick="closePanel()"></div>

<!-- ═══ SLIDE PANEL ═══ -->
<div class="panel" id="panel">
  <div class="panel-head">
    <h2><i class="fas fa-user-md"></i> Register New Doctor</h2>
    <button class="btn-close" onclick="closePanel()"><i class="fas fa-times"></i></button>
  </div>

  <div class="panel-body">
    <div class="perr" id="perr">
      <strong><i class="fas fa-exclamation-triangle"></i> Please fix the following:</strong>
      <ul id="perr-list"></ul>
    </div>

    <div class="sec-lbl"><i class="fas fa-id-card"></i> Personal</div>
    <div class="fg" id="fg-name">
      <label>Doctor Name <span class="req">*</span></label>
      <input type="text" id="p-name" placeholder="e.g. Dr. Arun Kumar" autocomplete="off">
      <span class="ferr">Doctor name is required.</span>
    </div>

    <div class="sec-lbl"><i class="fas fa-hospital"></i> Professional</div>
    <div class="g2">
      <div class="fg" id="fg-sp">
        <label>Specialty <span class="req">*</span></label>
        <select id="p-sp" onchange="toggleCsp(this.value)">
          <option value="">— Select specialty —</option>
          <?php foreach($specialties as $sp): ?>
          <option value="<?php echo htmlspecialchars($sp,ENT_QUOTES); ?>"><?php echo htmlspecialchars($sp); ?></option>
          <?php endforeach; ?>
          <option value="__other__">✚ Add new specialty…</option>
        </select>
        <div id="csp-wrap">
          <input type="text" id="p-csp" placeholder="Type new specialty name…" autocomplete="off">
        </div>
        <span class="ferr">Specialty is required.</span>
      </div>
      <div class="fg" id="fg-city">
        <label>City <span class="req">*</span></label>
        <input type="text" id="p-city" list="city-dl" placeholder="e.g. Chennai" autocomplete="off">
        <datalist id="city-dl">
          <?php foreach($cities as $c): ?>
          <option value="<?php echo htmlspecialchars($c); ?>">
          <?php endforeach; ?>
        </datalist>
        <span class="ferr">City is required.</span>
      </div>
    </div>
    <div class="fg" id="fg-hosp">
      <label>Hospital / Clinic <span class="req">*</span></label>
      <input type="text" id="p-hosp" list="hosp-dl" placeholder="e.g. Apollo Hospitals Chennai" autocomplete="off">
      <datalist id="hosp-dl">
        <?php foreach($hospitals as $h): ?>
        <option value="<?php echo htmlspecialchars($h); ?>">
        <?php endforeach; ?>
      </datalist>
      <span class="ferr">Hospital is required.</span>
    </div>

    <div class="sec-lbl"><i class="fas fa-chart-bar"></i> Stats</div>
    <div class="g2">
      <div class="fg" id="fg-exp">
        <label>Experience (years) <span class="req">*</span></label>
        <input type="number" id="p-exp" min="0" max="60" placeholder="e.g. 12">
        <span class="ferr">Enter a valid whole number.</span>
      </div>
      <div class="fg" id="fg-rev">
        <label>Total Reviews <span class="req">*</span></label>
        <input type="number" id="p-rev" min="0" placeholder="e.g. 120">
        <span class="ferr">Enter a valid whole number.</span>
      </div>
    </div>

    <div class="sec-lbl"><i class="fas fa-star"></i> Rating</div>
    <div class="fg" id="fg-rat">
      <label>Rating (0 – 5) <span class="req">*</span></label>
      <div class="sw">
        <div class="sp" id="sp">
          <input type="radio" id="ps5" name="pstar" value="5">
          <label for="ps5" title="5 stars">★</label>
          <input type="radio" id="ps4" name="pstar" value="4">
          <label for="ps4" title="4 stars">★</label>
          <input type="radio" id="ps3" name="pstar" value="3">
          <label for="ps3" title="3 stars">★</label>
          <input type="radio" id="ps2" name="pstar" value="2">
          <label for="ps2" title="2 stars">★</label>
          <input type="radio" id="ps1" name="pstar" value="1">
          <label for="ps1" title="1 star">★</label>
        </div>
        <input type="number" class="ri" id="p-rat" step="0.1" min="0" max="5" placeholder="4.5">
        <span style="color:var(--muted);font-size:.85em;white-space:nowrap">/ 5</span>
      </div>
      <span class="ferr">Rating must be a number between 0 and 5.</span>
    </div>

  </div>

  <div class="panel-foot">
    <button class="btn-save" id="btn-save" onclick="saveDoctor()">
      <i class="fas fa-save"></i> Save Doctor
    </button>
    <button class="btn-disc" onclick="closePanel()">Discard</button>
  </div>
</div>

<script>
/* ═══ STATE ═══ */
let curSort = 'rating';
let curOrd  = 'desc';
let searchTO;

/* ═══ SEARCH ═══ */
const searchInput = document.getElementById('searchInput');

searchInput.addEventListener('input', function() {
  clearTimeout(searchTO);
  document.getElementById('spinner').classList.add('show');
  searchTO = setTimeout(() => doSearch(this.value.trim()), 300);
});

searchInput.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { this.value = ''; doSearch(''); }
});

function doSearch(q) {
  const url = `?ajax=1&q=${encodeURIComponent(q)}&sort=${encodeURIComponent(curSort)}&order=${encodeURIComponent(curOrd)}`;
  fetch(url)
    .then(r => r.json())
    .then(data => {
      renderTable(data);
      const lbl = data.mode === 'specialty_group' ? `in ${data.highlight_specialty}` : 'Doctors';
      document.getElementById('countPill').innerHTML =
        `<strong>${data.total}</strong><span>${lbl}</span>`;
    })
    .catch(() => {})
    .finally(() => document.getElementById('spinner').classList.remove('show'));
}

function quickSearch(term) {
  searchInput.value = term;
  doSearch(term);
}

/* ═══ TABLE RENDER ═══ */
function renderTable(data, highlightId) {
  const tb = document.getElementById('tbody');
  const hi = (data.highlight_specialty || '').toLowerCase();

  if (!data.total) {
    tb.innerHTML = `<tr><td colspan="6">
      <div class="no-res">
        <i class="fas fa-search"></i>
        <p>No doctors found.</p>
        <small>Try a different search term or add a new doctor.</small>
      </div></td></tr>`;
    return;
  }

  tb.innerHTML = data.doctors.map(d => rowJS(d, hi, d.id == highlightId)).join('');
}

function rowJS(d, hi, isNew) {
  const f = Math.floor(d.rating), half = (d.rating - f) >= 0.5;
  let st = '';
  for (let i = 1; i <= 5; i++) {
    st += i <= f
      ? '<i class="fas fa-star"></i>'
      : (half && i === f + 1 ? '<i class="fas fa-star-half-alt"></i>' : '<i class="far fa-star"></i>');
  }
  const hiC  = hi && d.specialty.toLowerCase().includes(hi) ? ' hi' : '';
  const newC = isNew ? ' new-row' : '';
  return `<tr class="${newC}">
    <td><div class="dc">
      <div class="av">${esc(d.name[0].toUpperCase())}</div>
      <span class="dn">${esc(d.name)}</span>
    </div></td>
    <td><span class="stag${hiC}">${esc(d.specialty)}</span></td>
    <td><span class="stars-d">${st}</span><span class="rv">${parseFloat(d.rating).toFixed(1)}</span></td>
    <td>${esc(d.hospital)}</td>
    <td>${parseInt(d.experience)} yrs</td>
    <td>${parseInt(d.total_reviews).toLocaleString()}</td>
  </tr>`;
}

function esc(t) {
  return String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

/* ═══ SORT — only rating & experience ═══ */
const sortableCols = ['rating', 'experience'];

function sortTable(col) {
  if (!sortableCols.includes(col)) return;
  if (curSort === col) {
    curOrd = curOrd === 'desc' ? 'asc' : 'desc';
  } else {
    curSort = col;
    curOrd  = 'desc';
  }
  updateSortIcons();
  doSearch(searchInput.value.trim());
}

function updateSortIcons() {
  document.querySelectorAll('.si').forEach(i => { i.className = 'fas fa-sort si'; });
  const el = document.getElementById('si-' + curSort);
  if (el) el.className = `fas fa-sort-${curOrd === 'asc' ? 'up' : 'down'} si on`;
}

updateSortIcons();

/* ═══ PANEL ═══ */
function openPanel() {
  document.getElementById('overlay').classList.add('open');
  document.getElementById('panel').classList.add('open');
  setTimeout(() => document.getElementById('overlay').classList.add('vis'), 10);
  setTimeout(() => document.getElementById('p-name').focus(), 380);
}

function closePanel() {
  document.getElementById('overlay').classList.remove('vis');
  document.getElementById('panel').classList.remove('open');
  setTimeout(() => document.getElementById('overlay').classList.remove('open'), 350);
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

/* ═══ CUSTOM SPECIALTY ═══ */
function toggleCsp(val) {
  const wrap = document.getElementById('csp-wrap');
  const csp  = document.getElementById('p-csp');
  if (val === '__other__') {
    wrap.style.display = 'block';
    csp.focus();
  } else {
    wrap.style.display = 'none';
    csp.value = '';
  }
}

/* ═══ STAR ↔ NUMBER SYNC ═══ */
document.querySelectorAll('#sp input[type=radio]').forEach(r => {
  r.addEventListener('change', function() {
    document.getElementById('p-rat').value = parseFloat(this.value).toFixed(1);
    document.getElementById('fg-rat').classList.remove('err');
  });
});

document.getElementById('p-rat').addEventListener('input', function() {
  const n = parseFloat(this.value);
  if (!isNaN(n) && n >= 0 && n <= 5) {
    const rounded = Math.min(Math.max(Math.round(n), 1), 5);
    const radio   = document.getElementById('ps' + rounded);
    if (radio) radio.checked = true;
    document.getElementById('fg-rat').classList.remove('err');
  }
});

/* ═══ SAVE DOCTOR ═══ */
function saveDoctor() {
  const name = document.getElementById('p-name').value.trim();
  const sp   = document.getElementById('p-sp').value;
  const csp  = document.getElementById('p-csp').value.trim();
  const hosp = document.getElementById('p-hosp').value.trim();
  const city = document.getElementById('p-city').value.trim();
  const rat  = document.getElementById('p-rat').value.trim();
  const rev  = document.getElementById('p-rev').value.trim();
  const exp  = document.getElementById('p-exp').value.trim();

  let bad = false;
  function setE(id, hasErr) {
    document.getElementById(id).classList.toggle('err', hasErr);
    if (hasErr) bad = true;
  }

  setE('fg-name', !name);
  const spInvalid = !sp || (sp === '__other__' && !csp);
  setE('fg-sp',   spInvalid);
  setE('fg-hosp', !hosp);
  setE('fg-city', !city);
  setE('fg-rat',  rat === '' || isNaN(+rat) || +rat < 0 || +rat > 5);
  setE('fg-rev',  rev === '' || !/^\d+$/.test(rev));
  setE('fg-exp',  exp === '' || !/^\d+$/.test(exp));

  if (bad) {
    document.querySelector('.fg.err')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const btn = document.getElementById('btn-save');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Saving…';
  document.getElementById('perr').classList.remove('show');

  const fd = new FormData();
  fd.append('ajax_add',     '1');
  fd.append('name',         name);
  fd.append('specialty',    sp);
  fd.append('custom_sp',    csp);
  fd.append('hospital',     hosp);
  fd.append('city',         city);
  fd.append('rating',       rat);
  fd.append('total_reviews', rev);
  fd.append('experience',   exp);

  fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (sp === '__other__' && csp) {
          refreshSpecialtyDropdown(data.specialties || [], csp);
        }
        const tb  = document.getElementById('tbody');
        const tmp = document.createElement('tbody');
        tmp.innerHTML = rowJS(data.doctor, '', true);
        const newTr = tmp.firstElementChild;
        tb.prepend(newTr);

        document.getElementById('heroTotal').textContent = data.total;
        document.getElementById('countPill').innerHTML =
          `<strong>${data.total}</strong><span>Doctors</span>`;

        resetPanel();
        closePanel();
        showFlash('✓ Dr. ' + esc(data.doctor.name) + ' has been added successfully!', 'success');
        setTimeout(() => newTr.scrollIntoView({ behavior: 'smooth', block: 'center' }), 400);

      } else {
        const pErr = document.getElementById('perr');
        document.getElementById('perr-list').innerHTML =
          data.errors.map(e => `<li>${esc(e)}</li>`).join('');
        pErr.classList.add('show');
        pErr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    })
    .catch(() => showFlash('Network error — please try again.', 'error'))
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-save"></i> Save Doctor';
    });
}

function refreshSpecialtyDropdown(specialties, selectedValue) {
  const sel = document.getElementById('p-sp');
  sel.innerHTML = '<option value="">— Select specialty —</option>';
  specialties.forEach(sp => {
    const opt = document.createElement('option');
    opt.value = sp;
    opt.textContent = sp;
    sel.appendChild(opt);
  });
  const other = document.createElement('option');
  other.value = '__other__';
  other.textContent = '✚ Add new specialty…';
  sel.appendChild(other);
  sel.value = '';
}

function resetPanel() {
  ['p-name', 'p-hosp', 'p-city', 'p-exp', 'p-rev', 'p-rat'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('p-sp').value  = '';
  document.getElementById('p-csp').value = '';
  document.getElementById('csp-wrap').style.display = 'none';
  document.querySelectorAll('#sp input[type=radio]').forEach(r => r.checked = false);
  document.querySelectorAll('.fg').forEach(f => f.classList.remove('err'));
  document.getElementById('perr').classList.remove('show');
}

let flashTimeout;
function showFlash(msg, type) {
  clearTimeout(flashTimeout);
  const el   = document.getElementById('flash');
  const icon = document.getElementById('flash-icon');
  el.className = `flash ${type} show`;
  icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
  document.getElementById('flash-msg').textContent = msg;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  flashTimeout = setTimeout(() => el.classList.remove('show'), 5000);
}
</script>
</body>
</html>