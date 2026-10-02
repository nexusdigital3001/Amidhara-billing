-- Amidhara Billing — optional hardening table (login rate limiting + audit)
--
-- SAFE: creates two NEW tables only. Touches no existing table, no existing
-- data, no existing column. Running it cannot break the live app.
--
-- Run once:  phpMyAdmin -> your database -> SQL tab -> paste -> Go

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45)  NOT NULL,
  username     VARCHAR(60)  NOT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lookup (ip, username, success, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  username   VARCHAR(60) NULL,
  action     VARCHAR(60)  NOT NULL,   -- e.g. invoice.delete, data.reset
  target     VARCHAR(120) NULL,       -- e.g. ASC/01/2026-27
  detail     TEXT NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action (action, created_at),
  INDEX idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'login_attempts + audit_log created' AS result;
