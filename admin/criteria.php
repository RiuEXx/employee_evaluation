<?php
/**
 * Criteria of one category (?category_id=). Criteria are optional rating
 * items: when a category has active criteria, evaluators rate each one and
 * the category score is their average; without criteria the category is
 * rated as a whole. Add or edit with the form, ?edit=<id> loads a criterion.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('categories.manage');

$categoryId = is_post() ? post_int('category_id') : get_int('category_id');
$stmt = db()->prepare('SELECT * FROM evaluation_categories WHERE id = ?');
$stmt->execute([$categoryId]);
$category = $stmt->fetch();
if (!$category) {
    render_error_page(404, 'That category does not exist.');
}
$selfUrl = 'admin/criteria.php?category_id=' . $categoryId;

/** One criterion of this category, or null. */
function find_criterion(int $id, int $categoryId): ?array
{
    $stmt = db()->prepare(
        'SELECT k.*, (SELECT COUNT(*) FROM evaluation_scores s WHERE s.criteria_id = k.id) AS scores
         FROM evaluation_criteria k WHERE k.id = ? AND k.category_id = ?'
    );
    $stmt->execute([$id, $categoryId]);
    return $stmt->fetch() ?: null;
}

$editing = get_int('edit') ? find_criterion(get_int('edit'), $categoryId) : null;
$stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM evaluation_criteria WHERE category_id = ?');
$stmt->execute([$categoryId]);
$nextOrder = (int) $stmt->fetchColumn();
$form = [
    'id'            => (int) ($editing['id'] ?? 0),
    'question_text' => $editing['question_text'] ?? '',
    'sort_order'    => (string) ($editing['sort_order'] ?? $nextOrder),
];
$errors = [];

if (is_post()) {
    $action = post_str('action');
    $criterion = post_int('id') ? find_criterion(post_int('id'), $categoryId) : null;

    if ($action === 'toggle' && $criterion) {
        $active = (int) $criterion['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE evaluation_criteria SET is_active = ? WHERE id = ?')->execute([$active, $criterion['id']]);
        audit_log('criteria_update', ($active ? 'Activated' : 'Deactivated') . ' a criterion of ' . $category['name'] . '.');
        flash('success', 'The criterion has been ' . ($active ? 'activated.' : 'deactivated. Its past scores are kept.'));
        redirect($selfUrl);
    }
    if ($action === 'delete' && $criterion) {
        if ((int) $criterion['scores'] > 0) {
            flash('error', 'This criterion already has scores and cannot be deleted. Deactivate it instead.');
        } else {
            db()->prepare('DELETE FROM evaluation_criteria WHERE id = ?')->execute([$criterion['id']]);
            audit_log('criteria_delete', 'Deleted a criterion of ' . $category['name'] . ': ' . $criterion['question_text']);
            flash('success', 'The criterion has been deleted.');
        }
        redirect($selfUrl);
    }
    if ($action === 'save') {
        $form = ['id' => $criterion ? (int) $criterion['id'] : 0, 'question_text' => post_str('question_text'), 'sort_order' => post_str('sort_order')];
        if ($form['question_text'] === '' || mb_strlen($form['question_text']) > 255) {
            $errors['question_text'] = 'Enter the criterion text (at most 255 characters).';
        }
        if (!preg_match('/^\d{1,6}$/', $form['sort_order'])) {
            $errors['sort_order'] = 'Enter a whole number from 0 to 999999.';
        }
        reject_emoji($form, $errors);
        if (!$errors) {
            if ($criterion) {
                db()->prepare('UPDATE evaluation_criteria SET question_text = ?, sort_order = ? WHERE id = ?')
                    ->execute([$form['question_text'], (int) $form['sort_order'], $criterion['id']]);
                audit_log('criteria_update', 'Updated a criterion of ' . $category['name'] . ': ' . $form['question_text']);
                flash('success', 'The criterion has been updated.');
            } else {
                db()->prepare('INSERT INTO evaluation_criteria (category_id, question_text, sort_order) VALUES (?, ?, ?)')
                    ->execute([$categoryId, $form['question_text'], (int) $form['sort_order']]);
                audit_log('criteria_create', 'Added a criterion to ' . $category['name'] . ': ' . $form['question_text']);
                flash('success', 'The criterion has been added.');
            }
            redirect($selfUrl);
        }
    }
}

$stmt = db()->prepare(
    'SELECT k.*, (SELECT COUNT(*) FROM evaluation_scores s WHERE s.criteria_id = k.id) AS scores
     FROM evaluation_criteria k
     WHERE k.category_id = ?
     ORDER BY k.sort_order, k.id'
);
$stmt->execute([$categoryId]);
$criteria = $stmt->fetchAll();
$activeCount = count(array_filter($criteria, fn($k) => (int) $k['is_active'] === 1));

$pageTitle = 'Criteria: ' . $category['name'];
$breadcrumbs = [['Administration', url('admin/index.php')], ['Categories', url('admin/categories.php')], [$category['name'], null]];
$navActive = 'admin/categories.php';
require __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info">
    <?= $activeCount > 0
        ? 'Evaluators rate each of the ' . $activeCount . ' active criteria below; the category score is their average.'
        : 'This category has no active criteria, so evaluators rate the category as a whole.' ?>
</div>

<div class="grid-2 section-gap">
    <section class="card">
        <h2><?= $form['id'] ? 'Edit criterion' : 'Add a criterion' ?></h2>
        <?php if ($errors): ?>
            <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
        <?php endif; ?>
        <form method="post" action="<?= e(url($selfUrl)) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <div class="field<?= isset($errors['question_text']) ? ' has-error' : '' ?>">
                <label for="question_text">Criterion <span class="required-mark">*</span></label>
                <textarea id="question_text" name="question_text" maxlength="255" required><?= e($form['question_text']) ?></textarea>
                <p class="field-help">Write it as a statement to rate, for example "Meets deadlines without reminders."</p>
                <?php if (isset($errors['question_text'])): ?><p class="field-error"><?= e($errors['question_text']) ?></p><?php endif; ?>
            </div>
            <div class="field<?= isset($errors['sort_order']) ? ' has-error' : '' ?>">
                <label for="sort_order">Order</label>
                <input type="text" id="sort_order" name="sort_order" value="<?= e($form['sort_order']) ?>" inputmode="numeric"
                       pattern="[0-9]*" maxlength="6" data-digits data-min="0" data-max="999999" autocomplete="off">
                <?php if (isset($errors['sort_order'])): ?><p class="field-error"><?= e($errors['sort_order']) ?></p><?php endif; ?>
            </div>
            <div class="form-actions">
                <button type="submit" name="action" value="save" class="btn btn-primary"><?= $form['id'] ? 'Save changes' : 'Add criterion' ?></button>
                <?php if ($form['id']): ?>
                    <a class="btn btn-secondary" href="<?= e(url($selfUrl)) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>About this category</h2>
        <dl class="details">
            <dt>Description</dt><dd><?= e($category['description'] ?: '-') ?></dd>
            <dt>Weight</dt><dd><?= e($category['weight']) ?></dd>
            <dt>Not applicable</dt><dd><?= (int) $category['allow_na'] === 1 ? 'Allowed' : 'Not allowed' ?></dd>
            <dt>Status</dt><dd><?= status_badge($category['is_active'] ? 'active' : 'inactive') ?></dd>
        </dl>
        <p class="mt-1 mb-0"><a href="<?= e(url('admin/category_edit.php?id=' . (int) $categoryId)) ?>">Edit the category</a></p>
    </section>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th scope="col" class="num">Order</th>
            <th scope="col">Criterion</th>
            <th scope="col" class="num">Scores</th>
            <th scope="col">Status</th>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($criteria as $k): ?>
            <tr>
                <td class="num"><?= (int) $k['sort_order'] ?></td>
                <td><?= e($k['question_text']) ?></td>
                <td class="num"><?= (int) $k['scores'] ?></td>
                <td><?= status_badge($k['is_active'] ? 'active' : 'inactive') ?></td>
                <td class="actions-cell">
                    <div class="actions">
                        <a class="btn btn-secondary btn-small" href="<?= e(url($selfUrl . '&edit=' . (int) $k['id'])) ?>">Edit</a>
                        <form method="post" action="<?= e(url($selfUrl)) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
                            <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                            <button type="submit" name="action" value="toggle" class="btn btn-secondary btn-small"><?= $k['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php if ((int) $k['scores'] === 0): ?>
                            <form method="post" action="<?= e(url($selfUrl)) ?>" class="inline-form" data-confirm="Delete this criterion?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
                                <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                                <button type="submit" name="action" value="delete" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$criteria): ?>
            <tr class="empty-row"><td colspan="5">No criteria yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
