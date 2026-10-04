<?php
/**
 * Department results for one period: average, completion, and the highest
 * and lowest scoring categories of every department.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('results.view');

$periods = result_periods();
$period = selected_period($periods);
$categories = categories_map();
$headcounts = department_headcounts();

$rows = [];
if ($period) {
    $periodId = (int) $period['id'];
    $byDept = score_rollup(['period_id' => $periodId], ['department_id']);
    $completionByDept = completion_by_department(completion_rows($periodId));

    foreach (departments_list() as $d) {
        $id = (int) $d['id'];
        $entry = rollup_entry($byDept, (string) $id);
        $cats = $entry['categories'];
        $best = $cats ? array_keys($cats, max($cats))[0] : null;
        $worst = $cats ? array_keys($cats, min($cats))[0] : null;
        $rows[] = [
            'id'          => $id,
            'name'        => $d['name'],
            'is_active'   => (int) $d['is_active'],
            'employees'   => $headcounts[$id] ?? 0,
            'evaluations' => $entry['evaluations'],
            'completion'  => $completionByDept[$id]['rate'] ?? null,
            'overall'     => $entry['overall'],
            'best'        => $best !== null ? ($categories[$best]['name'] ?? '') . ' (' . format_score($cats[$best]) . ')' : null,
            'worst'       => $worst !== null ? ($categories[$worst]['name'] ?? '') . ' (' . format_score($cats[$worst]) . ')' : null,
        ];
    }
}

$q = get_str('q');
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) => mb_stripos($r['name'], $q) !== false));
}
$sort = sort_state([
    'name' => 'name', 'employees' => 'employees', 'evaluations' => 'evaluations',
    'completion' => 'completion', 'average' => 'overall',
], 'average', 'desc');
$rows = sort_rows($rows, $sort['column'], $sort['dir']);
$pages = pagination(count($rows));
$pageRows = page_slice($rows, $pages);

$pageTitle = 'Department Results';
$breadcrumbs = [['Results', url('ceo/index.php')], ['Departments', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if (!$period): ?>
    <div class="alert alert-info">No evaluation period has been opened yet, so there are no results to show.</div>
<?php else: ?>
    <form method="get" class="filters no-print" role="search">
        <?= period_select_field($periods, $period) ?>
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Department name">
        </div>
        <button type="submit" class="btn btn-secondary">Apply</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <?= sort_th('Department', 'name', $sort) ?>
                <?= sort_th('Active employees', 'employees', $sort, 'num') ?>
                <?= sort_th('Evaluations', 'evaluations', $sort, 'num') ?>
                <?= sort_th('Completion', 'completion', $sort) ?>
                <?= sort_th('Average', 'average', $sort, 'num') ?>
                <th scope="col">Highest category</th>
                <th scope="col">Lowest category</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pageRows as $row): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('ceo/department.php?id=' . $row['id'] . '&period=' . (int) $period['id'])) ?>"><?= e($row['name']) ?></a>
                        <?php if (!$row['is_active']): ?> <?= status_badge('inactive') ?><?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $row['employees'] ?></td>
                    <td class="num"><?= (int) $row['evaluations'] ?></td>
                    <td><?= $row['completion'] !== null ? e(format_percent($row['completion'])) : '<span class="muted">-</span>' ?></td>
                    <td class="num"><?= score_badge($row['overall']) ?></td>
                    <td><?= e($row['best'] ?? '-') ?></td>
                    <td><?= e($row['worst'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?>
                <tr class="empty-row"><td colspan="7">No departments match your search.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pages) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
