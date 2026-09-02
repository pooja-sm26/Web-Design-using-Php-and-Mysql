<?php
// ============================================================
//  TeamPulse · Single-File Admin System (Production Grade)
//  SAVE THIS FILE AS ANY NAME: index.php, team.php, admin.php etc.
//  1. Run database.sql in phpMyAdmin first
//  2. Edit DB credentials below OR set environment variables
//  3. Upload to htdocs folder and open in browser
// ============================================================

// ── ENVIRONMENT ──────────────────────────────────────────────
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_VERSION', '2.1.0');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0750, true);
    ini_set('error_log', $log_dir . '/app.log');
}

// ── DB CONFIG (override with environment variables in production) ─
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);
$DB_NAME = getenv('DB_NAME') ?: 'teampulse';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';

// ── CONSTANTS ─────────────────────────────────────────────────
define('SESSION_LIFETIME',   7200);  // 2 hours
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SEC',  900);   // 15 minutes
define('PAGINATION_LIMIT',   25);
define('ACTIVITY_LOG_LIMIT', 50);

// ── BOOT ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// ── DB CONNECTION ─────────────────────────────────────────────
$pdo      = null;
$db_error = null;
$_stmt_cache = [];

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => true,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]
    );
} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log('[TeamPulse] DB connection failed: ' . $e->getMessage());
}

// ── HELPERS ───────────────────────────────────────────────────
function db(): ?PDO { global $pdo; return $pdo; }
function xe($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function q(string $sql, array $p = []): PDOStatement {
    global $_stmt_cache;
    $hash = md5($sql);
    if (!isset($_stmt_cache[$hash])) {
        $_stmt_cache[$hash] = db()->prepare($sql);
    }
    $_stmt_cache[$hash]->execute($p);
    return $_stmt_cache[$hash];
}

function one(string $sql, array $p = []): array|false { return q($sql, $p)->fetch(); }
function all(string $sql, array $p = []): array        { return q($sql, $p)->fetchAll(); }
function scalar(string $sql, array $p = [])            { return q($sql, $p)->fetchColumn(); }

// ── INPUT VALIDATION ──────────────────────────────────────────
function validate(array $rules, array $data): array {
    $errors = [];
    foreach ($rules as $field => [$label, $checks]) {
        $val = trim($data[$field] ?? '');
        foreach ($checks as $check) {
            if ($check === 'required' && $val === '') {
                $errors[] = "$label is required."; break;
            }
            if (str_starts_with($check, 'min:') && $val !== '' && strlen($val) < (int)substr($check, 4)) {
                $errors[] = "$label must be at least " . substr($check, 4) . " characters.";
            }
            if ($check === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "$label must be a valid email address.";
            }
            if ($check === 'numeric' && $val !== '' && !is_numeric($val)) {
                $errors[] = "$label must be a number.";
            }
            if (str_starts_with($check, 'max:') && strlen($val) > (int)substr($check, 4)) {
                $errors[] = "$label must be at most " . substr($check, 4) . " characters.";
            }
        }
    }
    return $errors;
}

// ── RATE LIMITING ─────────────────────────────────────────────
function get_rate_key(string $username): string {
    return 'ratelimit_' . md5($username . ($_SERVER['REMOTE_ADDR'] ?? ''));
}
function is_rate_limited(string $username): bool {
    $key      = get_rate_key($username);
    $attempts = $_SESSION[$key] ?? 0;
    $since    = $_SESSION[$key . '_since'] ?? 0;
    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        if ((time() - $since) < LOGIN_LOCKOUT_SEC) return true;
        clear_rate_limit($username);
    }
    return false;
}
function record_failed_attempt(string $username): void {
    $key = get_rate_key($username);
    if (empty($_SESSION[$key . '_since'])) $_SESSION[$key . '_since'] = time();
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
}
function clear_rate_limit(string $username): void {
    $key = get_rate_key($username);
    unset($_SESSION[$key], $_SESSION[$key . '_since']);
}
function rate_limit_remaining(string $username): int {
    $key   = get_rate_key($username);
    $since = $_SESSION[$key . '_since'] ?? time();
    return max(0, LOGIN_LOCKOUT_SEC - (time() - $since));
}

// ── SESSION MANAGEMENT ────────────────────────────────────────
function check_session_expiry(): void {
    if (!empty($_SESSION['admin'])) {
        $last = $_SESSION['last_active'] ?? time();
        if ((time() - $last) > SESSION_LIFETIME) {
            log_action('SESSION_EXPIRED');
            session_unset();
            session_destroy();
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
        $_SESSION['last_active'] = time();
    }
}
check_session_expiry();

// ── ACTIVITY LOG ──────────────────────────────────────────────
const LOGGABLE_ACTIONS = [
    'LOGIN', 'LOGOUT', 'REGISTER', 'SESSION_EXPIRED',
    'ADD_EMPLOYEE', 'UPDATE_EMPLOYEE', 'DELETE_EMPLOYEE',
    'ADD_DEPT', 'UPDATE_DEPT', 'DELETE_DEPT',
    'ADD_PROJECT', 'UPDATE_PROJECT', 'DELETE_PROJECT',
    'UPDATE_PROJECT_MEMBERS',
];
function log_action(string $action, string $details = ''): void {
    if (!in_array($action, LOGGABLE_ACTIONS, true)) return;
    $uid = $_SESSION['admin']['id'] ?? null;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        q("INSERT INTO activity_log(admin_id, action, details, ip_address) VALUES(?,?,?,?)",
          [$uid, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log('[TeamPulse] log_action failed: ' . $e->getMessage());
    }
}

// ── CSRF ──────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$t);
}

// ── API RESPONSE HELPERS ──────────────────────────────────────
function api_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}
function api_error(string $msg, int $http_code = 200): void {
    http_response_code($http_code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

$is_logged_in = !empty($_SESSION['admin']['id']) && $pdo !== null;

// ── JSON AJAX HANDLER ─────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    if ($db_error)      api_error('Database unavailable. Contact administrator.', 503);
    if (!$is_logged_in) api_error('Session expired. Please sign in again.', 401);
    if (!csrf_ok())     api_error('Security token mismatch. Refresh the page and try again.', 403);

    $action = trim($_POST['action'] ?? '');

    // ── EMPLOYEES ──────────────────────────────────────────────
    if ($action === 'emp_list') {
        $q    = '%' . trim($_POST['q'] ?? '') . '%';
        $d    = $_POST['dept']   ?? '';
        $s    = $_POST['status'] ?? '';
        $page = max(1, (int)($_POST['page'] ?? 1));
        $off  = ($page - 1) * PAGINATION_LIMIT;

        $where = "(e.name LIKE ? OR e.role LIKE ? OR e.email LIKE ? OR IFNULL(e.emp_code,'') LIKE ?)";
        $p     = [$q, $q, $q, $q];
        if ($d !== '') { $where .= " AND e.department_id=?"; $p[] = (int)$d; }
        if ($s !== '') { $where .= " AND e.status=?";        $p[] = $s; }

        $total = (int)scalar("SELECT COUNT(*) FROM employees e WHERE $where", $p);
        $rows  = all(
            "SELECT e.*, dp.name dept_name, dp.color dept_color
             FROM employees e
             LEFT JOIN departments dp ON e.department_id = dp.id
             WHERE $where
             ORDER BY e.created_at DESC
             LIMIT " . PAGINATION_LIMIT . " OFFSET $off",
            $p
        );
        api_ok(['data' => $rows, 'total' => $total, 'page' => $page,
                'pages' => (int)ceil($total / PAGINATION_LIMIT)]);
    }

    if ($action === 'emp_save') {
        $id = (int)($_POST['id'] ?? 0);

        $errors = validate([
            'name'  => ['Name',  ['required', 'max:120']],
            'role'  => ['Role',  ['required', 'max:120']],
            'email' => ['Email', ['required', 'email', 'max:180']],
        ], $_POST);
        if ($errors) api_error(implode(' ', $errors));

        $dup_sql = $id
            ? "SELECT id FROM employees WHERE email=? AND id<>?"
            : "SELECT id FROM employees WHERE email=?";
        $dup_p = $id ? [trim($_POST['email']), $id] : [trim($_POST['email'])];
        if (one($dup_sql, $dup_p)) api_error('That email address is already in use.');

        $vals = [
            trim($_POST['emp_code']   ?? '') ?: null,
            trim($_POST['name']),
            trim($_POST['role']),
            (int)($_POST['dept']      ?? 0) ?: null,
            strtolower(trim($_POST['email'])),
            trim($_POST['phone']      ?? '') ?: null,
            in_array($_POST['status'] ?? '', ['Active','Remote','On Leave','Resigned'])
                ? $_POST['status'] : 'Active',
            preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                ? $_POST['color'] : '#6C63FF',
            is_numeric($_POST['salary'] ?? '') ? (float)$_POST['salary'] : null,
            $_POST['joined'] ?? '' ?: null,
            trim($_POST['notes']  ?? '') ?: null,
        ];

        try {
            if ($id) {
                q("UPDATE employees SET emp_code=?,name=?,role=?,department_id=?,email=?,
                   phone=?,status=?,avatar_color=?,salary=?,joined=?,notes=?,
                   updated_at=NOW() WHERE id=?", [...$vals, $id]);
                log_action('UPDATE_EMPLOYEE', "id:$id name:{$vals[1]}");
            } else {
                q("INSERT INTO employees
                   (emp_code,name,role,department_id,email,phone,status,avatar_color,salary,joined,notes)
                   VALUES(?,?,?,?,?,?,?,?,?,?,?)", $vals);
                $id = (int)db()->lastInsertId();
                log_action('ADD_EMPLOYEE', "id:$id name:{$vals[1]}");
            }
            api_ok(['id' => $id]);
        } catch (PDOException $e) {
            error_log('[TeamPulse] emp_save: ' . $e->getMessage());
            api_error('Database error. Please try again.');
        }
    }

    if ($action === 'emp_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) api_error('Invalid employee ID.');
        if (!one("SELECT id FROM employees WHERE id=?", [$id])) api_error('Employee not found.');
        q("DELETE FROM employees WHERE id=?", [$id]);
        log_action('DELETE_EMPLOYEE', "id:$id");
        api_ok();
    }

    // ── DEPARTMENTS ────────────────────────────────────────────
    if ($action === 'dept_list') {
        $rows = all(
            "SELECT d.*, COUNT(e.id) cnt
             FROM departments d
             LEFT JOIN employees e ON e.department_id = d.id
             GROUP BY d.id
             ORDER BY d.name"
        );
        api_ok(['data' => $rows]);
    }

    if ($action === 'dept_save') {
        $id = (int)($_POST['id'] ?? 0);

        $errors = validate([
            'name' => ['Department name', ['required', 'max:80']],
        ], $_POST);
        if ($errors) api_error(implode(' ', $errors));

        $name  = trim($_POST['name']);
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6C63FF';

        $dup_sql = $id
            ? "SELECT id FROM departments WHERE name=? AND id<>?"
            : "SELECT id FROM departments WHERE name=?";
        $dup_p = $id ? [$name, $id] : [$name];
        if (one($dup_sql, $dup_p)) api_error('A department with that name already exists.');

        try {
            if ($id) {
                q("UPDATE departments SET name=?, color=?, updated_at=NOW() WHERE id=?", [$name, $color, $id]);
                log_action('UPDATE_DEPT', "id:$id name:$name");
            } else {
                q("INSERT INTO departments(name, color) VALUES(?,?)", [$name, $color]);
                $id = (int)db()->lastInsertId();
                log_action('ADD_DEPT', "id:$id name:$name");
            }
            api_ok(['id' => $id]);
        } catch (PDOException $e) {
            error_log('[TeamPulse] dept_save: ' . $e->getMessage());
            api_error('Database error. Please try again.');
        }
    }

    if ($action === 'dept_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) api_error('Invalid department ID.');
        if (!one("SELECT id FROM departments WHERE id=?", [$id])) api_error('Department not found.');
        q("UPDATE employees SET department_id=NULL, updated_at=NOW() WHERE department_id=?", [$id]);
        q("DELETE FROM departments WHERE id=?", [$id]);
        log_action('DELETE_DEPT', "id:$id");
        api_ok();
    }

    // ── PROJECTS ───────────────────────────────────────────────
    if ($action === 'proj_list') {
        $rows = all(
            "SELECT p.*, COUNT(pm.employee_id) mem_count
             FROM projects p
             LEFT JOIN project_members pm ON pm.project_id = p.id
             GROUP BY p.id
             ORDER BY p.created_at DESC"
        );
        api_ok(['data' => $rows]);
    }

    if ($action === 'proj_save') {
        $id = (int)($_POST['id'] ?? 0);

        $errors = validate([
            'name' => ['Project name', ['required', 'max:160']],
        ], $_POST);
        if ($errors) api_error(implode(' ', $errors));

        $name   = trim($_POST['name']);
        $desc   = trim($_POST['desc']   ?? '');
        $status = in_array($_POST['status'] ?? '', ['Active','Planning','On Hold','Completed'])
                    ? $_POST['status'] : 'Active';
        $start  = $_POST['start'] ?? '' ?: null;
        $end    = $_POST['end']   ?? '' ?: null;

        if ($start && $end && $end < $start) api_error('End date cannot be before start date.');

        try {
            $vals = [$name, $desc, $status, $start, $end];
            if ($id) {
                q("UPDATE projects SET name=?,description=?,status=?,start_date=?,end_date=?,updated_at=NOW() WHERE id=?",
                  [...$vals, $id]);
                log_action('UPDATE_PROJECT', "id:$id name:$name");
            } else {
                q("INSERT INTO projects(name,description,status,start_date,end_date) VALUES(?,?,?,?,?)", $vals);
                $id = (int)db()->lastInsertId();
                log_action('ADD_PROJECT', "id:$id name:$name");
            }
            api_ok(['id' => $id]);
        } catch (PDOException $e) {
            error_log('[TeamPulse] proj_save: ' . $e->getMessage());
            api_error('Database error. Please try again.');
        }
    }

    if ($action === 'proj_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) api_error('Invalid project ID.');
        if (!one("SELECT id FROM projects WHERE id=?", [$id])) api_error('Project not found.');
        q("DELETE FROM projects WHERE id=?", [$id]);
        log_action('DELETE_PROJECT', "id:$id");
        api_ok();
    }

    if ($action === 'proj_members_get') {
        $pid = (int)($_POST['pid'] ?? 0);
        if (!$pid) api_error('Invalid project ID.');
        $all_emp  = all("SELECT id, name, role, avatar_color FROM employees ORDER BY name");
        $assigned = q("SELECT employee_id FROM project_members WHERE project_id=?", [$pid])
                      ->fetchAll(PDO::FETCH_COLUMN);
        api_ok(['all' => $all_emp, 'assigned' => array_map('intval', $assigned)]);
    }

    if ($action === 'proj_members_save') {
        $pid = (int)($_POST['pid'] ?? 0);
        if (!$pid) api_error('Invalid project ID.');
        if (!one("SELECT id FROM projects WHERE id=?", [$pid])) api_error('Project not found.');

        $raw  = json_decode($_POST['eids'] ?? '[]', true);
        $eids = is_array($raw) ? array_map('intval', array_filter($raw, 'is_numeric')) : [];

        db()->beginTransaction();
        try {
            q("DELETE FROM project_members WHERE project_id=?", [$pid]);
            $stmt = db()->prepare("INSERT IGNORE INTO project_members(project_id,employee_id) VALUES(?,?)");
            foreach ($eids as $eid) {
                if ($eid > 0) $stmt->execute([$pid, $eid]);
            }
            db()->commit();
            log_action('UPDATE_PROJECT_MEMBERS', "pid:$pid count:" . count($eids));
            api_ok(['count' => count($eids)]);
        } catch (PDOException $e) {
            db()->rollBack();
            error_log('[TeamPulse] proj_members_save: ' . $e->getMessage());
            api_error('Failed to save member assignments.');
        }
    }

    // ── ACTIVE MEMBERS (status-aware) ──────────────────────────
    if ($action === 'proj_active_members') {
        $pid = (int)($_POST['pid'] ?? 0);
        if (!$pid) api_error('Invalid project ID.');

        $proj = one("SELECT id, name, status FROM projects WHERE id=?", [$pid]);
        if (!$proj) api_error('Project not found.');

        $proj_status = $proj['status'];

        // Tailor the employee status filter based on project status
        if ($proj_status === 'Active') {
            // Only currently working employees
            $status_sql = "AND e.status IN ('Active','Remote')";
            $status_params = [];
        } elseif ($proj_status === 'Completed') {
            // Everyone who was on this project (full history)
            $status_sql = "";
            $status_params = [];
        } elseif ($proj_status === 'On Hold') {
            // Employees still committed (not resigned)
            $status_sql = "AND e.status IN ('Active','Remote','On Leave')";
            $status_params = [];
        } else {
            // Planning — all assigned members
            $status_sql = "";
            $status_params = [];
        }

        $rows = all(
            "SELECT e.id, e.name, e.role, e.email, e.phone, e.status,
                    e.avatar_color, dp.name dept_name, dp.color dept_color
             FROM project_members pm
             JOIN employees e ON e.id = pm.employee_id
             LEFT JOIN departments dp ON e.department_id = dp.id
             WHERE pm.project_id = ?
               $status_sql
             ORDER BY e.name",
            array_merge([$pid], $status_params)
        );

        api_ok(['data' => $rows, 'proj_status' => $proj_status]);
    }

    // ── STATS ──────────────────────────────────────────────────
    if ($action === 'stats') {
        api_ok([
            'total'  => (int)scalar("SELECT COUNT(*) FROM employees"),
            'active' => (int)scalar("SELECT COUNT(*) FROM employees WHERE status='Active'"),
            'remote' => (int)scalar("SELECT COUNT(*) FROM employees WHERE status='Remote'"),
            'leave'  => (int)scalar("SELECT COUNT(*) FROM employees WHERE status='On Leave'"),
            'depts'  => (int)scalar("SELECT COUNT(*) FROM departments"),
            'projs'  => (int)scalar("SELECT COUNT(*) FROM projects WHERE status='Active'"),
        ]);
    }

    // ── ACTIVITY ───────────────────────────────────────────────
    if ($action === 'activity_list') {
        $rows = all(
            "SELECT l.*, a.full_name admin_name
             FROM activity_log l
             LEFT JOIN admin_users a ON l.admin_id = a.id
             ORDER BY l.created_at DESC
             LIMIT " . ACTIVITY_LOG_LIMIT
        );
        api_ok(['data' => $rows]);
    }

    // ── DEPT OPTIONS ───────────────────────────────────────────
    if ($action === 'dept_options') {
        api_ok(['data' => all("SELECT id, name, color FROM departments ORDER BY name")]);
    }

    api_error('Unknown action.', 400);
}

// ── AUTH POST ACTIONS ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $form = $_POST['form'] ?? '';

    if ($form === 'login' && $pdo) {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        if (is_rate_limited($user)) {
            $wait = ceil(rate_limit_remaining($user) / 60);
            $_SESSION['login_err'] = "Too many failed attempts. Try again in {$wait} minute(s).";
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }

        $row = one("SELECT * FROM admin_users WHERE username=? LIMIT 1", [$user]);
        if ($row && password_verify($pass, $row['password_hash'])) {
            clear_rate_limit($user);
            session_regenerate_id(true);
            $_SESSION['admin'] = [
                'id'        => (int)$row['id'],
                'username'  => $row['username'],
                'full_name' => $row['full_name'],
                'email'     => $row['email'],
            ];
            $_SESSION['csrf']        = bin2hex(random_bytes(32));
            $_SESSION['last_active'] = time();
            log_action('LOGIN', 'user:' . $user);
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }

        record_failed_attempt($user);
        $remaining = LOGIN_MAX_ATTEMPTS - ($_SESSION[get_rate_key($user)] ?? 0);
        $_SESSION['login_err'] = $remaining > 0
            ? "Invalid username or password. {$remaining} attempt(s) remaining."
            : 'Account temporarily locked. Try again in ' . ceil(LOGIN_LOCKOUT_SEC / 60) . ' minutes.';
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    if ($form === 'register' && $pdo) {
        $full = trim($_POST['full_name'] ?? '');
        $user = trim($_POST['username']  ?? '');
        $mail = trim($_POST['email']     ?? '');
        $pass = $_POST['password']       ?? '';
        $conf = $_POST['confirm']        ?? '';

        $errors = validate([
            'full_name' => ['Full name', ['required', 'max:80']],
            'username'  => ['Username',  ['required', 'min:3', 'max:40']],
            'email'     => ['Email',     ['required', 'email', 'max:180']],
            'password'  => ['Password',  ['required', 'min:6']],
        ], $_POST);

        if (!$errors && $pass !== $conf)
            $errors[] = 'Passwords do not match.';
        if (!$errors && one("SELECT id FROM admin_users WHERE username=?", [$user]))
            $errors[] = 'That username is already taken.';
        if (!$errors && one("SELECT id FROM admin_users WHERE email=?", [strtolower($mail)]))
            $errors[] = 'That email is already registered.';

        if ($errors) {
            $_SESSION['reg_err']  = implode(' ', $errors);
            $_SESSION['reg_data'] = ['full_name' => $full, 'username' => $user, 'email' => $mail];
            header('Location: ' . $_SERVER['PHP_SELF'] . '?page=register'); exit;
        }

        q("INSERT INTO admin_users(username,password_hash,full_name,email) VALUES(?,?,?,?)",
          [$user, password_hash($pass, PASSWORD_DEFAULT, ['cost' => 12]), $full, strtolower($mail)]);
        log_action('REGISTER', 'username:' . $user);
        $_SESSION['reg_ok'] = 'Account created successfully! You can now sign in.';
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    if ($form === 'logout') {
        log_action('LOGOUT');
        session_unset();
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

$page = $_GET['page'] ?? 'login';
if ($is_logged_in) $page = 'app';
elseif ($page !== 'register') $page = 'login';

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TeamPulse — Employee Database</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══ RESET ═══ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}

/* ═══ TOKENS ═══ */
:root{
  --font:'Plus Jakarta Sans',sans-serif;
  --font-head:'Space Grotesk',sans-serif;

  --bg:#0c0c14;
  --bg2:#111120;
  --bg3:#181828;
  --surface:#1e1e30;
  --surface2:#252538;
  --border:rgba(255,255,255,.08);
  --border2:rgba(255,255,255,.15);

  --txt:#eeeef8;
  --txt2:#9898b8;
  --txt3:#5a5a78;

  --p:#7b6ef6;
  --p-light:#a89fff;
  --p-dim:rgba(123,110,246,.18);
  --p-glow:rgba(123,110,246,.3);

  --g:#3dd68c;
  --g-dim:rgba(61,214,140,.14);
  --r:#f0514f;
  --r-dim:rgba(240,81,79,.14);
  --y:#f5c542;
  --y-dim:rgba(245,197,66,.14);
  --b:#4ea3e8;
  --b-dim:rgba(78,163,232,.14);

  --radius:12px;
  --radius-lg:18px;
  --radius-xl:24px;
  --sidebar:248px;
  --topbar:56px;
  --shadow:0 4px 24px rgba(0,0,0,.5);
}

/* ═══ BASE ═══ */
body{font-family:var(--font);background:var(--bg);color:var(--txt);min-height:100vh;font-size:14px;line-height:1.5;overflow-x:hidden}

/* ═══ BG GRID ═══ */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(123,110,246,.04) 1px,transparent 1px),
    linear-gradient(90deg,rgba(123,110,246,.04) 1px,transparent 1px);
  background-size:48px 48px;animation:grid-pan 40s linear infinite}
@keyframes grid-pan{to{background-position:48px 48px}}

/* ═══ ORBS ═══ */
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.orb-1{width:500px;height:500px;background:rgba(123,110,246,.12);top:-150px;left:-100px;animation:orb-float 16s ease-in-out infinite alternate}
.orb-2{width:400px;height:400px;background:rgba(61,214,140,.07);bottom:-100px;right:-80px;animation:orb-float 12s 4s ease-in-out infinite alternate}
@keyframes orb-float{to{transform:translate(40px,50px) scale(1.1)}}

/* ═══ AUTH PAGES ═══ */
.auth-wrap{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.auth-box{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);padding:44px 40px;animation:fade-up .5s both;box-shadow:var(--shadow)}
.auth-logo{font-family:var(--font-head);font-weight:700;font-size:1.5rem;color:var(--p-light);margin-bottom:4px;display:flex;align-items:center;gap:10px}
.auth-logo span{width:34px;height:34px;border-radius:9px;background:var(--p-dim);border:1px solid rgba(123,110,246,.3);display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.auth-sub{color:var(--txt2);font-size:.875rem;margin-bottom:32px}
.auth-err{background:var(--r-dim);border:1px solid rgba(240,81,79,.3);border-radius:var(--radius);padding:11px 14px;color:#f87171;font-size:.84rem;margin-bottom:18px}
.auth-ok{background:var(--g-dim);border:1px solid rgba(61,214,140,.3);border-radius:var(--radius);padding:11px 14px;color:#5ee8a8;font-size:.84rem;margin-bottom:18px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{margin-bottom:16px}
.field label{display:block;font-size:.72rem;font-weight:600;color:var(--txt3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:7px}
.inp-icon{position:relative}
.inp-icon svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--txt3);pointer-events:none;width:16px;height:16px}
.inp-icon input{padding-left:40px}
input,select,textarea{width:100%;padding:10px 13px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);color:var(--txt);font-family:var(--font);font-size:.9rem;outline:none;transition:border-color .2s,box-shadow .2s}
input:focus,select:focus,textarea:focus{border-color:var(--p);box-shadow:0 0 0 3px var(--p-dim)}
input[type=color]{padding:4px;height:40px;cursor:pointer}
select option{background:var(--bg3)}
textarea{resize:vertical;min-height:72px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:var(--radius);font-family:var(--font);font-size:.86rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;white-space:nowrap;text-decoration:none}
.btn-primary{background:var(--p);color:#fff;box-shadow:0 4px 18px var(--p-glow)}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px);box-shadow:0 8px 24px var(--p-glow)}
.btn-ghost{background:var(--surface2);color:var(--txt);border:1px solid var(--border)}
.btn-ghost:hover{border-color:var(--border2);background:rgba(255,255,255,.06)}
.btn-danger{background:var(--r-dim);color:#f87171;border:1px solid rgba(240,81,79,.25)}
.btn-danger:hover{background:rgba(240,81,79,.22)}
.btn-success{background:var(--g-dim);color:var(--g);border:1px solid rgba(61,214,140,.25)}
.btn-success:hover{background:rgba(61,214,140,.22)}
.btn-info{background:var(--b-dim);color:var(--b);border:1px solid rgba(78,163,232,.25)}
.btn-info:hover{background:rgba(78,163,232,.22)}
.btn-sm{padding:6px 12px;font-size:.78rem}
.btn-xs{padding:3px 9px;font-size:.73rem}
.btn-full{width:100%;justify-content:center}
.link-row{text-align:center;margin-top:16px;font-size:.82rem;color:var(--txt2)}
.link-row a{color:var(--p-light);text-decoration:none;font-weight:600}
.link-row a:hover{text-decoration:underline}
.hint-box{background:var(--p-dim);border:1px solid rgba(123,110,246,.2);border-radius:var(--radius);padding:10px 13px;font-size:.78rem;color:var(--txt2);margin-top:14px;text-align:center}
.hint-box code{background:rgba(255,255,255,.1);padding:1px 6px;border-radius:5px;color:var(--p-light)}

/* ═══ APP LAYOUT ═══ */
.app{display:flex;min-height:100vh;position:relative;z-index:1}
.sidebar{width:var(--sidebar);flex-shrink:0;background:var(--bg2);border-right:1px solid var(--border);
  display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50;overflow-y:auto;
  transition:transform .25s cubic-bezier(.4,0,.2,1)}
.sb-logo{padding:22px 20px 16px;font-family:var(--font-head);font-weight:700;font-size:1.2rem;color:var(--p-light);
  display:flex;align-items:center;gap:10px}
.sb-logo-icon{width:32px;height:32px;border-radius:8px;background:var(--p-dim);border:1px solid rgba(123,110,246,.3);
  display:flex;align-items:center;justify-content:center;font-size:.95rem}
.sb-logo small{font-family:var(--font);font-size:.65rem;font-weight:400;color:var(--txt3);display:block;margin-top:1px}
.sb-section{padding:10px 18px 3px;font-size:.63rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.12em}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 16px;margin:1px 8px;border-radius:10px;
  font-size:.84rem;color:var(--txt2);cursor:pointer;text-decoration:none;transition:background .18s,color .18s;border:none;background:none}
.sb-link svg{width:16px;height:16px;flex-shrink:0;opacity:.7;transition:opacity .18s}
.sb-link:hover{background:rgba(255,255,255,.05);color:var(--txt)}
.sb-link.active{background:var(--p-dim);color:var(--p-light);border:1px solid rgba(123,110,246,.18)}
.sb-link.active svg{opacity:1}
.sb-footer{margin-top:auto;padding:16px;border-top:1px solid var(--border)}
.sb-admin{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.sb-av{width:34px;height:34px;border-radius:9px;background:var(--p-dim);border:1px solid rgba(123,110,246,.25);
  display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:.82rem;color:var(--p-light);flex-shrink:0}

.main{margin-left:var(--sidebar);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{position:sticky;top:0;z-index:40;background:rgba(12,12,20,.92);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;
  padding:0 24px;height:var(--topbar);flex-shrink:0}
.topbar-title{font-family:var(--font-head);font-weight:600;font-size:1rem;color:var(--txt)}
.content{padding:24px;flex:1}

/* ═══ PAGE HEADER ═══ */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:12px;flex-wrap:wrap}
.page-header-left h1{font-family:var(--font-head);font-weight:700;font-size:1.15rem}
.page-header-left p{font-size:.8rem;color:var(--txt2);margin-top:2px}
.page-header-right{display:flex;gap:9px;flex-wrap:wrap}

/* ═══ STAT CARDS ═══ */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;
  position:relative;overflow:hidden;cursor:default;animation:fade-up .4s both;
  transition:border-color .2s,transform .2s}
.stat-card:hover{transform:translateY(-3px);border-color:var(--border2)}
.stat-card::before{content:'';position:absolute;inset:0;background:var(--tint,transparent);pointer-events:none}
.stat-icon{font-size:1.5rem;margin-bottom:8px;display:block}
.stat-num{font-family:var(--font-head);font-weight:700;font-size:1.9rem;letter-spacing:-1px;line-height:1;color:var(--txt)}
.stat-lbl{font-size:.69rem;color:var(--txt3);text-transform:uppercase;letter-spacing:.09em;margin-top:4px}

/* ═══ TABLE CARD ═══ */
.table-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;animation:fade-up .4s both}
.tbl-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.83rem}
th{padding:9px 14px;text-align:left;font-size:.67rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.09em;
  border-bottom:1px solid var(--border);background:rgba(0,0,0,.2);white-space:nowrap}
td{padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover td{background:rgba(255,255,255,.025)}

/* ═══ AVATAR ═══ */
.avatar{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;
  font-family:var(--font-head);font-weight:700;font-size:.78rem;color:#fff;flex-shrink:0;
  box-shadow:0 2px 8px rgba(0,0,0,.3)}

/* ═══ BADGE ═══ */
.badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:99px;font-size:.71rem;font-weight:600}
.badge-active{background:var(--g-dim);color:var(--g)}
.badge-remote{background:var(--p-dim);color:var(--p-light)}
.badge-leave{background:var(--y-dim);color:var(--y)}
.badge-resigned{background:var(--r-dim);color:#f87171}
.badge-planning{background:var(--b-dim);color:var(--b)}
.badge-onhold{background:var(--y-dim);color:var(--y)}
.badge-completed{background:var(--g-dim);color:var(--g)}

/* ═══ TOOLBAR ═══ */
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.search-box{flex:1;min-width:200px;position:relative}
.search-box svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--txt3);pointer-events:none;width:15px;height:15px}
.search-box input{padding:8px 12px 8px 36px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem}
.filter-sel{padding:8px 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  color:var(--txt);font-family:var(--font);font-size:.84rem;cursor:pointer;outline:none;transition:border-color .2s}
.filter-sel:focus{border-color:var(--p)}

/* ═══ PAGINATION ═══ */
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;border-top:1px solid var(--border);justify-content:flex-end}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;
  font-size:.78rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--surface2);
  color:var(--txt2);transition:all .15s}
.pag-btn:hover:not(:disabled){border-color:var(--border2);color:var(--txt)}
.pag-btn.active{background:var(--p);border-color:var(--p);color:#fff}
.pag-btn:disabled{opacity:.35;cursor:not-allowed}
.pag-info{font-size:.76rem;color:var(--txt3);margin-right:8px}

/* ═══ MODAL ═══ */
.modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.75);backdrop-filter:blur(8px);
  display:flex;align-items:center;justify-content:center;padding:20px;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--radius-xl);padding:32px;
  width:100%;max-width:580px;max-height:92vh;overflow-y:auto;
  transform:scale(.96) translateY(14px);transition:transform .22s;
  box-shadow:0 40px 100px rgba(0,0,0,.7)}
.modal-overlay.open .modal{transform:scale(1) translateY(0)}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.modal-title{font-family:var(--font-head);font-weight:700;font-size:1.15rem}
.modal-close{background:none;border:none;color:var(--txt3);cursor:pointer;padding:4px;border-radius:7px;font-size:1.1rem;line-height:1;transition:color .2s}
.modal-close:hover{color:var(--txt)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .full{grid-column:1/-1}
.modal-footer{display:flex;gap:9px;justify-content:flex-end;margin-top:22px;padding-top:18px;border-top:1px solid var(--border)}

/* ═══ DEPT / PROJ CARDS ═══ */
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.item-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;
  transition:transform .2s,border-color .2s;cursor:default;animation:fade-up .4s both}
.item-card:hover{transform:translateY(-3px);border-color:var(--border2)}

/* ═══ ACTIVE MEMBER ROW ═══ */
.active-member-row{display:flex;align-items:center;gap:13px;padding:12px 0;border-bottom:1px solid var(--border)}
.active-member-row:last-child{border-bottom:none}

/* ═══ ONLINE DOT ═══ */
.online-dot{width:8px;height:8px;border-radius:50%;background:var(--g);flex-shrink:0;
  box-shadow:0 0 6px rgba(61,214,140,.6);animation:pulse-dot 2s ease-in-out infinite}
@keyframes pulse-dot{0%,100%{box-shadow:0 0 4px rgba(61,214,140,.5)}50%{box-shadow:0 0 10px rgba(61,214,140,.9)}}

/* ═══ TOAST ═══ */
#toast-box{position:fixed;bottom:20px;right:20px;z-index:300;display:flex;flex-direction:column;gap:9px}
.toast{background:var(--surface);border:1px solid var(--border2);border-radius:var(--radius);
  padding:11px 16px;font-size:.83rem;display:flex;align-items:center;gap:8px;
  box-shadow:var(--shadow);animation:slide-in .3s both;min-width:220px}
.toast.ok{border-left:3px solid var(--g)}
.toast.err{border-left:3px solid var(--r)}
@keyframes slide-in{from{opacity:0;transform:translateX(48px)}to{opacity:1;transform:none}}
@keyframes fade-up{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
@keyframes spin{to{transform:rotate(360deg)}}

/* ═══ SPINNER ═══ */
.spinner{width:28px;height:28px;border:2px solid var(--border);border-top-color:var(--p);border-radius:50%;animation:spin .7s linear infinite;margin:40px auto}

/* ═══ EMPTY ═══ */
.empty-state{padding:60px;text-align:center;color:var(--txt3)}
.empty-state .emoji{font-size:2.2rem;margin-bottom:10px}

/* ═══ DASH GRID ═══ */
.dash-grid{display:grid;grid-template-columns:1fr 300px;gap:18px}

/* ═══ DB ERROR ═══ */
.db-error-page{position:fixed;inset:0;z-index:999;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--bg)}
.db-error-box{background:var(--surface);border:1px solid rgba(240,81,79,.3);border-radius:var(--radius-xl);padding:40px;max-width:520px;text-align:center;box-shadow:var(--shadow)}
.db-error-box h2{font-family:var(--font-head);font-size:1.3rem;color:#f87171;margin:12px 0 8px}
.db-error-box pre{background:var(--bg3);border-radius:var(--radius);padding:13px;text-align:left;font-size:.76rem;color:var(--y);overflow:auto;margin:14px 0}
.db-error-box ul{text-align:left;font-size:.84rem;color:var(--txt2);margin:0;padding-left:20px;line-height:2}

/* ═══ MOBILE ═══ */
.menu-btn{display:none;background:none;border:none;color:var(--txt);cursor:pointer;padding:4px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:49}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:none}
  .sb-overlay.show{display:block}
  .main{margin-left:0}
  .menu-btn{display:flex}
  .form-grid{grid-template-columns:1fr}
  .form-grid .full{grid-column:1}
  .dash-grid{grid-template-columns:1fr!important}
  .stats-grid{grid-template-columns:1fr 1fr}
  .field-row{grid-template-columns:1fr}
}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:99px}
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<?php if ($db_error): ?>
<!-- ── DB ERROR ── -->
<div class="db-error-page">
  <div class="db-error-box">
    <div style="font-size:2.5rem">⚠️</div>
    <h2>Database Connection Failed</h2>
    <p style="font-size:.87rem;color:var(--txt2)">Could not connect to MySQL. Fix the settings at the top of <code style="background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px">index.php</code></p>
    <pre><?= xe($db_error) ?></pre>
    <ul>
      <li>Open <strong>XAMPP Control Panel</strong> → start <strong>Apache</strong> &amp; <strong>MySQL</strong></li>
      <li>Run <strong>database.sql</strong> in phpMyAdmin</li>
      <li>Check host is <strong>127.0.0.1</strong>, port <strong>3306</strong></li>
      <li>Edit <strong>$DB_USER / $DB_PASS</strong> at top of index.php</li>
    </ul>
    <button class="btn btn-primary" style="margin-top:16px" onclick="location.reload()">↺ Try Again</button>
  </div>
</div>

<?php elseif ($page === 'register'): ?>
<!-- ══════════════════════════════════════════
     REGISTER PAGE
══════════════════════════════════════════ -->
<?php
$reg_err  = $_SESSION['reg_err']  ?? ''; unset($_SESSION['reg_err']);
$reg_ok   = $_SESSION['reg_ok']   ?? ''; unset($_SESSION['reg_ok']);
$reg_data = $_SESSION['reg_data'] ?? []; unset($_SESSION['reg_data']);
?>
<div class="auth-wrap">
  <div class="auth-box" style="max-width:480px">
    <div class="auth-logo"><span>🏢</span> TeamPulse</div>
    <div class="auth-sub">Create a new admin account — no approval needed</div>

    <?php if ($reg_err): ?>
    <div class="auth-err">⚠️ <?= xe($reg_err) ?></div>
    <?php endif; ?>
    <?php if ($reg_ok): ?>
    <div class="auth-ok">✅ <?= xe($reg_ok) ?> <a href="?" style="color:var(--g);font-weight:700;text-decoration:underline">Sign in now →</a></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="form" value="register">
      <div class="field-row">
        <div class="field">
          <label>Full Name *</label>
          <input type="text" name="full_name" placeholder="Your full name"
            value="<?= xe($reg_data['full_name'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label>Username *</label>
          <input type="text" name="username" placeholder="e.g. admin2"
            value="<?= xe($reg_data['username'] ?? '') ?>" required autocomplete="off">
        </div>
      </div>
      <div class="field">
        <label>Email Address *</label>
        <div class="inp-icon">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" name="email" placeholder="you@company.io"
            value="<?= xe($reg_data['email'] ?? '') ?>" required>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Password *</label>
          <div class="inp-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" name="password" placeholder="Min 6 chars" required>
          </div>
        </div>
        <div class="field">
          <label>Confirm *</label>
          <div class="inp-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" name="confirm" placeholder="Repeat password" required>
          </div>
        </div>
      </div>
      <div style="margin-top:8px">
        <button type="submit" class="btn btn-primary btn-full">Create Account →</button>
      </div>
    </form>
    <div class="link-row">Already have an account? <a href="?">Sign in here</a></div>
  </div>
</div>

<?php elseif ($page === 'login'): ?>
<!-- ══════════════════════════════════════════
     LOGIN PAGE
══════════════════════════════════════════ -->
<div class="auth-wrap">
  <div style="width:100%;max-width:820px;display:grid;grid-template-columns:1fr 1fr;gap:0;
    background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);
    overflow:hidden;box-shadow:var(--shadow);animation:fade-up .5s both">
    <!-- Left panel -->
    <div style="padding:48px 40px;background:linear-gradient(135deg,rgba(123,110,246,.12),rgba(61,214,140,.06));
      display:flex;flex-direction:column;justify-content:center;border-right:1px solid var(--border)">
      <div class="auth-logo" style="margin-bottom:12px"><span>🏢</span> TeamPulse</div>
      <p style="font-size:.9rem;color:var(--txt2);line-height:1.7;margin-bottom:28px">The complete project team employee database — manage people, departments &amp; projects.</p>
      <?php foreach([['👥','Employee CRUD with search &amp; filters'],['🏢','Department management'],['🚀','Project &amp; member tracking'],['🟢','Live active worker view'],['📋','Full audit activity log']] as $f): ?>
      <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px;font-size:.85rem">
        <div style="width:30px;height:30px;border-radius:8px;background:var(--p-dim);display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= $f[0] ?></div>
        <span style="color:var(--txt)"><?= $f[1] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- Right panel -->
    <div style="padding:48px 40px;display:flex;flex-direction:column;justify-content:center">
      <div style="font-family:var(--font-head);font-weight:700;font-size:1.4rem;margin-bottom:4px">Welcome back</div>
      <div style="font-size:.84rem;color:var(--txt2);margin-bottom:28px">Sign in to your admin account</div>
      <?php if (!empty($_SESSION['login_err'])): ?>
      <div class="auth-err"><?= xe($_SESSION['login_err']) ?></div>
      <?php unset($_SESSION['login_err']); endif; ?>
      <form method="POST">
        <input type="hidden" name="form" value="login">
        <div class="field">
          <label>Username</label>
          <div class="inp-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" name="username" placeholder="admin" required autocomplete="username">
          </div>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="inp-icon">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:4px">Sign In →</button>
      </form>
      <div class="link-row">No account? <a href="?page=register">Register here</a></div>
      <div class="hint-box">Default: <code>admin</code> / <code>admin123</code></div>
    </div>
  </div>
</div>
<style>@media(max-width:680px){.auth-wrap>div{grid-template-columns:1fr!important} .auth-wrap>div>div:first-child{display:none!important}}</style>

<?php else: ?>
<!-- ══════════════════════════════════════════
     MAIN APP
══════════════════════════════════════════ -->
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-logo">
      <div class="sb-logo-icon">🏢</div>
      <div>TeamPulse <small>Admin Panel</small></div>
    </div>

    <div class="sb-section">Main</div>
    <a class="sb-link" data-view="dashboard" onclick="switchView('dashboard',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>

    <div class="sb-section">People</div>
    <a class="sb-link" data-view="employees" onclick="switchView('employees',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Employees
    </a>
    <a class="sb-link" data-view="departments" onclick="switchView('departments',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Departments
    </a>

    <div class="sb-section">Work</div>
    <a class="sb-link" data-view="projects" onclick="switchView('projects',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Projects
    </a>

    <div class="sb-section">System</div>
    <a class="sb-link" data-view="activity" onclick="switchView('activity',this)">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Activity Log
    </a>
    <a class="sb-link" onclick="window.open(location.pathname+'?page=register','_blank')" style="cursor:pointer">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
      Register Admin
    </a>

    <div class="sb-footer">
      <div class="sb-admin">
        <div class="sb-av"><?= strtoupper(substr($_SESSION['admin']['full_name'] ?? 'A', 0, 1)) ?></div>
        <div>
          <div style="font-size:.83rem;font-weight:600"><?= xe($_SESSION['admin']['full_name'] ?? '') ?></div>
          <div style="font-size:.71rem;color:var(--txt3)"><?= xe($_SESSION['admin']['email'] ?? '') ?></div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="form" value="logout">
        <button type="submit" class="btn btn-danger btn-full" style="justify-content:center">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
          Sign Out
        </button>
      </form>
    </div>
  </aside>

  <div class="sb-overlay" id="sb-overlay" onclick="toggleSb()"></div>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="menu-btn" onclick="toggleSb()">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <span class="topbar-title" id="topbar-title">Dashboard</span>
      </div>
      <span style="font-size:.76rem;color:var(--txt3)"><?= date('D, d M Y') ?></span>
    </div>
    <div class="content" id="view-content">
      <div class="spinner"></div>
    </div>
  </div>
</div>

<!-- ══════ MODALS ══════ -->

<!-- Employee Modal -->
<div class="modal-overlay" id="emp-modal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="emp-modal-title">Add Employee</div>
      <button class="modal-close" onclick="closeModal('emp-modal')">✕</button>
    </div>
    <div class="form-grid">
      <input type="hidden" id="emp-id">
      <div class="field"><label>Employee Code</label><input id="emp-code" placeholder="EMP009"></div>
      <div class="field"><label>Avatar Colour</label><input type="color" id="emp-color" value="#6C63FF"></div>
      <div class="field full"><label>Full Name *</label><input id="emp-name" placeholder="e.g. Priya Sharma"></div>
      <div class="field"><label>Role / Title *</label><input id="emp-role" placeholder="Lead Engineer"></div>
      <div class="field"><label>Department</label>
        <select id="emp-dept"><option value="">— Select —</option></select>
      </div>
      <div class="field full"><label>Email Address *</label><input type="email" id="emp-email" placeholder="name@company.io"></div>
      <div class="field"><label>Phone</label><input id="emp-phone" placeholder="+91 98765 43210"></div>
      <div class="field"><label>Status</label>
        <select id="emp-status"><option>Active</option><option>Remote</option><option>On Leave</option><option>Resigned</option></select>
      </div>
      <div class="field"><label>Salary (₹)</label><input type="number" id="emp-salary" placeholder="100000"></div>
      <div class="field"><label>Joining Date</label><input type="date" id="emp-joined"></div>
      <div class="field full"><label>Notes</label><textarea id="emp-notes" placeholder="Any additional notes…"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('emp-modal')">Cancel</button>
      <button class="btn btn-primary" id="emp-save-btn" onclick="saveEmployee()">Save Employee</button>
    </div>
  </div>
</div>

<!-- Dept Modal -->
<div class="modal-overlay" id="dept-modal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <div class="modal-title" id="dept-modal-title">Add Department</div>
      <button class="modal-close" onclick="closeModal('dept-modal')">✕</button>
    </div>
    <div class="field"><label>Department Name *</label><input id="dept-name" placeholder="e.g. Engineering"></div>
    <div class="field" style="margin-top:13px"><label>Colour</label><input type="color" id="dept-color" value="#6C63FF"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('dept-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveDept()">Save Department</button>
    </div>
  </div>
</div>

<!-- Project Modal -->
<div class="modal-overlay" id="proj-modal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <div class="modal-title" id="proj-modal-title">New Project</div>
      <button class="modal-close" onclick="closeModal('proj-modal')">✕</button>
    </div>
    <div class="field"><label>Project Name *</label><input id="proj-name" placeholder="e.g. Portal Redesign"></div>
    <div class="field" style="margin-top:13px"><label>Description</label><textarea id="proj-desc" placeholder="Brief description…"></textarea></div>
    <div class="form-grid" style="margin-top:13px">
      <div class="field"><label>Status</label>
        <select id="proj-status"><option>Active</option><option>Planning</option><option>On Hold</option><option>Completed</option></select>
      </div>
      <div class="field"><label>&#8203;</label></div>
      <div class="field"><label>Start Date</label><input type="date" id="proj-start"></div>
      <div class="field"><label>End Date</label><input type="date" id="proj-end"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('proj-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveProject()">Save Project</button>
    </div>
  </div>
</div>

<!-- Members Modal (assign all) -->
<div class="modal-overlay" id="members-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <div class="modal-title" id="members-modal-title">Assign Members</div>
      <button class="modal-close" onclick="closeModal('members-modal')">✕</button>
    </div>
    <input type="hidden" id="members-proj-id">
    <div id="members-list" style="max-height:340px;overflow-y:auto"><div class="spinner"></div></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('members-modal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveMembers()">Save Assignments</button>
    </div>
  </div>
</div>

<!-- Active Members Modal -->
<div class="modal-overlay" id="active-modal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="active-modal-title">Active Workers</div>
        <div id="active-modal-sub" style="font-size:.75rem;color:var(--txt3);margin-top:3px"></div>
      </div>
      <button class="modal-close" onclick="closeModal('active-modal')">✕</button>
    </div>
    <div id="active-members-list" style="max-height:440px;overflow-y:auto;padding-top:4px"></div>
    <div class="modal-footer" style="border-top:none;padding-top:8px;margin-top:4px">
      <button class="btn btn-ghost" onclick="closeModal('active-modal')">Close</button>
    </div>
  </div>
</div>

<div id="toast-box"></div>

<!-- ══════ JAVASCRIPT ══════ -->
<script>
const CSRF = <?= json_encode($csrf) ?>;
const PAGE_SIZE = <?= PAGINATION_LIMIT ?>;
let currentView = 'dashboard';
let empRows = [], empTotal = 0, empPage = 1;
let deptEditId = null, projEditId = null, debT;

// ── CORE ──────────────────────────────────────────────────────
async function post(action, data = {}) {
  try {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', CSRF);
    Object.entries(data).forEach(([k, v]) => fd.append(k, String(v ?? '')));
    const r = await fetch(location.href, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    if (r.status === 401) { toast('Session expired. Reloading…', 'err'); setTimeout(() => location.reload(), 1500); return { ok: false }; }
    if (!r.ok) return { ok: false, msg: 'Server error: HTTP ' + r.status };
    return await r.json();
  } catch (e) {
    return { ok: false, msg: 'Network error: ' + e.message };
  }
}

function toast(msg, type = 'ok') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = (type === 'ok' ? '✅ ' : '❌ ') + msg;
  document.getElementById('toast-box').appendChild(t);
  setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(o => o.classList.remove('open'));
});

function toggleSb() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sb-overlay').classList.toggle('show');
}

function esc(v)       { const d = document.createElement('div'); d.textContent = String(v ?? ''); return d.innerHTML; }
function ini(n)       { const p = (n || '').trim().split(' '); return (p[0][0] + (p[1] ? p[1][0] : '')).toUpperCase(); }
function fmtDate(d)   { if (!d) return '—'; return new Date(d).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }); }
function fmtSalary(s) { return s ? '₹' + Number(s).toLocaleString('en-IN') : '—'; }
function badgeCls(s)  {
  return { Active:'badge-active', Remote:'badge-remote', 'On Leave':'badge-leave',
           Resigned:'badge-resigned', Planning:'badge-planning', 'On Hold':'badge-onhold',
           Completed:'badge-completed' }[s] || 'badge-remote';
}

function switchView(view, el) {
  currentView = view;
  document.querySelectorAll('.sb-link').forEach(l => l.classList.remove('active'));
  if (el) el.classList.add('active');
  document.getElementById('topbar-title').textContent = {
    dashboard:'Dashboard', employees:'Employees', departments:'Departments',
    projects:'Projects', activity:'Activity Log'
  }[view] || view;
  if (window.innerWidth < 900) {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sb-overlay').classList.remove('show');
  }
  ({ dashboard, employees, departments, projects, activity })[view]?.();
}

// ── PAGINATION ────────────────────────────────────────────────
function renderPagination(total, page, onNav) {
  const pages = Math.ceil(total / PAGE_SIZE);
  if (pages <= 1) return '';
  const from = (page - 1) * PAGE_SIZE + 1;
  const to   = Math.min(page * PAGE_SIZE, total);
  let btns = '';
  const start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
  if (start > 1) btns += `<button class="pag-btn" onclick="${onNav}(1)">1</button>${start > 2 ? '<span style="color:var(--txt3);padding:0 2px">…</span>' : ''}`;
  for (let i = start; i <= end; i++)
    btns += `<button class="pag-btn${i === page ? ' active' : ''}" onclick="${onNav}(${i})">${i}</button>`;
  if (end < pages) btns += `${end < pages - 1 ? '<span style="color:var(--txt3);padding:0 2px">…</span>' : ''}<button class="pag-btn" onclick="${onNav}(${pages})">${pages}</button>`;
  return `<div class="pagination">
    <span class="pag-info">${from}–${to} of ${total}</span>
    <button class="pag-btn" onclick="${onNav}(${page - 1})" ${page <= 1 ? 'disabled' : ''}>‹</button>
    ${btns}
    <button class="pag-btn" onclick="${onNav}(${page + 1})" ${page >= pages ? 'disabled' : ''}>›</button>
  </div>`;
}

// ── DASHBOARD ─────────────────────────────────────────────────
async function dashboard() {
  const c = document.getElementById('view-content');
  c.innerHTML = '<div class="spinner"></div>';
  const [sd, rd, ld] = await Promise.all([
    post('stats'),
    post('emp_list', { q:'', dept:'', status:'', page:1 }),
    post('activity_list')
  ]);
  if (!sd.ok) { c.innerHTML = `<p style="color:var(--r)">Error: ${esc(sd.msg)}</p>`; return; }
  const s      = sd;
  const recent = (rd.data || []).slice(0, 6);
  const logs   = (ld.data || []).slice(0, 6);
  c.innerHTML = `
  <div class="stats-grid">
    <div class="stat-card" style="--tint:rgba(123,110,246,.06);animation-delay:.04s"><span class="stat-icon">👥</span><div class="stat-num">${s.total}</div><div class="stat-lbl">Total Employees</div></div>
    <div class="stat-card" style="--tint:rgba(61,214,140,.06);animation-delay:.08s"><span class="stat-icon">✅</span><div class="stat-num">${s.active}</div><div class="stat-lbl">Active</div></div>
    <div class="stat-card" style="--tint:rgba(123,110,246,.06);animation-delay:.12s"><span class="stat-icon">🌐</span><div class="stat-num">${s.remote}</div><div class="stat-lbl">Remote</div></div>
    <div class="stat-card" style="--tint:rgba(245,197,66,.06);animation-delay:.16s"><span class="stat-icon">🏖️</span><div class="stat-num">${s.leave}</div><div class="stat-lbl">On Leave</div></div>
    <div class="stat-card" style="--tint:rgba(240,81,79,.06);animation-delay:.2s"><span class="stat-icon">🏢</span><div class="stat-num">${s.depts}</div><div class="stat-lbl">Departments</div></div>
    <div class="stat-card" style="--tint:rgba(78,163,232,.06);animation-delay:.24s"><span class="stat-icon">🚀</span><div class="stat-num">${s.projs}</div><div class="stat-lbl">Active Projects</div></div>
  </div>
  <div class="dash-grid" style="display:grid;grid-template-columns:1fr 290px;gap:18px">
    <div class="table-card" style="animation-delay:.1s">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px">
        <div style="font-family:var(--font-head);font-weight:600;font-size:.95rem">Recent Employees</div>
        <button class="btn btn-ghost btn-sm" onclick="switchView('employees',document.querySelector('[data-view=employees]'))">View All</button>
      </div>
      <div class="tbl-scroll"><table>
        <thead><tr><th>Employee</th><th>Department</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>${recent.map(e => `<tr>
          <td><div style="display:flex;align-items:center;gap:10px">
            <div class="avatar" style="background:${esc(e.avatar_color)}">${ini(e.name)}</div>
            <div><div style="font-weight:500">${esc(e.name)}</div><div style="font-size:.72rem;color:var(--txt2)">${esc(e.role)}</div></div>
          </div></td>
          <td><span style="background:${esc(e.dept_color||'#6C63FF')}22;color:${esc(e.dept_color||'#6C63FF')};padding:2px 8px;border-radius:6px;font-size:.73rem;font-weight:600">${esc(e.dept_name||'—')}</span></td>
          <td><span class="badge ${badgeCls(e.status)}">${esc(e.status)}</span></td>
          <td style="color:var(--txt2);font-size:.79rem">${fmtDate(e.joined)}</td>
        </tr>`).join('')}</tbody>
      </table></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="table-card" style="padding:18px;animation-delay:.14s">
        <div style="font-family:var(--font-head);font-weight:600;font-size:.9rem;margin-bottom:12px">Recent Activity</div>
        ${logs.map(l => {
          const col = {ADD:'var(--g)',UPDATE:'var(--y)',DELETE:'var(--r)',LOGIN:'var(--b)'}[l.action?.split('_')[0]] || 'var(--p)';
          return `<div style="display:flex;gap:10px;margin-bottom:10px;align-items:flex-start">
            <div style="width:7px;height:7px;border-radius:50%;background:${col};flex-shrink:0;margin-top:5px"></div>
            <div><div style="font-size:.78rem;font-weight:500">${esc(l.action)}</div>
                 <div style="font-size:.7rem;color:var(--txt3)">${esc(l.admin_name||'System')} · ${new Date(l.created_at).toLocaleString('en-IN',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}</div></div>
          </div>`;
        }).join('') || '<p style="font-size:.82rem;color:var(--txt3)">No activity yet.</p>'}
      </div>
    </div>
  </div>`;
}

// ── EMPLOYEES ─────────────────────────────────────────────────
let empDeptOptions = [];
async function employees() {
  const c = document.getElementById('view-content');
  const dopts = await post('dept_options');
  empDeptOptions = dopts.data || [];
  empPage = 1;
  c.innerHTML = `
  <div class="page-header">
    <div class="page-header-left"><h1>Employee Directory</h1><p>Manage all team members</p></div>
    <div class="page-header-right">
      <button class="btn btn-ghost btn-sm" onclick="exportCSV()">⬇ Export CSV</button>
      <button class="btn btn-primary btn-sm" onclick="openAddEmp()">＋ Add Employee</button>
    </div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      <input id="emp-search" placeholder="Search name, role, email, code…" oninput="clearTimeout(debT);debT=setTimeout(()=>{empPage=1;loadEmpTable()},300)">
    </div>
    <select class="filter-sel" id="emp-dept-filter" onchange="empPage=1;loadEmpTable()">
      <option value="">All Departments</option>
      ${empDeptOptions.map(d => `<option value="${esc(d.id)}">${esc(d.name)}</option>`).join('')}
    </select>
    <select class="filter-sel" id="emp-status-filter" onchange="empPage=1;loadEmpTable()">
      <option value="">All Status</option>
      <option>Active</option><option>Remote</option><option>On Leave</option><option>Resigned</option>
    </select>
  </div>
  <div class="table-card" id="emp-table-wrap" style="padding:0;overflow:hidden"><div class="spinner"></div></div>`;
  loadEmpTable();
}

async function loadEmpTable(page) {
  if (page !== undefined) empPage = page;
  const w = document.getElementById('emp-table-wrap');
  if (!w) return;
  w.innerHTML = '<div class="spinner"></div>';
  const d = await post('emp_list', {
    q:      document.getElementById('emp-search')?.value || '',
    dept:   document.getElementById('emp-dept-filter')?.value || '',
    status: document.getElementById('emp-status-filter')?.value || '',
    page:   empPage,
  });
  if (!d.ok) { w.innerHTML = `<p style="padding:24px;color:var(--r)">${esc(d.msg)}</p>`; return; }
  empRows  = d.data;
  empTotal = d.total;
  if (!empRows.length) { w.innerHTML = '<div class="empty-state"><div class="emoji">🔍</div><p>No employees found</p></div>'; return; }
  w.innerHTML = `<div class="tbl-scroll"><table>
    <thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Status</th><th>Email</th><th>Phone</th><th>Salary</th><th>Joined</th><th>Actions</th></tr></thead>
    <tbody>${empRows.map(e => `<tr>
      <td><div style="display:flex;align-items:center;gap:10px">
        <div class="avatar" style="background:${esc(e.avatar_color||'#6C63FF')}">${ini(e.name)}</div>
        <div><div style="font-weight:500">${esc(e.name)}</div><div style="font-size:.73rem;color:var(--txt2)">${esc(e.role)}</div></div>
      </div></td>
      <td style="color:var(--txt2);font-size:.79rem">${esc(e.emp_code||'—')}</td>
      <td><span style="background:${esc(e.dept_color||'#6C63FF')}22;color:${esc(e.dept_color||'#6C63FF')};padding:2px 8px;border-radius:6px;font-size:.73rem;font-weight:600">${esc(e.dept_name||'—')}</span></td>
      <td><span class="badge ${badgeCls(e.status)}">${esc(e.status)}</span></td>
      <td style="font-size:.79rem"><a href="mailto:${esc(e.email)}" style="color:var(--p-light);text-decoration:none">${esc(e.email)}</a></td>
      <td style="font-size:.79rem;color:var(--txt2)">${esc(e.phone||'—')}</td>
      <td style="font-size:.79rem;color:var(--g)">${fmtSalary(e.salary)}</td>
      <td style="font-size:.79rem;color:var(--txt2)">${fmtDate(e.joined)}</td>
      <td><div style="display:flex;gap:5px">
        <button class="btn btn-ghost btn-xs" onclick='openEditEmp(${JSON.stringify(e).replace(/'/g,"&#39;")})'>✏️ Edit</button>
        <button class="btn btn-danger btn-xs" onclick="deleteEmp(${e.id})">🗑️</button>
      </div></td>
    </tr>`).join('')}</tbody>
  </table></div>
  ${renderPagination(empTotal, empPage, 'loadEmpTable')}`;
}

function openAddEmp() {
  document.getElementById('emp-modal-title').textContent = 'Add Employee';
  document.getElementById('emp-id').value = '';
  ['emp-code','emp-name','emp-role','emp-email','emp-phone','emp-salary','emp-notes'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  document.getElementById('emp-dept').innerHTML = '<option value="">— Select —</option>' +
    empDeptOptions.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
  document.getElementById('emp-status').value = 'Active';
  document.getElementById('emp-color').value  = '#6C63FF';
  document.getElementById('emp-joined').value = new Date().toISOString().slice(0, 10);
  openModal('emp-modal');
}

function openEditEmp(e) {
  document.getElementById('emp-modal-title').textContent = 'Edit Employee';
  document.getElementById('emp-id').value    = e.id;
  document.getElementById('emp-code').value  = e.emp_code || '';
  document.getElementById('emp-name').value  = e.name;
  document.getElementById('emp-role').value  = e.role;
  document.getElementById('emp-dept').innerHTML = '<option value="">— Select —</option>' +
    empDeptOptions.map(d => `<option value="${d.id}"${e.department_id == d.id ? ' selected' : ''}>${esc(d.name)}</option>`).join('');
  document.getElementById('emp-email').value  = e.email;
  document.getElementById('emp-phone').value  = e.phone || '';
  document.getElementById('emp-status').value = e.status;
  document.getElementById('emp-color').value  = e.avatar_color || '#6C63FF';
  document.getElementById('emp-salary').value = e.salary || '';
  document.getElementById('emp-joined').value = e.joined || '';
  document.getElementById('emp-notes').value  = e.notes || '';
  openModal('emp-modal');
}

async function saveEmployee() {
  const btn = document.getElementById('emp-save-btn');
  btn.textContent = 'Saving…'; btn.disabled = true;
  const d = await post('emp_save', {
    id:       document.getElementById('emp-id').value,
    emp_code: document.getElementById('emp-code').value,
    name:     document.getElementById('emp-name').value,
    role:     document.getElementById('emp-role').value,
    dept:     document.getElementById('emp-dept').value,
    email:    document.getElementById('emp-email').value,
    phone:    document.getElementById('emp-phone').value,
    status:   document.getElementById('emp-status').value,
    color:    document.getElementById('emp-color').value,
    salary:   document.getElementById('emp-salary').value,
    joined:   document.getElementById('emp-joined').value,
    notes:    document.getElementById('emp-notes').value,
  });
  btn.textContent = 'Save Employee'; btn.disabled = false;
  if (d.ok) { toast('Employee saved!'); closeModal('emp-modal'); loadEmpTable(); }
  else toast(d.msg || 'Save failed', 'err');
}

async function deleteEmp(id) {
  if (!confirm('Delete this employee? This cannot be undone.')) return;
  const d = await post('emp_delete', { id });
  if (d.ok) { toast('Employee deleted.'); loadEmpTable(); }
  else toast(d.msg || 'Delete failed', 'err');
}

async function exportCSV() {
  const d = await post('emp_list', {
    q:      document.getElementById('emp-search')?.value || '',
    dept:   document.getElementById('emp-dept-filter')?.value || '',
    status: document.getElementById('emp-status-filter')?.value || '',
    page:   1,
  });
  const allRows = [...(d.data || [])];
  const pages = d.pages || 1;
  for (let p = 2; p <= pages; p++) {
    const r = await post('emp_list', {
      q:      document.getElementById('emp-search')?.value || '',
      dept:   document.getElementById('emp-dept-filter')?.value || '',
      status: document.getElementById('emp-status-filter')?.value || '',
      page:   p,
    });
    allRows.push(...(r.data || []));
  }
  if (!allRows.length) { toast('Nothing to export', 'err'); return; }
  const cols  = ['emp_code','name','role','dept_name','email','phone','status','salary','joined'];
  const lines = [cols.join(','), ...allRows.map(r => cols.map(c => `"${(r[c]||'').toString().replace(/"/g,'""')}"`).join(','))];
  const a = document.createElement('a');
  a.href     = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(lines.join('\n'));
  a.download = 'employees_' + Date.now() + '.csv';
  a.click();
  toast(`Exported ${allRows.length} employees!`);
}

// ── DEPARTMENTS ───────────────────────────────────────────────
async function departments() {
  const c = document.getElementById('view-content');
  c.innerHTML = '<div class="spinner"></div>';
  const d = await post('dept_list');
  if (!d.ok) { c.innerHTML = `<p style="color:var(--r)">${esc(d.msg)}</p>`; return; }
  c.innerHTML = `
  <div class="page-header">
    <div class="page-header-left"><h1>Departments</h1><p>Organise your team structure</p></div>
    <div class="page-header-right"><button class="btn btn-primary btn-sm" onclick="openAddDept()">＋ Add Department</button></div>
  </div>
  <div class="cards-grid">${d.data.map((dept, i) => `
    <div class="item-card" style="border-top:3px solid ${esc(dept.color)};animation-delay:${i * .06}s">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px">
        <div style="width:38px;height:38px;border-radius:9px;background:${esc(dept.color)}22;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">🏢</div>
        <div style="display:flex;gap:5px">
          <button class="btn btn-ghost btn-xs" onclick='openEditDept(${JSON.stringify(dept).replace(/'/g,"&#39;")})'>✏️</button>
          <button class="btn btn-danger btn-xs" onclick="deleteDept(${dept.id})">🗑️</button>
        </div>
      </div>
      <div style="font-family:var(--font-head);font-weight:700;font-size:.97rem;margin-bottom:10px">${esc(dept.name)}</div>
      <span style="background:${esc(dept.color)}22;color:${esc(dept.color)};padding:3px 10px;border-radius:99px;font-size:.73rem;font-weight:700">${dept.cnt} member${dept.cnt != 1 ? 's' : ''}</span>
    </div>`).join('') || '<p style="color:var(--txt3)">No departments yet. Add one!</p>'}
  </div>`;
}

function openAddDept() {
  deptEditId = null;
  document.getElementById('dept-modal-title').textContent = 'Add Department';
  document.getElementById('dept-name').value  = '';
  document.getElementById('dept-color').value = '#6C63FF';
  openModal('dept-modal');
}
function openEditDept(d) {
  deptEditId = d.id;
  document.getElementById('dept-modal-title').textContent = 'Edit Department';
  document.getElementById('dept-name').value  = d.name;
  document.getElementById('dept-color').value = d.color || '#6C63FF';
  openModal('dept-modal');
}
async function saveDept() {
  const d = await post('dept_save', {
    id:    deptEditId || 0,
    name:  document.getElementById('dept-name').value,
    color: document.getElementById('dept-color').value,
  });
  if (d.ok) { toast('Department saved!'); closeModal('dept-modal'); departments(); }
  else toast(d.msg || 'Error', 'err');
}
async function deleteDept(id) {
  if (!confirm('Delete department? Employees will be unassigned.')) return;
  const d = await post('dept_delete', { id });
  if (d.ok) { toast('Department deleted.'); departments(); }
  else toast(d.msg || 'Error', 'err');
}

// ── PROJECTS ──────────────────────────────────────────────────
async function projects() {
  const c = document.getElementById('view-content');
  c.innerHTML = '<div class="spinner"></div>';
  const d = await post('proj_list');
  if (!d.ok) { c.innerHTML = `<p style="color:var(--r)">${esc(d.msg)}</p>`; return; }
  const statusCol = { Active:'var(--g)', Planning:'var(--b)', 'On Hold':'var(--y)', Completed:'var(--p-light)' };

  // Button label changes based on project status
  const workerBtnLabel = {
    Active:    '🟢 View Active Workers',
    Planning:  '👥 View Assigned Members',
    'On Hold': '⏸️ View Committed Members',
    Completed: '🏁 View Who Worked On This',
  };

  c.innerHTML = `
  <div class="page-header">
    <div class="page-header-left"><h1>Projects</h1><p>Track and manage team projects</p></div>
    <div class="page-header-right"><button class="btn btn-primary btn-sm" onclick="openAddProj()">＋ New Project</button></div>
  </div>
  <div class="cards-grid">${d.data.map((p, i) => {
    const sc  = statusCol[p.status] || 'var(--p-light)';
    const lbl = workerBtnLabel[p.status] || '👥 View Members';
    return `<div class="item-card" style="border-left:3px solid ${sc};animation-delay:${i * .07}s">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div style="flex:1;min-width:0">
          <div style="font-family:var(--font-head);font-weight:700;font-size:1rem;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</div>
          <span class="badge ${badgeCls(p.status)}">${esc(p.status)}</span>
        </div>
        <div style="display:flex;gap:4px;margin-left:8px;flex-shrink:0">
          <button class="btn btn-ghost btn-xs" onclick='openEditProj(${JSON.stringify(p).replace(/'/g,"&#39;")})' title="Edit project">✏️</button>
          <button class="btn btn-success btn-xs" onclick="openMembers(${p.id},'${esc(p.name)}')" title="Assign members">👥</button>
          <button class="btn btn-danger btn-xs" onclick="deleteProj(${p.id})" title="Delete project">🗑️</button>
        </div>
      </div>
      <p style="font-size:.8rem;color:var(--txt2);margin-bottom:13px;min-height:16px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">${esc(p.description||'')}</p>
      <div style="display:flex;gap:10px;font-size:.75rem;color:var(--txt3);flex-wrap:wrap;margin-bottom:12px">
        <span>👥 ${p.mem_count} member${p.mem_count != 1 ? 's' : ''}</span>
        ${p.start_date ? `<span>📅 ${fmtDate(p.start_date)}</span>` : ''}
        ${p.end_date   ? `<span>🏁 ${fmtDate(p.end_date)}</span>`   : ''}
      </div>
      <button class="btn btn-info btn-xs" style="width:100%;justify-content:center"
        onclick="openActiveMembers(${p.id}, '${esc(p.name)}', event)">
        ${lbl}
      </button>
    </div>`;
  }).join('') || '<p style="color:var(--txt3)">No projects yet. Create one!</p>'}
  </div>`;
}

function openAddProj() {
  projEditId = null;
  document.getElementById('proj-modal-title').textContent = 'New Project';
  ['proj-name','proj-desc','proj-start','proj-end'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('proj-status').value = 'Active';
  openModal('proj-modal');
}
function openEditProj(p) {
  projEditId = p.id;
  document.getElementById('proj-modal-title').textContent = 'Edit Project';
  document.getElementById('proj-name').value   = p.name;
  document.getElementById('proj-desc').value   = p.description || '';
  document.getElementById('proj-status').value = p.status;
  document.getElementById('proj-start').value  = p.start_date || '';
  document.getElementById('proj-end').value    = p.end_date   || '';
  openModal('proj-modal');
}
async function saveProject() {
  const d = await post('proj_save', {
    id:     projEditId || 0,
    name:   document.getElementById('proj-name').value,
    desc:   document.getElementById('proj-desc').value,
    status: document.getElementById('proj-status').value,
    start:  document.getElementById('proj-start').value,
    end:    document.getElementById('proj-end').value,
  });
  if (d.ok) { toast('Project saved!'); closeModal('proj-modal'); projects(); }
  else toast(d.msg || 'Error', 'err');
}
async function deleteProj(id) {
  if (!confirm('Delete this project?')) return;
  const d = await post('proj_delete', { id });
  if (d.ok) { toast('Project deleted.'); projects(); }
  else toast(d.msg || 'Error', 'err');
}

async function openMembers(pid, pname) {
  document.getElementById('members-modal-title').textContent = 'Members · ' + pname;
  document.getElementById('members-proj-id').value = pid;
  openModal('members-modal');
  document.getElementById('members-list').innerHTML = '<div class="spinner"></div>';
  const d = await post('proj_members_get', { pid });
  if (!d.ok) {
    document.getElementById('members-list').innerHTML = `<p style="padding:16px;color:var(--r)">${esc(d.msg)}</p>`;
    return;
  }
  document.getElementById('members-list').innerHTML = (d.all || []).map(e => `
    <label style="display:flex;align-items:center;gap:12px;padding:10px 4px;cursor:pointer;border-bottom:1px solid var(--border)">
      <input type="checkbox" name="member" value="${e.id}"
        ${(d.assigned || []).includes(Number(e.id)) ? 'checked' : ''}
        style="width:16px;height:16px;accent-color:var(--p);flex-shrink:0">
      <div class="avatar" style="background:${esc(e.avatar_color||'#6C63FF')};width:30px;height:30px;font-size:.72rem;border-radius:7px">${ini(e.name)}</div>
      <div>
        <div style="font-size:.85rem;font-weight:500">${esc(e.name)}</div>
        <div style="font-size:.74rem;color:var(--txt2)">${esc(e.role)}</div>
      </div>
    </label>`).join('') || '<p style="padding:20px;color:var(--txt3)">No employees found.</p>';
}

async function saveMembers() {
  const pid  = document.getElementById('members-proj-id').value;
  const eids = JSON.stringify(
    [...document.querySelectorAll('#members-list input[name=member]:checked')].map(c => c.value)
  );
  const d = await post('proj_members_save', { pid, eids });
  if (d.ok) { toast('Assignments saved!'); closeModal('members-modal'); projects(); }
  else toast(d.msg || 'Error', 'err');
}

// ── ACTIVE / CONTEXT-AWARE MEMBERS MODAL ─────────────────────
async function openActiveMembers(pid, pname, event) {
  if (event) event.stopPropagation();

  // Set placeholder title while loading
  document.getElementById('active-modal-title').textContent = 'Loading…';
  document.getElementById('active-modal-sub').textContent   = pname;
  document.getElementById('active-members-list').innerHTML  = '<div class="spinner"></div>';
  openModal('active-modal');

  const d = await post('proj_active_members', { pid });

  if (!d.ok) {
    document.getElementById('active-modal-title').textContent = 'Error';
    document.getElementById('active-members-list').innerHTML =
      `<p style="padding:16px;color:var(--r)">${esc(d.msg)}</p>`;
    return;
  }

  const rows       = d.data        || [];
  const projStatus = d.proj_status || 'Active';

  // Modal title per project status
  const titleMap = {
    Active:    'Active Workers',
    Completed: 'Who Worked On This',
    'On Hold': 'Committed Members',
    Planning:  'Assigned Members',
  };
  document.getElementById('active-modal-title').textContent = titleMap[projStatus] || 'Members';

  // Empty state per project status
  if (!rows.length) {
    const emptyMap = {
      Active:    'No active or remote workers on this project right now.',
      Completed: 'No members were assigned to this project.',
      'On Hold': 'No committed members on this project.',
      Planning:  'No members assigned yet.',
    };
    const emptyEmoji = { Active:'😴', Completed:'🏁', 'On Hold':'⏸️', Planning:'📋' };
    document.getElementById('active-members-list').innerHTML = `
      <div class="empty-state" style="padding:50px 30px">
        <div class="emoji">${emptyEmoji[projStatus] || '👥'}</div>
        <p style="margin-top:8px;font-size:.88rem">${emptyMap[projStatus] || 'No members found.'}</p>
      </div>`;
    return;
  }

  // ── COMPLETED: flat list of all who worked on it ──────────
  if (projStatus === 'Completed') {
    const html = `
      <div style="font-size:.68rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.1em;padding:4px 0 12px">
        Worked on this project (${rows.length})
      </div>` +
      rows.map(e => `
        <div class="active-member-row">
          <div style="width:8px;height:8px;border-radius:50%;background:var(--txt3);flex-shrink:0"></div>
          <div class="avatar" style="background:${esc(e.avatar_color||'#6C63FF')};width:36px;height:36px;border-radius:9px;font-size:.8rem">${ini(e.name)}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(e.name)}</div>
            <div style="font-size:.74rem;color:var(--txt2)">${esc(e.role)}${e.dept_name ? ' · ' + esc(e.dept_name) : ''}</div>
            ${e.email ? `<div style="font-size:.72rem;color:var(--txt3);margin-top:1px">${esc(e.email)}</div>` : ''}
          </div>
          <span class="badge ${badgeCls(e.status)}">${esc(e.status)}</span>
        </div>`).join('');
    document.getElementById('active-members-list').innerHTML = html;
    return;
  }

  // ── ACTIVE / ON HOLD / PLANNING: grouped by employee status ─
  const actives = rows.filter(e => e.status === 'Active');
  const remotes = rows.filter(e => e.status === 'Remote');
  const onLeave = rows.filter(e => e.status === 'On Leave');

  function memberRow(e, isOnline) {
    const dot = isOnline
      ? `<div class="online-dot"></div>`
      : `<div style="width:8px;height:8px;border-radius:50%;background:var(--y);flex-shrink:0;box-shadow:0 0 5px rgba(245,197,66,.5)"></div>`;
    return `
      <div class="active-member-row">
        ${dot}
        <div class="avatar" style="background:${esc(e.avatar_color||'#6C63FF')};width:36px;height:36px;border-radius:9px;font-size:.8rem">${ini(e.name)}</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(e.name)}</div>
          <div style="font-size:.74rem;color:var(--txt2)">${esc(e.role)}${e.dept_name ? ' · ' + esc(e.dept_name) : ''}</div>
          ${e.email ? `<div style="font-size:.72rem;color:var(--txt3);margin-top:1px">${esc(e.email)}</div>` : ''}
        </div>
        <span class="badge ${badgeCls(e.status)}">${esc(e.status)}</span>
      </div>`;
  }

  function sectionHeader(label, count, topPad) {
    return `<div style="font-size:.68rem;font-weight:700;color:var(--txt3);text-transform:uppercase;letter-spacing:.1em;padding:${topPad ? '16px' : '4px'} 0 8px">${label} (${count})</div>`;
  }

  let html = '';
  if (actives.length) {
    html += sectionHeader('In Office', actives.length, false);
    html += actives.map(e => memberRow(e, true)).join('');
  }
  if (remotes.length) {
    html += sectionHeader('Remote', remotes.length, actives.length > 0);
    html += remotes.map(e => memberRow(e, true)).join('');
  }
  if (onLeave.length) {
    html += sectionHeader('On Leave', onLeave.length, actives.length > 0 || remotes.length > 0);
    html += onLeave.map(e => memberRow(e, false)).join('');
  }

  document.getElementById('active-members-list').innerHTML = html;
}

// ── ACTIVITY ──────────────────────────────────────────────────
async function activity() {
  const c = document.getElementById('view-content');
  c.innerHTML = '<div class="spinner"></div>';
  const d = await post('activity_list');
  if (!d.ok) { c.innerHTML = `<p style="color:var(--r)">${esc(d.msg)}</p>`; return; }
  const rows = d.data || [];
  const actionCol = { ADD:'var(--g)', UPDATE:'var(--y)', DELETE:'var(--r)', LOGIN:'var(--b)', LOGOUT:'var(--txt3)' };
  c.innerHTML = `
  <div class="page-header">
    <div class="page-header-left"><h1>Activity Log</h1><p>${rows.length} recent events</p></div>
  </div>
  <div class="table-card" style="padding:0;overflow:hidden">
    <div class="tbl-scroll"><table>
      <thead><tr><th>#</th><th>Time</th><th>Admin</th><th>IP Address</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>${rows.length ? rows.map((l, i) => {
        const col = actionCol[l.action?.split('_')[0]] || 'var(--p-light)';
        return `<tr>
          <td style="color:var(--txt3);font-size:.75rem">${i + 1}</td>
          <td style="font-size:.77rem;color:var(--txt2);white-space:nowrap">${new Date(l.created_at).toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}</td>
          <td style="font-size:.82rem">${esc(l.admin_name||'System')}</td>
          <td style="font-size:.77rem;color:var(--txt3)">${esc(l.ip_address||'—')}</td>
          <td><span style="background:${col}22;color:${col};padding:2px 9px;border-radius:99px;font-size:.71rem;font-weight:700">${esc(l.action)}</span></td>
          <td style="font-size:.78rem;color:var(--txt2)">${esc(l.details||'—')}</td>
        </tr>`;
      }).join('') : '<tr><td colspan="6" style="padding:50px;text-align:center;color:var(--txt3)">No activity recorded yet.</td></tr>'
      }</tbody>
    </table></div>
  </div>`;
}

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const firstLink = document.querySelector('.sb-link[data-view="dashboard"]');
  if (firstLink) firstLink.classList.add('active');
  dashboard();
});
</script>

<?php endif; ?>
</body>
</html>