<?php
/**
 * System Log: problems the application ran into (crashes, PHP errors, a
 * database that could not be reached, refused forms, missing pages), read
 * from the log files written by includes/logger.php. Newest first, with
 * filters for level, dates and text. The Audit Log is the other log: it
 * records what people did.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('system_log.view');

$q = search_query();
$level = get_str('level');
$levelOptions = ['' => 'All levels', 'problems' => 'Critical and errors'] + LOG_LEVELS;
if (!array_key_exists($level, $levelOptions)) {
    $level = '';
}
$from = get_str('from');
$to = get_str('to');

// The date filter runs from the oldest log file (never before MIN_DATE) to today.
$days = system_log_days();
$maxDate = today_date();
$minDate = max(MIN_DATE, $days ? $days[0] : $maxDate);

$dateErrors = [];
foreach (['from' => $from, 'to' => $to] as $field => $value) {
    if ($value !== '' && !is_date_between($value, $minDate, $maxDate)) {
        $dateErrors[$field] = date_range_message($minDate, $maxDate);
    }
}
if (!$dateErrors && $from !== '' && $to !== '' && $from > $to) {
    $dateErrors['to'] = 'Cannot be before the "From" date.';
}

// A wrong date range is reported next to the fields and not applied.
$entries = read_system_log(!$dateErrors && $from !== '' ? $from : $minDate, !$dateErrors && $to !== '' ? $to : $maxDate);
$entries = array_values(array_filter($entries, function ($entry) use ($level, $q) {
    if ($level === 'problems' && !in_array($entry['level'], ['critical', 'error'], true)) {
        return false;
    }
    if ($level !== '' && $level !== 'problems' && $entry['level'] !== $level) {
        return false;
    }
    $text = $entry['ref'] . ' ' . $entry['message'] . ' ' . ($entry['page'] ?? '') . ' ' . ($entry['where'] ?? '');
    return $q === '' || mb_stripos($text, $q) !== false;
}));
$pages = pagination(count($entries), 25);
$pageEntries = page_slice($entries, $pages);

// Names for the employee ids in the entries on this page.
$ids = array_values(array_unique(array_filter(array_map(fn($entry) => (int) ($entry['user'] ?? 0), $pageEntries))));
$people = [];
if ($ids) {
    $stmt = db()->prepare('SELECT id, employee_number, first_name, last_name FROM employees WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $people = array_column($stmt->fetchAll(), null, 'id');
}

// A folder the web server cannot write to means problems are not being recorded.
$canWrite = is_dir(log_dir()) ? is_writable(log_dir()) : is_writable(dirname(log_dir()));

$levelBadges = ['critical' => 'badge-red', 'error' => 'badge-red', 'warning' => 'badge-yellow', 'notice' => 'badge-gray'];

$pageTitle = 'System Log';
$breadcrumbs = [['Administration', url('admin/index.php')], ['System Log', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!$canWrite): ?>
    <div class="alert alert-error" role="alert">
        The web server cannot write to the folder <code>logs</code>, so problems are not being recorded.
        Give the web server write access to it.
    </div>
<?php endif; ?>

<p class="muted">
    Problems the system ran into: crashes, PHP errors and warnings, a database that could not be reached, refused
    forms and missing pages. Error pages show users a reference code to quote; search for it here. Entries are kept
    for <?= (int) LOG_KEEP_DAYS ?> days. What people did is in the <a href="<?= e(url('admin/audit_log.php')) ?>">Audit Log</a>.
</p>

<form method="get" class="filters" role="search" novalidate>
    <div class="field field-wide">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="Reference, message or page">
    </div>
    <div class="field">
        <label for="level">Level</label>
        <select id="level" name="level"><?= select_options($levelOptions, $level) ?></select>
    </div>
    <?php foreach (['from' => 'From', 'to' => 'To'] as $field => $label): ?>
        <div class="field<?= isset($dateErrors[$field]) ? ' has-error' : '' ?>">
            <label for="<?= $field ?>"><?= $label ?></label>
            <input type="date" id="<?= $field ?>" name="<?= $field ?>" value="<?= e($field === 'from' ? $from : $to) ?>"
                   min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>">
        </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-secondary">Apply</button>
    <a class="btn btn-secondary" href="<?= e(url('admin/system_log.php')) ?>">Clear</a>
    <?php foreach ($dateErrors as $field => $message): ?>
        <p class="field-error" data-for="<?= $field ?>"><?= $field === 'from' ? 'From' : 'To' ?>: <?= e($message) ?></p>
    <?php endforeach; ?>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th scope="col">Date and time</th>
            <th scope="col">Level</th>
            <th scope="col">Problem</th>
            <th scope="col">Page</th>
            <th scope="col">User</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pageEntries as $entry): ?>
            <?php $person = $people[(int) ($entry['user'] ?? 0)] ?? null; ?>
            <tr>
                <td class="nowrap"><?= e(format_datetime($entry['time'])) ?><br><span class="muted small">Ref <?= e($entry['ref'] ?? '-') ?></span></td>
                <td><span class="badge <?= $levelBadges[$entry['level']] ?? 'badge-gray' ?>"><?= e(LOG_LEVELS[$entry['level']] ?? $entry['level']) ?></span></td>
                <td class="log-message">
                    <?= e($entry['message']) ?>
                    <?php if (!empty($entry['where'])): ?><br><span class="muted small"><?= e($entry['where']) ?></span><?php endif; ?>
                    <?php if (!empty($entry['trace'])): ?>
                        <details class="log-trace"><summary>Stack trace</summary><pre><?= e($entry['trace']) ?></pre></details>
                    <?php endif; ?>
                </td>
                <td class="log-page"><?= e($entry['page'] ?? '-') ?></td>
                <td>
                    <?php if ($person): ?>
                        <?= e(full_name($person)) ?><br><span class="muted small"><?= e($person['employee_number']) ?></span>
                    <?php else: ?>
                        <span class="muted"><?= !empty($entry['user']) ? 'Employee #' . (int) $entry['user'] : 'Not logged in' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pageEntries): ?>
            <tr class="empty-row"><td colspan="5"><?= $entries === [] && !$q && !$level && !$from && !$to ? 'No problems recorded. All good.' : 'No entries match these filters.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
