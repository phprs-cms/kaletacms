<?php

declare(strict_types=1);

/* ---------- link check: only public URLs (protection against probing the internal network) ---------- */
check('Odkazy: výběr odkazů z HTML', Kaleta\Core\Links::links('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), ['https://example.com/a?x=1&y=2', '/clanek/muj']);
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $internal) {
    check('Odkazy: nekontroluje se ' . $internal, Kaleta\Core\Links::isPublic($internal), false);
}
check('Odkazy: veřejná adresa se kontroluje', Kaleta\Core\Links::isPublic('https://93.184.216.34/stranka'), true);

