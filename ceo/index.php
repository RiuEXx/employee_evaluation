<?php
/**
 * Results overview (CEO dashboard): company-wide numbers for one period,
 * department averages, category averages, top performers and the trend.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.view');

$periods = result_periods();
$period = selected_period($periods);

$totalEmployees = (int) db()->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
$totalDepartments = (int) db()->query('SELECT COUNT(*) FROM departments WHERE is_active = 1')->fetchColumn();

$pageTitle = 'Results Overview';
$useCharts = true;
require __DIR__ . '/../includes/header.php';

if (!$period): ?>
    <div class="stats">
        <div class="stat"><p class="stat-label">Active employees</p><p class="stat-value"><?= $totalEmployees ?></p></div>
        <div class="stat accent"><p class="stat-label">Departments</p><p class="stat-value"><?= $totalDepartments ?></p></div>
    </div>
    <div class="alert alert-info">No evaluation period has been opened yet, so there are no results to show.</div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

$periodId = (int) $period['id'];
$categories = categories_map();
$company = rollup_entry(score_rollup(['period_id' => $periodId]));
$byDept = score_rollup(['period_id' => $periodId], ['department_id']);
$completionRows = completion_rows($periodId);
$completion = completion_totals($completionRows);
$completionByDept = completion_by_department($completionRows);
$headcounts = department_headcounts();

// Departments that are active or have results in this period.
$deptRows = [];
foreach (departments_list() as $d) {
    $id = (int) $d['id'];
    $entry = rollup_entry($byDept, (string) $id);
    if (!$d['is_active'] && $entry['evaluations'] === 0) {
        continue;
    }
    $deptRows[] = [
        'id'          => $id,
        'name'        => $d['name'],
        'employees'   => $headcounts[$id] ?? 0,
        'evaluations' => $entry['evaluations'],
        'overall'     => $entry['overall'],
        'completion'  => $completionByDept[$id]['rate'] ?? null,
    ];
}

// Top performers: employees with enough evaluations, best overall first.
$employees = employees_by_id();
$ranked = [];
foreach (score_rollup(['period_id' => $periodId], ['evaluatee_id']) as $entry) {
    $id = $entry['keys']['evaluatee_id'];
    if (enough_evaluations($entry['evaluations']) && isset($employees[$id])) {
        $ranked[] = $employees[$id] + ['overall' => $entry['overall'], 'evaluations' => $entry['evaluations']];
    }
}
$ranked = array_slice(sort_rows($ranked, 'overall', 'desc'), 0, 5);

$trend = array_values(array_filter(score_trend([]), fn($t) => $t['evaluations'] > 0));
?>

<form method="get" class="filters no-print">
    <?= period_select_field($periods, $period) ?>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
</form>

<?php if ($period['status'] === 'open'): ?>
    <div class="alert alert-warning">
        <?= e($period['title']) ?> is still open (until <?= e(format_date($period['end_date'])) ?>), so these results can still change.
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <p class="stat-label">Active employees</p>
        <p class="stat-value"><?= $totalEmployees ?></p>
    </div>
    <div class="stat">
        <p class="stat-label">Departments</p>
        <p class="stat-value"><?= $totalDepartments ?></p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Completion rate</p>
        <p class="stat-value"><?= format_percent($completion['rate']) ?></p>
        <p class="stat-note"><?= (int) $completion['done'] ?> of <?= (int) $completion['expected'] ?> evaluations submitted</p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Company average</p>
        <p class="stat-value"><?= format_score($company['overall']) ?></p>
        <p class="stat-note"><?= $company['overall'] !== null ? e(score_label($company['overall'])) . ', from ' . plural($company['evaluations'], 'evaluation') : 'No evaluations yet' ?></p>
    </div>
</div>

<div class="grid-2 section-gap">
    <section class="card">
        <h2>Average score by department</h2>
        <?php
        $withScores = array_values(array_filter($deptRows, fn($r) => $r['overall'] !== null));
        if ($withScores) {
            echo chart_canvas('chart-departments', [
                'type' => 'bar',
                'labels' => array_column($withScores, 'name'),
                'datasets' => [['label' => 'Average score', 'data' => array_map(fn($r) => round($r['overall'], 2), $withScores), 'color' => 'blue']],
            ], 'Bar chart of the average score of each department');
        } else {
            echo '<p class="empty-state">No evaluations have been submitted in this period yet.</p>';
        }
        ?>
    </section>

    <section class="card">
        <h2>Company average by category</h2>
        <?php if ($company['categories']): ?>
            <?= chart_canvas('chart-categories', [
                'type' => 'horizontalBar',
                'labels' => array_map(fn($id) => $categories[$id]['name'] ?? 'Category', array_keys($company['categories'])),
                'datasets' => [['label' => 'Company average', 'data' => array_map(fn($v) => round($v, 2), array_values($company['categories'])), 'color' => 'green']],
            ], 'Bar chart of the company average for each rating category') ?>
        <?php else: ?>
            <p class="empty-state">No category scores yet.</p>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="card-header">
        <h2>Departments</h2>
        <a class="btn btn-secondary btn-small" href="<?= e(url('ceo/departments.php?period=' . $periodId)) ?>">All department details</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th scope="col">Department</th>
                <th scope="col" class="num">Active employees</th>
                <th scope="col" class="num">Evaluations</th>
                <th scope="col">Completion</th>
                <th scope="col" class="num">Average score</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($deptRows as $row): ?>
                <tr>
                    <td><a href="<?= e(url('ceo/department.php?id=' . $row['id'] . '&period=' . $periodId)) ?>"><?= e($row['name']) ?></a></td>
                    <td class="num"><?= (int) $row['employees'] ?></td>
                    <td class="num"><?= (int) $row['evaluations'] ?></td>
                    <td><?= $row['completion'] !== null
                            ? progress_bar((int) round($row['completion'] * 100), 100, format_percent($row['completion']))
                            : '<span class="muted">-</span>' ?></td>
                    <td class="num"><?= score_badge($row['overall']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deptRows): ?>
                <tr class="empty-row"><td colspan="5">No departments yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="grid-2 section-gap">
    <section class="card">
        <h2>Top performers</h2>
        <?php if ($ranked): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th scope="col">Employee</th><th scope="col">Department</th><th scope="col" class="num">Score</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ranked as $row): ?>
                        <tr>
                            <td><a href="<?= e(url('ceo/employee.php?id=' . (int) $row['id'] . '&period=' . $periodId)) ?>"><?= e(full_name($row)) ?></a>
                                <br><span class="muted small"><?= e($row['position'] ?? '') ?></span></td>
                            <td><?= e($row['department_name'] ?? '-') ?></td>
                            <td class="num"><?= score_badge($row['overall']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small mb-0">Only employees with at least <?= min_evaluations() ?> evaluations are ranked.</p>
        <?php else: ?>
            <p class="empty-state">No employee has enough evaluations yet (at least <?= min_evaluations() ?> are needed).</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Company trend</h2>
        <?php if (count($trend) > 1): ?>
            <?= chart_canvas('chart-trend', [
                'type' => 'line',
                'labels' => array_map(fn($t) => $t['period']['title'], $trend),
                'datasets' => [['label' => 'Company average', 'data' => array_map(fn($t) => $t['overall'] !== null ? round($t['overall'], 2) : null, $trend), 'color' => 'blue']],
            ], 'Line chart of the company average score per evaluation period', 'short') ?>
        <?php else: ?>
            <p class="empty-state">The trend appears once there are results for at least two periods.</p>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
