<?php
/**
 * Printable report for one period (optionally one department): company
 * summary, departments, category averages, and every employee's scores.
 * The print stylesheet hides the menus and buttons; use the Print button.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.export');

$periods = result_periods();
$period = selected_period($periods);
$departments = departments_list();
$deptFilter = get_int('department');
$deptFilterRow = $deptFilter ? find_department($deptFilter) : null;
if (!$deptFilterRow) {
    $deptFilter = 0;
}
$categories = categories_map();

$pageTitle = 'Performance Evaluation Report';
$breadcrumbs = [['Results', url('ceo/index.php')], ['Printable Report', null]];
$hideTitle = true;
require __DIR__ . '/../includes/header.php';

if (!$period): ?>
    <h1 class="page-title"><?= e($pageTitle) ?></h1>
    <div class="alert alert-info">No evaluation period has been opened yet, so there is nothing to report.</div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

$periodId = (int) $period['id'];
$where = ['period_id' => $periodId] + ($deptFilter ? ['department_id' => $deptFilter] : []);
$scope = rollup_entry(score_rollup($where));
$byDept = score_rollup(['period_id' => $periodId], ['department_id']);
$byEmployee = score_rollup($where, ['evaluatee_id']);
$completionRows = completion_rows($periodId, $deptFilter ?: null);
$completion = completion_totals($completionRows);
$completionByDept = completion_by_department($completionRows);
$employees = employees_by_id();
$headcounts = department_headcounts();

// Employee rows grouped by the department they were evaluated in.
$sections = [];
foreach ($byEmployee as $entry) {
    $id = $entry['keys']['evaluatee_id'];
    if (!isset($employees[$id])) {
        continue;
    }
    $dId = evaluation_department($periodId, $id) ?? 0;
    $sections[$dId][] = $employees[$id] + [
        'evaluations' => $entry['evaluations'],
        'enough'      => enough_evaluations($entry['evaluations']),
        'overall'     => $entry['overall'],
        'categories'  => $entry['categories'],
        'sort_name'   => $employees[$id]['last_name'] . ' ' . $employees[$id]['first_name'],
    ];
}
$catIds = array_keys($scope['categories'] ?: array_filter($categories, fn($c) => (int) $c['is_active'] === 1));
?>

<form method="get" class="filters no-print">
    <?= period_select_field($periods, $period) ?>
    <div class="field">
        <label for="department">Department</label>
        <select id="department" name="department" data-autosubmit>
            <option value="">Whole company</option>
            <?= select_options(array_column($departments, 'name', 'id'), $deptFilter ?: '') ?>
        </select>
    </div>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
    <button type="button" class="btn btn-primary" data-print>Print report</button>
    <a class="btn btn-secondary" href="<?= e(url('ceo/export.php?type=employees&period=' . $periodId . ($deptFilter ? '&department=' . $deptFilter : ''))) ?>">Download employee CSV</a>
    <a class="btn btn-secondary" href="<?= e(url('ceo/export.php?type=departments&period=' . $periodId)) ?>">Download department CSV</a>
</form>

<div class="report-header">
    <div>
        <p class="muted m-0"><?= e(setting('company_name', '')) ?></p>
        <h1 class="page-title mb-0"><?= e($pageTitle) ?></h1>
        <p class="m-0"><strong><?= e($period['title']) ?></strong>
            (<?= e(format_date($period['start_date'])) ?> to <?= e(format_date($period['end_date'])) ?>, <?= e(ucfirst($period['status'])) ?>)
            <?= $deptFilterRow ? ' &middot; ' . e($deptFilterRow['name']) : '' ?></p>
    </div>
    <p class="muted small m-0">Generated <?= e(date('M j, Y g:i A')) ?><br>by <?= e(full_name($user)) ?></p>
</div>

<?php if ($period['status'] === 'open'): ?>
    <p class="alert alert-warning">This period is still open; the figures can still change.</p>
<?php endif; ?>

<section class="card">
    <h2>Summary</h2>
    <div class="table-wrap">
        <table class="report-table">
            <tbody>
            <tr><th scope="row">Evaluations submitted</th><td class="num"><?= (int) $scope['evaluations'] ?></td></tr>
            <tr><th scope="row">Completion rate</th><td class="num"><?= e(format_percent($completion['rate'])) ?> (<?= (int) $completion['done'] ?> of <?= (int) $completion['expected'] ?>)</td></tr>
            <tr><th scope="row">Employees who finished evaluating</th><td class="num"><?= (int) $completion['finished'] ?> of <?= (int) $completion['evaluators'] ?></td></tr>
            <tr><th scope="row"><?= $deptFilterRow ? 'Department average' : 'Company average' ?></th><td class="num"><?= format_score($scope['overall']) ?> <?= e(score_label($scope['overall'])) ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<?php if (!$deptFilter): ?>
    <section class="card">
        <h2>Departments</h2>
        <div class="table-wrap">
            <table class="report-table">
                <thead>
                <tr>
                    <th scope="col">Department</th>
                    <th scope="col" class="num">Active employees</th>
                    <th scope="col" class="num">Evaluations</th>
                    <th scope="col" class="num">Completion</th>
                    <th scope="col" class="num">Average</th>
                    <th scope="col">Highest category</th>
                    <th scope="col">Lowest category</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $d): ?>
                    <?php
                    $entry = rollup_entry($byDept, (string) $d['id']);
                    if (!$d['is_active'] && $entry['evaluations'] === 0) {
                        continue;
                    }
                    $cats = $entry['categories'];
                    $best = $cats ? array_keys($cats, max($cats))[0] : null;
                    $worst = $cats ? array_keys($cats, min($cats))[0] : null;
                    ?>
                    <tr>
                        <td><?= e($d['name']) ?></td>
                        <td class="num"><?= (int) ($headcounts[(int) $d['id']] ?? 0) ?></td>
                        <td class="num"><?= (int) $entry['evaluations'] ?></td>
                        <td class="num"><?= e(format_percent($completionByDept[(int) $d['id']]['rate'] ?? null)) ?></td>
                        <td class="num"><?= format_score($entry['overall']) ?></td>
                        <td><?= $best !== null ? e($categories[$best]['name']) . ' (' . format_score($cats[$best]) . ')' : '-' ?></td>
                        <td><?= $worst !== null ? e($categories[$worst]['name']) . ' (' . format_score($cats[$worst]) . ')' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Average by category</h2>
    <div class="table-wrap">
        <table class="report-table">
            <thead>
            <tr><th scope="col">Category</th><th scope="col" class="num">Weight</th><th scope="col" class="num">Average</th><th scope="col">Rating</th></tr>
            </thead>
            <tbody>
            <?php foreach ($scope['categories'] as $catId => $avg): ?>
                <tr>
                    <td><?= e($categories[$catId]['name'] ?? 'Category') ?></td>
                    <td class="num"><?= e($categories[$catId]['weight'] ?? '1.00') ?></td>
                    <td class="num"><?= format_score($avg) ?></td>
                    <td><?= e(score_label($avg)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$scope['categories']): ?>
                <tr class="empty-row"><td colspan="4">No evaluations in this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php foreach ($departments as $d): ?>
    <?php
    $list = $sections[(int) $d['id']] ?? [];
    if (!$list) {
        continue;
    }
    $list = sort_rows($list, 'sort_name', 'asc');
    ?>
    <section class="card">
        <h2>Employees: <?= e($d['name']) ?></h2>
        <div class="table-wrap">
            <table class="report-table">
                <thead>
                <tr>
                    <th scope="col">Employee</th>
                    <th scope="col">Position</th>
                    <th scope="col" class="num">Evals</th>
                    <th scope="col" class="num">Overall</th>
                    <?php foreach ($catIds as $catId): ?>
                        <th scope="col" class="num"><?= e($categories[$catId]['name'] ?? 'Category') ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <tr>
                        <td><?= e(full_name($row)) ?><?= $row['status'] !== 'active' ? ' (inactive)' : '' ?></td>
                        <td><?= e($row['position'] ?: '-') ?></td>
                        <td class="num"><?= (int) $row['evaluations'] ?></td>
                        <?php if ($row['enough']): ?>
                            <td class="num"><strong><?= format_score($row['overall']) ?></strong></td>
                            <?php foreach ($catIds as $catId): ?>
                                <td class="num"><?= format_score($row['categories'][$catId] ?? null) ?></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td colspan="<?= count($catIds) + 1 ?>" class="muted">Hidden: fewer than <?= min_evaluations() ?> evaluations</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<p class="muted small">
    Rating scale: <?php foreach (RATING_LABELS as $s => $l): ?><?= $s ?> = <?= e($l) ?><?= $s < 5 ? ', ' : '.' ?><?php endforeach; ?>
    The overall score is the weighted average of the category averages. Individual scores are only shown for
    employees with at least <?= min_evaluations() ?> evaluations, so that no single evaluator can be identified.
</p>

<?php require __DIR__ . '/../includes/footer.php'; ?>
