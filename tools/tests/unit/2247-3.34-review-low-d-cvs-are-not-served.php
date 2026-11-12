<?php

declare(strict_types=1);

/* ---------- 3.34 review low (d): the CVs in storage/applications are never served – Apache by .htaccess, nginx by the documented rule ---------- */
// A probe of storage/ over HTTP from System status would be a request of the site to itself: on a server with one worker
// (php -S, small FastCGI pools) it waits for its own answer until it times out, so the rule is documented and checked here.
$ldNginx = (string) file_get_contents(KALETA_SYSTEM . '/nginx.example.conf');
check('[source-pin] 3.34 review low (d): nginx denies storage/ (the CVs named), Apache denies it twice (storage/.htaccess, the folder\'s own), the ROADMAP says so', [
    preg_match('#^location ~ \^/\(.*\|storage\|.*\)\(/\|\$\) \{ return 403; \}$#m', $ldNginx) === 1, str_contains($ldNginx, '/storage/applications/.htaccess must return 403 or 404'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/storage/.htaccess'), 'Require all denied'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php'), "\"Require all denied\\nDeny from all\\n\""),
    str_contains((string) file_get_contents(KALETA_ROOT . '/docs/ROADMAP.md'), 'not served by the web server on Apache')],
    [true, true, true, true, true]);
