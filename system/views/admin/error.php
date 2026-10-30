<?php /** @var string $text */ /** @var list<string>|null $modules 3.19.2: when given, the text is already translated and its menu paths become links */ ?>
<p class="hlaska hlaska-chyba"><?= isset($modules) ? Kaleta\Admin\MenuPaths::links($app->url('admin.php'), $text, $modules) : e(t($text)) ?></p>
<p class="navigace-radek"><a class="navigace" href="admin.php"><?= e(t('Back to the dashboard')) ?></a></p>
