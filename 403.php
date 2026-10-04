<?php
/** Friendly "Access denied" page (also used by Apache through .htaccess). */
require_once __DIR__ . '/includes/bootstrap.php';
render_error_page(403);
