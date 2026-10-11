<?php
/**
 * Loaded first by every page. Sets up configuration, error handling and the
 * system log, the session, the inactivity timeout and the CSRF check for POST
 * requests.
 */

require_once __DIR__ . '/../config/constants.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

// The system log comes before everything else, so problems in the rest are recorded.
require_once __DIR__ . '/logger.php';
set_error_handler('log_php_error');
register_shutdown_function('log_fatal_error');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/evaluation.php';

set_exception_handler('handle_uncaught_exception');

send_security_headers();
start_secure_session();
enforce_session_timeout();

// Every POST request in the application must carry a valid CSRF token.
csrf_verify_request();
