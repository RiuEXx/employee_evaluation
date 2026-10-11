<?php
/**
 * Fixed application constants.
 *
 * Options that HR may want to change while the system runs (lockout length,
 * session timeout, anonymity threshold, and so on) live in the `settings`
 * table instead and are edited on Admin > Settings.
 */

define('APP_NAME', 'Employee Performance Evaluation System');
define('APP_SHORT_NAME', 'Performance Evaluation');

// true shows full error details in the browser. Keep it false outside development.
define('APP_DEBUG', false);

// Timezone for every date PHP shows or stores. The database connection is set
// to the same offset (see config/db.php), so NOW() in SQL matches PHP's clock.
define('APP_TIMEZONE', 'Asia/Manila');

// Name of the session cookie.
define('SESSION_NAME', 'EVALSESSID');

// Rows per page on list pages.
define('PER_PAGE', 15);

// Largest CSV file accepted by the employee import, in bytes (1 MB).
define('IMPORT_MAX_BYTES', 1048576);

// Most rows a single CSV import may contain.
define('IMPORT_MAX_ROWS', 1000);

// Longest text a search box accepts, in characters.
define('SEARCH_MAX_LENGTH', 50);

// Earliest date any date field accepts (date hired, evaluation periods, audit log filter).
define('MIN_DATE', '2000-01-01');

// How far ahead of today an evaluation period may be planned (a strtotime() offset).
define('PERIOD_MAX_AHEAD', '+1 year');

// Days the system log (logs/system-*.log.php) is kept before old files are deleted.
define('LOG_KEEP_DAYS', 30);

// Employee numbers are the role's tag followed by this many digits or more (at most 6), e.g. EMP1024.
define('EMPLOYEE_NUMBER_MIN_DIGITS', 4);

// The rating scale used on every evaluation form.
const RATING_LABELS = [
    1 => 'Poor',
    2 => 'Needs Improvement',
    3 => 'Satisfactory',
    4 => 'Good',
    5 => 'Excellent',
];

// Permissions that open the Administration area.
const ADMIN_PERMISSIONS = [
    'departments.manage',
    'employees.manage',
    'pins.reset',
    'categories.manage',
    'periods.manage',
    'roles.manage',
    'audit.view',
    'system_log.view',
    'settings.manage',
];
