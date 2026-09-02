<?php
/**
 * process.php — Appointment Validation & Processing
 * Handles AJAX requests from index.php — Always returns JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php'; // provides $pdo

function json_respond(string $status, string $message, array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

function clean_str(string $val): string
{
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}
function clean_int(mixed $val): int
{
    return (int) filter_var($val, FILTER_SANITIZE_NUMBER_INT);
}

function validate_appointment(array $data): array
{
    $errors = [];

    if (empty($data['doctor_id']) || $data['doctor_id'] < 1)
        $errors['doctor_id'] = 'Please select a doctor.';

    if (empty($data['full_name']) || strlen($data['full_name']) < 3)
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    elseif (!preg_match('/^[\p{L} \'\-]{3,140}$/u', $data['full_name']))
        $errors['full_name'] = 'Name contains invalid characters.';

    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Please enter a valid email address.';

    $phone = preg_replace('/[\s\-\(\)]+/', '', $data['phone'] ?? '');
    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone))
        $errors['phone'] = 'Phone number is invalid (7–15 digits).';

    if (!empty($data['dob'])) {
        $dob = DateTime::createFromFormat('Y-m-d', $data['dob']);
        if (!$dob || $dob > new DateTime())
            $errors['dob'] = 'Date of birth is invalid.';
    }

    if (empty($data['appt_date'])) {
        $errors['appt_date'] = 'Please select an appointment date.';
    } else {
        $apptDate = DateTime::createFromFormat('Y-m-d', $data['appt_date']);
        if (!$apptDate)
            $errors['appt_date'] = 'Invalid date format.';
        elseif ($apptDate < new DateTime('today'))
            $errors['appt_date'] = 'Appointment date cannot be in the past.';
        elseif ($apptDate > new DateTime('+6 months'))
            $errors['appt_date'] = 'Cannot schedule more than 6 months ahead.';
    }

    if (empty($data['appt_time'])) {
        $errors['appt_time'] = 'Please select a time slot.';
    } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data['appt_time'])) {
        $errors['appt_time'] = 'Invalid time format.';
    } elseif (!empty($data['appt_date']) && $data['appt_date'] === date('Y-m-d')) {
        $slot = DateTime::createFromFormat('H:i', $data['appt_time']);
        if ($slot && $slot < new DateTime('+30 minutes'))
            $errors['appt_time'] = 'Choose a slot at least 30 minutes from now.';
    }

    if (!empty($data['reason']) && strlen(trim($data['reason'])) > 0 && strlen(trim($data['reason'])) < 10)
        $errors['reason'] = 'Reason is too short — write at least 10 characters or leave it blank.';

    return $errors;
}

// ── GET ?action=doctors ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'doctors') {
    global $pdo;
    $rows = $pdo->query(
        'SELECT id, name, specialty FROM doctors WHERE available = 1 ORDER BY specialty, name'
    )->fetchAll();
    json_respond('success', '', ['doctors' => $rows]);
}

// ── GET ?action=doctor_appointments&doctor_id=N ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'doctor_appointments') {
    global $pdo;
    $doctor_id = clean_int($_GET['doctor_id'] ?? 0);
    if (!$doctor_id) json_respond('error', 'Doctor ID required.');
    $rows = $pdo->prepare(
        "SELECT a.id, p.full_name, p.email, p.phone,
                a.appt_date, a.appt_time, a.reason, a.status,
                a.created_at, a.updated_at
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         WHERE a.doctor_id = ?
         ORDER BY
           FIELD(a.status,'pending','confirmed','viewed','diagnosed','cancelled','completed'),
           a.appt_date ASC, a.appt_time ASC"
    );
    $rows->execute([$doctor_id]);
    json_respond('success', '', ['appointments' => $rows->fetchAll()]);
}

// ── GET ?action=appointments (all, with filters) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'appointments') {
    global $pdo;
    $status = clean_str($_GET['status'] ?? '');
    $valid_statuses = ['pending','viewed','diagnosed','confirmed','cancelled','completed'];
    $where = '';
    $params = [];
    if ($status && in_array($status, $valid_statuses, true)) {
        $where = 'WHERE a.status = ?';
        $params[] = $status;
    }
    $rows = $pdo->prepare(
        "SELECT a.id, p.full_name, p.email, p.phone, d.name AS doctor, d.specialty,
                a.appt_date, a.appt_time, a.reason, a.status, a.created_at, a.updated_at
         FROM appointments a
         JOIN patients  p ON p.id = a.patient_id
         JOIN doctors   d ON d.id = a.doctor_id
         $where
         ORDER BY a.appt_date ASC, a.appt_time ASC"
    );
    $rows->execute($params);
    json_respond('success', '', ['appointments' => $rows->fetchAll()]);
}

// ── POST ?action=update_status ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'update_status') {
    global $pdo;
    $appt_id    = clean_int($_POST['appt_id'] ?? 0);
    $new_status = clean_str($_POST['status'] ?? '');
    $valid = ['confirmed', 'viewed', 'diagnosed', 'cancelled', 'completed'];
    if (!$appt_id || !in_array($new_status, $valid, true))
        json_respond('error', 'Invalid request.');
    $stmt = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $stmt->execute([$new_status, $appt_id]);
    if (!$stmt->rowCount())
        json_respond('error', 'Appointment not found.');
    $pdo->prepare('INSERT INTO validation_log (appointment_id, action, performed_by) VALUES (?, ?, ?)')
        ->execute([$appt_id, $new_status, 'staff']);
    json_respond('success', 'Status updated.');
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'recent') {
    global $pdo;
    $rows = $pdo->query(
        "SELECT a.id, p.full_name, d.name AS doctor, d.specialty,
                a.appt_date, a.appt_time, a.status, a.created_at
         FROM appointments a
         JOIN patients  p ON p.id = a.patient_id
         JOIN doctors   d ON d.id = a.doctor_id
         ORDER BY a.created_at DESC LIMIT 20"
    )->fetchAll();
    json_respond('success', '', ['appointments' => $rows]);
}

// ── POST — Book Appointment ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $pdo;

    $data = [
        'doctor_id' => clean_int($_POST['doctor_id'] ?? 0),
        'full_name' => clean_str($_POST['full_name'] ?? ''),
        'email'     => strtolower(clean_str($_POST['email'] ?? '')),
        'phone'     => clean_str($_POST['phone'] ?? ''),
        'dob'       => clean_str($_POST['dob'] ?? ''),
        'appt_date' => clean_str($_POST['appt_date'] ?? ''),
        'appt_time' => clean_str($_POST['appt_time'] ?? ''),
        'reason'    => clean_str($_POST['reason'] ?? ''),
    ];

    $errors = validate_appointment($data);
    if (!empty($errors))
        json_respond('validation', 'Please fix the errors below.', ['errors' => $errors]);

    $stmt = $pdo->prepare('SELECT * FROM doctors WHERE id = ? AND available = 1 LIMIT 1');
    $stmt->execute([$data['doctor_id']]);
    $doctor = $stmt->fetch();
    if (!$doctor)
        json_respond('validation', 'Selected doctor not found.', ['errors' => ['doctor_id' => 'Doctor unavailable.']]);

    try {
        $sp = $pdo->prepare(
            'CALL sp_book_appointment(?,?,?,?,?,?,?,?,@p_status,@p_message,@p_appt_id)'
        );
        $sp->execute([
            $data['doctor_id'],
            $data['full_name'],
            $data['email'],
            $data['phone'],
            !empty($data['dob']) ? $data['dob'] : null,
            $data['appt_date'],
            $data['appt_time'],
            $data['reason'],
        ]);
        $sp->closeCursor();

        $out = $pdo->query('SELECT @p_status AS st, @p_message AS msg, @p_appt_id AS aid')->fetch();

        if ($out['st'] !== 'success') {
            $fieldMap = [
                'conflict' => ['appt_time' => $out['msg']],
                'invalid'  => ['appt_date' => $out['msg']],
            ];
            json_respond('validation', $out['msg'], ['errors' => $fieldMap[$out['st']] ?? []]);
        }

        json_respond('success', 'Appointment booked!', [
            'appointment_id' => (int) $out['aid'],
            'doctor'         => $doctor['name'],
            'specialty'      => $doctor['specialty'],
            'patient'        => $data['full_name'],
            'appt_date'      => $data['appt_date'],
            'appt_time'      => $data['appt_time'],
        ]);

    } catch (PDOException $e) {
        json_respond('error', 'Could not save appointment. Please try again.');
    }
}

json_respond('error', 'Invalid request.');