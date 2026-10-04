<?php
/**
 * Sessions, login and logout, the current user, and permission checks.
 *
 * Pages protect themselves with one call at the top:
 *     $user = require_permission('results.view');   // any one of the listed codes
 *     $user = require_login();                      // any logged-in user
 * Permissions come from the role_permissions table, never from role names.
 */

// A bcrypt hash of a random string. When the employee number does not exist we
// still check the PIN against this hash, so a failed login takes the same time
// either way and response times do not reveal which employee numbers exist.
const DUMMY_PIN_HASH = '$2y$10$gW7SBYG5kcFEZX1kxvA0KOXrnhS/PrTPqF9mskB5LuLMXx0d4qYZa';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');   // ignore session ids the server never issued
    ini_set('session.use_only_cookies', '1');  // never accept a session id from the URL
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,                  // cookie ends when the browser closes
        'path'     => base_path() . '/',
        'secure'   => $https,
        'httponly' => true,               // JavaScript cannot read the cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Empties the session and moves it to a new id (the old one stops working). */
function end_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Logs the user out after too long without loading a page. */
function enforce_session_timeout(): void
{
    if (empty($_SESSION['employee_id'])) {
        return;
    }

    $minutes = setting_int('session_timeout_minutes', 15);
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $minutes * 60) {
        audit_log('session_timeout', 'Logged out after ' . $minutes . ' minutes of inactivity.', (int) $_SESSION['employee_id']);
        end_session();
        flash('warning', 'You were logged out after ' . $minutes . ' minutes of inactivity. Please log in again.');
        redirect('index.php');
    }

    $_SESSION['last_activity'] = time();

    // Change the session id every 10 minutes, which limits what a stolen cookie is worth.
    if (time() - (int) ($_SESSION['regenerated_at'] ?? 0) > 600) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
    }
}

// ---------------------------------------------------------------------------
// Current user and permissions
// ---------------------------------------------------------------------------

/**
 * The logged-in employee with role, department and permission codes, or null.
 * It is re-read from the database on every request, so a deactivated account
 * or a changed role takes effect immediately.
 */
function current_user(bool $reload = false): ?array
{
    static $user = false; // false = not loaded yet in this request

    if ($user !== false && !$reload) {
        return $user;
    }
    $user = null;

    if (empty($_SESSION['employee_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.email, e.position,
                e.department_id, e.role_id, e.must_change_pin, e.status,
                r.name AS role_name, d.name AS department_name, d.is_active AS department_active
         FROM employees e
         JOIN roles r ON r.id = e.role_id
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE e.id = ?'
    );
    $stmt->execute([(int) $_SESSION['employee_id']]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        // The account was deleted or deactivated while logged in.
        end_session();
        flash('error', 'Your account is no longer active. Please contact HR.');
        return null;
    }

    $stmt = db()->prepare(
        'SELECT p.code FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?'
    );
    $stmt->execute([(int) $row['role_id']]);
    $row['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $user = $row;
}

function can(string $permission): bool
{
    $user = current_user();
    return $user !== null && in_array($permission, $user['permissions'], true);
}

function can_any(array $permissions): bool
{
    $user = current_user();
    return $user !== null && array_intersect($permissions, $user['permissions']) !== [];
}

/**
 * Requires a logged-in user and returns it. Users who still have to change
 * their PIN are sent to the Change PIN page (which defines PIN_CHANGE_PAGE).
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (empty($_SESSION['flash'])) {
            flash('info', 'Please log in to continue.');
        }
        redirect('index.php');
    }

    if ((int) $user['must_change_pin'] === 1 && !defined('PIN_CHANGE_PAGE')) {
        redirect('change_pin.php');
    }

    return $user;
}

/** Requires at least one of the given permissions; shows the 403 page otherwise. */
function require_permission(string ...$permissions): array
{
    $user = require_login();
    if (array_intersect($permissions, $user['permissions']) === []) {
        render_error_page(403);
    }
    return $user;
}

/** The page a user lands on after login, based on what they are allowed to do. */
function home_path(array $user): string
{
    $perms = $user['permissions'];
    if (in_array('results.view', $perms, true)) {
        return 'ceo/index.php';
    }
    if (array_intersect(ADMIN_PERMISSIONS, $perms) !== []) {
        return 'admin/index.php';
    }
    if (in_array('evaluations.submit', $perms, true)) {
        return 'employee/index.php';
    }
    if (in_array('completion.view', $perms, true)) {
        return 'ceo/completion.php';
    }
    if (in_array('summary.view_own', $perms, true)) {
        return 'employee/summary.php';
    }
    return 'change_pin.php';
}

// ---------------------------------------------------------------------------
// Login and logout
// ---------------------------------------------------------------------------

function is_valid_pin(string $pin): bool
{
    return preg_match('/^\d{4}$/', $pin) === 1;
}

/**
 * Checks an employee number and PIN, applying the lockout rules.
 * Returns ['ok' => true] or ['ok' => false, 'error' => message].
 */
function attempt_login(string $employeeNumber, string $pin): array
{
    $invalid = ['ok' => false, 'error' => 'Invalid employee number or PIN.'];

    if ($employeeNumber === '' || !is_valid_pin($pin)) {
        return ['ok' => false, 'error' => 'Enter your employee number and your 4-digit PIN.'];
    }

    $stmt = db()->prepare(
        'SELECT id, employee_number, pin_hash, status,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
                TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS lock_seconds
         FROM employees
         WHERE employee_number = ?'
    );
    $stmt->execute([$employeeNumber]);
    $employee = $stmt->fetch();

    if (!$employee) {
        password_verify($pin, DUMMY_PIN_HASH); // same timing as a real check
        audit_log('login_failed', 'Unknown employee number.', null, $employeeNumber);
        return $invalid;
    }

    $id = (int) $employee['id'];
    $number = $employee['employee_number'];

    if ((int) $employee['is_locked'] === 1) {
        $minutes = max(1, (int) ceil(((int) $employee['lock_seconds']) / 60));
        audit_log('login_blocked', 'Login attempt while the account is locked.', $id, $number);
        return ['ok' => false, 'error' => 'This account is locked because of too many failed attempts. '
            . 'Try again in ' . plural($minutes, 'minute') . ', or ask HR to unlock it.'];
    }

    if (!password_verify($pin, $employee['pin_hash'])) {
        $max = setting_int('max_login_attempts', 5);

        // Count the failure in SQL so two attempts at the same moment are both counted.
        db()->prepare('UPDATE employees SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([$id]);
        $stmt = db()->prepare('SELECT failed_attempts FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= $max) {
            $lockMinutes = setting_int('lockout_minutes', 15);
            db()->prepare('UPDATE employees SET failed_attempts = 0, locked_until = NOW() + INTERVAL ? MINUTE WHERE id = ?')
                ->execute([$lockMinutes, $id]);
            audit_log('account_locked', 'Locked for ' . $lockMinutes . ' minutes after ' . $attempts . ' failed attempts.', $id, $number);
            return ['ok' => false, 'error' => 'Too many failed attempts. This account is now locked for '
                . plural($lockMinutes, 'minute') . '.'];
        }

        audit_log('login_failed', 'Wrong PIN (attempt ' . $attempts . ' of ' . $max . ').', $id, $number);
        return $invalid;
    }

    // The PIN is right. Only now say that the account is inactive, so the
    // message cannot be used to discover accounts without knowing their PIN.
    if ($employee['status'] !== 'active') {
        audit_log('login_failed', 'Account is inactive.', $id, $number);
        return ['ok' => false, 'error' => 'This account is inactive. Please contact HR.'];
    }

    if (password_needs_rehash($employee['pin_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE employees SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
    }
    db()->prepare('UPDATE employees SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?')
        ->execute([$id]);

    login_user($id);
    audit_log('login_success', 'Logged in.', $id, $number);

    return ['ok' => true];
}

/** Starts the logged-in session for an employee. */
function login_user(int $employeeId): void
{
    // A new session id at login prevents session fixation.
    session_regenerate_id(true);
    $flash = $_SESSION['flash'] ?? [];
    $_SESSION = [
        'employee_id'    => $employeeId,
        'last_activity'  => time(),
        'regenerated_at' => time(),
        'flash'          => $flash,
    ];
    current_user(true);
}

function logout_user(): void
{
    if (!empty($_SESSION['employee_id'])) {
        audit_log('logout', 'Logged out.', (int) $_SESSION['employee_id']);
    }
    end_session();
    flash('success', 'You have been logged out.');
    redirect('index.php');
}

/**
 * The PIN for a new account or a PIN reset, following the "new_pin_mode"
 * setting: the default PIN, or a random one that is shown once to the admin.
 * Returns ['pin' => '1234', 'random' => false].
 */
function new_account_pin(): array
{
    $default = setting('default_pin', '1234');

    if (setting('new_pin_mode') === 'random') {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while ($pin === $default);
        return ['pin' => $pin, 'random' => true];
    }

    return ['pin' => is_valid_pin((string) $default) ? $default : '1234', 'random' => false];
}
