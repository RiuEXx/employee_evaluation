<?php
/**
 * Employee results for one period, with search, filters, sorting and paging.
 * Scores stay hidden for employees below the minimum number of evaluations.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.view');

$periods = result_periods();
$period = selected_period($periods);
$departments = departments_list();

$q = get_str('q');
$deptFilter = get_int('department');
$statusFilter = get_str('status') ?: 'active';

$rows = [];
if ($period) {
    $periodId = (int) $period['id'];
    $results = score_rollup(['period_id' => $periodId], ['evaluatee_id']);
    $given = array_column(completion_rows($periodId), null, 'id');
    $receiveRoles = array_map('intval', explode(',', role_ids_with('evaluations.receive')));

    $stmt = db()->query(
        'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.position, e.status, e.role_id,
                e.department_id, d.name AS department_name
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id'
    );
    foreach ($stmt->fetchAll() as $emp) {
        $id = (int) $emp['id'];
        $entry = rollup_entry($results, (string) $id);
        // List people who can be evaluated, plus anyone who has results.
        if (!in_array((int) $emp['role_id'], $receiveRoles, true) && $entry['evaluations'] === 0) {
            continue;
        }
        if ($deptFilter && (int) $emp['department_id'] !== $deptFilter) {
            continue;
        }
        if ($statusFilter !== 'all' && $emp['status'] !== $statusFilter) {
            continue;
        }
        $name = full_name($emp);
        if ($q !== '' && mb_stripos($name . ' ' . $emp['employee_number'] . ' ' . $emp['position'], $q) === false) {
            continue;
        }
        $g = $given[$id] ?? null;
        $rows[] = $emp + [
            'name'        => $name,
            'sort_name'   => $emp['last_name'] . ' ' . $emp['first_name'],
            'evaluations' => $entry['evaluations'],
            'overall'     => enough_evaluations($entry['evaluations']) ? $entry['overall'] : null,
            'given_done'  => $g['done'] ?? null,
            'given_total' => $g['expected'] ?? null,
            'given_rate'  => $g['rate'] ?? null,
        ];
    }
}

$sort = sort_state([
    'name' => 'sort_name', 'department' => 'department_name', 'evaluations' => 'evaluations',
    'score' => 'overall', 'given' => 'given_rate',
], 'score', 'desc');
$rows = sort_rows($rows, $sort['column'], $sort['dir']);
$pages = pagination(count($rows));
$pageRows = page_slice($rows, $pages);

$pageTitle = 'Employee Results';
$breadcrumbs = [['Results', url('ceo/index.php')], ['Employees', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!$period): ?>
    <div class="alert alert-info">No evaluation period has been opened yet, so there are no results to show.</div>
<?php else: ?>
    <form method="get" class="filters no-print" role="search">
        <?= period_select_field($periods, $period) ?>
        <div class="field">
            <label for="department">Department</label>
            <select id="department" name="department">
                <option value="">All departments</option>
                <?= select_options(array_column($departments, 'name', 'id'), $deptFilter ?: '') ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?= select_options(['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'], $statusFilter) ?>
            </select>
        </div>
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, number or position">
        </div>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <a class="btn btn-secondary" href="<?= e(url('ceo/employees.php?period=' . (int) $period['id'])) ?>">Clear</a>
    </form>

    <p class="muted small">
        Scores appear once an employee has at least <?= min_evaluations() ?> evaluations.
        "Evaluations given" shows how many department mates the employee has evaluated in this period.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <?= sort_th('Employee', 'name', $sort) ?>
                <?= sort_th('Department', 'department', $sort) ?>
                <th scope="col">Position</th>
                <?= sort_th('Evaluations received', 'evaluations', $sort, 'num') ?>
                <?= sort_th('Score', 'score', $sort, 'num') ?>
                <?= sort_th('Evaluations given', 'given', $sort) ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('ceo/employee.php?id=' . (int) $row['id'] . '&period=' . (int) $period['id'])) ?>"><?= e($row['name']) ?></a>
                        <?php if ($row['status'] !== 'active'): ?> <?= status_badge('inactive') ?><?php endif; ?>
                        <br><span class="muted small"><?= e($row['employee_number']) ?></span>
                    </td>
                    <td><?= e($row['department_name'] ?? '-') ?></td>
                    <td><?= e($row['position'] ?: '-') ?></td>
                    <td class="num"><?= (int) $row['evaluations'] ?></td>
                    <td class="num">
                        <?= $row['overall'] !== null ? score_badge($row['overall']) : '<span class="muted small">Not enough</span>' ?>
                    </td>
                    <td><?= $row['given_total'] !== null ? e($row['given_done'] . ' of ' . $row['given_total']) : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?>
                <tr class="empty-row"><td colspan="6">No employees match these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pages) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
