<?php
/**
 * Departments: list with search, filter, sorting and paging, plus the
 * Activate / Deactivate and Delete actions. A department that still has
 * employees or evaluations cannot be deleted; deactivate it instead.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('departments.manage');

if (is_post()) {
    $department = find_department(post_int('id'));
    $action = post_str('action');

    if (!$department) {
        flash('error', 'That department no longer exists.');
    } elseif ($action === 'toggle') {
        $active = (int) $department['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE departments SET is_active = ? WHERE id = ?')->execute([$active, $department['id']]);
        audit_log($active ? 'department_activate' : 'department_deactivate', 'Department: ' . $department['name'] . '.');
        flash('success', $department['name'] . ($active ? ' is active again.' : ' has been deactivated. Its members no longer take part in evaluations.'));
    } elseif ($action === 'delete') {
        $stmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM employees WHERE department_id = ?) AS employees,
                    (SELECT COUNT(*) FROM evaluations WHERE department_id = ?) AS evaluations'
        );
        $stmt->execute([$department['id'], $department['id']]);
        $usage = $stmt->fetch();
        if ($usage['employees'] > 0 || $usage['evaluations'] > 0) {
            flash('error', $department['name'] . ' cannot be deleted because it has ' . plural((int) $usage['employees'], 'employee')
                . ' and ' . plural((int) $usage['evaluations'], 'evaluation') . '. Deactivate it instead.');
        } else {
            db()->prepare('DELETE FROM departments WHERE id = ?')->execute([$department['id']]);
            audit_log('department_delete', 'Department: ' . $department['name'] . '.');
            flash('success', $department['name'] . ' has been deleted.');
        }
    }
    redirect(current_request_uri());
}

$q = search_query();
$status = get_str('status');
$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = '(d.name LIKE ? OR d.description LIKE ?)';
    $params[] = like_pattern($q);
    $params[] = like_pattern($q);
}
if ($status === 'active' || $status === 'inactive') {
    $conditions[] = 'd.is_active = ?';
    $params[] = $status === 'active' ? 1 : 0;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM departments d $whereSql");
$stmt->execute($params);
$pages = pagination((int) $stmt->fetchColumn());

$sort = sort_state([
    'name' => 'd.name', 'employees' => 'active_employees', 'status' => 'd.is_active', 'created' => 'd.created_at',
], 'name');
$stmt = db()->prepare(
    "SELECT d.*,
            (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.status = 'active') AS active_employees,
            (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) AS all_employees,
            (SELECT COUNT(*) FROM evaluations v WHERE v.department_id = d.id) AS evaluations
     FROM departments d
     $whereSql
     ORDER BY {$sort['sql']}, d.name
     LIMIT {$pages['per_page']} OFFSET {$pages['offset']}"
);
$stmt->execute($params);
$departments = $stmt->fetchAll();

$pageTitle = 'Departments';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Departments', null]];
require __DIR__ . '/../includes/header.php';
?>

<div class="filters">
    <form method="get" class="filters m-0" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="Name or description">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?= select_options(['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'], $status) ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">Apply</button>
    </form>
    <a class="btn btn-primary" href="<?= e(url('admin/department_edit.php')) ?>">Add department</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?= sort_th('Name', 'name', $sort) ?>
            <th scope="col">Description</th>
            <?= sort_th('Active employees', 'employees', $sort, 'num') ?>
            <?= sort_th('Status', 'status', $sort) ?>
            <?= sort_th('Created', 'created', $sort) ?>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($departments as $d): ?>
            <tr>
                <td><?= e($d['name']) ?></td>
                <td><?= e($d['description'] ?: '-') ?></td>
                <td class="num"><?= (int) $d['active_employees'] ?></td>
                <td><?= status_badge($d['is_active'] ? 'active' : 'inactive') ?></td>
                <td class="nowrap"><?= e(format_date($d['created_at'])) ?></td>
                <td class="actions-cell">
                    <div class="actions">
                        <a class="btn btn-secondary btn-small" href="<?= e(url('admin/department_edit.php?id=' . (int) $d['id'])) ?>">Edit</a>
                        <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                              data-confirm="<?= e($d['is_active'] ? 'Deactivate ' . $d['name'] . '? Its members will stop taking part in evaluations.' : 'Activate ' . $d['name'] . '?') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" name="action" value="toggle" class="btn btn-secondary btn-small"><?= $d['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php if ((int) $d['all_employees'] === 0 && (int) $d['evaluations'] === 0): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e('Delete ' . $d['name'] . ' permanently?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" name="action" value="delete" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$departments): ?>
            <tr class="empty-row"><td colspan="6">No departments found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>
<p class="muted small">A department with employees or evaluations cannot be deleted. Deactivate it instead: its history is kept.</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
