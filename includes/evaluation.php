<?php
/**
 * Evaluation rules and result calculations, shared by the employee, CEO and
 * admin pages.
 *
 * Who evaluates whom:
 *   - An evaluator is an active employee whose role has `evaluations.submit`
 *     and who belongs to an active department.
 *   - They evaluate every other active employee of that department whose role
 *     has `evaluations.receive`. Never themselves, never another department.
 *   - One evaluation per evaluator, evaluatee and period (a UNIQUE key in the
 *     database enforces this too).
 *
 * How results are calculated (see score_rollup()):
 *   1. Inside one evaluation, a category score is the average of its ratings
 *      (one rating, or one per criterion). "Not applicable" adds nothing.
 *   2. A category average is the average of those per-evaluation scores.
 *   3. The overall score is the weighted average of the category averages,
 *      using each category's weight.
 *   An employee's averages stay hidden until they have at least the number of
 *   evaluations set in "min_evaluations_for_average", to protect anonymity.
 */

// ---------------------------------------------------------------------------
// Roles, periods, departments, employees, categories
// ---------------------------------------------------------------------------

/**
 * Ids of the roles that have a permission, as a list for SQL "IN (...)".
 * The ids are integers read from the database, so inlining them is safe.
 */
function role_ids_with(string $permission): string
{
    static $cache = [];
    if (!isset($cache[$permission])) {
        $stmt = db()->prepare(
            'SELECT rp.role_id FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = ?'
        );
        $stmt->execute([$permission]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $cache[$permission] = $ids ? implode(',', $ids) : '0';
    }
    return $cache[$permission];
}

function get_open_period(): ?array
{
    $row = db()->query("SELECT * FROM evaluation_periods WHERE status = 'open' LIMIT 1")->fetch();
    return $row ?: null;
}

function find_period(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM evaluation_periods WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Periods that can have results (open or closed), newest first. */
function result_periods(): array
{
    return db()->query(
        "SELECT * FROM evaluation_periods WHERE status IN ('open', 'closed') ORDER BY start_date DESC, id DESC"
    )->fetchAll();
}

/** The period chosen with ?period=, else the open period, else the newest one. */
function selected_period(array $periods): ?array
{
    $wanted = get_int('period');
    foreach ($periods as $p) {
        if ((int) $p['id'] === $wanted) {
            return $p;
        }
    }
    foreach ($periods as $p) {
        if ($p['status'] === 'open') {
            return $p;
        }
    }
    return $periods[0] ?? null;
}

/** "Q3 2026 Performance Review (Open)" style label for period pickers. */
function period_label(array $period): string
{
    return $period['title'] . ' (' . ucfirst($period['status']) . ')';
}

/** id => label pairs for a period <select>. */
function period_options(array $periods): array
{
    $options = [];
    foreach ($periods as $p) {
        $options[$p['id']] = period_label($p);
    }
    return $options;
}

/** The "Evaluation period" dropdown used in the filter bar of result pages. */
function period_select_field(array $periods, ?array $selected): string
{
    return '<div class="field field-wide"><label for="period">Evaluation period</label>'
        . '<select id="period" name="period" data-autosubmit>'
        . select_options(period_options($periods), $selected['id'] ?? '')
        . '</select></div>';
}

/** Number of active employees per department id. */
function department_headcounts(): array
{
    $rows = db()->query(
        "SELECT department_id, COUNT(*) AS total FROM employees
         WHERE status = 'active' AND department_id IS NOT NULL
         GROUP BY department_id"
    )->fetchAll();
    return array_map('intval', array_column($rows, 'total', 'department_id'));
}

/** Every employee keyed by id, with the department name, for result tables. */
function employees_by_id(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $rows = db()->query(
            'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.position, e.status,
                    e.department_id, d.name AS department_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id'
        )->fetchAll();
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
    }
    return $map;
}

function departments_list(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM departments' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';
    return db()->query($sql)->fetchAll();
}

function find_department(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM departments WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** One employee with role and department names (never the PIN hash). */
function find_employee(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.email, e.position, e.department_id,
                e.role_id, e.must_change_pin, e.status, e.failed_attempts, e.locked_until, e.last_login,
                e.date_hired, e.created_at, r.name AS role_name, d.name AS department_name
         FROM employees e
         JOIN roles r ON r.id = e.role_id
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE e.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Employee numbers: the role's tag followed by digits, e.g. CEO1001, ADM1002,
// EMP1003. The tag is roles.number_prefix, so it always matches the role; the
// digits are unique on their own, so a change of role (EMP1005 becomes
// ADM1005) never takes a number someone else has.
// ---------------------------------------------------------------------------

/**
 * Employee numbers are shown only to people who manage employees (HR). Other
 * users, the CEO included, see names only.
 */
function can_see_employee_numbers(): bool
{
    return can('employees.manage');
}

/** The digits of a tagged employee number ("ADM1002" gives "1002"), or '' for any other format. */
function employee_number_digits(string $number): string
{
    return preg_match('/^[A-Za-z]{2,5}(\d{' . EMPLOYEE_NUMBER_MIN_DIGITS . ',6})$/', $number, $m) ? $m[1] : '';
}

/** The next free digits: one more than the highest number in use, e.g. "1024". */
function next_employee_digits(): string
{
    $highest = 1000; // the first employee gets 1001
    foreach (db()->query('SELECT employee_number FROM employees')->fetchAll(PDO::FETCH_COLUMN) as $number) {
        if (preg_match('/(\d+)$/', $number, $m)) {
            $highest = max($highest, (int) $m[1]);
        }
    }
    return str_pad((string) ($highest + 1), EMPLOYEE_NUMBER_MIN_DIGITS, '0', STR_PAD_LEFT);
}

/** Why $digits cannot be used for an employee number, or '' when it can. $exceptId is the employee being edited. */
function employee_digits_problem(string $digits, int $exceptId = 0): string
{
    if (!preg_match('/^\d{' . EMPLOYEE_NUMBER_MIN_DIGITS . ',6}$/', $digits)) {
        return 'Enter ' . EMPLOYEE_NUMBER_MIN_DIGITS . ' to 6 digits, for example ' . next_employee_digits() . '.';
    }
    // "1005" and "01005" count as the same number.
    $stmt = db()->prepare('SELECT employee_number FROM employees WHERE id <> ? AND employee_number REGEXP ? LIMIT 1');
    $stmt->execute([$exceptId, '^[A-Za-z]+0*' . ltrim($digits, '0') . '$']);
    $taken = $stmt->fetchColumn();
    return $taken !== false ? 'The number ' . $digits . ' is already used by ' . $taken . '.' : '';
}

/** Every category (active or not) keyed by id, in display order. */
function categories_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $rows = db()->query('SELECT * FROM evaluation_categories ORDER BY sort_order, name')->fetchAll();
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
    }
    return $map;
}

/** Active categories, each with its active criteria, for the evaluation form. */
function form_categories(): array
{
    $categories = db()->query(
        'SELECT * FROM evaluation_categories WHERE is_active = 1 ORDER BY sort_order, name'
    )->fetchAll();
    $criteria = db()->query(
        'SELECT * FROM evaluation_criteria WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll();

    $byCategory = [];
    foreach ($criteria as $c) {
        $byCategory[(int) $c['category_id']][] = $c;
    }
    foreach ($categories as &$cat) {
        $cat['criteria'] = $byCategory[(int) $cat['id']] ?? [];
    }
    unset($cat);

    return $categories;
}

/**
 * The rating items of a category on the form: one per active criterion, or
 * the category itself when it has none. Keys are "k<criteria id>" or
 * "c<category id>", the names used in the form's rating[...] fields.
 */
function category_items(array $category): array
{
    if (!empty($category['criteria'])) {
        $items = [];
        foreach ($category['criteria'] as $c) {
            $items['k' . $c['id']] = ['label' => $c['question_text'], 'criteria_id' => (int) $c['id']];
        }
        return $items;
    }
    return ['c' . $category['id'] => ['label' => $category['name'], 'criteria_id' => null]];
}

// ---------------------------------------------------------------------------
// Evaluating department mates
// ---------------------------------------------------------------------------

/** Why this user cannot evaluate anyone right now, or '' when they can. */
function evaluator_problem(array $user): string
{
    if (!in_array('evaluations.submit', $user['permissions'], true)) {
        return 'Your role does not take part in peer evaluations.';
    }
    if (empty($user['department_id'])) {
        return 'You are not assigned to a department, so there is no one for you to evaluate.';
    }
    if ((int) $user['department_active'] !== 1) {
        return 'Your department is inactive, so it does not take part in evaluations.';
    }
    return '';
}

/**
 * The department mates a user can evaluate, with the evaluation they already
 * submitted in $periodId (evaluation_id is null while pending).
 */
function department_peers(array $user, ?int $periodId): array
{
    if (evaluator_problem($user) !== '') {
        return [];
    }
    $receive = role_ids_with('evaluations.receive');
    $stmt = db()->prepare(
        "SELECT ee.id, ee.employee_number, ee.first_name, ee.last_name, ee.position,
                ev.id AS evaluation_id, ev.submitted_at, ev.updated_at
         FROM employees ee
         LEFT JOIN evaluations ev
                ON ev.period_id = :period AND ev.evaluator_id = :me AND ev.evaluatee_id = ee.id
         WHERE ee.department_id = :dept
           AND ee.id <> :me2
           AND ee.status = 'active'
           AND ee.role_id IN ($receive)
         ORDER BY ee.last_name, ee.first_name"
    );
    $stmt->execute([
        'period' => $periodId ?? 0,
        'me'     => $user['id'],
        'dept'   => $user['department_id'],
        'me2'    => $user['id'],
    ]);
    return $stmt->fetchAll();
}

/** A department mate the user is allowed to evaluate, or null. */
function find_peer(array $user, int $evaluateeId, ?int $periodId): ?array
{
    foreach (department_peers($user, $periodId) as $peer) {
        if ((int) $peer['id'] === $evaluateeId) {
            return $peer;
        }
    }
    return null;
}

function find_evaluation(int $periodId, int $evaluatorId, int $evaluateeId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM evaluations WHERE period_id = ? AND evaluator_id = ? AND evaluatee_id = ?'
    );
    $stmt->execute([$periodId, $evaluatorId, $evaluateeId]);
    return $stmt->fetch() ?: null;
}

/**
 * The stored ratings of an evaluation as form values: ['c2' => 4, 'k1' => 5].
 * Items of a category that allows N/A and has no rating become 'na'.
 */
function evaluation_values(int $evaluationId, array $categories): array
{
    $stmt = db()->prepare('SELECT category_id, criteria_id, score FROM evaluation_scores WHERE evaluation_id = ?');
    $stmt->execute([$evaluationId]);

    $values = [];
    foreach ($stmt->fetchAll() as $s) {
        $key = $s['criteria_id'] !== null ? 'k' . $s['criteria_id'] : 'c' . $s['category_id'];
        $values[$key] = (int) $s['score'];
    }
    foreach ($categories as $cat) {
        if ((int) $cat['allow_na'] === 1) {
            foreach (category_items($cat) as $key => $item) {
                $values[$key] = $values[$key] ?? 'na';
            }
        }
    }
    return $values;
}

/**
 * Reads and validates the posted ratings and comment.
 * Returns [values, comment, errors]; values are key => 1..5 or 'na'.
 */
function read_rating_input(array $categories): array
{
    $posted = $_POST['rating'] ?? [];
    $posted = is_array($posted) ? $posted : [];
    $values = [];
    $errors = [];
    $rated = 0;

    foreach ($categories as $cat) {
        foreach (category_items($cat) as $key => $item) {
            $raw = $posted[$key] ?? '';
            $raw = is_string($raw) ? $raw : '';
            if ($raw === 'na' && (int) $cat['allow_na'] === 1) {
                $values[$key] = 'na';
            } elseif (preg_match('/^[1-5]$/', $raw)) {
                $values[$key] = (int) $raw;
                $rated++;
            } else {
                $errors[$key] = 'Choose a rating.';
            }
        }
    }

    if ($errors === [] && $rated === 0) {
        $errors['form'] = 'Give at least one rating; not every item can be Not Applicable.';
    }

    $comment = post_str('overall_comment');
    if (mb_strlen($comment) > 2000) {
        $errors['overall_comment'] = 'The comment can be at most 2000 characters.';
    } elseif (has_emoji($comment)) {
        $errors['overall_comment'] = 'Emoji are not allowed. Use plain text only.';
    } elseif ($comment === '' && setting_bool('require_comments')) {
        $errors['overall_comment'] = 'A comment is required.';
    }

    return [$values, $comment, $errors];
}

/**
 * Saves a new or edited evaluation and its ratings in one transaction and
 * returns its id. A duplicate submission fails on the UNIQUE key (error 1062),
 * which the caller turns into a friendly message.
 */
function save_evaluation(array $period, array $user, array $evaluatee, array $categories,
                         array $values, string $comment, ?array $existing): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            $evaluationId = (int) $existing['id'];
            $pdo->prepare('UPDATE evaluations SET overall_comment = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$comment !== '' ? $comment : null, $evaluationId]);
            $pdo->prepare('DELETE FROM evaluation_scores WHERE evaluation_id = ?')->execute([$evaluationId]);
        } else {
            $pdo->prepare(
                'INSERT INTO evaluations (period_id, evaluator_id, evaluatee_id, department_id, overall_comment, submitted_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([
                $period['id'], $user['id'], $evaluatee['id'], $user['department_id'],
                $comment !== '' ? $comment : null,
            ]);
            $evaluationId = (int) $pdo->lastInsertId();
        }

        $insert = $pdo->prepare(
            'INSERT INTO evaluation_scores (evaluation_id, category_id, criteria_id, score) VALUES (?, ?, ?, ?)'
        );
        foreach ($categories as $cat) {
            foreach (category_items($cat) as $key => $item) {
                $value = $values[$key] ?? 'na';
                if ($value !== 'na') {
                    $insert->execute([$evaluationId, $cat['id'], $item['criteria_id'], $value]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return $evaluationId;
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

function min_evaluations(): int
{
    return max(1, setting_int('min_evaluations_for_average', 3));
}

function enough_evaluations(int $count): bool
{
    return $count >= min_evaluations();
}

/** Shown instead of an average that is hidden to protect anonymity. */
function not_enough_text(int $count): string
{
    return 'Not enough evaluations (' . $count . ' of ' . min_evaluations() . ' needed)';
}

/**
 * Average scores of the evaluations matching $where, grouped by $groupBy.
 *
 * $where   : any of period_id, evaluatee_id, department_id, evaluator_id => int
 * $groupBy : any of period_id, evaluatee_id, department_id
 *
 * Returns entries keyed by the group values joined with ":" ('' when not
 * grouped), each: ['keys' => [column => id], 'evaluations' => int,
 * 'categories' => [category_id => average], 'overall' => float|null].
 */
function score_rollup(array $where = [], array $groupBy = []): array
{
    $allowedWhere = ['period_id', 'evaluatee_id', 'department_id', 'evaluator_id'];
    $groupBy = array_values(array_intersect(['period_id', 'evaluatee_id', 'department_id'], $groupBy));

    $conditions = [];
    $params = [];
    foreach ($where as $column => $value) {
        if (in_array($column, $allowedWhere, true)) {
            $conditions[] = "e.$column = :w_$column";
            $params["w_$column"] = (int) $value;
        }
    }
    $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $innerGroup = implode('', array_map(fn($c) => "e.$c, ", $groupBy));
    $outerGroup = implode('', array_map(fn($c) => "x.$c, ", $groupBy));

    // Step 1 (inner query): one score per evaluation and category.
    // Step 2 (outer query): average those per group and category.
    $stmt = db()->prepare(
        "SELECT {$outerGroup}x.category_id, AVG(x.cat_score) AS avg_score
         FROM (
             SELECT {$innerGroup}s.evaluation_id, s.category_id, AVG(s.score) AS cat_score
             FROM evaluation_scores s
             JOIN evaluations e ON e.id = s.evaluation_id
             $whereSql
             GROUP BY {$innerGroup}s.evaluation_id, s.category_id
         ) x
         GROUP BY {$outerGroup}x.category_id"
    );
    $stmt->execute($params);
    $categoryRows = $stmt->fetchAll();

    $groupSelect = implode(', ', array_map(fn($c) => "e.$c", $groupBy));
    $stmt = db()->prepare(
        'SELECT ' . ($groupSelect !== '' ? $groupSelect . ', ' : '') . 'COUNT(*) AS evaluations
         FROM evaluations e ' . $whereSql
        . ($groupSelect !== '' ? ' GROUP BY ' . $groupSelect : '')
    );
    $stmt->execute($params);
    $countRows = $stmt->fetchAll();

    $result = [];
    $keyOf = function (array $row) use ($groupBy): string {
        return implode(':', array_map(fn($c) => (int) $row[$c], $groupBy));
    };

    foreach ($countRows as $row) {
        if ((int) $row['evaluations'] === 0) {
            continue;
        }
        $keys = [];
        foreach ($groupBy as $c) {
            $keys[$c] = (int) $row[$c];
        }
        $result[$keyOf($row)] = [
            'keys'        => $keys,
            'evaluations' => (int) $row['evaluations'],
            'categories'  => [],
            'overall'     => null,
        ];
    }

    // Keep the categories in display order.
    $order = array_flip(array_keys(categories_map()));
    usort($categoryRows, fn($a, $b) => ($order[(int) $a['category_id']] ?? 0) <=> ($order[(int) $b['category_id']] ?? 0));

    foreach ($categoryRows as $row) {
        $key = $keyOf($row);
        if (isset($result[$key])) {
            $result[$key]['categories'][(int) $row['category_id']] = (float) $row['avg_score'];
        }
    }
    foreach ($result as &$entry) {
        $entry['overall'] = weighted_overall($entry['categories']);
    }
    unset($entry);

    return $result;
}

/** One group from score_rollup(), or an empty entry when it has no evaluations. */
function rollup_entry(array $rollup, string $key = ''): array
{
    return $rollup[$key] ?? ['keys' => [], 'evaluations' => 0, 'categories' => [], 'overall' => null];
}

/** Weighted average of category averages, using each category's weight. */
function weighted_overall(array $categoryAverages): ?float
{
    $categories = categories_map();
    $sum = 0.0;
    $weights = 0.0;
    foreach ($categoryAverages as $id => $average) {
        $weight = (float) ($categories[$id]['weight'] ?? 1);
        $sum += $average * $weight;
        $weights += $weight;
    }
    return $weights > 0 ? $sum / $weights : null;
}

/**
 * Overall score per period for the evaluations matching $where, oldest period
 * first: [['period' => row, 'evaluations' => int, 'overall' => float|null]].
 */
function score_trend(array $where): array
{
    $rollup = score_rollup($where, ['period_id']);
    $trend = [];
    foreach (array_reverse(result_periods()) as $period) {
        $entry = rollup_entry($rollup, (string) $period['id']);
        $trend[] = ['period' => $period, 'evaluations' => $entry['evaluations'], 'overall' => $entry['overall']];
    }
    return $trend;
}

/**
 * The department an employee belonged to when they were evaluated in a period
 * (the most common one, in case of a transfer during the period).
 */
function evaluation_department(int $periodId, int $evaluateeId): ?int
{
    $stmt = db()->prepare(
        'SELECT department_id FROM evaluations
         WHERE period_id = ? AND evaluatee_id = ?
         GROUP BY department_id ORDER BY COUNT(*) DESC LIMIT 1'
    );
    $stmt->execute([$periodId, $evaluateeId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Comments an employee received in a period. The order is a fixed shuffle,
 * not the submission time, which could hint at who wrote each comment.
 * Evaluator names are only selected when $withNames is true.
 */
function received_comments(int $periodId, int $evaluateeId, bool $withNames = false): array
{
    $names = $withNames ? ", CONCAT(ev.first_name, ' ', ev.last_name) AS evaluator_name" : '';
    $stmt = db()->prepare(
        "SELECT e.overall_comment AS comment $names
         FROM evaluations e
         JOIN employees ev ON ev.id = e.evaluator_id
         WHERE e.period_id = ? AND e.evaluatee_id = ?
           AND e.overall_comment IS NOT NULL AND e.overall_comment <> ''
         ORDER BY SHA1(CONCAT(e.id, '-', e.evaluatee_id))"
    );
    $stmt->execute([$periodId, $evaluateeId]);
    return $stmt->fetchAll();
}

/**
 * Each evaluation an employee received in a period, as category scores, in a
 * fixed shuffled order and without any evaluator data.
 */
function individual_scores(int $periodId, int $evaluateeId): array
{
    $stmt = db()->prepare(
        'SELECT s.evaluation_id, s.category_id, AVG(s.score) AS score
         FROM evaluation_scores s
         JOIN evaluations e ON e.id = s.evaluation_id
         WHERE e.period_id = ? AND e.evaluatee_id = ?
         GROUP BY s.evaluation_id, s.category_id
         ORDER BY SHA1(CONCAT(s.evaluation_id, \'-\', e.evaluatee_id)), s.category_id'
    );
    $stmt->execute([$periodId, $evaluateeId]);

    $evaluations = [];
    foreach ($stmt->fetchAll() as $row) {
        $evaluations[(int) $row['evaluation_id']][(int) $row['category_id']] = (float) $row['score'];
    }
    return array_values($evaluations);
}

/** Adds a 'rank' to rows sorted best first; equal scores share a rank. */
function add_ranks(array $rows, string $scoreKey): array
{
    $rank = 0;
    $previous = null;
    foreach ($rows as $i => &$row) {
        $score = $row[$scoreKey];
        if ($score === null) {
            $row['rank'] = null;
            continue;
        }
        $rounded = round($score, 2);
        if ($previous === null || $rounded !== $previous) {
            $rank = $i + 1;
            $previous = $rounded;
        }
        $row['rank'] = $rank;
    }
    unset($row);
    return $rows;
}

// ---------------------------------------------------------------------------
// Completion
// ---------------------------------------------------------------------------

/**
 * Evaluation progress per evaluator in a period, measured against the current
 * department membership: expected = colleagues the evaluator should rate,
 * done = how many of them they have rated in this period.
 */
function completion_rows(int $periodId, ?int $departmentId = null): array
{
    $submit = role_ids_with('evaluations.submit');
    $receive = role_ids_with('evaluations.receive');
    $params = ['period' => $periodId];
    $deptSql = '';
    if ($departmentId) {
        $deptSql = ' AND ev.department_id = :dept';
        $params['dept'] = $departmentId;
    }

    $stmt = db()->prepare(
        "SELECT ev.id, ev.employee_number, ev.first_name, ev.last_name, ev.position,
                d.id AS department_id, d.name AS department_name,
                COUNT(ee.id) AS expected, COUNT(e.id) AS done
         FROM employees ev
         JOIN departments d ON d.id = ev.department_id AND d.is_active = 1
         JOIN employees ee ON ee.department_id = ev.department_id
                          AND ee.id <> ev.id
                          AND ee.status = 'active'
                          AND ee.role_id IN ($receive)
         LEFT JOIN evaluations e ON e.period_id = :period AND e.evaluator_id = ev.id AND e.evaluatee_id = ee.id
         WHERE ev.status = 'active' AND ev.role_id IN ($submit) $deptSql
         GROUP BY ev.id, ev.employee_number, ev.first_name, ev.last_name, ev.position, d.id, d.name
         ORDER BY d.name, ev.last_name, ev.first_name"
    );
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['expected'] = (int) $row['expected'];
        $row['done'] = (int) $row['done'];
        $row['rate'] = $row['expected'] > 0 ? $row['done'] / $row['expected'] : 0.0;
        $row['status'] = $row['done'] === 0 ? 'Not started' : ($row['done'] >= $row['expected'] ? 'Complete' : 'In progress');
        $row['name'] = full_name($row);
    }
    unset($row);
    return $rows;
}

/** Totals for a set of completion rows. */
function completion_totals(array $rows): array
{
    $expected = array_sum(array_column($rows, 'expected'));
    $done = array_sum(array_column($rows, 'done'));
    $finished = count(array_filter($rows, fn($r) => $r['done'] >= $r['expected']));
    return [
        'evaluators' => count($rows),
        'finished'   => $finished,
        'expected'   => $expected,
        'done'       => $done,
        'rate'       => $expected > 0 ? $done / $expected : null,
    ];
}

/** Completion totals per department id. */
function completion_by_department(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $groups[(int) $row['department_id']][] = $row;
    }
    $result = [];
    foreach ($groups as $deptId => $deptRows) {
        $result[$deptId] = completion_totals($deptRows) + ['name' => $deptRows[0]['department_name']];
    }
    return $result;
}
