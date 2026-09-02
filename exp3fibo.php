<?php
declare(strict_types=1);

// ─── DB CONFIG ────────────────────────────────────────────────────────────────
$host   = 'localhost';
$dbname = 'fibonacci_db';
$user   = 'root';
$pass   = '';
$pdo    = null;
$dbe    = null;

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Ensure table exists with MEDIUMTEXT for huge numbers
    $pdo->exec("CREATE TABLE IF NOT EXISTS fibonacci_cache (
        position BIGINT UNSIGNED PRIMARY KEY,
        value    MEDIUMTEXT NOT NULL,
        cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    $dbe = 'DB connection failed: ' . $e->getMessage();
}

// ─── BIG INTEGER ADDITION (pure PHP fallback if bcmath absent) ───────────────
function addBig(string $x, string $y): string {
    if (function_exists('bcadd')) return bcadd($x, $y, 0);
    $x = strrev($x); $y = strrev($y);
    $m = max(strlen($x), strlen($y));
    $c = 0; $r = '';
    for ($i = 0; $i < $m; $i++) {
        $s = ($i < strlen($x) ? (int)$x[$i] : 0)
           + ($i < strlen($y) ? (int)$y[$i] : 0) + $c;
        $r .= $s % 10; $c = intdiv($s, 10);
    }
    return strrev($c ? $r . $c : $r);
}

// ─── GENERATE FIBONACCI SEQUENCE UP TO N (with DB caching) ──────────────────
function genFib(int $n, PDO $pdo): array {
    // Fetch all already-cached positions up to n
    $stmt = $pdo->prepare("SELECT position, value FROM fibonacci_cache WHERE position <= :n ORDER BY position");
    $stmt->execute([':n' => $n]);
    $cached = [];
    foreach ($stmt->fetchAll() as $row) {
        $cached[(int)$row['position']] = (string)$row['value'];
    }

    $ins = $pdo->prepare("INSERT IGNORE INTO fibonacci_cache(position, value) VALUES(:p, :v)");
    $seq = [];
    $a = '0'; $b = '1';

    for ($i = 1; $i <= $n; $i++) {
        if (isset($cached[$i])) {
            $val = $cached[$i];
            $isNew = false;
        } else {
            $val = match($i) {
                1 => '0',
                2 => '1',
                default => addBig($a, $b),
            };
            $ins->execute([':p' => $i, ':v' => $val]);
            $isNew = true;
        }

        $digits = strlen($val);
        $seq[] = [
            'position' => $i,
            'value'    => $val,
            'digits'   => $digits,
            'new'      => $isNew,
        ];

        // Rolling window: advance a, b
        $na = $b;
        $b  = addBig($a, $b);
        $a  = $na;
    }

    return $seq;
}

// ─── AJAX ENDPOINT ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if (!$pdo) throw new RuntimeException($dbe ?? 'DB unavailable.');

        switch ($_POST['action']) {

            case 'generate':
                $count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
                if (!$count) throw new InvalidArgumentException('Count must be a positive integer.');
                $data = genFib($count, $pdo);
                // For very large n, truncate display values but keep full digits info
                $out = array_map(function($item) {
                    $v = $item['value'];
                    $d = $item['digits'];
                    return [
                        'position' => $item['position'],
                        'value'    => $d > 30 ? substr($v, 0, 12) . '…' . substr($v, -6) : $v,
                        'full'     => $v,
                        'digits'   => $d,
                        'new'      => $item['new'],
                    ];
                }, $data);
                echo json_encode(['success' => true, 'data' => $out]);
                break;

            case 'single':
                // Get a specific Fibonacci number
                $pos = filter_input(INPUT_POST, 'position', FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]);
                if (!$pos) throw new InvalidArgumentException('Invalid position.');
                $data = genFib($pos, $pdo);
                $item = end($data);
                echo json_encode(['success' => true, 'item' => $item]);
                break;

            case 'stats':
                $cnt  = $pdo->query("SELECT COUNT(*) FROM fibonacci_cache")->fetchColumn();
                $stmt = $pdo->query("SELECT MAX(position) as mp, MAX(LENGTH(value)) as md FROM fibonacci_cache");
                $row  = $stmt->fetch();
                echo json_encode(['success' => true, 'cached' => $cnt, 'maxPos' => $row['mp'], 'maxDigits' => $row['md']]);
                break;

            case 'clear':
                $pdo->exec("TRUNCATE TABLE fibonacci_cache");
                echo json_encode(['success' => true]);
                break;

            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fibonacci ∞ Explorer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=JetBrains+Mono:wght@300;400;700&display=swap" rel="stylesheet">
<style>
/* ─── RESET & ROOT ─────────────────────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
  --bg:      #04080f;
  --bg2:     #070d17;
  --surf:    #0b1220;
  --card:    #0e1628;
  --border:  rgba(255,255,255,.07);
  --gold:    #ffb700;
  --gold2:   #ffd966;
  --gold3:   rgba(255,183,0,.12);
  --teal:    #00e5c0;
  --teal2:   rgba(0,229,192,.15);
  --red:     #ff4e4e;
  --dim:     #3d546e;
  --muted:   #8098b0;
  --txt:     #c8d8e8;
  --white:   #eef4ff;
  --glow:    0 0 60px rgba(255,183,0,.2);
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Syne', sans-serif;
  background: var(--bg);
  color: var(--txt);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ─── NOISE + GRID BACKGROUND ──────────────────────────────────── */
body::before {
  content:'';
  position:fixed; inset:0;
  background-image:
    repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(255,183,0,.025) 40px),
    repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(255,183,0,.025) 40px);
  pointer-events:none; z-index:0;
}
body::after {
  content:'';
  position:fixed; inset:0;
  background: radial-gradient(ellipse 80% 60% at 50% -10%, rgba(255,183,0,.08) 0%, transparent 70%);
  pointer-events:none; z-index:0;
}

/* ─── CANVAS BG ─────────────────────────────────────────────────── */
#bgCanvas {
  position:fixed; inset:0; z-index:0;
  opacity:.35; pointer-events:none;
}

/* ─── LAYOUT ────────────────────────────────────────────────────── */
.wrap { position:relative; z-index:1; max-width:1200px; margin:0 auto; padding:40px 24px 120px; }

/* ─── HEADER ────────────────────────────────────────────────────── */
header {
  text-align:center;
  margin-bottom:56px;
  padding-top:20px;
}
.header-kicker {
  font-family: 'JetBrains Mono', monospace;
  font-size:.65rem; letter-spacing:.35em;
  color:var(--gold); text-transform:uppercase;
  margin-bottom:20px;
  display:inline-flex; align-items:center; gap:10px;
}
.header-kicker::before, .header-kicker::after {
  content:''; display:inline-block;
  width:32px; height:1px;
  background: linear-gradient(90deg, transparent, var(--gold));
}
.header-kicker::after { background: linear-gradient(90deg, var(--gold), transparent); }

h1 {
  font-size: clamp(3rem, 8vw, 7rem);
  font-weight:800; line-height:.9;
  letter-spacing:-.06em;
  color:var(--white);
  margin-bottom:16px;
}
h1 span.hl {
  background: linear-gradient(135deg, var(--gold), var(--gold2));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
  background-clip:text;
}
h1 .inf {
  font-size:1.1em; line-height:1;
  color:var(--teal);
  -webkit-text-fill-color: var(--teal);
}
.tagline {
  font-family:'JetBrains Mono',monospace;
  font-size:.78rem; color:var(--dim);
  letter-spacing:.05em;
}
.phi-display {
  margin-top:18px;
  font-family:'JetBrains Mono',monospace;
  font-size:1.05rem; color:var(--gold);
  opacity:.7;
  animation: fadeGlow 3s ease-in-out infinite;
}
@keyframes fadeGlow {
  0%,100%{opacity:.5; text-shadow:none}
  50%{opacity:1; text-shadow:0 0 20px var(--gold)}
}

/* ─── STATS BAR ─────────────────────────────────────────────────── */
#statsBar {
  display:flex; gap:12px; flex-wrap:wrap;
  margin-bottom:28px;
}
.stat-pill {
  font-family:'JetBrains Mono',monospace;
  font-size:.7rem; padding:8px 16px;
  background:var(--surf); border:1px solid var(--border);
  border-radius:100px; color:var(--muted);
  display:flex; align-items:center; gap:8px;
  transition: border-color .3s;
}
.stat-pill .sv { color:var(--gold); font-weight:700; }
.stat-pill:hover { border-color:var(--gold); }

/* ─── CONTROL PANEL ─────────────────────────────────────────────── */
.ctrl-panel {
  background: var(--surf);
  border:1px solid var(--border);
  border-radius:24px;
  padding:28px 32px;
  margin-bottom:24px;
  position:relative;
  overflow:hidden;
}
.ctrl-panel::before {
  content:'';
  position:absolute; top:0; left:0; right:0; height:1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
  opacity:.4;
}

.ctrl-grid {
  display:grid;
  grid-template-columns: 1fr 1fr auto auto auto;
  gap:16px; align-items:end;
}
@media(max-width:780px) {
  .ctrl-grid { grid-template-columns:1fr 1fr; }
  .ctrl-grid > *:last-child,
  .ctrl-grid > *:nth-last-child(2) { grid-column: span 1; }
}
@media(max-width:480px) {
  .ctrl-grid { grid-template-columns:1fr; }
}

.field label {
  display:block;
  font-family:'JetBrains Mono',monospace;
  font-size:.62rem; letter-spacing:.15em;
  text-transform:uppercase; color:var(--dim);
  margin-bottom:8px;
}
.field input[type=number],
.field input[type=text],
.field select {
  font-family:'JetBrains Mono',monospace;
  font-size:1rem; font-weight:700;
  background:var(--card);
  border:1px solid var(--border);
  color:var(--white);
  padding:12px 18px;
  border-radius:14px;
  width:100%; outline:none;
  transition: border-color .25s, box-shadow .25s;
  -moz-appearance: textfield;
}
.field input::-webkit-outer-spin-button,
.field input::-webkit-inner-spin-button { -webkit-appearance:none; }
.field input:focus, .field select:focus {
  border-color:var(--gold);
  box-shadow: 0 0 0 3px rgba(255,183,0,.12);
}

/* speed slider */
.spd-wrap { position:relative; }
.spd-wrap input[type=range] {
  -webkit-appearance:none;
  width:100%; height:4px;
  background: rgba(255,255,255,.08);
  border-radius:2px; cursor:pointer; outline:none;
  margin-top:4px;
}
.spd-wrap input[type=range]::-webkit-slider-thumb {
  -webkit-appearance:none;
  width:18px; height:18px;
  background: var(--gold);
  border-radius:50%;
  box-shadow:0 0 10px rgba(255,183,0,.5);
  transition: transform .2s;
}
.spd-wrap input[type=range]::-webkit-slider-thumb:hover { transform:scale(1.25); }
.spd-labels {
  display:flex; justify-content:space-between;
  font-family:'JetBrains Mono',monospace;
  font-size:.55rem; color:var(--dim);
  margin-top:5px;
}

/* buttons */
.btn {
  font-family:'Syne',sans-serif;
  font-weight:700; font-size:.82rem;
  letter-spacing:.04em;
  padding:13px 28px; border-radius:14px;
  border:none; cursor:pointer;
  transition: all .22s; white-space:nowrap;
  position:relative; overflow:hidden;
}
.btn::after {
  content:'';
  position:absolute; inset:0;
  background:rgba(255,255,255,.1);
  opacity:0; transition:opacity .2s;
}
.btn:hover::after { opacity:1; }
.btn:active { transform:scale(.97); }
.btn:disabled { opacity:.3; cursor:not-allowed; }

.btn-primary {
  background: linear-gradient(135deg, var(--gold), #ff8c00);
  color:#1a0900;
  box-shadow:0 4px 20px rgba(255,183,0,.3);
}
.btn-primary:hover { box-shadow:0 6px 30px rgba(255,183,0,.5); }

.btn-teal {
  background: linear-gradient(135deg, var(--teal), #00b897);
  color:#001a14;
  box-shadow:0 4px 20px rgba(0,229,192,.2);
}

.btn-ghost {
  background:transparent;
  border:1px solid var(--border);
  color:var(--muted);
}
.btn-ghost:hover { border-color:var(--red); color:var(--red); }

.btn-stop {
  background: linear-gradient(135deg,#ff4e4e,#c00);
  color:#fff;
  box-shadow:0 4px 20px rgba(255,78,78,.3);
}

/* mode selector pills */
.mode-pills {
  display:flex; gap:8px; flex-wrap:wrap;
  margin-top:20px; padding-top:20px;
  border-top:1px solid var(--border);
}
.mode-pill {
  font-family:'JetBrains Mono',monospace;
  font-size:.68rem; padding:8px 18px;
  border-radius:100px; cursor:pointer;
  border:1px solid var(--border);
  color:var(--muted);
  background:transparent;
  transition:all .2s;
}
.mode-pill.active, .mode-pill:hover {
  background: var(--gold3);
  border-color:var(--gold);
  color:var(--gold);
}

/* ─── GOLDEN SPIRAL CANVAS ──────────────────────────────────────── */
.spiral-wrap {
  position:relative;
  background:var(--surf);
  border:1px solid var(--border);
  border-radius:24px;
  overflow:hidden;
  margin-bottom:24px;
}
.spiral-wrap::before {
  content:'';
  position:absolute; top:0; left:0; right:0; height:1px;
  background: linear-gradient(90deg, transparent, var(--teal), transparent);
  opacity:.4;
}
#spiralCanvas {
  display:block; width:100%;
  height:220px;
}
.spiral-overlay {
  position:absolute; top:16px; right:20px;
  font-family:'JetBrains Mono',monospace;
  font-size:.65rem; color:var(--dim);
  text-align:right; pointer-events:none;
}
#phiRatioLive {
  display:block; font-size:1.4rem;
  color:var(--gold); font-weight:700;
  line-height:1; margin-bottom:4px;
  transition:color .5s;
}

/* ─── MAIN DISPLAY AREA ─────────────────────────────────────────── */
#mainView {
  background:var(--surf);
  border:1px solid var(--border);
  border-radius:24px;
  padding:28px;
  margin-bottom:24px;
  min-height:240px;
  position:relative;
  overflow:hidden;
}
#mainView::before {
  content:'';
  position:absolute; top:0; left:0; right:0; height:1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
  opacity:.3;
}

/* VIEW: STREAM */
#streamView {
  display:flex; flex-wrap:wrap; gap:8px;
  align-items:flex-end;
  min-height:160px;
}
.fib-chip {
  position:relative;
  border-radius:14px;
  background:var(--card);
  border:1px solid var(--border);
  padding:12px 10px 8px;
  text-align:center;
  min-width:72px;
  overflow:hidden;
  opacity:0;
  transform: scale(.7) translateY(30px);
  transition: opacity .4s cubic-bezier(.34,1.56,.64,1),
              transform .4s cubic-bezier(.34,1.56,.64,1),
              border-color .4s, background .4s;
}
.fib-chip.visible { opacity:1; transform:scale(1) translateY(0); }
.fib-chip.active  { border-color:var(--gold); background:rgba(255,183,0,.09); }
.fib-chip.done    { border-color:rgba(255,255,255,.08); }
.fib-chip.large-n {
  min-width:90px; font-size:.7rem;
}
.chip-val {
  font-family:'JetBrains Mono',monospace;
  font-size:.9rem; font-weight:700;
  color:var(--white); white-space:nowrap;
  overflow:hidden; text-overflow:ellipsis;
  max-width:86px;
}
.fib-chip.active .chip-val { color:var(--gold2); }
.chip-pos {
  font-size:.58rem; color:var(--dim);
  margin-top:4px;
  font-family:'JetBrains Mono',monospace;
}
.chip-digits {
  font-size:.5rem; color:var(--teal);
  font-family:'JetBrains Mono',monospace;
}
/* glow ring animation */
.ring {
  position:absolute; inset:0;
  border-radius:14px;
  border:2px solid var(--gold);
  opacity:1; transform:scale(.85);
  animation:ringOut .55s ease-out forwards;
  pointer-events:none;
}
@keyframes ringOut {
  to { transform:scale(1.9); opacity:0; }
}

/* VIEW: TABLE */
#tableView {
  display:none;
  overflow-x:auto;
}
.fib-table {
  width:100%; border-collapse:collapse;
  font-family:'JetBrains Mono',monospace;
  font-size:.78rem;
}
.fib-table th {
  text-align:left; padding:10px 16px;
  color:var(--dim); font-size:.62rem;
  letter-spacing:.15em; text-transform:uppercase;
  border-bottom:1px solid var(--border);
}
.fib-table td {
  padding:10px 16px;
  border-bottom:1px solid rgba(255,255,255,.03);
  color:var(--muted);
  transition: background .3s, color .3s;
  opacity:0; animation: rowIn .4s ease forwards;
}
.fib-table tr.active td { background:rgba(255,183,0,.06); color:var(--white); }
.fib-table td.pos { color:var(--dim); }
.fib-table td.val { color:var(--white); font-weight:700; }
.fib-table td.ratio { color:var(--gold); }
.fib-table td.digits { color:var(--teal); }
.fib-table td.badge-new {
  color:var(--teal); font-size:.62rem;
  text-transform:uppercase; letter-spacing:.1em;
}
@keyframes rowIn { to { opacity:1; } }

/* VIEW: GRAPH */
#graphView {
  display:none;
  height:180px;
  align-items:flex-end;
  gap:3px;
}
#graphView.visible { display:flex; }
.bar-col {
  flex:1; display:flex; flex-direction:column;
  align-items:center; gap:3px;
  min-width:0;
}
.bar-fill {
  width:100%; background:rgba(255,255,255,.08);
  border-radius:3px 3px 0 0;
  height:0;
  transition:height .6s cubic-bezier(.34,1.56,.64,1), background .3s;
}
.bar-fill.active { background:var(--gold); }
.bar-fill.done   { background:rgba(255,183,0,.35); }
.bar-lbl {
  font-family:'JetBrains Mono',monospace;
  font-size:.5rem; color:var(--dim);
}

/* ─── EQUATION ROW ──────────────────────────────────────────────── */
#eqRow {
  display:flex; align-items:center; gap:8px;
  flex-wrap:wrap;
  font-family:'JetBrains Mono',monospace;
  font-size:.82rem; color:var(--dim);
  padding:16px 0 4px;
  min-height:50px;
}
#eqRow .eq-val { color:var(--txt); font-weight:700; }
#eqRow .eq-res { color:var(--gold); font-size:1rem; font-weight:700; }
#eqRow .eq-op  { color:var(--teal); }

/* ─── PROGRESS ─────────────────────────────────────────────────── */
#progWrap {
  display:flex; align-items:center; gap:12px;
  margin-top:16px;
}
#progTrack {
  flex:1; height:4px;
  background:rgba(255,255,255,.07);
  border-radius:2px; overflow:hidden;
  position:relative;
}
#progFill {
  height:100%; width:0%;
  background: linear-gradient(90deg, var(--gold), var(--gold2));
  border-radius:2px;
  transition:width .4s ease;
  box-shadow:0 0 8px var(--gold);
}
#progLbl {
  font-family:'JetBrains Mono',monospace;
  font-size:.65rem; color:var(--dim);
  min-width:72px; text-align:right;
}

/* ─── CURRENT NUMBER SPOTLIGHT ─────────────────────────────────── */
#spotlight {
  text-align:center;
  padding:24px 16px;
  display:none;
}
#spotlight.on { display:block; }
.spot-pos {
  font-family:'JetBrains Mono',monospace;
  font-size:.65rem; color:var(--dim);
  letter-spacing:.2em; text-transform:uppercase;
  margin-bottom:10px;
}
#spotVal {
  font-family:'JetBrains Mono',monospace;
  font-weight:700;
  font-size: clamp(1.2rem, 4vw, 2.8rem);
  color:var(--gold);
  word-break:break-all;
  line-height:1.3;
  text-shadow:0 0 30px rgba(255,183,0,.4);
  animation:spotPop .4s cubic-bezier(.34,1.56,.64,1);
}
@keyframes spotPop {
  from { transform:scale(.85); opacity:.3; }
  to   { transform:scale(1); opacity:1; }
}
.spot-meta {
  margin-top:10px;
  font-family:'JetBrains Mono',monospace;
  font-size:.7rem; color:var(--dim);
  display:flex; justify-content:center; gap:18px;
}
.spot-meta span { color:var(--teal); }

/* ─── DIGIT COUNTER ANIMATION ───────────────────────────────────── */
#digitMeter {
  display:flex; align-items:center; gap:14px;
  margin-top:16px; padding:14px 18px;
  background:var(--card); border-radius:14px;
  border:1px solid var(--border);
}
#digitMeter .dm-lbl {
  font-family:'JetBrains Mono',monospace;
  font-size:.65rem; color:var(--dim);
  text-transform:uppercase; letter-spacing:.1em;
}
#digitCount {
  font-family:'JetBrains Mono',monospace;
  font-size:1.8rem; font-weight:700;
  color:var(--teal);
  transition: color .3s;
  min-width:4ch;
}
.digit-bar-wrap {
  flex:1; height:6px;
  background:rgba(255,255,255,.06);
  border-radius:3px; overflow:hidden;
}
#digitBar {
  height:100%; width:0%;
  background: linear-gradient(90deg, var(--teal), var(--gold));
  border-radius:3px;
  transition:width .5s ease;
}

/* ─── LOOKUP SECTION ─────────────────────────────────────────────── */
.lookup-panel {
  background:var(--surf); border:1px solid var(--border);
  border-radius:24px; padding:28px 32px;
  margin-bottom:24px;
}
.panel-title {
  font-size:.65rem; letter-spacing:.25em;
  text-transform:uppercase; color:var(--dim);
  font-family:'JetBrains Mono',monospace;
  margin-bottom:20px;
  display:flex; align-items:center; gap:10px;
}
.panel-title::after {
  content:''; flex:1; height:1px;
  background:var(--border);
}
.lookup-row {
  display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;
}
#lookupResult {
  margin-top:18px; padding:18px;
  background:var(--card); border-radius:14px;
  border:1px solid var(--border);
  font-family:'JetBrains Mono',monospace;
  font-size:.85rem; color:var(--muted);
  display:none;
  animation:fadeIn .3s ease;
}
#lookupResult.on { display:block; }
#lookupResult .lr-val {
  font-size:1.1rem; color:var(--gold); font-weight:700;
  word-break:break-all; line-height:1.5;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

/* ─── PARTICLES ─────────────────────────────────────────────────── */
.pt {
  position:absolute; border-radius:50%;
  pointer-events:none;
  animation:ptFly .7s ease-out forwards;
}
@keyframes ptFly {
  0%   { transform:translate(0,0) scale(1); opacity:.9; }
  100% { transform:translate(var(--dx),var(--dy)) scale(0); opacity:0; }
}

/* ─── TOAST ─────────────────────────────────────────────────────── */
#toast {
  position:fixed; bottom:28px; right:28px;
  background:var(--surf); border:1px solid var(--border);
  border-left:3px solid var(--gold);
  border-radius:14px; padding:14px 22px;
  font-family:'JetBrains Mono',monospace;
  font-size:.75rem; color:var(--txt);
  opacity:0; transform:translateY(10px);
  transition:all .3s; z-index:999;
  pointer-events:none; max-width:320px;
}
#toast.on { opacity:1; transform:none; }
#toast.err { border-left-color:var(--red); }

/* ─── SCROLLBAR ─────────────────────────────────────────────────── */
::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:var(--bg); }
::-webkit-scrollbar-thumb { background:var(--dim); border-radius:3px; }

/* ─── LOADING PULSE ─────────────────────────────────────────────── */
.loading-dots::after {
  content:''; animation:dots 1.2s steps(4,end) infinite;
}
@keyframes dots {
  0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'} 100%{content:''}
}

/* ─── RESPONSIVE ─────────────────────────────────────────────────── */
@media(max-width:600px) {
  header { margin-bottom:36px; }
  h1 { letter-spacing:-.04em; }
  .ctrl-panel { padding:20px; }
  #mainView { padding:18px; }
}
</style>
</head>
<body>

<canvas id="bgCanvas"></canvas>

<div class="wrap">

  <!-- HEADER -->
  <header>
    <div class="header-kicker">Mathematical Sequence Explorer</div>
    <h1>Fibonacci<br><span class="hl">Sequence</span> <span class="inf">∞</span></h1>
    <p class="tagline">F(n) = F(n−1) + F(n−2) &nbsp;·&nbsp; Unlimited precision &nbsp;·&nbsp; DB cached</p>
    <div class="phi-display">φ = 1.6180339887498948482…</div>
  </header>

  <!-- STATS BAR -->
  <div id="statsBar">
    <div class="stat-pill">📦 Cached terms: <span class="sv" id="sCached">—</span></div>
    <div class="stat-pill">📍 Max position: <span class="sv" id="sMaxPos">—</span></div>
    <div class="stat-pill">🔢 Max digits: <span class="sv" id="sMaxDig">—</span></div>
    <div class="stat-pill">⚡ Speed: <span class="sv" id="sSpd">Normal</span></div>
  </div>

  <!-- CONTROL PANEL -->
  <div class="ctrl-panel">
    <div class="ctrl-grid">
      <div class="field">
        <label>Number of terms (∞ = any n)</label>
        <input type="number" id="nInput" value="20" min="1" placeholder="e.g. 1000">
      </div>
      <div class="field spd-wrap">
        <label>Animation speed</label>
        <input type="range" id="spdSlider" min="1" max="6" value="3" step="1">
        <div class="spd-labels"><span>Slow</span><span>Fast</span><span>⚡ Turbo</span></div>
      </div>
      <button class="btn btn-primary" id="btnGen">▶ Generate</button>
      <button class="btn btn-teal" id="btnPause" disabled>⏸ Pause</button>
      <button class="btn btn-ghost" id="btnClear">🗑 Clear Cache</button>
    </div>

    <!-- VIEW MODES -->
    <div class="mode-pills">
      <span style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--dim);align-self:center;margin-right:4px;">View:</span>
      <button class="mode-pill active" data-mode="stream">Stream</button>
      <button class="mode-pill" data-mode="table">Table</button>
      <button class="mode-pill" data-mode="graph">Graph</button>
      <button class="mode-pill" data-mode="spotlight">Spotlight</button>
    </div>
  </div>

  <!-- SPIRAL -->
  <div class="spiral-wrap">
    <canvas id="spiralCanvas"></canvas>
    <div class="spiral-overlay">
      <span id="phiRatioLive">—</span>
      <span style="font-size:.6rem;color:var(--dim)">φ convergence</span>
    </div>
  </div>

  <!-- MAIN VIEW -->
  <div id="mainView">
    <!-- Stream -->
    <div id="streamView"></div>
    <!-- Table -->
    <div id="tableView">
      <table class="fib-table">
        <thead>
          <tr>
            <th>Position</th>
            <th>Value</th>
            <th>φ Ratio</th>
            <th>Digits</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
    <!-- Graph -->
    <div id="graphView"></div>
    <!-- Spotlight -->
    <div id="spotlight">
      <div class="spot-pos" id="spotPos">F(—)</div>
      <div id="spotVal">—</div>
      <div class="spot-meta">
        <div>Digits: <span id="spotDigits">—</span></div>
        <div>φ ratio: <span id="spotPhi">—</span></div>
        <div>Status: <span id="spotNew">—</span></div>
      </div>
    </div>

    <!-- Equation row -->
    <div id="eqRow"></div>
    <!-- Progress -->
    <div id="progWrap">
      <div id="progTrack"><div id="progFill"></div></div>
      <div id="progLbl">0 / 0</div>
    </div>
    <!-- Digit meter -->
    <div id="digitMeter">
      <div class="dm-lbl">Digits in F(n)</div>
      <div id="digitCount">0</div>
      <div class="digit-bar-wrap"><div id="digitBar"></div></div>
    </div>
  </div>

  <!-- LOOKUP PANEL -->
  <div class="lookup-panel">
    <div class="panel-title">Find Specific Term</div>
    <div class="lookup-row">
      <div class="field" style="flex:1;min-width:160px;">
        <label>Position (1-based)</label>
        <input type="number" id="lookupN" min="1" placeholder="e.g. 100">
      </div>
      <button class="btn btn-teal" id="btnLookup">Compute F(n)</button>
    </div>
    <div id="lookupResult">
      <div id="lookupVal"></div>
    </div>
  </div>

</div><!-- /wrap -->

<div id="toast"></div>

<script>
/* ─── CONSTANTS ─────────────────────────────────────────── */
const PHI = 1.6180339887498948482;

/* ─── STATE ─────────────────────────────────────────────── */
let seqData = [], step = 0, timer = null, paused = false, playing = false;
let viewMode = 'stream';
let maxDigits = 1;
let spiralRaf = null, spiralPhase = 0;
let bgRaf = null;

/* ─── DOM REFS ──────────────────────────────────────────── */
const $ = id => document.getElementById(id);
const btnGen    = $('btnGen');
const btnPause  = $('btnPause');
const btnClear  = $('btnClear');
const btnLookup = $('btnLookup');
const nInput    = $('nInput');
const spdSlider = $('spdSlider');
const streamView  = $('streamView');
const tableBody   = $('tableBody');
const graphView   = $('graphView');
const spotlight   = $('spotlight');
const eqRow       = $('eqRow');
const progFill    = $('progFill');
const progLbl     = $('progLbl');
const digitCount  = $('digitCount');
const digitBar    = $('digitBar');
const phiRatioLive= $('phiRatioLive');

/* ─── BACKGROUND CANVAS PARTICLES ───────────────────────── */
(function initBG() {
  const cvs = $('bgCanvas');
  const ctx = cvs.getContext('2d');
  let W, H;
  const pts = [];
  function resize() {
    W = cvs.width  = window.innerWidth;
    H = cvs.height = window.innerHeight;
  }
  resize();
  window.addEventListener('resize', resize);
  // Create drifting particles
  for (let i = 0; i < 55; i++) {
    pts.push({
      x: Math.random() * 1200, y: Math.random() * 900,
      r: Math.random() * 1.5 + .3,
      vx: (Math.random() - .5) * .3,
      vy: (Math.random() - .5) * .3,
      a: Math.random(),
    });
  }
  function draw() {
    ctx.clearRect(0,0,W,H);
    pts.forEach(p => {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
      if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
      ctx.fillStyle = `rgba(255,183,0,${p.a * .25})`;
      ctx.fill();
    });
    // Draw connecting lines for close particles
    for (let i = 0; i < pts.length; i++) {
      for (let j = i+1; j < pts.length; j++) {
        const dx = pts[i].x - pts[j].x, dy = pts[i].y - pts[j].y;
        const d = Math.sqrt(dx*dx+dy*dy);
        if (d < 110) {
          ctx.beginPath();
          ctx.moveTo(pts[i].x, pts[i].y);
          ctx.lineTo(pts[j].x, pts[j].y);
          ctx.strokeStyle = `rgba(255,183,0,${(1-d/110)*.08})`;
          ctx.lineWidth = .5;
          ctx.stroke();
        }
      }
    }
    bgRaf = requestAnimationFrame(draw);
  }
  draw();
})();

/* ─── GOLDEN SPIRAL ─────────────────────────────────────── */
(function initSpiral() {
  const cvs = $('spiralCanvas');
  const ctx2 = cvs.getContext('2d');
  let W2, H2;
  function resize2() {
    W2 = cvs.width  = cvs.offsetWidth  * devicePixelRatio;
    H2 = cvs.height = cvs.offsetHeight * devicePixelRatio;
    ctx2.scale(devicePixelRatio, devicePixelRatio);
  }
  resize2();
  window.addEventListener('resize', () => { ctx2.resetTransform(); resize2(); });

  function drawSpiral(phase, aiStep, total) {
    const W = cvs.offsetWidth, H = cvs.offsetHeight;
    ctx2.clearRect(0,0,W,H);
    const cx = W*.5, cy = H*.5, maxR = Math.min(W,H)*.42;
    const prog = total > 0 ? Math.min((aiStep+1)/total, 1) : 0;

    // Grid lines subtly
    ctx2.strokeStyle = 'rgba(255,183,0,.04)';
    ctx2.lineWidth = .5;
    for (let r = 20; r < maxR; r += 28) {
      ctx2.beginPath();
      ctx2.arc(cx, cy, r, 0, Math.PI*2);
      ctx2.stroke();
    }

    // Spiral trail
    const gradient = ctx2.createLinearGradient(cx-maxR, cy, cx+maxR, cy);
    gradient.addColorStop(0, 'rgba(0,229,192,0)');
    gradient.addColorStop(.5, 'rgba(255,183,0,.8)');
    gradient.addColorStop(1, 'rgba(0,229,192,0)');
    ctx2.beginPath();
    const steps = Math.floor(520 * prog);
    for (let i = 0; i <= steps; i++) {
      const t = (i/520)*prog;
      const a = i*0.18 + phase;
      const r2 = t * maxR;
      const x = cx + r2*Math.cos(a), y = cy + r2*Math.sin(a);
      i === 0 ? ctx2.moveTo(x,y) : ctx2.lineTo(x,y);
    }
    ctx2.strokeStyle = gradient;
    ctx2.lineWidth = 1.6;
    ctx2.shadowColor = 'rgba(255,183,0,.4)';
    ctx2.shadowBlur = 8;
    ctx2.stroke();
    ctx2.shadowBlur = 0;

    // Dot at current position
    if (aiStep >= 0 && total > 0) {
      const pr = (aiStep/Math.max(total-1,1)) * maxR * .9 + 4;
      const pa = aiStep * 0.18 + phase;
      ctx2.beginPath();
      ctx2.arc(cx+pr*Math.cos(pa), cy+pr*Math.sin(pa), 5, 0, Math.PI*2);
      ctx2.fillStyle = '#ffb700';
      ctx2.shadowColor = '#ffb700';
      ctx2.shadowBlur = 16;
      ctx2.fill();
      ctx2.shadowBlur = 0;
    }

    // φ text overlay
    ctx2.font = `bold ${14*devicePixelRatio/devicePixelRatio}px JetBrains Mono`;
    ctx2.fillStyle = 'rgba(255,183,0,.15)';
    ctx2.textAlign = 'center';
    ctx2.fillText('φ', cx, cy+5);
  }

  function loop() {
    spiralPhase += 0.012;
    drawSpiral(spiralPhase, step-1, seqData.length);
    spiralRaf = requestAnimationFrame(loop);
  }
  window._startSpiral = () => { cancelAnimationFrame(spiralRaf); loop(); };
  window._drawSpiral  = (ai,t) => drawSpiral(spiralPhase, ai, t);
  loop();
})();

/* ─── HELPERS ────────────────────────────────────────────── */
function getDelay() {
  return [1000, 650, 380, 200, 80, 20][parseInt(spdSlider.value)-1];
}
function fmtNum(v) {
  const s = String(v);
  if (s.length <= 9) return Number(s).toLocaleString();
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function showToast(msg, err=false) {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'on' + (err?' err':'');
  clearTimeout(t._t);
  t._t = setTimeout(()=>t.className='', 3200);
}
function setBusy(v) {
  btnGen.disabled = v;
  btnClear.disabled = v;
  if (!v) btnPause.disabled = true;
}

async function post(body) {
  const r = await fetch('', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(body).toString()
  });
  const d = await r.json().catch(()=>null);
  if (!r.ok || !d?.success) throw new Error(d?.msg || 'Request failed');
  return d;
}

/* ─── STATS ──────────────────────────────────────────────── */
async function refreshStats() {
  try {
    const d = await post({action:'stats'});
    $('sCached').textContent  = Number(d.cached).toLocaleString();
    $('sMaxPos').textContent  = d.maxPos ? Number(d.maxPos).toLocaleString() : '—';
    $('sMaxDig').textContent  = d.maxDigits ? Number(d.maxDigits).toLocaleString() : '—';
  } catch(e) {}
}

/* ─── VIEW SWITCHING ─────────────────────────────────────── */
document.querySelectorAll('.mode-pill').forEach(pill => {
  pill.addEventListener('click', () => {
    document.querySelectorAll('.mode-pill').forEach(p=>p.classList.remove('active'));
    pill.classList.add('active');
    viewMode = pill.dataset.mode;
    updateViewVisibility();
  });
});

function updateViewVisibility() {
  streamView.style.display = viewMode==='stream'    ? 'flex' : 'none';
  $('tableView').style.display = viewMode==='table' ? 'block': 'none';
  graphView.className = viewMode==='graph' ? 'visible' : '';
  spotlight.className = viewMode==='spotlight' ? 'on' : '';
}

/* ─── BUILD INITIAL DOM ──────────────────────────────────── */
function buildViews(list) {
  // Stream
  streamView.innerHTML = '';
  // Table
  tableBody.innerHTML = '';
  // Graph
  graphView.innerHTML = '';
  eqRow.innerHTML = '';
  progFill.style.width = '0%';
  progLbl.textContent = `0 / ${list.length}`;
  digitCount.textContent = '0';
  digitBar.style.width = '0%';
  phiRatioLive.textContent = '—';
  phiRatioLive.style.color = 'var(--gold)';
  maxDigits = Math.max(...list.map(x=>x.digits), 1);

  const isLargeN = list.length > 100;

  list.forEach((item, i) => {
    // Stream chips
    const chip = document.createElement('div');
    chip.className = 'fib-chip' + (isLargeN ? ' large-n' : '');
    chip.id = 'chip'+i;
    chip.innerHTML = `
      <div class="chip-val" id="cv${i}">?</div>
      <div class="chip-pos">F(${item.position})</div>
      ${item.digits > 15 ? `<div class="chip-digits">${item.digits}d</div>` : ''}
    `;
    streamView.appendChild(chip);

    // Table row
    const tr = document.createElement('tr');
    tr.id = 'tr'+i;
    tr.innerHTML = `
      <td class="pos" style="animation-delay:${i*.01}s">${item.position}</td>
      <td class="val" id="tv${i}" style="animation-delay:${i*.01+.05}s">—</td>
      <td class="ratio" id="tvr${i}" style="animation-delay:${i*.01+.1}s">—</td>
      <td class="digits" style="animation-delay:${i*.01+.15}s">${item.digits}</td>
      <td class="badge-new" style="animation-delay:${i*.01+.2}s">${item.new?'NEW':'cached'}</td>
    `;
    tableBody.appendChild(tr);

    // Graph bar
    const col = document.createElement('div');
    col.className = 'bar-col';
    col.innerHTML = `
      <div class="bar-fill" id="bf${i}"></div>
      ${list.length <= 60 ? `<div class="bar-lbl">${i}</div>` : ''}
    `;
    graphView.appendChild(col);
  });

  updateViewVisibility();
}

/* ─── PHI ROLL ANIMATION ─────────────────────────────────── */
function rollPhi(targetEl, target, dur=320) {
  const start = parseFloat(targetEl.dataset.cur||'0') || 0;
  const t0 = performance.now();
  function tick(now) {
    const p = Math.min((now-t0)/dur, 1);
    const e = 1 - Math.pow(1-p,3);
    const v = start + (target-start)*e;
    const txt = isFinite(v) ? v.toFixed(8) : '∞';
    targetEl.textContent = txt;
    targetEl.dataset.cur = v;
    if (p < 1) requestAnimationFrame(tick);
    else targetEl.textContent = isFinite(target) ? target.toFixed(8) : '∞';
  }
  requestAnimationFrame(tick);
}

/* ─── BURST PARTICLES ────────────────────────────────────── */
function burst(el) {
  const pr = el.getBoundingClientRect();
  const sr = $('mainView').getBoundingClientRect();
  const cx = pr.left - sr.left + pr.width/2;
  const cy = pr.top  - sr.top  + pr.height/2;
  for (let i = 0; i < 10; i++) {
    const p = document.createElement('div');
    p.className = 'pt';
    const a = Math.random() * Math.PI*2;
    const d = 28 + Math.random()*22;
    const colors = ['#ffb700','#ffd966','#00e5c0','#ff8c00'];
    p.style.cssText = `
      left:${cx-2.5}px; top:${cy-2.5}px;
      width:5px; height:5px;
      background:${colors[Math.floor(Math.random()*colors.length)]};
      --dx:${Math.cos(a)*d}px;
      --dy:${Math.sin(a)*d}px;
    `;
    $('mainView').appendChild(p);
    setTimeout(()=>p.remove(), 750);
  }
}

/* ─── REVEAL SINGLE STEP ─────────────────────────────────── */
function revealStep(s) {
  if (s >= seqData.length) { endAnim(); return; }
  const item = seqData[s];

  // Update progress
  const pct = ((s+1)/seqData.length*100).toFixed(1);
  progFill.style.width = pct+'%';
  progLbl.textContent  = `${s+1} / ${seqData.length}`;

  // Digit meter
  const dPct = Math.min((item.digits/maxDigits)*100, 100);
  digitCount.textContent = item.digits.toLocaleString();
  digitBar.style.width   = dPct+'%';
  if (item.digits > 50) digitCount.style.color = 'var(--gold)';
  else if (item.digits > 20) digitCount.style.color = 'var(--teal)';
  else digitCount.style.color = 'var(--teal)';

  // Stream view
  const chip = $('chip'+s);
  if (chip) {
    chip.classList.add('visible','active');
    const cv = $('cv'+s);
    if (cv) cv.textContent = item.value;
    // Ring burst
    const ring = document.createElement('div');
    ring.className='ring';
    chip.appendChild(ring);
    setTimeout(()=>ring.remove(),600);
    burst(chip);
    // Deactivate previous
    if (s > 0) {
      const prev = $('chip'+(s-1));
      if (prev) { prev.classList.remove('active'); prev.classList.add('done'); }
    }
    // Auto-scroll chips into view
    chip.scrollIntoView({block:'nearest',inline:'end',behavior:'smooth'});
  }

  // Table view
  const tr = $('tr'+s);
  if (tr) {
    document.querySelectorAll('#tableBody tr.active').forEach(r=>r.classList.remove('active'));
    tr.classList.add('active');
    const tv = $('tv'+s);
    if (tv) tv.textContent = item.value;
    if (s >= 1) {
      const ratio = parseFloat(item.full) / parseFloat(seqData[s-1].full);
      const tvr = $('tvr'+s);
      if (tvr && isFinite(ratio)) tvr.textContent = ratio.toFixed(8);
    }
    tr.scrollIntoView({block:'nearest', behavior:'smooth'});
  }

  // Graph view
  const maxV = Math.max(...seqData.slice(0,s+1).map(x=>parseFloat(x.full)||0), 1);
  for (let i = 0; i <= s; i++) {
    const bf = $('bf'+i);
    if (bf) {
      const h = ((parseFloat(seqData[i].full)||0)/maxV*160);
      bf.style.height = h+'px';
      bf.classList.remove('active','done');
      bf.classList.add(i===s?'active':'done');
    }
  }

  // Spotlight view
  if (viewMode==='spotlight') {
    $('spotPos').textContent = `F(${item.position})`;
    $('spotVal').textContent = item.full || item.value;
    $('spotVal').style.animation = 'none';
    void $('spotVal').offsetWidth;
    $('spotVal').style.animation = '';
    $('spotDigits').textContent  = item.digits.toLocaleString();
    $('spotNew').textContent     = item.new ? 'Newly computed' : 'From cache';
    if (s >= 1) {
      const r = parseFloat(item.full)/parseFloat(seqData[s-1].full);
      $('spotPhi').textContent = isFinite(r) ? r.toFixed(8) : '∞';
    } else {
      $('spotPhi').textContent = '—';
    }
  }

  // φ ratio & eq row
  if (s >= 1) {
    const ratio = parseFloat(item.full) / parseFloat(seqData[s-1].full);
    if (isFinite(ratio)) {
      rollPhi(phiRatioLive, ratio);
      const diff = Math.abs(ratio - PHI);
      phiRatioLive.style.color = diff < 0.001 ? 'var(--teal)' : diff < 0.01 ? 'var(--gold2)' : 'var(--gold)';
    }
  }
  if (s >= 2) {
    eqRow.innerHTML = `
      <span>F(${s-1})</span>
      <span class="eq-val">${item.value}</span>
      <span class="eq-op"> ... (F(${s-2}) + F(${s-1}))</span>
      <span style="color:var(--dim)"> → </span>
      <span class="eq-res">${item.value}</span>
    `;
  } else {
    eqRow.innerHTML = `<span style="color:var(--dim)">Seed value:</span><span class="eq-val">&nbsp;${item.value}</span>`;
  }

  // Speed label
  const spdLabels = ['Slow','Normal','Fast','Very Fast','Turbo','⚡ Max'];
  $('sSpd').textContent = spdLabels[parseInt(spdSlider.value)-1];
}

/* ─── ANIMATION LOOP ─────────────────────────────────────── */
function startAnim() {
  step = 0; playing = true; paused = false;
  btnGen.textContent = '■ Stop';
  btnPause.disabled = false;
  btnPause.textContent = '⏸ Pause';

  function tick() {
    if (paused) return;
    revealStep(step);
    step++;
    if (step > seqData.length) { clearInterval(timer); endAnim(); }
  }
  tick();
  timer = setInterval(tick, getDelay());
}

function endAnim() {
  playing = false; paused = false;
  clearInterval(timer);
  btnGen.textContent = '▶ Generate';
  btnPause.disabled = true;
  btnPause.textContent = '⏸ Pause';
  setBusy(false);
  // Finalise all chips
  document.querySelectorAll('.fib-chip.active').forEach(c=>{c.classList.remove('active');c.classList.add('done');});
  document.querySelectorAll('.bar-fill.active').forEach(b=>{b.classList.remove('active');b.classList.add('done');});
  progFill.style.width = '100%';
  refreshStats();
  showToast(`✓ Done! Computed ${seqData.length} terms.`);
}

/* ─── MAIN GENERATE ─────────────────────────────────────── */
async function generate() {
  if (playing) {
    clearInterval(timer);
    playing = false; paused = false;
    btnGen.textContent = '▶ Generate';
    btnPause.disabled = true;
    return;
  }
  const rawN = parseInt(nInput.value) || 20;
  const count = Math.max(1, rawN);

  setBusy(true);
  btnGen.textContent = 'Loading…';
  btnGen.classList.add('loading-dots');

  try {
    const {data} = await post({action:'generate', count});
    seqData = data;
    btnGen.classList.remove('loading-dots');
    buildViews(data);
    startAnim();
    setBusy(false);
    btnGen.disabled = false;
  } catch(e) {
    btnGen.classList.remove('loading-dots');
    showToast(e.message, true);
    setBusy(false);
    btnGen.textContent = '▶ Generate';
  }
}

/* ─── PAUSE / RESUME ─────────────────────────────────────── */
btnPause.addEventListener('click', () => {
  if (!playing) return;
  if (paused) {
    paused = false;
    btnPause.textContent = '⏸ Pause';
    timer = setInterval(()=>{
      if (paused) return;
      revealStep(step); step++;
      if(step>seqData.length){clearInterval(timer);endAnim();}
    }, getDelay());
  } else {
    paused = true;
    clearInterval(timer);
    btnPause.textContent = '▶ Resume';
  }
});

/* ─── CLEAR CACHE ────────────────────────────────────────── */
btnClear.addEventListener('click', async () => {
  if (!confirm('Clear all cached Fibonacci terms from DB?')) return;
  setBusy(true);
  try {
    await post({action:'clear'});
    seqData = [];
    streamView.innerHTML=''; tableBody.innerHTML='';
    graphView.innerHTML=''; eqRow.innerHTML='';
    progFill.style.width='0%';
    phiRatioLive.textContent='—';
    digitCount.textContent='0';
    digitBar.style.width='0%';
    showToast('Cache cleared.');
    refreshStats();
  } catch(e) { showToast(e.message,true); }
  finally { setBusy(false); }
});

/* ─── SPEED CHANGE WHILE PLAYING ────────────────────────── */
spdSlider.addEventListener('input', () => {
  const spdLabels = ['Slow','Normal','Fast','Very Fast','Turbo','⚡ Max'];
  $('sSpd').textContent = spdLabels[parseInt(spdSlider.value)-1];
  if (playing && !paused) {
    clearInterval(timer);
    timer = setInterval(()=>{
      if(paused)return;
      revealStep(step); step++;
      if(step>seqData.length){clearInterval(timer);endAnim();}
    }, getDelay());
  }
});

/* ─── LOOKUP SINGLE TERM ─────────────────────────────────── */
btnLookup.addEventListener('click', async () => {
  const pos = parseInt($('lookupN').value);
  if (!pos || pos < 1) { showToast('Enter a valid position.',true); return; }
  btnLookup.textContent='Computing…';
  btnLookup.disabled=true;
  try {
    const {item} = await post({action:'single', position:pos});
    const lr = $('lookupResult');
    lr.className = 'on';
    lr.innerHTML = `
      <div style="font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--dim);margin-bottom:10px;text-transform:uppercase;letter-spacing:.15em;">
        F(${item.position}) — ${item.digits} digits
      </div>
      <div class="lr-val">${item.value}</div>
      <div style="margin-top:10px;font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--dim);">
        ${item.new ? '🆕 Newly computed & cached' : '⚡ Retrieved from cache'}
      </div>
    `;
    refreshStats();
    showToast(`F(${pos}) computed! ${item.digits} digits.`);
  } catch(e) { showToast(e.message,true); }
  finally { btnLookup.textContent='Compute F(n)'; btnLookup.disabled=false; }
});

/* ─── KEYBOARD ──────────────────────────────────────────── */
document.addEventListener('keydown', e => {
  if (e.key==='Enter' && document.activeElement===nInput) generate();
  if (e.key==='Enter' && document.activeElement===$('lookupN')) btnLookup.click();
  if (e.key===' ' && playing) { e.preventDefault(); btnPause.click(); }
});

/* ─── INIT ──────────────────────────────────────────────── */
btnGen.addEventListener('click', generate);
refreshStats();
updateViewVisibility();
</script>
</body>
</html>