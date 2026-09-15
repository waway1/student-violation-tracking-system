-- =====================================================================
-- VTS UPGRADE — 2026-07c  (roster + security)
-- Patakbuhin ITO sa phpMyAdmin PAGKATAPOS ng student_violation_system.sql
-- Ligtas patakbuhin kahit ilang beses (IF NOT EXISTS).
--
-- Bakit kailangan:
--   student_roster  -> ang opisyal na listahan ng enrolled students.
--                      WALANG makaka-register kung walang laman ito.
--   login_attempts  -> brute-force / bot lockout sa login.
--
-- (Kusa ring ginagawa ng PHP ang mga ito, pero mas malinis na dito na
--  gawin — para hindi na kailangan ng CREATE permission ang DB user mo.)
-- =====================================================================

CREATE TABLE IF NOT EXISTS student_roster (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id   VARCHAR(10)  NOT NULL UNIQUE,
    lastname    VARCHAR(60)  NOT NULL,
    firstname   VARCHAR(60)  NULL,
    course      VARCHAR(150) NULL,
    year_level  VARCHAR(20)  NULL,
    is_used     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_roster_lastname (lastname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(100) NULL,
    ip           VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_ip   (ip, attempted_at),
    INDEX idx_la_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
