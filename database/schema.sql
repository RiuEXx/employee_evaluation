-- =============================================================================
-- EMPLOYEE PERFORMANCE EVALUATION SYSTEM
-- database/schema.sql
--
-- Creates the database, every table (parents before children), the indexes,
-- and the seed data the application needs: roles, permissions, settings,
-- departments, employees, rating categories, criteria and two evaluation
-- periods (one open, one draft).
--
-- Runs on XAMPP's MariaDB 10.4+ and on MySQL 8.0+.
-- Import it with phpMyAdmin (Import tab) or the mysql client:
--     mysql -u root < database/schema.sql
-- It DROPS and re-creates the database, so importing it again resets
-- everything. Afterwards, import database/seed.sql (optional) for a history
-- of sample evaluations so the CEO dashboards have data to show.
--
-- Every seeded account uses the PIN 1234 and must change it on first login.
-- =============================================================================

DROP DATABASE IF EXISTS employee_evaluation_db;

CREATE DATABASE employee_evaluation_db
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE employee_evaluation_db;


-- =============================================================================
-- 1. ORGANISATION: departments, roles, permissions
-- =============================================================================

CREATE TABLE departments (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100)  NOT NULL,
    description VARCHAR(255)  NULL,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT PK_departments PRIMARY KEY (id),
    CONSTRAINT UQ_departments_name UNIQUE (name),
    CONSTRAINT CHK_departments_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Groups of employees. Employees evaluate the mates in their own department.';

CREATE TABLE roles (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(50)   NOT NULL,
    description VARCHAR(255)  NULL,
    is_system   TINYINT(1)    NOT NULL DEFAULT 0,

    CONSTRAINT PK_roles PRIMARY KEY (id),
    CONSTRAINT UQ_roles_name UNIQUE (name)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'User roles. is_system = 1 marks the built-in roles, which cannot be deleted.';

CREATE TABLE permissions (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code        VARCHAR(60)   NOT NULL,
    description VARCHAR(255)  NOT NULL,
    group_name  VARCHAR(50)   NOT NULL,
    sort_order  INT           NOT NULL DEFAULT 0,

    CONSTRAINT PK_permissions PRIMARY KEY (id),
    CONSTRAINT UQ_permissions_code UNIQUE (code)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Privileges the PHP code checks. Pages ask for a permission code, never a role name.';

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,

    CONSTRAINT PK_role_permissions PRIMARY KEY (role_id, permission_id),
    CONSTRAINT FK_role_permissions_roles
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT FK_role_permissions_permissions
        FOREIGN KEY (permission_id) REFERENCES permissions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Which permissions each role has (many-to-many).';


-- =============================================================================
-- 2. EMPLOYEES (also the user accounts)
-- =============================================================================

CREATE TABLE employees (
    id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    employee_number  VARCHAR(20)       NOT NULL,
    first_name       VARCHAR(60)       NOT NULL,
    last_name        VARCHAR(60)       NOT NULL,
    email            VARCHAR(150)      NULL,
    position         VARCHAR(100)      NULL,
    department_id    INT UNSIGNED      NULL,
    role_id          INT UNSIGNED      NOT NULL,
    pin_hash         VARCHAR(255)      NOT NULL,
    must_change_pin  TINYINT(1)        NOT NULL DEFAULT 1,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    failed_attempts  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    locked_until     DATETIME          NULL,
    last_login       DATETIME          NULL,
    date_hired       DATE              NULL,
    created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT PK_employees PRIMARY KEY (id),
    CONSTRAINT UQ_employees_number UNIQUE (employee_number),
    CONSTRAINT UQ_employees_email UNIQUE (email),
    CONSTRAINT FK_employees_departments
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT FK_employees_roles
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT CHK_employees_number CHECK (CHAR_LENGTH(TRIM(employee_number)) > 0),
    CONSTRAINT CHK_employees_names  CHECK (CHAR_LENGTH(TRIM(first_name)) > 0 AND CHAR_LENGTH(TRIM(last_name)) > 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Employees and their login data. PINs are stored only as password_hash() hashes.';

CREATE INDEX IDX_employees_name       ON employees (last_name, first_name);
CREATE INDEX IDX_employees_status     ON employees (status);
CREATE INDEX IDX_employees_department ON employees (department_id, status);


-- =============================================================================
-- 3. EVALUATION SET-UP: periods, categories, criteria
-- =============================================================================

CREATE TABLE evaluation_periods (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title       VARCHAR(100)  NOT NULL,
    start_date  DATE          NOT NULL,
    end_date    DATE          NOT NULL,
    status      ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
    -- 1 while the period is open, NULL otherwise. The UNIQUE key below allows
    -- many NULLs but only one 1, so the database itself guarantees that only
    -- one period can be open at a time.
    open_lock   TINYINT UNSIGNED AS (CASE WHEN status = 'open' THEN 1 ELSE NULL END) STORED,
    created_by  INT UNSIGNED  NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT PK_evaluation_periods PRIMARY KEY (id),
    CONSTRAINT UQ_evaluation_periods_one_open UNIQUE (open_lock),
    CONSTRAINT FK_evaluation_periods_created_by
        FOREIGN KEY (created_by) REFERENCES employees (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT CHK_evaluation_periods_dates CHECK (end_date >= start_date),
    CONSTRAINT CHK_evaluation_periods_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Evaluation rounds. Evaluations can only be submitted while a period is open.';

CREATE INDEX IDX_evaluation_periods_start ON evaluation_periods (start_date);

CREATE TABLE evaluation_categories (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100)  NOT NULL,
    description VARCHAR(255)  NULL,
    weight      DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
    allow_na    TINYINT(1)    NOT NULL DEFAULT 0,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order  INT           NOT NULL DEFAULT 0,

    CONSTRAINT PK_evaluation_categories PRIMARY KEY (id),
    CONSTRAINT UQ_evaluation_categories_name UNIQUE (name),
    CONSTRAINT CHK_evaluation_categories_weight CHECK (weight > 0 AND weight <= 100)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Rating categories. weight is relative: the overall score is the weighted average of category averages. allow_na lets evaluators answer Not Applicable.';

CREATE TABLE evaluation_criteria (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    category_id   INT UNSIGNED  NOT NULL,
    question_text VARCHAR(255)  NOT NULL,
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order    INT           NOT NULL DEFAULT 0,

    CONSTRAINT PK_evaluation_criteria PRIMARY KEY (id),
    CONSTRAINT FK_evaluation_criteria_categories
        FOREIGN KEY (category_id) REFERENCES evaluation_categories (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Optional rating items inside a category. A category with active criteria is rated item by item; its score is their average.';

CREATE INDEX IDX_evaluation_criteria_category ON evaluation_criteria (category_id, sort_order);


-- =============================================================================
-- 4. EVALUATIONS AND SCORES
-- =============================================================================

CREATE TABLE evaluations (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    period_id       INT UNSIGNED  NOT NULL,
    evaluator_id    INT UNSIGNED  NOT NULL,
    evaluatee_id    INT UNSIGNED  NOT NULL,
    -- The evaluatee's department when the evaluation was made, so results stay
    -- correct after a department transfer.
    department_id   INT UNSIGNED  NOT NULL,
    overall_comment TEXT          NULL,
    submitted_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NULL,

    CONSTRAINT PK_evaluations PRIMARY KEY (id),
    -- One evaluation per evaluator, per evaluatee, per period.
    CONSTRAINT UQ_evaluations_once UNIQUE (period_id, evaluator_id, evaluatee_id),
    CONSTRAINT FK_evaluations_periods
        FOREIGN KEY (period_id) REFERENCES evaluation_periods (id)
        ON DELETE RESTRICT,
    CONSTRAINT FK_evaluations_evaluator
        FOREIGN KEY (evaluator_id) REFERENCES employees (id)
        ON DELETE RESTRICT,
    CONSTRAINT FK_evaluations_evaluatee
        FOREIGN KEY (evaluatee_id) REFERENCES employees (id)
        ON DELETE RESTRICT,
    CONSTRAINT FK_evaluations_departments
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT,
    CONSTRAINT CHK_evaluations_not_self CHECK (evaluator_id <> evaluatee_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'One submitted peer evaluation. The evaluator is stored but never shown to the evaluatee.';

CREATE INDEX IDX_evaluations_evaluatee  ON evaluations (period_id, evaluatee_id);
CREATE INDEX IDX_evaluations_department ON evaluations (period_id, department_id);
CREATE INDEX IDX_evaluations_evaluator  ON evaluations (evaluator_id);

CREATE TABLE evaluation_scores (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    evaluation_id INT UNSIGNED      NOT NULL,
    category_id   INT UNSIGNED      NOT NULL,
    criteria_id   INT UNSIGNED      NULL,
    score         TINYINT UNSIGNED  NOT NULL,
    -- criteria_id with NULL folded to 0, so the UNIQUE key below also works
    -- for scores given to a whole category (NULLs never collide in a UNIQUE key).
    criteria_key  INT UNSIGNED AS (IFNULL(criteria_id, 0)) STORED,

    CONSTRAINT PK_evaluation_scores PRIMARY KEY (id),
    CONSTRAINT UQ_evaluation_scores_item UNIQUE (evaluation_id, category_id, criteria_key),
    CONSTRAINT FK_evaluation_scores_evaluations
        FOREIGN KEY (evaluation_id) REFERENCES evaluations (id)
        ON DELETE CASCADE,
    CONSTRAINT FK_evaluation_scores_categories
        FOREIGN KEY (category_id) REFERENCES evaluation_categories (id)
        ON DELETE RESTRICT,
    CONSTRAINT FK_evaluation_scores_criteria
        FOREIGN KEY (criteria_id) REFERENCES evaluation_criteria (id)
        ON DELETE RESTRICT,
    CONSTRAINT CHK_evaluation_scores_range CHECK (score BETWEEN 1 AND 5)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Ratings inside an evaluation: 1 Poor, 2 Needs Improvement, 3 Satisfactory, 4 Good, 5 Excellent. Not Applicable answers are simply not stored.';

CREATE INDEX IDX_evaluation_scores_category ON evaluation_scores (category_id);


-- =============================================================================
-- 5. AUDIT LOG AND SETTINGS
-- =============================================================================

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id     INT UNSIGNED    NULL,
    -- The employee number typed at login, kept for failed logins with an
    -- unknown number (employee_id is NULL then).
    employee_number VARCHAR(20)     NULL,
    action          VARCHAR(50)     NOT NULL,
    details         VARCHAR(500)    NULL,
    ip_address      VARCHAR(45)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT PK_audit_logs PRIMARY KEY (id),
    CONSTRAINT FK_audit_logs_employees
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Security and administration history: logins, failed logins, PIN changes, record changes.';

CREATE INDEX IDX_audit_logs_created  ON audit_logs (created_at);
CREATE INDEX IDX_audit_logs_action   ON audit_logs (action, created_at);
CREATE INDEX IDX_audit_logs_employee ON audit_logs (employee_id, created_at);

CREATE TABLE settings (
    setting_key   VARCHAR(60)   NOT NULL,
    setting_value VARCHAR(255)  NOT NULL,
    label         VARCHAR(150)  NOT NULL,
    description   VARCHAR(255)  NULL,
    input_type    ENUM('bool','int','text','select') NOT NULL DEFAULT 'text',
    options       VARCHAR(255)  NULL,
    min_value     INT           NULL,
    max_value     INT           NULL,
    sort_order    INT           NOT NULL DEFAULT 0,
    updated_at    DATETIME      NULL,

    CONSTRAINT PK_settings PRIMARY KEY (setting_key)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Options HR can change on the Settings page. options holds value:Label pairs for select inputs.';


-- =============================================================================
-- 6. SEED DATA: roles and permissions
-- =============================================================================

INSERT INTO roles (id, name, description, is_system) VALUES
    (1, 'CEO',      'Views all results, summaries, statistics and reports.', 1),
    (2, 'Admin',    'Human Resources administrator: manages the organisation, periods and accounts.', 1),
    (3, 'Employee', 'Evaluates department mates and sees their own summary.', 1);

INSERT INTO permissions (code, description, group_name, sort_order) VALUES
    ('results.view',        'View all evaluation results, summaries and statistics',      'Results',        10),
    ('results.export',      'Print reports and export results to CSV',                   'Results',        20),
    ('completion.view',     'View the evaluation completion tracker',                    'Results',        30),
    ('evaluations.submit',  'Evaluate the other employees in their own department',      'Evaluations',    40),
    ('evaluations.receive', 'Can be evaluated by the other employees in their department','Evaluations',   50),
    ('summary.view_own',    'View their own results summary (when Settings allow it)',   'Evaluations',    60),
    ('departments.manage',  'Add, edit and deactivate departments',                      'Administration', 70),
    ('employees.manage',    'Add, edit, import, activate and deactivate employees',      'Administration', 80),
    ('pins.reset',          'Reset employee PINs and unlock locked accounts',            'Administration', 90),
    ('categories.manage',   'Manage rating categories and criteria',                     'Administration', 100),
    ('periods.manage',      'Create, open and close evaluation periods',                 'Administration', 110),
    ('roles.manage',        'Change which permissions each role has',                    'Administration', 120),
    ('audit.view',          'View the audit log',                                        'Administration', 130),
    ('settings.manage',     'Change system settings',                                    'Administration', 140);

-- CEO: results only. The CEO is not evaluated (no evaluations.receive);
-- grant that permission to the CEO role, and give the CEO a department, to change it.
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions
WHERE code IN ('results.view', 'results.export', 'completion.view');

-- Admin (HR): all administration, plus evaluating their own department.
-- Results are not granted by default; tick results.view on the
-- Roles and Permissions page to let HR see them.
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE code IN ('completion.view', 'evaluations.submit', 'evaluations.receive', 'summary.view_own',
               'departments.manage', 'employees.manage', 'pins.reset', 'categories.manage',
               'periods.manage', 'roles.manage', 'audit.view', 'settings.manage');

-- Employee: evaluate department mates and see their own summary.
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions
WHERE code IN ('evaluations.submit', 'evaluations.receive', 'summary.view_own');


-- =============================================================================
-- 7. SEED DATA: settings
-- =============================================================================

INSERT INTO settings (setting_key, setting_value, label, description, input_type, options, min_value, max_value, sort_order) VALUES
    ('company_name', 'Evergreen Solutions Inc.', 'Company name',
        'Shown in the header of every page and on printed reports.', 'text', NULL, NULL, NULL, 10),
    ('show_results_to_employees', '1', 'Employees can see their own summary',
        'When on, employees see the aggregated results they received.', 'bool', NULL, NULL, NULL, 20),
    ('results_only_after_close', '1', 'Show employee summaries only after a period is closed',
        'When off, employees can also see results of the open period while it runs.', 'bool', NULL, NULL, NULL, 30),
    ('show_individual_scores_to_employees', '0', 'Employees can see individual ratings',
        'When on, employees also see each rating they received (still anonymous), not only the averages.', 'bool', NULL, NULL, NULL, 40),
    ('show_comments_to_employees', '1', 'Employees can see comments they received',
        'Comments are always shown without the evaluator name.', 'bool', NULL, NULL, NULL, 50),
    ('min_evaluations_for_average', '3', 'Minimum evaluations before an average is shown',
        'Protects anonymity: an employee with fewer evaluations shows "Not enough evaluations" everywhere.', 'int', NULL, 1, 20, 60),
    ('require_comments', '0', 'Require an overall comment',
        'When on, evaluators must write a comment before they can submit.', 'bool', NULL, NULL, NULL, 70),
    ('allow_edit_until_close', '0', 'Allow editing a submitted evaluation until the period closes',
        'When off, an evaluation cannot be changed after it is submitted.', 'bool', NULL, NULL, NULL, 80),
    ('show_evaluator_names_to_ceo', '0', 'Show evaluator names on comments in the results pages',
        'When off, comments in the CEO results pages are anonymous.', 'bool', NULL, NULL, NULL, 90),
    ('new_pin_mode', 'default', 'PIN for new and reset accounts',
        'Use the default PIN below, or generate a random 4-digit PIN that is shown once to the admin.', 'select',
        'default:Use the default PIN|random:Generate a random PIN', NULL, NULL, 100),
    ('default_pin', '1234', 'Default PIN',
        'Exactly 4 digits. Users cannot keep this PIN: they must change it on first login.', 'text', NULL, NULL, NULL, 110),
    ('max_login_attempts', '5', 'Failed logins before an account is locked',
        'The account is locked after this many wrong PINs in a row.', 'int', NULL, 3, 10, 120),
    ('lockout_minutes', '15', 'Lockout duration (minutes)',
        'How long a locked account stays locked. HR can unlock it sooner.', 'int', NULL, 1, 1440, 130),
    ('session_timeout_minutes', '15', 'Log out after inactivity (minutes)',
        'Users are logged out after this many minutes without loading a page.', 'int', NULL, 5, 240, 140);


-- =============================================================================
-- 8. SEED DATA: departments and employees
-- PIN for every account: 1234 (hash below). must_change_pin = 1 forces a new
-- PIN at first login.
-- =============================================================================

INSERT INTO departments (id, name, description) VALUES
    (1, 'Human Resources',        'Recruitment, employee relations, training and benefits.'),
    (2, 'Finance',                'Accounting, payroll, budgeting and financial reports.'),
    (3, 'Information Technology', 'Systems, network, software and user support.'),
    (4, 'Operations',             'Daily operations, logistics, inventory and purchasing.');

INSERT INTO employees
    (id, employee_number, first_name, last_name, email, position, department_id, role_id, pin_hash, must_change_pin, status, date_hired)
VALUES
    -- Leadership
    (1,  'EMP1001', 'Ricardo',  'Villanueva', 'ricardo.villanueva@evergreen.example', 'Chief Executive Officer',     NULL, 1, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2012-01-09'),
    -- Human Resources (the HR Manager is the system Admin)
    (2,  'EMP1002', 'Angela',   'Mendoza',    'angela.mendoza@evergreen.example',     'HR Manager',                  1,    2, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2015-06-15'),
    (3,  'EMP1003', 'Carlo',    'Bautista',   'carlo.bautista@evergreen.example',     'Recruitment Specialist',      1,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2019-02-04'),
    (4,  'EMP1004', 'Patricia', 'Lim',        'patricia.lim@evergreen.example',       'Training Coordinator',        1,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2020-08-17'),
    (5,  'EMP1005', 'Joseph',   'Navarro',    'joseph.navarro@evergreen.example',     'Compensation and Benefits Officer', 1, 3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2018-11-05'),
    (6,  'EMP1006', 'Kristine', 'Ramos',      'kristine.ramos@evergreen.example',     'HR Assistant',                1,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2023-03-20'),
    -- Finance
    (7,  'EMP1007', 'Miguel',   'Torres',     'miguel.torres@evergreen.example',      'Finance Manager',             2,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2014-04-01'),
    (8,  'EMP1008', 'Andrea',   'Cruz',       'andrea.cruz@evergreen.example',        'Senior Accountant',           2,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2016-09-12'),
    (9,  'EMP1009', 'Paolo',    'Garcia',     'paolo.garcia@evergreen.example',       'Accountant',                  2,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2021-01-18'),
    (10, 'EMP1010', 'Bianca',   'Flores',     'bianca.flores@evergreen.example',      'Accounts Payable Clerk',      2,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2022-07-04'),
    (11, 'EMP1011', 'Daniel',   'Aquino',     'daniel.aquino@evergreen.example',      'Budget Analyst',              2,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2019-10-21'),
    -- Information Technology
    (12, 'EMP1012', 'Mark',     'Dela Cruz',  'mark.delacruz@evergreen.example',      'IT Manager',                  3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2013-05-27'),
    (13, 'EMP1013', 'Sofia',    'Reyes',      'sofia.reyes@evergreen.example',        'Software Developer',          3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2020-02-10'),
    (14, 'EMP1014', 'Gabriel',  'Santos',     'gabriel.santos@evergreen.example',     'Systems Administrator',       3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2017-08-14'),
    (15, 'EMP1015', 'Camille',  'Tan',        'camille.tan@evergreen.example',        'Network Engineer',            3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2021-06-01'),
    (16, 'EMP1016', 'Joshua',   'Castillo',   'joshua.castillo@evergreen.example',    'IT Support Specialist',       3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2024-01-15'),
    (17, 'EMP1017', 'Nicole',   'Gonzales',   'nicole.gonzales@evergreen.example',    'QA Analyst',                  3,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2022-11-28'),
    -- Operations
    (18, 'EMP1018', 'Antonio',  'Rivera',     'antonio.rivera@evergreen.example',     'Operations Manager',          4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2011-03-07'),
    (19, 'EMP1019', 'Hannah',   'Ocampo',     'hannah.ocampo@evergreen.example',      'Logistics Coordinator',       4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2018-05-21'),
    (20, 'EMP1020', 'Rafael',   'Domingo',    'rafael.domingo@evergreen.example',     'Inventory Supervisor',        4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2016-12-05'),
    (21, 'EMP1021', 'Jasmine',  'Pascual',    'jasmine.pascual@evergreen.example',    'Operations Analyst',          4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2021-09-13'),
    (22, 'EMP1022', 'Vincent',  'Mercado',    'vincent.mercado@evergreen.example',    'Warehouse Lead',              4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'active',   '2019-04-29'),
    -- Left the company in April 2026: cannot log in, but past evaluations are kept.
    (23, 'EMP1023', 'Lea',      'Soriano',    'lea.soriano@evergreen.example',        'Purchasing Officer',          4,    3, '$2y$10$hn/N0.6PCn0SdFfce/bXe.oIwsh2ysXXvIlUxXK7uiKBee8xg2Cui', 1, 'inactive', '2017-02-13');


-- =============================================================================
-- 9. SEED DATA: rating categories and criteria
-- Weights are relative: Quality of Work and Productivity count 1.5 times as
-- much as the other categories in the overall score.
-- =============================================================================

INSERT INTO evaluation_categories (id, name, description, weight, allow_na, sort_order) VALUES
    (1, 'Quality of Work',                'Accuracy, thoroughness and the standard of the work produced.',                     1.50, 0, 10),
    (2, 'Productivity',                   'Amount of work completed and good use of time.',                                    1.50, 0, 20),
    (3, 'Teamwork and Collaboration',     'Works well with others and supports team goals.',                                   1.00, 0, 30),
    (4, 'Communication',                  'Shares information clearly, listens, and keeps others informed.',                   1.00, 0, 40),
    (5, 'Reliability and Punctuality',    'Dependable, on time, and follows through on commitments.',                         1.00, 0, 50),
    (6, 'Initiative and Problem Solving', 'Acts without being told and finds practical solutions.',                            1.00, 0, 60),
    (7, 'Professionalism and Attitude',   'Respectful, positive, and follows company policies.',                               1.00, 0, 70),
    (8, 'Leadership',                     'Guides, motivates and develops others. Answer Not Applicable if it does not apply.', 1.00, 1, 80);

-- Two categories are rated through specific criteria; the rest are rated directly.
INSERT INTO evaluation_criteria (id, category_id, question_text, sort_order) VALUES
    (1, 1, 'Work is accurate and free of errors.',              10),
    (2, 1, 'Meets quality standards consistently, without rework.', 20),
    (3, 4, 'Explains ideas clearly, in writing and in person.',  10),
    (4, 4, 'Listens to others and keeps the team informed.',     20);


-- =============================================================================
-- 10. SEED DATA: evaluation periods
-- seed.sql adds three closed periods with sample results.
-- =============================================================================

INSERT INTO evaluation_periods (id, title, start_date, end_date, status, created_by) VALUES
    (1, 'Q3 2026 Performance Review', '2026-09-28', '2026-10-16', 'open',  2),
    (2, 'Q4 2026 Performance Review', '2026-12-07', '2026-12-23', 'draft', 2);


-- =============================================================================
-- VERIFICATION QUERIES (optional: run them to check the import)
-- =============================================================================

-- Row counts per table.
SELECT 'departments' AS table_name, COUNT(*) AS row_count FROM departments
UNION ALL SELECT 'roles',                 COUNT(*) FROM roles
UNION ALL SELECT 'permissions',           COUNT(*) FROM permissions
UNION ALL SELECT 'role_permissions',      COUNT(*) FROM role_permissions
UNION ALL SELECT 'employees',             COUNT(*) FROM employees
UNION ALL SELECT 'evaluation_categories', COUNT(*) FROM evaluation_categories
UNION ALL SELECT 'evaluation_criteria',   COUNT(*) FROM evaluation_criteria
UNION ALL SELECT 'evaluation_periods',    COUNT(*) FROM evaluation_periods
UNION ALL SELECT 'settings',              COUNT(*) FROM settings;

-- Employees per department (the CEO has no department).
SELECT COALESCE(d.name, '(no department)') AS department,
       SUM(e.status = 'active')   AS active_employees,
       SUM(e.status = 'inactive') AS inactive_employees
FROM employees e
LEFT JOIN departments d ON d.id = e.department_id
GROUP BY d.name
ORDER BY d.name;

-- Permissions per role.
SELECT r.name AS role, GROUP_CONCAT(p.code ORDER BY p.sort_order SEPARATOR ', ') AS permissions
FROM roles r
JOIN role_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id
GROUP BY r.id, r.name
ORDER BY r.id;
