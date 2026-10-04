<?php
/**
 * My Summary: the aggregated results an employee received.
 *
 * What is shown depends on Settings:
 *   show_results_to_employees            the page is available at all
 *   results_only_after_close             only closed periods (default) or also the open one
 *   show_individual_scores_to_employees  each anonymous evaluation, not only averages
 *   show_comments_to_employees           anonymous comments
 * Nothing is shown below the minimum number of evaluations, and evaluator
 * names are never shown here.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('summary.view_own');

$enabled = setting_bool('show_results_to_employees');
$afterClose = setting_bool('results_only_after_close');
$periods = array_values(array_filter(result_periods(), fn($p) => $p['status'] === 'closed' || !$afterClose));
$categories = categories_map();
$myId = (int) $user['id'];

// Without ?period=, start at the newest period in which this employee received evaluations.
$period = null;
if ($enabled && get_int('period') === 0) {
    $received = score_rollup(['evaluatee_id' => $myId], ['period_id']);
    foreach ($periods as $p) {
        if (isset($received[(string) $p['id']])) {
            $period = $p;
            break;
        }
    }
}
if ($enabled && !$period) {
    $period = selected_period($periods);
}

$pageTitle = 'My Summary';
$useCharts = true;
require __DIR__ . '/../includes/header.php';

if (!$enabled): ?>
    <div class="alert alert-info">Evaluation results are not shared with employees at this time.</div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

if (!$period): ?>
    <div class="alert alert-info">
        There are no results to show yet. Your results appear here after an evaluation period
        <?= $afterClose ? 'is closed' : 'has started' ?>.
    </div>
    <?php require __DIR__ . '/../includes/footer.php';
    exit;
endif;

$periodId = (int) $period['id'];
$mine = rollup_entry(score_rollup(['period_id' => $periodId, 'evaluatee_id' => $myId]));
$count = $mine['evaluations'];
$enough = enough_evaluations($count);

$deptId = evaluation_department($periodId, $myId) ?? (int) $user['department_id'];
$dept = $deptId ? rollup_entry(score_rollup(['period_id' => $periodId, 'department_id' => $deptId])) : rollup_entry([]);
$deptRow = $deptId ? find_department($deptId) : null;

// Trend over the periods this page may show; hidden where below the minimum.
$visibleIds = array_map(fn($p) => (int) $p['id'], $periods);
$trend = array_values(array_filter(score_trend(['evaluatee_id' => $myId]),
    fn($t) => in_array((int) $t['period']['id'], $visibleIds, true) && $t['evaluations'] > 0));
?>

<form method="get" class="filters no-print">
    <div class="field field-wide">
        <label for="period">Evaluation period</label>
        <select id="period" name="period" data-autosubmit>
            <?= select_options(period_options($periods), $periodId) ?>
        </select>
    </div>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
</form>

<?php if ($period['status'] === 'open'): ?>
    <div class="alert alert-warning">This period is still open, so these results can still change.</div>
<?php endif; ?>

<?php if ($count === 0): ?>
    <div class="alert alert-info">You did not receive any evaluations in <?= e($period['title']) ?>.</div>
<?php elseif (!$enough): ?>
    <div class="alert alert-info">
        <?= e(not_enough_text($count)) ?>. To protect the anonymity of your colleagues, results are only shown
        once at least <?= min_evaluations() ?> people have evaluated you.
    </div>
<?php else: ?>

    <div class="stats">
        <div class="stat">
            <p class="stat-label">Your overall score</p>
            <p class="stat-value"><?= format_score($mine['overall']) ?></p>
            <p class="stat-note"><?= e(score_label($mine['overall'])) ?>, out of 5</p>
        </div>
        <div class="stat accent">
            <p class="stat-label">Evaluations received</p>
            <p class="stat-value"><?= (int) $count ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Department average</p>
            <p class="stat-value"><?= format_score($dept['overall']) ?></p>
            <p class="stat-note"><?= e($deptRow['name'] ?? '') ?></p>
        </div>
    </div>

    <div class="grid-2 section-gap">
        <section class="card">
            <h2>Scores by category</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th scope="col">Category</th><th scope="col" class="num">You</th><th scope="col" class="num">Department</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mine['categories'] as $catId => $avg): ?>
                        <tr>
                            <td><?= e($categories[$catId]['name'] ?? 'Category') ?></td>
                            <td class="num"><?= score_badge($avg) ?></td>
                            <td class="num"><?= format_score($dept['categories'][$catId] ?? null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small mb-0">Scores are averages on the 1 to 5 scale. The overall score is weighted by category.</p>
        </section>

        <section class="card">
            <h2>You compared with your department</h2>
            <?php
            $labels = [];
            $you = [];
            $deptData = [];
            foreach ($mine['categories'] as $catId => $avg) {
                $labels[] = $categories[$catId]['name'] ?? 'Category';
                $you[] = round($avg, 2);
                $deptData[] = isset($dept['categories'][$catId]) ? round($dept['categories'][$catId], 2) : null;
            }
            echo chart_canvas('chart-me-vs-dept', [
                'type' => 'horizontalBar',
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'You', 'data' => $you, 'color' => 'blue'],
                    ['label' => 'Department average', 'data' => $deptData, 'color' => 'green'],
                ],
            ], 'Bar chart of your category scores compared with the department average', 'tall');
            ?>
        </section>
    </div>

    <?php if (setting_bool('show_individual_scores_to_employees')): ?>
        <?php $individual = individual_scores($periodId, $myId); ?>
        <section class="card">
            <h2>Individual ratings</h2>
            <p class="muted">Each column is one anonymous evaluation, in random order.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <?php foreach ($individual as $i => $_): ?>
                            <th scope="col" class="num">Evaluation <?= e(chr(65 + ($i % 26))) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mine['categories'] as $catId => $_): ?>
                        <tr>
                            <td><?= e($categories[$catId]['name'] ?? 'Category') ?></td>
                            <?php foreach ($individual as $scores): ?>
                                <td class="num"><?= isset($scores[$catId]) ? format_score($scores[$catId]) : '<span class="muted">N/A</span>' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if (setting_bool('show_comments_to_employees')): ?>
        <?php $comments = received_comments($periodId, $myId); ?>
        <section class="card">
            <h2>Comments from colleagues</h2>
            <?php if ($comments): ?>
                <ul class="comment-list">
                    <?php foreach ($comments as $c): ?>
                        <li><?= e($c['comment']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">No comments were written for you in this period.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

<?php if (count($trend) > 1): ?>
    <section class="card">
        <h2>Your trend over time</h2>
        <?php
        $trendLabels = [];
        $trendData = [];
        foreach ($trend as $t) {
            $trendLabels[] = $t['period']['title'];
            $trendData[] = enough_evaluations($t['evaluations']) && $t['overall'] !== null ? round($t['overall'], 2) : null;
        }
        echo chart_canvas('chart-my-trend', [
            'type' => 'line',
            'labels' => $trendLabels,
            'datasets' => [['label' => 'Your overall score', 'data' => $trendData, 'color' => 'blue']],
        ], 'Line chart of your overall score per period', 'short');
        ?>
        <p class="muted small mb-0">Periods with fewer than <?= min_evaluations() ?> evaluations are left out of the line.</p>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
