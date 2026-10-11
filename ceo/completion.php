<?php
/**
 * Evaluation completion tracker: who has and has not finished evaluating
 * their department mates in a period, per employee and per department.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('completion.view');

$periods = result_periods();
$period = selected_period($periods);
$departments = departments_list(true);

$q = search_query();
$deptFilter = get_int('department');
$statusFilter = get_str('status');
$statusOptions = ['' => 'All', 'unfinished' => 'Not finished', 'Not started' => 'Not started', 'In progress' => 'In progress', 'Complete' => 'Complete'];
if (!array_key_exists($statusFilter, $statusOptions)) {
    $statusFilter = '';
}

$allRows = $period ? completion_rows((int) $period['id']) : [];
$totals = completion_totals($allRows);
$byDept = completion_by_department($allRows);

// Employee numbers are shown (and searchable) only for people who manage employees.
$showNumbers = can_see_employee_numbers();

$rows = array_values(array_filter($allRows, function ($r) use ($q, $deptFilter, $statusFilter, $showNumbers) {
    if ($deptFilter && (int) $r['department_id'] !== $deptFilter) {
        return false;
    }
    if ($statusFilter === 'unfinished' && $r['status'] === 'Complete') {
        return false;
    }
    if ($statusFilter !== '' && $statusFilter !== 'unfinished' && $r['status'] !== $statusFilter) {
        return false;
    }
    return $q === '' || mb_stripos($r['name'] . ' ' . $r['position'] . ($showNumbers ? ' ' . $r['employee_number'] : ''), $q) !== false;
}));
foreach ($rows as &$row) {
    $row['sort_name'] = $row['last_name'] . ' ' . $row['first_name'];
    $row['remaining'] = $row['expected'] - $row['done'];
}
unset($row);

$sort = sort_state([
    'name' => 'sort_name', 'department' => 'department_name', 'done' => 'done',
    'remaining' => 'remaining', 'progress' => 'rate',
], 'progress', 'asc');
$rows = sort_rows($rows, $sort['column'], $sort['dir']);
$pages = pagination(count($rows));
$pageRows = page_slice($rows, $pages);

$pageTitle = 'Completion Tracker';
$breadcrumbs = [['Results', url(can('results.view') ? 'ceo/index.php' : 'ceo/completion.php')], ['Completion Tracker', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!$period): ?>
    <div class="alert alert-info">No evaluation period has been opened yet.</div>
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
            <select id="status" name="status"><?= select_options($statusOptions, $statusFilter) ?></select>
        </div>
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="<?= $showNumbers ? 'Name or number' : 'Name or position' ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <a class="btn btn-secondary" href="<?= e(url('ceo/export.php?type=completion&period=' . (int) $period['id'])) ?>">Download CSV</a>
    </form>

    <div class="stats">
        <div class="stat accent">
            <p class="stat-label">Overall completion</p>
            <p class="stat-value"><?= format_percent($totals['rate']) ?></p>
            <p class="stat-note"><?= (int) $totals['done'] ?> of <?= (int) $totals['expected'] ?> evaluations submitted</p>
        </div>
        <div class="stat">
            <p class="stat-label">Employees finished</p>
            <p class="stat-value"><?= (int) $totals['finished'] ?> of <?= (int) $totals['evaluators'] ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Employees not finished</p>
            <p class="stat-value"><?= (int) ($totals['evaluators'] - $totals['finished']) ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Period</p>
            <p class="stat-value small-value"><?= e($period['title']) ?></p>
            <p class="stat-note"><?= e(format_date($period['start_date'])) ?> to <?= e(format_date($period['end_date'])) ?>, <?= e(ucfirst($period['status'])) ?></p>
        </div>
    </div>

    <section class="card">
        <h2>By department</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Department</th>
                    <th scope="col" class="num">Employees finished</th>
                    <th scope="col" class="num">Evaluations submitted</th>
                    <th scope="col">Progress</th>
                    <th scope="col" class="no-print">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($byDept as $id => $d): ?>
                    <tr>
                        <td><?= e($d['name']) ?></td>
                        <td class="num"><?= (int) $d['finished'] ?> of <?= (int) $d['evaluators'] ?></td>
                        <td class="num"><?= (int) $d['done'] ?> of <?= (int) $d['expected'] ?></td>
                        <td><?= progress_bar((int) $d['done'], (int) $d['expected']) ?></td>
                        <td class="actions-cell no-print">
                            <a class="btn btn-secondary btn-small" href="<?= e(query_url(['department' => $id, 'page' => null])) ?>#by-employee">Show employees</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byDept): ?>
                    <tr class="empty-row"><td colspan="5">No one takes part in evaluations yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" id="by-employee">
        <h2>By employee</h2>
        <p class="muted small">Progress is measured against each employee's current department mates.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <?= sort_th('Employee', 'name', $sort) ?>
                    <?= sort_th('Department', 'department', $sort) ?>
                    <?= sort_th('Done', 'done', $sort, 'num') ?>
                    <?= sort_th('Remaining', 'remaining', $sort, 'num') ?>
                    <?= sort_th('Progress', 'progress', $sort) ?>
                    <th scope="col">Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pageRows as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?><?php if ($showNumbers): ?><br><span class="muted small"><?= e($row['employee_number']) ?></span><?php endif; ?></td>
                        <td><?= e($row['department_name']) ?></td>
                        <td class="num"><?= (int) $row['done'] ?> of <?= (int) $row['expected'] ?></td>
                        <td class="num"><?= (int) $row['remaining'] ?></td>
                        <td><?= progress_bar((int) $row['done'], (int) $row['expected'], format_percent($row['rate'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pageRows): ?>
                    <tr class="empty-row"><td colspan="6">No employees match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($pages) ?>
    </section>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
