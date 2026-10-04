<?php
/**
 * Roles and Permissions. Privileges live in the role_permissions table; this
 * page edits them as a grid (one column per role), and adds or removes
 * custom roles. Examples:
 *   - tick "results.view" for Admin to let HR see results;
 *   - tick "evaluations.receive" for CEO (and give the CEO a department) to
 *     have the CEO evaluated.
 * You cannot remove "roles.manage" from your own role, so the page cannot
 * lock you out.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('roles.manage');

$roles = db()->query(
    'SELECT r.*, (SELECT COUNT(*) FROM employees e WHERE e.role_id = r.id) AS employees FROM roles r ORDER BY r.id'
)->fetchAll();
$permissions = db()->query('SELECT * FROM permissions ORDER BY sort_order, code')->fetchAll();
$permissionCodes = array_column($permissions, 'code', 'id');

/** Current grid as [role_id => [permission_id => true]]. */
function current_grid(): array
{
    $grid = [];
    foreach (db()->query('SELECT role_id, permission_id FROM role_permissions')->fetchAll() as $row) {
        $grid[(int) $row['role_id']][(int) $row['permission_id']] = true;
    }
    return $grid;
}

$grid = current_grid();
$roleErrors = [];
$newRole = ['name' => '', 'description' => ''];

if (is_post()) {
    $action = post_str('action');

    if ($action === 'save') {
        $posted = $_POST['perm'] ?? [];
        $posted = is_array($posted) ? $posted : [];
        $newGrid = [];
        foreach ($roles as $role) {
            $rid = (int) $role['id'];
            $newGrid[$rid] = [];
            foreach ((array) ($posted[$rid] ?? []) as $pid) {
                if (isset($permissionCodes[(int) $pid])) {
                    $newGrid[$rid][(int) $pid] = true;
                }
            }
        }

        $manageId = (int) array_search('roles.manage', $permissionCodes, true);
        if (empty($newGrid[(int) $user['role_id']][$manageId])) {
            flash('error', 'You cannot remove "roles.manage" from your own role (' . $user['role_name'] . '). Nothing was saved.');
            redirect('admin/roles.php');
        }

        $changes = [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($roles as $role) {
                $rid = (int) $role['id'];
                $before = array_keys($grid[$rid] ?? []);
                $after = array_keys($newGrid[$rid]);
                foreach (array_diff($after, $before) as $pid) {
                    $changes[] = $role['name'] . ' +' . $permissionCodes[$pid];
                }
                foreach (array_diff($before, $after) as $pid) {
                    $changes[] = $role['name'] . ' -' . $permissionCodes[$pid];
                }
                $delete->execute([$rid]);
                foreach ($after as $pid) {
                    $insert->execute([$rid, $pid]);
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        if ($changes) {
            audit_log('role_permissions_update', implode(', ', $changes));
            flash('success', 'Permissions saved: ' . implode(', ', $changes) . '.');
        } else {
            flash('info', 'Nothing changed.');
        }
        redirect('admin/roles.php');
    }

    if ($action === 'add_role') {
        $newRole = ['name' => post_str('name'), 'description' => post_str('description')];
        if ($newRole['name'] === '' || mb_strlen($newRole['name']) > 50) {
            $roleErrors['name'] = 'Enter a role name of at most 50 characters.';
        } elseif (in_array(mb_strtolower($newRole['name']), array_map('mb_strtolower', array_column($roles, 'name')), true)) {
            $roleErrors['name'] = 'A role with this name already exists.';
        }
        if (mb_strlen($newRole['description']) > 255) {
            $roleErrors['description'] = 'At most 255 characters.';
        }
        if (!$roleErrors) {
            db()->prepare('INSERT INTO roles (name, description, is_system) VALUES (?, ?, 0)')
                ->execute([$newRole['name'], $newRole['description'] !== '' ? $newRole['description'] : null]);
            audit_log('role_create', 'Role: ' . $newRole['name'] . '.');
            flash('success', 'Role "' . $newRole['name'] . '" added. Tick its permissions in the grid and save.');
            redirect('admin/roles.php');
        }
    }

    if ($action === 'delete_role') {
        $target = null;
        foreach ($roles as $role) {
            if ((int) $role['id'] === post_int('role_id')) {
                $target = $role;
            }
        }
        if (!$target) {
            flash('error', 'That role no longer exists.');
        } elseif ((int) $target['is_system'] === 1) {
            flash('error', 'Built-in roles cannot be deleted.');
        } elseif ((int) $target['employees'] > 0) {
            flash('error', 'Move the ' . plural((int) $target['employees'], 'employee') . ' with this role to another role first.');
        } else {
            db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$target['id']]);
            audit_log('role_delete', 'Role: ' . $target['name'] . '.');
            flash('success', 'Role "' . $target['name'] . '" deleted.');
        }
        redirect('admin/roles.php');
    }
}

$pageTitle = 'Roles and Permissions';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Roles and Permissions', null]];
require __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <p class="mb-0">
        Each column is a role and each row a permission. Changes apply to everyone with that role at their next page load.
        Useful examples: give <strong>Admin</strong> the <em>results.view</em> permission to let HR see results; give
        <strong>CEO</strong> <em>evaluations.receive</em> (and a department) to have the CEO evaluated by that department.
    </p>
</section>

<form method="post" action="<?= e(url('admin/roles.php')) ?>">
    <?= csrf_field() ?>
    <div class="table-wrap">
        <table class="matrix">
            <thead>
            <tr>
                <th scope="col">Permission</th>
                <?php foreach ($roles as $role): ?>
                    <th scope="col"><?= e($role['name']) ?><br><span class="muted small"><?= e(plural((int) $role['employees'], 'person', 'people')) ?></span></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php $group = null; ?>
            <?php foreach ($permissions as $perm): ?>
                <?php if ($perm['group_name'] !== $group): $group = $perm['group_name']; ?>
                    <tr class="group-row"><td colspan="<?= count($roles) + 1 ?>"><?= e($group) ?></td></tr>
                <?php endif; ?>
                <tr>
                    <td>
                        <strong><?= e($perm['code']) ?></strong><br>
                        <span class="muted small"><?= e($perm['description']) ?></span>
                    </td>
                    <?php foreach ($roles as $role): ?>
                        <td>
                            <input type="checkbox" name="perm[<?= (int) $role['id'] ?>][]" value="<?= (int) $perm['id'] ?>"
                                   aria-label="<?= e($role['name'] . ': ' . $perm['code']) ?>"
                                   <?= isset($grid[(int) $role['id']][(int) $perm['id']]) ? 'checked' : '' ?>>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions section-gap">
        <button type="submit" name="action" value="save" class="btn btn-primary">Save permissions</button>
        <a class="btn btn-secondary" href="<?= e(url('admin/roles.php')) ?>">Undo changes</a>
    </div>
</form>

<div class="grid-2 section-gap">
    <section class="card">
        <h2>Roles</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th scope="col">Role</th><th scope="col" class="num">People</th><th scope="col">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><?= e($role['name']) ?><br><span class="muted small"><?= e($role['description'] ?? '') ?></span></td>
                        <td class="num"><?= (int) $role['employees'] ?></td>
                        <td class="actions-cell">
                            <?php if ((int) $role['is_system'] === 1): ?>
                                <span class="muted small">Built-in</span>
                            <?php elseif ((int) $role['employees'] > 0): ?>
                                <span class="muted small">In use</span>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('admin/roles.php')) ?>" class="inline-form"
                                      data-confirm="<?= e('Delete the role ' . $role['name'] . '?') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                                    <button type="submit" name="action" value="delete_role" class="btn btn-danger btn-small">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Add a role</h2>
        <form method="post" action="<?= e(url('admin/roles.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="field<?= isset($roleErrors['name']) ? ' has-error' : '' ?>">
                <label for="name">Name <span class="required-mark">*</span></label>
                <input type="text" id="name" name="name" value="<?= e($newRole['name']) ?>" maxlength="50" required>
                <?php if (isset($roleErrors['name'])): ?><p class="field-error"><?= e($roleErrors['name']) ?></p><?php endif; ?>
            </div>
            <div class="field<?= isset($roleErrors['description']) ? ' has-error' : '' ?>">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" value="<?= e($newRole['description']) ?>" maxlength="255">
                <?php if (isset($roleErrors['description'])): ?><p class="field-error"><?= e($roleErrors['description']) ?></p><?php endif; ?>
            </div>
            <button type="submit" name="action" value="add_role" class="btn btn-secondary">Add role</button>
        </form>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
