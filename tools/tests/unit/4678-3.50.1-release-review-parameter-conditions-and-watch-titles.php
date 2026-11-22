<?php

declare(strict_types=1);

/* ---------- 3.50.1 release review (second pass): a wider address-parameter condition publishes, the watch names a site part ---------- */
use Kaleta\Builder\ApplicationFormWatch as W678;
use Kaleta\Builder\Build as B678;

$b678Form = fn (array $over = []): array => $over + ['id' => 'frm678', 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'ucel' => \Kaleta\Core\Applications::PURPOSE, 'pole' => []]];
$b678Json = fn (array $section): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek678', 'typ' => 'sekce'] + $section + ['deti' => [$b678Form()]]]]);
$b678Param = fn (array $parameter): array => ['podminky' => ['parametr' => $parameter]];
check('3.50.1 release review: conditionsNarrower – the same address parameter without its value or with the same value is not narrower; another parameter, a value added or changed is', [
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], ['parametr' => ['nazev' => 'utm_campaign']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], ['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign']], ['parametr' => ['nazev' => 'utm_campaign']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign']], ['parametr' => ['nazev' => 'utm_source']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign']], ['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], ['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'leto']]),
    B678::conditionsNarrower(['parametr' => ['nazev' => 'utm_campaign']], ['parametr' => 'utm_campaign'])],
    [false, false, false, true, true, true, true]);
check('3.50.1 release review: shadowedApplicationForms – dropping the value of the section\'s address-parameter condition publishes; another parameter is refused', [
    B678::shadowedApplicationForms($b678Json($b678Param(['nazev' => 'utm_campaign', 'hodnota' => 'jaro'])), $b678Json($b678Param(['nazev' => 'utm_campaign'])), []),
    B678::shadowedApplicationForms($b678Json($b678Param(['nazev' => 'utm_campaign', 'hodnota' => 'jaro'])), $b678Json($b678Param(['nazev' => 'utm_source'])), [])],
    [[], ['frm678']]);
check('3.50.1 release review: partTitle – the default header by its type, a variant with its language and name (or its key when unnamed)', [
    W678::partTitle(['typ' => 'hlavicka', 'jazyk' => '', 'varianta' => '', 'nazev' => '']),
    W678::partTitle(['typ' => 'paticka', 'jazyk' => 'en', 'varianta' => 'v2', 'nazev' => 'Careers footer']),
    W678::partTitle(['typ' => 'paticka', 'jazyk' => '', 'varianta' => 'v2', 'nazev' => ''])],
    [t(Kaleta\Builder\SiteParts::TYPES['hlavicka'][0]), t(Kaleta\Builder\SiteParts::TYPES['paticka'][0]) . ' (en) – Careers footer', t(Kaleta\Builder\SiteParts::TYPES['paticka'][0]) . ' – v2']);
