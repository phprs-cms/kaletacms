<?php
/**
 * Vizuální editor bloků - vkládá se před </body> místo běžné patičky, když přihlášený uživatel otevře web s ?upravit=1.
 *
 * @var MiroCMS\Core\App $app
 * @var string $rozvrzeni
 */
use MiroCMS\Admin\Moduly\Bloky;
use MiroCMS\Admin\Moduly\Reklama;
use MiroCMS\Admin\Moduly\Rubriky;
use MiroCMS\Core\Rozsireni;

$vypnute = array_keys(array_filter(['nov' => 'novinky', 'ank' => 'ankety', 'rek' => 'reklama', 'nws' => 'newsletter', 'cte' => 'ctenari', 'psh' => 'push'], fn (string $r): bool => !Rozsireni::je($app->settings(), $r)));
// texty editoru jsou v jazyce administrace přihlášeného (ne v jazyce právě zobrazené verze webu); slovník se čte přímo, globální jazyk se nemění
$kodAdmin = (string) ($app->auth()->user()['jazyk'] ?? '') ?: MiroCMS\Core\Jazyk::vychozi($app->settings());
$kodAdmin = isset(MiroCMS\Core\Jazyk::ADMINISTRACE[$kodAdmin]) ? $kodAdmin : 'cs';
$slovnikAdmin = $kodAdmin !== 'cs' && is_file(MIROCMS_SYSTEM . '/jazyky/admin-' . $kodAdmin . '.php') ? require MIROCMS_SYSTEM . '/jazyky/admin-' . $kodAdmin . '.php' : [];
$ta = fn (string $text): string => $slovnikAdmin[$text] ?? $text;
$svgIkona = require MIROCMS_ROOT . '/system/views/admin/ikony.php'; // stejná sada čárových ikon jako v administraci
$katalog = [];
foreach (Bloky::KATALOG as $skupina => $typy) {
    foreach ($typy as $typ => [$nazev, $popis, $ikona]) {
        if (!in_array($typ, $vypnute, true)) {
            $katalog[$ta($skupina)][] = ['typ' => (string) $typ, 'nazev' => $ta($nazev), 'popis' => $ta($popis), 'ikona' => $svgIkona($ikona)];
        }
    }
}
$nastaveni = [
    'admin' => $app->url('admin.php'),
    'csrf' => $app->session->csrfToken(),
    'rozvrzeni' => $rozvrzeni,
    'rozvrzeniVolby' => array_map(fn (array $r): array => ['nazev' => $ta($r[0]), 'popis' => $ta($r[1])], Bloky::ROZVRZENI),
    'katalog' => $katalog,
    'jazyky' => MiroCMS\Core\Jazyk::dalsi($app->settings()) === [] ? [] : [['', $ta('ve všech jazycích')], ['vy', MiroCMS\Core\Jazyk::DOSTUPNE[MiroCMS\Core\Jazyk::vychozi($app->settings())][0]], ...array_map(fn (string $k): array => [$k, MiroCMS\Core\Jazyk::DOSTUPNE[$k][0]], MiroCMS\Core\Jazyk::dalsi($app->settings()))],
    'rubriky' => array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => str_repeat('– ', $r['uroven']) . $r['nazev']], Rubriky::strom($app->db())),
    'pozice' => array_map($ta, array_diff_key(Reklama::POZICE, ['pod-clankem' => 1])),
    'kde' => array_map($ta, Bloky::KDE),
    'zarizeni' => array_map($ta, Bloky::ZARIZENI),
    'obdobiNej' => array_map(fn (int $dni): array => [$dni, $ta(Bloky::OBDOBI_NEJ[$dni])], array_keys(Bloky::OBDOBI_NEJ)),
];
?>
<input type="hidden" name="_csrf" value="<?= e($app->session->csrfToken()) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/vizual.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<script id="mc-nastaveni" type="application/json"><?= json_encode($nastaveni, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if ($kodAdmin !== 'cs'): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . $kodAdmin . '.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>"></script>
<?php endif ?>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>"></script>
<script src="<?= e($app->url('image/vizual.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>"></script>
