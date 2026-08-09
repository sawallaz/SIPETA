-- SIPETA MySQL application user setup (Phase 1.5)
--
-- Run this ONCE against your MySQL server (as root) BEFORE deploying:
--   mysql -u root -p < scripts/db-user.sql
--
-- Uses CREATE USER IF NOT EXISTS so it is safe to re-run. It does NOT drop
-- any existing user, so it will not break an already-running application.
--
-- NOTE: replace 'CHANGE_ME' with a strong password. This file is committed
-- with a placeholder on purpose — never put a real password in version control.

CREATE USER IF NOT EXISTS 'sipeta_app'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON sipeta.* TO 'sipeta_app'@'localhost';
FLUSH PRIVILEGES;
