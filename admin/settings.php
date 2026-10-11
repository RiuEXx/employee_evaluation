<?php
/**
 * Settings. The form is built from the `settings` table: each row says its
 * input type (bool, int, text, select) and limits, so a new setting only
 * needs a new row in the database.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_permission('settings.manage');

$settings = db()->query('SELECT * FROM settings ORDER BY sort_order, setting_key')->fetchAll();

/** Turns "default:Use the default PIN|random:Generate" into [value => label]. */
function setting_choices(?string $options): array
{
    $choices = [];
    foreach (explode('|', (string) $options) as $pair) {
        [$value, $label] = array_pad(explode(':', $pair, 2), 2, '');
        if ($value !== '') {
            $choices[$value] = $label !== '' ? $label : $value;
        }
    }
    return $choices;
}

$values = array_column($settings, 'setting_value', 'setting_key');
$errors = [];

if (is_post()) {
    $new = [];
    foreach ($settings as $s) {
        $key = $s['setting_key'];
        $raw = post_str('s_' . $key);

        switch ($s['input_type']) {
            case 'bool':
                $new[$key] = isset($_POST['s_' . $key]) ? '1' : '0';
                break;
            case 'int':
                // Digits only: no sign, decimal point, exponent ("e") or other characters.
                if (!preg_match('/^\d{1,6}$/', $raw)
                    || ($s['min_value'] !== null && (int) $raw < (int) $s['min_value'])
                    || ($s['max_value'] !== null && (int) $raw > (int) $s['max_value'])) {
                    $errors[$key] = 'Enter a whole number from ' . $s['min_value'] . ' to ' . $s['max_value'] . '.';
                    $new[$key] = $raw;
                } else {
                    $new[$key] = (string) (int) $raw; // "007" is saved as "7"
                }
                break;
            case 'select':
                if (!array_key_exists($raw, setting_choices($s['options']))) {
                    $errors[$key] = 'Choose one of the options.';
                }
                $new[$key] = $raw;
                break;
            default:
                if (mb_strlen($raw) > 255) {
                    $errors[$key] = 'At most 255 characters.';
                } elseif (has_emoji($raw)) {
                    $errors[$key] = 'Emoji are not allowed. Use plain text only.';
                }
                $new[$key] = $raw;
        }
    }

    // Rules for particular settings.
    if (!isset($errors['default_pin']) && !is_valid_pin($new['default_pin'] ?? '')) {
        $errors['default_pin'] = 'The default PIN must be exactly 4 digits.';
    }
    if (!isset($errors['company_name']) && trim($new['company_name'] ?? '') === '') {
        $errors['company_name'] = 'Enter the company name.';
    }

    $values = $new;

    if (!$errors) {
        $changed = [];
        $update = db()->prepare('UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?');
        foreach ($settings as $s) {
            $key = $s['setting_key'];
            if ($new[$key] !== $s['setting_value']) {
                $update->execute([$new[$key], $key]);
                // The default PIN itself is not written to the log.
                $changed[] = $key === 'default_pin' ? 'default_pin (changed)' : $key . ' = ' . $new[$key];
            }
        }
        if ($changed) {
            audit_log('settings_update', implode(', ', $changed));
            flash('success', 'Settings saved.');
        } else {
            flash('info', 'Nothing changed.');
        }
        redirect('admin/settings.php');
    }
}

$pageTitle = 'Settings';
$breadcrumbs = [['Administration', url('admin/index.php')], ['Settings', null]];
require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">Nothing was saved. Please correct the fields marked below.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/settings.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <section class="card">
        <?php foreach ($settings as $s): ?>
            <?php
            $key = $s['setting_key'];
            $field = 's_' . $key;
            $value = $values[$key] ?? $s['setting_value'];
            $error = $errors[$key] ?? '';
            ?>
            <div class="field<?= $error ? ' has-error' : '' ?>">
                <?php if ($s['input_type'] === 'bool'): ?>
                    <label class="checkbox">
                        <input type="checkbox" id="<?= e($field) ?>" name="<?= e($field) ?>" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                        <span><strong><?= e($s['label']) ?></strong></span>
                    </label>
                <?php else: ?>
                    <label for="<?= e($field) ?>"><?= e($s['label']) ?></label>
                    <?php if ($s['input_type'] === 'select'): ?>
                        <select id="<?= e($field) ?>" name="<?= e($field) ?>">
                            <?= select_options(setting_choices($s['options']), $value) ?>
                        </select>
                    <?php elseif ($s['input_type'] === 'int'): ?>
                        <input type="text" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($value) ?>"
                               inputmode="numeric" pattern="[0-9]*" maxlength="<?= strlen((string) $s['max_value']) ?>"
                               data-digits data-min="<?= e($s['min_value']) ?>" data-max="<?= e($s['max_value']) ?>"
                               autocomplete="off" class="narrow-input">
                    <?php elseif ($key === 'default_pin'): ?>
                        <input type="text" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($value) ?>"
                               inputmode="numeric" pattern="[0-9]{4}" maxlength="4" data-pin class="narrow-input" autocomplete="off">
                    <?php else: ?>
                        <input type="text" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($value) ?>" maxlength="255">
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($s['description']): ?><p class="field-help"><?= e($s['description']) ?></p><?php endif; ?>
                <?php if ($error): ?><p class="field-error"><?= e($error) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/settings.php')) ?>">Undo changes</a>
        </div>
    </section>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
