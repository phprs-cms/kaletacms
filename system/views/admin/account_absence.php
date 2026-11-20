<?php
/**
 * My account → Absence (3.47, U68): my own absences with a deputy (views/admin/absences, Core\Absences).
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var string $action
 * @var list<array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}> $absences
 * @var array<int, string> $people
 */
$url = $app->url(Kaleta\Admin\Account::path('absence'));
?>
<?= $app->view->render('admin/absences', ['app' => $app, 'csrf' => $csrf, 'absences' => $absences ?? [], 'people' => $people ?? [], 'addUrl' => $url, 'deleteUrl' => $url, 'own' => true,
    'hidden' => '<input type="hidden" name="co" value="absence_add">', 'deleteHidden' => '<input type="hidden" name="co" value="absence_delete">']) // 3.42 ?>
