<?php
/**
 * Add or edit a department (?id= to edit).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('departments.manage');

$id = is_post() ? post_int('id') : get_int('id');
$department = $id ? find_department($id) : null;
if ($id && !$department) {
    render_error_page(404, 'That department does not exist.');
}

$form = [
    'name'        => $department['name'] ?? '',
    'description' => $department['description'] ?? '',
    'is_active'   => (int) ($department['is_active'] ?? 1),
];
$errors = [];

if (is_post()) {
    $form['name'] = post_str('name');
    $form['description'] = post_str('description');
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($form['name'] === '') {
        $errors['name'] = 'Enter the department name.';
    } elseif (mb_strlen($form['name']) > 100) {
        $errors['name'] = 'The name can be at most 100 characters.';
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM departments WHERE name = ? AND id <> ?');
        $stmt->execute([$form['name'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors['name'] = 'Another department already has this name.';
        }
    }
    if (mb_strlen($form['description']) > 255) {
        $errors['description'] = 'The description can be at most 255 characters.';
    }
    reject_emoji($form, $errors);

    if (!$errors) {
        $description = $form['description'] !== '' ? $form['description'] : null;
        if ($department) {
            db()->prepare('UPDATE departments SET name = ?, description = ?, is_active = ? WHERE id = ?')
                ->execute([$form['name'], $description, $form['is_active'], $id]);
            audit_log('department_update', 'Department: ' . $form['name'] . '.');
            flash('success', 'Department "' . $form['name'] . '" has been updated.');
        } else {
            db()->prepare('INSERT INTO departments (name, description, is_active) VALUES (?, ?, ?)')
                ->execute([$form['name'], $description, $form['is_active']]);
            audit_log('department_create', 'Department: ' . $form['name'] . '.');
            flash('success', 'Department "' . $form['name'] . '" has been added.');
        }
        redirect('admin/departments.php');
    }
}

$pageTitle = $department ? 'Edit Department' : 'Add Department';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Departments', url('admin/departments.php')], [$pageTitle, null]];
$navActive = 'admin/departments.php';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
<?php endif; ?>

<section class="card narrow-card">
    <form method="post" action="<?= e(url('admin/department_edit.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Name <span class="required-mark">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="100" required>
            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['description']) ? ' has-error' : '' ?>">
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="255"><?= e($form['description']) ?></textarea>
            <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>>
                <span>Active (members take part in evaluations)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $department ? 'Save changes' : 'Add department' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/departments.php')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
