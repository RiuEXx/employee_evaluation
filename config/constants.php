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
    'settings.manage',
];
