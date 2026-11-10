<?php

declare(strict_types=1);

/* ---------- spam protection: IP hash ---------- */
check('Antispam::otisk: není to IP adresa', str_contains(Kaleta\Core\Antispam::hash('203.0.113.7'), '203'), false);
check('Antispam::otisk: stejná adresa = stejný otisk', Kaleta\Core\Antispam::hash('203.0.113.7'), Kaleta\Core\Antispam::hash('203.0.113.7'));

