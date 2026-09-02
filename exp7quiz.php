<?php
// ============================================================
//  QuizMaster Pro — Single-File PHP+MySQL Quiz App  [FIXED]
//  Fixes: Timer display, Submit button, DB level column
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'quiz_system');

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        if ($conn->connect_error) die(json_encode(['error' => $conn->connect_error]));
    }
    return $conn;
}

session_start();

// ── AJAX HANDLERS ─────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {

        case 'register':
            $fullname = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username']  ?? '');
            $email    = trim($_POST['email']     ?? '');
            $pass     = $_POST['password']       ?? '';
            if (!$fullname || !$username || !$email || !$pass) {
                echo json_encode(['ok'=>false,'msg'=>'All fields are required.']); exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid email address.']); exit;
            }
            if (strlen($pass) < 6) {
                echo json_encode(['ok'=>false,'msg'=>'Password must be at least 6 characters.']); exit;
            }
            $stmt = db()->prepare("SELECT id FROM users WHERE email=? OR username=?");
            $stmt->bind_param('ss', $email, $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['ok'=>false,'msg'=>'Email or username already taken.']); exit;
            }
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $stmt2  = db()->prepare("INSERT INTO users (full_name,username,email,password,role) VALUES (?,?,?,?,'student')");
            $stmt2->bind_param('ssss', $fullname, $username, $email, $hashed);
            if ($stmt2->execute()) {
                $uid  = db()->insert_id;
                $_SESSION['user'] = ['id'=>$uid,'username'=>$username,'full_name'=>$fullname,'role'=>'student'];
                echo json_encode(['ok'=>true,'user'=>['name'=>$fullname,'role'=>'student']]);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'Registration failed. Try again.']);
            }
            exit;

        case 'login':
            $email = trim($_POST['email'] ?? '');
            $pass  = $_POST['password']   ?? '';
            $stmt  = db()->prepare("SELECT id,username,full_name,role,password FROM users WHERE email=?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && password_verify($pass, $user['password'])) {
                $_SESSION['user'] = $user;
                echo json_encode(['ok'=>true,'user'=>['name'=>$user['full_name'],'role'=>$user['role']]]);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'Invalid email or password']);
            }
            exit;

        case 'logout':
            session_destroy();
            echo json_encode(['ok'=>true]);
            exit;

        // ── FIX: added ORDER BY that works without c.level if column missing ──
        case 'get_categories':
            $level = $_GET['level'] ?? 'all';
            // Check if level column exists to avoid SQL error
            $hasLevel = false;
            $chk = db()->query("SHOW COLUMNS FROM categories LIKE 'level'");
            if ($chk && $chk->num_rows > 0) $hasLevel = true;

            $where  = ($level !== 'all' && $hasLevel) ? "WHERE c.level=?" : "";
            $order  = $hasLevel ? "ORDER BY c.level,c.name" : "ORDER BY c.name";
            $sql    = "SELECT c.*,
                         COUNT(DISTINCT q.id) quiz_count
                       FROM categories c
                       LEFT JOIN quizzes q ON q.category_id=c.id AND q.is_published=1
                       $where
                       GROUP BY c.id $order";
            $stmt = db()->prepare($sql);
            if ($level !== 'all' && $hasLevel) $stmt->bind_param('s', $level);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            exit;

        case 'get_quizzes':
            $cat = (int)($_GET['cat'] ?? 0);
            $stmt = db()->prepare(
                "SELECT q.*,c.name cat_name,c.icon,c.color,
                   COUNT(DISTINCT qs.id) question_count
                 FROM quizzes q
                 JOIN categories c ON c.id=q.category_id
                 LEFT JOIN questions qs ON qs.quiz_id=q.id
                 WHERE q.category_id=? AND q.is_published=1
                 GROUP BY q.id ORDER BY q.title");
            $stmt->bind_param('i', $cat);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            exit;

        case 'start_quiz':
            if (empty($_SESSION['user'])) { echo json_encode(['ok'=>false,'msg'=>'Login required']); exit; }
            $qid = (int)($_POST['quiz_id'] ?? 0);
            $uid = (int)$_SESSION['user']['id'];
            $stmt = db()->prepare("SELECT COUNT(*) n FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND status='completed'");
            $stmt->bind_param('ii', $qid, $uid);
            $stmt->execute();
            $cnt  = $stmt->get_result()->fetch_assoc()['n'];
            $stmt2 = db()->prepare("SELECT max_attempts,time_limit,title,shuffle_q,shuffle_a,pass_percentage FROM quizzes WHERE id=?");
            $stmt2->bind_param('i', $qid);
            $stmt2->execute();
            $quiz = $stmt2->get_result()->fetch_assoc();
            if (!$quiz)          { echo json_encode(['ok'=>false,'msg'=>'Quiz not found']);       exit; }
            if ($cnt >= $quiz['max_attempts']) { echo json_encode(['ok'=>false,'msg'=>'Max attempts reached']); exit; }
            // Close any in-progress attempt
            $stmtClose = db()->prepare("UPDATE quiz_attempts SET status='timed_out' WHERE quiz_id=? AND user_id=? AND status='in_progress'");
            $stmtClose->bind_param('ii', $qid, $uid);
            $stmtClose->execute();
            $stmt3 = db()->prepare("INSERT INTO quiz_attempts (quiz_id,user_id,status) VALUES (?,'$uid','in_progress')");
            $stmt3 = db()->prepare("INSERT INTO quiz_attempts (quiz_id,user_id,status) VALUES (?,?,'in_progress')");
            $stmt3->bind_param('ii', $qid, $uid);
            $stmt3->execute();
            $aid = db()->insert_id;
            $stmt4 = db()->prepare("SELECT id,question_text,question_type,points,explanation,sort_order FROM questions WHERE quiz_id=? ORDER BY sort_order");
            $stmt4->bind_param('i', $qid);
            $stmt4->execute();
            $questions = $stmt4->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($quiz['shuffle_q']) shuffle($questions);
            foreach ($questions as &$q) {
                $stmt5 = db()->prepare("SELECT id,answer_text,sort_order FROM answers WHERE question_id=? ORDER BY sort_order");
                $stmt5->bind_param('i', $q['id']);
                $stmt5->execute();
                $q['answers'] = $stmt5->get_result()->fetch_all(MYSQLI_ASSOC);
                if ($quiz['shuffle_a']) shuffle($q['answers']);
            }
            unset($q);
            echo json_encode(['ok'=>true,'attempt_id'=>$aid,'quiz'=>$quiz,'questions'=>$questions]);
            exit;

        case 'submit_quiz':
            if (empty($_SESSION['user'])) { echo json_encode(['ok'=>false,'msg'=>'Login required']); exit; }
            $aid  = (int)($_POST['attempt_id'] ?? 0);
            $resp = json_decode($_POST['responses'] ?? '{}', true);
            $time = (int)($_POST['time_taken'] ?? 0);
            if (!$aid || !is_array($resp)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid submission data']); exit;
            }
            $uid  = (int)$_SESSION['user']['id'];
            $stmt = db()->prepare("SELECT qa.*,qz.pass_percentage,qz.id quiz_id FROM quiz_attempts qa JOIN quizzes qz ON qz.id=qa.quiz_id WHERE qa.id=? AND qa.user_id=?");
            $stmt->bind_param('ii', $aid, $uid);
            $stmt->execute();
            $attempt = $stmt->get_result()->fetch_assoc();
            if (!$attempt) { echo json_encode(['ok'=>false,'msg'=>'Invalid attempt']); exit; }
            $pts_earned = 0; $pts_total = 0; $results = [];
            foreach ($resp as $qid => $ans_id) {
                $qid    = (int)$qid;
                $ans_id = (int)$ans_id;
                if (!$qid || !$ans_id) continue;
                $sq = db()->prepare("SELECT points,explanation FROM questions WHERE id=?");
                $sq->bind_param('i', $qid);
                $sq->execute();
                $q = $sq->get_result()->fetch_assoc();
                if (!$q) continue;
                $pts_total += (int)$q['points'];
                $sa = db()->prepare("SELECT is_correct FROM answers WHERE id=? AND question_id=?");
                $sa->bind_param('ii', $ans_id, $qid);
                $sa->execute();
                $a       = $sa->get_result()->fetch_assoc();
                $correct = $a && (int)$a['is_correct'] === 1;
                if ($correct) $pts_earned += (int)$q['points'];
                $si = db()->prepare("INSERT IGNORE INTO user_responses (attempt_id,question_id,answer_id,is_correct) VALUES (?,?,?,?)");
                $ic = (int)$correct;
                $si->bind_param('iiii', $aid, $qid, $ans_id, $ic);
                $si->execute();
                $sc = db()->prepare("SELECT id,answer_text FROM answers WHERE question_id=? AND is_correct=1 LIMIT 1");
                $sc->bind_param('i', $qid);
                $sc->execute();
                $correct_ans = $sc->get_result()->fetch_assoc();
                $results[$qid] = ['correct'=>$correct,'explanation'=>$q['explanation'],'correct_answer'=>$correct_ans];
            }
            $score = $pts_total > 0 ? round(($pts_earned / $pts_total) * 100, 2) : 0;
            $su = db()->prepare("UPDATE quiz_attempts SET score=?,points_earned=?,points_total=?,time_taken=?,status='completed',completed_at=NOW() WHERE id=?");
            $su->bind_param('diiii', $score, $pts_earned, $pts_total, $time, $aid);
            $su->execute();
            echo json_encode(['ok'=>true,'score'=>$score,'pts_earned'=>$pts_earned,'pts_total'=>$pts_total,
                              'passed'=>$score>=$attempt['pass_percentage'],'pass_pct'=>$attempt['pass_percentage'],
                              'results'=>$results]);
            exit;

        case 'leaderboard':
            $qid = (int)($_GET['quiz_id'] ?? 0);
            $stmt = db()->prepare(
                "SELECT u.full_name,u.username,MAX(qa.score) best_score,MIN(qa.time_taken) best_time
                 FROM quiz_attempts qa JOIN users u ON u.id=qa.user_id
                 WHERE qa.quiz_id=? AND qa.status='completed'
                 GROUP BY u.id ORDER BY best_score DESC,best_time ASC LIMIT 10");
            $stmt->bind_param('i', $qid);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            exit;

        case 'my_history':
            if (empty($_SESSION['user'])) { echo json_encode([]); exit; }
            $uid  = (int)$_SESSION['user']['id'];
            $stmt = db()->prepare(
                "SELECT qa.*,qz.title,c.icon,c.color
                 FROM quiz_attempts qa
                 JOIN quizzes qz ON qz.id=qa.quiz_id
                 JOIN categories c ON c.id=qz.category_id
                 WHERE qa.user_id=? AND qa.status='completed'
                 ORDER BY qa.completed_at DESC LIMIT 20");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            exit;
    }
    exit;
}

$loggedIn    = !empty($_SESSION['user']);
$userName    = $loggedIn ? htmlspecialchars($_SESSION['user']['full_name']) : '';
$userInitial = $loggedIn ? strtoupper(mb_substr($_SESSION['user']['full_name'], 0, 1)) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>QuizMaster Pro</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@300;400;500;700;800&display=swap" rel="stylesheet"/>
<style>
/* ══ TOKENS ══════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#050710;--surface:#0c0f1e;--card:#111428;--card2:#161b33;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --lime:#c8ff57;--lime-dim:rgba(200,255,87,.12);--lime-glow:rgba(200,255,87,.25);
  --cyan:#38f5d4;--pink:#ff4d8d;--violet:#9b5dff;
  --text:#e8ecf8;--muted:#5a6080;--muted2:#8892b0;
  --r:18px;--r-sm:10px;
  --font-head:'Clash Display',sans-serif;--font-body:'Cabinet Grotesk',sans-serif;
  --ease:cubic-bezier(.25,.46,.45,.94);--spring:cubic-bezier(.34,1.56,.64,1);
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font-body);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;
  background-image:linear-gradient(rgba(200,255,87,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(200,255,87,.03) 1px,transparent 1px);
  background-size:60px 60px;pointer-events:none}
body::after{content:'';position:fixed;inset:0;z-index:0;
  background:radial-gradient(ellipse 700px 500px at 20% 10%,rgba(155,93,255,.09) 0%,transparent 60%),
             radial-gradient(ellipse 600px 400px at 80% 80%,rgba(56,245,212,.07) 0%,transparent 60%),
             radial-gradient(ellipse 500px 400px at 50% 40%,rgba(200,255,87,.05) 0%,transparent 60%);pointer-events:none}
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:var(--surface)}
::-webkit-scrollbar-thumb{background:var(--lime);border-radius:3px}

/* ══ NAV ════════════════════════════════════════════════════ */
nav{position:sticky;top:0;z-index:200;display:flex;align-items:center;justify-content:space-between;
  padding:1rem 2.5rem;background:rgba(5,7,16,.8);backdrop-filter:blur(24px) saturate(180%);
  border-bottom:1px solid var(--border)}
.logo{font-family:var(--font-head);font-size:1.5rem;font-weight:700;letter-spacing:-.02em;
  display:flex;align-items:center;gap:.4rem}
.logo-quiz{color:var(--text)}.logo-master{color:var(--lime)}
.logo-dot{width:8px;height:8px;border-radius:50%;background:var(--lime);
  box-shadow:0 0 12px var(--lime);animation:pulse-dot 2s ease-in-out infinite}
@keyframes pulse-dot{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:.6}}
.nav-right{display:flex;align-items:center;gap:.8rem}
.nav-btn{padding:.45rem 1.2rem;border-radius:50px;font-family:var(--font-body);font-size:.84rem;
  font-weight:500;cursor:pointer;border:none;transition:all .22s var(--ease);letter-spacing:.02em}
.nav-btn.ghost{background:transparent;border:1px solid var(--border2);color:var(--muted2)}
.nav-btn.ghost:hover{border-color:var(--lime);color:var(--lime)}
.nav-btn.solid{background:var(--lime);color:#050710;font-weight:700}
.nav-btn.solid:hover{transform:translateY(-2px);box-shadow:0 8px 24px var(--lime-glow)}
.user-badge{display:flex;align-items:center;gap:.7rem}
.user-badge span{font-size:.84rem;color:var(--muted2)}.user-badge strong{color:var(--text)}
.avatar{width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,var(--lime),var(--cyan));
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.8rem;color:#050710;border:2px solid rgba(200,255,87,.3)}

/* ══ HERO ════════════════════════════════════════════════════ */
#hero{position:relative;z-index:1;padding:6rem 2rem 5rem;text-align:center;overflow:hidden}
.hero-eyebrow{display:inline-flex;align-items:center;gap:.6rem;
  background:rgba(200,255,87,.08);border:1px solid rgba(200,255,87,.2);
  border-radius:50px;padding:.35rem 1.1rem;font-size:.75rem;font-weight:500;
  color:var(--lime);letter-spacing:.1em;text-transform:uppercase;margin-bottom:2rem;
  animation:fadeDown .6s var(--ease) both}
.hero-eyebrow::before{content:'';width:6px;height:6px;border-radius:50%;
  background:var(--lime);box-shadow:0 0 8px var(--lime)}
.hero-title{font-family:var(--font-head);font-size:clamp(3rem,8vw,6.5rem);font-weight:700;
  line-height:.95;letter-spacing:-.04em;margin-bottom:1.5rem;animation:fadeDown .7s .1s var(--ease) both}
.hero-title .word-1{display:block;color:var(--text)}
.hero-title .word-2{display:block;
  background:linear-gradient(100deg,var(--lime) 0%,var(--cyan) 50%,var(--violet) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{max-width:480px;margin:0 auto 2.5rem;font-size:1.05rem;color:var(--muted2);
  line-height:1.75;font-weight:300;animation:fadeDown .7s .2s var(--ease) both}
.hero-cta{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;
  animation:fadeDown .7s .3s var(--ease) both}
.btn-cta{padding:.9rem 2.2rem;border-radius:50px;font-family:var(--font-body);
  font-size:1rem;font-weight:700;cursor:pointer;border:none;transition:all .25s var(--ease)}
.btn-cta.primary{background:var(--lime);color:#050710;box-shadow:0 0 40px rgba(200,255,87,.2)}
.btn-cta.primary:hover{transform:translateY(-3px);box-shadow:0 16px 48px var(--lime-glow)}
.btn-cta.secondary{background:var(--card2);border:1px solid var(--border2);color:var(--text)}
.btn-cta.secondary:hover{border-color:var(--cyan);color:var(--cyan)}
.stats-strip{display:flex;gap:0;justify-content:center;flex-wrap:wrap;
  margin-top:4rem;padding:2rem;background:var(--card);border:1px solid var(--border);
  border-radius:var(--r);max-width:700px;margin-inline:auto;animation:fadeUp .7s .45s var(--ease) both}
.stat-item{flex:1;min-width:130px;text-align:center;padding:1rem;position:relative}
.stat-item+.stat-item::before{content:'';position:absolute;left:0;top:20%;height:60%;
  width:1px;background:var(--border)}
.stat-num{font-family:var(--font-head);font-size:2.4rem;font-weight:700;color:var(--lime);line-height:1}
.stat-lbl{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-top:.4rem}

/* ══ FILTER BAR ═════════════════════════════════════════════ */
.filter-bar{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;
  padding:2rem 2rem 1rem;position:relative;z-index:1}
.filter-pill{padding:.5rem 1.3rem;border-radius:50px;font-size:.82rem;font-weight:500;
  background:var(--card);border:1px solid var(--border2);color:var(--muted2);
  cursor:pointer;transition:all .22s}
.filter-pill:hover{border-color:var(--lime);color:var(--lime)}
.filter-pill.active{background:var(--lime);border-color:var(--lime);color:#050710;font-weight:700}
.section-header{padding:1rem 2.5rem 1.5rem;display:flex;align-items:flex-end;
  justify-content:space-between;position:relative;z-index:1}
.section-header h2{font-family:var(--font-head);font-size:1.7rem;font-weight:700;letter-spacing:-.02em}
.section-header h2 em{font-style:normal;color:var(--lime)}
.section-count{font-size:.78rem;color:var(--muted)}

/* ══ CATEGORY GRID ══════════════════════════════════════════ */
#category-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));
  gap:1rem;padding:0 2.5rem 4rem;position:relative;z-index:1}
.cat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);
  padding:1.6rem;cursor:pointer;position:relative;overflow:hidden;
  transition:transform .28s var(--spring),border-color .22s,box-shadow .28s;
  animation:fadeUp .5s var(--ease) both}
.cat-card::before{content:'';position:absolute;top:-60px;right:-60px;width:160px;height:160px;
  border-radius:50%;background:var(--c,var(--lime));opacity:.06;
  transition:opacity .3s,transform .3s;pointer-events:none}
.cat-card:hover{transform:translateY(-6px);border-color:var(--c,var(--lime));
  box-shadow:0 24px 64px rgba(0,0,0,.5)}
.cat-card:hover::before{opacity:.12;transform:scale(1.2)}
.cat-icon-wrap{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.05);
  border:1px solid var(--border);display:flex;align-items:center;justify-content:center;
  font-size:1.6rem;margin-bottom:1rem;transition:background .2s}
.cat-card:hover .cat-icon-wrap{background:rgba(255,255,255,.09)}
.cat-name{font-family:var(--font-head);font-size:1.05rem;font-weight:600;
  margin-bottom:.4rem;letter-spacing:-.01em}
.cat-desc{font-size:.78rem;color:var(--muted2);line-height:1.55;margin-bottom:1rem}
.cat-footer{display:flex;align-items:center;justify-content:space-between}
.cat-count{font-size:.72rem;color:var(--muted);background:var(--surface);
  border:1px solid var(--border);padding:.2rem .7rem;border-radius:50px}
.cat-level{font-size:.65rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;padding:.22rem .7rem;border-radius:50px}
.cat-level.easy{background:rgba(56,245,212,.1);color:var(--cyan);border:1px solid rgba(56,245,212,.2)}
.cat-level.professional{background:rgba(200,255,87,.1);color:var(--lime);border:1px solid rgba(200,255,87,.2)}
.skeleton{background:linear-gradient(90deg,var(--card) 25%,var(--card2) 50%,var(--card) 75%);
  background-size:200% 100%;animation:skeleton-wave 1.4s ease-in-out infinite;border-radius:var(--r)}
@keyframes skeleton-wave{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ══ MODAL BASE ═════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.7);
  backdrop-filter:blur(10px) saturate(120%);display:flex;align-items:center;
  justify-content:center;padding:1rem;opacity:0;pointer-events:none;
  transition:opacity .28s var(--ease)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--card);border:1px solid var(--border2);border-radius:22px;
  width:100%;max-width:500px;max-height:90vh;overflow-y:auto;
  transform:translateY(24px) scale(.96);transition:transform .35s var(--spring);
  box-shadow:0 32px 80px rgba(0,0,0,.6)}
.modal-overlay.open .modal{transform:translateY(0) scale(1)}
.modal-head{padding:1.6rem 1.8rem 1rem;display:flex;align-items:center;
  justify-content:space-between;border-bottom:1px solid var(--border)}
.modal-head h2{font-family:var(--font-head);font-size:1.25rem;font-weight:700;letter-spacing:-.02em}
.modal-close{width:34px;height:34px;border-radius:50%;background:var(--surface);
  border:1px solid var(--border);cursor:pointer;color:var(--muted2);font-size:1rem;
  display:flex;align-items:center;justify-content:center;transition:all .2s}
.modal-close:hover{background:var(--pink);color:#fff;border-color:var(--pink)}
.modal-body{padding:1.6rem 1.8rem}

/* ══ AUTH ═══════════════════════════════════════════════════ */
.auth-tabs{display:flex;gap:.3rem;background:var(--surface);border:1px solid var(--border);
  border-radius:50px;padding:.3rem;margin-bottom:1.6rem}
.auth-tab{flex:1;padding:.5rem;border-radius:50px;font-family:var(--font-body);
  font-size:.85rem;font-weight:500;cursor:pointer;border:none;background:transparent;
  color:var(--muted2);transition:all .22s}
.auth-tab.active{background:var(--lime);color:#050710;font-weight:700}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.75rem;font-weight:500;color:var(--muted2);
  margin-bottom:.45rem;letter-spacing:.04em;text-transform:uppercase}
.form-input{width:100%;padding:.75rem 1rem;border-radius:var(--r-sm);background:var(--surface);
  border:1px solid var(--border2);color:var(--text);font-family:var(--font-body);
  font-size:.92rem;transition:border-color .2s,box-shadow .2s;outline:none}
.form-input:focus{border-color:var(--lime);box-shadow:0 0 0 3px rgba(200,255,87,.1)}
.form-input::placeholder{color:var(--muted)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.form-err{font-size:.78rem;color:var(--pink);min-height:1.1rem;margin-top:.4rem;
  display:flex;align-items:center;gap:.4rem}
.form-err:not(:empty)::before{content:'⚠'}
.btn-submit{width:100%;padding:.85rem;border-radius:var(--r-sm);background:var(--lime);
  border:none;color:#050710;font-family:var(--font-head);font-size:1rem;font-weight:700;
  cursor:pointer;transition:all .22s;margin-top:.5rem;letter-spacing:.01em}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 10px 32px var(--lime-glow)}
.btn-submit:active{transform:translateY(0)}
.btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.demo-creds{background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r-sm);padding:.9rem;margin-top:1rem;
  font-size:.78rem;color:var(--muted2);line-height:1.8}
.demo-creds code{color:var(--lime);background:rgba(200,255,87,.08);padding:.1rem .35rem;border-radius:4px}

/* ══ QUIZ LIST MODAL ════════════════════════════════════════ */
.quiz-list-item{background:var(--surface);border:1px solid var(--border);
  border-radius:14px;padding:1.1rem 1.3rem;margin-bottom:.8rem;
  cursor:pointer;transition:all .22s;position:relative}
.quiz-list-item:hover{border-color:var(--lime);transform:translateX(4px)}
.quiz-list-item:hover::after{content:'→';position:absolute;right:1.2rem;top:50%;
  transform:translateY(-50%);color:var(--lime);font-size:1.1rem}
.qli-title{font-weight:700;font-size:.95rem;margin-bottom:.4rem}
.qli-desc{font-size:.78rem;color:var(--muted2);margin-bottom:.7rem}
.qli-badges{display:flex;gap:.5rem;flex-wrap:wrap}
.qli-badge{font-size:.7rem;padding:.2rem .6rem;border-radius:50px;
  background:var(--card);border:1px solid var(--border);color:var(--muted2)}
.diff-easy  {color:var(--cyan);border-color:rgba(56,245,212,.3)!important;background:rgba(56,245,212,.06)!important}
.diff-medium{color:var(--lime);border-color:rgba(200,255,87,.3)!important;background:rgba(200,255,87,.06)!important}
.diff-hard  {color:var(--pink);border-color:rgba(255,77,141,.3)!important;background:rgba(255,77,141,.06)!important}
.lb-btn{margin-top:.7rem;background:transparent;border:1px solid var(--border);
  color:var(--muted);border-radius:50px;padding:.25rem .85rem;font-size:.72rem;
  cursor:pointer;transition:all .2s;font-family:var(--font-body)}
.lb-btn:hover{border-color:var(--lime);color:var(--lime)}

/* ══ QUIZ SCREEN ════════════════════════════════════════════ */
#quiz-screen{display:none;position:fixed;inset:0;z-index:300;background:var(--bg);overflow-y:auto}
#quiz-screen.active{display:block}
.quiz-topbar{display:flex;align-items:center;justify-content:space-between;
  padding:1rem 2rem;border-bottom:1px solid var(--border);
  background:rgba(5,7,16,.9);backdrop-filter:blur(16px);
  position:sticky;top:0;z-index:10;gap:1rem}
.quiz-title-sm{font-family:var(--font-head);font-size:1rem;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.quiz-timer{display:flex;align-items:center;gap:.5rem;background:var(--card);
  border:1px solid var(--border2);border-radius:50px;padding:.4rem 1.1rem;
  font-family:var(--font-head);font-size:1rem;font-weight:700;color:var(--lime);
  white-space:nowrap;transition:all .3s;min-width:100px;justify-content:center}
.quiz-timer.warn{color:var(--pink);border-color:var(--pink);animation:blink .6s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.quiz-progress-bar{height:2px;background:var(--surface)}
.quiz-progress-fill{height:100%;background:linear-gradient(90deg,var(--lime),var(--cyan));
  transition:width .4s var(--ease)}
.quiz-content{max-width:680px;margin:0 auto;padding:2.5rem 2rem}
.q-counter{font-size:.72rem;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;
  margin-bottom:.9rem;display:flex;align-items:center;gap:.5rem}
.q-counter::before{content:'';width:20px;height:2px;background:var(--lime);border-radius:1px}
.q-text{font-family:var(--font-head);font-size:1.5rem;font-weight:600;line-height:1.35;
  margin-bottom:2rem;letter-spacing:-.02em}
.answers-grid{display:grid;gap:.65rem}
.ans-option{padding:1rem 1.3rem;border-radius:14px;background:var(--card);
  border:1.5px solid var(--border);cursor:pointer;transition:all .22s var(--ease);
  display:flex;align-items:center;gap:1rem;font-size:.95rem}
.ans-option:hover:not(.disabled){border-color:var(--lime);background:rgba(200,255,87,.04);transform:translateX(6px)}
.ans-option.selected{border-color:var(--lime);background:rgba(200,255,87,.07)}
.ans-option.correct{border-color:var(--cyan);background:rgba(56,245,212,.07);color:var(--cyan)}
.ans-option.wrong  {border-color:var(--pink);background:rgba(255,77,141,.07);color:var(--pink)}
.ans-option.disabled{cursor:default}
.ans-letter{width:32px;height:32px;min-width:32px;border-radius:50%;
  background:var(--surface);border:1.5px solid var(--border2);
  display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:700;color:var(--muted2);transition:all .22s}
.ans-option.selected .ans-letter{background:var(--lime);border-color:var(--lime);color:#050710}
.ans-option.correct  .ans-letter{background:var(--cyan);border-color:var(--cyan);color:#050710}
.ans-option.wrong    .ans-letter{background:var(--pink);border-color:var(--pink);color:#fff}
.explanation-box{margin-top:1.1rem;padding:1rem 1.2rem;
  background:rgba(56,245,212,.05);border:1px solid rgba(56,245,212,.18);
  border-radius:12px;font-size:.85rem;color:var(--cyan);line-height:1.65;
  animation:fadeUp .35s var(--ease)}
.explanation-box strong{display:block;margin-bottom:.4rem;font-size:.68rem;
  text-transform:uppercase;letter-spacing:.1em;opacity:.7}
.q-nav{display:flex;gap:1rem;margin-top:2.5rem;justify-content:space-between;align-items:center}
.btn-nav{padding:.65rem 1.6rem;border-radius:50px;font-family:var(--font-body);
  font-size:.88rem;font-weight:500;cursor:pointer;transition:all .2s;border:1px solid var(--border2)}
.btn-nav.prev{background:transparent;color:var(--muted2)}
.btn-nav.prev:hover:not([disabled]){color:var(--text);border-color:var(--text)}
.btn-nav.next{background:var(--lime);border-color:var(--lime);color:#050710;font-weight:700}
.btn-nav.next:hover{box-shadow:0 8px 24px var(--lime-glow)}
.btn-nav.submit-q{background:var(--cyan);border-color:var(--cyan);color:#050710;font-weight:700}
.btn-nav.submit-q:hover{box-shadow:0 8px 24px rgba(56,245,212,.3)}
.q-dots{display:flex;gap:.4rem;flex-wrap:wrap;justify-content:center}
.q-dot{width:9px;height:9px;border-radius:50%;background:var(--surface);
  border:1px solid var(--border2);cursor:pointer;transition:all .2s}
.q-dot.answered{background:var(--lime);border-color:var(--lime)}
.q-dot.current {border-color:var(--cyan);transform:scale(1.4)}

/* ══ CONFIRM DIALOG (replaces window.confirm) ═══════════════ */
#confirm-overlay{position:fixed;inset:0;z-index:600;background:rgba(0,0,0,.75);
  backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;
  padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
#confirm-overlay.open{opacity:1;pointer-events:all}
#confirm-box{background:var(--card);border:1px solid var(--border2);border-radius:20px;
  padding:2rem;max-width:380px;width:100%;text-align:center;
  transform:scale(.94);transition:transform .3s var(--spring)}
#confirm-overlay.open #confirm-box{transform:scale(1)}
#confirm-box h3{font-family:var(--font-head);font-size:1.2rem;margin-bottom:.6rem}
#confirm-box p{color:var(--muted2);font-size:.88rem;margin-bottom:1.5rem;line-height:1.6}
.confirm-btns{display:flex;gap:.8rem;justify-content:center}
.confirm-btns .btn-nav{padding:.6rem 1.4rem}

/* ══ RESULT MODAL ═══════════════════════════════════════════ */
.score-circle-wrap{text-align:center;margin-bottom:1.5rem}
.score-ring{width:150px;height:150px;margin:0 auto;position:relative}
.score-ring svg{transform:rotate(-90deg)}
.score-ring-bg{fill:none;stroke:var(--surface);stroke-width:10}
.score-ring-fill{fill:none;stroke:var(--lime);stroke-width:10;stroke-linecap:round;
  stroke-dasharray:408;stroke-dashoffset:408;transition:stroke-dashoffset 1.2s var(--ease)}
.score-ring-fill.cyan{stroke:var(--cyan)}
.score-ring-num{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-size:2.2rem;font-weight:700}
.verdict-strip{text-align:center;margin-bottom:1.5rem}
.verdict-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1.5rem;
  border-radius:50px;font-weight:700;font-size:.9rem;margin-bottom:.4rem}
.verdict-badge.pass{background:rgba(56,245,212,.1);color:var(--cyan);border:1px solid rgba(56,245,212,.25)}
.verdict-badge.fail{background:rgba(255,77,141,.1);color:var(--pink);border:1px solid rgba(255,77,141,.25)}
.verdict-sub{font-size:.8rem;color:var(--muted)}
.result-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.7rem;margin-bottom:1.5rem}
.rs-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:.9rem;text-align:center}
.rs-val{font-family:var(--font-head);font-size:1.5rem;font-weight:700}
.rs-lbl{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}
.result-actions{display:flex;gap:.7rem;margin-bottom:1.5rem}
.result-actions .btn-nav{flex:1;text-align:center}
.review-section h4{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.9rem}
.review-item{border-radius:12px;padding:.9rem 1.1rem;margin-bottom:.65rem;
  border-left:3px solid transparent;background:var(--surface)}
.review-item.correct-r{border-color:var(--cyan)}
.review-item.wrong-r  {border-color:var(--pink)}
.review-q{font-size:.86rem;font-weight:600;margin-bottom:.4rem}
.review-ans{font-size:.78rem;color:var(--muted2);margin-top:.2rem}
.review-ans span.wrong-a{color:var(--pink);font-weight:500}
.review-ans span.right-a{color:var(--cyan);font-weight:500}
.review-expl{font-size:.75rem;color:var(--muted);margin-top:.35rem;line-height:1.5}

/* ══ LEADERBOARD ════════════════════════════════════════════ */
.lb-table{width:100%;border-collapse:collapse}
.lb-table th{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;
  padding:.5rem .8rem;border-bottom:1px solid var(--border);text-align:left}
.lb-table td{padding:.7rem .8rem;font-size:.88rem;border-bottom:1px solid var(--border)}
.lb-table tr:last-child td{border-bottom:none}
.lb-rank{font-family:var(--font-head);font-weight:800;font-size:1.1rem}
.rank-1{color:var(--lime)}.rank-2{color:var(--muted2)}.rank-3{color:#e07b30}

/* ══ HISTORY ════════════════════════════════════════════════ */
.hist-card{background:var(--card2);border:1px solid var(--border);border-radius:14px;
  padding:1rem 1.2rem;margin-bottom:.7rem;display:flex;align-items:center;gap:1rem}
.hist-icon{font-size:1.8rem}
.hist-info{flex:1}
.hist-title{font-weight:700;font-size:.9rem}
.hist-meta{font-size:.74rem;color:var(--muted);margin-top:.2rem}
.hist-score{font-family:var(--font-head);font-size:1.3rem;font-weight:800}

/* ══ TOAST ══════════════════════════════════════════════════ */
#toast{position:fixed;bottom:1.8rem;right:1.8rem;z-index:9999;
  background:var(--card2);border:1px solid var(--border2);border-radius:14px;
  padding:.9rem 1.4rem;font-size:.86rem;max-width:320px;
  transform:translateY(80px) scale(.9);opacity:0;transition:all .35s var(--spring);
  box-shadow:0 24px 60px rgba(0,0,0,.5)}
#toast.show{transform:translateY(0) scale(1);opacity:1}
#toast.success{border-left:3px solid var(--lime)}
#toast.error  {border-left:3px solid var(--pink)}

/* ══ KEYFRAMES ══════════════════════════════════════════════ */
@keyframes fadeDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeUp  {from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)}}

/* ══ RESPONSIVE ═════════════════════════════════════════════ */
@media(max-width:640px){
  nav{padding:.9rem 1.2rem}
  #hero{padding:4rem 1.2rem 3rem}
  .hero-title{font-size:2.8rem}
  #category-grid{grid-template-columns:1fr 1fr;gap:.7rem;padding:0 1.2rem 3rem}
  .cat-card{padding:1rem}
  .section-header{padding:1rem 1.2rem 1rem}
  .filter-bar{padding:1.5rem 1.2rem .8rem}
  .result-stats{grid-template-columns:1fr 1fr}
  .quiz-content{padding:1.5rem 1.2rem}
  .q-text{font-size:1.2rem}
  .form-row{grid-template-columns:1fr}
  .stats-strip{margin:2.5rem 1.2rem 0}
}
</style>
</head>
<body>

<!-- ══ NAV ══════════════════════════════════════════════════ -->
<nav>
  <div class="logo">
    <span class="logo-quiz">Quiz</span><span class="logo-master">Master</span>
    <div class="logo-dot"></div>
  </div>
  <div class="nav-right">
    <?php if ($loggedIn): ?>
    <div class="user-badge">
      <div class="avatar"><?= $userInitial ?></div>
      <span>Hey, <strong><?= $userName ?></strong></span>
    </div>
    <button class="nav-btn ghost" onclick="showHistory()">My Scores</button>
    <button class="nav-btn ghost" onclick="doLogout()">Logout</button>
    <?php else: ?>
    <button class="nav-btn ghost" onclick="openAuth('login')">Login</button>
    <button class="nav-btn solid" onclick="openAuth('register')">Sign Up Free</button>
    <?php endif; ?>
  </div>
</nav>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<section id="hero">
  <div class="hero-eyebrow">200+ Questions · 20 Categories · Real-Time Leaderboards</div>
  <h1 class="hero-title">
    <span class="word-1">Prove What You</span>
    <span class="word-2">Actually Know</span>
  </h1>
  <p class="hero-sub">From quantum mechanics to pop culture — sharp questions, instant feedback, and global rankings to challenge yourself.</p>
  <div class="hero-cta">
    <button class="btn-cta primary" onclick="scrollToQuizzes()">Browse Quizzes →</button>
    <?php if (!$loggedIn): ?>
    <button class="btn-cta secondary" onclick="openAuth('register')">Create Account</button>
    <?php else: ?>
    <button class="btn-cta secondary" onclick="showHistory()">View My Scores</button>
    <?php endif; ?>
  </div>
  <div class="stats-strip">
    <div class="stat-item"><div class="stat-num">20</div><div class="stat-lbl">Categories</div></div>
    <div class="stat-item"><div class="stat-num">200+</div><div class="stat-lbl">Questions</div></div>
    <div class="stat-item"><div class="stat-num">10</div><div class="stat-lbl">Pro Topics</div></div>
    <div class="stat-item"><div class="stat-num">∞</div><div class="stat-lbl">Learning</div></div>
  </div>
</section>

<!-- ══ FILTER + GRID ═════════════════════════════════════════ -->
<div id="quizzes-section">
  <div class="filter-bar">
    <button class="filter-pill active" data-level="all">All</button>
    <button class="filter-pill" data-level="easy">🟢 Easy</button>
    <button class="filter-pill" data-level="professional">🟡 Professional</button>
  </div>
  <div class="section-header">
    <h2>Browse <em>Categories</em></h2>
    <span class="section-count" id="cat-count"></span>
  </div>
  <div id="category-grid">
    <?php for($i=0;$i<6;$i++): ?>
    <div class="skeleton" style="height:180px;animation-delay:<?= $i*.08 ?>s"></div>
    <?php endfor; ?>
  </div>
</div>

<!-- ══ AUTH MODAL ════════════════════════════════════════════ -->
<div class="modal-overlay" id="auth-modal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="auth-modal-title">Welcome Back</h2>
      <button class="modal-close" onclick="closeModal('auth-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="auth-tabs">
        <button class="auth-tab active" id="tab-login"    onclick="switchTab('login')">Login</button>
        <button class="auth-tab"        id="tab-register" onclick="switchTab('register')">Create Account</button>
      </div>
      <!-- LOGIN -->
      <div id="form-login">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" id="login-email" class="form-input" placeholder="you@example.com"/>
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="login-pass" class="form-input" placeholder="••••••••"
                 onkeydown="if(event.key==='Enter')doLogin()"/>
        </div>
        <div class="form-err" id="login-err"></div>
        <button class="btn-submit" id="btn-login" onclick="doLogin()">Sign In →</button>
        <div class="demo-creds">
          <strong style="color:var(--muted2)">Demo accounts:</strong><br>
          Email: <code>admin@quizmaster.com</code><br>
          Email: <code>student1@example.com</code><br>
          Password: <code>password</code>
        </div>
      </div>
      <!-- REGISTER -->
      <div id="form-register" style="display:none">
        <div class="form-row">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" id="reg-name" class="form-input" placeholder="Jane Doe"/>
          </div>
          <div class="form-group">
            <label>Username</label>
            <input type="text" id="reg-username" class="form-input" placeholder="janedoe"/>
          </div>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" id="reg-email" class="form-input" placeholder="you@example.com"/>
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" id="reg-pass" class="form-input" placeholder="Min 6 characters"/>
        </div>
        <div class="form-err" id="reg-err"></div>
        <button class="btn-submit" id="btn-register" onclick="doRegister()">Create Account →</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ QUIZ LIST MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="quiz-list-modal">
  <div class="modal">
    <div class="modal-head">
      <h2 id="qlist-title">Select a Quiz</h2>
      <button class="modal-close" onclick="closeModal('quiz-list-modal')">✕</button>
    </div>
    <div class="modal-body" id="quiz-list-body"></div>
  </div>
</div>

<!-- ══ RESULT MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="result-modal">
  <div class="modal" style="max-width:600px">
    <div class="modal-head">
      <h2>Quiz Complete 🎯</h2>
      <button class="modal-close" onclick="closeModal('result-modal');exitQuiz()">✕</button>
    </div>
    <div class="modal-body" id="result-body"></div>
  </div>
</div>

<!-- ══ LEADERBOARD MODAL ═════════════════════════════════════ -->
<div class="modal-overlay" id="lb-modal">
  <div class="modal">
    <div class="modal-head">
      <h2>🏆 Leaderboard</h2>
      <button class="modal-close" onclick="closeModal('lb-modal')">✕</button>
    </div>
    <div class="modal-body" id="lb-body"></div>
  </div>
</div>

<!-- ══ HISTORY MODAL ═════════════════════════════════════════ -->
<div class="modal-overlay" id="hist-modal">
  <div class="modal">
    <div class="modal-head">
      <h2>📊 My History</h2>
      <button class="modal-close" onclick="closeModal('hist-modal')">✕</button>
    </div>
    <div class="modal-body" id="hist-body"></div>
  </div>
</div>

<!-- ══ QUIZ SCREEN ════════════════════════════════════════════ -->
<div id="quiz-screen">
  <div class="quiz-topbar">
    <div class="quiz-title-sm" id="quiz-screen-title">Quiz</div>
    <div class="q-dots" id="q-dots"></div>
    <div class="quiz-timer" id="quiz-timer">⏱ <span id="timer-val">--:--</span></div>
  </div>
  <div class="quiz-progress-bar">
    <div class="quiz-progress-fill" id="quiz-progress" style="width:0%"></div>
  </div>
  <div class="quiz-content" id="quiz-content"></div>
</div>

<!-- ══ CUSTOM CONFIRM DIALOG (replaces window.confirm) ═══════ -->
<div id="confirm-overlay">
  <div id="confirm-box">
    <h3 id="confirm-title">Submit Quiz?</h3>
    <p id="confirm-msg">Are you sure you want to submit?</p>
    <div class="confirm-btns">
      <button class="btn-nav prev" onclick="confirmResolve(false)">Keep Reviewing</button>
      <button class="btn-nav submit-q" onclick="confirmResolve(true)">Submit Now ✓</button>
    </div>
  </div>
</div>

<!-- ══ TOAST ═════════════════════════════════════════════════ -->
<div id="toast"></div>

<script>
// ── STATE ────────────────────────────────────────────────────
const state = {
  loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
  quiz: null, questions: [], attemptId: null,
  currentQ: 0, responses: {}, reviewData: null,
  timerInterval: null, timeLeft: 0, timeTaken: 0,
  isSubmitting: false,
};

// ── API ──────────────────────────────────────────────────────
async function api(params, method = 'GET') {
  const isPost = method === 'POST';
  const url    = isPost ? '?api=1' : '?' + new URLSearchParams(params);
  const opts   = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
  if (isPost) {
    opts.method = 'POST';
    const fd = new FormData();
    for (const k in params) fd.append(k, String(params[k]));
    opts.body = fd;
  }
  try {
    const r = await fetch(url, opts);
    return await r.json();
  } catch (e) {
    return { ok: false, msg: 'Network error. Please try again.' };
  }
}

// ── MODALS ───────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── CUSTOM CONFIRM (FIX: replaces window.confirm) ────────────
let confirmResolve = null;
function showConfirm(title, msg) {
  return new Promise(resolve => {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-msg').textContent   = msg;
    document.getElementById('confirm-overlay').classList.add('open');
    confirmResolve = (val) => {
      document.getElementById('confirm-overlay').classList.remove('open');
      confirmResolve = null;
      resolve(val);
    };
  });
}

// ── TOAST ────────────────────────────────────────────────────
let _toastTimer;
function toast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = `show ${type}`;
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => { t.className = ''; }, 3200);
}

// ── AUTH ─────────────────────────────────────────────────────
function switchTab(tab) {
  const isLogin = tab === 'login';
  document.getElementById('tab-login').classList.toggle('active', isLogin);
  document.getElementById('tab-register').classList.toggle('active', !isLogin);
  document.getElementById('form-login').style.display    = isLogin ? '' : 'none';
  document.getElementById('form-register').style.display = isLogin ? 'none' : '';
  document.getElementById('auth-modal-title').textContent = isLogin ? 'Welcome Back' : 'Create Account';
  document.getElementById('login-err').textContent = '';
  document.getElementById('reg-err').textContent   = '';
}

function openAuth(tab = 'login') {
  switchTab(tab);
  openModal('auth-modal');
}

async function doLogin() {
  const email = document.getElementById('login-email').value.trim();
  const pass  = document.getElementById('login-pass').value;
  const err   = document.getElementById('login-err');
  const btn   = document.getElementById('btn-login');
  if (!email || !pass) { err.textContent = 'Please fill in all fields.'; return; }
  btn.disabled = true; btn.textContent = 'Signing in…'; err.textContent = '';
  const res = await api({ action: 'login', email, password: pass }, 'POST');
  btn.disabled = false; btn.textContent = 'Sign In →';
  if (res.ok) {
    toast('Welcome back, ' + res.user.name + '! 👋');
    closeModal('auth-modal');
    setTimeout(() => location.reload(), 700);
  } else { err.textContent = res.msg; }
}

async function doRegister() {
  const full_name = document.getElementById('reg-name').value.trim();
  const username  = document.getElementById('reg-username').value.trim();
  const email     = document.getElementById('reg-email').value.trim();
  const password  = document.getElementById('reg-pass').value;
  const err       = document.getElementById('reg-err');
  const btn       = document.getElementById('btn-register');
  if (!full_name || !username || !email || !password) { err.textContent = 'All fields are required.'; return; }
  btn.disabled = true; btn.textContent = 'Creating…'; err.textContent = '';
  const res = await api({ action: 'register', full_name, username, email, password }, 'POST');
  btn.disabled = false; btn.textContent = 'Create Account →';
  if (res.ok) {
    toast('Account created! Welcome ' + res.user.name + ' 🎉');
    closeModal('auth-modal');
    setTimeout(() => location.reload(), 700);
  } else { err.textContent = res.msg; }
}

async function doLogout() {
  await api({ action: 'logout' }, 'POST');
  location.reload();
}

// ── CATEGORIES ───────────────────────────────────────────────
async function loadCategories(level = 'all') {
  const grid = document.getElementById('category-grid');
  grid.innerHTML = Array(6).fill(0).map((_, i) =>
    `<div class="skeleton" style="height:180px;animation-delay:${i*.08}s"></div>`
  ).join('');
  const cats = await api({ action: 'get_categories', level });
  document.getElementById('cat-count').textContent = cats.length + ' categories';
  grid.innerHTML = '';
  if (!cats.length) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:3rem;color:var(--muted)">
      No categories found. Please import the database via phpMyAdmin.</div>`;
    return;
  }
  cats.forEach((c, i) => {
    const card = document.createElement('div');
    card.className = 'cat-card';
    card.style.cssText = `--c:${c.color};animation-delay:${i*.05}s`;
    const lvl = c.level || 'easy';
    card.innerHTML = `
      <div class="cat-icon-wrap">${c.icon || '📚'}</div>
      <div class="cat-name">${escHtml(c.name)}</div>
      <div class="cat-desc">${escHtml(c.description || '')}</div>
      <div class="cat-footer">
        <span class="cat-count">${c.quiz_count} quiz${c.quiz_count != 1 ? 'zes' : ''}</span>
        <span class="cat-level ${lvl}">${lvl}</span>
      </div>`;
    card.onclick = () => loadQuizList(c.id, c.name, c.icon, c.color);
    grid.appendChild(card);
  });
}

// Filter pills
document.querySelectorAll('.filter-pill').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadCategories(btn.dataset.level);
  });
});

// ── QUIZ LIST ────────────────────────────────────────────────
async function loadQuizList(catId, catName, icon, color) {
  document.getElementById('qlist-title').textContent = `${icon} ${catName}`;
  document.getElementById('quiz-list-body').innerHTML =
    '<p style="color:var(--muted);text-align:center;padding:1rem">Loading…</p>';
  openModal('quiz-list-modal');
  const quizzes = await api({ action: 'get_quizzes', cat: catId });
  const body = document.getElementById('quiz-list-body');
  if (!quizzes.length) {
    body.innerHTML = '<p style="color:var(--muted);text-align:center">No quizzes in this category yet.</p>';
    return;
  }
  body.innerHTML = '';
  quizzes.forEach(q => {
    const el = document.createElement('div');
    el.className = 'quiz-list-item';
    const diffCls = q.difficulty === 'easy' ? 'diff-easy' : q.difficulty === 'hard' ? 'diff-hard' : 'diff-medium';
    el.innerHTML = `
      <div class="qli-title">${escHtml(q.title)}</div>
      <div class="qli-desc">${escHtml(q.description || '')}</div>
      <div class="qli-badges">
        <span class="qli-badge ${diffCls}">⚡ ${q.difficulty}</span>
        <span class="qli-badge">❓ ${q.question_count} questions</span>
        <span class="qli-badge">⏱ ${q.time_limit} min</span>
        <span class="qli-badge">🎯 Pass: ${q.pass_percentage}%</span>
      </div>
      <button class="lb-btn">🏆 Leaderboard</button>`;
    el.addEventListener('click', e => {
      if (e.target.classList.contains('lb-btn')) return;
      startQuiz(q.id, q.title);
    });
    el.querySelector('.lb-btn').addEventListener('click', e => {
      e.stopPropagation();
      showLeaderboard(q.id, q.title);
    });
    body.appendChild(el);
  });
}

// ── START QUIZ ───────────────────────────────────────────────
async function startQuiz(quizId, title) {
  if (!state.loggedIn) {
    closeModal('quiz-list-modal');
    toast('Please sign in to take a quiz', 'error');
    setTimeout(() => openAuth('login'), 700);
    return;
  }
  closeModal('quiz-list-modal');
  const res = await api({ action: 'start_quiz', quiz_id: quizId }, 'POST');
  if (!res.ok) { toast(res.msg, 'error'); return; }

  state.quiz        = res.quiz;
  state.questions   = res.questions;
  state.attemptId   = res.attempt_id;
  state.currentQ    = 0;
  state.responses   = {};
  state.reviewData  = null;
  state.timeTaken   = 0;
  state.isSubmitting= false;

  document.getElementById('quiz-screen-title').textContent = title;
  document.getElementById('quiz-screen').classList.add('active');
  document.body.style.overflow = 'hidden';

  buildDots();
  renderQuestion();
  // ── FIX 1: pass time_limit so timer shows correct initial value ──
  startTimer(parseInt(res.quiz.time_limit) * 60);
}

// ── DOTS ─────────────────────────────────────────────────────
function buildDots() {
  const c = document.getElementById('q-dots');
  c.innerHTML = '';
  state.questions.forEach((_, i) => {
    const d = document.createElement('div');
    d.className = 'q-dot' + (i === 0 ? ' current' : '');
    d.id = 'dot-' + i;
    d.title = 'Question ' + (i + 1);
    d.onclick = () => gotoQ(i);
    c.appendChild(d);
  });
}

function updateDots() {
  state.questions.forEach((q, i) => {
    const d = document.getElementById('dot-' + i);
    if (!d) return;
    d.className = 'q-dot'
      + (state.responses[q.id] !== undefined ? ' answered' : '')
      + (i === state.currentQ ? ' current' : '');
  });
}

// ── RENDER QUESTION ──────────────────────────────────────────
function renderQuestion() {
  const q        = state.questions[state.currentQ];
  const total    = state.questions.length;
  const answered = state.responses[q.id];
  const letters  = ['A','B','C','D','E','F'];
  const reviewing= state.reviewData !== null;
  const rq       = reviewing ? state.reviewData[q.id] : null;

  document.getElementById('quiz-progress').style.width =
    ((state.currentQ + 1) / total * 100) + '%';
  updateDots();

  const answersHTML = q.answers.map((a, i) => {
    let cls = 'ans-option';
    if (reviewing && rq) {
      const sel       = parseInt(answered) === parseInt(a.id);
      const isCorrect = rq.correct_answer && parseInt(a.id) === parseInt(rq.correct_answer.id);
      cls += ' disabled';
      if (sel && rq.correct)   cls += ' correct';
      else if (sel)            cls += ' wrong';
      else if (isCorrect)      cls += ' correct';
    } else if (parseInt(answered) === parseInt(a.id)) {
      cls += ' selected';
    }
    return `<div class="${cls}" onclick="selectAnswer(${q.id},${a.id})">
      <span class="ans-letter">${letters[i]}</span>
      ${escHtml(a.answer_text)}
    </div>`;
  }).join('');

  const explHTML = (reviewing && rq && rq.explanation)
    ? `<div class="explanation-box"><strong>💡 Explanation</strong>${escHtml(rq.explanation)}</div>` : '';

  const isLast = state.currentQ === total - 1;
  const navHTML = `
    <div class="q-nav">
      <button class="btn-nav prev" onclick="gotoQ(${state.currentQ - 1})"
        ${state.currentQ === 0 ? 'disabled style="opacity:.3;cursor:default"' : ''}>← Prev</button>
      <span style="font-size:.78rem;color:var(--muted)">${state.currentQ + 1} / ${total}</span>
      ${reviewing
        ? `<button class="btn-nav next" onclick="closeReview()">Finish ✓</button>`
        : isLast
          ? `<button class="btn-nav submit-q" id="submit-btn" onclick="confirmSubmit()">Submit Quiz ✓</button>`
          : `<button class="btn-nav next" onclick="gotoQ(${state.currentQ + 1})">Next →</button>`}
    </div>`;

  const html = `
    <div class="q-counter">Question ${state.currentQ + 1} of ${total} · ${q.points} pt${q.points > 1 ? 's' : ''}</div>
    <div class="q-text">${escHtml(q.question_text)}</div>
    <div class="answers-grid">${answersHTML}</div>
    ${explHTML}
    ${navHTML}`;

  const content = document.getElementById('quiz-content');
  content.style.opacity   = '0';
  content.style.transform = 'translateX(14px)';
  content.innerHTML       = html;
  requestAnimationFrame(() => {
    content.style.transition = 'opacity .3s,transform .3s';
    content.style.opacity    = '1';
    content.style.transform  = 'translateX(0)';
  });
}

function selectAnswer(qid, aid) {
  if (state.reviewData) return;
  state.responses[qid] = aid;
  // Re-render answers only (no full re-render to avoid flicker)
  document.querySelectorAll('.ans-option').forEach(opt => {
    opt.classList.remove('selected');
    const letter = opt.querySelector('.ans-letter');
    if (letter) { letter.style.background = ''; letter.style.color = ''; }
  });
  // Find and highlight selected
  document.querySelectorAll('.ans-option').forEach(opt => {
    opt.onclick = null;
  });
  renderQuestion(); // re-render to properly show selection
  updateDots();
}

function gotoQ(n) {
  if (n < 0 || n >= state.questions.length) return;
  state.currentQ = n;
  renderQuestion();
}

// ── FIX 2: TIMER — shows correct time immediately ────────────
function startTimer(seconds) {
  clearInterval(state.timerInterval);
  state.timeLeft = seconds;

  const timerEl = document.getElementById('quiz-timer');
  const valEl   = document.getElementById('timer-val');

  // ── Show correct initial time RIGHT AWAY (not after 1 second) ──
  function updateDisplay() {
    const m = Math.floor(state.timeLeft / 60);
    const s = state.timeLeft % 60;
    valEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    timerEl.classList.toggle('warn', state.timeLeft <= 60 && state.timeLeft > 0);
  }

  updateDisplay(); // show immediately

  state.timerInterval = setInterval(() => {
    if (state.timeLeft > 0) {
      state.timeLeft--;
      state.timeTaken++;
    }
    updateDisplay();
    if (state.timeLeft <= 0) {
      clearInterval(state.timerInterval);
      toast('⏰ Time is up! Submitting…', 'error');
      setTimeout(() => submitQuiz(true), 900);
    }
  }, 1000);
}

function stopTimer() {
  clearInterval(state.timerInterval);
  state.timerInterval = null;
}

// ── FIX 3: SUBMIT — uses custom dialog, not window.confirm ───
async function confirmSubmit() {
  if (state.isSubmitting) return; // prevent double submit
  const answered = Object.keys(state.responses).length;
  const total    = state.questions.length;

  if (answered < total) {
    const ok = await showConfirm(
      'Submit Quiz?',
      `You have answered ${answered} of ${total} questions. Submit anyway?`
    );
    if (!ok) return;
  } else {
    const ok = await showConfirm(
      'Submit Quiz?',
      `All ${total} questions answered. Ready to submit?`
    );
    if (!ok) return;
  }
  submitQuiz();
}

async function submitQuiz() {
  if (state.isSubmitting) return;
  state.isSubmitting = true;
  stopTimer();

  // Disable submit button to prevent double click
  const btn = document.getElementById('submit-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

  const res = await api({
    action:     'submit_quiz',
    attempt_id: state.attemptId,
    responses:  JSON.stringify(state.responses),
    time_taken: state.timeTaken
  }, 'POST');

  state.isSubmitting = false;

  if (!res.ok) {
    toast(res.msg || 'Submission failed. Please try again.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Submit Quiz ✓'; }
    return;
  }
  showResults(res);
}

// ── RESULTS ──────────────────────────────────────────────────
function showResults(res) {
  state.reviewData = res.results;
  const pct         = parseFloat(res.score);
  const passed      = res.passed;
  const circumference = 2 * Math.PI * 65;
  const dashOffset    = circumference - (pct / 100 * circumference);
  const mm = Math.floor(state.timeTaken / 60);
  const ss = state.timeTaken % 60;
  const timeFmt = String(mm).padStart(2,'0') + ':' + String(ss).padStart(2,'0');

  const reviewHTML = state.questions.map(q => {
    const rv = res.results[q.id];
    if (!rv) return '';
    const cls      = rv.correct ? 'correct-r' : 'wrong-r';
    const icon     = rv.correct ? '✅' : '❌';
    const selAns   = q.answers.find(a => parseInt(a.id) === parseInt(state.responses[q.id]));
    const corrAns  = rv.correct_answer;
    return `<div class="review-item ${cls}">
      <div class="review-q">${icon} ${escHtml(q.question_text)}</div>
      ${!rv.correct && selAns ? `<div class="review-ans">Your answer: <span class="wrong-a">${escHtml(selAns.answer_text)}</span></div>` : ''}
      ${!rv.correct && corrAns ? `<div class="review-ans">Correct: <span class="right-a">${escHtml(corrAns.answer_text)}</span></div>` : ''}
      ${rv.explanation ? `<div class="review-expl">💡 ${escHtml(rv.explanation)}</div>` : ''}
    </div>`;
  }).join('');

  document.getElementById('result-body').innerHTML = `
    <div class="score-circle-wrap">
      <div class="score-ring">
        <svg width="150" height="150" viewBox="0 0 150 150">
          <circle class="score-ring-bg" cx="75" cy="75" r="65"/>
          <circle class="score-ring-fill ${pct >= res.pass_pct ? '' : 'cyan'}"
            id="score-ring-fill" cx="75" cy="75" r="65"
            stroke-dasharray="${circumference.toFixed(2)}"
            stroke-dashoffset="${circumference.toFixed(2)}"/>
        </svg>
        <div class="score-ring-num">${pct.toFixed(0)}%</div>
      </div>
    </div>
    <div class="verdict-strip">
      <div class="verdict-badge ${passed ? 'pass' : 'fail'}">${passed ? '🎉 Passed!' : '😔 Try Again'}</div>
      <div class="verdict-sub">Pass mark: ${res.pass_pct}% · Your score: ${pct.toFixed(1)}%</div>
    </div>
    <div class="result-stats">
      <div class="rs-item"><div class="rs-val" style="color:var(--lime)">${res.pts_earned}</div><div class="rs-lbl">Earned</div></div>
      <div class="rs-item"><div class="rs-val" style="color:var(--muted2)">${res.pts_total}</div><div class="rs-lbl">Total</div></div>
      <div class="rs-item"><div class="rs-val">${timeFmt}</div><div class="rs-lbl">Time</div></div>
    </div>
    <div class="result-actions">
      <button class="btn-nav next"  onclick="reviewAnswers()">Review Answers</button>
      <button class="btn-nav prev"  onclick="closeModal('result-modal');exitQuiz()">Exit</button>
    </div>
    <div class="review-section">
      <h4>Answer Breakdown</h4>
      ${reviewHTML}
    </div>`;

  requestAnimationFrame(() => {
    setTimeout(() => {
      const fill = document.getElementById('score-ring-fill');
      if (fill) fill.style.strokeDashoffset = dashOffset.toFixed(2);
    }, 150);
  });

  document.getElementById('quiz-screen').classList.remove('active');
  document.body.style.overflow = '';
  openModal('result-modal');
}

function reviewAnswers() {
  closeModal('result-modal');
  document.getElementById('quiz-screen').classList.add('active');
  document.body.style.overflow = 'hidden';
  state.currentQ = 0;
  renderQuestion();
}

function closeReview() {
  state.reviewData = null;
  document.getElementById('quiz-screen').classList.remove('active');
  document.body.style.overflow = '';
  toast('Quiz finished! Great work 🎉');
}

function exitQuiz() {
  document.getElementById('quiz-screen').classList.remove('active');
  document.body.style.overflow = '';
  stopTimer();
}

// ── LEADERBOARD ──────────────────────────────────────────────
async function showLeaderboard(quizId, quizTitle) {
  closeModal('quiz-list-modal');
  document.getElementById('lb-body').innerHTML =
    '<p style="color:var(--muted);text-align:center;padding:1rem">Loading…</p>';
  openModal('lb-modal');
  const data   = await api({ action: 'leaderboard', quiz_id: quizId });
  const medals = ['🥇','🥈','🥉'];
  document.getElementById('lb-body').innerHTML = `
    <p style="font-weight:700;margin-bottom:1.2rem;font-size:.95rem">${escHtml(quizTitle)}</p>
    ${!data.length ? '<p style="color:var(--muted)">No scores yet — be the first!</p>' :
    `<table class="lb-table">
      <thead><tr><th>#</th><th>Player</th><th>Best Score</th><th>Time</th></tr></thead>
      <tbody>${data.map((r, i) => {
        const m = Math.floor(r.best_time / 60), s = r.best_time % 60;
        return `<tr>
          <td class="lb-rank ${i<3?'rank-'+(i+1):''}">${medals[i] || i+1}</td>
          <td>${escHtml(r.full_name)}</td>
          <td style="color:var(--lime);font-weight:700">${parseFloat(r.best_score).toFixed(1)}%</td>
          <td style="color:var(--muted2)">${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}</td>
        </tr>`;
      }).join('')}</tbody>
    </table>`}`;
}

// ── HISTORY ──────────────────────────────────────────────────
async function showHistory() {
  document.getElementById('hist-body').innerHTML =
    '<p style="color:var(--muted);text-align:center;padding:1rem">Loading…</p>';
  openModal('hist-modal');
  const data = await api({ action: 'my_history' });
  if (!data.length) {
    document.getElementById('hist-body').innerHTML =
      '<p style="color:var(--muted);text-align:center">No quizzes taken yet — go try one!</p>';
    return;
  }
  document.getElementById('hist-body').innerHTML = data.map(h => {
    const score = parseFloat(h.score);
    const m = Math.floor(h.time_taken / 60), s = h.time_taken % 60;
    const col  = score >= 70 ? 'var(--lime)' : score >= 50 ? 'var(--cyan)' : 'var(--pink)';
    const date = new Date(h.completed_at).toLocaleDateString('en-IN',
      { day:'numeric', month:'short', year:'numeric' });
    return `<div class="hist-card">
      <div class="hist-icon">${h.icon || '📚'}</div>
      <div class="hist-info">
        <div class="hist-title">${escHtml(h.title)}</div>
        <div class="hist-meta">${date} · ⏱ ${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}</div>
      </div>
      <div class="hist-score" style="color:${col}">${score.toFixed(0)}%</div>
    </div>`;
  }).join('');
}

// ── UTILITIES ────────────────────────────────────────────────
function escHtml(str) {
  return String(str || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function scrollToQuizzes() {
  document.getElementById('quizzes-section').scrollIntoView({ behavior: 'smooth' });
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// ── INIT ─────────────────────────────────────────────────────
loadCategories('all');
</script>
</body>
</html>