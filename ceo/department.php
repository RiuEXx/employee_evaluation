<?php
/**
 * One department in one period: average, completion, category averages
 * compared with the company, the ranking of its employees, and the trend.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.view');

$department = find_department(get_int('id'));
if (!$department) {
    render_error_page(404, 'That department does not exist.');
}
$deptId = (int) $department['id'];

$periods = result_periods();
$period = selected_period($periods);
$categories = categories_map();

$pageTitle = $department['name'];
$breadcrumbs = [['Results', url('ceo/index.php')], ['Departments', url('ceo/departments.php')], [$department['name'], null]];
$navActive = 'ceo/departments.php';
$useCharts = true;
require __DIR__ . '/../includes/header.php';

if (!$period): ?>
    <div class="alert alert-info">No evaluation period has been opened yet, so there are no results to show.</div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

$periodId = (int) $period['id'];
$dept = rollup_entry(score_rollup(['period_id' => $periodId, 'department_id' => $deptId]));
$company = rollup_entry(score_rollup(['period_id' => $periodId]));
$completionRows = completion_rows($periodId, $deptId);
$completion = completion_totals($completionRows);
$givenByEvaluator = array_column($completionRows, null, 'id');

$cats = $dept['categories'];
$bestId = $cats ? array_keys($cats, max($cats))[0] : null;
$worstId = $cats ? array_keys($cats, min($cats))[0] : null;

// Ranking: everyone evaluated in this department in this period, plus current
// active members who have not received evaluations yet.
$employees = employees_by_id();
$results = score_rollup(['period_id' => $periodId, 'department_id' => $deptId], ['evaluatee_id']);
$memberIds = array_map(fn($e) => $e['keys']['evaluatee_id'], $results);
foreach ($employees as $id => $emp) {
    if ((int) $emp['department_id'] === $deptId && $emp['status'] === 'active') {
        $memberIds[] = $id;
    }
}
$rows = [];
foreach (array_unique($memberIds) as $id) {
    if (!isset($employees[$id])) {
        continue;
    }
    $entry = rollup_entry($results, (string) $id);
    $enough = enough_evaluations($entry['evaluations']);
    $rows[] = $employees[$id] + [
        'name'        => full_name($employees[$id]),
        'sort_name'   => $employees[$id]['last_name'] . ' ' . $employees[$id]['first_name'],
        'evaluations' => $entry['evaluations'],
        'overall'     => $enough ? $entry['overall'] : null,
        'given'       => $givenByEvaluator[$id] ?? null,
    ];
}
$rows = add_ranks(sort_rows($rows, 'overall', 'desc'), 'overall');

$q = search_query();
if ($q !== '') {
    // Employee numbers are searchable only for people who manage employees.
    $searchNumbers = can_see_employee_numbers();
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_stripos($r['name'] . ' ' . $r['position'] . ($searchNumbers ? ' ' . $r['employee_number'] : ''), $q) !== false));
}
$sort = sort_state(['rank' => 'rank', 'name' => 'sort_name', 'evaluations' => 'evaluations', 'score' => 'overall'], 'rank');
$rows = sort_rows($rows, $sort['column'], $sort['dir']);
$pages = pagination(count($rows));
$pageRows = page_slice($rows, $pages);

$deptTrend = score_trend(['department_id' => $deptId]);
$companyTrend = score_trend([]);
$trendIndexes = array_keys(array_filter($deptTrend, fn($t) => $t['evaluations'] > 0));
?>

<form method="get" class="filters no-print">
    <input type="hidden" name="id" value="<?= $deptId ?>">
    <?= period_select_field($periods, $period) ?>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
</form>

<?php if ($period['status'] === 'open'): ?>
    <div class="alert alert-warning">This period is still open, so these results can still change.</div>
<?php endif; ?>
<?php if (!(int) $department['is_active']): ?>
    <div class="alert alert-info">This department is inactive. Its past results are kept.</div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <p class="stat-label">Department average</p>
        <p class="stat-value"><?= format_score($dept['overall']) ?></p>
        <p class="stat-note">Company: <?= format_score($company['overall']) ?></p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Completion rate</p>
        <p class="stat-value"><?= format_percent($completion['rate']) ?></p>
        <p class="stat-note"><?= (int) $completion['done'] ?> of <?= (int) $completion['expected'] ?> evaluations</p>
    </div>
    <div class="stat">
        <p class="stat-label">Highest category</p>
        <p class="stat-value small-value"><?= e($bestId !== null ? $categories[$bestId]['name'] : '-') ?></p>
        <p class="stat-note"><?= $bestId !== null ? format_score($cats[$bestId]) : '' ?></p>
    </div>
    <div class="stat">
        <p class="stat-label">Lowest category</p>
        <p class="stat-value small-value"><?= e($worstId !== null ? $categories[$worstId]['name'] : '-') ?></p>
        <p class="stat-note"><?= $worstId !== null ? format_score($cats[$worstId]) : '' ?></p>
    </div>
</div>

<div class="grid-2 section-gap">
    <section class="card">
        <h2>Category averages</h2>
        <?php if ($cats): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col" class="num">Department</th>
                        <th scope="col" class="num">Company</th>
                        <th scope="col" class="num">Difference</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cats as $catId => $avg): ?>
                        <?php $companyAvg = $company['categories'][$catId] ?? null; ?>
                        <tr>
                            <td><?= e($categories[$catId]['name'] ?? 'Category') ?></td>
                            <td class="num"><?= score_badge($avg) ?></td>
                            <td class="num"><?= format_score($companyAvg) ?></td>
                            <td class="num"><?= $companyAvg !== null ? sprintf('%+.2f', $avg - $companyAvg) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty-state">No evaluations for this department in this period.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Department compared with the company</h2>
        <?php if ($cats): ?>
            <?= chart_canvas('chart-dept-categories', [
                'type' => 'horizontalBar',
                'labels' => array_map(fn($id) => $categories[$id]['name'] ?? 'Category', array_keys($cats)),
                'datasets' => [
                    ['label' => $department['name'], 'data' => array_map(fn($v) => round($v, 2), array_values($cats)), 'color' => 'blue'],
                    ['label' => 'Company average', 'data' => array_map(fn($id) => isset($company['categories'][$id]) ? round($company['categories'][$id], 2) : null, array_keys($cats)), 'color' => 'green'],
                ],
            ], 'Bar chart comparing department and company averages by category', 'tall') ?>
        <?php else: ?>
            <p class="empty-state">No data to chart.</p>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="card-header">
        <h2>Employee ranking</h2>
        <form method="get" class="filters m-0" role="search">
            <input type="hidden" name="id" value="<?= $deptId ?>">
            <input type="hidden" name="period" value="<?= $periodId ?>">
            <div class="field">
                <label for="q" class="visually-hidden">Search employees</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="Search employees">
            </div>
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <?= sort_th('Rank', 'rank', $sort, 'num') ?>
                <?= sort_th('Employee', 'name', $sort) ?>
                <th scope="col">Position</th>
                <?= sort_th('Evaluations received', 'evaluations', $sort, 'num') ?>
                <?= sort_th('Score', 'score', $sort, 'num') ?>
                <th scope="col">Evaluations given</th>
                <th scope="col" class="no-print">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <tr>
                    <td class="num"><?= $row['rank'] !== null ? (int) $row['rank'] : '-' ?></td>
                    <td>
                        <?= e($row['name']) ?>
                        <?php if ($row['status'] !== 'active'): ?> <?= status_badge('inactive') ?><?php endif; ?>
                        <?php if ((int) $row['department_id'] !== $deptId): ?><br><span class="muted small">Now in <?= e($row['department_name'] ?? 'no department') ?></span><?php endif; ?>
                    </td>
                    <td><?= e($row['position'] ?: '-') ?></td>
                    <td class="num"><?= (int) $row['evaluations'] ?></td>
                    <td class="num">
                        <?= $row['overall'] !== null
                            ? score_badge($row['overall'])
                            : '<span class="muted small">' . e(not_enough_text((int) $row['evaluations'])) . '</span>' ?>
                    </td>
                    <td><?= $row['given'] ? e($row['given']['done'] . ' of ' . $row['given']['expected']) : '<span class="muted">-</span>' ?></td>
                    <td class="actions-cell no-print">
                        <a class="btn btn-secondary btn-small" href="<?= e(url('ceo/employee.php?id=' . (int) $row['id'] . '&period=' . $periodId)) ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?>
                <tr class="empty-row"><td colspan="7">No employees to show.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pages) ?>
</section>

<?php if (count($trendIndexes) > 1): ?>
    <section class="card">
        <h2>Trend across periods</h2>
        <?= chart_canvas('chart-dept-trend', [
            'type' => 'line',
            'labels' => array_map(fn($t) => $t['period']['title'], $deptTrend),
            'datasets' => [
                ['label' => $department['name'], 'data' => array_map(fn($t) => $t['overall'] !== null ? round($t['overall'], 2) : null, $deptTrend), 'color' => 'blue'],
                ['label' => 'Company average', 'data' => array_map(fn($t) => $t['overall'] !== null ? round($t['overall'], 2) : null, $companyTrend), 'color' => 'green'],
            ],
        ], 'Line chart of the department and company averages per period', 'short') ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
