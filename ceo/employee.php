<?php
/**
 * One employee's results: category averages compared with their department
 * and the company, the number of evaluators, anonymous comments, and the
 * trend across periods. Everything stays hidden below the minimum number of
 * evaluations, to protect the evaluators' anonymity.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.view');

$employee = find_employee(get_int('id'));
if (!$employee) {
    render_error_page(404, 'That employee does not exist.');
}
$empId = (int) $employee['id'];
$name = full_name($employee);

$periods = result_periods();
$period = selected_period($periods);
$categories = categories_map();
$showNames = setting_bool('show_evaluator_names_to_ceo');

$pageTitle = $name;
$breadcrumbs = [['Results', url('ceo/index.php')], ['Employees', url('ceo/employees.php')], [$name, null]];
$navActive = 'ceo/employees.php';
$useCharts = true;
require __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <dl class="details">
        <dt>Employee number</dt><dd><?= e($employee['employee_number']) ?></dd>
        <dt>Position</dt><dd><?= e($employee['position'] ?: '-') ?></dd>
        <dt>Department</dt><dd><?= e($employee['department_name'] ?? 'No department') ?></dd>
        <dt>Role</dt><dd><?= e($employee['role_name']) ?></dd>
        <dt>Status</dt><dd><?= status_badge($employee['status']) ?></dd>
        <dt>Date hired</dt><dd><?= e(format_date($employee['date_hired'])) ?></dd>
    </dl>
</section>

<?php if (!$period): ?>
    <div class="alert alert-info">No evaluation period has been opened yet, so there are no results to show.</div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

$periodId = (int) $period['id'];
$mine = rollup_entry(score_rollup(['period_id' => $periodId, 'evaluatee_id' => $empId]));
$count = $mine['evaluations'];
$enough = enough_evaluations($count);

$deptId = evaluation_department($periodId, $empId) ?? (int) $employee['department_id'];
$deptRow = $deptId ? find_department($deptId) : null;
$dept = $deptId ? rollup_entry(score_rollup(['period_id' => $periodId, 'department_id' => $deptId])) : rollup_entry([]);
$company = rollup_entry(score_rollup(['period_id' => $periodId]));

$given = null;
foreach (completion_rows($periodId, (int) $employee['department_id'] ?: null) as $row) {
    if ((int) $row['id'] === $empId) {
        $given = $row;
    }
}

// Trend: the employee's score per period, next to the average of the
// department they were in at that time.
$trendRows = [];
foreach (score_trend(['evaluatee_id' => $empId]) as $t) {
    if ($t['evaluations'] === 0) {
        continue;
    }
    $pid = (int) $t['period']['id'];
    $tDeptId = evaluation_department($pid, $empId);
    $tDept = $tDeptId ? rollup_entry(score_rollup(['period_id' => $pid, 'department_id' => $tDeptId])) : rollup_entry([]);
    $tDeptRow = $tDeptId ? find_department($tDeptId) : null;
    $trendRows[] = [
        'period'      => $t['period'],
        'evaluations' => $t['evaluations'],
        'overall'     => enough_evaluations($t['evaluations']) ? $t['overall'] : null,
        'department'  => $tDeptRow['name'] ?? '-',
        'dept_avg'    => $tDept['overall'],
    ];
}

/** "+0.25" / "-0.10" difference, or "-" when one side is missing. */
function diff_text(?float $a, ?float $b): string
{
    return ($a === null || $b === null) ? '-' : sprintf('%+.2f', $a - $b);
}
?>

<form method="get" class="filters no-print">
    <input type="hidden" name="id" value="<?= $empId ?>">
    <?= period_select_field($periods, $period) ?>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
</form>

<?php if ($period['status'] === 'open'): ?>
    <div class="alert alert-warning">This period is still open, so these results can still change.</div>
<?php endif; ?>

<?php if ($count === 0): ?>
    <div class="alert alert-info"><?= e($name) ?> did not receive any evaluations in <?= e($period['title']) ?>.</div>
<?php elseif (!$enough): ?>
    <div class="alert alert-info">
        <?= e(not_enough_text($count)) ?>. Scores and comments are hidden until at least
        <?= min_evaluations() ?> colleagues have evaluated <?= e($employee['first_name']) ?>, so no single evaluator can be identified.
    </div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <p class="stat-label">Overall score</p>
        <p class="stat-value"><?= $enough ? format_score($mine['overall']) : '-' ?></p>
        <p class="stat-note"><?= $enough ? e(score_label($mine['overall'])) : 'Hidden' ?></p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Number of evaluators</p>
        <p class="stat-value"><?= (int) $count ?></p>
    </div>
    <div class="stat">
        <p class="stat-label">Department average</p>
        <p class="stat-value"><?= format_score($dept['overall']) ?></p>
        <p class="stat-note"><?= e($deptRow['name'] ?? '') ?><?= $enough ? ' (difference ' . diff_text($mine['overall'], $dept['overall']) . ')' : '' ?></p>
    </div>
    <div class="stat">
        <p class="stat-label">Company average</p>
        <p class="stat-value"><?= format_score($company['overall']) ?></p>
        <p class="stat-note"><?= $enough ? 'Difference ' . diff_text($mine['overall'], $company['overall']) : '' ?></p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Evaluations given</p>
        <p class="stat-value"><?= $given ? (int) $given['done'] . ' of ' . (int) $given['expected'] : '-' ?></p>
        <p class="stat-note">Department mates evaluated</p>
    </div>
</div>

<?php if ($enough): ?>
    <div class="grid-2 section-gap">
        <section class="card">
            <h2>Average by category</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col" class="num">Employee</th>
                        <th scope="col" class="num">Department</th>
                        <th scope="col" class="num">Company</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mine['categories'] as $catId => $avg): ?>
                        <tr>
                            <td><?= e($categories[$catId]['name'] ?? 'Category') ?></td>
                            <td class="num"><?= score_badge($avg) ?></td>
                            <td class="num"><?= format_score($dept['categories'][$catId] ?? null) ?></td>
                            <td class="num"><?= format_score($company['categories'][$catId] ?? null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td>Overall (weighted)</td>
                        <td class="num"><?= format_score($mine['overall']) ?></td>
                        <td class="num"><?= format_score($dept['overall']) ?></td>
                        <td class="num"><?= format_score($company['overall']) ?></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Category profile</h2>
            <?php
            $catIds = array_keys($mine['categories']);
            $round = fn($v) => $v !== null ? round($v, 2) : null;
            echo chart_canvas('chart-employee-radar', [
                'type' => 'radar',
                'labels' => array_map(fn($id) => $categories[$id]['name'] ?? 'Category', $catIds),
                'datasets' => [
                    ['label' => $name, 'data' => array_map(fn($id) => $round($mine['categories'][$id]), $catIds), 'color' => 'blue'],
                    ['label' => 'Department average', 'data' => array_map(fn($id) => $round($dept['categories'][$id] ?? null), $catIds), 'color' => 'green'],
                    ['label' => 'Company average', 'data' => array_map(fn($id) => $round($company['categories'][$id] ?? null), $catIds), 'color' => 'yellow'],
                ],
            ], 'Radar chart of the employee category scores compared with department and company averages', 'tall');
            ?>
        </section>
    </div>

    <section class="card">
        <h2>Comments</h2>
        <?php $comments = received_comments($periodId, $empId, $showNames); ?>
        <?php if ($comments): ?>
            <p class="muted small"><?= $showNames ? 'Evaluator names are shown because of the current Settings.' : 'Comments are anonymous and listed in random order.' ?></p>
            <ul class="comment-list">
                <?php foreach ($comments as $c): ?>
                    <li><?= e($c['comment']) ?><?php if ($showNames): ?><span class="comment-author">From <?= e($c['evaluator_name']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">No comments in this period.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Trend across periods</h2>
    <?php if ($trendRows): ?>
        <?php if (count($trendRows) > 1): ?>
            <?= chart_canvas('chart-employee-trend', [
                'type' => 'line',
                'labels' => array_map(fn($r) => $r['period']['title'], $trendRows),
                'datasets' => [
                    ['label' => $name, 'data' => array_map(fn($r) => $r['overall'] !== null ? round($r['overall'], 2) : null, $trendRows), 'color' => 'blue'],
                    ['label' => 'Department average', 'data' => array_map(fn($r) => $r['dept_avg'] !== null ? round($r['dept_avg'], 2) : null, $trendRows), 'color' => 'green'],
                ],
            ], 'Line chart of the employee score and department average per period', 'short') ?>
        <?php endif; ?>
        <div class="table-wrap mt-1">
            <table>
                <thead>
                <tr>
                    <th scope="col">Period</th>
                    <th scope="col">Department at the time</th>
                    <th scope="col" class="num">Evaluations</th>
                    <th scope="col" class="num">Score</th>
                    <th scope="col" class="num">Department average</th>
                    <th scope="col" class="num">Change</th>
                </tr>
                </thead>
                <tbody>
                <?php $previous = null; ?>
                <?php foreach ($trendRows as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('ceo/employee.php?id=' . $empId . '&period=' . (int) $r['period']['id'])) ?>"><?= e($r['period']['title']) ?></a></td>
                        <td><?= e($r['department']) ?></td>
                        <td class="num"><?= (int) $r['evaluations'] ?></td>
                        <td class="num"><?= $r['overall'] !== null ? score_badge($r['overall']) : '<span class="muted small">Not enough</span>' ?></td>
                        <td class="num"><?= format_score($r['dept_avg']) ?></td>
                        <td class="num"><?= diff_text($r['overall'], $previous) ?></td>
                    </tr>
                    <?php if ($r['overall'] !== null) { $previous = $r['overall']; } ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty-state">No evaluations received in any period yet.</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
