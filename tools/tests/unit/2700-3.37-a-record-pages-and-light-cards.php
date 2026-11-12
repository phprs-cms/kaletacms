<?php
declare(strict_types=1);

// 3.37 (A): one Handling form on the enquiry – a person's triage only when it changed; a light board card
use Kaleta\Admin\Modules\Enquiries;

$a337Stored = ['kategorie' => 'sales', 'priorita' => 3, 'navrh_odpovedi' => 'Draft'];
check('3.37 (U26): the Handling form changes the triage only when the kind, the priority or the drafted reply differ (empty = no change)', [
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('sales', 3, 'Draft')),
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('', 0, 'Draft')),
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('support', 3, 'Draft')),
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('sales', 1, 'Draft')),
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('sales', 3, 'Draft ')),
    Enquiries::triageChanged($a337Stored, Kaleta\Core\Triage::clean('sales', 3, 'New draft')),
    Enquiries::triageChanged(['kategorie' => null, 'priorita' => 0, 'navrh_odpovedi' => null], Kaleta\Core\Triage::clean('', 0, '')),
    Enquiries::triageChanged(['kategorie' => null, 'priorita' => 0, 'navrh_odpovedi' => null], Kaleta\Core\Triage::clean('spam', 0, '')),
], [false, false, true, true, false, true, false, true]);

$a337View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$a337Url = new class { /** @param array<string, scalar> $p */ public function url(string $a = '', array $p = []): string { return 'admin.php?' . http_build_query(['module' => 'pipeline'] + ($a !== '' ? ['action' => $a] : []) + $p); } };
$a337Row = ['id' => 9, 'title' => 'Brackets', 'contact_id' => null, 'contact_name' => null, 'contact_email' => null, 'company_id' => null, 'company_name' => null, 'owner_id' => 4,
    'stage' => 'new', 'value' => null, 'currency' => null, 'expected_close' => null, 'closed_at' => null, 'lost_reason' => null, 'lost_note' => null, 'created_by' => null,
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'source_enquiry_id' => null];
$a337Card = fn (array $row, array $owners): string => Kaleta\Core\Language::runWith('en', fn (): string => $a337View->render('admin/pipeline/card', ['app' => new Kaleta\Core\App([]),
    'module' => $a337Url, 'csrf' => '', 'd' => $row, 'stages' => Kaleta\Core\Deals::parseStages(''), 'owners' => $owners, 'return' => 'module=pipeline', 'today' => '2026-11-03',
    'canContacts' => false]), 'admin-');
$a337Owned = $a337Card($a337Row, [4 => 'Žofie van der Berg']);
$a337Nobody = $a337Card(['owner_id' => null] + $a337Row, [4 => 'Žofie van der Berg']);
check('3.37 (U28): a card names its person by initials (the whole name for screen readers and on hover), says nothing for nobody, and keeps its move form', [
    str_contains($a337Owned, '<span class="obchod-osoba" title="Žofie van der Berg"><span aria-hidden="true">ŽV</span><span class="navod-skryte">Responsible person: Žofie van der Berg</span></span>'),
    str_contains($a337Nobody, 'obchod-osoba'), str_contains($a337Nobody, 'obchod-udaje'), str_contains($a337Nobody . $a337Owned, 'nobody responsible'),
    substr_count($a337Owned . $a337Nobody, 'data-deal-move'),
], [true, false, false, false, 2]);
