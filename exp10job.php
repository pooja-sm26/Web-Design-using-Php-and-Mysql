<?php
// ============================================================
//  TALENTBRIDGE INDIA — Production Single-File PHP Application
//  Requirements: PHP 8.1+, MySQL 8.0+ / MariaDB 10.5+
//  Setup: 1) Import jobportal_india_schema.sql
//         2) Edit DB credentials below
//         3) Create /uploads/resumes/ directory (chmod 755)
// ============================================================

// ── Configuration ─────────────────────────────────────────────
define('DB_HOST',  'localhost');
define('DB_USER',  'root');
define('DB_PASS',  '');
define('DB_NAME',  'jobportal_india');
define('APP_NAME', 'TalentBridge India');
define('APP_URL',  'http://localhost');                 // no trailing slash
define('UPLOAD_DIR', __DIR__ . '/uploads/resumes/');   // writable directory
define('MAX_RESUME_MB', 5);
define('ALLOWED_RESUME_TYPES', ['pdf','doc','docx']);
define('ITEMS_PER_PAGE', 12);

// ── Session & Error ────────────────────────────────────────────
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── Database ──────────────────────────────────────────────────
function db(): mysqli {
    static $c = null;
    if (!$c) {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($c->connect_error) die(json_encode(['error' => 'DB error: '.$c->connect_error]));
        $c->set_charset('utf8mb4');
    }
    return $c;
}
function q(string $sql, array $p = [], string $t = ''): mysqli_stmt {
    $s = db()->prepare($sql);
    if ($p) $s->bind_param($t ?: str_repeat('s', count($p)), ...$p);
    $s->execute();
    return $s;
}
function rows(string $sql, array $p = [], string $t = ''): array {
    return q($sql,$p,$t)->get_result()->fetch_all(MYSQLI_ASSOC);
}
function row(string $sql, array $p = [], string $t = ''): ?array {
    $r = rows($sql,$p,$t); return $r[0] ?? null;
}
function last_id(): int { return db()->insert_id; }

// ── Auth helpers ──────────────────────────────────────────────
function uid(): ?int   { return $_SESSION['uid']  ?? null; }
function uname(): string { return $_SESSION['uname'] ?? ''; }
function logged(): bool { return uid() !== null; }
function role(): string { return $_SESSION['role'] ?? 'jobseeker'; }

// ── Security ──────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) json_out(['ok'=>false,'msg'=>'Invalid request token.']);
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function json_out(array $d): never { header('Content-Type: application/json'); echo json_encode($d); exit; }

// ── INR salary formatter ──────────────────────────────────────
function inr(int $amount): string {
    if ($amount >= 10000000) return '₹' . round($amount/10000000, 1) . ' Cr';
    if ($amount >= 100000)   return '₹' . round($amount/100000, 1)   . ' L';
    if ($amount >= 1000)     return '₹' . round($amount/1000, 0)     . 'K';
    return '₹' . number_format($amount);
}
function salary_label(?int $min, ?int $max, bool $negotiable): string {
    if ($negotiable && !$min) return 'As per industry';
    if (!$min && !$max)       return 'Not disclosed';
    if ($min && $max)         return inr($min) . ' – ' . inr($max) . ' / yr';
    return ($min ? inr($min) : inr($max ?? 0)) . ' / yr';
}

// ── File Upload ───────────────────────────────────────────────
function upload_resume(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok'=>false,'msg'=>'Upload error.'];
    $size_mb = $file['size'] / 1048576;
    if ($size_mb > MAX_RESUME_MB) return ['ok'=>false,'msg'=>'Resume must be under '.MAX_RESUME_MB.'MB.'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_RESUME_TYPES)) return ['ok'=>false,'msg'=>'Only PDF/DOC/DOCX allowed.'];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $fname = 'resume_' . uid() . '_' . time() . '.' . $ext;
    $dest  = UPLOAD_DIR . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok'=>false,'msg'=>'Could not save file.'];
    return ['ok'=>true,'path'=>'uploads/resumes/'.$fname,'name'=>$file['name']];
}

// ── Rate limit (simple session-based) ─────────────────────────
function rate_limit(string $key, int $max = 5, int $window = 300): bool {
    $now = time();
    $k   = 'rl_' . $key;
    if (!isset($_SESSION[$k])) $_SESSION[$k] = ['count'=>0,'since'=>$now];
    if ($now - $_SESSION[$k]['since'] > $window) $_SESSION[$k] = ['count'=>0,'since'=>$now];
    if ($_SESSION[$k]['count'] >= $max) return false;
    $_SESSION[$k]['count']++;
    return true;
}

// ═══════════════════════════════════════════════════
//  POST ROUTER
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $safe   = ['register','login','fetch_jobs','job_detail','categories','stats','search_suggest'];
    if (!in_array($action, $safe)) verify_csrf();

    // ── Register ────────────────────────────────────────────
    if ($action === 'register') {
        if (!rate_limit('register', 3, 600)) json_out(['ok'=>false,'msg'=>'Too many attempts. Wait 10 min.']);
        $name   = trim($_POST['full_name'] ?? '');
        $email  = strtolower(trim($_POST['email'] ?? ''));
        $pass   = $_POST['password'] ?? '';
        $phone  = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $city   = trim($_POST['city']  ?? '');
        $state  = trim($_POST['state'] ?? '');
        if (!$name || !$email || strlen($pass) < 8)
            json_out(['ok'=>false,'msg'=>'Fill all fields. Password min 8 characters.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            json_out(['ok'=>false,'msg'=>'Invalid email address.']);
        if ($phone && !preg_match('/^[6-9]\d{9}$/', $phone))
            json_out(['ok'=>false,'msg'=>'Enter a valid 10-digit Indian mobile number.']);
        if (row('SELECT id FROM users WHERE email=?', [$email]))
            json_out(['ok'=>false,'msg'=>'Email already registered.']);
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        q('INSERT INTO users (full_name,email,password_hash,phone,city,state) VALUES(?,?,?,?,?,?)',
          [$name,$email,$hash,$phone,$city,$state]);
        $uid = last_id();
        q('INSERT INTO user_roles (user_id,role) VALUES(?,?)', [$uid,'jobseeker'], 'is');
        $_SESSION['uid']   = $uid;
        $_SESSION['uname'] = $name;
        $_SESSION['role']  = 'jobseeker';
        json_out(['ok'=>true,'name'=>$name,'uid'=>$uid]);
    }

    // ── Login ────────────────────────────────────────────────
    if ($action === 'login') {
        if (!rate_limit('login', 8, 300)) json_out(['ok'=>false,'msg'=>'Too many login attempts.']);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $user  = row('SELECT u.*,r.role FROM users u LEFT JOIN user_roles r ON r.user_id=u.id WHERE u.email=?', [$email]);
        if (!$user || !password_verify($pass, $user['password_hash']))
            json_out(['ok'=>false,'msg'=>'Invalid email or password.']);
        if (!$user['is_active']) json_out(['ok'=>false,'msg'=>'Account deactivated. Contact support.']);
        $_SESSION['uid']   = $user['id'];
        $_SESSION['uname'] = $user['full_name'];
        $_SESSION['role']  = $user['role'] ?? 'jobseeker';
        q('UPDATE users SET last_login=NOW() WHERE id=?', [$user['id']], 'i');
        json_out(['ok'=>true,'name'=>$user['full_name'],'role'=>$_SESSION['role']]);
    }

    // ── Logout ───────────────────────────────────────────────
    if ($action === 'logout') { session_destroy(); json_out(['ok'=>true]); }

    // ── Fetch Jobs ───────────────────────────────────────────
    if ($action === 'fetch_jobs') {
        $kw    = trim($_POST['search']   ?? '');
        $cat   = (int)($_POST['category']   ?? 0);
        $type  = trim($_POST['job_type']    ?? '');
        $exp   = trim($_POST['experience']  ?? '');
        $city  = trim($_POST['city']        ?? '');
        $state = trim($_POST['state']       ?? '');
        $mode  = trim($_POST['work_mode']   ?? '');
        $smin  = (int)($_POST['salary_min'] ?? 0);
        $sort  = in_array($_POST['sort'] ?? '', ['salary','posted_at','applications_count']) ? $_POST['sort'] : 'posted_at';
        $page  = max(1, (int)($_POST['page'] ?? 1));
        $offs  = ($page - 1) * ITEMS_PER_PAGE;

        $sql = 'SELECT j.id,j.title,j.job_type,j.experience,j.location,j.city,j.state,
                       j.work_mode,j.salary_min,j.salary_max,j.salary_negotiable,
                       j.skills_required,j.deadline,j.is_featured,j.is_urgent,
                       j.applications_count,j.posted_at,j.vacancies,
                       c.name AS company_name,c.logo_color,c.industry,c.is_verified,
                       cat.name AS category_name,cat.icon AS category_icon
                FROM jobs j
                JOIN companies c    ON c.id   = j.company_id
                JOIN categories cat ON cat.id = j.category_id
                WHERE j.is_active=1
                  AND (j.deadline IS NULL OR j.deadline >= CURDATE())';
        $p = []; $t = '';
        if ($kw) {
            $sql .= ' AND (j.title LIKE ? OR c.name LIKE ? OR j.skills_required LIKE ? OR j.description LIKE ?)';
            $l = "%$kw%"; $p = array_merge($p, [$l,$l,$l,$l]); $t .= 'ssss';
        }
        if ($cat)   { $sql .= ' AND j.category_id=?'; $p[] = $cat;   $t .= 'i'; }
        if ($type)  { $sql .= ' AND j.job_type=?';    $p[] = $type;  $t .= 's'; }
        if ($exp)   { $sql .= ' AND j.experience=?';  $p[] = $exp;   $t .= 's'; }
        if ($city)  { $sql .= ' AND j.city LIKE ?';   $p[] = "%$city%";  $t .= 's'; }
        if ($state) { $sql .= ' AND j.state LIKE ?';  $p[] = "%$state%"; $t .= 's'; }
        if ($mode)  { $sql .= ' AND j.work_mode=?';   $p[] = $mode;  $t .= 's'; }
        if ($smin)  { $sql .= ' AND (j.salary_max>=? OR j.salary_negotiable=1)'; $p[] = $smin; $t .= 'i'; }

        // count query
        $cnt = (int)row(str_replace('SELECT j.id,j.title,j.job_type,j.experience,j.location,j.city,j.state,
                       j.work_mode,j.salary_min,j.salary_max,j.salary_negotiable,
                       j.skills_required,j.deadline,j.is_featured,j.is_urgent,
                       j.applications_count,j.posted_at,j.vacancies,
                       c.name AS company_name,c.logo_color,c.industry,c.is_verified,
                       cat.name AS category_name,cat.icon AS category_icon', 'SELECT COUNT(*) AS n', $sql), $p, $t)['n'];

        $sql .= match($sort) {
            'salary'             => ' ORDER BY j.is_featured DESC, j.salary_max DESC, j.posted_at DESC',
            'applications_count' => ' ORDER BY j.is_featured DESC, j.applications_count DESC, j.posted_at DESC',
            default              => ' ORDER BY j.is_featured DESC, j.is_urgent DESC, j.posted_at DESC'
        };
        $sql .= ' LIMIT ? OFFSET ?';
        $p[] = ITEMS_PER_PAGE; $p[] = $offs; $t .= 'ii';

        $jobs = rows($sql, $p, $t);
        // Format salary labels and enrich
        foreach ($jobs as &$j) {
            $j['salary_label'] = salary_label($j['salary_min'], $j['salary_max'], (bool)$j['salary_negotiable']);
            $j['posted_ago']   = time_ago($j['posted_at']);
            $j['saved']        = false;
            $j['applied']      = false;
        }
        unset($j);
        if (logged()) {
            $saved   = array_column(rows('SELECT job_id FROM saved_jobs WHERE user_id=?',[uid()],'i'),'job_id');
            $applied = array_column(rows('SELECT job_id FROM applications WHERE user_id=?',[uid()],'i'),'job_id');
            foreach ($jobs as &$j) {
                $j['saved']   = in_array($j['id'], $saved);
                $j['applied'] = in_array($j['id'], $applied);
            }
        }
        json_out(['ok'=>true,'jobs'=>$jobs,'total'=>$cnt,'pages'=>ceil($cnt/ITEMS_PER_PAGE),'page'=>$page]);
    }

    // ── Search Suggestions ───────────────────────────────────
    if ($action === 'search_suggest') {
        $kw = trim($_POST['q'] ?? '');
        if (strlen($kw) < 2) json_out(['ok'=>true,'suggestions'=>[]]);
        $l = "%$kw%";
        $titles = rows('SELECT DISTINCT title AS text,"job" AS type FROM jobs WHERE is_active=1 AND title LIKE ? LIMIT 5',[$l]);
        $skills = rows('SELECT DISTINCT skills_required AS raw FROM jobs WHERE is_active=1 AND skills_required LIKE ? LIMIT 3',[$l]);
        $sug = $titles;
        foreach ($skills as $s) {
            foreach (explode(',', $s['raw']) as $sk) {
                $sk = trim($sk);
                if (stripos($sk, $kw) !== false) { $sug[] = ['text'=>$sk,'type'=>'skill']; }
            }
        }
        json_out(['ok'=>true,'suggestions'=>array_slice($sug, 0, 8)]);
    }

    // ── Job Detail ───────────────────────────────────────────
    if ($action === 'job_detail') {
        $id = (int)($_POST['job_id'] ?? 0);
        $j  = row('SELECT j.*,c.name AS company_name,c.logo_color,c.industry,c.description AS co_desc,
                          c.website,c.founded_year,c.employee_count,c.city AS co_city,c.state AS co_state,
                          c.is_verified,cat.name AS category_name,cat.icon AS category_icon
                   FROM jobs j
                   JOIN companies c ON c.id=j.company_id
                   JOIN categories cat ON cat.id=j.category_id
                   WHERE j.id=? AND j.is_active=1', [$id], 'i');
        if (!$j) json_out(['ok'=>false,'msg'=>'Job not found or expired.']);
        q('UPDATE jobs SET views=views+1 WHERE id=?', [$id], 'i');
        $j['salary_label'] = salary_label($j['salary_min'], $j['salary_max'], (bool)$j['salary_negotiable']);
        $j['posted_ago']   = time_ago($j['posted_at']);
        if (logged()) {
            $j['saved']   = (bool)row('SELECT id FROM saved_jobs WHERE user_id=? AND job_id=?',[uid(),$id],'ii');
            $j['applied'] = (bool)row('SELECT id FROM applications WHERE user_id=? AND job_id=?',[uid(),$id],'ii');
        }
        // Similar jobs
        $similar = rows('SELECT j2.id,j2.title,j2.job_type,j2.salary_min,j2.salary_max,j2.salary_negotiable,j2.location,
                                c2.name AS company_name,c2.logo_color
                         FROM jobs j2 JOIN companies c2 ON c2.id=j2.company_id
                         WHERE j2.category_id=? AND j2.id!=? AND j2.is_active=1
                         ORDER BY j2.posted_at DESC LIMIT 4',
                        [$j['category_id'],$id],'ii');
        foreach ($similar as &$s) $s['salary_label'] = salary_label($s['salary_min'],$s['salary_max'],(bool)$s['salary_negotiable']);
        $j['similar'] = $similar;
        json_out(['ok'=>true,'job'=>$j]);
    }

    // ── Apply ────────────────────────────────────────────────
    if ($action === 'apply') {
        if (!logged()) json_out(['ok'=>false,'msg'=>'Please sign in to apply.']);
        $jid  = (int)($_POST['job_id'] ?? 0);
        $cl   = trim($_POST['cover_letter'] ?? '');
        $exp  = (int)($_POST['expected_salary'] ?? 0);
        $np   = (int)($_POST['notice_period']   ?? 0);
        if (!$jid) json_out(['ok'=>false,'msg'=>'Invalid job.']);
        if (row('SELECT id FROM applications WHERE user_id=? AND job_id=?',[uid(),$jid],'ii'))
            json_out(['ok'=>false,'msg'=>'You have already applied for this job.']);
        $job = row('SELECT j.title,c.name AS co FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.id=?',[$jid],'i');
        if (!$job) json_out(['ok'=>false,'msg'=>'Job not found.']);
        $u = row('SELECT resume_path FROM users WHERE id=?',[uid()],'i');
        q('INSERT INTO applications (user_id,job_id,cover_letter,expected_salary,notice_period,resume_snapshot)
           VALUES(?,?,?,?,?,?)',
          [uid(),$jid,$cl,$exp ?: null,$np,$u['resume_path'] ?? null],'iisiii');
        // notification
        q('INSERT INTO notifications (user_id,type,title,body) VALUES(?,?,?,?)',
          [uid(),'application_update',
           'Application Submitted',
           'You applied for "'.$job['title'].'" at '.$job['co'].'. We\'ll notify you of updates.'],'isss');
        json_out(['ok'=>true,'msg'=>'Application submitted!  You will be notified of updates.']);
    }

    // ── Toggle Save ──────────────────────────────────────────
    if ($action === 'toggle_save') {
        if (!logged()) json_out(['ok'=>false,'msg'=>'Please sign in first.']);
        $jid = (int)($_POST['job_id'] ?? 0);
        if (row('SELECT id FROM saved_jobs WHERE user_id=? AND job_id=?',[uid(),$jid],'ii')) {
            q('DELETE FROM saved_jobs WHERE user_id=? AND job_id=?',[uid(),$jid],'ii');
            json_out(['ok'=>true,'saved'=>false]);
        } else {
            q('INSERT INTO saved_jobs (user_id,job_id) VALUES(?,?)',[uid(),$jid],'ii');
            json_out(['ok'=>true,'saved'=>true]);
        }
    }

    // ── My Applications ──────────────────────────────────────
    if ($action === 'my_applications') {
        if (!logged()) json_out(['ok'=>false]);
        $apps = rows('SELECT a.*,j.title,j.job_type,j.location,j.salary_min,j.salary_max,
                             j.salary_negotiable,c.name AS company_name,c.logo_color,c.industry
                      FROM applications a
                      JOIN jobs j ON j.id=a.job_id
                      JOIN companies c ON c.id=j.company_id
                      WHERE a.user_id=? ORDER BY a.applied_at DESC',[uid()],'i');
        foreach ($apps as &$a) $a['salary_label'] = salary_label($a['salary_min'],$a['salary_max'],(bool)$a['salary_negotiable']);
        json_out(['ok'=>true,'applications'=>$apps]);
    }

    // ── Saved Jobs ───────────────────────────────────────────
    if ($action === 'saved_jobs') {
        if (!logged()) json_out(['ok'=>false]);
        $jobs = rows('SELECT j.id,j.title,j.job_type,j.location,j.salary_min,j.salary_max,
                             j.salary_negotiable,j.deadline,s.saved_at,
                             c.name AS company_name,c.logo_color,cat.name AS category_name
                      FROM saved_jobs s
                      JOIN jobs j ON j.id=s.job_id
                      JOIN companies c ON c.id=j.company_id
                      JOIN categories cat ON cat.id=j.category_id
                      WHERE s.user_id=? ORDER BY s.saved_at DESC',[uid()],'i');
        foreach ($jobs as &$j) $j['salary_label'] = salary_label($j['salary_min'],$j['salary_max'],(bool)$j['salary_negotiable']);
        json_out(['ok'=>true,'jobs'=>$jobs]);
    }

    // ── Get Profile ──────────────────────────────────────────
    if ($action === 'get_profile') {
        if (!logged()) json_out(['ok'=>false]);
        $u   = row('SELECT id,full_name,email,phone,city,state,headline,summary,total_experience,
                           current_salary,expected_salary,notice_period,skills,resume_path,resume_name,
                           linkedin_url,github_url,portfolio_url,profile_views
                    FROM users WHERE id=?',[uid()],'i');
        $edu = rows('SELECT * FROM user_education WHERE user_id=? ORDER BY pass_year DESC',[uid()],'i');
        $exp = rows('SELECT * FROM user_experience WHERE user_id=? ORDER BY start_date DESC',[uid()],'i');
        if ($u['current_salary'])  $u['current_salary_label']  = inr($u['current_salary']);
        if ($u['expected_salary']) $u['expected_salary_label'] = inr($u['expected_salary']);
        json_out(['ok'=>true,'profile'=>$u,'education'=>$edu,'experience'=>$exp]);
    }

    // ── Update Profile ───────────────────────────────────────
    if ($action === 'update_profile') {
        if (!logged()) json_out(['ok'=>false,'msg'=>'Not logged in.']);
        $fields = ['full_name','phone','city','state','headline','summary',
                   'total_experience','current_salary','expected_salary',
                   'notice_period','skills','linkedin_url','github_url','portfolio_url'];
        $sets = []; $vals = []; $types = '';
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $sets[]  = "`$f`=?";
                $vals[]  = trim($_POST[$f]);
                $types  .= 's';
            }
        }
        if (empty($sets)) json_out(['ok'=>false,'msg'=>'Nothing to update.']);
        $vals[] = uid(); $types .= 'i';
        q('UPDATE users SET '.implode(',',$sets).' WHERE id=?', $vals, $types);
        json_out(['ok'=>true,'msg'=>'Profile updated successfully.']);
    }

    // ── Upload Resume ────────────────────────────────────────
    if ($action === 'upload_resume') {
        if (!logged()) json_out(['ok'=>false,'msg'=>'Not logged in.']);
        if (empty($_FILES['resume'])) json_out(['ok'=>false,'msg'=>'No file received.']);
        $r = upload_resume($_FILES['resume']);
        if (!$r['ok']) json_out($r);
        q('UPDATE users SET resume_path=?,resume_name=? WHERE id=?',[$r['path'],$r['name'],uid()],'ssi');
        json_out(['ok'=>true,'msg'=>'Resume uploaded successfully.','path'=>$r['path'],'name'=>$r['name']]);
    }

    // ── Notifications ────────────────────────────────────────
    if ($action === 'notifications') {
        if (!logged()) json_out(['ok'=>false]);
        $notifs = rows('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20',[uid()],'i');
        $unread = (int)row('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0',[uid()],'i')['n'];
        json_out(['ok'=>true,'notifications'=>$notifs,'unread'=>$unread]);
    }

    if ($action === 'mark_read') {
        if (!logged()) json_out(['ok'=>false]);
        q('UPDATE notifications SET is_read=1 WHERE user_id=?',[uid()],'i');
        json_out(['ok'=>true]);
    }

    // ── Job Alert ────────────────────────────────────────────
    if ($action === 'set_alert') {
        if (!logged()) json_out(['ok'=>false,'msg'=>'Please sign in.']);
        $cat   = (int)($_POST['category_id'] ?? 0) ?: null;
        $kw    = trim($_POST['keywords']   ?? '') ?: null;
        $city  = trim($_POST['city']       ?? '') ?: null;
        $state = trim($_POST['state']      ?? '') ?: null;
        $freq  = in_array($_POST['frequency']??'Daily',['Instant','Daily','Weekly'])
                 ? $_POST['frequency'] : 'Daily';
        q('INSERT INTO job_alerts (user_id,keywords,category_id,city,state,frequency)
           VALUES(?,?,?,?,?,?)',[uid(),$kw,$cat,$city,$state,$freq],'isisss');
        json_out(['ok'=>true,'msg'=>"Job alert set! You'll get $freq notifications."]);
    }

    // ── Categories ───────────────────────────────────────────
    if ($action === 'categories') {
        $cats = rows('SELECT cat.*,COUNT(j.id) AS job_count
                      FROM categories cat
                      LEFT JOIN jobs j ON j.category_id=cat.id AND j.is_active=1
                           AND (j.deadline IS NULL OR j.deadline>=CURDATE())
                      GROUP BY cat.id ORDER BY job_count DESC');
        json_out(['ok'=>true,'categories'=>$cats]);
    }

    // ── Stats ────────────────────────────────────────────────
    if ($action === 'stats') {
        $jobs    = (int)row('SELECT COUNT(*) AS n FROM jobs WHERE is_active=1')['n'];
        $comp    = (int)row('SELECT COUNT(*) AS n FROM companies')['n'];
        $apps    = (int)row('SELECT COUNT(*) AS n FROM applications')['n'];
        $cities  = (int)row('SELECT COUNT(DISTINCT city) AS n FROM jobs WHERE is_active=1')['n'];
        json_out(['ok'=>true,'jobs'=>$jobs,'companies'=>$comp,'applications'=>$apps,'cities'=>$cities]);
    }

    // ── Indian States list ────────────────────────────────────
    if ($action === 'states') {
        $states = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat',
                   'Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh',
                   'Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab',
                   'Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh',
                   'Uttarakhand','West Bengal',
                   'Andaman and Nicobar Islands','Chandigarh','Delhi','Jammu & Kashmir',
                   'Ladakh','Lakshadweep','Puducherry'];
        json_out(['ok'=>true,'states'=>$states]);
    }

    json_out(['ok'=>false,'msg'=>'Unknown action.']);
}

// ── Session Info ──────────────────────────────────────────────
if (isset($_GET['session_info'])) {
    $unread = logged() ? (int)row('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0',[uid()],'i')['n'] : 0;
    json_out(['logged'=>logged(),'name'=>uname(),'role'=>role(),'csrf'=>csrf_token(),'unread'=>$unread]);
}

// ── Helpers ───────────────────────────────────────────────────
function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)   return max(1, (int)($diff/60)) . 'm ago';
    if ($diff < 86400)  return (int)($diff/3600) . 'h ago';
    if ($diff < 604800) return (int)($diff/86400) . 'd ago';
    return date('d M Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="description" content="Find top jobs in India — IT, Finance, Healthcare, Engineering and more. Apply with one click on TalentBridge India."/>
<title>TalentBridge India — Find Jobs in India</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet"/>
<style>
/* ── Root ──────────────────────────────────────────────────── */
:root{
  --bg:#07090f;
  --s1:#0d1117;
  --s2:#111827;
  --s3:#1a2235;
  --border:#1f2d46;
  --border2:#253350;
  --a1:#3b82f6;
  --a2:#8b5cf6;
  --a3:#10b981;
  --gold:#f59e0b;
  --red:#ef4444;
  --orange:#f97316;
  --text:#e2e8f0;
  --text2:#94a3b8;
  --text3:#64748b;
  --r:14px;
  --r2:10px;
  --r3:8px;
  --sh:0 8px 32px rgba(0,0,0,.5);
  --f:'Plus Jakarta Sans',sans-serif;
  --fm:'JetBrains Mono',monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;color-scheme:dark}
body{background:var(--bg);color:var(--text);font-family:var(--f);font-size:14.5px;line-height:1.6;overflow-x:hidden}
a{color:inherit;text-decoration:none}
button{cursor:pointer;font-family:var(--f);transition:.18s}
input,select,textarea{font-family:var(--f)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
img{max-width:100%;display:block}

/* ── Layout ───────────────────────────────────────────────── */
.wrap{max-width:1320px;margin:0 auto;padding:0 20px}

/* ── NAV ──────────────────────────────────────────────────── */
nav{position:sticky;top:0;z-index:200;background:rgba(7,9,15,.88);backdrop-filter:blur(20px);border-bottom:1px solid var(--border)}
.nav-i{display:flex;align-items:center;justify-content:space-between;height:62px;gap:12px}
.logo{font-size:1.25rem;font-weight:800;letter-spacing:-.4px;white-space:nowrap}
.logo .hi{color:var(--a1)}
.logo .flag{font-size:.9rem}
.nav-r{display:flex;align-items:center;gap:8px}
.nb{padding:8px 16px;border-radius:var(--r2);font-size:.82rem;font-weight:600;border:none}
.nb.ghost{background:transparent;color:var(--text2)}
.nb.ghost:hover{background:var(--s2);color:var(--text)}
.nb.out{background:transparent;border:1.5px solid var(--border2);color:var(--text)}
.nb.out:hover{border-color:var(--a1);color:var(--a1)}
.nb.pri{background:linear-gradient(135deg,var(--a1),var(--a2));color:#fff}
.nb.pri:hover{opacity:.88;transform:translateY(-1px)}
.nb.suc{background:linear-gradient(135deg,var(--a3),#059669);color:#fff}
.avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--a1),var(--a2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.8rem;color:#fff;flex-shrink:0}
.notif-btn{position:relative;background:transparent;border:none;color:var(--text2);font-size:1.1rem;padding:6px}
.notif-dot{position:absolute;top:4px;right:4px;width:8px;height:8px;background:var(--red);border-radius:50%;border:2px solid var(--bg)}

/* ── HERO ─────────────────────────────────────────────────── */
.hero{padding:88px 0 64px;position:relative;overflow:hidden;text-align:center}
.hero-bg{position:absolute;inset:0;background:
  radial-gradient(ellipse 70% 50% at 50% -5%,rgba(59,130,246,.14) 0%,transparent 65%),
  radial-gradient(ellipse 40% 30% at 85% 90%,rgba(139,92,246,.09) 0%,transparent 60%),
  radial-gradient(ellipse 30% 20% at 10% 70%,rgba(16,185,129,.07) 0%,transparent 55%)}
.hero-grid{position:absolute;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:44px 44px;opacity:.25}
.hero-content{position:relative}
.hero-chip{display:inline-flex;align-items:center;gap:7px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:100px;padding:5px 16px;font-size:.76rem;font-weight:600;color:var(--a1);margin-bottom:24px;animation:up .5s ease both}
.hero h1{font-size:clamp(2rem,5.5vw,3.8rem);font-weight:800;letter-spacing:-1.5px;line-height:1.1;margin-bottom:16px;animation:up .55s .1s ease both}
.hero h1 .g{background:linear-gradient(135deg,var(--a1),var(--a2),var(--a3));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{color:var(--text2);font-size:1rem;max-width:500px;margin:0 auto 40px;animation:up .55s .18s ease both}

/* ── SEARCH ───────────────────────────────────────────────── */
.search-wrap{max-width:860px;margin:0 auto;animation:up .55s .25s ease both;position:relative}
.search-box{display:flex;background:var(--s2);border:1.5px solid var(--border2);border-radius:var(--r);overflow:visible;box-shadow:var(--sh);position:relative}
.search-box input,.search-box select{background:transparent;border:none;outline:none;color:var(--text);padding:15px 18px;font-size:.9rem}
.search-box input{flex:1;min-width:0}
.search-box input::placeholder{color:var(--text3)}
.search-box select{color:var(--text2);border-left:1px solid var(--border);cursor:pointer;font-size:.82rem}
.search-box select option{background:var(--s2)}
.search-box .sdiv{width:1px;background:var(--border);flex-shrink:0;align-self:stretch;margin:8px 0}
.search-btn{background:linear-gradient(135deg,var(--a1),var(--a2));border:none;color:#fff;padding:15px 28px;font-size:.88rem;font-weight:700;transition:.2s;white-space:nowrap;border-radius:0 var(--r) var(--r) 0}
.search-btn:hover{opacity:.88}
.suggest-box{position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--s2);border:1.5px solid var(--border2);border-radius:var(--r2);z-index:100;overflow:hidden;box-shadow:var(--sh);display:none}
.suggest-box.open{display:block}
.suggest-item{padding:10px 18px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:.15s}
.suggest-item:hover{background:var(--s3)}
.suggest-type{font-size:.7rem;padding:2px 7px;border-radius:4px;font-weight:600}
.suggest-type.job{background:rgba(59,130,246,.15);color:var(--a1)}
.suggest-type.skill{background:rgba(16,185,129,.15);color:var(--a3)}

/* ── POPULAR SEARCHES ─────────────────────────────────────── */
.popular{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:18px;animation:up .55s .32s ease both}
.ptag{background:var(--s2);border:1px solid var(--border);border-radius:100px;padding:5px 14px;font-size:.76rem;color:var(--text2);cursor:pointer;transition:.18s}
.ptag:hover{border-color:var(--a1);color:var(--a1)}

/* ── STATS ────────────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:52px;animation:up .55s .38s ease both}
.stat-card{background:var(--s2);border:1px solid var(--border);border-radius:var(--r);padding:20px;text-align:center}
.stat-n{font-size:1.9rem;font-weight:800;background:linear-gradient(135deg,var(--a1),var(--a2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1}
.stat-l{font-size:.73rem;color:var(--text3);text-transform:uppercase;letter-spacing:.6px;margin-top:5px}

/* ── SECTION ──────────────────────────────────────────────── */
section{padding:64px 0}
.sh{text-align:center;margin-bottom:40px}
.sl{font-size:.72rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--a1);font-weight:700;margin-bottom:8px}
.st{font-size:clamp(1.5rem,3vw,2.2rem);font-weight:800;letter-spacing:-1px}
.ss{color:var(--text2);margin-top:8px;font-size:.9rem}

/* ── CATEGORY GRID ────────────────────────────────────────── */
.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:12px}
.cc{background:var(--s2);border:1.5px solid var(--border);border-radius:var(--r);padding:20px 12px;text-align:center;cursor:pointer;transition:.22s;position:relative;overflow:hidden}
.cc:hover{border-color:var(--a1);transform:translateY(-3px);box-shadow:0 12px 36px rgba(59,130,246,.18)}
.cc.active{border-color:var(--a1);background:rgba(59,130,246,.07)}
.cc-icon{font-size:1.8rem;margin-bottom:9px}
.cc-name{font-size:.78rem;font-weight:700;margin-bottom:3px}
.cc-cnt{font-size:.7rem;color:var(--text3)}

/* ── FILTERS ──────────────────────────────────────────────── */
.filter-bar{background:var(--s1);border:1.5px solid var(--border);border-radius:var(--r);padding:14px 18px;margin-bottom:24px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.fs{background:var(--s3);border:1px solid var(--border2);color:var(--text);padding:7px 12px;border-radius:var(--r3);font-size:.8rem;cursor:pointer;outline:none;transition:.18s}
.fs:hover,.fs:focus{border-color:var(--a1)}
.fs option{background:var(--s2)}
.flabel{font-size:.75rem;color:var(--text3);font-weight:600}
.fg{display:flex;align-items:center;gap:7px}
.fspace{flex:1}
.clr-btn{background:transparent;border:1px solid var(--border2);color:var(--text3);padding:7px 14px;border-radius:var(--r3);font-size:.78rem}
.clr-btn:hover{border-color:var(--red);color:var(--red)}

/* Salary slider */
.salary-row{display:flex;align-items:center;gap:10px}
.salary-val{font-size:.78rem;color:var(--a3);font-weight:700;white-space:nowrap;min-width:70px;font-family:var(--fm)}
input[type=range]{-webkit-appearance:none;appearance:none;height:4px;background:var(--border2);border-radius:2px;outline:none;width:120px}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:var(--a1);cursor:pointer}

/* ── JOBS GRID ────────────────────────────────────────────── */
.jobs-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px}
.jcount{font-size:.85rem;color:var(--text2)}
.jcount strong{color:var(--text)}
.jgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.jcard{background:var(--s2);border:1.5px solid var(--border);border-radius:var(--r);padding:20px;cursor:pointer;transition:.22s;position:relative;overflow:hidden}
.jcard::before{content:'';position:absolute;bottom:0;left:0;right:0;height:2.5px;background:linear-gradient(90deg,var(--a1),var(--a2));opacity:0;transition:.22s}
.jcard:hover{border-color:rgba(59,130,246,.4);transform:translateY(-3px);box-shadow:0 14px 44px rgba(59,130,246,.15)}
.jcard:hover::before{opacity:1}
.jcard.featured{border-color:rgba(245,158,11,.35)}
.jcard.featured::before{background:linear-gradient(90deg,var(--gold),var(--orange));opacity:1}
.jcard-top{display:flex;gap:12px;margin-bottom:14px;align-items:flex-start}
.clogo{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;color:#fff;flex-shrink:0}
.jtitle{font-size:.93rem;font-weight:700;margin-bottom:3px;line-height:1.3}
.jcomp{font-size:.76rem;color:var(--text2);display:flex;align-items:center;gap:5px}
.vbadge{color:var(--a3);font-size:.7rem}
.badges{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px}
.badge{padding:3px 9px;border-radius:100px;font-size:.68rem;font-weight:700;letter-spacing:.2px}
.bt{background:rgba(59,130,246,.12);color:#60a5fa}
.be{background:rgba(139,92,246,.12);color:#a78bfa}
.bm{background:rgba(16,185,129,.12);color:#34d399}
.ba{background:rgba(245,158,11,.12);color:#fbbf24}
.bc{background:rgba(30,45,80,.9);color:var(--text2)}
.bfeat{background:rgba(245,158,11,.15);color:var(--gold)}
.burg{background:rgba(239,68,68,.12);color:#f87171}
.sal{font-family:var(--fm);font-size:.92rem;font-weight:700;color:var(--a3);margin-bottom:8px}
.skills{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px}
.stag{background:var(--s3);border:1px solid var(--border);border-radius:5px;padding:2px 8px;font-size:.66rem;color:var(--text2)}
.jfoot{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border)}
.jloc{font-size:.72rem;color:var(--text3);display:flex;align-items:center;gap:4px}
.jmeta{display:flex;align-items:center;gap:8px}
.jago{font-size:.68rem;color:var(--text3);font-family:var(--fm)}
.sav{background:transparent;border:none;color:var(--text3);font-size:1rem;padding:4px;line-height:1}
.sav:hover,.sav.saved{color:var(--gold)}
.vacbadge{font-size:.68rem;color:var(--text3)}

/* ── PAGINATION ───────────────────────────────────────────── */
.pag{display:flex;justify-content:center;gap:8px;margin-top:32px;flex-wrap:wrap}
.pag-btn{background:var(--s2);border:1.5px solid var(--border);color:var(--text2);width:38px;height:38px;border-radius:var(--r3);font-size:.85rem;display:flex;align-items:center;justify-content:center}
.pag-btn:hover{border-color:var(--a1);color:var(--a1)}
.pag-btn.active{background:var(--a1);border-color:var(--a1);color:#fff;font-weight:700}

/* ── MODAL SYSTEM ─────────────────────────────────────────── */
.ov{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(10px);z-index:300;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:.22s}
.ov.open{opacity:1;pointer-events:all}
.modal{background:var(--s1);border:1.5px solid var(--border2);border-radius:18px;width:100%;max-height:92vh;overflow-y:auto;transform:translateY(18px) scale(.97);transition:.22s;box-shadow:0 40px 80px rgba(0,0,0,.65)}
.ov.open .modal{transform:none}
.mh{display:flex;justify-content:space-between;align-items:center;padding:22px 26px 0}
.mt{font-size:1.15rem;font-weight:800;letter-spacing:-.3px}
.xbtn{background:transparent;border:none;color:var(--text2);font-size:1.3rem;padding:4px;line-height:1}
.xbtn:hover{color:var(--text)}
.mb{padding:22px 26px 26px}
.mtabs{display:flex;gap:2px;background:var(--s3);border-radius:var(--r3);padding:4px;margin-bottom:22px}
.mtab{flex:1;background:transparent;border:none;padding:8px;border-radius:var(--r3);font-size:.82rem;color:var(--text2);font-weight:600;transition:.18s}
.mtab.active{background:var(--border2);color:var(--text)}

/* ── FORM ─────────────────────────────────────────────────── */
.fg2{margin-bottom:16px}
.fl{display:block;font-size:.78rem;font-weight:600;color:var(--text2);margin-bottom:6px}
.fc{width:100%;background:var(--s3);border:1.5px solid var(--border2);border-radius:var(--r3);color:var(--text);padding:11px 14px;font-size:.86rem;outline:none;transition:.18s}
.fc:focus{border-color:var(--a1);box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.fc::placeholder{color:var(--text3)}
textarea.fc{resize:vertical;min-height:90px}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ferr{color:var(--red);font-size:.76rem;margin-top:6px;min-height:18px}
.fsuc{color:var(--a3);font-size:.78rem;margin-top:6px}
.fbtn{width:100%;padding:12px;border:none;border-radius:var(--r3);font-size:.9rem;font-weight:700;margin-top:6px}
.fbtn.pri{background:linear-gradient(135deg,var(--a1),var(--a2));color:#fff}
.fbtn.pri:hover{opacity:.88}
.fbtn.suc{background:linear-gradient(135deg,var(--a3),#059669);color:#fff}
.fbtn.suc:hover{opacity:.88}
.fbtn.ghost{background:var(--s3);border:1.5px solid var(--border2);color:var(--text)}
.fbtn.ghost:hover{border-color:var(--a1)}
.fhint{font-size:.72rem;color:var(--text3);margin-top:5px}

/* ── JOB DETAIL MODAL ─────────────────────────────────────── */
.jd-ov .modal{max-width:680px}
.jd-top{display:flex;gap:14px;margin-bottom:18px;align-items:flex-start}
.jd-logo{width:58px;height:58px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.3rem;color:#fff}
.jd-title{font-size:1.4rem;font-weight:800;letter-spacing:-.4px;margin-bottom:3px}
.jd-co{font-size:.85rem;color:var(--text2)}
.jd-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.jd-sec{margin-bottom:18px}
.jd-sec h4{font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--a1);margin-bottom:8px}
.jd-sec p,.jd-sec li{font-size:.85rem;color:var(--text2);line-height:1.8}
.jd-sec ul{padding-left:18px}
.jd-acts{display:flex;gap:10px;margin-top:22px;padding-top:18px;border-top:1px solid var(--border)}
.jd-apply{flex:1;padding:13px;border:none;border-radius:var(--r3);background:linear-gradient(135deg,var(--a1),var(--a2));color:#fff;font-size:.9rem;font-weight:700}
.jd-apply:disabled{opacity:.45;cursor:not-allowed}
.jd-save{padding:13px 18px;background:var(--s3);border:1.5px solid var(--border2);border-radius:var(--r3);color:var(--text);font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:7px}
.jd-save:hover,.jd-save.saved{border-color:var(--gold);color:var(--gold)}
.sim-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
.sim-card{background:var(--s3);border:1px solid var(--border);border-radius:var(--r3);padding:12px;cursor:pointer;transition:.18s}
.sim-card:hover{border-color:var(--a1)}
.sim-title{font-size:.82rem;font-weight:700;margin-bottom:3px}
.sim-co{font-size:.72rem;color:var(--text2)}
.sim-sal{font-size:.78rem;color:var(--a3);font-family:var(--fm);font-weight:700;margin-top:5px}

/* ── APPLY MODAL ──────────────────────────────────────────── */
.apply-title{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:var(--r3);padding:11px 14px;margin-bottom:18px;font-weight:700;font-size:.86rem}
.sal-hint{font-size:.75rem;color:var(--text3)}

/* ── DASHBOARD ────────────────────────────────────────────── */
.dash-ov .modal{max-width:700px}
.dtabs{display:flex;gap:2px;background:var(--s3);border-radius:var(--r3);padding:4px;margin-bottom:22px}
.dtab{flex:1;background:transparent;border:none;padding:8px 14px;border-radius:var(--r3);font-size:.8rem;color:var(--text2);font-weight:600;cursor:pointer;transition:.18s}
.dtab.active{background:var(--border2);color:var(--text)}
.app-item{background:var(--s3);border:1px solid var(--border);border-radius:var(--r3);padding:14px;margin-bottom:10px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:.18s}
.app-item:hover{border-color:var(--a1)}
.app-logo{width:38px;height:38px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;color:#fff}
.app-inf{flex:1;min-width:0}
.app-t{font-size:.85rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.app-c{font-size:.73rem;color:var(--text2)}
.app-d{font-size:.7rem;color:var(--text3);font-family:var(--fm)}
.sbadge{padding:4px 10px;border-radius:100px;font-size:.67rem;font-weight:800;letter-spacing:.3px;white-space:nowrap}
.sSubmitted{background:rgba(59,130,246,.15);color:#60a5fa}
.sViewed{background:rgba(100,116,139,.15);color:#94a3b8}
.sShortlisted{background:rgba(139,92,246,.15);color:#a78bfa}
.sAssessment{background:rgba(249,115,22,.15);color:#fb923c}
.sInterview{background:rgba(16,185,129,.15);color:#34d399}
.sOffer{background:rgba(16,185,129,.25);color:#10b981}
.sHired{background:rgba(16,185,129,.35);color:#059669}
.sRejected{background:rgba(239,68,68,.12);color:#f87171}
.sWithdrawn{background:rgba(100,116,139,.1);color:#64748b}
.empty{text-align:center;padding:36px;color:var(--text3)}
.empty-icon{font-size:2.2rem;margin-bottom:10px}

/* ── PROFILE FORM ─────────────────────────────────────────── */
.resume-zone{background:var(--s3);border:2px dashed var(--border2);border-radius:var(--r2);padding:20px;text-align:center;cursor:pointer;transition:.22s}
.resume-zone:hover{border-color:var(--a1)}
.resume-name{font-size:.8rem;color:var(--a3);font-family:var(--fm);margin-top:8px}

/* ── NOTIFICATION PANEL ───────────────────────────────────── */
.notif-ov .modal{max-width:420px}
.notif-item{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;cursor:pointer;transition:.15s}
.notif-item:hover{background:var(--s3)}
.notif-item.unread{background:rgba(59,130,246,.04)}
.notif-icon{font-size:1.2rem;flex-shrink:0;margin-top:2px}
.notif-title{font-size:.83rem;font-weight:700;margin-bottom:2px}
.notif-body{font-size:.75rem;color:var(--text2);line-height:1.5}
.notif-time{font-size:.68rem;color:var(--text3);font-family:var(--fm);margin-top:4px}

/* ── ALERT SETUP ──────────────────────────────────────────── */
.alert-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:100px;padding:4px 12px;font-size:.74rem;color:var(--a3);margin-bottom:16px}

/* ── TOAST ────────────────────────────────────────────────── */
#toast{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.ti{background:var(--s2);border:1px solid var(--border2);border-radius:var(--r3);padding:12px 18px;font-size:.82rem;max-width:320px;opacity:0;transform:translateY(10px);transition:.28s;box-shadow:var(--sh)}
.ti.show{opacity:1;transform:none}
.ti.success{border-left:3px solid var(--a3)}
.ti.error{border-left:3px solid var(--red)}
.ti.info{border-left:3px solid var(--a1)}
.ti.warn{border-left:3px solid var(--gold)}

/* ── LOADER ───────────────────────────────────────────────── */
.spin{display:inline-block;width:18px;height:18px;border:2px solid var(--border2);border-top-color:var(--a1);border-radius:50%;animation:sp .65s linear infinite}
.loading-row{display:flex;justify-content:center;align-items:center;padding:56px;color:var(--text2);gap:12px}
.loading-row .spin{width:28px;height:28px}

/* ── FOOTER ───────────────────────────────────────────────── */
footer{background:var(--s1);border-top:1px solid var(--border);padding:32px 0;text-align:center}
.foot-logo{font-size:1.1rem;font-weight:800;margin-bottom:8px}
.foot-logo span{color:var(--a1)}
footer p{color:var(--text3);font-size:.78rem}

/* ── ANIMATIONS ───────────────────────────────────────────── */
@keyframes up{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
@keyframes sp{to{transform:rotate(360deg)}}

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media(max-width:720px){
  .search-box{flex-direction:column}
  .search-box select,.search-box .sdiv:not(:first-of-type){display:none}
  .search-btn{border-radius:0 0 var(--r) var(--r);width:100%}
  .stats-row{grid-template-columns:1fr 1fr}
  .jgrid{grid-template-columns:1fr}
  .frow{grid-template-columns:1fr}
  .jd-acts{flex-direction:column}
  .sim-grid{grid-template-columns:1fr}
  .filter-bar{gap:7px}
}
</style>
</head>
<body>

<!-- NAV ──────────────────────────────────────────────────────── -->
<nav>
<div class="wrap nav-i">
  <div class="logo">Talent<span class="hi">Bridge</span> <span class="flag">🇮🇳</span></div>
  <div class="nav-r">
    <button class="nb ghost" onclick="scrollTo2('cat-sec')">Categories</button>
    <button class="nb ghost" onclick="scrollTo2('jobs-sec')">Jobs</button>

    <span id="g-nav">
      <button class="nb out" onclick="openAuth('login')">Sign In</button>
      <button class="nb pri" onclick="openAuth('register')">Join Free</button>
    </span>
    <span id="u-nav" style="display:none;align-items:center;gap:8px">
      <button class="notif-btn" id="notif-btn" onclick="openNotifications()" title="Notifications">
        🔔<span class="notif-dot" id="notif-dot" style="display:none"></span>
      </button>
      <div class="avatar" id="nav-av">U</div>
      <span id="nav-name" style="font-size:.8rem;font-weight:600"></span>
      <button class="nb ghost" onclick="openDash('apps')">My Applications</button>
      <button class="nb ghost" onclick="openDash('profile')">Profile</button>
      <button class="nb ghost" onclick="doLogout()">Sign Out</button>
    </span>
  </div>
</div>
</nav>

<!-- HERO ──────────────────────────────────────────────────────── -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid"></div>
  <div class="wrap hero-content">
    <div class="hero-chip">🚀 <span id="hero-tag">India's Smartest Job Portal</span></div>
    <h1>Your Dream Career<br>Starts in <span class="g">Bharat</span></h1>
    <p class="hero-sub">Thousands of verified jobs across India — in IT, Finance, Healthcare, Engineering & more.</p>

    <div class="search-wrap">
      <div class="search-box">
        <input type="text" id="s-q" placeholder="Job title, skill, or company…" autocomplete="off"
               oninput="onSearchInput()" onkeydown="if(event.key==='Enter')doSearch()"/>
        <div class="sdiv"></div>
        <select id="s-cat" onchange="doSearch()">
          <option value="">All Categories</option>
        </select>
        <div class="sdiv"></div>
        <input type="text" id="s-city" list="city-list" placeholder="📍 City / State"
               style="max-width:160px" oninput="deb(doSearch,400)" autocomplete="off"/>
        <datalist id="city-list">
          <option>Mumbai</option><option>Bengaluru</option><option>Delhi</option>
          <option>Hyderabad</option><option>Chennai</option><option>Pune</option>
          <option>Kolkata</option><option>Noida</option><option>Gurugram</option>
          <option>Ahmedabad</option><option>Jaipur</option><option>Kochi</option>
        </datalist>
        <button class="search-btn" onclick="doSearch()">🔍 Search</button>
      </div>
      <div class="suggest-box" id="suggest-box"></div>
    </div>

    <div class="popular">
      <span class="ptag" onclick="quickSearch('React Developer')">React Developer</span>
      <span class="ptag" onclick="quickSearch('Data Analyst')">Data Analyst</span>
      <span class="ptag" onclick="quickSearch('Product Manager')">Product Manager</span>
      <span class="ptag" onclick="quickSearch('Java Developer')">Java Developer</span>
      <span class="ptag" onclick="quickSearch('Digital Marketing')">Digital Marketing</span>
      <span class="ptag" onclick="quickSearch('HR Manager')">HR Manager</span>
      <span class="ptag" onclick="quickSearch('Python')">Python</span>
      <span class="ptag" onclick="quickSearch('CA Fresher')">CA Fresher</span>
    </div>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-n" id="st-jobs">—</div><div class="stat-l">Open Jobs</div></div>
      <div class="stat-card"><div class="stat-n" id="st-comp">—</div><div class="stat-l">Top Companies</div></div>
      <div class="stat-card"><div class="stat-n" id="st-app">—</div><div class="stat-l">Applications</div></div>
      <div class="stat-card"><div class="stat-n" id="st-city">—</div><div class="stat-l">Cities</div></div>
    </div>
  </div>
</section>

<!-- CATEGORIES ─────────────────────────────────────────────── -->
<section id="cat-sec" style="padding-top:0">
<div class="wrap">
  <div class="sh">
    <div class="sl">Browse by Domain</div>
    <div class="st">Job Categories</div>
    <div class="ss">Click a category to filter instantly</div>
  </div>
  <div class="cat-grid" id="cat-grid"></div>
</div>
</section>

<!-- JOBS ───────────────────────────────────────────────────── -->
<section id="jobs-sec">
<div class="wrap">
  <div class="sh">
    <div class="sl">Latest Opportunities</div>
    <div class="st">Open Positions</div>
  </div>

  <div class="filter-bar">
    <div class="fg"><span class="flabel">Type</span>
      <select class="fs" id="f-type" onchange="doSearch()">
        <option value="">All</option>
        <option>Full-time</option><option>Part-time</option><option>Contract</option>
        <option>Internship</option><option>Remote</option><option>Hybrid</option><option>Freelance</option>
      </select>
    </div>
    <div class="fg"><span class="flabel">Level</span>
      <select class="fs" id="f-exp" onchange="doSearch()">
        <option value="">All</option>
        <option>Fresher</option><option>0-1 yr</option><option>1-3 yrs</option>
        <option>3-5 yrs</option><option>5-8 yrs</option><option>8-12 yrs</option><option>12+ yrs</option>
      </select>
    </div>
    <div class="fg"><span class="flabel">Mode</span>
      <select class="fs" id="f-mode" onchange="doSearch()">
        <option value="">All</option>
        <option>On-site</option><option>Remote</option><option>Hybrid</option>
      </select>
    </div>
    <div class="fg">
      <span class="flabel">Min Salary</span>
      <div class="salary-row">
        <input type="range" id="f-sal" min="0" max="5000000" step="100000" value="0"
               oninput="updateSalLabel();deb(doSearch,400)"/>
        <span class="salary-val" id="sal-val">Any</span>
      </div>
    </div>
    <div class="fg"><span class="flabel">Sort</span>
      <select class="fs" id="f-sort" onchange="doSearch()">
        <option value="posted_at">Newest</option>
        <option value="salary">Salary ↑</option>
        <option value="applications_count">Popular</option>
      </select>
    </div>
    <div class="fspace"></div>
    <button class="nb ghost" onclick="openAlertModal()" style="font-size:.78rem">🔔 Set Alert</button>
    <button class="clr-btn" onclick="clearFilters()">✕ Clear</button>
  </div>

  <div class="jobs-header">
    <div class="jcount" id="jcount">Loading…</div>
  </div>
  <div class="jgrid" id="jgrid"><div class="loading-row"><div class="spin"></div>Fetching jobs…</div></div>
  <div class="pag" id="pag"></div>
</div>
</section>

<!-- FOOTER ──────────────────────────────────────────────────── -->
<footer>
  <div class="foot-logo">Talent<span>Bridge</span> 🇮🇳</div>
  <p>© 2025 TalentBridge India · Built with PHP 8 & MySQL · Made for Bharat</p>
</footer>

<!-- TOAST ───────────────────────────────────────────────────── -->
<div id="toast"></div>

<!-- ══ AUTH MODAL ═══════════════════════════════════════════ -->
<div class="ov" id="ov-auth" onclick="cbg(event,'ov-auth')">
<div class="modal" style="max-width:480px">
  <div class="mh"><div class="mt" id="auth-title">Sign In</div><button class="xbtn" onclick="co('ov-auth')">✕</button></div>
  <div class="mb">
    <div class="mtabs">
      <button class="mtab active" id="tab-li" onclick="swAuth('login')">Sign In</button>
      <button class="mtab" id="tab-reg" onclick="swAuth('register')">Create Account</button>
    </div>
    <!-- Login -->
    <div id="form-li">
      <div class="fg2"><label class="fl">Email Address</label>
        <input class="fc" type="email" id="li-e" placeholder="you@example.com" onkeydown="kenter(event,'doLogin')"/></div>
      <div class="fg2"><label class="fl">Password</label>
        <input class="fc" type="password" id="li-p" placeholder="Your password" onkeydown="kenter(event,'doLogin')"/></div>
      <div class="ferr" id="li-err"></div>
      <button class="fbtn pri" onclick="doLogin()">Sign In →</button>
    </div>
    <!-- Register -->
    <div id="form-reg" style="display:none">
      <div class="frow">
        <div class="fg2"><label class="fl">Full Name *</label>
          <input class="fc" id="r-name" placeholder="Rahul Sharma"/></div>
        <div class="fg2"><label class="fl">Mobile Number *</label>
          <input class="fc" id="r-ph" type="tel" placeholder="98765 43210" maxlength="10"/></div>
      </div>
      <div class="fg2"><label class="fl">Email Address *</label>
        <input class="fc" type="email" id="r-em" placeholder="rahul@example.com"/></div>
      <div class="frow">
        <div class="fg2"><label class="fl">Password * <span class="fhint">(min 8 chars)</span></label>
          <input class="fc" type="password" id="r-pw" placeholder="Strong password"/></div>
        <div class="fg2"><label class="fl">City</label>
          <input class="fc" id="r-city" placeholder="Mumbai" list="city-list"/></div>
      </div>
      <div class="fg2"><label class="fl">State</label>
        <select class="fc" id="r-state"><option value="">Select State</option></select></div>
      <div class="ferr" id="r-err"></div>
      <button class="fbtn pri" onclick="doRegister()">Create Free Account →</button>
      <div class="fhint" style="margin-top:10px;text-align:center">By registering you agree to our Terms & Privacy Policy</div>
    </div>
  </div>
</div>
</div>

<!-- ══ JOB DETAIL MODAL ════════════════════════════════════ -->
<div class="ov jd-ov" id="ov-jd" onclick="cbg(event,'ov-jd')">
<div class="modal">
  <div class="mh"><div class="mt">Job Details</div><button class="xbtn" onclick="co('ov-jd')">✕</button></div>
  <div class="mb" id="jd-body"><div class="loading-row"><div class="spin"></div>Loading…</div></div>
</div>
</div>

<!-- ══ APPLY MODAL ══════════════════════════════════════════ -->
<div class="ov" id="ov-apply" onclick="cbg(event,'ov-apply')">
<div class="modal" style="max-width:520px">
  <div class="mh"><div class="mt">Apply for Position</div><button class="xbtn" onclick="co('ov-apply')">✕</button></div>
  <div class="mb">
    <div class="apply-title" id="apply-jt">Position</div>
    <div class="fg2"><label class="fl">Cover Letter <span class="fhint">(recommended)</span></label>
      <textarea class="fc" id="cl" placeholder="Tell the recruiter why you are a great fit. Include your key achievements, relevant experience, and why this role excites you…" style="min-height:130px"></textarea></div>
    <div class="frow">
      <div class="fg2"><label class="fl">Expected CTC (₹ / year)</label>
        <input class="fc" id="exp-sal" type="number" min="0" placeholder="e.g. 1200000"/>
        <div class="fhint" id="exp-sal-hint"></div>
      </div>
      <div class="fg2"><label class="fl">Notice Period (days)</label>
        <select class="fc" id="np">
          <option value="0">Immediate</option>
          <option value="15">15 days</option>
          <option value="30">30 days</option>
          <option value="45">45 days</option>
          <option value="60">60 days</option>
          <option value="90">90 days</option>
        </select>
      </div>
    </div>
    <div class="ferr" id="apply-err"></div>
    <div class="fsuc" id="apply-suc"></div>
    <button class="fbtn suc" id="apply-btn" onclick="submitApp()">🚀 Submit Application</button>
  </div>
</div>
</div>

<!-- ══ DASHBOARD MODAL ══════════════════════════════════════ -->
<div class="ov dash-ov" id="ov-dash" onclick="cbg(event,'ov-dash')">
<div class="modal" style="max-width:700px">
  <div class="mh"><div class="mt">My Dashboard</div><button class="xbtn" onclick="co('ov-dash')">✕</button></div>
  <div class="mb">
    <div class="dtabs">
      <button class="dtab active" id="dt-apps" onclick="swDash('apps')">📋 Applications</button>
      <button class="dtab" id="dt-saved" onclick="swDash('saved')">★ Saved Jobs</button>
      <button class="dtab" id="dt-profile" onclick="swDash('profile')">👤 Profile</button>
    </div>
    <div id="dash-content"><div class="loading-row"><div class="spin"></div></div></div>
  </div>
</div>
</div>

<!-- ══ NOTIFICATIONS MODAL ══════════════════════════════════ -->
<div class="ov notif-ov" id="ov-notif" onclick="cbg(event,'ov-notif')">
<div class="modal" style="max-width:420px">
  <div class="mh"><div class="mt">Notifications</div><button class="xbtn" onclick="co('ov-notif')">✕</button></div>
  <div class="mb" id="notif-body" style="padding:0 0 16px"><div class="loading-row"><div class="spin"></div></div></div>
</div>
</div>

<!-- ══ JOB ALERT MODAL ══════════════════════════════════════ -->
<div class="ov" id="ov-alert" onclick="cbg(event,'ov-alert')">
<div class="modal" style="max-width:460px">
  <div class="mh"><div class="mt">Set Job Alert</div><button class="xbtn" onclick="co('ov-alert')">✕</button></div>
  <div class="mb">
    <div class="alert-chip">🔔 Get matching jobs delivered to you</div>
    <div class="fg2"><label class="fl">Keywords</label>
      <input class="fc" id="al-kw" placeholder="e.g. React Developer, Data Analyst"/></div>
    <div class="frow">
      <div class="fg2"><label class="fl">Category</label>
        <select class="fc" id="al-cat"><option value="">Any</option></select></div>
      <div class="fg2"><label class="fl">City</label>
        <input class="fc" id="al-city" placeholder="e.g. Bengaluru" list="city-list"/></div>
    </div>
    <div class="fg2"><label class="fl">Frequency</label>
      <select class="fc" id="al-freq">
        <option>Instant</option><option selected>Daily</option><option>Weekly</option>
      </select>
    </div>
    <div class="ferr" id="al-err"></div>
    <button class="fbtn pri" onclick="saveAlert()">🔔 Activate Alert</button>
  </div>
</div>
</div>

<!-- ════════════════════════════ JAVASCRIPT ══════════════════════ -->
<script>
'use strict';
let CSRF = '';
let isLogged = false;
let activeCat = 0;
let curPage = 1;
let curJobId = null;
let debTimer = null;
const INR_LABELS = ['Any','₹1L','₹2L','₹3L','₹4L','₹5L','₹6L','₹7L','₹8L','₹9L','₹10L',
                    '₹12L','₹15L','₹18L','₹20L','₹25L','₹30L','₹35L','₹40L','₹50L'];

// ── Core helpers ────────────────────────────────────────────────
async function api(data, withFile = false) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) if(v !== null && v !== undefined) fd.append(k, v);
  if (CSRF) fd.append('_csrf', CSRF);
  const r = await fetch('', {method:'POST', body: fd});
  return r.json();
}
async function apiFile(data, fileInput) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) fd.append(k, v);
  if (fileInput?.files[0]) fd.append('resume', fileInput.files[0]);
  if (CSRF) fd.append('_csrf', CSRF);
  const r = await fetch('', {method:'POST', body: fd});
  return r.json();
}

function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const el = document.createElement('div');
  el.className = `ti ${type}`;
  el.textContent = msg;
  t.appendChild(el);
  setTimeout(() => el.classList.add('show'), 10);
  setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 3800);
}
function oo(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function co(id)  { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function cbg(e,id){ if(e.target.id===id) co(id); }
function kenter(e,fn){ if(e.key==='Enter') window[fn](); }
function deb(fn,ms){ clearTimeout(debTimer); debTimer=setTimeout(fn,ms); }
function scrollTo2(id){ document.getElementById(id).scrollIntoView({behavior:'smooth'}); }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function inrFmt(n){ if(!n) return ''; if(n>=1e7) return '₹'+Math.round(n/1e7*10)/10+' Cr'; if(n>=1e5) return '₹'+Math.round(n/1e5*10)/10+'L'; return '₹'+n.toLocaleString('en-IN'); }

// ── Salary slider ───────────────────────────────────────────────
const SAL_STEPS = [0,100000,200000,300000,400000,500000,600000,700000,800000,900000,
                   1000000,1200000,1500000,1800000,2000000,2500000,3000000,3500000,4000000,5000000];
function updateSalLabel() {
  const v = +document.getElementById('f-sal').value;
  const idx = SAL_STEPS.findIndex(s=>s>=v) || 0;
  const lbl = v===0?'Any':inrFmt(v)+'+';
  document.getElementById('sal-val').textContent = lbl;
}

// ── Auth ────────────────────────────────────────────────────────
function openAuth(tab='login') { swAuth(tab); oo('ov-auth'); }
function swAuth(t) {
  document.getElementById('form-li').style.display  = t==='login'?'':'none';
  document.getElementById('form-reg').style.display = t==='register'?'':'none';
  document.getElementById('tab-li').classList.toggle('active', t==='login');
  document.getElementById('tab-reg').classList.toggle('active', t==='register');
  document.getElementById('auth-title').textContent = t==='login'?'Sign In':'Create Free Account';
}

async function doLogin() {
  const email = document.getElementById('li-e').value.trim();
  const pass  = document.getElementById('li-p').value;
  document.getElementById('li-err').textContent = '';
  if (!email||!pass) { document.getElementById('li-err').textContent='Fill all fields.'; return; }
  const r = await api({action:'login', email, password:pass});
  if (!r.ok) { document.getElementById('li-err').textContent = r.msg; return; }
  setLoggedIn(r.name); co('ov-auth');
  toast('Welcome back, '+r.name+'! 👋','success'); loadJobs(); checkNotifs();
}

async function doRegister() {
  const full_name = document.getElementById('r-name').value.trim();
  const email     = document.getElementById('r-em').value.trim();
  const password  = document.getElementById('r-pw').value;
  const phone     = document.getElementById('r-ph').value.replace(/\s/g,'');
  const city      = document.getElementById('r-city').value.trim();
  const state     = document.getElementById('r-state').value;
  document.getElementById('r-err').textContent='';
  if(!full_name||!email||!password){document.getElementById('r-err').textContent='Fill required fields.';return;}
  const r = await api({action:'register',full_name,email,password,phone,city,state});
  if(!r.ok){document.getElementById('r-err').textContent=r.msg;return;}
  setLoggedIn(r.name); co('ov-auth');
  toast('Welcome to TalentBridge, '+r.name+'! 🎉','success'); loadJobs();
}

async function doLogout() {
  await api({action:'logout'});
  document.getElementById('g-nav').style.display='';
  document.getElementById('u-nav').style.display='none';
  isLogged=false; toast('Signed out.','info'); loadJobs();
}

function setLoggedIn(name) {
  isLogged=true;
  document.getElementById('g-nav').style.display='none';
  document.getElementById('u-nav').style.display='flex';
  document.getElementById('nav-av').textContent=name.charAt(0).toUpperCase();
  document.getElementById('nav-name').textContent=name;
}

// ── Categories ──────────────────────────────────────────────────
async function loadCats() {
  const r = await api({action:'categories'});
  const sel = document.getElementById('s-cat');
  const alSel = document.getElementById('al-cat');
  const grid = document.getElementById('cat-grid');
  grid.innerHTML='';
  for (const c of r.categories) {
    const o1 = new Option(c.name, c.id); sel.appendChild(o1);
    const o2 = new Option(c.name, c.id); alSel.appendChild(o2);
    const d = document.createElement('div');
    d.className='cc'; d.dataset.id=c.id;
    d.innerHTML=`<div class="cc-icon">${c.icon}</div><div class="cc-name">${esc(c.name)}</div><div class="cc-cnt">${c.job_count} jobs</div>`;
    d.onclick=()=>catFilter(c.id);
    grid.appendChild(d);
  }
}
function catFilter(id) {
  activeCat = activeCat===id?0:id;
  document.getElementById('s-cat').value = activeCat||'';
  document.querySelectorAll('.cc').forEach(c=>c.classList.toggle('active',+c.dataset.id===activeCat));
  curPage=1; loadJobs();
}

// ── Stats ────────────────────────────────────────────────────────
async function loadStats() {
  const r = await api({action:'stats'});
  document.getElementById('st-jobs').textContent = r.jobs;
  document.getElementById('st-comp').textContent = r.companies;
  document.getElementById('st-app').textContent  = r.applications;
  document.getElementById('st-city').textContent = r.cities;
  document.getElementById('hero-tag').textContent = r.jobs+' Open Jobs Across India';
}

// ── Job search ───────────────────────────────────────────────────
async function loadJobs(page=curPage) {
  curPage=page;
  const grid = document.getElementById('jgrid');
  grid.innerHTML='<div class="loading-row"><div class="spin"></div>Finding jobs…</div>';
  const r = await api({
    action:'fetch_jobs',
    search:   document.getElementById('s-q').value.trim(),
    category: document.getElementById('s-cat').value||activeCat,
    job_type: document.getElementById('f-type').value,
    experience:document.getElementById('f-exp').value,
    city:     document.getElementById('s-city').value.trim(),
    work_mode:document.getElementById('f-mode').value,
    salary_min:document.getElementById('f-sal').value,
    sort:     document.getElementById('f-sort').value,
    page
  });
  const jobs = r.jobs||[];
  const total = r.total||0;
  document.getElementById('jcount').innerHTML = `Showing <strong>${jobs.length}</strong> of <strong>${total}</strong> job${total!==1?'s':''}`;
  if(!jobs.length){
    grid.innerHTML=`<div class="empty" style="grid-column:1/-1"><div class="empty-icon">🔍</div><p>No jobs match your filters. Try broadening your search.</p></div>`;
  } else {
    grid.innerHTML = jobs.map(jobCard).join('');
  }
  renderPag(r.pages||1, page);
}

function doSearch() { curPage=1; loadJobs(1); }

function quickSearch(q) {
  document.getElementById('s-q').value=q;
  doSearch();
  scrollTo2('jobs-sec');
}

function clearFilters() {
  ['s-q','s-city'].forEach(id=>document.getElementById(id).value='');
  ['s-cat','f-type','f-exp','f-mode','f-sort'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('f-sal').value=0; updateSalLabel();
  activeCat=0; document.querySelectorAll('.cc').forEach(c=>c.classList.remove('active'));
  doSearch();
}

// ── Auto-suggest ─────────────────────────────────────────────────
let sugTimer=null;
function onSearchInput() {
  clearTimeout(sugTimer);
  const q = document.getElementById('s-q').value.trim();
  if(q.length<2){closeSuggest();deb(doSearch,400);return;}
  sugTimer=setTimeout(async()=>{
    const r=await api({action:'search_suggest',q});
    showSuggestions(r.suggestions||[]);
    deb(doSearch,600);
  },280);
}
function showSuggestions(list) {
  const box=document.getElementById('suggest-box');
  if(!list.length){closeSuggest();return;}
  box.innerHTML=list.map(s=>`<div class="suggest-item" onclick="pickSuggest('${esc(s.text)}')">
    <span>${esc(s.text)}</span>
    <span class="suggest-type ${s.type}">${s.type}</span>
  </div>`).join('');
  box.classList.add('open');
}
function pickSuggest(text){document.getElementById('s-q').value=text;closeSuggest();doSearch();}
function closeSuggest(){document.getElementById('suggest-box').classList.remove('open');}
document.addEventListener('click',e=>{if(!e.target.closest('.search-wrap'))closeSuggest();});

// ── Pagination ────────────────────────────────────────────────────
function renderPag(total, cur) {
  const el=document.getElementById('pag');
  if(total<=1){el.innerHTML='';return;}
  let h='';
  if(cur>1)h+=`<button class="pag-btn" onclick="loadJobs(${cur-1})">‹</button>`;
  for(let i=1;i<=total;i++){
    if(i===1||i===total||Math.abs(i-cur)<=2)
      h+=`<button class="pag-btn ${i===cur?'active':''}" onclick="loadJobs(${i})">${i}</button>`;
    else if(Math.abs(i-cur)===3) h+=`<span style="color:var(--text3);padding:0 4px">…</span>`;
  }
  if(cur<total)h+=`<button class="pag-btn" onclick="loadJobs(${cur+1})">›</button>`;
  el.innerHTML=h;
}

// ── Job card renderer ─────────────────────────────────────────────
function jobCard(j) {
  const skills=(j.skills_required||'').split(',').slice(0,4).map(s=>`<span class="stag">${esc(s.trim())}</span>`).join('');
  return `
<div class="jcard ${j.is_featured?'featured':''}" onclick="openJD(${j.id})">
  ${j.is_featured?'<div style="position:absolute;top:12px;right:12px"><span class="badge bfeat">⭐ Featured</span></div>':''}
  ${j.is_urgent?'<div style="position:absolute;top:${j.is_featured?36:12}px;right:12px"><span class="badge burg">🔥 Urgent</span></div>':''}
  <div class="jcard-top">
    <div class="clogo" style="background:${esc(j.logo_color)}">${j.company_name.charAt(0)}</div>
    <div style="flex:1;min-width:0">
      <div class="jtitle">${esc(j.title)}</div>
      <div class="jcomp">${esc(j.company_name)} · ${esc(j.industry)}
        ${j.company_verified?'<span class="vbadge" title="Verified Company">✓</span>':''}
      </div>
    </div>
    <button class="sav ${j.saved?'saved':''}" onclick="event.stopPropagation();toggleSave(this,${j.id})" title="Save">${j.saved?'★':'☆'}</button>
  </div>
  <div class="badges">
    <span class="badge bt">${esc(j.job_type)}</span>
    <span class="badge be">${esc(j.experience)}</span>
    <span class="badge bc">${j.category_icon} ${esc(j.category_name)}</span>
    ${j.work_mode==='Remote'||j.work_mode==='Hybrid'?`<span class="badge bm">🌐 ${esc(j.work_mode)}</span>`:''}
    ${j.applied?'<span class="badge ba">✓ Applied</span>':''}
    ${j.vacancies>1?`<span class="badge bc">${j.vacancies} openings</span>`:''}
  </div>
  <div class="sal">${esc(j.salary_label)}</div>
  <div class="skills">${skills}</div>
  <div class="jfoot">
    <span class="jloc">📍 ${esc(j.city||j.location||'India')}</span>
    <div class="jmeta">
      ${j.applications_count>0?`<span class="jago">${j.applications_count} applied</span>`:''}
      <span class="jago">${esc(j.posted_ago)}</span>
    </div>
  </div>
</div>`;
}

// ── Save toggle ───────────────────────────────────────────────────
async function toggleSave(btn, jid) {
  if(!isLogged){openAuth('login');return;}
  const r=await api({action:'toggle_save',job_id:jid});
  if(!r.ok){toast(r.msg,'error');return;}
  btn.textContent=r.saved?'★':'☆';
  btn.classList.toggle('saved',r.saved);
  toast(r.saved?'Job saved ★':'Removed from saved','info');
}

// ── Job Detail ────────────────────────────────────────────────────
async function openJD(id) {
  curJobId=id;
  document.getElementById('jd-body').innerHTML='<div class="loading-row"><div class="spin"></div>Loading details…</div>';
  oo('ov-jd');
  const r=await api({action:'job_detail',job_id:id});
  if(!r.ok){document.getElementById('jd-body').innerHTML=`<p style="color:var(--red)">${r.msg}</p>`;return;}
  const j=r.job;
  const skills=(j.skills_required||'').split(',').map(s=>`<span class="stag" style="font-size:.75rem;padding:3px 10px">${esc(s.trim())}</span>`).join('');
  const sim=(j.similar||[]).map(s=>`<div class="sim-card" onclick="openJD(${s.id})">
    <div style="display:flex;gap:8px;align-items:center">
      <div class="clogo" style="background:${esc(s.logo_color)};width:30px;height:30px;font-size:.75rem">${s.company_name.charAt(0)}</div>
      <div><div class="sim-title">${esc(s.title)}</div><div class="sim-co">${esc(s.company_name)}</div></div>
    </div>
    <div class="sim-sal">${esc(s.salary_label)}</div>
  </div>`).join('');

  document.getElementById('jd-body').innerHTML=`
  <div class="jd-top">
    <div class="jd-logo" style="background:${esc(j.logo_color)}">${j.company_name.charAt(0)}</div>
    <div>
      <div class="jd-title">${esc(j.title)}</div>
      <div class="jd-co">${esc(j.company_name)} · ${esc(j.industry)}
        ${j.company_verified?'<span style="color:var(--a3);font-size:.75rem">✓ Verified</span>':''}
      </div>
    </div>
  </div>
  <div class="jd-meta">
    <span class="badge bt">${esc(j.job_type)}</span>
    <span class="badge be">${esc(j.experience)}</span>
    <span class="badge bc">📍 ${esc(j.city||j.location||'India')}</span>
    <span class="badge bm">🌐 ${esc(j.work_mode)}</span>
    ${j.deadline?`<span class="badge ba">⏰ Closes ${new Date(j.deadline).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'})}</span>`:''}
    ${j.vacancies>1?`<span class="badge bc">${j.vacancies} openings</span>`:''}
  </div>
  <div class="jd-sec"><h4>💰 Compensation</h4>
    <p style="font-size:1.15rem;font-weight:800;color:var(--a3);font-family:var(--fm)">${esc(j.salary_label)}</p>
  </div>
  ${j.description?`<div class="jd-sec"><h4>📋 About the Role</h4><p>${esc(j.description)}</p></div>`:''}
  ${j.responsibilities?`<div class="jd-sec"><h4>✅ Responsibilities</h4><p>${esc(j.responsibilities)}</p></div>`:''}
  ${j.requirements?`<div class="jd-sec"><h4>🎓 Requirements</h4><p>${esc(j.requirements)}</p></div>`:''}
  ${j.skills_required?`<div class="jd-sec"><h4>🛠 Skills & Tools</h4><div class="skills" style="margin-top:4px">${skills}</div></div>`:''}
  ${j.co_desc?`<div class="jd-sec"><h4>🏢 About ${esc(j.company_name)}</h4>
    <p>${esc(j.co_desc)}</p>
    <div style="margin-top:8px;font-size:.78rem;color:var(--text3)">
      ${j.founded_year?`Founded ${j.founded_year} · `:''}
      ${j.employee_count?`${j.employee_count} employees · `:''}
      ${j.co_city&&j.co_state?`${j.co_city}, ${j.co_state} · `:''}
      ${j.website?`<a href="${esc(j.website)}" target="_blank" style="color:var(--a1)">${esc(j.website)}</a>`:''}
    </div>
  </div>`:''}
  ${sim?`<div class="jd-sec"><h4>💼 Similar Jobs</h4><div class="sim-grid">${sim}</div></div>`:''}
  <div class="jd-acts">
    <button class="jd-apply" id="jd-apply" onclick="openApply(${j.id},'${esc(j.title)} at ${esc(j.company_name)}')" ${j.applied?'disabled':''}>
      ${j.applied?'✓ Already Applied':'🚀 Apply Now'}
    </button>
    <button class="jd-save ${j.saved?'saved':''}" id="jd-save" onclick="jdSave(${j.id})">
      ${j.saved?'★ Saved':'☆ Save'}
    </button>
  </div>`;
}

async function jdSave(jid) {
  if(!isLogged){openAuth('login');return;}
  const r=await api({action:'toggle_save',job_id:jid});
  if(!r.ok){toast(r.msg,'error');return;}
  const b=document.getElementById('jd-save');
  b.textContent=r.saved?'★ Saved':'☆ Save';
  b.classList.toggle('saved',r.saved);
  toast(r.saved?'Saved ★':'Removed','info');
}

// ── Apply ─────────────────────────────────────────────────────────
function openApply(id, title) {
  if(!isLogged){co('ov-jd');openAuth('login');return;}
  curJobId=id;
  document.getElementById('apply-jt').textContent=title;
  document.getElementById('cl').value='';
  document.getElementById('apply-err').textContent='';
  document.getElementById('apply-suc').textContent='';
  document.getElementById('apply-btn').disabled=false;
  document.getElementById('apply-btn').textContent='🚀 Submit Application';
  // prefill expected salary from profile
  const es=document.getElementById('exp-sal');
  es.value='';
  oo('ov-apply');
}

async function submitApp() {
  const btn=document.getElementById('apply-btn');
  btn.disabled=true; btn.textContent='Submitting…';
  document.getElementById('apply-err').textContent='';
  const r=await api({
    action:'apply', job_id:curJobId,
    cover_letter:document.getElementById('cl').value.trim(),
    expected_salary:document.getElementById('exp-sal').value||0,
    notice_period:document.getElementById('np').value
  });
  if(!r.ok){document.getElementById('apply-err').textContent=r.msg;btn.disabled=false;btn.textContent='🚀 Submit Application';return;}
  document.getElementById('apply-suc').textContent=r.msg;
  btn.textContent='✓ Applied!';
  toast(r.msg,'success');
  setTimeout(()=>{co('ov-apply');loadJobs(curPage);loadStats();},1800);
}

// ── Dashboard ─────────────────────────────────────────────────────
let dashTab='apps';
async function openDash(tab='apps') {
  if(!isLogged){openAuth('login');return;}
  dashTab=tab;
  ['apps','saved','profile'].forEach(t=>{
    document.getElementById(`dt-${t}`).classList.toggle('active',t===tab);
  });
  oo('ov-dash');
  await loadDashTab(tab);
}
async function swDash(tab) {
  dashTab=tab;
  ['apps','saved','profile'].forEach(t=>{
    document.getElementById(`dt-${t}`).classList.toggle('active',t===tab);
  });
  await loadDashTab(tab);
}

async function loadDashTab(tab) {
  const el=document.getElementById('dash-content');
  el.innerHTML='<div class="loading-row"><div class="spin"></div></div>';
  if(tab==='apps') {
    const r=await api({action:'my_applications'});
    if(!r.applications?.length){el.innerHTML='<div class="empty"><div class="empty-icon">📋</div><p>No applications yet. Start applying!</p></div>';return;}
    el.innerHTML=`<p style="font-size:.78rem;color:var(--text3);margin-bottom:14px">${r.applications.length} application${r.applications.length>1?'s':''}</p>`+
      r.applications.map(a=>`
      <div class="app-item" onclick="openJD(${a.job_id})">
        <div class="app-logo" style="background:${esc(a.logo_color)}">${a.company_name.charAt(0)}</div>
        <div class="app-inf">
          <div class="app-t">${esc(a.title)}</div>
          <div class="app-c">${esc(a.company_name)} · ${esc(a.job_type)} · ${esc(a.location||'India')}</div>
          <div class="app-d">Applied ${new Date(a.applied_at).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'})}</div>
        </div>
        <span class="sbadge s${esc(a.status)}">${esc(a.status)}</span>
      </div>`).join('');
  }
  else if(tab==='saved') {
    const r=await api({action:'saved_jobs'});
    if(!r.jobs?.length){el.innerHTML='<div class="empty"><div class="empty-icon">★</div><p>No saved jobs. Bookmark interesting roles!</p></div>';return;}
    el.innerHTML=r.jobs.map(j=>`
    <div class="app-item" onclick="openJD(${j.id})">
      <div class="app-logo" style="background:${esc(j.logo_color)}">${j.company_name.charAt(0)}</div>
      <div class="app-inf">
        <div class="app-t">${esc(j.title)}</div>
        <div class="app-c">${esc(j.company_name)} · ${esc(j.category_name)}</div>
        <div class="app-d" style="color:var(--a3);font-weight:700">${esc(j.salary_label)}</div>
      </div>
      <div style="text-align:right">
        ${j.deadline?`<div class="app-d">Closes ${new Date(j.deadline).toLocaleDateString('en-IN',{day:'numeric',month:'short'})}</div>`:''}
        <button class="sav saved" onclick="event.stopPropagation();removeSaved(this,${j.id})" style="margin-top:4px">★</button>
      </div>
    </div>`).join('');
  }
  else if(tab==='profile') {
    const r=await api({action:'get_profile'});
    const u=r.profile||{};
    el.innerHTML=`
    <div class="fg2"><label class="fl">Full Name</label><input class="fc" id="p-name" value="${esc(u.full_name||'')}"/></div>
    <div class="frow">
      <div class="fg2"><label class="fl">Mobile</label><input class="fc" id="p-ph" value="${esc(u.phone||'')}" placeholder="10-digit mobile"/></div>
      <div class="fg2"><label class="fl">City</label><input class="fc" id="p-city" value="${esc(u.city||'')}" list="city-list"/></div>
    </div>
    <div class="fg2"><label class="fl">Headline <span class="fhint">(e.g. Senior React Developer | 5 yrs)</span></label>
      <input class="fc" id="p-hl" value="${esc(u.headline||'')}" placeholder="Your professional headline"/></div>
    <div class="fg2"><label class="fl">Skills <span class="fhint">(comma-separated)</span></label>
      <input class="fc" id="p-skills" value="${esc(u.skills||'')}" placeholder="React, Python, SQL…"/></div>
    <div class="frow">
      <div class="fg2"><label class="fl">Total Experience (yrs)</label>
        <input class="fc" id="p-exp" type="number" min="0" step="0.5" value="${u.total_experience||0}"/></div>
      <div class="fg2"><label class="fl">Notice Period (days)</label>
        <input class="fc" id="p-np" type="number" min="0" value="${u.notice_period||0}"/></div>
    </div>
    <div class="frow">
      <div class="fg2"><label class="fl">Current CTC (₹/yr)</label>
        <input class="fc" id="p-cur-sal" type="number" value="${u.current_salary||''}" placeholder="e.g. 1200000"/></div>
      <div class="fg2"><label class="fl">Expected CTC (₹/yr)</label>
        <input class="fc" id="p-exp-sal" type="number" value="${u.expected_salary||''}" placeholder="e.g. 1800000"/></div>
    </div>
    <div class="fg2"><label class="fl">LinkedIn URL</label>
      <input class="fc" id="p-li" value="${esc(u.linkedin_url||'')}" placeholder="https://linkedin.com/in/…"/></div>
    <div class="fg2"><label class="fl">GitHub / Portfolio</label>
      <input class="fc" id="p-gh" value="${esc(u.github_url||'')}" placeholder="https://github.com/…"/></div>
    <div class="ferr" id="p-err"></div>
    <button class="fbtn pri" onclick="saveProfile()">💾 Save Profile</button>
    <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--border)">
      <label class="fl">Resume / CV <span class="fhint">(PDF, DOC, DOCX · max 5MB)</span></label>
      ${u.resume_name?`<div class="resume-name">📄 ${esc(u.resume_name)}</div>`:''}
      <div class="resume-zone" onclick="document.getElementById('resume-fi').click()">
        <div style="font-size:1.8rem;margin-bottom:8px">📤</div>
        <div style="font-size:.82rem;color:var(--text2)">Click to upload or replace resume</div>
      </div>
      <input type="file" id="resume-fi" accept=".pdf,.doc,.docx" style="display:none" onchange="uploadResume()"/>
      <div class="ferr" id="res-err"></div>
    </div>`;
  }
}

async function saveProfile() {
  document.getElementById('p-err').textContent='';
  const data={action:'update_profile',
    full_name:document.getElementById('p-name').value,
    phone:document.getElementById('p-ph').value,
    city:document.getElementById('p-city').value,
    headline:document.getElementById('p-hl').value,
    skills:document.getElementById('p-skills').value,
    total_experience:document.getElementById('p-exp').value,
    notice_period:document.getElementById('p-np').value,
    current_salary:document.getElementById('p-cur-sal').value||null,
    expected_salary:document.getElementById('p-exp-sal').value||null,
    linkedin_url:document.getElementById('p-li').value,
    github_url:document.getElementById('p-gh').value
  };
  const r=await api(data);
  if(!r.ok){document.getElementById('p-err').textContent=r.msg;return;}
  toast('Profile saved! ✓','success');
  // Update nav name
  const newName=document.getElementById('p-name').value.trim();
  if(newName){document.getElementById('nav-name').textContent=newName;document.getElementById('nav-av').textContent=newName.charAt(0).toUpperCase();}
}

async function uploadResume() {
  const fi=document.getElementById('resume-fi');
  document.getElementById('res-err').textContent='Uploading…';
  const r=await apiFile({action:'upload_resume'},fi);
  if(!r.ok){document.getElementById('res-err').textContent=r.msg;return;}
  document.getElementById('res-err').textContent='';
  toast(r.msg,'success');
  // Refresh profile section
  await loadDashTab('profile');
}

async function removeSaved(btn,jid) {
  const r=await api({action:'toggle_save',job_id:jid});
  if(r.ok&&!r.saved){btn.closest('.app-item').remove();toast('Removed from saved','info');}
}

// ── Notifications ─────────────────────────────────────────────────
async function openNotifications() {
  oo('ov-notif');
  const r=await api({action:'notifications'});
  api({action:'mark_read'});
  document.getElementById('notif-dot').style.display='none';
  const body=document.getElementById('notif-body');
  if(!r.notifications?.length){body.innerHTML='<div class="empty" style="padding:28px"><div class="empty-icon">🔔</div><p>No notifications yet</p></div>';return;}
  const icons={application_update:'📋',new_job:'💼',interview:'📅',offer:'🎁',system:'ℹ️'};
  body.innerHTML=r.notifications.map(n=>`
  <div class="notif-item ${n.is_read?'':'unread'}">
    <div class="notif-icon">${icons[n.type]||'🔔'}</div>
    <div>
      <div class="notif-title">${esc(n.title)}</div>
      <div class="notif-body">${esc(n.body||'')}</div>
      <div class="notif-time">${new Date(n.created_at).toLocaleDateString('en-IN',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</div>
    </div>
  </div>`).join('');
}

async function checkNotifs() {
  const r=await fetch('?session_info=1').then(x=>x.json());
  if(r.unread>0) document.getElementById('notif-dot').style.display='block';
}

// ── Job Alert ─────────────────────────────────────────────────────
function openAlertModal() {
  if(!isLogged){openAuth('login');return;}
  oo('ov-alert');
}
async function saveAlert() {
  document.getElementById('al-err').textContent='';
  const r=await api({
    action:'set_alert',
    keywords:document.getElementById('al-kw').value,
    category_id:document.getElementById('al-cat').value||null,
    city:document.getElementById('al-city').value,
    frequency:document.getElementById('al-freq').value
  });
  if(!r.ok){document.getElementById('al-err').textContent=r.msg;return;}
  co('ov-alert'); toast(r.msg,'success');
}

// ── Indian States loader ──────────────────────────────────────────
async function loadStates() {
  const r=await api({action:'states'});
  const sel=document.getElementById('r-state');
  (r.states||[]).forEach(s=>{const o=new Option(s,s);sel.appendChild(o);});
}

// ── Init ───────────────────────────────────────────────────────────
(async()=>{
  // session check
  try {
    const s=await fetch('?session_info=1').then(x=>x.json());
    CSRF=s.csrf||'';
    if(s.logged){isLogged=true;setLoggedIn(s.name);}
    if(s.unread>0) document.getElementById('notif-dot').style.display='block';
  }catch(e){}
  updateSalLabel();
  await Promise.all([loadCats(),loadStats(),loadStates()]);
  loadJobs();
})();
</script>
</body>
</html>