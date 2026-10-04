<?php
/**
 * Login page: Employee Number + 4-digit PIN.
 * Lockout, hashing and audit logging are handled by attempt_login() in includes/auth.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';

// Already logged in: go straight to the user's home page.
$user = current_user();
if ($user) {
    redirect((int) $user['must_change_pin'] === 1 ? 'change_pin.php' : home_path($user));
}

$error = '';
$employeeNumber = '';

if (is_post()) {
    $employeeNumber = mb_substr(post_str('employee_number'), 0, 20);
    $pin = $_POST['pin'] ?? '';

    $result = attempt_login($employeeNumber, is_string($pin) ? $pin : '');
    if ($result['ok']) {
        $user = current_user();
        redirect((int) $user['must_change_pin'] === 1 ? 'change_pin.php' : home_path($user));
    }
    $error = $result['error'];
}

$pageTitle = 'Log in';
$layout = 'plain';
$hideTitle = true;
require __DIR__ . '/includes/header.php';
?>

<section class="card login-card">
    <h1><?= e(APP_SHORT_NAME) ?></h1>
    <p class="login-company"><?= e(setting('company_name', '')) ?></p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('index.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="employee_number">Employee Number</label>
            <input type="text" id="employee_number" name="employee_number" value="<?= e($employeeNumber) ?>"
                   maxlength="20" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus>
        </div>

        <div class="field">
            <label for="pin">PIN</label>
            <input type="password" id="pin" name="pin" class="pin-input" inputmode="numeric" pattern="[0-9]{4}"
                   minlength="4" maxlength="4" autocomplete="current-password" required data-pin
                   aria-describedby="pin-help">
            <p class="field-help" id="pin-help">Your 4-digit PIN.</p>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>

    <p class="login-note">
        After <?= setting_int('max_login_attempts', 5) ?> wrong PINs in a row the account is locked for
        <?= setting_int('lockout_minutes', 15) ?> minutes. Forgot your PIN? Ask HR to reset it.
    </p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
