<?php
/**
 * Audit log viewer with filters (text, action, date range), sorting and paging.
 * Logins, failed logins, lockouts, PIN changes and resets, and every
 * administrative change are recorded here. Evaluation entries name the
 * period only, so the log never shows who evaluated whom.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('audit.view');

$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$q = get_str('q');
$action = get_str('action');
$from = get_str('from');
$to = get_str('to');

$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = "(a.employee_number LIKE ? OR e.employee_number LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)";
    array_push($params, like_pattern($q), like_pattern($q), like_pattern($q), like_pattern($q), like_pattern($q));
}
if ($action !== '' && in_array($action, $actions, true)) {
    $conditions[] = 'a.action = ?';
    $params[] = $action;
}
if ($from !== '' && is_valid_date($from)) {
    $conditions[] = 'a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '' && is_valid_date($to)) {
    $conditions[] = 'a.created_at < ? + INTERVAL 1 DAY';
    $params[] = $to;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN employees e ON e.id = a.employee_id $whereSql");
$stmt->execute($params);
$pages = pagination((int) $stmt->fetchColumn(), 25);

$sort = sort_state(['date' => 'a.id', 'action' => 'a.action', 'employee' => 'e.last_name', 'ip' => 'a.ip_address'], 'date', 'desc');
$stmt = db()->prepare(
    "SELECT a.*, e.first_name, e.last_name, e.employee_number AS current_number
     FROM audit_logs a
     LEFT JOIN employees e ON e.id = a.employee_id
     $whereSql
     ORDER BY {$sort['sql']}, a.id DESC
     LIMIT {$pages['per_page']} OFFSET {$pages['offset']}"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Log';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Audit Log', null]];
require __DIR__ . '/../includes/header.php';
?>

<form method="get" class="filters" role="search">
    <div class="field field-wide">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Employee, details or IP address">
    </div>
    <div class="field">
        <label for="action">Action</label>
        <select id="action" name="action">
            <option value="">All actions</option>
            <?= select_options(array_combine($actions, $actions) ?: [], $action) ?>
        </select>
    </div>
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="btn btn-secondary">Apply</button>
    <a class="btn btn-secondary" href="<?= e(url('admin/audit_log.php')) ?>">Clear</a>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?= sort_th('Date and time', 'date', $sort) ?>
            <?= sort_th('Employee', 'employee', $sort) ?>
            <?= sort_th('Action', 'action', $sort) ?>
            <th scope="col">Details</th>
            <?= sort_th('IP address', 'ip', $sort) ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td class="nowrap"><?= e(format_datetime($log['created_at'])) ?></td>
                <td>
                    <?php if ($log['first_name']): ?>
                        <?= e(full_name($log)) ?><br><span class="muted small"><?= e($log['current_number']) ?></span>
                    <?php else: ?>
                        <span class="muted"><?= e($log['employee_number'] ? 'Typed: ' . $log['employee_number'] : '-') ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= preg_match('/failed|locked|blocked/', $log['action']) ? 'badge-red' : 'badge-blue' ?>"><?= e($log['action']) ?></span></td>
                <td><?= e($log['details']) ?></td>
                <td class="nowrap"><?= e($log['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
            <tr class="empty-row"><td colspan="5">No entries match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
