<?php
/**
 * CSV export (opens in Excel).
 *   ?type=employees    one row per evaluated employee with category averages (needs results.export)
 *   ?type=departments  one row per department (needs results.export)
 *   ?type=completion   evaluation progress per employee (needs completion.view)
 * Optional: &period=<id>, and &department=<id> for the employees export.
 * Scores below the minimum number of evaluations are left empty, as on screen.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$type = get_str('type') ?: 'employees';
if (!in_array($type, ['employees', 'departments', 'completion'], true)) {
    render_error_page(404, 'Unknown export type.');
}
$user = $type === 'completion' ? require_permission('completion.view') : require_permission('results.export');

$period = selected_period(result_periods());
if (!$period) {
    flash('info', 'There is no evaluation period to export yet.');
    redirect(home_path($user));
}
$periodId = (int) $period['id'];
$deptFilter = get_int('department');
$categories = categories_map();

/**
 * Makes a value safe for spreadsheets: text starting with = + - @ could be run
 * as a formula by Excel ("CSV injection"), so it gets a leading apostrophe.
 */
function csv_cell($value): string
{
    if ($value === null) {
        return '';
    }
    $value = (string) $value;
    if ($value !== '' && !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

$rows = [];

if ($type === 'employees') {
    $where = ['period_id' => $periodId] + ($deptFilter ? ['department_id' => $deptFilter] : []);
    $results = score_rollup($where, ['evaluatee_id']);
    $employees = employees_by_id();
    $catIds = array_keys(rollup_entry(score_rollup($where))['categories']);

    $header = ['Employee Number', 'First Name', 'Last Name', 'Department (at evaluation)', 'Position', 'Status', 'Evaluations Received', 'Overall Score'];
    foreach ($catIds as $catId) {
        $header[] = $categories[$catId]['name'] ?? 'Category ' . $catId;
    }
    $header[] = 'Note';
    $rows[] = $header;

    $list = [];
    foreach ($results as $entry) {
        $id = $entry['keys']['evaluatee_id'];
        if (isset($employees[$id])) {
            $list[] = $employees[$id] + ['entry' => $entry, 'dept_id' => evaluation_department($periodId, $id)];
        }
    }
    usort($list, fn($a, $b) => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);

    foreach ($list as $emp) {
        $entry = $emp['entry'];
        $enough = enough_evaluations($entry['evaluations']);
        $dept = $emp['dept_id'] ? find_department($emp['dept_id']) : null;
        $row = [
            $emp['employee_number'], $emp['first_name'], $emp['last_name'], $dept['name'] ?? '',
            $emp['position'], $emp['status'], $entry['evaluations'],
            $enough ? number_format($entry['overall'], 2, '.', '') : '',
        ];
        foreach ($catIds as $catId) {
            $row[] = $enough && isset($entry['categories'][$catId]) ? number_format($entry['categories'][$catId], 2, '.', '') : '';
        }
        $row[] = $enough ? '' : 'Hidden: fewer than ' . min_evaluations() . ' evaluations';
        $rows[] = $row;
    }
} elseif ($type === 'departments') {
    $byDept = score_rollup(['period_id' => $periodId], ['department_id']);
    $completionByDept = completion_by_department(completion_rows($periodId));
    $headcounts = department_headcounts();
    $rows[] = ['Department', 'Active Employees', 'Evaluations', 'Evaluations Expected', 'Evaluations Submitted', 'Completion %', 'Average Score', 'Highest Category', 'Lowest Category'];
    foreach (departments_list() as $d) {
        $id = (int) $d['id'];
        $entry = rollup_entry($byDept, (string) $id);
        $c = $completionByDept[$id] ?? null;
        $cats = $entry['categories'];
        $best = $cats ? array_keys($cats, max($cats))[0] : null;
        $worst = $cats ? array_keys($cats, min($cats))[0] : null;
        $rows[] = [
            $d['name'], $headcounts[$id] ?? 0, $entry['evaluations'],
            $c['expected'] ?? 0, $c['done'] ?? 0, $c && $c['rate'] !== null ? round($c['rate'] * 100) : '',
            $entry['overall'] !== null ? number_format($entry['overall'], 2, '.', '') : '',
            $best !== null ? $categories[$best]['name'] : '', $worst !== null ? $categories[$worst]['name'] : '',
        ];
    }
} else {
    $rows[] = ['Employee Number', 'First Name', 'Last Name', 'Department', 'Position', 'Evaluations Done', 'Evaluations Expected', 'Completion %', 'Status'];
    foreach (completion_rows($periodId, $deptFilter ?: null) as $r) {
        $rows[] = [
            $r['employee_number'], $r['first_name'], $r['last_name'], $r['department_name'], $r['position'],
            $r['done'], $r['expected'], round($r['rate'] * 100), $r['status'],
        ];
    }
}

audit_log('export_csv', 'Exported ' . $type . ' for ' . $period['title'] . '.');

$filename = $type . '_' . slugify($period['title']) . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel shows accented names correctly
foreach ($rows as $row) {
    fputcsv($out, array_map('csv_cell', $row), ',', '"', '');
}
fclose($out);
exit;
