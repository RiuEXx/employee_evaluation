<?php
/**
 * Add or edit a rating category (?id= to edit).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('categories.manage');

$id = is_post() ? post_int('id') : get_int('id');
$category = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM evaluation_categories WHERE id = ?');
    $stmt->execute([$id]);
    $category = $stmt->fetch() ?: null;
    if (!$category) {
        render_error_page(404, 'That category does not exist.');
    }
}

$nextOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM evaluation_categories')->fetchColumn();
$form = [
    'name'        => $category['name'] ?? '',
    'description' => $category['description'] ?? '',
    'weight'      => $category['weight'] ?? '1.00',
    'sort_order'  => (string) ($category['sort_order'] ?? $nextOrder),
    'allow_na'    => (int) ($category['allow_na'] ?? 0),
    'is_active'   => (int) ($category['is_active'] ?? 1),
];
$errors = [];

if (is_post()) {
    $form['name'] = post_str('name');
    $form['description'] = post_str('description');
    $form['weight'] = post_str('weight');
    $form['sort_order'] = post_str('sort_order');
    $form['allow_na'] = isset($_POST['allow_na']) ? 1 : 0;
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
        $errors['name'] = 'Enter a name of at most 100 characters.';
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM evaluation_categories WHERE name = ? AND id <> ?');
        $stmt->execute([$form['name'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors['name'] = 'Another category already has this name.';
        }
    }
    if (mb_strlen($form['description']) > 255) {
        $errors['description'] = 'At most 255 characters.';
    }
    if (!is_numeric($form['weight']) || (float) $form['weight'] <= 0 || (float) $form['weight'] > 100) {
        $errors['weight'] = 'Enter a number greater than 0 and at most 100, for example 1 or 1.5.';
    }
    if (!preg_match('/^-?\d{1,6}$/', $form['sort_order'])) {
        $errors['sort_order'] = 'Enter a whole number.';
    }

    if (!$errors) {
        $values = [
            $form['name'], $form['description'] !== '' ? $form['description'] : null,
            round((float) $form['weight'], 2), $form['allow_na'], $form['is_active'], (int) $form['sort_order'],
        ];
        if ($category) {
            db()->prepare('UPDATE evaluation_categories SET name = ?, description = ?, weight = ?, allow_na = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute(array_merge($values, [$id]));
            audit_log('category_update', 'Category: ' . $form['name'] . '.');
            flash('success', 'Category "' . $form['name'] . '" has been updated.');
        } else {
            db()->prepare('INSERT INTO evaluation_categories (name, description, weight, allow_na, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute($values);
            audit_log('category_create', 'Category: ' . $form['name'] . '.');
            flash('success', 'Category "' . $form['name'] . '" has been added.');
        }
        redirect('admin/categories.php');
    }
}

$pageTitle = $category ? 'Edit Category' : 'Add Category';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Categories', url('admin/categories.php')], [$pageTitle, null]];
$navActive = 'admin/categories.php';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
<?php endif; ?>

<?php if ($category && get_open_period()): ?>
    <div class="alert alert-warning">
        An evaluation period is open. Changes apply to evaluations submitted from now on; evaluations already
        submitted keep their scores.
    </div>
<?php endif; ?>

<section class="card narrow-card">
    <form method="post" action="<?= e(url('admin/category_edit.php')) ?>" novalidate>
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
            <p class="field-help">Shown to evaluators under the category name.</p>
            <?php if (isset($errors['description'])): ?><p class="field-error"><?= e($errors['description']) ?></p><?php endif; ?>
        </div>

        <div class="form-grid">
            <div class="field<?= isset($errors['weight']) ? ' has-error' : '' ?>">
                <label for="weight">Weight</label>
                <input type="number" id="weight" name="weight" value="<?= e($form['weight']) ?>" min="0.01" max="100" step="0.01" required>
                <p class="field-help">1 = normal. 1.5 counts one and a half times as much.</p>
                <?php if (isset($errors['weight'])): ?><p class="field-error"><?= e($errors['weight']) ?></p><?php endif; ?>
            </div>
            <div class="field<?= isset($errors['sort_order']) ? ' has-error' : '' ?>">
                <label for="sort_order">Order on the form</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= e($form['sort_order']) ?>" step="1" required>
                <p class="field-help">Lower numbers come first.</p>
                <?php if (isset($errors['sort_order'])): ?><p class="field-error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="allow_na" value="1" <?= $form['allow_na'] ? 'checked' : '' ?>>
                <span>Allow "Not applicable" (for example Leadership, which does not apply to everyone)</span>
            </label>
        </div>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>>
                <span>Active (shown on the evaluation form)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $category ? 'Save changes' : 'Add category' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/categories.php')) ?>">Cancel</a>
            <?php if ($category): ?>
                <a class="btn btn-secondary" href="<?= e(url('admin/criteria.php?category_id=' . (int) $id)) ?>">Manage criteria</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
