<?php
/**
 * The "Agency" tab (3.23, C12 – part of General until then): who builds and looks after the site; the console reporting
 * tab follows it once an agency is named (C8, Settings::visibleTabs). For the variables and the $field function see list.php.
 */
?>
<fieldset>
<legend><?= e(t('Built and looked after by')) ?></legend>
<p class="napoveda"><?= e(t('The agency or freelancer who looks after the site. The sign-in screen and the foot of the admin show whom to ask for help.')) ?></p>
<?php
$field('agency_name', 'Name');
$field('agency_url', 'Website', 'url');
$field('agency_email', 'E-mail for help', 'email');
$field('agency_phone', 'Phone for help');
$field('agency_logo', 'Logo', 'text', 'A path from Media, e.g. media/2026/10/agency.svg – click the file in Media to open it, its address starts with media/.');
?>
</fieldset>
<?php if (isset(Kaleta\Admin\Modules\Settings::visibleTabs($app->settings())['console'])): ?>
<p class="napoveda"><?= $module->hint(t('Whether this site reports to the agency\'s console is set in Settings → Reporting to a console.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Once an agency is named and saved, the tab Reporting to a console appears: it pairs this site with the console of an agency that looks after several sites.')) ?></p>
<?php endif ?>
