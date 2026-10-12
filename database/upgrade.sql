-- =============================================================================
-- EMPLOYEE PERFORMANCE EVALUATION SYSTEM
-- database/upgrade.sql
--
-- Brings a database imported from an older schema.sql up to date WITHOUT
-- losing any data. Safe to run more than once: each step checks first.
-- (A fresh install does not need it: schema.sql already has all of this.)
--
-- Import it with phpMyAdmin (select the database, Import tab) or:
--     mysql -u root employee_evaluation_db < database/upgrade.sql
-- Runs on XAMPP's MariaDB 10.4+ and on MySQL 8.0+.
--
-- What it changes:
--   1. audit_logs: the ip_address column is removed (no IP addresses are kept).
--   2. roles: a number_prefix column, the tag that starts the employee numbers
--      of the role (CEO, ADM, EMP). Custom roles get EMP; add a new role to
--      use another tag.
--   3. employees: numbers get the tag of their role, keeping their digits,
--      e.g. EMP1001 (the CEO) becomes CEO1001 and EMP1002 (Admin) ADM1002.
--      Those people then log in with the new number.
--   4. permissions: system_log.view (Admin > System Log), given to every role
--      that can view the audit log; employees.manage now also says that it
--      shows employee numbers.
--   5. Account lockout removed: the employees columns failed_attempts and
--      locked_until and the settings max_login_attempts and lockout_minutes
--      are deleted; pins.reset no longer mentions unlocking.
-- =============================================================================

USE employee_evaluation_db;

-- 1. No IP addresses in the audit log.
SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'ip_address'),
    'ALTER TABLE audit_logs DROP COLUMN ip_address',
    'DO 0');
PREPARE step FROM @sql;
EXECUTE step;
DEALLOCATE PREPARE step;

-- 2. Number tag per role.
SET @sql := IF(
    NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'number_prefix'),
    'ALTER TABLE roles ADD COLUMN number_prefix VARCHAR(5) NOT NULL DEFAULT ''EMP'' AFTER name',
    'DO 0');
PREPARE step FROM @sql;
EXECUTE step;
DEALLOCATE PREPARE step;

UPDATE roles SET number_prefix = 'CEO' WHERE name = 'CEO'   AND is_system = 1;
UPDATE roles SET number_prefix = 'ADM' WHERE name = 'Admin' AND is_system = 1;

-- New roles must name their tag, so the column keeps no default.
ALTER TABLE roles ALTER COLUMN number_prefix DROP DEFAULT;

SET @sql := IF(
    NOT EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND CONSTRAINT_NAME = 'CHK_roles_number_prefix'),
    'ALTER TABLE roles ADD CONSTRAINT CHK_roles_number_prefix CHECK (number_prefix REGEXP ''^[A-Za-z]{2,5}$'')',
    'DO 0');
PREPARE step FROM @sql;
EXECUTE step;
DEALLOCATE PREPARE step;

-- 3. Employee numbers start with their role's tag. Only numbers written as
--    letters + 4 to 6 digits are changed; anything else is left for HR to fix
--    on the Edit Employee page.
UPDATE employees e
JOIN roles r ON r.id = e.role_id
SET e.employee_number = CONCAT(r.number_prefix, REGEXP_SUBSTR(e.employee_number, '[0-9]+$'))
WHERE e.employee_number REGEXP '^[A-Za-z]{2,5}[0-9]{4,6}$'
  AND REGEXP_SUBSTR(e.employee_number, '^[A-Za-z]+') <> r.number_prefix;

-- 4. Permissions.
INSERT IGNORE INTO permissions (code, description, group_name, sort_order) VALUES
    ('system_log.view', 'View the system log (errors and other problems)', 'Administration', 135);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, s.id
FROM role_permissions rp
JOIN permissions a ON a.id = rp.permission_id AND a.code = 'audit.view'
JOIN permissions s ON s.code = 'system_log.view';

UPDATE permissions
SET description = 'Add, edit, import, activate and deactivate employees; see employee numbers'
WHERE code = 'employees.manage';

-- 5. No account lockout.
SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'failed_attempts'),
    'ALTER TABLE employees DROP COLUMN failed_attempts',
    'DO 0');
PREPARE step FROM @sql;
EXECUTE step;
DEALLOCATE PREPARE step;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'locked_until'),
    'ALTER TABLE employees DROP COLUMN locked_until',
    'DO 0');
PREPARE step FROM @sql;
EXECUTE step;
DEALLOCATE PREPARE step;

DELETE FROM settings WHERE setting_key IN ('max_login_attempts', 'lockout_minutes');

UPDATE permissions SET description = 'Reset employee PINs' WHERE code = 'pins.reset';


-- =============================================================================
-- VERIFICATION (optional)
-- =============================================================================

SELECT id, name, number_prefix FROM roles ORDER BY id;

SELECT e.employee_number, r.name AS role
FROM employees e
JOIN roles r ON r.id = e.role_id
ORDER BY e.id
LIMIT 5;

SELECT r.name AS role
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'system_log.view';
