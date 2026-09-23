<?php
/**
 * MiroCMS - veřejná část webu.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = MiroCMS\Core\App::boot();
(new MiroCMS\Front\Kernel($app))->handle()->send();

// po odeslání stránky: oznámení o právě vydaných (i naplánovaných) článcích a kontrola bezpečnostních aktualizací (nejvýše jednou za 12 hodin)
MiroCMS\Core\Oznameni::naPozadi($app);
MiroCMS\Core\Aktualizace::naPozadi($app);
