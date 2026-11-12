<?php

declare(strict_types=1);

/* ---------- 3.29.1 (owner, 3 Nov): after a sign-in only Content is open in the left menu ---------- */
$menu3291Layout = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php');
$menu3291Script = (string) file_get_contents(KALETA_ROOT . '/image/admin-menu.js');
check('[source-pin] 3.29.1 menu: Content is the group open after a sign-in (a real group, the first); the layout marks it and names the sign-in on the script; the script resets the memory on a new sign-in and opens only the marked group by default', [
    Kaleta\Admin\MenuGroups::OPEN_AT_SIGN_IN, Kaleta\Admin\MenuGroups::ORDER[0], Kaleta\Admin\MenuGroups::key(Kaleta\Admin\MenuGroups::OPEN_AT_SIGN_IN),
    str_contains($menu3291Layout, "\$menuGroup['group'] === Kaleta\\Admin\\MenuGroups::OPEN_AT_SIGN_IN ? ' data-menu-vychozi' : ''"),
    str_contains($menu3291Layout, 'data-menu-prihlaseni="<?= (int) $app->session->get(\'login_at\', 0) ?>"'),
    str_contains($menu3291Script, "stored._signin !== signIn"), str_contains($menu3291Script, ": group.hasAttribute('data-menu-vychozi');"), str_contains($menu3291Script, '!phone'),
    str_contains($menu3291Script, "if (!group.hasAttribute('data-aktualni'))")],
    ['Content', 'Content', 'content', true, true, true, true, false, true]);

