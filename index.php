<?php
/**
 * Kaleta - veřejná část webu.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
(new Kaleta\Front\Kernel($app))->handle()->send();

// po odeslání stránky: oznámení o právě vydaných (i naplánovaných) článcích a kontrola bezpečnostních aktualizací (nejvýše jednou za 12 hodin)
Kaleta\Core\Oznameni::naPozadi($app);
Kaleta\Core\Aktualizace::naPozadi($app);
