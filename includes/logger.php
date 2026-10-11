<?php
/**
 * System log: problems the application runs into, so HR knows when something
 * goes wrong. It records crashes, PHP errors and warnings, a database that
 * cannot be reached, refused forms and missing pages. (The audit log, in the
 * database, records what people did; this log records what went wrong.)
 *
 * Entries are written to one file per day in /logs, e.g.
 * logs/system-2026-10-11.log.php, one JSON object per line. Files are used
 * instead of a table so that a database failure is still recorded. Each file
 * starts with a line of PHP that stops it from being served over the web, and
 * files older than LOG_KEEP_DAYS days are deleted. No IP addresses are stored.
 *
 * This file needs nothing but config/constants.php, so bootstrap.php loads it
 * first and errors in everything loaded after it are recorded.
 */

// Levels, most serious first.
const LOG_LEVELS = [
    'critical' => 'Critical', // the system cannot work: database unreachable, fatal PHP error
    'error'    => 'Error',    // a page crashed
    'warning'  => 'Warning',  // PHP warnings, refused forms, access denied
    'notice'   => 'Notice',   // missing pages, deprecated code
];

// First line of every log file: if the file is ever requested over the web,
// PHP runs this line and sends nothing back.
const LOG_FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";

/** Folder of the log files. */
function log_dir(): string
{
    return dirname(__DIR__) . '/logs';
}

/** The log file of one day (YYYY-MM-DD). */
function log_file(string $date): string
{
    return log_dir() . '/system-' . $date . '.log.php';
}

/** A path relative to the application folder, e.g. "admin/employees.php". */
function log_short_path(string $file): string
{
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/';
    $file = str_replace('\\', '/', $file);
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
}

/**
 * Writes one entry and returns its reference code (six characters, shown on
 * error pages so a user can quote it to HR).
 * $extra adds fields: 'where' (file:line) and 'trace'.
 * It never throws: a logging failure must not hide the problem being logged,
 * so it falls back to PHP's own error log instead.
 */
function system_log(string $level, string $message, array $extra = []): string
{
    static $writing = false;

    try {
        $ref = strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable $ex) {
        $ref = strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
    if ($writing) {
        return $ref; // a problem inside the logger itself is not logged again
    }
    $writing = true;

    try {
        $entry = [
            'time'    => date('Y-m-d H:i:s'),
            'level'   => isset(LOG_LEVELS[$level]) ? $level : 'error',
            'ref'     => $ref,
            'message' => mb_substr($message, 0, 1000),
            'page'    => log_request_summary(),
            // Only the employee id: looking up the name would need the database,
            // which may be the very thing that failed. The log page adds names.
            'user'    => isset($_SESSION['employee_id']) ? (int) $_SESSION['employee_id'] : null,
        ];
        if (isset($extra['where'])) {
            $entry['where'] = mb_substr((string) $extra['where'], 0, 300);
        }
        if (isset($extra['trace'])) {
            $entry['trace'] = mb_substr((string) $extra['trace'], 0, 4000);
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_dir(log_dir()) && !@mkdir(log_dir(), 0775, true) && !is_dir(log_dir())) {
            throw new RuntimeException('cannot create the folder ' . log_dir());
        }
        $file = log_file(date('Y-m-d'));
        $isNew = !is_file($file);
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            throw new RuntimeException('cannot write to ' . $file);
        }
        flock($handle, LOCK_EX);
        if (fstat($handle)['size'] === 0) {
            fwrite($handle, LOG_FILE_GUARD);
        }
        fwrite($handle, $line . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($isNew) {
            prune_system_logs();
        }
    } catch (Throwable $ex) {
        error_log('[employee_evaluation] System log not written (' . $ex->getMessage() . '): ' . $level . ' ' . $message);
    } finally {
        $writing = false;
    }

    return $ref;
}

/** "POST /employee_evaluation/admin/employees.php?q=ana", for the log. */
function log_request_summary(): string
{
    if (PHP_SAPI === 'cli') {
        return 'CLI ' . log_short_path($_SERVER['SCRIPT_FILENAME'] ?? '');
    }
    return mb_substr(($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/'), 0, 300);
}

/** Deletes log files older than LOG_KEEP_DAYS days. Runs when a day's first entry is written. */
function prune_system_logs(): void
{
    $oldest = date('Y-m-d', strtotime('-' . LOG_KEEP_DAYS . ' days'));
    foreach (glob(log_dir() . '/system-*.log.php') ?: [] as $file) {
        if (preg_match('/system-(\d{4}-\d{2}-\d{2})\.log\.php$/', $file, $m) && $m[1] < $oldest) {
            @unlink($file);
        }
    }
}

/** The days (YYYY-MM-DD) that have a log file, oldest first. */
function system_log_days(): array
{
    $days = [];
    foreach (glob(log_dir() . '/system-*.log.php') ?: [] as $file) {
        if (preg_match('/system-(\d{4}-\d{2}-\d{2})\.log\.php$/', $file, $m)) {
            $days[] = $m[1];
        }
    }
    sort($days);
    return $days;
}

/** Every entry logged from $from to $to (YYYY-MM-DD, both included), newest first. */
function read_system_log(string $from, string $to): array
{
    $entries = [];
    foreach (system_log_days() as $day) {
        if ($day < $from || $day > $to) {
            continue;
        }
        $lines = @file(log_file($day), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true); // the PHP guard line is not JSON and is skipped
            if (is_array($entry) && isset($entry['time'], $entry['level'], $entry['message'])) {
                $entries[] = $entry;
            }
        }
    }
    // Files are in time order, so reversing gives newest first (also within the same second).
    return array_reverse($entries);
}

// ---------------------------------------------------------------------------
// PHP error hooks (registered by bootstrap.php)
// ---------------------------------------------------------------------------

/** "Warning", "Notice", ... for a PHP error type. */
function php_error_name(int $type): string
{
    $names = [
        E_ERROR => 'Fatal error', E_PARSE => 'Parse error', E_CORE_ERROR => 'Fatal error', E_COMPILE_ERROR => 'Fatal error',
        E_WARNING => 'Warning', E_CORE_WARNING => 'Warning', E_COMPILE_WARNING => 'Warning', E_USER_WARNING => 'Warning',
        E_NOTICE => 'Notice', E_USER_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated',
        E_USER_ERROR => 'Error', E_RECOVERABLE_ERROR => 'Error',
    ];
    return $names[$type] ?? 'PHP error';
}

/**
 * Error handler: records PHP warnings, notices and deprecations, then lets
 * PHP handle them as usual (nothing is shown to users unless APP_DEBUG is on).
 */
function log_php_error(int $type, string $message, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $type)) {
        return false; // silenced with @
    }
    $level = match (true) {
        in_array($type, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)              => 'error',
        in_array($type, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true) => 'notice',
        default                                                                 => 'warning',
    };
    system_log($level, php_error_name($type) . ': ' . $message, ['where' => log_short_path($file) . ':' . $line]);
    return false;
}

/** Shutdown function: records fatal errors (out of memory, time limit, syntax errors), which no handler can catch. */
function log_fatal_error(): void
{
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        system_log('critical', php_error_name($error['type']) . ': ' . $error['message'],
            ['where' => log_short_path($error['file']) . ':' . $error['line']]);
    }
}
