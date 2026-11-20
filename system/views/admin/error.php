<?php /** @var string $text */ /** @var array{text: string, url: string}|null $link 3.46.2: a way on (an expired download link: the record) */ /** @var list<string>|null $modules 3.19.2: when given, the text is already translated and its menu paths become links */ ?>
<p class="hlaska hlaska-chyba"><?= isset($modules) ? Kaleta\Admin\MenuPaths::links($app->url('admin.php'), $text, $modules) : e(t($text)) ?></p>
<?php if (isset($link)): ?><p class="navigace-radek"><a class="navigace" href="<?= e($link['url']) ?>"><?= e($link['text']) ?></a></p><?php endif ?>
<p class="navigace-radek"><a class="navigace" href="admin.php"><?= e(t('Back to the dashboard')) ?></a></p>
