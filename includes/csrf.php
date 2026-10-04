<?php
/**
 * CSRF protection. Every form includes csrf_field(); bootstrap.php calls
 * csrf_verify_request() before any page code runs, so no POST request can
 * change data without a valid token.
 */

/** The token for this session (created on first use). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to place inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Rejects POST requests whose token is missing or wrong. The user is sent back
 * to the same page with a message instead of seeing an error.
 */
function csrf_verify_request(): void
{
    if (!is_post()) {
        return;
    }

    $sent = $_POST['csrf_token'] ?? '';
    if (is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent)) {
        return;
    }

    // An upload larger than post_max_size also arrives with an empty $_POST.
    $tooLarge = empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    flash('error', $tooLarge
        ? 'The file you sent is too large.'
        : 'The form has expired or is invalid. Please try again.');
    redirect(csrf_return_path());
}

/**
 * Where to send the user after a rejected form: the page the form was on
 * (from the Referer header, only when it is this same site), else the URL
 * the form was posted to.
 */
function csrf_return_path(): string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $parts = $referer !== '' ? parse_url($referer) : false;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($parts && isset($parts['host'], $parts['path']) && $host !== ''
        && strcasecmp($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $host) === 0
        && str_starts_with($parts['path'], base_path() . '/')) {
        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    return current_request_uri();
}
