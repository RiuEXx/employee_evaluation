<?php
/**
 * Rating categories: list with search, sorting and paging, plus Activate /
 * Deactivate and Delete. A category that already has scores cannot be
 * deleted (past results depend on it); deactivate it to remove it from new
 * evaluation forms.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('categories.manage');

if (is_post()) {
    $stmt = db()->prepare('SELECT * FROM evaluation_categories WHERE id = ?');
    $stmt->execute([post_int('id')]);
    $category = $stmt->fetch();
    $action = post_str('action');

    if (!$category) {
        flash('error', 'That category no longer exists.');
    } elseif ($action === 'toggle') {
        $active = (int) $category['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE evaluation_categories SET is_active = ? WHERE id = ?')->execute([$active, $category['id']]);
        audit_log($active ? 'category_activate' : 'category_deactivate', 'Category: ' . $category['name'] . '.');
        flash('success', $category['name'] . ($active ? ' is back on the evaluation form.' : ' has been removed from the evaluation form. Its past scores are kept.'));
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM evaluation_scores WHERE category_id = ?');
        $stmt->execute([$category['id']]);
        if ($stmt->fetchColumn() > 0) {
            flash('error', $category['name'] . ' already has scores and cannot be deleted. Deactivate it instead.');
        } else {
            // Its criteria are deleted with it (ON DELETE CASCADE).
            db()->prepare('DELETE FROM evaluation_categories WHERE id = ?')->execute([$category['id']]);
            audit_log('category_delete', 'Category: ' . $category['name'] . '.');
            flash('success', $category['name'] . ' has been deleted.');
        }
    }
    redirect(current_request_uri());
}

$q = search_query();
$params = [];
$whereSql = '';
if ($q !== '') {
    $whereSql = 'WHERE c.name LIKE ? OR c.description LIKE ?';
    $params = [like_pattern($q), like_pattern($q)];
}

$stmt = db()->prepare("SELECT COUNT(*) FROM evaluation_categories c $whereSql");
$stmt->execute($params);
$pages = pagination((int) $stmt->fetchColumn());

$sort = sort_state(['order' => 'c.sort_order', 'name' => 'c.name', 'weight' => 'c.weight', 'status' => 'c.is_active'], 'order');
$stmt = db()->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM evaluation_criteria k WHERE k.category_id = c.id AND k.is_active = 1) AS active_criteria,
            (SELECT COUNT(*) FROM evaluation_scores s WHERE s.category_id = c.id) AS scores
     FROM evaluation_categories c
     $whereSql
     ORDER BY {$sort['sql']}, c.name
     LIMIT {$pages['per_page']} OFFSET {$pages['offset']}"
);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$totalWeight = (float) db()->query('SELECT SUM(weight) FROM evaluation_categories WHERE is_active = 1')->fetchColumn();

$pageTitle = 'Rating Categories';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Categories', null]];
require __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <p>
        Evaluators rate each active category from 1 (Poor) to 5 (Excellent), in the order shown here.
        A category with criteria is rated item by item, and its score is the average of those items.
    </p>
    <p class="mb-0">
        <strong>Weight</strong> sets how much a category counts in the overall score
        (the overall score is the weighted average of the category averages).
        Active weights currently add up to <?= e(number_format($totalWeight, 2)) ?>.
    </p>
</section>

<div class="filters">
    <form method="get" class="filters m-0" role="search">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="<?= SEARCH_MAX_LENGTH ?>" placeholder="Name or description">
        </div>
        <button type="submit" class="btn btn-secondary">Search</button>
    </form>
    <a class="btn btn-primary" href="<?= e(url('admin/category_edit.php')) ?>">Add category</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?= sort_th('Order', 'order', $sort, 'num') ?>
            <?= sort_th('Category', 'name', $sort) ?>
            <?= sort_th('Weight', 'weight', $sort, 'num') ?>
            <th scope="col">N/A allowed</th>
            <th scope="col">Criteria</th>
            <?= sort_th('Status', 'status', $sort) ?>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td class="num"><?= (int) $c['sort_order'] ?></td>
                <td><?= e($c['name']) ?><br><span class="muted small"><?= e($c['description'] ?: '') ?></span></td>
                <td class="num"><?= e($c['weight']) ?></td>
                <td><?= (int) $c['allow_na'] === 1 ? 'Yes' : 'No' ?></td>
                <td class="nowrap">
                    <?= (int) $c['active_criteria'] > 0 ? e(plural((int) $c['active_criteria'], 'criterion', 'criteria')) : '<span class="muted">Rated directly</span>' ?>
                    <br><a href="<?= e(url('admin/criteria.php?category_id=' . (int) $c['id'])) ?>">Manage criteria</a>
                </td>
                <td><?= status_badge($c['is_active'] ? 'active' : 'inactive') ?></td>
                <td class="actions-cell">
                    <div class="actions">
                        <a class="btn btn-secondary btn-small" href="<?= e(url('admin/category_edit.php?id=' . (int) $c['id'])) ?>">Edit</a>
                        <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" name="action" value="toggle" class="btn btn-secondary btn-small"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php if ((int) $c['scores'] === 0): ?>
                            <form method="post" action="<?= e(current_request_uri()) ?>" class="inline-form"
                                  data-confirm="<?= e('Delete the category ' . $c['name'] . ' and its criteria?') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" name="action" value="delete" class="btn btn-danger btn-small">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
            <tr class="empty-row"><td colspan="7">No categories found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= render_pagination($pages) ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
