<?php
/**
 * Add, edit or delete an employee (?id= to edit).
 *
 * New accounts get a PIN from new_account_pin(): the default PIN or a random
 * one, shown once to the admin. They must change it at first login.
 * An employee with evaluations cannot be deleted (deactivate them instead),
 * and admins cannot change their own role or status, so nobody locks
 * themselves out by accident.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('employees.manage');

$id = is_post() ? post_int('id') : get_int('id');
$employee = $id ? find_employee($id) : null;
if ($id && !$employee) {
    render_error_page(404, 'That employee does not exist.');
}
$isSelf = $employee && (int) $employee['id'] === (int) $user['id'];

$departments = departments_list();
$roles = db()->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$defaultRoleId = (int) (db()->query("SELECT id FROM roles WHERE name = 'Employee'")->fetchColumn() ?: $roles[0]['id']);

// How many evaluations involve this employee (deleting is only allowed at 0).
$evaluationCount = 0;
if ($employee) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM evaluations WHERE evaluator_id = ? OR evaluatee_id = ?');
    $stmt->execute([$id, $id]);
    $evaluationCount = (int) $stmt->fetchColumn();
}

// Delete
if (is_post() && post_str('action') === 'delete' && $employee) {
    $label = full_name($employee) . ' (' . $employee['employee_number'] . ')';
    if ($isSelf) {
        flash('error', 'You cannot delete your own account.');
        redirect('admin/employee_edit.php?id=' . $id);
    }
    if ($evaluationCount > 0) {
        flash('error', $label . ' has ' . plural($evaluationCount, 'evaluation') . ' and cannot be deleted. Deactivate the account instead.');
        redirect('admin/employee_edit.php?id=' . $id);
    }
    db()->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
    audit_log('employee_delete', 'Deleted ' . $label . '.');
    flash('success', $label . ' has been deleted.');
    redirect('admin/employees.php');
}

$form = [
    'employee_number' => $employee['employee_number'] ?? '',
    'first_name'      => $employee['first_name'] ?? '',
    'last_name'       => $employee['last_name'] ?? '',
    'email'           => $employee['email'] ?? '',
    'position'        => $employee['position'] ?? '',
    'department_id'   => (string) ($employee['department_id'] ?? ''),
    'role_id'         => (string) ($employee['role_id'] ?? $defaultRoleId),
    'date_hired'      => $employee['date_hired'] ?? '',
    'status'          => $employee['status'] ?? 'active',
];
$errors = [];

if (is_post() && post_str('action') === 'save') {
    foreach (['employee_number', 'first_name', 'last_name', 'email', 'position', 'department_id', 'role_id', 'date_hired', 'status'] as $field) {
        $form[$field] = post_str($field);
    }
    $form['employee_number'] = strtoupper($form['employee_number']);
    if ($isSelf) {
        // Your own role and status cannot be changed here.
        $form['role_id'] = (string) $employee['role_id'];
        $form['status'] = $employee['status'];
    }

    if (!preg_match('/^[A-Za-z0-9-]{3,20}$/', $form['employee_number'])) {
        $errors['employee_number'] = 'Use 3 to 20 letters, digits or dashes, for example EMP1024.';
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE employee_number = ? AND id <> ?');
        $stmt->execute([$form['employee_number'], $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors['employee_number'] = 'This employee number is already in use.';
        }
    }
    foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $field => $label) {
        if ($form[$field] === '') {
            $errors[$field] = 'Enter the ' . $label . '.';
        } elseif (mb_strlen($form[$field]) > 60) {
            $errors[$field] = 'At most 60 characters.';
        }
    }
    if ($form['email'] !== '') {
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 150) {
            $errors['email'] = 'Enter a valid email address, or leave it empty.';
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE email = ? AND id <> ?');
            $stmt->execute([$form['email'], $id]);
            if ($stmt->fetchColumn() > 0) {
                $errors['email'] = 'Another employee already uses this email address.';
            }
        }
    }
    if (mb_strlen($form['position']) > 100) {
        $errors['position'] = 'At most 100 characters.';
    }
    if ($form['department_id'] !== '' && !in_array((int) $form['department_id'], array_map('intval', array_column($departments, 'id')), true)) {
        $errors['department_id'] = 'Choose a department from the list.';
    }
    if (!in_array((int) $form['role_id'], array_map('intval', array_column($roles, 'id')), true)) {
        $errors['role_id'] = 'Choose a role from the list.';
    }
    if ($form['date_hired'] !== '' && (!is_valid_date($form['date_hired']) || $form['date_hired'] > date('Y-m-d'))) {
        $errors['date_hired'] = 'Enter a valid date that is not in the future.';
    }
    if (!in_array($form['status'], ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose a status.';
    }

    if (!$errors) {
        $values = [
            $form['employee_number'], $form['first_name'], $form['last_name'],
            $form['email'] !== '' ? $form['email'] : null,
            $form['position'] !== '' ? $form['position'] : null,
            $form['department_id'] !== '' ? (int) $form['department_id'] : null,
            (int) $form['role_id'],
            $form['date_hired'] !== '' ? $form['date_hired'] : null,
            $form['status'],
        ];
        $label = $form['first_name'] . ' ' . $form['last_name'] . ' (' . $form['employee_number'] . ')';

        if ($employee) {
            db()->prepare(
                'UPDATE employees SET employee_number = ?, first_name = ?, last_name = ?, email = ?, position = ?,
                        department_id = ?, role_id = ?, date_hired = ?, status = ?
                 WHERE id = ?'
            )->execute(array_merge($values, [$id]));

            // Record what changed, in words, for the audit log.
            $names = array_column($departments, 'name', 'id');
            $roleNames = array_column($roles, 'name', 'id');
            $changes = [];
            if ((string) $employee['department_id'] !== $form['department_id']) {
                $changes[] = 'department ' . ($names[$employee['department_id']] ?? 'none') . ' to ' . ($names[$form['department_id']] ?? 'none');
            }
            if ((string) $employee['role_id'] !== $form['role_id']) {
                $changes[] = 'role ' . $roleNames[$employee['role_id']] . ' to ' . $roleNames[$form['role_id']];
            }
            if ($employee['status'] !== $form['status']) {
                $changes[] = 'status ' . $employee['status'] . ' to ' . $form['status'];
            }
            audit_log('employee_update', 'Updated ' . $label . ($changes ? ': ' . implode('; ', $changes) : '') . '.');
            flash('success', $label . ' has been updated.');
        } else {
            $pin = new_account_pin();
            db()->prepare(
                'INSERT INTO employees (employee_number, first_name, last_name, email, position, department_id, role_id,
                                        date_hired, status, pin_hash, must_change_pin)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute(array_merge($values, [password_hash($pin['pin'], PASSWORD_DEFAULT)]));
            audit_log('employee_create', 'Created ' . $label . '.');
            flash('success', $label . ' has been added. Their first PIN is ' . $pin['pin']
                . ($pin['random'] ? ' (shown only this once, so note it now)' : ' (the default PIN)')
                . '. They must change it at first login.');
        }
        redirect('admin/employees.php');
    }
}

$pageTitle = $employee ? 'Edit Employee' : 'Add Employee';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Employees', url('admin/employees.php')], [$pageTitle, null]];
$navActive = 'admin/employees.php';
require __DIR__ . '/../includes/header.php';

/** A labelled text-like input with its error message. */
function text_field(string $name, string $label, array $form, array $errors, array $attrs = []): void
{
    $error = $errors[$name] ?? '';
    $type = $attrs['type'] ?? 'text';
    unset($attrs['type']);
    $required = !empty($attrs['required']);
    $extra = '';
    foreach ($attrs as $key => $value) {
        $extra .= $value === true ? ' ' . $key : ' ' . $key . '="' . e($value) . '"';
    }
    ?>
    <div class="field<?= $error ? ' has-error' : '' ?>">
        <label for="<?= e($name) ?>"><?= e($label) ?><?= $required ? ' <span class="required-mark">*</span>' : '' ?></label>
        <input type="<?= e($type) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($form[$name]) ?>"<?= $extra ?>>
        <?php if ($error): ?><p class="field-error"><?= e($error) ?></p><?php endif; ?>
    </div>
    <?php
}
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
<?php endif; ?>

<section class="card">
    <form method="post" action="<?= e(url('admin/employee_edit.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="form-grid">
            <?php text_field('employee_number', 'Employee number', $form, $errors, ['required' => true, 'maxlength' => 20, 'autocomplete' => 'off']); ?>
            <?php text_field('email', 'Email', $form, $errors, ['type' => 'email', 'maxlength' => 150]); ?>
            <?php text_field('first_name', 'First name', $form, $errors, ['required' => true, 'maxlength' => 60]); ?>
            <?php text_field('last_name', 'Last name', $form, $errors, ['required' => true, 'maxlength' => 60]); ?>
            <?php text_field('position', 'Position', $form, $errors, ['maxlength' => 100]); ?>
            <?php text_field('date_hired', 'Date hired', $form, $errors, ['type' => 'date', 'max' => date('Y-m-d')]); ?>

            <div class="field<?= isset($errors['department_id']) ? ' has-error' : '' ?>">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="">No department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (string) $d['id'] === $form['department_id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?><?= $d['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-help">Employees evaluate everyone in their department. After a transfer, evaluations already submitted keep the old department.</p>
                <?php if (isset($errors['department_id'])): ?><p class="field-error"><?= e($errors['department_id']) ?></p><?php endif; ?>
            </div>

            <div class="field<?= isset($errors['role_id']) ? ' has-error' : '' ?>">
                <label for="role_id">Role</label>
                <select id="role_id" name="role_id" <?= $isSelf ? 'disabled' : '' ?>>
                    <?= select_options(array_column($roles, 'name', 'id'), $form['role_id']) ?>
                </select>
                <?php if ($isSelf): ?><p class="field-help">You cannot change your own role.</p><?php endif; ?>
                <?php if (isset($errors['role_id'])): ?><p class="field-error"><?= e($errors['role_id']) ?></p><?php endif; ?>
            </div>

            <?php if ($employee): ?>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" <?= $isSelf ? 'disabled' : '' ?>>
                        <?= select_options(['active' => 'Active', 'inactive' => 'Inactive (cannot log in)'], $form['status']) ?>
                    </select>
                    <?php if ($isSelf): ?><p class="field-help">You cannot deactivate yourself.</p><?php endif; ?>
                </div>
            <?php else: ?>
                <input type="hidden" name="status" value="active">
                <div class="field full">
                    <p class="field-help">
                        The new employee gets
                        <?= setting('new_pin_mode') === 'random' ? 'a random 4-digit PIN, shown to you once after saving' : 'the default PIN (' . e(setting('default_pin', '1234')) . ')' ?>,
                        and must change it at first login.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" name="action" value="save" class="btn btn-primary"><?= $employee ? 'Save changes' : 'Add employee' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/employees.php')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php if ($employee): ?>
    <section class="card">
        <h2>Account</h2>
        <dl class="details">
            <dt>Last login</dt><dd><?= e($employee['last_login'] ? format_datetime($employee['last_login']) : 'Never') ?></dd>
            <dt>Created</dt><dd><?= e(format_datetime($employee['created_at'])) ?></dd>
            <dt>PIN</dt><dd><?= (int) $employee['must_change_pin'] === 1 ? 'Must be changed at next login' : 'Set by the employee' ?></dd>
            <dt>Evaluations</dt><dd><?= e(plural($evaluationCount, 'evaluation')) ?> given or received</dd>
        </dl>
        <?php if (!$isSelf && $evaluationCount === 0): ?>
            <form method="post" action="<?= e(url('admin/employee_edit.php')) ?>" class="mt-1"
                  data-confirm="<?= e('Delete ' . full_name($employee) . ' permanently? This cannot be undone.') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" name="action" value="delete" class="btn btn-danger">Delete employee</button>
            </form>
        <?php elseif (!$isSelf): ?>
            <p class="muted mt-1 mb-0">This employee has evaluations, so the account cannot be deleted. Set the status to Inactive instead; the history is kept.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
