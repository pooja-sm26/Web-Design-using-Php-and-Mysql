-- ============================================================
-- Doctor Appointment System — Fixed Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS doctor_appointments
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE doctor_appointments;

-- =========================================
-- DOCTORS TABLE
-- =========================================
CREATE TABLE IF NOT EXISTS doctors (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120)  NOT NULL,
  specialty   VARCHAR(100)  NOT NULL,
  email       VARCHAR(180)  NOT NULL UNIQUE,
  phone       VARCHAR(20)   NOT NULL,
  avatar      VARCHAR(255)  DEFAULT NULL,
  available   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================
-- PATIENTS TABLE
-- =========================================
CREATE TABLE IF NOT EXISTS patients (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name   VARCHAR(140)  NOT NULL,
  email       VARCHAR(180)  NOT NULL,
  phone       VARCHAR(20)   NOT NULL,
  dob         DATE          DEFAULT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =========================================
-- APPOINTMENTS TABLE
-- =========================================
CREATE TABLE IF NOT EXISTS appointments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doctor_id   INT UNSIGNED NOT NULL,
  patient_id  INT UNSIGNED NOT NULL,
  appt_date   DATE         NOT NULL,
  appt_time   TIME         NOT NULL,
  reason      TEXT         NOT NULL,
  status      ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
  notes       TEXT         DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_appt_doctor  FOREIGN KEY (doctor_id)  REFERENCES doctors(id)  ON DELETE CASCADE,
  CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  UNIQUE KEY uq_doctor_slot (doctor_id, appt_date, appt_time)
) ENGINE=InnoDB;

-- =========================================
-- VALIDATION LOG TABLE
-- =========================================
CREATE TABLE IF NOT EXISTS validation_log (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT UNSIGNED NOT NULL,
  action         ENUM('created','confirmed','cancelled','completed') NOT NULL,
  performed_by   VARCHAR(120) DEFAULT 'system',
  note           TEXT         DEFAULT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================================
-- SAMPLE DOCTORS
-- INSERT IGNORE: skips rows that already exist (duplicate email),
-- so re-running this script never throws a duplicate key error.
-- =========================================
INSERT IGNORE INTO doctors (name, specialty, email, phone) VALUES
  ('Dr. Arjun Mehta',    'Cardiologist',      'arjun.mehta@clinic.com',   '+91-98400-11111'),
  ('Dr. Priya Sharma',   'Dermatologist',     'priya.sharma@clinic.com',  '+91-98400-22222'),
  ('Dr. Karthik Rajan',  'Orthopedic',        'karthik.rajan@clinic.com', '+91-98400-33333'),
  ('Dr. Meena Iyer',     'Neurologist',       'meena.iyer@clinic.com',    '+91-98400-44444'),
  ('Dr. Suresh Babu',    'General Physician', 'suresh.babu@clinic.com',   '+91-98400-55555');

-- =========================================
-- STORED PROCEDURE
-- FIX 1: Renamed label from sp_book_appointment → main_block
--         (original label name matched procedure name — causes parse error)
-- FIX 2: SELECT..INTO must use CONTINUE HANDLER or
--         have NO ROWS guard; added IGNORE to prevent
--         "no data" condition crashing the procedure
-- FIX 3: LEAVE label must match BEGIN label exactly
-- =========================================

DROP PROCEDURE IF EXISTS sp_book_appointment;

DELIMITER $$

CREATE PROCEDURE sp_book_appointment(
  IN  p_doctor_id   INT UNSIGNED,
  IN  p_full_name   VARCHAR(140),
  IN  p_email       VARCHAR(180),
  IN  p_phone       VARCHAR(20),
  IN  p_dob         DATE,
  IN  p_appt_date   DATE,
  IN  p_appt_time   TIME,
  IN  p_reason      TEXT,
  OUT p_status      VARCHAR(50),
  OUT p_message     VARCHAR(255),
  OUT p_appt_id     INT UNSIGNED
)

main_block: BEGIN

  DECLARE v_patient_id  INT UNSIGNED DEFAULT NULL;
  DECLARE v_conflict    INT          DEFAULT 0;

  -- FIX 4: CONTINUE handler for NOT FOUND prevents SELECT..INTO
  --        from raising an error when no patient row is found
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_patient_id = NULL;

  -- FIX 5: EXIT handler for SQL exceptions must come AFTER
  --        CONTINUE handler declaration
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_status  = 'error';
    SET p_message = 'Database error occurred. Please try again.';
    SET p_appt_id = NULL;
  END;

  START TRANSACTION;

  -- ── Validate: no past dates ──────────────────────────────
  IF p_appt_date < CURDATE() THEN
    SET p_status  = 'invalid';
    SET p_message = 'Appointment date cannot be in the past.';
    SET p_appt_id = NULL;
    ROLLBACK;
    LEAVE main_block;
  END IF;

  -- ── Check slot conflict ───────────────────────────────────
  SELECT COUNT(*)
  INTO   v_conflict
  FROM   appointments
  WHERE  doctor_id  = p_doctor_id
    AND  appt_date  = p_appt_date
    AND  appt_time  = p_appt_time
    AND  status    != 'cancelled';

  IF v_conflict > 0 THEN
    SET p_status  = 'conflict';
    SET p_message = 'This time slot is already booked. Please choose another.';
    SET p_appt_id = NULL;
    ROLLBACK;
    LEAVE main_block;
  END IF;

  -- ── Find existing patient by email ────────────────────────
  -- FIX 6: Reset v_patient_id before SELECT..INTO so the
  --        CONTINUE handler NULL assignment is reliable
  SET v_patient_id = NULL;

  SELECT id
  INTO   v_patient_id
  FROM   patients
  WHERE  email = p_email
  LIMIT  1;

  -- ── Insert new patient if not found ──────────────────────
  IF v_patient_id IS NULL THEN
    INSERT INTO patients (full_name, email, phone, dob)
    VALUES (p_full_name, p_email, p_phone, p_dob);
    SET v_patient_id = LAST_INSERT_ID();
  END IF;

  -- ── Insert appointment ────────────────────────────────────
  INSERT INTO appointments (doctor_id, patient_id, appt_date, appt_time, reason, status)
  VALUES (p_doctor_id, v_patient_id, p_appt_date, p_appt_time, p_reason, 'pending');

  SET p_appt_id = LAST_INSERT_ID();

  -- ── Log the action ────────────────────────────────────────
  INSERT INTO validation_log (appointment_id, action, performed_by)
  VALUES (p_appt_id, 'created', p_email);

  COMMIT;

  SET p_status  = 'success';
  SET p_message = 'Appointment booked successfully!';

END$$

DELIMITER ;

-- =========================================
-- TEST THE PROCEDURE
-- FIX 7: Wrapped in its own transaction-safe call
--        and added SELECT to view all output vars
-- =========================================

CALL sp_book_appointment(
  1,                    -- doctor_id
  'Pooja',              -- full_name
  'pooja@gmail.com',    -- email
  '9876543210',         -- phone
  '2003-06-10',         -- dob
  '2026-06-01',         -- appt_date  (must be today or future)
  '10:00:00',           -- appt_time
  'Fever and headache', -- reason
  @status,
  @message,
  @appt_id
);

SELECT
  @status  AS booking_status,
  @message AS booking_message,
  @appt_id AS appointment_id;
