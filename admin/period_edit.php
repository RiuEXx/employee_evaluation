<?php
/**
 * Create or edit an evaluation period (?id= to edit). New periods start as
 * drafts; they are opened and closed from the Evaluation Periods list.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('periods.manage');

$id = is_post() ? post_int('id') : get_int('id');
$period = $id ? find_period($id) : null;
if ($id && !$period) {
    render_error_page(404, 'That period does not exist.');
}

$form = [
    'title'      => $period['title'] ?? '',
    'start_date' => $period['start_date'] ?? date('Y-m-d'),
    'end_date'   => $period['end_date'] ?? date('Y-m-d', strtotime('+14 days')),
];
$errors = [];

if (is_post()) {
    foreach (array_keys($form) as $field) {
        $form[$field] = post_str($field);
    }

    if ($form['title'] === '' || mb_strlen($form['title']) > 100) {
        $errors['title'] = 'Enter a title of at most 100 characters, for example "Q1 2027 Performance Review".';
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM evaluation_periods WHERE title = ? AND id <> ?');
        $stmt->execute([$form['title'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors['title'] = 'Another period already has this title.';
        }
    }
    if (!is_valid_date($form['start_date'])) {
        $errors['start_date'] = 'Enter a valid start date.';
    }
    if (!is_valid_date($form['end_date'])) {
        $errors['end_date'] = 'Enter a valid end date.';
    } elseif (!isset($errors['start_date']) && $form['end_date'] < $form['start_date']) {
        $errors['end_date'] = 'The end date cannot be before the start date.';
    }

    if (!$errors) {
        if ($period) {
            db()->prepare('UPDATE evaluation_periods SET title = ?, start_date = ?, end_date = ? WHERE id = ?')
                ->execute([$form['title'], $form['start_date'], $form['end_date'], $id]);
            audit_log('period_update', 'Updated ' . $form['title'] . ' (' . $form['start_date'] . ' to ' . $form['end_date'] . ').');
            flash('success', '"' . $form['title'] . '" has been updated.');
        } else {
            db()->prepare("INSERT INTO evaluation_periods (title, start_date, end_date, status, created_by) VALUES (?, ?, ?, 'draft', ?)")
                ->execute([$form['title'], $form['start_date'], $form['end_date'], $user['id']]);
            audit_log('period_create', 'Created ' . $form['title'] . ' (' . $form['start_date'] . ' to ' . $form['end_date'] . ').');
            flash('success', '"' . $form['title'] . '" has been created as a draft. Open it from the list when evaluations should start.');
        }
        redirect('admin/periods.php');
    }
}

$pageTitle = $period ? 'Edit Evaluation Period' : 'New Evaluation Period';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Evaluation Periods', url('admin/periods.php')], [$pageTitle, null]];
$navActive = 'admin/periods.php';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
<?php endif; ?>

<section class="card narrow-card">
    <?php if ($period): ?>
        <p>Status: <?= status_badge($period['status']) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/period_edit.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="field<?= isset($errors['title']) ? ' has-error' : '' ?>">
            <label for="title">Title <span class="required-mark">*</span></label>
            <input type="text" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="100" required>
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= e($errors['title']) ?></p><?php endif; ?>
        </div>

        <div class="form-grid">
            <div class="field<?= isset($errors['start_date']) ? ' has-error' : '' ?>">
                <label for="start_date">Start date <span class="required-mark">*</span></label>
                <input type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>" required>
                <?php if (isset($errors['start_date'])): ?><p class="field-error"><?= e($errors['start_date']) ?></p><?php endif; ?>
            </div>
            <div class="field<?= isset($errors['end_date']) ? ' has-error' : '' ?>">
                <label for="end_date">End date <span class="required-mark">*</span></label>
                <input type="date" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>" required>
                <?php if (isset($errors['end_date'])): ?><p class="field-error"><?= e($errors['end_date']) ?></p><?php endif; ?>
            </div>
        </div>
        <p class="field-help">
            The dates tell employees when the round runs. Evaluations are accepted while the period is open,
            so remember to close it at the end date.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $period ? 'Save changes' : 'Create period' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/periods.php')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
