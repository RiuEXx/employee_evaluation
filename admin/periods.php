<?php
/**
 * Evaluation periods: list with search, filter, sorting and paging, plus the
 * Open, Close and Delete actions. Only one period can be open at a time
 * (also enforced by a UNIQUE key in the database). A period that has
 * evaluations cannot be deleted.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('periods.manage');

if (is_post()) {
    $period = find_period(post_int('id'));
    $action = post_str('action');

    if (!$period) {
        flash('error', 'That period no longer exists.');
    } elseif ($action === 'open') {
        $open = get_open_period();
        if ($open && (int) $open['id'] !== (int) $period['id']) {
            flash('error', 'Only one period can be open at a time. Close "' . $open['title'] . '" first.');
        } elseif ($period['status'] !== 'open') {
            try {
                db()->prepare("UPDATE evaluation_periods SET status = 'open' WHERE id = ?")->execute([$period['id']]);
                audit_log('period_open', 'Opened ' . $period['title'] . '.');
                flash('success', '"' . $period['title'] . '" is now open. Employees can submit their evaluations.');
            } catch (PDOException $ex) {
                if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) {
                    throw $ex;
                }
                flash('error', 'Another period was opened at the same moment. Only one period can be open at a time.');
            }
        }
    } elseif ($action === 'close' && $period['status'] === 'open') {
        db()->prepare("UPDATE evaluation_periods SET status = 'closed' WHERE id = ?")->execute([$period['id']]);
        audit_log('period_close', 'Closed ' . $period['title'] . '.');
        flash('success', '"' . $period['title'] . '" is closed. No more evaluations can be submitted, and employees can now see their results (if Settings allow it).');
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM evaluations WHERE period_id = ?');
        $stmt->execute([$period['id']]);
        if ($stmt->fetchColumn() > 0) {
            flash('error', '"' . $period['title'] . '" has evaluations and cannot be deleted.');
        } else {
            db()->prepare('DELETE FROM evaluation_periods WHERE id = ?')->execute([$period['id']]);
            audit_log('period_delete', 'Deleted ' . $period['title'] . '.');
            flash('success', '"' . $period['title'] . '" has been deleted.');
        }
    }
    redirect(current_request_uri());
}

$q = get_str('q');
$status = get_str('status');
$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = 'p.title LIKE ?';
    $params[] = like_pattern($q);
}
if (in_array($status, ['draft', 'open', 'closed'], true)) {
    $conditions[] = 'p.status = ?';
    $params[] = $status;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM evaluation_periods p $whereSql");
$stmt->execute($params);
$pages = pagination((int) $stmt->fetchColumn());

$sort = sort_state(['title' => 'p.title', 'start' => 'p.start_date', 'status' => 'p.status', 'evaluations' => 'evaluations'], 'start', 'desc');
$stmt = db()->prepare(
    "SELECT p.*, e.first_name, e.last_name,
            (SELECT COUNT(*) FROM evaluations v WHERE v.period_id = p.id) AS evaluations
     FROM evaluation_periods p
     LEFT JOIN employees e ON e.id = p.created_by
     $whereSql
     ORDER BY {$sort['sql']}, p.id DESC
     LIMIT {$pages['per_page']} OFFSET {$pages['offset']}"
);
$stmt->execute($params);
$periods = $stmt->fetchAll();
$openPeriod = get_open_period();

$pageTitle = 'Evaluation Periods';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Evaluation Periods', null]];
require __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <p class="mb-0">
        A period starts as a <strong>draft</strong>. <strong>Open</strong> it to let employees evaluate their department
        mates, and <strong>close</strong> it when the evaluation round is over. Only one period can be open at a time.
        <?= $openPeriod ? 'Currently open: <strong>' . e($openPeriod['title']) . '</strong>.' : 'No period is open right now.' ?>
    </p>
</section>

<div class="filters">
    <form method="get" class="filters m-0" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Title">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <?= select_options(['' => 'All', 'draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'], $status) ?>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">Apply</button>
    </form>
    <a class="btn btn-primary" href="<?= e(url('admin/period_edit.php')) ?>">New period</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?= sort_th('Title', 'title', $sort) ?>
            <?= sort_th('Dates', 'start', $sort) ?>
            <?= sort_th('Status', 'status', $sort) ?>
            <?= sort_th('Evaluations', 'evaluations', $sort, 'num') ?>
            <th scope="col">Created by</th>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($periods as $p): ?>
            <tr>
                <td><?= e($p['title']) ?></td>
                <td class="nowrap"><?= e(format_date($p['start_date'])) ?> to <?= e(format_date($p['end_date'])) ?></td>
                <td><?= status_badge($p['status']) ?></td>
                <td class="num"><?= (int) $p['evaluations'] ?></td>
                <td><?= e($p['first_name'] ? full_name($p) : '-') ?></td>
                <td class="actions-cell">
                    <div class="actions">
                        <a class="btn btn-secondary btn-small" href="<?= e(url('admin/period_edit.php?id=' . (int) $p['id'])) ?>">Edit</a>
                        <?php if ($p['status'] === 'open'): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e('Close ' . $p['title'] . '? Employees will no longer be able to submit evaluations.') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" name="action" value="close" class="btn btn-danger btn-small">Close</button>
                            </form>
                        <?php elseif (!$openPeriod): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e(($p['status'] === 'closed' ? 'Reopen ' : 'Open ') . $p['title'] . '? Employees will be able to submit evaluations.') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" name="action" value="open" class="btn btn-accent btn-small"><?= $p['status'] === 'closed' ? 'Reopen' : 'Open' ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) $p['evaluations'] === 0 && $p['status'] !== 'open'): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e('Delete ' . $p['title'] . '?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" name="action" value="delete" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$periods): ?>
            <tr class="empty-row"><td colspan="6">No periods found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
