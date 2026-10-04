<?php
/**
 * Evaluate one department mate.
 *
 * Steps: 1. Rate (form)  ->  2. Review  ->  3. Submitted (confirmation).
 * The review step re-sends the answers as hidden fields and everything is
 * validated again on the server before saving. After submitting, the browser
 * is redirected (Post/Redirect/Get), so a refresh cannot submit twice; the
 * UNIQUE key on evaluations blocks duplicates at the database level as well.
 *
 * A submitted evaluation is read-only, unless the "allow_edit_until_close"
 * setting is on, in which case it can be changed until the period closes.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('evaluations.submit');

$evaluateeId = is_post() ? post_int('evaluatee_id') : get_int('id');
$period = get_open_period();

if (!$period) {
    flash('info', 'No evaluation period is open, so evaluations cannot be submitted right now.');
    redirect('employee/index.php');
}
$problem = evaluator_problem($user);
if ($problem !== '') {
    flash('error', $problem);
    redirect('employee/index.php');
}
if ($evaluateeId === (int) $user['id']) {
    render_error_page(403, 'You cannot evaluate yourself. Please choose one of your department mates.');
}

$evaluatee = find_peer($user, $evaluateeId, (int) $period['id']);
if (!$evaluatee) {
    render_error_page(404, 'This person is not on your list. You can only evaluate active employees of your own department.');
}

$evaluateeName = full_name($evaluatee);
$categories = form_categories();
$existing = find_evaluation((int) $period['id'], (int) $user['id'], $evaluateeId);
$allowEdit = setting_bool('allow_edit_until_close');
$requireComment = setting_bool('require_comments');
$selfUrl = 'employee/evaluate.php?id=' . $evaluateeId;

$values = $existing ? evaluation_values((int) $existing['id'], $categories) : [];
$comment = (string) ($existing['overall_comment'] ?? '');
$errors = [];

if ($existing && get_int('done') === 1) {
    $step = 'done';
} elseif ($existing && !$allowEdit) {
    $step = 'view';
} else {
    $step = 'form';
}

if (is_post()) {
    if ($existing && !$allowEdit) {
        flash('error', 'You have already evaluated ' . $evaluateeName . ' for this period. Evaluations cannot be changed after they are submitted.');
        redirect($selfUrl);
    }

    $action = post_str('action');
    [$values, $comment, $errors] = read_rating_input($categories);

    if ($action === 'edit') {
        // "Back to edit" from the review step: show the form with the answers kept.
        $errors = [];
        $step = 'form';
    } elseif ($errors) {
        $step = 'form';
    } elseif ($action === 'review') {
        $step = 'review';
    } elseif ($action === 'submit') {
        try {
            save_evaluation($period, $user, $evaluatee, $categories, $values, $comment, $existing);
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                flash('error', 'This evaluation had already been submitted.');
                redirect($selfUrl);
            }
            throw $ex;
        }
        // The audit entry names the period only, never the evaluatee, to keep
        // the log from revealing who evaluated whom.
        audit_log($existing ? 'evaluation_update' : 'evaluation_submit', 'Period: ' . $period['title'] . '.');
        redirect($selfUrl . '&done=1');
    }
}

// The next colleague still waiting for an evaluation (for the confirmation page).
$nextPending = null;
if ($step === 'done') {
    foreach (department_peers($user, (int) $period['id']) as $peer) {
        if ($peer['evaluation_id'] === null) {
            $nextPending = $peer;
            break;
        }
    }
}

$stepTitles = ['form' => 'Evaluate ' . $evaluateeName, 'review' => 'Review your evaluation', 'done' => 'Evaluation submitted', 'view' => 'Your evaluation of ' . $evaluateeName];
$pageTitle = $stepTitles[$step];
$breadcrumbs = [['My Evaluations', url('employee/index.php')], [$evaluateeName, null]];
$navActive = 'employee/index.php';
require __DIR__ . '/../includes/header.php';

/** Prints the radio buttons for one rating item. */
function rating_item(string $key, string $legend, bool $legendHidden, string $categoryName, bool $allowNa, $value, ?string $error): void
{
    ?>
    <fieldset class="rating-item<?= $error ? ' has-error' : '' ?>">
        <legend>
            <?php if ($legendHidden): ?>
                <span class="visually-hidden"><?= e($categoryName) ?>: </span>Your rating
            <?php else: ?>
                <?= e($legend) ?>
            <?php endif; ?>
        </legend>
        <div class="rating-options">
            <?php foreach (RATING_LABELS as $score => $label): ?>
                <label class="rating-option">
                    <input type="radio" name="rating[<?= e($key) ?>]" value="<?= $score ?>" required
                        <?= $value === $score ? 'checked' : '' ?>>
                    <span><strong><?= $score ?></strong><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
            <?php if ($allowNa): ?>
                <label class="rating-option na">
                    <input type="radio" name="rating[<?= e($key) ?>]" value="na" <?= $value === 'na' ? 'checked' : '' ?>>
                    <span><strong>N/A</strong>Not applicable</span>
                </label>
            <?php endif; ?>
        </div>
        <?php if ($error): ?><p class="field-error"><?= e($error) ?></p><?php endif; ?>
    </fieldset>
    <?php
}

/** The answers as a table (used by the review and read-only views). */
function answers_table(array $categories, array $values): void
{
    ?>
    <div class="table-wrap">
        <table class="review-table">
            <thead>
            <tr><th scope="col">Category</th><th scope="col">Item</th><th scope="col">Your rating</th></tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $cat): ?>
                <?php foreach (category_items($cat) as $key => $item): ?>
                    <?php $value = $values[$key] ?? null; ?>
                    <tr>
                        <td><?= e($cat['name']) ?></td>
                        <td><?= $item['criteria_id'] ? e($item['label']) : '<span class="muted">Whole category</span>' ?></td>
                        <td class="rating-cell">
                            <?php if ($value === 'na' || $value === null): ?>
                                Not applicable
                            <?php else: ?>
                                <?= e(rating_text((int) $value)) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$stepNumber = ['form' => 1, 'review' => 2, 'done' => 3, 'view' => 3][$step];
?>

<p class="eval-intro">
    <span><strong><?= e($evaluateeName) ?></strong>, <?= e($evaluatee['position'] ?: 'no position on file') ?></span>
    <span class="muted"><?= e($period['title']) ?></span>
</p>

<?php if ($step !== 'view'): ?>
    <ol class="steps" aria-label="Progress">
        <?php foreach (['Rate', 'Review', 'Submitted'] as $i => $label): ?>
            <?php $n = $i + 1; ?>
            <li class="<?= $n === $stepNumber ? 'current' : ($n < $stepNumber ? 'done' : '') ?>"<?= $n === $stepNumber ? ' aria-current="step"' : '' ?>><?= e($label) ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if ($step === 'form'): ?>

    <?php if ($existing): ?>
        <div class="alert alert-info">
            You submitted this evaluation on <?= e(format_datetime($existing['submitted_at'])) ?>.
            You can change it until the period closes.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <?= isset($errors['form']) ? e($errors['form']) : 'Some answers are missing. Please complete the items marked below.' ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <p class="mb-0">
            Rate <?= e($evaluatee['first_name']) ?> in each category. Your answers are anonymous:
            <?= e($evaluatee['first_name']) ?> will never see who gave which rating or comment.
        </p>
        <ul class="scale-legend mt-1" aria-label="Rating scale">
            <?php foreach (RATING_LABELS as $score => $label): ?>
                <li><strong><?= $score ?></strong> = <?= e($label) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <form method="post" action="<?= e(url($selfUrl)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="evaluatee_id" value="<?= (int) $evaluateeId ?>">

        <?php foreach ($categories as $cat): ?>
            <section class="category-block">
                <h3><?= e($cat['name']) ?></h3>
                <?php if ($cat['description']): ?><p class="category-desc"><?= e($cat['description']) ?></p><?php endif; ?>
                <?php foreach (category_items($cat) as $key => $item): ?>
                    <?php rating_item($key, $item['label'], $item['criteria_id'] === null, $cat['name'], (int) $cat['allow_na'] === 1, $values[$key] ?? null, $errors[$key] ?? null); ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <section class="card">
            <div class="field<?= isset($errors['overall_comment']) ? ' has-error' : '' ?>">
                <label for="overall_comment">
                    Overall comment <?= $requireComment ? '<span class="required-mark">(required)</span>' : '<span class="muted">(optional)</span>' ?>
                </label>
                <textarea id="overall_comment" name="overall_comment" maxlength="2000" data-counter="comment-count"
                          <?= $requireComment ? 'required' : '' ?>><?= e($comment) ?></textarea>
                <p class="field-help" id="comment-count"></p>
                <p class="field-help">Be specific and constructive. The comment is shown without your name.</p>
                <?php if (isset($errors['overall_comment'])): ?>
                    <p class="field-error"><?= e($errors['overall_comment']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" name="action" value="review" class="btn btn-primary">Review my evaluation</button>
                <a class="btn btn-secondary" href="<?= e(url('employee/index.php')) ?>">Cancel</a>
            </div>
        </section>
    </form>

<?php elseif ($step === 'review'): ?>

    <div class="alert alert-info">
        Check your answers. Nothing is saved until you press <strong>Submit evaluation</strong>.
        <?= $allowEdit ? 'You can still change it later, until the period closes.' : 'After submitting you cannot change it.' ?>
    </div>

    <?php answers_table($categories, $values); ?>

    <section class="card">
        <h2>Overall comment</h2>
        <?php if ($comment !== ''): ?>
            <p class="comment-box"><?= e($comment) ?></p>
        <?php else: ?>
            <p class="muted">No comment.</p>
        <?php endif; ?>
    </section>

    <form method="post" action="<?= e(url($selfUrl)) ?>" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="evaluatee_id" value="<?= (int) $evaluateeId ?>">
        <?php foreach ($values as $key => $value): ?>
            <input type="hidden" name="rating[<?= e($key) ?>]" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <textarea name="overall_comment" hidden aria-hidden="true"><?= e($comment) ?></textarea>

        <div class="form-actions">
            <button type="submit" name="action" value="submit" class="btn btn-primary"
                <?= $allowEdit ? '' : 'data-confirm="Submit this evaluation? You cannot change it afterwards."' ?>>Submit evaluation</button>
            <button type="submit" name="action" value="edit" class="btn btn-secondary" formnovalidate>Back to edit</button>
        </div>
    </form>

<?php elseif ($step === 'done'): ?>

    <section class="card">
        <div class="alert alert-success alert-strong" role="status">
            Thank you. Your evaluation of <?= e($evaluateeName) ?> has been recorded.
        </div>
        <p>
            Submitted on <?= e(format_datetime($existing['updated_at'] ?? $existing['submitted_at'])) ?>.
            <?= $allowEdit ? 'You can still change it until the period closes.' : 'It can no longer be changed.' ?>
        </p>
        <div class="actions">
            <?php if ($nextPending): ?>
                <a class="btn btn-primary" href="<?= e(url('employee/evaluate.php?id=' . (int) $nextPending['id'])) ?>">
                    Evaluate next: <?= e(full_name($nextPending)) ?>
                </a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= e(url('employee/index.php')) ?>">Back to My Evaluations</a>
            <?php if ($allowEdit): ?>
                <a class="btn btn-secondary" href="<?= e(url($selfUrl)) ?>">Change this evaluation</a>
            <?php endif; ?>
        </div>
        <?php if (!$nextPending): ?>
            <p class="mt-1 mb-0">You have evaluated everyone in your department for this period.</p>
        <?php endif; ?>
    </section>

<?php else: /* view: read-only */ ?>

    <div class="alert alert-info">
        You submitted this evaluation on <?= e(format_datetime($existing['submitted_at'])) ?>.
        Evaluations cannot be changed after they are submitted.
    </div>

    <?php answers_table($categories, $values); ?>

    <section class="card">
        <h2>Overall comment</h2>
        <?php if ($comment !== ''): ?>
            <p class="comment-box"><?= e($comment) ?></p>
        <?php else: ?>
            <p class="muted">No comment.</p>
        <?php endif; ?>
    </section>

    <p><a class="btn btn-secondary" href="<?= e(url('employee/index.php')) ?>">Back to My Evaluations</a></p>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
