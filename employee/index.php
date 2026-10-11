<?php
/**
 * Employee dashboard: the open evaluation period, every department mate to
 * evaluate with their status (Pending / Completed), and overall progress.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('evaluations.submit');

$period = get_open_period();
$problem = evaluator_problem($user);
$allowEdit = setting_bool('allow_edit_until_close');

$peers = $period ? department_peers($user, (int) $period['id']) : [];
$total = count($peers);
$done = count(array_filter($peers, fn($p) => $p['evaluation_id'] !== null));

// Rows for the table, with keys used for searching and sorting.
$rows = [];
foreach ($peers as $peer) {
    $completed = $peer['evaluation_id'] !== null;
    $rows[] = $peer + [
        'name'      => full_name($peer),
        'sort_name' => $peer['last_name'] . ' ' . $peer['first_name'],
        'status'    => $completed ? 'Completed' : 'Pending',
        // Pending colleagues are listed first by default.
        'status_order' => $completed ? 1 : 0,
    ];
}
$nextPending = null;
foreach ($rows as $row) {
    if ($row['evaluation_id'] === null) {
        $nextPending = $row;
        break;
    }
}

// Employee numbers are shown (and searchable) only for people who manage employees.
$showNumbers = can_see_employee_numbers();

$q = search_query();
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_stripos($r['name'] . ' ' . $r['position'] . ($showNumbers ? ' ' . $r['employee_number'] : ''), $q) !== false));
}
$sort = sort_state(['status' => 'status_order', 'name' => 'sort_name', 'position' => 'position', 'submitted' => 'submitted_at'], 'status');
$rows = sort_rows($rows, $sort['column'], $sort['dir']);
$pages = pagination(count($rows));
$rows = page_slice($rows, $pages);

$pageTitle = 'My Evaluations';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($problem !== ''): ?>
    <div class="alert alert-info"><?= e($problem) ?></div>
<?php elseif (!$period): ?>
    <div class="alert alert-info">
        No evaluation period is open right now. You can evaluate your department mates when HR opens the next period.
    </div>
<?php else: ?>
    <?php
    $daysLeft = (int) floor((strtotime($period['end_date']) - strtotime(date('Y-m-d'))) / 86400);
    ?>
    <section class="card">
        <div class="card-header">
            <div>
                <h2><?= e($period['title']) ?></h2>
                <p class="muted m-0">
                    <?= e(format_date($period['start_date'])) ?> to <?= e(format_date($period['end_date'])) ?>
                    &middot; <?= e($user['department_name']) ?>
                </p>
            </div>
            <?= status_badge('open') ?>
        </div>

        <?php if ($daysLeft > 0): ?>
            <p>The period ends in <strong><?= e(plural($daysLeft, 'day')) ?></strong>. Please evaluate each colleague below.</p>
        <?php elseif ($daysLeft === 0): ?>
            <p><strong>Today is the last day</strong> of this evaluation period.</p>
        <?php else: ?>
            <p>The planned end date has passed. Submit any remaining evaluations before HR closes the period.</p>
        <?php endif; ?>

        <div class="progress-large">
            <?= progress_bar($done, $total, $done . ' of ' . $total . ' colleagues evaluated (' . ($total ? round($done / $total * 100) : 0) . '%)') ?>
        </div>

        <?php if ($total === 0): ?>
            <p class="muted mt-1">There is no one else in your department to evaluate.</p>
        <?php elseif ($done === $total): ?>
            <div class="alert alert-success mt-1 mb-0">
                You have evaluated all your department mates for this period. Thank you.
            </div>
        <?php elseif ($nextPending): ?>
            <p class="mt-1 mb-0">
                <a class="btn btn-primary" href="<?= e(url('employee/evaluate.php?id=' . (int) $nextPending['id'])) ?>">
                    Evaluate next: <?= e($nextPending['name']) ?>
                </a>
            </p>
        <?php endif; ?>
    </section>

    <?php if ($total > 0): ?>
        <section class="card">
            <div class="card-header">
                <h2>Department mates</h2>
            </div>

            <form method="get" class="filters" role="search">
                <div class="field field-wide">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="Name or position">
                </div>
                <button type="submit" class="btn btn-secondary">Search</button>
                <?php if ($q !== ''): ?><a class="btn btn-secondary" href="<?= e(url('employee/index.php')) ?>">Clear</a><?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <?= sort_th('Colleague', 'name', $sort) ?>
                        <?= sort_th('Position', 'position', $sort) ?>
                        <?= sort_th('Status', 'status', $sort) ?>
                        <?= sort_th('Submitted', 'submitted', $sort) ?>
                        <th scope="col">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?><?php if ($showNumbers): ?><br><span class="muted small"><?= e($row['employee_number']) ?></span><?php endif; ?></td>
                            <td><?= e($row['position'] ?: '-') ?></td>
                            <td><?= status_badge($row['status']) ?></td>
                            <td class="nowrap"><?= e($row['submitted_at'] ? format_datetime($row['submitted_at']) : '-') ?></td>
                            <td class="actions-cell">
                                <?php $link = url('employee/evaluate.php?id=' . (int) $row['id']); ?>
                                <?php if ($row['evaluation_id'] === null): ?>
                                    <a class="btn btn-primary btn-small" href="<?= e($link) ?>">Evaluate</a>
                                <?php elseif ($allowEdit): ?>
                                    <a class="btn btn-secondary btn-small" href="<?= e($link) ?>">View or edit</a>
                                <?php else: ?>
                                    <a class="btn btn-secondary btn-small" href="<?= e($link) ?>">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr class="empty-row"><td colspan="5">No colleagues match your search.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($pages) ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if (can('summary.view_own') && setting_bool('show_results_to_employees')): ?>
    <p><a href="<?= e(url('employee/summary.php')) ?>">See the results you received in earlier periods</a></p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
