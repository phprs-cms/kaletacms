<?php

declare(strict_types=1);

use Kaleta\Core\Files;

/* ---------- attachments ---------- */
check('Soubory: PDF je příloha', Files::isAttachment('Zpráva.PDF'), true);
check('Soubory: PHP není příloha', Files::isAttachment('shell.php'), false);
check('Soubory: dvojitá přípona', Files::isAttachment('shell.pdf.php'), false);
check('Soubory: SVG a HTML ne', Files::isAttachment('x.svg') || Files::isAttachment('x.html'), false);
check('Soubory: velikost', Files::size(1536), '2 kB');
check('Soubory: velikost v MB', Files::size(5 * 1048576), '5,0 MB');

