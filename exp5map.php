<?php
// ============================================================
//  ANATOMY DB — Single-file PHP Application
//  Configure your DB credentials below
// ============================================================

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'anatomy_db';

// ── DB Connection ────────────────────────────────────────────
function db() {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
                $DB_USER, $DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            die(json_encode(['error' => $e->getMessage()]));
        }
    }
    return $pdo;
}

// ── AJAX handlers ────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    if ($action === 'systems') {
        $rows = db()->query("SELECT * FROM body_systems ORDER BY id")->fetchAll();
        echo json_encode($rows); exit;
    }

    if ($action === 'structures') {
        $sid = (int)($_GET['system_id'] ?? 0);
        $diff = $_GET['difficulty'] ?? '';
        $search = $_GET['search'] ?? '';
        $sql = "SELECT s.*, bs.name AS system_name, bs.color AS system_color
                FROM structures s JOIN body_systems bs ON s.system_id = bs.id WHERE 1=1";
        $params = [];
        if ($sid) { $sql .= " AND s.system_id = ?"; $params[] = $sid; }
        if ($diff) { $sql .= " AND s.difficulty = ?"; $params[] = $diff; }
        if ($search) { $sql .= " AND (s.name LIKE ? OR s.latin_name LIKE ?)";
            $params[] = "%$search%"; $params[] = "%$search%"; }
        $sql .= " ORDER BY s.system_id, s.name";
        $stmt = db()->prepare($sql); $stmt->execute($params);
        echo json_encode($stmt->fetchAll()); exit;
    }

    if ($action === 'structure_detail') {
        $id = (int)($_GET['id'] ?? 0);
        $s = db()->prepare("SELECT s.*, bs.name AS system_name, bs.color AS system_color
            FROM structures s JOIN body_systems bs ON s.system_id=bs.id WHERE s.id=?");
        $s->execute([$id]); $struct = $s->fetch();

        $q = db()->prepare("SELECT * FROM quizzes WHERE structure_id=?");
        $q->execute([$id]); $quizzes = $q->fetchAll();

        $n = db()->prepare("SELECT * FROM study_nodes WHERE structure_id=? ORDER BY parent_node_id, id");
        $n->execute([$id]); $nodes = $n->fetchAll();

        echo json_encode(['structure'=>$struct,'quizzes'=>$quizzes,'nodes'=>$nodes]); exit;
    }

    if ($action === 'progress') {
        $sess = session_id() ?: 'demo-session-001';
        $rows = db()->prepare("SELECT up.*, s.name AS structure_name
            FROM user_progress up JOIN structures s ON up.structure_id=s.id
            WHERE up.session_id=? ORDER BY up.last_studied DESC LIMIT 20");
        $rows->execute([$sess]); echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'update_progress' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sess = session_id() ?: 'demo-session-001';
        $sid = (int)($data['structure_id'] ?? 0);
        $status = $data['status'] ?? 'unseen';
        $notes = $data['notes'] ?? '';
        $check = db()->prepare("SELECT id FROM user_progress WHERE session_id=? AND structure_id=?");
        $check->execute([$sess, $sid]);
        if ($check->fetch()) {
            $u = db()->prepare("UPDATE user_progress SET status=?,notes=? WHERE session_id=? AND structure_id=?");
            $u->execute([$status,$notes,$sess,$sid]);
        } else {
            $i = db()->prepare("INSERT INTO user_progress(session_id,structure_id,status,notes) VALUES(?,?,?,?)");
            $i->execute([$sess,$sid,$status,$notes]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'stats') {
        $sess = session_id() ?: 'demo-session-001';
        $total = db()->query("SELECT COUNT(*) FROM structures")->fetchColumn();
        $learned = db()->prepare("SELECT COUNT(*) FROM user_progress WHERE session_id=? AND status='learned'");
        $learned->execute([$sess]);
        $reviewing = db()->prepare("SELECT COUNT(*) FROM user_progress WHERE session_id=? AND status='reviewing'");
        $reviewing->execute([$sess]);
        $bySystem = db()->query("SELECT bs.name, bs.color, COUNT(s.id) as total
            FROM body_systems bs LEFT JOIN structures s ON bs.id=s.system_id GROUP BY bs.id")->fetchAll();
        echo json_encode(['total'=>$total,'learned'=>$learned->fetchColumn(),'reviewing'=>$reviewing->fetchColumn(),'bySystem'=>$bySystem]); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Anatomica — Human Body Explorer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:          #060912;
  --bg2:         #0c1020;
  --bg3:         #111827;
  --surface:     #161d2e;
  --surface2:    #1e2740;
  --border:      rgba(255,255,255,0.07);
  --border2:     rgba(255,255,255,0.13);
  --text:        #e8ecf4;
  --text2:       #8a95b0;
  --text3:       #4a5568;
  --accent:      #4fc3f7;
  --accent2:     #81d4fa;
  --gold:        #f6c347;
  --red:         #ff6b6b;
  --green:       #56cf8e;
  --radius:      12px;
  --radius2:     20px;
  --shadow:      0 8px 32px rgba(0,0,0,0.5);
  --shadow2:     0 2px 8px rgba(0,0,0,0.3);
  --transition:  all 0.28s cubic-bezier(0.4,0,0.2,1);
}

html { scroll-behavior: smooth; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'DM Sans', sans-serif;
  font-size: 15px;
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg2); }
::-webkit-scrollbar-thumb { background: var(--surface2); border-radius: 3px; }

/* ── Background orbs ── */
.bg-orbs {
  position: fixed; inset: 0; pointer-events: none; z-index: 0;
  overflow: hidden;
}
.orb {
  position: absolute; border-radius: 50%; filter: blur(100px); opacity: 0.18;
  animation: drift 20s ease-in-out infinite alternate;
}
.orb1 { width: 600px; height: 600px; background: #1a4a8a; top:-200px; left:-200px; animation-duration:25s; }
.orb2 { width: 500px; height: 500px; background: #0d3d5c; bottom:-100px; right:-100px; animation-duration:30s; animation-delay:-8s; }
.orb3 { width: 350px; height: 350px; background: #2d1b4e; top:40%; left:50%; animation-duration:22s; animation-delay:-4s; }
@keyframes drift { from { transform: translate(0,0) scale(1); } to { transform: translate(40px,30px) scale(1.08); } }

/* ── Layout ── */
.app { position: relative; z-index: 1; display: flex; min-height: 100vh; }

/* ── Sidebar ── */
.sidebar {
  width: 280px; min-width: 280px;
  background: linear-gradient(180deg, var(--bg2) 0%, var(--bg) 100%);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: sticky; top: 0; height: 100vh;
  overflow-y: auto; z-index: 10;
}

.logo {
  padding: 28px 24px 20px;
  border-bottom: 1px solid var(--border);
}
.logo-title {
  font-family: 'Playfair Display', serif;
  font-size: 26px; font-weight: 900;
  letter-spacing: -0.5px;
  background: linear-gradient(135deg, var(--accent) 0%, #b3e5fc 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.logo-sub { font-size: 11px; color: var(--text3); letter-spacing: 2px; text-transform: uppercase; margin-top: 3px; }

/* Stats bar */
.stats-bar {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;
}
.stat-item { text-align: center; }
.stat-val { font-size: 20px; font-weight: 700; font-family: 'DM Mono', monospace; }
.stat-val.learned { color: var(--green); }
.stat-val.reviewing { color: var(--gold); }
.stat-val.total { color: var(--accent); }
.stat-lbl { font-size: 9px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }

/* Nav */
.nav-section { padding: 16px 16px 8px; }
.nav-label { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 1.5px; padding: 0 8px 8px; }

.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: var(--radius);
  cursor: pointer; transition: var(--transition);
  font-size: 14px; color: var(--text2); font-weight: 500;
  margin-bottom: 2px; border: 1px solid transparent;
}
.nav-item:hover { background: var(--surface); color: var(--text); }
.nav-item.active { background: var(--surface2); color: var(--accent); border-color: rgba(79,195,247,0.2); }
.nav-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.nav-count { margin-left: auto; font-size: 11px; color: var(--text3); font-family: 'DM Mono',monospace; }

/* Systems nav */
.systems-nav { flex: 1; overflow-y: auto; padding: 8px 16px 16px; }

/* ── Main content ── */
.main { flex: 1; display: flex; flex-direction: column; min-width: 0; }

/* ── Topbar ── */
.topbar {
  padding: 20px 32px;
  border-bottom: 1px solid var(--border);
  background: rgba(6,9,18,0.8);
  backdrop-filter: blur(20px);
  display: flex; align-items: center; gap: 16px;
  position: sticky; top: 0; z-index: 9;
}
.topbar-title {
  font-family: 'Playfair Display', serif;
  font-size: 22px; font-weight: 700;
}
.topbar-title span { color: var(--accent); }

.search-wrap { flex: 1; max-width: 400px; position: relative; }
.search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text3); font-size: 16px; }
.search-input {
  width: 100%; padding: 10px 16px 10px 40px;
  background: var(--surface); border: 1px solid var(--border2);
  border-radius: 50px; color: var(--text); font-family: 'DM Sans',sans-serif;
  font-size: 14px; outline: none; transition: var(--transition);
}
.search-input:focus { border-color: var(--accent); background: var(--surface2); box-shadow: 0 0 0 3px rgba(79,195,247,0.1); }
.search-input::placeholder { color: var(--text3); }

.filter-btns { display: flex; gap: 6px; }
.filter-btn {
  padding: 8px 16px; border-radius: 50px; font-size: 12px; font-weight: 600;
  cursor: pointer; transition: var(--transition); border: 1px solid var(--border2);
  background: transparent; color: var(--text2); font-family: 'DM Sans',sans-serif;
  letter-spacing: 0.3px;
}
.filter-btn:hover { border-color: var(--accent); color: var(--accent); }
.filter-btn.active { background: var(--accent); color: var(--bg); border-color: var(--accent); }

/* ── Content area ── */
.content { flex: 1; padding: 28px 32px; overflow-y: auto; }

/* ── View panels ── */
.view { display: none; }
.view.active { display: block; }

/* ── Dashboard ── */
.dashboard-hero {
  background: linear-gradient(135deg, var(--surface) 0%, var(--surface2) 100%);
  border: 1px solid var(--border2); border-radius: var(--radius2);
  padding: 36px 40px; margin-bottom: 28px;
  position: relative; overflow: hidden;
}
.dashboard-hero::before {
  content: '⚕'; position: absolute; right: 30px; top: 50%; transform: translateY(-50%);
  font-size: 120px; opacity: 0.05; line-height: 1;
}
.hero-title {
  font-family: 'Playfair Display', serif; font-size: 36px; font-weight: 900;
  line-height: 1.2; margin-bottom: 10px;
}
.hero-title em { font-style: italic; color: var(--accent); }
.hero-sub { color: var(--text2); font-size: 16px; max-width: 480px; line-height: 1.6; }

.progress-ring-wrap { display: flex; gap: 24px; margin-top: 24px; }
.progress-pill {
  display: flex; align-items: center; gap: 10px; padding: 10px 20px;
  background: rgba(0,0,0,0.3); border-radius: 50px; border: 1px solid var(--border);
}
.pill-dot { width: 8px; height: 8px; border-radius: 50%; }
.pill-txt { font-size: 13px; font-weight: 500; }

/* System cards grid */
.section-hdr {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 18px;
}
.section-title {
  font-family: 'Playfair Display', serif; font-size: 20px; font-weight: 700;
}
.see-all { font-size: 12px; color: var(--accent); cursor: pointer; font-weight: 600; letter-spacing: 0.5px; }
.see-all:hover { text-decoration: underline; }

.systems-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 36px; }

.system-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius2); padding: 22px 20px;
  cursor: pointer; transition: var(--transition);
  position: relative; overflow: hidden;
}
.system-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: var(--card-color, var(--accent));
  transition: var(--transition);
}
.system-card:hover { transform: translateY(-3px); border-color: var(--border2); box-shadow: 0 12px 40px rgba(0,0,0,0.4); }
.system-card:hover::before { height: 5px; }
.sys-icon { font-size: 28px; margin-bottom: 12px; }
.sys-name { font-weight: 700; font-size: 15px; margin-bottom: 4px; }
.sys-desc { font-size: 12px; color: var(--text2); line-height: 1.5; }
.sys-count { margin-top: 12px; font-size: 11px; font-family: 'DM Mono',monospace; color: var(--text3); }

/* ── Structures grid ── */
.structures-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }

.struct-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius2); padding: 22px;
  cursor: pointer; transition: var(--transition);
  position: relative; overflow: hidden;
  animation: fadeUp 0.4s ease both;
}
@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
.struct-card:hover { transform: translateY(-2px); border-color: var(--border2); box-shadow: var(--shadow); }

.struct-card-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; }
.struct-name { font-family: 'Playfair Display',serif; font-size: 18px; font-weight: 700; line-height: 1.2; }
.struct-latin { font-size: 12px; color: var(--text3); font-style: italic; margin-top: 3px; }
.diff-badge {
  font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 50px;
  text-transform: uppercase; letter-spacing: 0.8px; white-space: nowrap; flex-shrink: 0;
}
.diff-beginner   { background: rgba(86,207,142,0.15); color: var(--green); border: 1px solid rgba(86,207,142,0.3); }
.diff-intermediate { background: rgba(246,195,71,0.15); color: var(--gold); border: 1px solid rgba(246,195,71,0.3); }
.diff-advanced   { background: rgba(255,107,107,0.15); color: var(--red); border: 1px solid rgba(255,107,107,0.3); }

.struct-desc { font-size: 13px; color: var(--text2); line-height: 1.6; margin-bottom: 14px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

.struct-footer { display: flex; align-items: center; justify-content: space-between; }
.sys-tag {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; color: var(--text2); font-weight: 500;
}
.sys-tag-dot { width: 8px; height: 8px; border-radius: 50%; }

.progress-status { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 50px; }
.status-learned   { background: rgba(86,207,142,0.15); color: var(--green); }
.status-reviewing { background: rgba(246,195,71,0.15); color: var(--gold); }
.status-unseen    { background: rgba(255,255,255,0.05); color: var(--text3); }

/* ── Quiz view ── */
.quiz-container { max-width: 680px; }
.quiz-card {
  background: var(--surface); border: 1px solid var(--border2);
  border-radius: var(--radius2); padding: 32px; margin-bottom: 20px;
}
.quiz-meta { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 16px; }
.quiz-q { font-family: 'Playfair Display',serif; font-size: 22px; font-weight: 700; line-height: 1.4; margin-bottom: 24px; }
.quiz-options { display: grid; gap: 10px; }
.quiz-opt {
  padding: 14px 20px; border-radius: var(--radius);
  border: 1px solid var(--border2); cursor: pointer;
  transition: var(--transition); font-size: 14px; color: var(--text2);
  background: var(--bg2);
}
.quiz-opt:hover:not(.answered) { border-color: var(--accent); color: var(--text); background: var(--surface2); }
.quiz-opt.correct { background: rgba(86,207,142,0.15); border-color: var(--green); color: var(--green); }
.quiz-opt.wrong   { background: rgba(255,107,107,0.1); border-color: var(--red); color: var(--red); }
.quiz-feedback { margin-top: 18px; padding: 14px 18px; border-radius: var(--radius); font-size: 14px; }
.quiz-feedback.correct { background: rgba(86,207,142,0.1); border: 1px solid rgba(86,207,142,0.3); color: var(--green); }
.quiz-feedback.wrong   { background: rgba(255,107,107,0.08); border: 1px solid rgba(255,107,107,0.25); color: var(--red); }
.quiz-progress-bar { height: 4px; background: var(--surface2); border-radius: 2px; margin-bottom: 24px; }
.quiz-progress-fill { height: 100%; background: var(--accent); border-radius: 2px; transition: width 0.4s ease; }
.quiz-score { text-align: center; padding: 40px; }
.quiz-score-num { font-family: 'Playfair Display',serif; font-size: 72px; font-weight: 900; color: var(--accent); line-height: 1; }
.quiz-score-lbl { font-size: 16px; color: var(--text2); margin-top: 8px; }

/* ── Detail modal ── */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.75);
  backdrop-filter: blur(8px); z-index: 1000;
  display: none; align-items: center; justify-content: center;
  padding: 20px; animation: fadeIn 0.2s ease;
}
.modal-overlay.open { display: flex; }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }

.modal {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius2); width: 100%; max-width: 700px;
  max-height: 90vh; overflow-y: auto;
  animation: slideUp 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes slideUp { from { transform:translateY(30px); opacity:0; } to { transform:translateY(0); opacity:1; } }

.modal-header {
  padding: 28px 32px 20px; border-bottom: 1px solid var(--border);
  position: sticky; top: 0; background: var(--bg2); z-index: 1;
  display: flex; justify-content: space-between; align-items: flex-start;
}
.modal-close {
  width: 36px; height: 36px; border-radius: 50%; background: var(--surface);
  border: 1px solid var(--border2); cursor: pointer; display: flex;
  align-items: center; justify-content: center; font-size: 18px;
  color: var(--text2); transition: var(--transition); flex-shrink: 0;
}
.modal-close:hover { background: var(--surface2); color: var(--text); }

.modal-body { padding: 24px 32px 32px; }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
.info-block {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px;
}
.info-block.full { grid-column: 1/-1; }
.info-label { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; }
.info-value { font-size: 14px; color: var(--text); line-height: 1.6; }
.info-value.latin { font-style: italic; color: var(--accent2); font-size: 15px; }

.clinical-block {
  background: linear-gradient(135deg, rgba(255,107,107,0.08), rgba(255,107,107,0.03));
  border: 1px solid rgba(255,107,107,0.2); border-radius: var(--radius); padding: 16px;
  margin-bottom: 24px;
}
.clinical-label { font-size: 10px; color: var(--red); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.clinical-value { font-size: 13px; color: var(--text2); line-height: 1.7; }

.nodes-section { margin-bottom: 24px; }
.node-tree { display: flex; flex-wrap: wrap; gap: 8px; }
.node-chip {
  padding: 6px 14px; border-radius: 50px; font-size: 12px; font-weight: 500;
  border: 1px solid var(--border2); color: var(--text2); background: var(--surface);
}
.node-chip.parent { border-color: var(--accent); color: var(--accent); background: rgba(79,195,247,0.08); }

/* Progress controls */
.progress-controls {
  background: var(--surface); border: 1px solid var(--border2);
  border-radius: var(--radius2); padding: 20px; margin-bottom: 24px;
}
.progress-label { font-size: 12px; color: var(--text2); font-weight: 600; margin-bottom: 12px; }
.status-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.status-btn {
  padding: 8px 18px; border-radius: 50px; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: var(--transition); border: 1px solid var(--border2);
  background: transparent; color: var(--text2); font-family: 'DM Sans',sans-serif;
}
.status-btn:hover, .status-btn.active { transform: none; }
.status-btn[data-s="learned"].active   { background: rgba(86,207,142,0.2); border-color: var(--green); color: var(--green); }
.status-btn[data-s="reviewing"].active { background: rgba(246,195,71,0.2); border-color: var(--gold); color: var(--gold); }
.status-btn[data-s="unseen"].active    { background: var(--surface2); border-color: var(--border2); color: var(--text); }
.notes-input {
  width: 100%; margin-top: 12px; padding: 12px;
  background: var(--bg); border: 1px solid var(--border2); border-radius: var(--radius);
  color: var(--text); font-family: 'DM Sans',sans-serif; font-size: 13px;
  resize: vertical; min-height: 70px; outline: none; transition: var(--transition);
}
.notes-input:focus { border-color: var(--accent); }
.save-btn {
  margin-top: 10px; padding: 10px 24px; background: var(--accent); color: var(--bg);
  border: none; border-radius: 50px; font-family: 'DM Sans',sans-serif;
  font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition);
}
.save-btn:hover { background: var(--accent2); transform: scale(1.02); }

/* Empty state */
.empty-state { text-align: center; padding: 80px 20px; color: var(--text3); }
.empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.4; }
.empty-txt { font-size: 15px; }

/* Loading */
.loading { text-align: center; padding: 60px; color: var(--text3); }
.spinner {
  width: 36px; height: 36px; border: 3px solid var(--surface2);
  border-top-color: var(--accent); border-radius: 50%;
  animation: spin 0.8s linear infinite; margin: 0 auto 16px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Toaster */
.toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  background: var(--surface2); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 12px 20px; font-size: 14px;
  color: var(--text); box-shadow: var(--shadow);
  transform: translateY(20px); opacity: 0; transition: all 0.3s ease;
  pointer-events: none;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-color: var(--green); color: var(--green); }

/* Responsive */
@media (max-width: 900px) {
  .sidebar { width: 240px; min-width: 240px; }
  .content { padding: 20px; }
  .topbar { padding: 16px 20px; flex-wrap: wrap; }
  .info-grid { grid-template-columns: 1fr; }
  .info-block.full { grid-column: 1; }
}
@media (max-width: 660px) {
  .app { flex-direction: column; }
  .sidebar { width: 100%; min-width: unset; height: auto; position: relative; }
  .systems-nav { display: none; }
  .structures-grid { grid-template-columns: 1fr; }
  .systems-grid { grid-template-columns: repeat(2,1fr); }
}
</style>
</head>
<body>

<div class="bg-orbs">
  <div class="orb orb1"></div>
  <div class="orb orb2"></div>
  <div class="orb orb3"></div>
</div>

<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-title">Anatomica</div>
      <div class="logo-sub">Human Body Explorer</div>
    </div>

    <div class="stats-bar">
      <div class="stat-item">
        <div class="stat-val total" id="sb-total">—</div>
        <div class="stat-lbl">Total</div>
      </div>
      <div class="stat-item">
        <div class="stat-val learned" id="sb-learned">—</div>
        <div class="stat-lbl">Learned</div>
      </div>
      <div class="stat-item">
        <div class="stat-val reviewing" id="sb-reviewing">—</div>
        <div class="stat-lbl">Review</div>
      </div>
    </div>

    <div class="nav-section">
      <div class="nav-label">Navigation</div>
      <div class="nav-item active" data-view="dashboard" onclick="navigate('dashboard',this)">
        <span style="font-size:16px">🏠</span> Dashboard
      </div>
      <div class="nav-item" data-view="structures" onclick="navigate('structures',this)">
        <span style="font-size:16px">🫀</span> All Structures
      </div>
      <div class="nav-item" data-view="quiz" onclick="navigate('quiz',this)">
        <span style="font-size:16px">🧠</span> Quiz Mode
      </div>
    </div>

    <div class="systems-nav" id="systems-nav">
      <div class="nav-label" style="padding:8px 8px 6px">Body Systems</div>
      <div id="sidebar-systems"></div>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <!-- TOPBAR -->
    <div class="topbar">
      <div class="topbar-title" id="page-title">
        <span>Dashboard</span>
      </div>
      <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" class="search-input" id="search-input" placeholder="Search structures, organs…" oninput="onSearch(this.value)">
      </div>
      <div class="filter-btns" id="filter-btns">
        <button class="filter-btn active" onclick="setFilter('',this)">All</button>
        <button class="filter-btn" onclick="setFilter('beginner',this)">Beginner</button>
        <button class="filter-btn" onclick="setFilter('intermediate',this)">Intermediate</button>
        <button class="filter-btn" onclick="setFilter('advanced',this)">Advanced</button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

      <!-- DASHBOARD VIEW -->
      <div id="view-dashboard" class="view active">
        <div class="dashboard-hero">
          <div class="hero-title">Explore the <em>Human Body</em><br>Like Never Before</div>
          <div class="hero-sub">An interactive atlas of anatomy — structures, clinical notes, quizzes, and progress tracking in one elegant tool.</div>
          <div class="progress-ring-wrap" id="hero-progress"></div>
        </div>

        <div class="section-hdr">
          <div class="section-title">Body Systems</div>
          <div class="see-all" onclick="navigate('structures',document.querySelector('[data-view=structures]'))">View all structures →</div>
        </div>
        <div class="systems-grid" id="dash-systems-grid">
          <div class="loading"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- STRUCTURES VIEW -->
      <div id="view-structures" class="view">
        <div class="structures-grid" id="structures-grid">
          <div class="loading" style="grid-column:1/-1"><div class="spinner"></div>Loading structures…</div>
        </div>
      </div>

      <!-- QUIZ VIEW -->
      <div id="view-quiz" class="view">
        <div class="quiz-container" id="quiz-container">
          <div class="loading"><div class="spinner"></div>Loading quiz…</div>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- DETAIL MODAL -->
<div class="modal-overlay" id="detail-modal" onclick="closeModal(event)">
  <div class="modal" id="modal-inner">
    <div class="modal-header">
      <div>
        <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px" id="modal-system-tag"></div>
        <div style="font-family:'Playfair Display',serif;font-size:26px;font-weight:900" id="modal-name"></div>
        <div style="font-style:italic;color:var(--accent2);font-size:14px;margin-top:3px" id="modal-latin"></div>
      </div>
      <button class="modal-close" onclick="document.getElementById('detail-modal').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body" id="modal-body"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ═══════════════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════════════
const state = {
  systems: [],
  structures: [],
  progress: {},
  currentSystem: 0,
  currentDiff: '',
  currentSearch: '',
  quizQuestions: [],
  quizIdx: 0,
  quizScore: 0,
  quizAnswered: false,
};

// ═══════════════════════════════════════════════════════════════
//  UTILITIES
// ═══════════════════════════════════════════════════════════════
const $ = id => document.getElementById(id);
const api = (params) => fetch('?' + new URLSearchParams(params)).then(r => r.json());

function showToast(msg, type='') {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'toast show' + (type ? ' '+type : '');
  setTimeout(() => t.className = 'toast', 2800);
}

const sysIcons = {
  'Skeletal':'🦴','Muscular':'💪','Nervous':'🧠','Cardiovascular':'🫀',
  'Respiratory':'🫁','Digestive':'🫃','Endocrine':'⚗️','Urinary':'🫘',
  'Lymphatic':'🛡️','Integumentary':'🧬'
};

function diffBadge(d) {
  return `<span class="diff-badge diff-${d}">${d}</span>`;
}

function shuffle(arr) {
  return [...arr].sort(() => Math.random() - 0.5);
}

// ═══════════════════════════════════════════════════════════════
//  BOOTSTRAP
// ═══════════════════════════════════════════════════════════════
async function init() {
  const [systems, stats, progress] = await Promise.all([
    api({api:'systems'}),
    api({api:'stats'}),
    api({api:'progress'}),
  ]);

  state.systems = systems;
  progress.forEach(p => state.progress[p.structure_id] = p);

  // Sidebar stats
  $('sb-total').textContent    = stats.total;
  $('sb-learned').textContent  = stats.learned;
  $('sb-reviewing').textContent = stats.reviewing;

  // Hero progress
  const pct = Math.round((stats.learned / stats.total) * 100);
  $('hero-progress').innerHTML = `
    <div class="progress-pill"><span class="pill-dot" style="background:var(--green)"></span><span class="pill-txt">${stats.learned} learned</span></div>
    <div class="progress-pill"><span class="pill-dot" style="background:var(--gold)"></span><span class="pill-txt">${stats.reviewing} reviewing</span></div>
    <div class="progress-pill"><span class="pill-dot" style="background:var(--accent)"></span><span class="pill-txt">${pct}% complete</span></div>
  `;

  // Sidebar systems nav
  const snav = $('sidebar-systems');
  snav.innerHTML = systems.map(s => `
    <div class="nav-item" onclick="filterBySystem(${s.id},this)" data-sys="${s.id}">
      <span class="nav-dot" style="background:${s.color}"></span>
      ${s.name}
    </div>
  `).join('');

  // Dashboard systems grid
  const bySystem = {};
  stats.bySystem.forEach(r => bySystem[r.name] = r.total);
  $('dash-systems-grid').innerHTML = systems.map(s => `
    <div class="system-card" style="--card-color:${s.color}" onclick="filterBySystem(${s.id})">
      <div class="sys-icon">${sysIcons[s.name] || '🔬'}</div>
      <div class="sys-name">${s.name}</div>
      <div class="sys-desc">${s.description}</div>
      <div class="sys-count">${bySystem[s.name] || 0} structures</div>
    </div>
  `).join('');
}

// ═══════════════════════════════════════════════════════════════
//  NAVIGATION
// ═══════════════════════════════════════════════════════════════
function navigate(view, el) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  $(`view-${view}`).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');

  const titles = { dashboard: 'Dashboard', structures: 'All Structures', quiz: 'Quiz Mode' };
  $('page-title').innerHTML = `<span>${titles[view] || view}</span>`;

  if (view === 'structures') loadStructures();
  if (view === 'quiz') loadQuiz();
}

function filterBySystem(sysId, el) {
  state.currentSystem = sysId;
  state.currentSearch = ''; $('search-input').value = '';
  navigate('structures', null);
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
  const sys = state.systems.find(s => s.id == sysId);
  if (sys) $('page-title').innerHTML = `<span style="color:${sys.color}">${sysIcons[sys.name]||'🔬'} ${sys.name}</span>`;
  loadStructures();
}

// ═══════════════════════════════════════════════════════════════
//  STRUCTURES
// ═══════════════════════════════════════════════════════════════
let searchTimer = null;
function onSearch(val) {
  state.currentSearch = val;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    if (document.querySelector('.view.active').id !== 'view-structures') {
      navigate('structures', document.querySelector('[data-view=structures]'));
    }
    loadStructures();
  }, 300);
}

function setFilter(diff, btn) {
  state.currentDiff = diff;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadStructures();
}

async function loadStructures() {
  const grid = $('structures-grid');
  grid.innerHTML = '<div class="loading" style="grid-column:1/-1"><div class="spinner"></div>Loading…</div>';
  const params = { api:'structures' };
  if (state.currentSystem) params.system_id = state.currentSystem;
  if (state.currentDiff)   params.difficulty = state.currentDiff;
  if (state.currentSearch) params.search = state.currentSearch;
  const structs = await api(params);
  state.structures = structs;
  if (!structs.length) {
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">🔍</div><div class="empty-txt">No structures found</div></div>';
    return;
  }
  grid.innerHTML = structs.map((s,i) => {
    const prog = state.progress[s.id];
    const status = prog ? prog.status : 'unseen';
    return `
    <div class="struct-card" style="animation-delay:${i*0.04}s" onclick="openDetail(${s.id})">
      <div class="struct-card-top">
        <div>
          <div class="struct-name">${s.name}</div>
          <div class="struct-latin">${s.latin_name || ''}</div>
        </div>
        ${diffBadge(s.difficulty)}
      </div>
      <div class="struct-desc">${s.description || ''}</div>
      <div class="struct-footer">
        <div class="sys-tag">
          <span class="sys-tag-dot" style="background:${s.system_color}"></span>
          ${s.system_name}
        </div>
        <span class="progress-status status-${status}">${status}</span>
      </div>
    </div>`;
  }).join('');
}

// ═══════════════════════════════════════════════════════════════
//  DETAIL MODAL
// ═══════════════════════════════════════════════════════════════
async function openDetail(id) {
  const overlay = $('detail-modal');
  overlay.classList.add('open');
  $('modal-body').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
  $('modal-name').textContent = '…';
  $('modal-latin').textContent = '';
  $('modal-system-tag').textContent = '';

  const data = await api({api:'structure_detail', id});
  const s = data.structure;
  const quizzes = data.quizzes;
  const nodes = data.nodes;
  const prog = state.progress[id];

  $('modal-system-tag').innerHTML = `<span style="color:${s.system_color}">${sysIcons[s.system_name]||'🔬'} ${s.system_name}</span>`;
  $('modal-name').textContent = s.name;
  $('modal-latin').textContent = s.latin_name || '';

  const parentNodes = nodes.filter(n => !n.parent_node_id);
  const childNodes  = nodes.filter(n => n.parent_node_id);

  const nodesHtml = nodes.length ? `
    <div class="nodes-section">
      <div class="info-label" style="margin-bottom:10px">Study Nodes</div>
      <div class="node-tree">
        ${parentNodes.map(n => `<span class="node-chip parent">${n.label}</span>`).join('')}
        ${childNodes.map(n => `<span class="node-chip">${n.label}</span>`).join('')}
      </div>
    </div>` : '';

  const currentStatus = prog ? prog.status : 'unseen';
  const currentNotes  = prog ? (prog.notes || '') : '';

  $('modal-body').innerHTML = `
    <div class="info-grid">
      <div class="info-block">
        <div class="info-label">Latin Name</div>
        <div class="info-value latin">${s.latin_name || 'N/A'}</div>
      </div>
      <div class="info-block">
        <div class="info-label">Difficulty</div>
        <div class="info-value">${diffBadge(s.difficulty)}</div>
      </div>
      <div class="info-block full">
        <div class="info-label">Description</div>
        <div class="info-value">${s.description || 'N/A'}</div>
      </div>
      <div class="info-block full">
        <div class="info-label">Functions</div>
        <div class="info-value">${s.functions || 'N/A'}</div>
      </div>
    </div>

    ${s.clinical_notes ? `
    <div class="clinical-block">
      <div class="clinical-label">⚕ Clinical Notes</div>
      <div class="clinical-value">${s.clinical_notes}</div>
    </div>` : ''}

    ${nodesHtml}

    <div class="progress-controls">
      <div class="progress-label">Study Progress</div>
      <div class="status-btns">
        <button class="status-btn ${currentStatus==='unseen'?'active':''}" data-s="unseen" onclick="setModalStatus(this,'unseen')">📋 Unseen</button>
        <button class="status-btn ${currentStatus==='reviewing'?'active':''}" data-s="reviewing" onclick="setModalStatus(this,'reviewing')">📖 Reviewing</button>
        <button class="status-btn ${currentStatus==='learned'?'active':''}" data-s="learned" onclick="setModalStatus(this,'learned')">✅ Learned</button>
      </div>
      <textarea class="notes-input" id="modal-notes" placeholder="Add personal notes…">${currentNotes}</textarea>
      <button class="save-btn" onclick="saveProgress(${id})">Save Progress</button>
    </div>

    ${quizzes.length ? `
    <div>
      <div class="info-label" style="margin-bottom:14px">Sample Quiz Questions (${quizzes.length})</div>
      <div style="display:grid;gap:10px">
        ${quizzes.slice(0,2).map(q => `
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px">
            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text)">${q.question}</div>
            <div style="font-size:12px;color:var(--green)">✓ ${q.correct_answer}</div>
          </div>
        `).join('')}
      </div>
    </div>` : ''}
  `;
}

function setModalStatus(btn, status) {
  document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

async function saveProgress(structureId) {
  const statusBtn = document.querySelector('.status-btn.active');
  const status = statusBtn ? statusBtn.dataset.s : 'unseen';
  const notes = $('modal-notes').value;
  await fetch('?api=update_progress', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({structure_id: structureId, status, notes})
  });
  state.progress[structureId] = {structure_id: structureId, status, notes};
  showToast('Progress saved!', 'success');
  loadStructures();
  const stats = await api({api:'stats'});
  $('sb-learned').textContent = stats.learned;
  $('sb-reviewing').textContent = stats.reviewing;
}

function closeModal(e) {
  if (e.target === $('detail-modal')) $('detail-modal').classList.remove('open');
}

// ═══════════════════════════════════════════════════════════════
//  QUIZ
// ═══════════════════════════════════════════════════════════════
async function loadQuiz() {
  const container = $('quiz-container');
  container.innerHTML = '<div class="loading"><div class="spinner"></div>Preparing quiz…</div>';
  const structs = await api({api:'structures'});
  const allQ = [];
  for (const s of structs) {
    const d = await api({api:'structure_detail', id:s.id});
    d.quizzes.forEach(q => allQ.push({...q, structureName: s.name}));
  }
  state.quizQuestions = shuffle(allQ).slice(0, 10);
  state.quizIdx = 0;
  state.quizScore = 0;
  renderQuizQuestion();
}

function renderQuizQuestion() {
  const container = $('quiz-container');
  const qs = state.quizQuestions;
  if (state.quizIdx >= qs.length) {
    const pct = Math.round((state.quizScore / qs.length) * 100);
    const emoji = pct>=80?'🎉':pct>=50?'👍':'📚';
    container.innerHTML = `
      <div class="quiz-card">
        <div class="quiz-score">
          <div style="font-size:48px;margin-bottom:12px">${emoji}</div>
          <div class="quiz-score-num">${state.quizScore}<span style="font-size:36px;color:var(--text3)">/${qs.length}</span></div>
          <div class="quiz-score-lbl">${pct}% correct — ${pct>=80?'Excellent work!':pct>=50?'Good effort!':'Keep studying!'}</div>
          <button class="save-btn" style="margin-top:24px" onclick="loadQuiz()">Try Again 🔁</button>
        </div>
      </div>`;
    return;
  }
  const q = qs[state.quizIdx];
  const wrong = JSON.parse(q.wrong_answers);
  const opts = shuffle([q.correct_answer, ...wrong]);
  const pct = (state.quizIdx / qs.length) * 100;
  state.quizAnswered = false;

  container.innerHTML = `
    <div class="quiz-progress-bar"><div class="quiz-progress-fill" style="width:${pct}%"></div></div>
    <div class="quiz-card">
      <div class="quiz-meta">Question ${state.quizIdx+1} of ${qs.length} · ${q.structureName}</div>
      <div class="quiz-q">${q.question}</div>
      <div class="quiz-options">
        ${opts.map(o => `<div class="quiz-opt" onclick="answerQuiz(this,'${o.replace(/'/g,"\\'")}','${q.correct_answer.replace(/'/g,"\\'")}')">
          <span style="font-size:11px;font-family:'DM Mono',monospace;color:var(--text3);margin-right:10px">${String.fromCharCode(65+opts.indexOf(o))}</span>${o}
        </div>`).join('')}
      </div>
      <div id="quiz-feedback" style="display:none" class="quiz-feedback"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <button class="save-btn" id="next-btn" style="display:none" onclick="nextQuestion()">Next Question →</button>
    </div>
  `;
}

function answerQuiz(el, chosen, correct) {
  if (state.quizAnswered) return;
  state.quizAnswered = true;
  const opts = document.querySelectorAll('.quiz-opt');
  opts.forEach(o => o.classList.add('answered'));
  if (chosen === correct) {
    el.classList.add('correct');
    state.quizScore++;
    $('quiz-feedback').innerHTML = '✓ Correct!';
    $('quiz-feedback').className = 'quiz-feedback correct';
  } else {
    el.classList.add('wrong');
    opts.forEach(o => { if (o.textContent.includes(correct)) o.classList.add('correct'); });
    $('quiz-feedback').innerHTML = `✗ The correct answer is: <strong>${correct}</strong>`;
    $('quiz-feedback').className = 'quiz-feedback wrong';
  }
  $('quiz-feedback').style.display = 'block';
  $('next-btn').style.display = 'block';
}

function nextQuestion() {
  state.quizIdx++;
  renderQuizQuestion();
}

// ═══════════════════════════════════════════════════════════════
//  START
// ═══════════════════════════════════════════════════════════════
init();
</script>
</body>
</html>