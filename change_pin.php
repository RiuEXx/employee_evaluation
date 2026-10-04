<?php
/**
 * Change PIN. Every user can change their own PIN here. Users with
 * must_change_pin = 1 (new accounts and reset PINs) are sent here after login
 * and cannot open any other page until they have chosen a new PIN.
 */
define('PIN_CHANGE_PAGE', true); // lets require_login() allow this page during a forced change
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$forced = (int) $user['must_change_pin'] === 1;
$errors = [];

if (is_post()) {
    $current = $_POST['current_pin'] ?? '';
    $new = $_POST['new_pin'] ?? '';
    $confirm = $_POST['confirm_pin'] ?? '';
    $current = is_string($current) ? $current : '';
    $new = is_string($new) ? $new : '';
    $confirm = is_string($confirm) ? $confirm : '';

    $stmt = db()->prepare('SELECT pin_hash FROM employees WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!is_valid_pin($current) || !password_verify($current, $hash)) {
        $errors['current_pin'] = 'Your current PIN is not correct.';
        audit_log('pin_change_failed', 'Wrong current PIN on the Change PIN page.');
    }

    if (!is_valid_pin($new)) {
        $errors['new_pin'] = 'The new PIN must be exactly 4 digits.';
    } elseif ($new === setting('default_pin', '1234')) {
        $errors['new_pin'] = 'The new PIN cannot be the default PIN.';
    } elseif (preg_match('/^(\d)\1{3}$/', $new)) {
        $errors['new_pin'] = 'The new PIN cannot be four identical digits.';
    } elseif (!isset($errors['current_pin']) && $new === $current) {
        $errors['new_pin'] = 'The new PIN must be different from your current PIN.';
    }

    if (!isset($errors['new_pin']) && $confirm !== $new) {
        $errors['confirm_pin'] = 'The two new PINs do not match.';
    }

    if (!$errors) {
        db()->prepare('UPDATE employees SET pin_hash = ?, must_change_pin = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        audit_log('pin_change', $forced ? 'Changed the PIN at first login (required).' : 'Changed the PIN.');

        // New session id after a credential change.
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();

        flash('success', 'Your PIN has been changed.');
        redirect(home_path(current_user(true)));
    }
}

$pageTitle = 'Change PIN';
$layout = $forced ? 'plain' : 'app';
$hideTitle = $forced;
require __DIR__ . '/includes/header.php';

/** One PIN field with its label, help text and error. */
function pin_field(string $name, string $label, array $errors, string $help = '', string $autocomplete = 'new-password'): void
{
    $error = $errors[$name] ?? '';
    ?>
    <div class="field<?= $error ? ' has-error' : '' ?>">
        <label for="<?= e($name) ?>"><?= e($label) ?></label>
        <input type="password" id="<?= e($name) ?>" name="<?= e($name) ?>" class="pin-input" inputmode="numeric"
               pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="<?= e($autocomplete) ?>" required data-pin
               <?= $error ? 'aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '' ?>>
        <?php if ($help !== ''): ?><p class="field-help"><?= e($help) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="field-error" id="<?= e($name) ?>-error"><?= e($error) ?></p><?php endif; ?>
    </div>
    <?php
}
?>

<section class="card <?= $forced ? 'login-card' : 'narrow-card' ?>">
    <?php if ($forced): ?>
        <h1>Choose a new PIN</h1>
        <p class="login-company">Welcome, <?= e($user['first_name']) ?>.</p>
        <div class="alert alert-info">
            For your security you must replace the PIN you were given before you continue.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">Please correct the fields marked below.</div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('change_pin.php')) ?>" novalidate data-once>
        <?= csrf_field() ?>
        <?php pin_field('current_pin', 'Current PIN', $errors, $forced ? 'The PIN you just logged in with.' : '', 'current-password'); ?>
        <?php pin_field('new_pin', 'New PIN', $errors, '4 digits. Not the default PIN, not your current PIN, and not four identical digits.'); ?>
        <?php pin_field('confirm_pin', 'Confirm new PIN', $errors); ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Change PIN</button>
            <?php if (!$forced): ?>
                <a class="btn btn-secondary" href="<?= e(url(home_path($user))) ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($forced): ?>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="login-note">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary btn-small">Log out instead</button>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
