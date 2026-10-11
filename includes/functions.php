<?php
/**
 * General helpers: escaping, URLs, request input, flash messages, settings,
 * the audit log, formatting, sorting, pagination and error pages.
 */

// ---------------------------------------------------------------------------
// Output and URLs
// ---------------------------------------------------------------------------

/** Escapes a value for safe output inside HTML text and attribute values. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL path of the application folder, e.g. "/employee_evaluation", or "" when
 * the application is the web root. It is worked out from the running script,
 * so the project folder can be renamed without changing any code.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $base = '';
    $root = realpath(__DIR__ . '/..');
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if ($root !== false && $script !== '' && stripos($script, $root) === 0) {
        // e.g. "/admin/employees.php"
        $relative = str_replace('\\', '/', substr($script, strlen($root)));
        $tail = substr($scriptName, -strlen($relative));
        if ($relative !== '' && strcasecmp($tail, $relative) === 0) {
            $base = substr($scriptName, 0, -strlen($relative));
        }
    }

    return $base = rtrim($base, '/');
}

/** Absolute URL path for a file inside the application, e.g. url('admin/index.php'). */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** URL of a file in /assets with a version number, so browsers reload it after a change. */
function asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . $path;
    $version = is_file($file) ? filemtime($file) : 0;
    return url('assets/' . $path) . '?v=' . $version;
}

/**
 * Sends a redirect and stops the script. $path is either relative to the
 * application root ("admin/index.php") or an absolute path ("/x/y.php?a=1").
 */
function redirect(string $path): void
{
    $target = str_starts_with($path, '/') ? $path : url($path);
    header('Location: ' . $target);
    exit;
}

/** Path and query string of the current request (always starts with "/"). */
function current_request_uri(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return str_starts_with($uri, '/') ? $uri : '/';
}

/**
 * The current page URL with some query parameters changed.
 * A null or '' value removes the parameter. Used by filters, sorting and paging.
 */
function query_url(array $changes = []): string
{
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    $path = strtok(current_request_uri(), '?');
    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

// ---------------------------------------------------------------------------
// Request input
// ---------------------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** A trimmed string from $_POST ('' when missing or not a string). */
function post_str(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** A trimmed string from $_GET ('' when missing or not a string). */
function get_str(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function post_int(string $key, int $default = 0): int
{
    $value = post_str($key);
    return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
}

function get_int(string $key, int $default = 0): int
{
    $value = get_str($key);
    return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
}

/** The search box text (?q=) without emoji, cut to SEARCH_MAX_LENGTH characters like the box itself. */
function search_query(): string
{
    return mb_substr(trim(preg_replace(EMOJI_PATTERN, '', get_str('q')) ?? ''), 0, SEARCH_MAX_LENGTH);
}

/**
 * Emoji and other picture symbols. Text fields take plain text only, so these
 * are refused (assets/js/app.js uses the same pattern while typing).
 * It matches pictographic characters from U+2300 up (so typographic signs
 * such as the copyright and trademark signs stay allowed), flags, skin tones,
 * keycaps and the variation selector that turns a character into an emoji.
 */
const EMOJI_PATTERN = '/(?=\p{Extended_Pictographic})[^\x{0000}-\x{22FF}]|[\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{FE0F}\x{20E3}\x{E0020}-\x{E007F}]/u';

/** True when the text contains an emoji. */
function has_emoji(string $text): bool
{
    return preg_match(EMOJI_PATTERN, $text) === 1;
}

/** Adds "No emoji" to $errors for each field in $values that has one (and no other error yet). */
function reject_emoji(array $values, array &$errors): void
{
    foreach ($values as $field => $value) {
        if (is_string($value) && !isset($errors[$field]) && has_emoji($value)) {
            $errors[$field] = 'Emoji are not allowed. Use plain text only.';
        }
    }
}

/** A "contains" pattern for SQL LIKE, with the user's % and _ treated as plain text. */
function like_pattern(string $text): string
{
    return '%' . addcslashes($text, '%_\\') . '%';
}

/** True when $value is a real calendar date written as YYYY-MM-DD. */
function is_valid_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** True when $value is a real date from $min to $max (all written as YYYY-MM-DD). */
function is_date_between(string $value, string $min, string $max): bool
{
    return is_valid_date($value) && $value >= $min && $value <= $max;
}

/** Today's date as YYYY-MM-DD, in the application timezone. */
function today_date(): string
{
    return date('Y-m-d');
}

/** Latest date an evaluation period may use: today plus PERIOD_MAX_AHEAD. */
function period_max_date(): string
{
    return date('Y-m-d', strtotime(PERIOD_MAX_AHEAD));
}

/** "Choose a date from Jan 1, 2000 to Oct 11, 2026." */
function date_range_message(string $min, string $max): string
{
    return 'Choose a date from ' . format_date($min) . ' to ' . format_date($max) . '.';
}

// ---------------------------------------------------------------------------
// Flash messages (shown once, on the next page load)
// ---------------------------------------------------------------------------

/** $type is one of: success, error, warning, info. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------------------------------------------------------------------------
// Settings (the `settings` table)
// ---------------------------------------------------------------------------

/** All settings as key => value. Loaded once per request. */
function settings_all(bool $reload = false): array
{
    static $settings = null;
    if ($settings === null || $reload) {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = array_column($rows, 'setting_value', 'setting_key');
    }
    return $settings;
}

function setting(string $key, ?string $default = null): ?string
{
    return settings_all()[$key] ?? $default;
}

function setting_bool(string $key): bool
{
    return setting($key, '0') === '1';
}

function setting_int(string $key, int $default): int
{
    $value = setting($key);
    return ($value !== null && preg_match('/^\d+$/', $value)) ? (int) $value : $default;
}

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------

/**
 * Writes one row to audit_logs.
 * $employeeId defaults to the logged-in user. $employeeNumber records what was
 * typed at the login form, for failed logins with an unknown number.
 */
function audit_log(string $action, string $details = '', ?int $employeeId = null, ?string $employeeNumber = null): void
{
    if ($employeeId === null && !empty($_SESSION['employee_id'])) {
        $employeeId = (int) $_SESSION['employee_id'];
    }
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (employee_id, employee_number, action, details)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $employeeId,
        $employeeNumber !== null ? mb_substr($employeeNumber, 0, 20) : null,
        $action,
        mb_substr($details, 0, 500),
    ]);
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if ($date === null || $date === '') {
        return '-';
    }
    $time = strtotime($date);
    return $time === false ? '-' : date($format, $time);
}

function format_datetime(?string $datetime): string
{
    return format_date($datetime, 'M j, Y g:i A');
}

/** A score average with two decimals, or "-" when there is none. */
function format_score(?float $score): string
{
    return $score === null ? '-' : number_format($score, 2);
}

/** A 0..1 ratio as a whole percentage, e.g. 0.756 -> "76%". */
function format_percent(?float $ratio): string
{
    return $ratio === null ? '-' : round($ratio * 100) . '%';
}

/** "4 - Good" for a single rating. */
function rating_text(int $score): string
{
    return $score . ' - ' . (RATING_LABELS[$score] ?? '');
}

/** The rating label closest to an average, e.g. 3.6 -> "Good". */
function score_label(?float $average): string
{
    if ($average === null) {
        return '';
    }
    $rounded = (int) max(1, min(5, round($average)));
    return RATING_LABELS[$rounded];
}

/** CSS class used to tint a score badge. */
function score_class(?float $average): string
{
    if ($average === null) {
        return 'score-none';
    }
    if ($average >= 4.0) {
        return 'score-high';
    }
    return $average >= 3.0 ? 'score-mid' : 'score-low';
}

function full_name(array $row): string
{
    return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}

/** "1 evaluation" / "3 evaluations". */
function plural(int $count, string $singular, ?string $pluralForm = null): string
{
    return $count . ' ' . ($count === 1 ? $singular : ($pluralForm ?? $singular . 's'));
}

/** Lowercase slug for file names, e.g. "Q3 2026 Review" -> "q3-2026-review". */
function slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'export';
}

// ---------------------------------------------------------------------------
// Sorting and pagination for list pages
// ---------------------------------------------------------------------------

/**
 * Reads ?sort= and ?dir= and checks them against a whitelist.
 * $columns maps a sort key to the SQL expression used in ORDER BY (or to the
 * array key when sorting in PHP). User input never reaches the SQL directly.
 */
function sort_state(array $columns, string $defaultKey, string $defaultDir = 'asc'): array
{
    $key = get_str('sort');
    if (!array_key_exists($key, $columns)) {
        $key = $defaultKey;
    }
    $dir = strtolower(get_str('dir'));
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = get_str('sort') === $key ? 'asc' : $defaultDir;
    }
    return [
        'key'    => $key,
        'dir'    => $dir,
        'column' => $columns[$key],
        'sql'    => $columns[$key] . ' ' . strtoupper($dir),
    ];
}

/** A sortable table header cell. Clicking it toggles the direction. */
function sort_th(string $label, string $key, array $sort, string $class = ''): string
{
    $active = $sort['key'] === $key;
    $nextDir = ($active && $sort['dir'] === 'asc') ? 'desc' : 'asc';
    $href = query_url(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
    $classes = trim($class . ' sortable' . ($active ? ' sorted-' . $sort['dir'] : ''));
    $aria = $active ? ' aria-sort="' . ($sort['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '';
    return '<th class="' . e($classes) . '"' . $aria . ' scope="col"><a href="' . e($href) . '">' . e($label) . '</a></th>';
}

/** Sorts rows in PHP by one key. Empty values (null) always go last. */
function sort_rows(array $rows, string $key, string $dir): array
{
    usort($rows, function ($a, $b) use ($key, $dir) {
        $x = $a[$key] ?? null;
        $y = $b[$key] ?? null;
        if ($x === null || $y === null) {
            return ($x === null) <=> ($y === null);
        }
        $cmp = (is_numeric($x) && is_numeric($y)) ? ($x <=> $y) : strnatcasecmp((string) $x, (string) $y);
        return $dir === 'desc' ? -$cmp : $cmp;
    });
    return $rows;
}

/** Works out the current page from ?page= for $total rows. */
function pagination(int $total, int $perPage = PER_PAGE): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, get_int('page', 1)), $pages);
    return [
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'total'    => $total,
    ];
}

/** "Showing 16-30 of 42" plus Previous / page numbers / Next links. */
function render_pagination(array $p): string
{
    if ($p['total'] === 0) {
        return '';
    }
    $from = $p['offset'] + 1;
    $to = min($p['offset'] + $p['per_page'], $p['total']);
    $html = '<nav class="pagination" aria-label="Pages"><span class="pagination-info">Showing '
        . $from . '-' . $to . ' of ' . $p['total'] . '</span>';

    if ($p['pages'] > 1) {
        $html .= '<span class="pagination-links">';
        $html .= $p['page'] > 1
            ? '<a href="' . e(query_url(['page' => $p['page'] - 1])) . '">Previous</a>'
            : '<span class="disabled">Previous</span>';

        // Show the first, last and the pages around the current one.
        $last = 0;
        for ($i = 1; $i <= $p['pages']; $i++) {
            if ($i !== 1 && $i !== $p['pages'] && abs($i - $p['page']) > 2) {
                continue;
            }
            if ($last && $i - $last > 1) {
                $html .= '<span class="gap">...</span>';
            }
            $html .= $i === $p['page']
                ? '<span class="current" aria-current="page">' . $i . '</span>'
                : '<a href="' . e(query_url(['page' => $i])) . '">' . $i . '</a>';
            $last = $i;
        }

        $html .= $p['page'] < $p['pages']
            ? '<a href="' . e(query_url(['page' => $p['page'] + 1])) . '">Next</a>'
            : '<span class="disabled">Next</span>';
        $html .= '</span>';
    }

    return $html . '</nav>';
}

/** The slice of an array for the current page (for lists sorted in PHP). */
function page_slice(array $rows, array $p): array
{
    return array_slice($rows, $p['offset'], $p['per_page']);
}

// ---------------------------------------------------------------------------
// Small HTML helpers
// ---------------------------------------------------------------------------

/** <option> tags for a select. $options is value => label. */
function select_options(array $options, $selected): string
{
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = (string) $value === (string) $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
    return $html;
}

/** A progress bar with its text value, for done / total. */
function progress_bar(int $done, int $total, string $label = ''): string
{
    $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
    $text = $label !== '' ? $label : $done . ' of ' . $total . ' (' . $percent . '%)';
    return '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $percent
        . '" aria-label="' . e($text) . '"><div class="progress-fill" style="width: ' . $percent . '%"></div></div>'
        . '<div class="progress-text">' . e($text) . '</div>';
}

/** A small coloured badge showing an average score, or a note when hidden. */
function score_badge(?float $average, string $emptyText = '-'): string
{
    if ($average === null) {
        return '<span class="score score-none">' . e($emptyText) . '</span>';
    }
    return '<span class="score ' . score_class($average) . '" title="' . e(score_label($average)) . '">'
        . format_score($average) . '</span>';
}

/** A status badge, e.g. Active / Inactive / Open / Closed. */
function status_badge(string $status): string
{
    $classes = [
        'active' => 'badge-green', 'open' => 'badge-green', 'completed' => 'badge-green', 'complete' => 'badge-green',
        'inactive' => 'badge-gray', 'closed' => 'badge-gray', 'draft' => 'badge-yellow',
        'pending' => 'badge-yellow', 'in progress' => 'badge-blue', 'not started' => 'badge-red', 'locked' => 'badge-red',
    ];
    $class = $classes[strtolower($status)] ?? 'badge-gray';
    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

/**
 * Embeds chart data for assets/js/charts.js as a JSON block (no inline script
 * code, so the Content Security Policy can forbid inline JavaScript).
 */
function chart_canvas(string $id, array $spec, string $ariaLabel, string $class = ''): string
{
    $json = json_encode($spec, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<div class="chart-box ' . e($class) . '"><canvas data-chart="' . e($id) . '" role="img" aria-label="'
        . e($ariaLabel) . '"></canvas></div><script type="application/json" id="' . e($id) . '">' . $json . '</script>';
}

// ---------------------------------------------------------------------------
// Security headers and error pages
// ---------------------------------------------------------------------------

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

/**
 * Shows a friendly error page (403, 404, ...) and stops the script.
 * Logged-in users keep the normal navigation; visitors get a plain page.
 */
function render_error_page(int $code, string $message = ''): void
{
    $titles = [
        400 => 'Bad request',
        403 => 'Access denied',
        404 => 'Page not found',
    ];
    $messages = [
        400 => 'The request could not be understood.',
        403 => 'You do not have permission to open this page. If you think this is a mistake, contact HR.',
        404 => 'The page or record you asked for does not exist or has been removed.',
    ];

    http_response_code($code);
    $pageTitle = $titles[$code] ?? 'Error';
    $errorMessage = $message !== '' ? $message : ($messages[$code] ?? 'Something went wrong.');
    // A missing page is only a notice (often an old bookmark); refused access is worth a closer look.
    system_log($code === 404 ? 'notice' : 'warning', $code . ' ' . $pageTitle . ($message !== '' ? ': ' . $message : '.'));
    $user = current_user();
    $layout = $user ? 'app' : 'plain';

    require __DIR__ . '/header.php';
    ?>
    <section class="card error-card">
        <p class="error-code">Error <?= (int) $code ?></p>
        <p><?= e($errorMessage) ?></p>
        <p class="actions">
            <?php if ($user): ?>
                <a class="btn btn-primary" href="<?= e(url(home_path($user))) ?>">Go to my home page</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">Go to the login page</a>
            <?php endif; ?>
        </p>
    </section>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * Last-resort handler for uncaught exceptions. It renders a standalone page
 * without touching the database, because the database may be what failed.
 */
function handle_uncaught_exception(Throwable $ex): void
{
    $isDbDown = $ex instanceof PDOException
        && preg_match('/\[(2002|2006|1044|1045|1049)\]/', $ex->getMessage());
    $ref = system_log($isDbDown ? 'critical' : 'error', get_class($ex) . ': ' . $ex->getMessage(), [
        'where' => log_short_path($ex->getFile()) . ':' . $ex->getLine(),
        'trace' => $ex->getTraceAsString(),
    ]);
    $message = $isDbDown
        ? 'The application cannot reach its database. Check that MySQL is running in the XAMPP Control Panel, '
          . 'that database/schema.sql has been imported, and that config/db.php has the right settings.'
        : 'An unexpected error occurred. Please try again. If it keeps happening, contact the system administrator.';

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = APP_DEBUG ? '<pre class="error-detail">' . e((string) $ex) . '</pre>' : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Error - ' . e(APP_SHORT_NAME) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('css/style.css')) . '">'
        . '<link rel="icon" type="image/svg+xml" href="' . e(asset('img/favicon.svg')) . '"></head>'
        . '<body class="plain"><main class="plain-main"><section class="card error-card">'
        . '<h1 class="page-title">Something went wrong</h1><p>' . e($message) . '</p>'
        . '<p class="error-ref">Reference: <strong>' . e($ref) . '</strong>. Give this code to HR: the System Log has the details.</p>'
        . $detail
        . '<p class="actions"><a class="btn btn-primary" href="' . e(url('index.php')) . '">Back to the start page</a></p>'
        . '</section></main></body></html>';
    exit;
}
