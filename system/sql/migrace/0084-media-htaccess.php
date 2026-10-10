<?php
/**
 * Kaleta 3.9.2 (N67): media/.htaccess also refuses files with a dangerous extension inside the name (photo.php.jpg,
 * page.shtml.pdf) – Apache's mod_mime applies a handler for an extension wherever it stands in the name. An update never
 * overwrites media/ (Core\Updater), so this migration does: the file is replaced when it is one Kaleta shipped (or it is
 * missing); a customised one stays and the new version is put next to it as media/.htaccess.kaleta-nova, which System
 * status reports (Core\Health). Self-contained on purpose: the update request of the release before may run it.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $new = <<<'HTACCESS'
# Uploaded files are only served – never run. The rule also matches an extension inside the name (x.php.jpg, x.shtml.pdf):
# Apache's mod_mime applies a handler for an extension wherever it stands in the name, in any case (3.9.2, N67).
<FilesMatch "(?i)\.(php\d?|pht|phtml|phar|pl|py|cgi|sh|shtml|html?|js)(\.|$)">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
<IfModule mod_headers.c>
    # browsers must not guess the file type; documents are offered for download instead of opening inside the site
    Header set X-Content-Type-Options "nosniff"
    # SVG from Media is cleaned (Core\Svg); a strict CSP is the second safeguard when someone opens it on its own
    <FilesMatch "\.svg$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"
    </FilesMatch>
    <FilesMatch "\.(docx?|xlsx?|pptx?|od[tsp]|rtf|csv|zip|epub|gpx|ics)$">
        Header set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
HTACCESS . "\n"; // a heredoc drops the last line break, the file has one
    // sha256 of every media/.htaccess earlier releases shipped
    $shipped = [
        '3bc60def50e5777ac0b3e35672745e260267bec855ea1746406cfc264bbd3a85',
        'e20dd912d8066f36f5d967ee462497fca1dcbcd5b53f19d6f77e6bff7ceec708',
        'f8d2781ba396672bf71fa583c356e93a703b940090551955b6035fa6cf6c12d1',
    ];
    $file = KALETA_ROOT . '/media/.htaccess';
    $current = is_file($file) ? (string) file_get_contents($file) : null;
    if ($current === $new) {
        return;
    }
    if ($current === null || in_array(hash('sha256', $current), $shipped, true)) {
        // written next to it and renamed: a request in between sees the old or the new file, never half of one; a failure is
        // reported by System status (Core\Health checks the rule itself)
        $temporary = $file . '.kaleta-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $new) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            error_log('Kaleta 3.9.2: media/.htaccess could not be updated – copy the deny rule from the release package by hand');
        }
        @unlink($file . '.kaleta-nova');

        return;
    }
    @file_put_contents($file . '.kaleta-nova', $new);
};
