<?php
// =========================================================
// CONFIG LOADER
//
// The real config (with DB credentials + API key) lives OUTSIDE the web root
// so it can never be served as a URL. This stub searches common paths and
// includes the first one it finds.
//
// Preferred server location:
//   /home/<user>/config/config.php   (one level above public_html)
//
// Local dev fallback:
//   <this-dir>/config.local.php      (gitignored)
// =========================================================

$candidates = [
    // cPanel-style: /home/USER/public_html/wp/  →  /home/USER/config/
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
    // Nested one deeper (if wp is inside another subfolder)
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
    // Inside public_html but outside wp/
    dirname(__DIR__)    . DIRECTORY_SEPARATOR . 'config_wp' . DIRECTORY_SEPARATOR . 'config.php',
    // Local dev (same folder as this loader)
    __DIR__             . DIRECTORY_SEPARATOR . 'config.local.php',
];

foreach ($candidates as $path) {
    if (is_file($path)) {
        require_once $path;
        return;
    }
}

// Nothing found — fail loudly
http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo "FATAL: config file not found.\n\nChecked locations (in order):\n";
foreach ($candidates as $path) echo "  - {$path}\n";
echo "\nUpload your real config.php to one of the paths above (the first one is recommended).";
exit;
