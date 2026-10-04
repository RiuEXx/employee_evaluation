<?php
/**
 * Admin (HR) dashboard: account and organisation numbers, the open period,
 * shortcuts, and the latest audit log entries.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission(...ADMIN_PERMISSIONS);

$counts = db()->query(
    "SELECT SUM(status = 'active') AS active,
            SUM(status = 'inactive') AS inactive,
            SUM(status = 'active' AND locked_until IS NOT NULL AND locked_until > NOW()) AS locked,
            SUM(status = 'active' AND must_change_pin = 1) AS pending_pin
     FROM employees"
)->fetch();
$departmentCount = (int) db()->query('SELECT COUNT(*) FROM departments WHERE is_active = 1')->fetchColumn();
$categoryCount = (int) db()->query('SELECT COUNT(*) FROM evaluation_categories WHERE is_active = 1')->fetchColumn();
$openPeriod = get_open_period();
$completion = $openPeriod ? completion_totals(completion_rows((int) $openPeriod['id'])) : null;

$recentLogs = [];
if (can('audit.view')) {
    $recentLogs = db()->query(
        'SELECT a.*, e.first_name, e.last_name
         FROM audit_logs a
         LEFT JOIN employees e ON e.id = a.employee_id
         ORDER BY a.id DESC
         LIMIT 8'
    )->fetchAll();
}

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="stats">
    <div class="stat">
        <p class="stat-label">Active employees</p>
        <p class="stat-value"><?= (int) $counts['active'] ?></p>
        <p class="stat-note"><?= (int) $counts['inactive'] ?> inactive</p>
    </div>
    <div class="stat">
        <p class="stat-label">Active departments</p>
        <p class="stat-value"><?= $departmentCount ?></p>
        <p class="stat-note"><?= plural($categoryCount, 'rating category', 'rating categories') ?></p>
    </div>
    <div class="stat accent">
        <p class="stat-label">Locked accounts</p>
        <p class="stat-value"><?= (int) $counts['locked'] ?></p>
        <p class="stat-note">Locked after too many wrong PINs</p>
    </div>
    <div class="stat accent">
        <p class="stat-label">PIN change pending</p>
        <p class="stat-value"><?= (int) $counts['pending_pin'] ?></p>
        <p class="stat-note">Still using a new or reset PIN</p>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <h2>Current evaluation period</h2>
        <?php if (can('periods.manage')): ?>
            <a class="btn btn-secondary btn-small" href="<?= e(url('admin/periods.php')) ?>">Manage periods</a>
        <?php endif; ?>
    </div>
    <?php if ($openPeriod): ?>
        <p>
            <strong><?= e($openPeriod['title']) ?></strong> is open,
            <?= e(format_date($openPeriod['start_date'])) ?> to <?= e(format_date($openPeriod['end_date'])) ?>.
        </p>
        <?= progress_bar((int) $completion['done'], (int) $completion['expected'],
            $completion['done'] . ' of ' . $completion['expected'] . ' evaluations submitted (' . format_percent($completion['rate']) . ')') ?>
        <?php if (can('completion.view')): ?>
            <p class="mt-1 mb-0"><a href="<?= e(url('ceo/completion.php?status=unfinished')) ?>">See who has not finished</a></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="mb-0">No evaluation period is open. Employees cannot submit evaluations until one is opened.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Shortcuts</h2>
    <div class="actions">
        <?php if (can('employees.manage')): ?>
            <a class="btn btn-primary" href="<?= e(url('admin/employee_edit.php')) ?>">Add employee</a>
            <a class="btn btn-secondary" href="<?= e(url('admin/employee_import.php')) ?>">Import employees from CSV</a>
        <?php endif; ?>
        <?php if (can('pins.reset') && (int) $counts['locked'] > 0): ?>
            <a class="btn btn-secondary" href="<?= e(url('admin/employees.php?status=locked')) ?>">Show locked accounts</a>
        <?php endif; ?>
        <?php if (can('departments.manage')): ?>
            <a class="btn btn-secondary" href="<?= e(url('admin/department_edit.php')) ?>">Add department</a>
        <?php endif; ?>
        <?php if (can('periods.manage')): ?>
            <a class="btn btn-secondary" href="<?= e(url('admin/period_edit.php')) ?>">New evaluation period</a>
        <?php endif; ?>
        <?php if (can('settings.manage')): ?>
            <a class="btn btn-secondary" href="<?= e(url('admin/settings.php')) ?>">Settings</a>
        <?php endif; ?>
    </div>
</section>

<?php if (can('audit.view')): ?>
    <section class="card">
        <div class="card-header">
            <h2>Recent activity</h2>
            <a class="btn btn-secondary btn-small" href="<?= e(url('admin/audit_log.php')) ?>">Full audit log</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th scope="col">When</th><th scope="col">Employee</th><th scope="col">Action</th><th scope="col">Details</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime($log['created_at'])) ?></td>
                        <td><?= e($log['first_name'] ? full_name($log) : ($log['employee_number'] ?? '-')) ?></td>
                        <td><?= e($log['action']) ?></td>
                        <td><?= e($log['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentLogs): ?>
                    <tr class="empty-row"><td colspan="4">No activity yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
