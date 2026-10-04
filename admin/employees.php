<?php
/**
 * Employees: list with search, filters, sorting and paging, plus the account
 * actions Reset PIN, Unlock, Activate and Deactivate.
 * Deactivated employees cannot log in; their past evaluations are kept.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('employees.manage', 'pins.reset');

if (is_post()) {
    $target = find_employee(post_int('id'));
    $action = post_str('action');
    $needs = ['reset_pin' => 'pins.reset', 'unlock' => 'pins.reset', 'activate' => 'employees.manage', 'deactivate' => 'employees.manage'];

    if (!$target || !isset($needs[$action])) {
        flash('error', 'That employee no longer exists.');
        redirect(current_request_uri());
    }
    if (!can($needs[$action])) {
        render_error_page(403);
    }

    $label = full_name($target) . ' (' . $target['employee_number'] . ')';

    if ($action === 'reset_pin') {
        $pin = new_account_pin();
        db()->prepare('UPDATE employees SET pin_hash = ?, must_change_pin = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($pin['pin'], PASSWORD_DEFAULT), $target['id']]);
        audit_log('pin_reset', 'PIN reset for ' . $label . ($pin['random'] ? ' (random PIN).' : ' (default PIN).'));
        flash('success', $pin['random']
            ? 'The new PIN for ' . $label . ' is ' . $pin['pin'] . '. Give it to them now: it is shown only this once. They must change it at their next login.'
            : 'The PIN for ' . $label . ' was reset to the default PIN (' . $pin['pin'] . '). They must change it at their next login.');
    } elseif ($action === 'unlock') {
        db()->prepare('UPDATE employees SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$target['id']]);
        audit_log('account_unlock', 'Unlocked ' . $label . '.');
        flash('success', $label . ' has been unlocked and can log in again.');
    } elseif ($action === 'deactivate') {
        if ((int) $target['id'] === (int) $user['id']) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            db()->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?")->execute([$target['id']]);
            audit_log('employee_deactivate', 'Deactivated ' . $label . '.');
            flash('success', $label . ' has been deactivated and can no longer log in. Past evaluations are kept.');
        }
    } else {
        db()->prepare("UPDATE employees SET status = 'active' WHERE id = ?")->execute([$target['id']]);
        audit_log('employee_activate', 'Activated ' . $label . '.');
        flash('success', $label . ' is active again.');
    }
    redirect(current_request_uri());
}

$departments = departments_list();
$roles = db()->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

$q = get_str('q');
$deptFilter = get_str('department');
$roleFilter = get_int('role');
$status = get_str('status');

$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = "(e.employee_number LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.email LIKE ? OR e.position LIKE ?)";
    array_push($params, like_pattern($q), like_pattern($q), like_pattern($q), like_pattern($q));
}
if ($deptFilter === 'none') {
    $conditions[] = 'e.department_id IS NULL';
} elseif (ctype_digit($deptFilter)) {
    $conditions[] = 'e.department_id = ?';
    $params[] = (int) $deptFilter;
}
if ($roleFilter) {
    $conditions[] = 'e.role_id = ?';
    $params[] = $roleFilter;
}
if ($status === 'active' || $status === 'inactive') {
    $conditions[] = 'e.status = ?';
    $params[] = $status;
} elseif ($status === 'locked') {
    $conditions[] = 'e.locked_until > NOW()';
} elseif ($status === 'pin') {
    $conditions[] = 'e.must_change_pin = 1';
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM employees e $whereSql");
$stmt->execute($params);
$pages = pagination((int) $stmt->fetchColumn());

$sort = sort_state([
    'number' => 'e.employee_number', 'name' => 'e.last_name', 'department' => 'd.name',
    'role' => 'r.name', 'status' => 'e.status', 'login' => 'e.last_login',
], 'name');
$stmt = db()->prepare(
    "SELECT e.id, e.employee_number, e.first_name, e.last_name, e.email, e.position, e.status,
            e.must_change_pin, e.last_login, d.name AS department_name, r.name AS role_name,
            (e.locked_until IS NOT NULL AND e.locked_until > NOW()) AS is_locked
     FROM employees e
     JOIN roles r ON r.id = e.role_id
     LEFT JOIN departments d ON d.id = e.department_id
     $whereSql
     ORDER BY {$sort['sql']}, e.last_name, e.first_name
     LIMIT {$pages['per_page']} OFFSET {$pages['offset']}"
);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$pinMode = setting('new_pin_mode') === 'random' ? 'a new random PIN' : 'the default PIN';

$pageTitle = 'Employees';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Employees', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if (can('employees.manage')): ?>
    <div class="actions section-gap">
        <a class="btn btn-primary" href="<?= e(url('admin/employee_edit.php')) ?>">Add employee</a>
        <a class="btn btn-secondary" href="<?= e(url('admin/employee_import.php')) ?>">Import from CSV</a>
    </div>
<?php endif; ?>

<form method="get" class="filters" role="search">
    <div class="field field-wide">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Number, name, email or position">
    </div>
    <div class="field">
        <label for="department">Department</label>
        <select id="department" name="department">
            <option value="">All departments</option>
            <?= select_options(array_column($departments, 'name', 'id') + ['none' => 'No department'], $deptFilter) ?>
        </select>
    </div>
    <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="">All roles</option>
            <?= select_options(array_column($roles, 'name', 'id'), $roleFilter ?: '') ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?= select_options(['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked', 'pin' => 'Must change PIN'], $status) ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary">Apply</button>
    <a class="btn btn-secondary" href="<?= e(url('admin/employees.php')) ?>">Clear</a>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?= sort_th('Number', 'number', $sort) ?>
            <?= sort_th('Name', 'name', $sort) ?>
            <?= sort_th('Department', 'department', $sort) ?>
            <?= sort_th('Role', 'role', $sort) ?>
            <?= sort_th('Status', 'status', $sort) ?>
            <?= sort_th('Last login', 'login', $sort) ?>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $emp): ?>
            <?php $isMe = (int) $emp['id'] === (int) $user['id']; ?>
            <tr>
                <td class="nowrap"><?= e($emp['employee_number']) ?></td>
                <td>
                    <?= e(full_name($emp)) ?><?= $isMe ? ' <span class="muted small">(you)</span>' : '' ?>
                    <br><span class="muted small"><?= e($emp['position'] ?: '-') ?></span>
                </td>
                <td><?= e($emp['department_name'] ?? 'No department') ?></td>
                <td><?= e($emp['role_name']) ?></td>
                <td>
                    <?= status_badge($emp['status']) ?>
                    <?php if ((int) $emp['is_locked'] === 1): ?> <?= status_badge('locked') ?><?php endif; ?>
                    <?php if ((int) $emp['must_change_pin'] === 1): ?><br><span class="muted small nowrap">Must change PIN</span><?php endif; ?>
                </td>
                <td class="nowrap"><?= e($emp['last_login'] ? format_datetime($emp['last_login']) : 'Never') ?></td>
                <td class="actions-cell">
                    <div class="actions">
                        <?php if (can('employees.manage')): ?>
                            <a class="btn btn-secondary btn-small" href="<?= e(url('admin/employee_edit.php?id=' . (int) $emp['id'])) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (can('pins.reset')): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e('Reset the PIN of ' . full_name($emp) . ' to ' . $pinMode . '?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                                <button type="submit" name="action" value="reset_pin" class="btn btn-secondary btn-small">Reset PIN</button>
                            </form>
                            <?php if ((int) $emp['is_locked'] === 1): ?>
                                <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                                    <button type="submit" name="action" value="unlock" class="btn btn-accent btn-small">Unlock</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (can('employees.manage') && !$isMe): ?>
                            <?php $deactivate = $emp['status'] === 'active'; ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e(($deactivate ? 'Deactivate ' : 'Activate ') . full_name($emp) . '?' . ($deactivate ? ' They will not be able to log in.' : '')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                                <button type="submit" name="action" value="<?= $deactivate ? 'deactivate' : 'activate' ?>"
                                        class="btn <?= $deactivate ? 'btn-danger' : 'btn-accent' ?> btn-small"><?= $deactivate ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?>
            <tr class="empty-row"><td colspan="7">No employees match these filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
