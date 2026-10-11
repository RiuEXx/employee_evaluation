<?php
/**
 * Bulk import of employees from a CSV file.
 *
 * Columns (first row = header, any order):
 *   first_name, last_name   required
 *   employee_number, email, position, department, role, date_hired (YYYY-MM-DD)   optional
 * Department and role are matched by name (role defaults to "Employee").
 * An employee number must start with its role's tag (EMP1024 for an
 * Employee); an empty one gets the next free number.
 * The whole file is checked first; if any row has an error nothing is
 * imported, so a file is never half-loaded.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('employees.manage');

const IMPORT_COLUMNS = ['employee_number', 'first_name', 'last_name', 'email', 'position', 'department', 'role', 'date_hired'];

// Download a template to fill in.
if (get_str('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employee_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, IMPORT_COLUMNS, ',', '"', '');
    fputcsv($out, ['EMP2001', 'Maria', 'Santos', 'maria.santos@example.com', 'Accountant', 'Finance', 'Employee', '2026-01-15'], ',', '"', '');
    fputcsv($out, ['', 'John', 'Reyes', '', 'IT Support Specialist', 'Information Technology', '', ''], ',', '"', '');
    fclose($out);
    exit;
}

$errors = [];
$created = [];
$fileName = '';

if (is_post()) {
    $file = $_FILES['csv'] ?? null;
    $fileName = is_array($file) ? basename((string) ($file['name'] ?? '')) : '';

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Choose a CSV file to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is too large.' : 'The upload failed. Please try again.';
    } elseif ($file['size'] > IMPORT_MAX_BYTES) {
        $errors[] = 'The file is larger than ' . round(IMPORT_MAX_BYTES / 1048576, 1) . ' MB.';
    } elseif (!preg_match('/\.(csv|txt)$/i', $fileName)) {
        $errors[] = 'The file must be a .csv file.';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'The upload failed. Please try again.';
    }

    $rows = [];
    if (!$errors) {
        $handle = fopen($file['tmp_name'], 'r');
        $firstLine = (string) fgets($handle);
        rewind($handle);
        // Excel in some regions saves CSV files with semicolons.
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            $errors[] = 'The file is empty.';
        } else {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // remove a UTF-8 BOM
            $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
            foreach (['first_name', 'last_name'] as $required) {
                if (!in_array($required, $header, true)) {
                    $errors[] = 'The header row is missing the column "' . $required . '".';
                }
            }
        }

        $line = 1;
        while (!$errors && ($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue; // blank line
            }
            $row = ['line' => $line];
            foreach (IMPORT_COLUMNS as $col) {
                $index = array_search($col, $header, true);
                $row[$col] = $index === false ? '' : trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
            if (count($rows) > IMPORT_MAX_ROWS) {
                $errors[] = 'The file has more than ' . IMPORT_MAX_ROWS . ' rows. Split it into smaller files.';
            }
        }
        fclose($handle);

        if (!$errors && !$rows) {
            $errors[] = 'The file has a header row but no employees.';
        }
    }

    // Validate every row before importing anything.
    if (!$errors) {
        $deptIds = [];
        foreach (departments_list() as $d) {
            $deptIds[mb_strtolower($d['name'])] = (int) $d['id'];
        }
        $roleIds = [];
        $rolePrefixes = [];
        foreach (db()->query('SELECT id, name, number_prefix FROM roles')->fetchAll() as $r) {
            $roleIds[mb_strtolower($r['name'])] = (int) $r['id'];
            $rolePrefixes[(int) $r['id']] = $r['number_prefix'];
        }
        $existing = db()->query('SELECT employee_number AS n, LOWER(email) AS m FROM employees')->fetchAll();
        // Digits already in use (as numbers, so 1005 and 01005 are the same), in the database or earlier in the file.
        $usedDigits = [];
        foreach (array_column($existing, 'n') as $number) {
            if (preg_match('/(\d+)$/', $number, $m)) {
                $usedDigits[(int) $m[1]] = $number;
            }
        }
        $emails = array_flip(array_filter(array_column($existing, 'm')));

        foreach ($rows as &$row) {
            $prefix = 'Row ' . $row['line'] . ': ';

            $roleName = $row['role'] !== '' ? $row['role'] : 'Employee';
            $row['role_id'] = $roleIds[mb_strtolower($roleName)] ?? null;
            if ($row['role_id'] === null) {
                $errors[] = $prefix . 'role "' . $roleName . '" does not exist.';
            }

            // A given number must be the role's tag plus digits; an empty one is filled in below.
            $row['employee_number'] = strtoupper($row['employee_number']);
            if ($row['employee_number'] !== '' && $row['role_id'] !== null) {
                $tag = $rolePrefixes[$row['role_id']];
                $digits = employee_number_digits($row['employee_number']);
                if ($digits === '' || !str_starts_with($row['employee_number'], $tag) || strlen($row['employee_number']) !== strlen($tag . $digits)) {
                    $errors[] = $prefix . 'employee number "' . $row['employee_number'] . '" must be ' . $tag . ' followed by '
                        . EMPLOYEE_NUMBER_MIN_DIGITS . ' to 6 digits (the tag of the role ' . $roleName . '), or empty.';
                } elseif (isset($usedDigits[(int) $digits])) {
                    $errors[] = $prefix . 'the number ' . $digits . ' is already used by ' . $usedDigits[(int) $digits] . '.';
                } else {
                    $usedDigits[(int) $digits] = $row['employee_number'];
                }
            }

            foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $col => $label) {
                if ($row[$col] === '' || mb_strlen($row[$col]) > 60) {
                    $errors[] = $prefix . $label . ' is required (at most 60 characters).';
                }
            }
            if ($row['email'] !== '') {
                if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($row['email']) > 150) {
                    $errors[] = $prefix . 'email "' . $row['email'] . '" is not valid.';
                } elseif (isset($emails[mb_strtolower($row['email'])])) {
                    $errors[] = $prefix . 'email ' . $row['email'] . ' is already used.';
                }
                $emails[mb_strtolower($row['email'])] = true;
            }
            if (mb_strlen($row['position']) > 100) {
                $errors[] = $prefix . 'position is longer than 100 characters.';
            }
            $row['department_id'] = null;
            if ($row['department'] !== '') {
                $row['department_id'] = $deptIds[mb_strtolower($row['department'])] ?? null;
                if ($row['department_id'] === null) {
                    $errors[] = $prefix . 'department "' . $row['department'] . '" does not exist.';
                }
            }
            if ($row['date_hired'] !== '' && !is_valid_date($row['date_hired'])) {
                $errors[] = $prefix . 'date hired "' . $row['date_hired'] . '" must be a date written as YYYY-MM-DD.';
            } elseif ($row['date_hired'] !== '' && !is_date_between($row['date_hired'], MIN_DATE, today_date())) {
                $errors[] = $prefix . 'date hired "' . $row['date_hired'] . '" must be from ' . MIN_DATE . ' to ' . today_date() . '.';
            }
            foreach (IMPORT_COLUMNS as $col) {
                if (has_emoji($row[$col])) {
                    $errors[] = $prefix . str_replace('_', ' ', $col) . ' contains an emoji; use plain text only.';
                }
            }
        }
        unset($row);

        // Rows without a number get the next free ones, after every number in use.
        if (!$errors) {
            $next = $usedDigits ? max(array_keys($usedDigits)) + 1 : 1001;
            foreach ($rows as &$row) {
                if ($row['employee_number'] === '') {
                    $row['employee_number'] = $rolePrefixes[$row['role_id']]
                        . str_pad((string) $next++, EMPLOYEE_NUMBER_MIN_DIGITS, '0', STR_PAD_LEFT);
                }
            }
            unset($row);
        }
    }

    // Import everything in one transaction.
    if (!$errors) {
        $pdo = db();
        $insert = $pdo->prepare(
            'INSERT INTO employees (employee_number, first_name, last_name, email, position, department_id, role_id,
                                    date_hired, pin_hash, must_change_pin, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'active\')'
        );
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $pin = new_account_pin();
                $insert->execute([
                    $row['employee_number'], $row['first_name'], $row['last_name'],
                    $row['email'] !== '' ? $row['email'] : null,
                    $row['position'] !== '' ? $row['position'] : null,
                    $row['department_id'], $row['role_id'],
                    $row['date_hired'] !== '' ? $row['date_hired'] : null,
                    password_hash($pin['pin'], PASSWORD_DEFAULT),
                ]);
                $created[] = $row + ['pin' => $pin['pin']];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        audit_log('employee_import', 'Imported ' . plural(count($created), 'employee') . ' from ' . mb_substr($fileName, 0, 100) . '.');
    }
}

$pageTitle = 'Import Employees';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Employees', url('admin/employees.php')], ['Import', null]];
$navActive = 'admin/employees.php';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($created): ?>
    <div class="alert alert-success" role="status">
        <?= e(plural(count($created), 'employee')) ?> imported from <?= e($fileName) ?>.
        <?= setting('new_pin_mode') === 'random'
            ? 'Each one got a random PIN, listed below. Note or print the list now: the PINs are shown only this once.'
            : 'Each one got the default PIN. They must change it at first login.' ?>
    </div>
    <section class="card">
        <div class="card-header">
            <h2>Imported employees</h2>
            <button type="button" class="btn btn-secondary btn-small no-print" data-print>Print this list</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th scope="col">Number</th><th scope="col">Name</th><th scope="col">Department</th><th scope="col">Role</th><th scope="col">First PIN</th></tr>
                </thead>
                <tbody>
                <?php foreach ($created as $row): ?>
                    <tr>
                        <td><?= e($row['employee_number']) ?></td>
                        <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td><?= e($row['department'] ?: 'No department') ?></td>
                        <td><?= e($row['role'] ?: 'Employee') ?></td>
                        <td><strong><?= e($row['pin']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mb-0"><a class="btn btn-primary" href="<?= e(url('admin/employees.php')) ?>">Go to the employee list</a></p>
    </section>
<?php else: ?>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            Nothing was imported. Fix the problems below and upload the file again.
            <ul>
                <?php foreach (array_slice($errors, 0, 50) as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
                <?php if (count($errors) > 50): ?><li>... and <?= count($errors) - 50 ?> more.</li><?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2>1. Prepare the file</h2>
        <p>
            Use a CSV file with a header row. Required columns: <strong>first_name</strong>, <strong>last_name</strong>.
            Optional: employee_number, email, position, department, role, date_hired (YYYY-MM-DD).
            Department and role must match existing names; an empty role means Employee.
            An employee number starts with the tag of its role (for example EMP1024 for an Employee, ADM1025 for an Admin);
            leave it empty to get the next free number.
        </p>
        <p class="mb-0"><a class="btn btn-secondary" href="<?= e(url('admin/employee_import.php?template=1')) ?>">Download a template</a></p>
    </section>

    <section class="card">
        <h2>2. Upload it</h2>
        <form method="post" action="<?= e(url('admin/employee_import.php')) ?>" enctype="multipart/form-data" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= IMPORT_MAX_BYTES ?>">
            <div class="field">
                <label for="csv">CSV file</label>
                <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
                <p class="field-help">At most <?= IMPORT_MAX_ROWS ?> employees and 1 MB per file. The file is checked first: if any row has a problem, nothing is imported.</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Import employees</button>
                <a class="btn btn-secondary" href="<?= e(url('admin/employees.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
