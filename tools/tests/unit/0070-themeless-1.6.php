<?php

declare(strict_types=1);

/* ---------- themeless (1.6) ---------- */
check('Themeless: the page frame is the system’s own, no layout folder and no layout setting', [is_file(KALETA_ROOT . '/system/views/front/base.php'), is_dir(KALETA_ROOT . '/layout'), isset(\Kaleta\Core\Settings::DEFAULTS['layout'])], [true, false, false]);

