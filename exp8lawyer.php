<?php
// ============================================================
//  LAWBRIDGE PORTAL — Complete PHP Application (Single File)
//  File: index.php
//  Requirements: PHP 8.0+, MySQL 5.7+, run database.sql first
// ============================================================

// ──────────────────────────────────────────────
//  DATABASE CONFIGURATION — change these values
// ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // ← your MySQL username
define('DB_PASS', '');           // ← your MySQL password
define('DB_NAME', 'lawbridge_portal');

// ──────────────────────────────────────────────
//  SESSION & CONNECTION BOOTSTRAP
// ──────────────────────────────────────────────
session_start();

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }
    // Ping and reconnect if connection was lost
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        $pdo = null;
        return getDB();
    }
    return $pdo;
}

// ──────────────────────────────────────────────
//  HELPER FUNCTIONS
// ──────────────────────────────────────────────
function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function stars(float $r): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($r >= $i)         $out .= '<i class="star full">★</i>';
        elseif ($r >= $i-0.5) $out .= '<i class="star half">★</i>';
        else                  $out .= '<i class="star empty">☆</i>';
    }
    return $out;
}

// ──────────────────────────────────────────────
//  POST ACTION HANDLERS
// ──────────────────────────────────────────────
$message = '';
$messageType = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- REGISTER ---
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $city  = trim($_POST['city'] ?? '');
    if ($name && $email && $pass) {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = "Email already registered. Please login.";
                $messageType = 'error';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $ins  = $db->prepare("INSERT INTO users(full_name,email,password_hash,phone,city) VALUES(?,?,?,?,?)");
                $ins->execute([$name, $email, $hash, $phone, $city]);
                $message = "Registration successful! Please login.";
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Please fill all required fields.";
        $messageType = 'error';
    }
}

// --- LOGIN ---
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user']    = $user;
            header('Location: ?page=search');
            exit;
        } else {
            $message = "Invalid email or password.";
            $messageType = 'error';
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// --- LOGOUT ---
if ($action === 'logout') {
    session_destroy();
    header('Location: ?page=home');
    exit;
}

// --- APPLY FOR CASE ---
if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    $lawyer_id   = (int)($_POST['lawyer_id'] ?? 0);
    $title       = trim($_POST['case_title'] ?? '');
    $type        = trim($_POST['case_type'] ?? '');
    $description = trim($_POST['case_description'] ?? '');
    $urgency     = $_POST['urgency'] ?? 'Medium';
    if ($lawyer_id && $title && $description) {
        try {
            $db  = getDB();
            // Check if user already has a pending application with this lawyer
            $chk = $db->prepare("SELECT id FROM case_applications WHERE user_id=? AND lawyer_id=? AND status IN ('Pending','Accepted','In Session')");
            $chk->execute([$_SESSION['user_id'], $lawyer_id]);
            if ($chk->fetch()) {
                $message = "You already have an active application with this lawyer.";
                $messageType = 'error';
            } else {
                $ins = $db->prepare("INSERT INTO case_applications(user_id,lawyer_id,case_title,case_type,case_description,urgency) VALUES(?,?,?,?,?,?)");
                $ins->execute([$_SESSION['user_id'], $lawyer_id, $title, $type, $description, $urgency]);
                // Notify lawyer (stored as user notification for demo — extend for lawyer auth)
                $appId = $db->lastInsertId();
                // Notify user
                $notif = $db->prepare("INSERT INTO notifications(user_id,message) VALUES(?,?)");
                $lname = $db->query("SELECT full_name FROM lawyers WHERE id=$lawyer_id")->fetchColumn();
                $notif->execute([$_SESSION['user_id'], "Your application for case '$title' has been sent to Advocate $lname. Please wait for confirmation."]);
                $message = "✓ Application submitted successfully! Advocate $lname will review your case shortly.";
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Please fill all required fields.";
        $messageType = 'error';
    }
}

// ──────────────────────────────────────────────
//  DATA FETCH HELPERS
// ──────────────────────────────────────────────
function getLawyers(string $spec='', string $search='', string $sort='rating', int $page=1): array {
    $db = getDB();
    $per = 10;
    $off = ($page - 1) * $per;
    $where = ['1=1'];
    $params = [];
    if ($spec)   { $where[] = 'specialization = ?'; $params[] = $spec; }
    if ($search) { $where[] = '(full_name LIKE ? OR bio LIKE ? OR city LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
    $orderMap = [
        'rating'       => 'rating DESC',
        'experience'   => 'experience_yrs DESC',
        'fee_low'      => 'fee_per_session ASC',
        'fee_high'     => 'fee_per_session DESC',
        'success_rate' => 'success_rate DESC',
    ];
    $order = $orderMap[$sort] ?? 'rating DESC';
    $sql   = "SELECT * FROM lawyers WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT $per OFFSET $off";
    $stmt  = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countLawyers(string $spec='', string $search=''): int {
    $db = getDB();
    $where = ['1=1'];
    $params = [];
    if ($spec)   { $where[] = 'specialization = ?'; $params[] = $spec; }
    if ($search) { $where[] = '(full_name LIKE ? OR bio LIKE ? OR city LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
    $sql  = "SELECT COUNT(*) FROM lawyers WHERE " . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function getLawyerById(int $id): ?array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM lawyers WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getUserApplications(int $uid): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT ca.*, l.full_name AS lawyer_name, l.specialization, l.photo_url, l.city AS lawyer_city, l.rating AS lawyer_rating
        FROM case_applications ca
        JOIN lawyers l ON ca.lawyer_id = l.id
        WHERE ca.user_id = ?
        ORDER BY ca.applied_at DESC
    ");
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

function getUserNotifications(int $uid): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

function unreadNotifCount(int $uid): int {
    $db   = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->execute([$uid]);
    return (int)$stmt->fetchColumn();
}

function markNotifsRead(int $uid): void {
    $db = getDB();
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
}

$specializations = [
    'Criminal Law','Civil Law','Family Law','Corporate Law',
    'Cyber Law','Property Law','Labour Law','Consumer Rights',
    'Tax Law','Human Rights'
];

$specIcons = [
    'Criminal Law'    => '⚖️',
    'Civil Law'       => '🏛️',
    'Family Law'      => '👨‍👩‍👧',
    'Corporate Law'   => '🏢',
    'Cyber Law'       => '💻',
    'Property Law'    => '🏠',
    'Labour Law'      => '👷',
    'Consumer Rights' => '🛡️',
    'Tax Law'         => '📊',
    'Human Rights'    => '✊',
];

// ──────────────────────────────────────────────
//  PAGE ROUTING
// ──────────────────────────────────────────────
$page = $_GET['page'] ?? 'home';
if (!isLoggedIn() && in_array($page, ['dashboard','apply'])) {
    header('Location: ?page=login');
    exit;
}
if ($page === 'notifications' && isLoggedIn()) {
    markNotifsRead($_SESSION['user_id']);
}

$notifCount = isLoggedIn() ? unreadNotifCount($_SESSION['user_id']) : 0;

// Read lawyer for detail page
$viewLawyer = null;
if ($page === 'lawyer' && isset($_GET['id'])) {
    $viewLawyer = getLawyerById((int)$_GET['id']);
}

// Search params
$filterSpec   = $_GET['spec']   ?? '';
$filterSearch = $_GET['q']      ?? '';
$filterSort   = $_GET['sort']   ?? 'rating';
$filterPage   = max(1, (int)($_GET['pg'] ?? 1));
$lawyers      = [];
$totalLawyers = 0;

if (in_array($page, ['search','home'])) {
    $lawyers      = getLawyers($filterSpec, $filterSearch, $filterSort, $filterPage);
    $totalLawyers = countLawyers($filterSpec, $filterSearch);
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LawBridge — Find Your Advocate</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   DESIGN SYSTEM — Deep Navy × Gold × Ivory
═══════════════════════════════════════════════════ */
:root{
  --navy:   #0a1628;
  --navy2:  #0e2040;
  --navy3:  #1a3358;
  --gold:   #c9913a;
  --gold2:  #e8b96b;
  --ivory:  #f8f4ed;
  --ivory2: #ede9e0;
  --cream:  #fdf9f3;
  --red:    #c0392b;
  --green:  #1e7e5e;
  --text:   #1c1c2e;
  --muted:  #6b6b80;
  --border: #d8cfc2;
  --shadow: 0 8px 32px rgba(10,22,40,.14);
  --shadow2:0 2px 12px rgba(10,22,40,.08);
  --radius: 14px;
  --font-head: 'Playfair Display', Georgia, serif;
  --font-body: 'DM Sans', sans-serif;
  --gutter: clamp(16px, 4vw, 40px);
}

*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{
  font-family:var(--font-body);
  background:var(--cream);
  color:var(--text);
  min-height:100vh;
  overflow-x:hidden;
}

/* ─── SCROLLBAR ─── */
::-webkit-scrollbar{width:7px;}
::-webkit-scrollbar-track{background:var(--ivory2);}
::-webkit-scrollbar-thumb{background:var(--navy3);border-radius:8px;}

/* ─── NAVBAR ─── */
.navbar{
  position:sticky;top:0;z-index:1000;
  background:var(--navy);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 var(--gutter);
  height:68px;
  box-shadow:0 2px 20px rgba(0,0,0,.3);
}
.navbar-brand{
  font-family:var(--font-head);
  font-size:1.55rem;font-weight:700;
  color:var(--gold2);
  text-decoration:none;
  letter-spacing:-.5px;
  display:flex;align-items:center;gap:10px;
}
.navbar-brand span{color:#fff;}
.nav-links{display:flex;align-items:center;gap:6px;}
.nav-links a{
  color:rgba(255,255,255,.78);
  text-decoration:none;font-size:.88rem;font-weight:500;
  padding:7px 14px;border-radius:8px;
  transition:all .22s;
}
.nav-links a:hover,.nav-links a.active{
  background:rgba(201,145,58,.18);color:var(--gold2);
}
.nav-badge{
  display:inline-flex;align-items:center;justify-content:center;
  background:var(--gold);color:#fff;
  border-radius:50%;width:18px;height:18px;font-size:.65rem;font-weight:700;
  margin-left:4px;vertical-align:middle;
  animation:pulse 2s infinite;
}
@keyframes pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.2);}}
.btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 22px;border-radius:10px;
  font-family:var(--font-body);font-size:.9rem;font-weight:600;
  cursor:pointer;text-decoration:none;border:none;
  transition:all .22s;
}
.btn-gold{background:var(--gold);color:#fff;}
.btn-gold:hover{background:var(--gold2);transform:translateY(-1px);box-shadow:var(--shadow2);}
.btn-outline{background:transparent;border:2px solid var(--gold);color:var(--gold);}
.btn-outline:hover{background:var(--gold);color:#fff;}
.btn-navy{background:var(--navy);color:#fff;}
.btn-navy:hover{background:var(--navy3);transform:translateY(-1px);}
.btn-sm{padding:7px 16px;font-size:.82rem;}
.btn-danger{background:var(--red);color:#fff;}
.btn-success{background:var(--green);color:#fff;}

/* ─── HERO ─── */
.hero{
  position:relative;
  background:var(--navy);
  padding:80px var(--gutter) 60px;
  overflow:hidden;
  text-align:center;
}
.hero::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 30% 50%, rgba(201,145,58,.18) 0%, transparent 60%),
             radial-gradient(ellipse at 80% 20%, rgba(201,145,58,.1) 0%, transparent 50%);
  pointer-events:none;
}
.hero-scales{
  position:absolute;right:5%;bottom:0;
  font-size:12rem;opacity:.04;line-height:1;
  pointer-events:none;
  animation:float 8s ease-in-out infinite;
}
@keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-20px);}}
.hero h1{
  font-family:var(--font-head);
  font-size:clamp(2rem,5vw,3.6rem);font-weight:900;
  color:#fff;line-height:1.1;
  animation:fadeUp .8s ease both;
}
.hero h1 em{color:var(--gold2);font-style:normal;}
.hero p{
  color:rgba(255,255,255,.65);
  font-size:clamp(.95rem,2vw,1.15rem);
  max-width:600px;margin:18px auto 36px;
  animation:fadeUp .8s .15s ease both;
  opacity:0;animation-fill-mode:forwards;
}
.hero-stats{
  display:flex;justify-content:center;gap:40px;flex-wrap:wrap;
  margin-top:36px;
  animation:fadeUp .8s .3s ease both;
  opacity:0;animation-fill-mode:forwards;
}
.hero-stat{text-align:center;}
.hero-stat strong{display:block;font-size:1.8rem;font-weight:700;color:var(--gold2);}
.hero-stat span{font-size:.82rem;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.08em;}

@keyframes fadeUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}

/* ─── SEARCH BAR ─── */
.search-section{
  background:var(--navy2);
  padding:28px var(--gutter);
  position:sticky;top:68px;z-index:900;
  border-bottom:1px solid rgba(201,145,58,.2);
}
.search-row{
  max-width:1100px;margin:0 auto;
  display:flex;gap:12px;flex-wrap:wrap;align-items:center;
}
.search-row input,.search-row select{
  font-family:var(--font-body);font-size:.9rem;
  background:rgba(255,255,255,.07);
  border:1.5px solid rgba(201,145,58,.25);
  color:#fff;border-radius:10px;padding:11px 16px;
  outline:none;transition:border-color .2s;
}
.search-row input::placeholder{color:rgba(255,255,255,.4);}
.search-row input:focus,.search-row select:focus{border-color:var(--gold);}
.search-row input{flex:1;min-width:200px;}
.search-row select option{background:var(--navy2);color:#fff;}
.search-row select{min-width:170px;}

/* ─── SECTION LAYOUTS ─── */
.container{max-width:1200px;margin:0 auto;padding:0 var(--gutter);}
.section{padding:52px 0;}
.section-title{
  font-family:var(--font-head);
  font-size:clamp(1.5rem,3vw,2rem);
  font-weight:700;color:var(--navy);
  margin-bottom:8px;
}
.section-sub{color:var(--muted);font-size:.95rem;margin-bottom:32px;}
.divider{width:60px;height:4px;background:var(--gold);border-radius:2px;margin:12px 0 28px;}

/* ─── SPEC GRID ─── */
.spec-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(130px,1fr));
  gap:14px;margin:32px 0;
}
.spec-card{
  background:var(--ivory);border:2px solid transparent;
  border-radius:14px;padding:20px 12px;text-align:center;
  cursor:pointer;transition:all .25s;text-decoration:none;
  color:var(--navy);
}
.spec-card:hover,.spec-card.active{
  border-color:var(--gold);background:#fff;
  box-shadow:0 6px 24px rgba(201,145,58,.2);
  transform:translateY(-3px);
}
.spec-card .icon{font-size:2rem;display:block;margin-bottom:8px;}
.spec-card .label{font-size:.78rem;font-weight:600;line-height:1.3;color:var(--navy);}

/* ─── LAWYER CARDS GRID ─── */
.lawyers-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
  gap:22px;
  margin-top:8px;
}
.lawyer-card{
  background:#fff;border-radius:var(--radius);
  border:1.5px solid var(--border);
  overflow:hidden;
  transition:all .28s;
  animation:cardIn .5s ease both;
  position:relative;
}
.lawyer-card:hover{
  box-shadow:var(--shadow);border-color:var(--gold);
  transform:translateY(-4px);
}
@keyframes cardIn{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.lawyer-card-header{
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy3) 100%);
  padding:22px 20px 18px;
  display:flex;align-items:center;gap:14px;
  position:relative;
}
.lawyer-avatar{
  width:64px;height:64px;border-radius:50%;
  background:linear-gradient(135deg,var(--gold),var(--gold2));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:1.4rem;font-weight:700;
  color:#fff;flex-shrink:0;
  border:3px solid rgba(255,255,255,.2);
  box-shadow:0 4px 14px rgba(0,0,0,.2);
}
.lawyer-header-info{flex:1;}
.lawyer-name{
  font-family:var(--font-head);font-size:1rem;font-weight:700;
  color:#fff;line-height:1.25;
}
.lawyer-spec{
  font-size:.75rem;color:var(--gold2);font-weight:600;
  text-transform:uppercase;letter-spacing:.06em;
  margin-top:3px;
}
.lawyer-city{font-size:.78rem;color:rgba(255,255,255,.5);margin-top:2px;}
.avail-badge{
  position:absolute;top:14px;right:14px;
  padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;
}
.avail-yes{background:rgba(30,126,94,.8);color:#a8f0d8;}
.avail-no{background:rgba(192,57,43,.7);color:#f9b8b3;}
.lawyer-card-body{padding:18px 20px;}
.lawyer-meta{
  display:grid;grid-template-columns:1fr 1fr;gap:10px;
  margin-bottom:16px;
}
.meta-item{
  background:var(--ivory);border-radius:10px;
  padding:10px 14px;
  border:1px solid var(--border);
}
.meta-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:600;}
.meta-value{font-size:1rem;font-weight:700;color:var(--navy);margin-top:2px;}
.meta-value.gold{color:var(--gold);}
.meta-value.green{color:var(--green);}
.lawyer-rating{display:flex;align-items:center;gap:6px;margin-bottom:12px;}
.star{font-style:normal;font-size:1rem;}
.star.full{color:var(--gold);}
.star.half{color:var(--gold2);}
.star.empty{color:var(--border);}
.rating-num{font-weight:700;color:var(--navy);font-size:.9rem;}
.lawyer-bio{
  font-size:.82rem;color:var(--muted);line-height:1.55;
  display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
  margin-bottom:16px;
}
.lawyer-fee{
  display:flex;align-items:center;justify-content:space-between;
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy3) 100%);
  border-radius:10px;padding:12px 16px;
  margin-bottom:14px;
}
.fee-label{font-size:.75rem;color:rgba(255,255,255,.6);font-weight:600;}
.fee-amount{font-family:var(--font-head);font-size:1.1rem;font-weight:700;color:var(--gold2);}
.card-actions{display:flex;gap:10px;}
.card-actions .btn{flex:1;justify-content:center;font-size:.83rem;}

/* ─── PAGINATION ─── */
.pagination{
  display:flex;align-items:center;gap:8px;
  justify-content:center;margin-top:36px;
}
.page-btn{
  width:38px;height:38px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  background:#fff;border:1.5px solid var(--border);
  color:var(--navy);font-weight:600;font-size:.88rem;
  text-decoration:none;transition:all .2s;
}
.page-btn:hover,.page-btn.active{
  background:var(--gold);color:#fff;border-color:var(--gold);
}

/* ─── LAWYER DETAIL ─── */
.detail-hero{
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy3) 100%);
  padding:48px var(--gutter);
}
.detail-hero-inner{
  max-width:1100px;margin:0 auto;
  display:flex;gap:32px;align-items:flex-start;flex-wrap:wrap;
}
.detail-avatar{
  width:120px;height:120px;border-radius:50%;
  background:linear-gradient(135deg,var(--gold),var(--gold2));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:2.8rem;font-weight:700;color:#fff;
  border:4px solid rgba(255,255,255,.2);
  box-shadow:0 8px 30px rgba(0,0,0,.3);
  flex-shrink:0;
}
.detail-info{flex:1;min-width:260px;}
.detail-info h1{
  font-family:var(--font-head);font-size:clamp(1.5rem,3vw,2.2rem);
  color:#fff;font-weight:700;margin-bottom:8px;
}
.detail-badges{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.badge{
  padding:5px 14px;border-radius:20px;font-size:.76rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;
}
.badge-gold{background:rgba(201,145,58,.25);color:var(--gold2);}
.badge-green{background:rgba(30,126,94,.25);color:#4ecba3;}
.badge-blue{background:rgba(60,130,200,.25);color:#7bc0f5;}
.detail-stats{
  display:grid;grid-template-columns:repeat(4,1fr);gap:14px;
  max-width:1100px;margin:0 auto;
  padding:0 var(--gutter);
  transform:translateY(-28px);
}
.stat-card{
  background:#fff;border-radius:12px;padding:20px;
  text-align:center;box-shadow:var(--shadow);
  border:1.5px solid var(--border);
}
.stat-card .val{
  font-family:var(--font-head);font-size:1.7rem;font-weight:700;
  color:var(--gold);
}
.stat-card .lbl{font-size:.75rem;color:var(--muted);margin-top:4px;font-weight:600;}
.detail-content{
  max-width:1100px;margin:0 auto;
  padding:0 var(--gutter) 48px;
  display:grid;grid-template-columns:1fr 380px;gap:28px;
}
.detail-bio{
  background:#fff;border-radius:var(--radius);padding:28px;
  border:1.5px solid var(--border);
}
.detail-bio h3{
  font-family:var(--font-head);font-size:1.1rem;
  color:var(--navy);margin-bottom:14px;
}
.detail-bio p{color:var(--muted);line-height:1.7;font-size:.92rem;}
.apply-card{
  background:#fff;border-radius:var(--radius);padding:28px;
  border:2px solid var(--gold);
  box-shadow:0 8px 30px rgba(201,145,58,.12);
  position:sticky;top:120px;
}
.apply-card h3{
  font-family:var(--font-head);font-size:1.2rem;
  color:var(--navy);margin-bottom:18px;
  display:flex;align-items:center;gap:8px;
}

/* ─── FORMS ─── */
.form-group{margin-bottom:16px;}
.form-group label{
  display:block;font-size:.82rem;font-weight:600;
  color:var(--navy);margin-bottom:6px;
}
.form-group input,.form-group textarea,.form-group select{
  width:100%;
  font-family:var(--font-body);font-size:.9rem;
  background:var(--ivory);
  border:1.5px solid var(--border);
  border-radius:10px;padding:11px 14px;
  outline:none;transition:border-color .2s;
  color:var(--text);
}
.form-group input:focus,.form-group textarea:focus,.form-group select:focus{
  border-color:var(--gold);background:#fff;
}
.form-group textarea{resize:vertical;min-height:100px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}

/* ─── AUTH PAGES ─── */
.auth-wrap{
  min-height:calc(100vh - 68px);
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 50%,var(--navy3) 100%);
  padding:32px var(--gutter);
}
.auth-card{
  background:#fff;border-radius:20px;
  padding:40px;max-width:440px;width:100%;
  box-shadow:0 24px 80px rgba(0,0,0,.35);
  animation:fadeUp .5s ease;
}
.auth-card h2{
  font-family:var(--font-head);font-size:1.8rem;
  color:var(--navy);margin-bottom:6px;
}
.auth-card p{color:var(--muted);font-size:.88rem;margin-bottom:28px;}

/* ─── DASHBOARD ─── */
.dashboard-header{
  background:var(--navy);padding:36px var(--gutter) 28px;
}
.dashboard-header h1{
  font-family:var(--font-head);color:#fff;
  font-size:clamp(1.4rem,3vw,2rem);
  display:flex;align-items:center;gap:12px;
}
.dashboard-header p{color:rgba(255,255,255,.55);margin-top:6px;font-size:.9rem;}
.applications-table{width:100%;border-collapse:collapse;}
.applications-table th{
  background:var(--navy);color:rgba(255,255,255,.7);
  font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;
  padding:12px 16px;text-align:left;
}
.applications-table td{
  padding:14px 16px;border-bottom:1px solid var(--border);
  font-size:.88rem;vertical-align:middle;
}
.applications-table tr:last-child td{border-bottom:none;}
.applications-table tr:hover td{background:var(--ivory);}

/* ─── ALERT / MESSAGE ─── */
.alert{
  padding:14px 20px;border-radius:10px;font-size:.9rem;font-weight:500;
  margin-bottom:20px;display:flex;align-items:center;gap:10px;
  animation:fadeUp .4s ease;
}
.alert-success{background:#d1e7dd;color:#0f5132;border:1px solid #a3cfbb;}
.alert-error{background:#f8d7da;color:#842029;border:1px solid #f1aeb5;}

/* ─── NOTIFICATIONS ─── */
.notif-item{
  background:#fff;border-radius:12px;border:1.5px solid var(--border);
  padding:16px 20px;margin-bottom:12px;
  display:flex;align-items:flex-start;gap:12px;
  animation:fadeUp .4s ease;
}
.notif-icon{font-size:1.3rem;flex-shrink:0;margin-top:2px;}
.notif-text{font-size:.88rem;line-height:1.5;color:var(--text);}
.notif-time{font-size:.75rem;color:var(--muted);margin-top:4px;}
.notif-unread{border-left:4px solid var(--gold);}

/* ─── EMPTY STATE ─── */
.empty-state{
  text-align:center;padding:64px 24px;
  color:var(--muted);
}
.empty-state .icon{font-size:4rem;display:block;margin-bottom:16px;opacity:.4;}
.empty-state h3{font-family:var(--font-head);font-size:1.3rem;color:var(--navy);margin-bottom:8px;}

/* ─── RESULTS BAR ─── */
.results-bar{
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:10px;
  padding:16px 0;border-bottom:1.5px solid var(--border);
  margin-bottom:24px;
}
.results-bar .count{font-size:.88rem;color:var(--muted);}
.results-bar strong{color:var(--navy);}

/* ─── FOOTER ─── */
.footer{
  background:var(--navy);color:rgba(255,255,255,.45);
  text-align:center;padding:32px var(--gutter);
  font-size:.82rem;
}
.footer a{color:var(--gold2);text-decoration:none;}
.footer-brand{
  font-family:var(--font-head);font-size:1.2rem;
  color:var(--gold2);margin-bottom:8px;
}

/* ─── MODAL OVERLAY ─── */
.modal-overlay{
  position:fixed;inset:0;background:rgba(10,22,40,.75);
  z-index:2000;display:flex;align-items:center;justify-content:center;
  backdrop-filter:blur(4px);
  opacity:0;pointer-events:none;transition:opacity .3s;
}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal{
  background:#fff;border-radius:20px;
  max-width:520px;width:calc(100% - 32px);
  max-height:90vh;overflow-y:auto;
  padding:36px;position:relative;
  transform:translateY(30px) scale(.97);transition:transform .3s;
  box-shadow:0 32px 80px rgba(0,0,0,.35);
}
.modal-overlay.open .modal{transform:translateY(0) scale(1);}
.modal-close{
  position:absolute;top:16px;right:16px;
  width:32px;height:32px;border-radius:8px;
  background:var(--ivory2);border:none;cursor:pointer;
  font-size:1.1rem;display:flex;align-items:center;justify-content:center;
  color:var(--muted);transition:all .2s;
}
.modal-close:hover{background:var(--border);color:var(--navy);}
.modal h2{font-family:var(--font-head);font-size:1.4rem;color:var(--navy);margin-bottom:6px;}
.modal p.sub{color:var(--muted);font-size:.85rem;margin-bottom:22px;}

/* ─── PROGRESS BAR ─── */
.progress-bar{
  background:var(--border);border-radius:99px;height:8px;overflow:hidden;
}
.progress-fill{
  height:100%;background:linear-gradient(90deg,var(--gold),var(--gold2));
  border-radius:99px;transition:width .6s ease;
}

/* ─── RESPONSIVE ─── */
@media(max-width:900px){
  .detail-content{grid-template-columns:1fr;}
  .detail-stats{grid-template-columns:repeat(2,1fr);}
  .apply-card{position:static;}
}
@media(max-width:600px){
  .navbar-brand{font-size:1.2rem;}
  .nav-links a span{display:none;}
  .detail-stats{grid-template-columns:repeat(2,1fr);}
  .lawyers-grid{grid-template-columns:1fr;}
  .form-row{grid-template-columns:1fr;}
}

/* ─── LOADING SHIMMER ─── */
.shimmer{
  background:linear-gradient(90deg,var(--ivory2) 25%,var(--ivory) 50%,var(--ivory2) 75%);
  background-size:200% 100%;
  animation:shimmer 1.6s infinite;
}
@keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}

/* ─── SCROLL REVEAL ─── */
.reveal{opacity:0;transform:translateY(24px);transition:all .6s ease;}
.reveal.visible{opacity:1;transform:translateY(0);}

/* ─── URGENCY PILLS ─── */
.urgency-Low{color:#0f5132;background:#d1e7dd;}
.urgency-Medium{color:#856404;background:#fff3cd;}
.urgency-High{color:#842029;background:#f8d7da;}
.urgency-Critical{color:#fff;background:var(--red);}
.urgency-pill{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;}

/* ─── APPLIED TEXT ─── */
.applied-text{
  font-size:.85rem;font-weight:600;
  color:var(--green);
}
</style>
</head>
<body>

<!-- ═══════════════════════════════ NAVBAR ═══════════════════════════════ -->
<nav class="navbar">
  <a href="?page=home" class="navbar-brand">⚖️ Law<span>Bridge</span></a>
  <div class="nav-links">
    <a href="?page=home" class="<?= $page==='home'?'active':'' ?>">🏠 <span>Home</span></a>
    <a href="?page=search" class="<?= $page==='search'?'active':'' ?>">🔍 <span>Find Lawyer</span></a>
    <?php if(isLoggedIn()): ?>
      <a href="?page=dashboard" class="<?= $page==='dashboard'?'active':'' ?>">📂 <span>My Cases</span></a>
      <a href="?page=notifications" class="<?= $page==='notifications'?'active':'' ?>">
        🔔 <span>Alerts</span><?php if($notifCount>0): ?><sup class="nav-badge"><?= $notifCount ?></sup><?php endif; ?>
      </a>
      <a href="?action=logout" class="btn btn-outline btn-sm" style="margin-left:8px">Sign Out</a>
    <?php else: ?>
      <a href="?page=login" class="<?= $page==='login'?'active':'' ?>">Login</a>
      <a href="?page=register" class="btn btn-gold btn-sm" style="margin-left:8px">Register</a>
    <?php endif; ?>
  </div>
</nav>

<!-- ═══════════════════════════════ GLOBAL MESSAGE ═══════════════════════ -->
<?php if($message): ?>
<div style="padding:12px var(--gutter);background:var(--cream);">
  <div class="container">
    <div class="alert alert-<?= $messageType ?>">
      <?= $messageType==='success' ? '✅' : '❌' ?> <?= e($message) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════ PAGES ══════════════════════════════════ -->

<?php if($page === 'home'): ?>
<!-- ─────────────── HOME PAGE ─────────────── -->
<section class="hero">
  <div class="hero-scales">⚖️</div>
  <h1>Find the Right <em>Advocate</em><br>for Your Case</h1>
  <p>Browse <?= countLawyers() ?>+ verified lawyers across 10 practice areas. Get real consultations — not just advice.</p>
  <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;animation:fadeUp .8s .25s ease both;opacity:0;animation-fill-mode:forwards;">
    <a href="?page=search" class="btn btn-gold" style="font-size:1rem;padding:14px 32px;">Find a Lawyer →</a>
    <?php if(!isLoggedIn()): ?>
    <a href="?page=register" class="btn btn-outline" style="font-size:1rem;padding:14px 32px;">Create Account</a>
    <?php endif; ?>
  </div>
  <div class="hero-stats">
    <div class="hero-stat"><strong><?= countLawyers() ?>+</strong><span>Verified Lawyers</span></div>
    <div class="hero-stat"><strong>10</strong><span>Practice Areas</span></div>
    <div class="hero-stat"><strong>₹1,400</strong><span>Starting Fee</span></div>
    <div class="hero-stat"><strong>95%</strong><span>Top Success Rate</span></div>
  </div>
</section>

<div class="container section">
  <div class="section-title">Browse by Practice Area</div>
  <div class="divider"></div>
  <div class="spec-grid">
    <?php foreach($specializations as $spec): ?>
    <a href="?page=search&spec=<?= urlencode($spec) ?>" class="spec-card reveal">
      <span class="icon"><?= $specIcons[$spec] ?? '⚖️' ?></span>
      <span class="label"><?= e($spec) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- FEATURED LAWYERS -->
<div style="background:var(--ivory);padding:52px 0;">
<div class="container">
  <div class="section-title">Featured Advocates</div>
  <div class="divider"></div>
  <div class="lawyers-grid">
    <?php
    $featured = getLawyers('','','rating',1);
    foreach(array_slice($featured,0,6) as $i=>$l):
      $initials = implode('',array_map(fn($w)=>$w[0], array_slice(explode(' ',$l['full_name']),0,2)));
    ?>
    <div class="lawyer-card reveal" style="animation-delay:<?= $i*0.08 ?>s">
      <div class="lawyer-card-header">
        <div class="lawyer-avatar"><?= e($initials) ?></div>
        <div class="lawyer-header-info">
          <div class="lawyer-name"><?= e($l['full_name']) ?></div>
          <div class="lawyer-spec"><?= $specIcons[$l['specialization']] ?? '' ?> <?= e($l['specialization']) ?></div>
          <div class="lawyer-city">📍 <?= e($l['city']) ?></div>
        </div>
        <span class="avail-badge <?= $l['is_available']?'avail-yes':'avail-no' ?>">
          <?= $l['is_available']?'Available':'Busy' ?>
        </span>
      </div>
      <div class="lawyer-card-body">
        <div class="lawyer-rating">
          <?= stars((float)$l['rating']) ?>
          <span class="rating-num"><?= number_format($l['rating'],1) ?></span>
          <span style="font-size:.75rem;color:var(--muted);">(<?= $l['total_cases'] ?> cases)</span>
        </div>
        <div class="lawyer-meta">
          <div class="meta-item">
            <div class="meta-label">Experience</div>
            <div class="meta-value"><?= $l['experience_yrs'] ?> <small style="font-size:.7rem;font-weight:400">yrs</small></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Success Rate</div>
            <div class="meta-value green"><?= $l['success_rate'] ?>%</div>
          </div>
        </div>
        <div class="progress-bar" style="margin-bottom:14px;" title="Success Rate">
          <div class="progress-fill" style="width:<?= $l['success_rate'] ?>%"></div>
        </div>
        <p class="lawyer-bio"><?= e($l['bio']) ?></p>
        <div class="lawyer-fee">
          <div>
            <div class="fee-label">Session Fee</div>
            <div class="fee-amount">₹<?= number_format($l['fee_per_session'],0) ?></div>
          </div>
          <div style="font-size:.75rem;color:rgba(255,255,255,.45);">Bar ID: <?= e($l['bar_council_id']) ?></div>
        </div>
        <div class="card-actions">
          <a href="?page=lawyer&id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">View Profile</a>
          <?php if(isLoggedIn()): ?>
          <a href="#apply-modal" class="btn btn-gold btn-sm" onclick="openApply(<?= $l['id'] ?>, '<?= e($l['full_name']) ?>', '<?= e($l['specialization']) ?>')">Apply Now</a>
          <?php else: ?>
          <a href="?page=login" class="btn btn-navy btn-sm">Login to Apply</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:32px;">
    <a href="?page=search" class="btn btn-gold">View All Lawyers →</a>
  </div>
</div>
</div>

<!-- HOW IT WORKS -->
<div class="container section">
  <div class="section-title">How LawBridge Works</div>
  <div class="divider"></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;margin-top:8px;">
    <?php $steps=[
      ['🔍','Search','Browse verified lawyers by specialization, location, rating, or fee.'],
      ['👤','Choose','View detailed profiles — experience, success rate, bio, and fees.'],
      ['📋','Apply','Submit your case description and select urgency level.'],
      ['💬','Connect','Lawyer reviews your case and schedules a real consultation session.'],
    ]; foreach($steps as $i=>$s): ?>
    <div class="reveal" style="text-align:center;padding:28px 20px;background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);animation-delay:<?= $i*0.1 ?>s">
      <div style="font-size:2.5rem;margin-bottom:14px;"><?= $s[0] ?></div>
      <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--gold);margin-bottom:8px;">Step <?= $i+1 ?></div>
      <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700;color:var(--navy);margin-bottom:8px;"><?= $s[1] ?></div>
      <div style="font-size:.84rem;color:var(--muted);line-height:1.55;"><?= $s[2] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif($page === 'search'): ?>
<!-- ─────────────── SEARCH PAGE ─────────────── -->
<div class="search-section">
  <form method="GET" class="search-row">
    <input type="hidden" name="page" value="search">
    <input type="text" name="q" placeholder="🔍  Search by name, city, or keyword..." value="<?= e($filterSearch) ?>">
    <select name="spec">
      <option value="">All Practice Areas</option>
      <?php foreach($specializations as $s): ?>
      <option value="<?= e($s) ?>" <?= $filterSpec===$s?'selected':'' ?>><?= $specIcons[$s]??'' ?> <?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort">
      <option value="rating"       <?= $filterSort==='rating'?'selected':'' ?>>Sort: Top Rated</option>
      <option value="success_rate" <?= $filterSort==='success_rate'?'selected':'' ?>>Sort: Success Rate</option>
      <option value="experience"   <?= $filterSort==='experience'?'selected':'' ?>>Sort: Experience</option>
      <option value="fee_low"      <?= $filterSort==='fee_low'?'selected':'' ?>>Sort: Fee Low→High</option>
      <option value="fee_high"     <?= $filterSort==='fee_high'?'selected':'' ?>>Sort: Fee High→Low</option>
    </select>
    <button type="submit" class="btn btn-gold">Search</button>
  </form>
</div>

<div class="container section">
  <!-- Spec pills -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <a href="?page=search" class="badge <?= !$filterSpec?'badge-gold':'badge-blue' ?>" style="text-decoration:none;padding:6px 16px;border-radius:20px;font-size:.78rem;cursor:pointer;">All Areas</a>
    <?php foreach($specializations as $s): ?>
    <a href="?page=search&spec=<?= urlencode($s) ?>&sort=<?= e($filterSort) ?>" 
       class="badge <?= $filterSpec===$s?'badge-gold':'badge-blue' ?>"
       style="text-decoration:none;padding:6px 16px;border-radius:20px;font-size:.78rem;cursor:pointer;">
      <?= $specIcons[$s]??'' ?> <?= e($s) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="results-bar">
    <div class="count">Showing <strong><?= count($lawyers) ?></strong> of <strong><?= $totalLawyers ?></strong> lawyers<?= $filterSpec?" in <strong>".e($filterSpec)."</strong>":'' ?><?= $filterSearch?" for <strong>\"".e($filterSearch)."\"</strong>":'' ?></div>
    <?php if($filterSpec||$filterSearch): ?>
    <a href="?page=search" style="font-size:.82rem;color:var(--gold);text-decoration:none;">✕ Clear filters</a>
    <?php endif; ?>
  </div>

  <?php if(empty($lawyers)): ?>
  <div class="empty-state">
    <span class="icon">🔍</span>
    <h3>No lawyers found</h3>
    <p>Try a different search term or practice area.</p>
    <a href="?page=search" class="btn btn-gold" style="margin-top:20px">Clear Search</a>
  </div>
  <?php else: ?>
  <div class="lawyers-grid">
    <?php foreach($lawyers as $i=>$l):
      $initials = implode('',array_map(fn($w)=>$w[0], array_slice(explode(' ',$l['full_name']),0,2)));
    ?>
    <div class="lawyer-card" style="animation-delay:<?= ($i%10)*0.05 ?>s">
      <div class="lawyer-card-header">
        <div class="lawyer-avatar"><?= e($initials) ?></div>
        <div class="lawyer-header-info">
          <div class="lawyer-name"><?= e($l['full_name']) ?></div>
          <div class="lawyer-spec"><?= $specIcons[$l['specialization']]??'' ?> <?= e($l['specialization']) ?></div>
          <div class="lawyer-city">📍 <?= e($l['city']) ?></div>
        </div>
        <span class="avail-badge <?= $l['is_available']?'avail-yes':'avail-no' ?>">
          <?= $l['is_available']?'Available':'Busy' ?>
        </span>
      </div>
      <div class="lawyer-card-body">
        <div class="lawyer-rating">
          <?= stars((float)$l['rating']) ?>
          <span class="rating-num"><?= number_format($l['rating'],1) ?></span>
          <span style="font-size:.75rem;color:var(--muted);">(<?= $l['total_cases'] ?> cases)</span>
        </div>
        <div class="lawyer-meta">
          <div class="meta-item">
            <div class="meta-label">Experience</div>
            <div class="meta-value"><?= $l['experience_yrs'] ?> <small style="font-size:.7rem;font-weight:400">yrs</small></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Success Rate</div>
            <div class="meta-value green"><?= $l['success_rate'] ?>%</div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Total Cases</div>
            <div class="meta-value"><?= $l['total_cases'] ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Session Fee</div>
            <div class="meta-value gold">₹<?= number_format($l['fee_per_session'],0) ?></div>
          </div>
        </div>
        <div class="progress-bar" style="margin-bottom:14px;" title="Success: <?= $l['success_rate'] ?>%">
          <div class="progress-fill" style="width:<?= $l['success_rate'] ?>%"></div>
        </div>
        <p class="lawyer-bio"><?= e($l['bio']) ?></p>
        <div class="lawyer-fee">
          <div>
            <div class="fee-label">Session Fee</div>
            <div class="fee-amount">₹<?= number_format($l['fee_per_session'],0) ?></div>
          </div>
          <div style="font-size:.75rem;color:rgba(255,255,255,.45);">Bar ID: <?= e($l['bar_council_id']) ?></div>
        </div>
        <div class="card-actions">
          <a href="?page=lawyer&id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">View Profile</a>
          <?php if(isLoggedIn()): ?>
          <button class="btn btn-gold btn-sm" onclick="openApply(<?= $l['id'] ?>, '<?= e(addslashes($l['full_name'])) ?>', '<?= e($l['specialization']) ?>')">
            📋 Apply Now
          </button>
          <?php else: ?>
          <a href="?page=login" class="btn btn-navy btn-sm">Login to Apply</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php
  $totalPages = ceil($totalLawyers / 10);
  if($totalPages > 1):
    $base = "?page=search&spec=".urlencode($filterSpec)."&q=".urlencode($filterSearch)."&sort=".urlencode($filterSort);
  ?>
  <div class="pagination">
    <?php if($filterPage > 1): ?>
    <a href="<?= $base ?>&pg=<?= $filterPage-1 ?>" class="page-btn">‹</a>
    <?php endif; ?>
    <?php for($p=max(1,$filterPage-2); $p<=min($totalPages,$filterPage+2); $p++): ?>
    <a href="<?= $base ?>&pg=<?= $p ?>" class="page-btn <?= $p===$filterPage?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if($filterPage < $totalPages): ?>
    <a href="<?= $base ?>&pg=<?= $filterPage+1 ?>" class="page-btn">›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php elseif($page === 'lawyer' && $viewLawyer): ?>
<!-- ─────────────── LAWYER DETAIL PAGE ─────────────── -->
<?php $l = $viewLawyer;
$initials = implode('',array_map(fn($w)=>$w[0], array_slice(explode(' ',$l['full_name']),0,2)));
?>
<div class="detail-hero">
  <div class="detail-hero-inner container">
    <div class="detail-avatar"><?= e($initials) ?></div>
    <div class="detail-info">
      <h1><?= e($l['full_name']) ?></h1>
      <div class="detail-badges">
        <span class="badge badge-gold"><?= $specIcons[$l['specialization']]??'' ?> <?= e($l['specialization']) ?></span>
        <span class="badge badge-blue">📍 <?= e($l['city']) ?></span>
        <span class="badge <?= $l['is_available']?'badge-green':'badge-gold' ?>">
          <?= $l['is_available']?'✅ Available':'⏳ Busy' ?>
        </span>
      </div>
      <div class="lawyer-rating" style="margin-bottom:12px;">
        <?= stars((float)$l['rating']) ?>
        <span class="rating-num" style="color:var(--gold2);"><?= number_format($l['rating'],1) ?>/5.0</span>
        <span style="font-size:.8rem;color:rgba(255,255,255,.45);"><?= $l['total_cases'] ?> total cases</span>
      </div>
      <div style="font-size:.82rem;color:rgba(255,255,255,.5);">Bar Council ID: <?= e($l['bar_council_id']) ?></div>
    </div>
  </div>
</div>

<div class="detail-stats container">
  <div class="stat-card reveal">
    <div class="val"><?= $l['experience_yrs'] ?></div>
    <div class="lbl">Years Experience</div>
  </div>
  <div class="stat-card reveal" style="animation-delay:.08s">
    <div class="val"><?= $l['success_rate'] ?>%</div>
    <div class="lbl">Success Rate</div>
  </div>
  <div class="stat-card reveal" style="animation-delay:.16s">
    <div class="val"><?= $l['total_cases'] ?></div>
    <div class="lbl">Cases Handled</div>
  </div>
  <div class="stat-card reveal" style="animation-delay:.24s">
    <div class="val">₹<?= number_format($l['fee_per_session'],0) ?></div>
    <div class="lbl">Session Fee</div>
  </div>
</div>

<div class="detail-content container">
  <div>
    <div class="detail-bio reveal">
      <h3>About <?= e($l['full_name']) ?></h3>
      <p><?= nl2br(e($l['bio'])) ?></p>
    </div>
    <div class="detail-bio reveal" style="margin-top:20px;animation-delay:.1s">
      <h3>Success Rate Overview</h3>
      <div style="margin:16px 0 8px;display:flex;justify-content:space-between;font-size:.82rem;font-weight:600;color:var(--navy);">
        <span>Win Rate</span><span><?= $l['success_rate'] ?>%</span>
      </div>
      <div class="progress-bar" style="height:14px;">
        <div class="progress-fill" style="width:<?= $l['success_rate'] ?>%"></div>
      </div>
      <p style="margin-top:16px;font-size:.84rem;color:var(--muted);">
        Based on <?= $l['total_cases'] ?> cases handled across <?= e($l['specialization']) ?> matters in <?= e($l['city']) ?>.
      </p>
    </div>
  </div>

  <div>
    <div class="apply-card reveal">
      <h3>📋 Apply for Consultation</h3>
      <?php if(!isLoggedIn()): ?>
        <p style="font-size:.87rem;color:var(--muted);margin-bottom:16px;">You need to be logged in to apply.</p>
        <a href="?page=login" class="btn btn-gold" style="width:100%;justify-content:center;">Login to Apply</a>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="lawyer_id" value="<?= $l['id'] ?>">
        <div class="form-group">
          <label>Case Title *</label>
          <input type="text" name="case_title" required placeholder="Brief title of your case">
        </div>
        <div class="form-group">
          <label>Case Type *</label>
          <input type="text" name="case_type" value="<?= e($l['specialization']) ?>" required>
        </div>
        <div class="form-group">
          <label>Urgency Level</label>
          <select name="urgency">
            <option value="Low">🟢 Low</option>
            <option value="Medium" selected>🟡 Medium</option>
            <option value="High">🔴 High</option>
            <option value="Critical">🚨 Critical</option>
          </select>
        </div>
        <div class="form-group">
          <label>Case Description *</label>
          <textarea name="case_description" required rows="5" placeholder="Describe your case in detail. Include dates, parties involved, and what outcome you seek..."></textarea>
        </div>
        <div style="background:var(--ivory);border-radius:10px;padding:14px;margin-bottom:16px;font-size:.82rem;color:var(--muted);border:1px solid var(--border);">
          <strong style="color:var(--navy);">Session Fee:</strong> ₹<?= number_format($l['fee_per_session'],0) ?> per session<br>
          <strong style="color:var(--navy);">Response Time:</strong> Usually within 24–48 hours
        </div>
        <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;font-size:.95rem;padding:13px;">
          🚀 Send Application
        </button>
        <?php if(!$l['is_available']): ?>
        <p style="margin-top:10px;font-size:.78rem;color:var(--red);text-align:center;">
          ⚠️ This advocate is currently busy. You may still apply and get scheduled.
        </p>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif($page === 'dashboard' && isLoggedIn()): ?>
<!-- ─────────────── DASHBOARD ─────────────── -->
<?php $applications = getUserApplications($_SESSION['user_id']); $user = currentUser(); ?>
<div class="dashboard-header">
  <div class="container">
    <h1>📂 My Cases</h1>
    <p>Welcome back, <?= e($user['full_name']) ?> — manage your case applications below.</p>
  </div>
</div>
<div class="container section">
  <?php if(empty($applications)): ?>
  <div class="empty-state">
    <span class="icon">📁</span>
    <h3>No applications yet</h3>
    <p>Find a lawyer and submit your first case application.</p>
    <a href="?page=search" class="btn btn-gold" style="margin-top:20px">Find a Lawyer</a>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);box-shadow:var(--shadow2);">
    <table class="applications-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Lawyer</th>
          <th>Case Title</th>
          <th>Type</th>
          <th>Urgency</th>
          <th>Applied On</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($applications as $app): ?>
        <tr>
          <td style="font-weight:700;color:var(--gold);">#<?= $app['id'] ?></td>
          <td>
            <div style="font-weight:600;font-size:.88rem;"><?= e($app['lawyer_name']) ?></div>
            <div style="font-size:.75rem;color:var(--muted);"><?= e($app['specialization']) ?></div>
            <div style="font-size:.75rem;color:var(--muted);">📍 <?= e($app['lawyer_city']) ?></div>
          </td>
          <td style="max-width:200px;">
            <div style="font-weight:600;font-size:.87rem;"><?= e($app['case_title']) ?></div>
            <div style="font-size:.75rem;color:var(--muted);margin-top:3px;white-space:normal;">
              <?= e(substr($app['case_description'],0,80)) ?>...
            </div>
          </td>
          <td style="font-size:.83rem;"><?= e($app['case_type']) ?></td>
          <td><span class="urgency-pill urgency-<?= $app['urgency'] ?>"><?= $app['urgency'] ?></span></td>
          <td>
            <div class="applied-text">✓ Applied</div>
            <div style="font-size:.75rem;color:var(--muted);margin-top:3px;"><?= date('d M Y', strtotime($app['applied_at'])) ?></div>
          </td>
          <td>
            <a href="?page=lawyer&id=<?= $app['lawyer_id'] ?>" class="btn btn-outline btn-sm">View Lawyer</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif($page === 'notifications' && isLoggedIn()): ?>
<!-- ─────────────── NOTIFICATIONS ─────────────── -->
<?php $notifs = getUserNotifications($_SESSION['user_id']); ?>
<div class="dashboard-header">
  <div class="container">
    <h1>🔔 Notifications</h1>
    <p>Updates about your case applications.</p>
  </div>
</div>
<div class="container section">
  <?php if(empty($notifs)): ?>
  <div class="empty-state">
    <span class="icon">🔔</span>
    <h3>No notifications yet</h3>
    <p>Notifications will appear here when there are updates on your applications.</p>
  </div>
  <?php else: ?>
    <?php foreach($notifs as $n): ?>
    <div class="notif-item <?= !$n['is_read']?'notif-unread':'' ?>">
      <div class="notif-icon">📣</div>
      <div>
        <div class="notif-text"><?= e($n['message']) ?></div>
        <div class="notif-time">🕐 <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php elseif($page === 'login'): ?>
<!-- ─────────────── LOGIN ─────────────── -->
<div class="auth-wrap">
  <div class="auth-card">
    <div style="text-align:center;margin-bottom:24px;">
      <div style="font-size:3rem;margin-bottom:8px;">⚖️</div>
      <h2>Welcome Back</h2>
      <p>Sign in to manage your case applications</p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <div class="form-group">
        <label>Email Address *</label>
        <input type="email" name="email" required placeholder="you@example.com">
      </div>
      <div class="form-group">
        <label>Password *</label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;margin-top:8px;padding:13px;font-size:.95rem;">
        Sign In →
      </button>
    </form>
    <p style="text-align:center;margin-top:20px;font-size:.85rem;color:var(--muted);">
      Don't have an account? <a href="?page=register" style="color:var(--gold);font-weight:600;">Register here</a>
    </p>
    <p style="text-align:center;margin-top:12px;font-size:.8rem;color:var(--muted);background:var(--ivory);border-radius:8px;padding:10px;">
      🧪 Demo: <strong>demo@lawbridge.in</strong> / <strong>password</strong>
    </p>
  </div>
</div>

<?php elseif($page === 'register'): ?>
<!-- ─────────────── REGISTER ─────────────── -->
<div class="auth-wrap">
  <div class="auth-card" style="max-width:520px;">
    <div style="text-align:center;margin-bottom:24px;">
      <div style="font-size:3rem;margin-bottom:8px;">⚖️</div>
      <h2>Create Account</h2>
      <p>Join LawBridge to find legal help</p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="register">
      <div class="form-row">
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="full_name" required placeholder="Your full name">
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" placeholder="10-digit mobile">
        </div>
      </div>
      <div class="form-group">
        <label>Email Address *</label>
        <input type="email" name="email" required placeholder="you@example.com">
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="city" placeholder="Your city">
      </div>
      <div class="form-group">
        <label>Password *</label>
        <input type="password" name="password" required placeholder="Minimum 8 characters" minlength="8">
      </div>
      <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;margin-top:8px;padding:13px;font-size:.95rem;">
        Create Account →
      </button>
    </form>
    <p style="text-align:center;margin-top:20px;font-size:.85rem;color:var(--muted);">
      Already have an account? <a href="?page=login" style="color:var(--gold);font-weight:600;">Sign in</a>
    </p>
  </div>
</div>
<?php else: ?>
<!-- 404 -->
<div class="container" style="text-align:center;padding:100px 24px;">
  <div style="font-size:5rem;margin-bottom:20px;">⚖️</div>
  <h2 style="font-family:var(--font-head);font-size:2rem;color:var(--navy);">Page Not Found</h2>
  <a href="?page=home" class="btn btn-gold" style="margin-top:24px">Go Home</a>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════ APPLY MODAL ═══════════════════════════ -->
<?php if(isLoggedIn()): ?>
<div class="modal-overlay" id="applyModal" onclick="if(event.target===this)closeApply()">
  <div class="modal">
    <button class="modal-close" onclick="closeApply()">✕</button>
    <h2>📋 Apply for Consultation</h2>
    <p class="sub" id="modalSubtitle">Submit your case to the selected advocate.</p>
    <form method="POST" id="applyForm">
      <input type="hidden" name="action" value="apply">
      <input type="hidden" name="lawyer_id" id="modalLawyerId">
      <div class="form-group">
        <label>Case Title *</label>
        <input type="text" name="case_title" id="modalCaseTitle" required placeholder="e.g. Property dispute with neighbour">
      </div>
      <div class="form-group">
        <label>Case Type *</label>
        <input type="text" name="case_type" id="modalCaseType" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Urgency</label>
          <select name="urgency">
            <option value="Low">🟢 Low</option>
            <option value="Medium" selected>🟡 Medium</option>
            <option value="High">🔴 High</option>
            <option value="Critical">🚨 Critical</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Case Description *</label>
        <textarea name="case_description" required rows="5" placeholder="Describe your case — include relevant facts, dates, parties, and what you need help with..."></textarea>
      </div>
      <div style="display:flex;gap:12px;margin-top:4px;">
        <button type="button" onclick="closeApply()" class="btn btn-outline" style="flex:1;justify-content:center;">Cancel</button>
        <button type="submit" class="btn btn-gold" style="flex:2;justify-content:center;">🚀 Submit Application</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════ FOOTER ═══════════════════════════════ -->
<footer class="footer">
  <div class="footer-brand">⚖️ LawBridge</div>
  <p>Connecting citizens with verified advocates across India.</p>
  <p style="margin-top:8px;">© <?= date('Y') ?> LawBridge Portal — For educational and real-world use.</p>
  <p style="margin-top:6px;font-size:.75rem;">
    All advocates listed are fictional for demonstration. In production, integrate with Bar Council of India verification API.
  </p>
</footer>

<!-- ═══════════════════════════════ SCRIPTS ═══════════════════════════════ -->
<script>
// ── Apply Modal ──
function openApply(id, name, spec) {
  document.getElementById('modalLawyerId').value = id;
  document.getElementById('modalSubtitle').textContent = 'Apply for consultation with ' + name;
  document.getElementById('modalCaseType').value = spec;
  document.getElementById('applyModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeApply() {
  document.getElementById('applyModal').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeApply(); });

// ── Scroll Reveal ──
const revealObserver = new IntersectionObserver(entries => {
  entries.forEach(e => { if(e.isIntersecting) { e.target.classList.add('visible'); revealObserver.unobserve(e.target); }});
}, { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

// ── Animate progress bars on scroll ──
const barObserver = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if(entry.isIntersecting) {
      const fill = entry.target.querySelector('.progress-fill');
      if(fill) { const w = fill.style.width; fill.style.width='0'; setTimeout(()=>fill.style.width=w, 100); }
    }
  });
}, { threshold: 0.3 });
document.querySelectorAll('.progress-bar').forEach(b => barObserver.observe(b));

// ── Auto-dismiss flash messages ──
setTimeout(() => {
  document.querySelectorAll('.alert').forEach(a => {
    a.style.transition = 'opacity .5s'; a.style.opacity='0';
    setTimeout(()=>a.remove(), 500);
  });
}, 5000);

// ── Stagger card animations ──
document.querySelectorAll('.lawyer-card').forEach((card, i) => {
  card.style.animationDelay = (i % 10 * 0.06) + 's';
});

// ── Active nav highlight ──
const currentPage = new URLSearchParams(location.search).get('page') || 'home';
document.querySelectorAll('.nav-links a').forEach(a => {
  if(a.href.includes('page='+currentPage)) a.classList.add('active');
});

// ── Smooth scroll spec cards ──
document.querySelectorAll('.spec-card').forEach(card => {
  card.addEventListener('click', function(e) {
    this.style.transform = 'scale(0.96)';
    setTimeout(()=>this.style.transform='', 200);
  });
});
</script>
</body>
</html>