<?php

declare(strict_types=1);

/* ---------- 3.39 B (N112): unblocking an account revokes its tokens unless the administrator keeps them ---------- */

// the form sends zrusit_pripojeni=0 (hidden) and =1 (the box, ticked by default): only an explicit "0" keeps the tokens
check('3.39 N112: the connections are kept only when the box is unticked (an explicit 0), as for a password change', [
    array_map(Kaleta\Admin\Account::keepsTokens(...), ['0', '1', '']), is_callable([Kaleta\Admin\Modules\Users::class, 'revokeAllConnections']),
], [[true, false, false], true]);
