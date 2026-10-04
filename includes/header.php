<?php
/**
 * Page header and navigation. Pages set these variables before including it:
 *   $pageTitle    string  title shown in the browser tab and as the page heading
 *   $breadcrumbs  array   optional list of [label, url or null]
 *   $layout       string  'app' (default: top bar + side menu) or 'plain' (centered card pages)
 *   $hideTitle    bool    optional, true to skip the <h1> (the page prints its own)
 */

$pageTitle = $pageTitle ?? APP_SHORT_NAME;
$breadcrumbs = $breadcrumbs ?? [];
$layout = $layout ?? 'app';
$navUser = $layout === 'app' ? current_user() : null;
$companyName = setting('company_name', '');

/** Navigation entries: [label, path, permission codes (any of), extra condition]. */
$navGroups = [];
if ($navUser) {
    $showSummary = setting_bool('show_results_to_employees');
    $navGroups = [
        'My Work' => [
            ['My Evaluations', 'employee/index.php', ['evaluations.submit'], true],
            ['My Summary', 'employee/summary.php', ['summary.view_own'], $showSummary],
        ],
        'Results' => [
            ['Overview', 'ceo/index.php', ['results.view'], true],
            ['Departments', 'ceo/departments.php', ['results.view'], true],
            ['Employees', 'ceo/employees.php', ['results.view'], true],
            ['Completion Tracker', 'ceo/completion.php', ['completion.view'], true],
            ['Printable Report', 'ceo/report.php', ['results.export'], true],
        ],
        'Administration' => [
            ['Admin Dashboard', 'admin/index.php', ADMIN_PERMISSIONS, true],
            ['Departments', 'admin/departments.php', ['departments.manage'], true],
            ['Employees', 'admin/employees.php', ['employees.manage', 'pins.reset'], true],
            ['Categories', 'admin/categories.php', ['categories.manage'], true],
            ['Evaluation Periods', 'admin/periods.php', ['periods.manage'], true],
            ['Roles and Permissions', 'admin/roles.php', ['roles.manage'], true],
            ['Audit Log', 'admin/audit_log.php', ['audit.view'], true],
            ['Settings', 'admin/settings.php', ['settings.manage'], true],
        ],
        'Account' => [
            ['Change PIN', 'change_pin.php', [], true],
        ],
    ];
}

// The menu entry for the current page is highlighted. Sub-pages (edit forms)
// highlight their list page through $navActive.
$currentPath = strtolower(str_replace('\\', '/', substr((string) realpath($_SERVER['SCRIPT_FILENAME']), strlen((string) realpath(__DIR__ . '/..')) + 1)));
$navActive = $navActive ?? $currentPath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e(APP_SHORT_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
</head>
<body class="<?= $layout === 'plain' ? 'plain' : 'app' ?>">
<a class="skip-link" href="#main">Skip to main content</a>

<?php if ($layout === 'plain'): ?>
<main id="main" class="plain-main">
<?php else: ?>
<header class="topbar">
    <button class="menu-toggle" type="button" aria-controls="sidebar" aria-expanded="false">Menu</button>
    <a class="brand" href="<?= e(url($navUser ? home_path($navUser) : 'index.php')) ?>">
        <span class="brand-app"><?= e(APP_SHORT_NAME) ?></span>
        <?php if ($companyName !== ''): ?><span class="brand-company"><?= e($companyName) ?></span><?php endif; ?>
    </a>
    <?php if ($navUser): ?>
        <div class="topbar-user">
            <span class="user-name"><?= e(full_name($navUser)) ?></span>
            <span class="user-role"><?= e($navUser['role_name']) ?><?= $navUser['department_name'] ? ', ' . e($navUser['department_name']) : '' ?></span>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-small btn-secondary">Log out</button>
            </form>
        </div>
    <?php endif; ?>
</header>

<div class="layout">
    <nav id="sidebar" class="sidebar" aria-label="Main menu">
        <?php foreach ($navGroups as $groupLabel => $items): ?>
            <?php
            $visible = array_filter($items, fn($item) => $item[3] && ($item[2] === [] || can_any($item[2])));
            if (!$visible) {
                continue;
            }
            ?>
            <div class="nav-group">
                <p class="nav-group-label"><?= e($groupLabel) ?></p>
                <ul>
                    <?php foreach ($visible as [$label, $path]): ?>
                        <li>
                            <a href="<?= e(url($path)) ?>"<?= strtolower($path) === $navActive ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <main id="main" class="content">
<?php endif; ?>

<?php if ($breadcrumbs): ?>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <ol>
            <?php foreach ($breadcrumbs as [$label, $href]): ?>
                <li><?php if ($href): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ol>
    </nav>
<?php endif; ?>

<?php if (empty($hideTitle)): ?>
    <h1 class="page-title"><?= e($pageTitle) ?></h1>
<?php endif; ?>

<?php foreach (take_flashes() as $flashMessage): ?>
    <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>">
        <?= e($flashMessage['message']) ?>
    </div>
<?php endforeach; ?>
