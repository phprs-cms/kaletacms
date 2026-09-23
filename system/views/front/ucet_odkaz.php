<?php
/**
 * Odkaz na účet čtenáře do hlavičky webu (rozšíření Čtenáři). Layout ho dostává hotový v proměnné $ucet_html.
 * Přihlášený čtenář má osobní cookie, se kterou se stránka necachuje – text se proto smí lišit podle čtenáře.
 *
 * @var string $url      adresa účtu čtenáře
 * @var string $jmeno    jméno přihlášeného čtenáře ('' = nepřihlášen)
 * @var bool $prihlasen
 */
?>
<a class="mc-ucet" href="<?= e($url) ?>"<?= $prihlasen ? ' aria-label="' . e(t('Můj účet')) . '"' : '' ?>><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span><?= e($prihlasen ? ($jmeno !== '' ? $jmeno : t('Můj účet')) : t('Přihlásit se')) ?></span></a>
