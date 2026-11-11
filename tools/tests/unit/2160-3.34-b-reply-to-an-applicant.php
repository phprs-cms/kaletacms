<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 (B): a reply to an applicant – templates per language, placeholders, the machinery of enquiry replies ---------- */
check('3.34 B: three templates (received, invitation, rejection), each a subject and a text with the placeholders; labels for the administration', [
    array_keys(Applications::TEMPLATES), array_keys(Applications::TEMPLATE_LABELS),
    array_map(fn (array $t): bool => str_contains($t[1], '{{position}}') && str_contains($t[1], '{{company}}') && str_contains($t[1], '{{name}}'), Applications::TEMPLATES),
    Kaleta\Core\Settings::DEFAULTS['application_templates'] ?? null],
    [['received', 'interview', 'rejection'], ['received', 'interview', 'rejection'], ['received' => true, 'interview' => true, 'rejection' => true], '']);
check('3.34 B: the placeholders – an empty name takes its comma or space with it', [
    Applications::fill('Hello {{name}}, about {{position}} – {{company}}', ['name' => 'Jana', 'position' => 'Welder', 'company' => 'Northfield']),
    Applications::fill('Hello {{name}},', ['name' => ' ', 'position' => '', 'company' => '']), Applications::fill('Dobrý den, {{name}},', ['name' => '', 'position' => '', 'company' => '']),
    Applications::fill('{{name}}您好', ['name' => '', 'position' => '', 'company' => ''])],
    ['Hello Jana, about Welder – Northfield', 'Hello,', 'Dobrý den,', '您好']);
check('3.34 B: stored templates keep known languages and keys with a text only', [
    Applications::storedTemplates('{"cs":{"received":{"subject":"A","text":"B"},"x":{"subject":"C","text":"D"},"rejection":{"subject":"E","text":" "}},"../x":{"received":{"subject":"F","text":"G"}},"de":"bad"}'),
    Applications::storedTemplates(''), Applications::storedTemplates('[1]')],
    [['cs' => ['received' => ['subject' => 'A', 'text' => 'B']]], [], []]);
$c336Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($c336Settings, ['site_name' => 'Northfield', 'site_language' => 'cs', 'company_name' => '']);
check('3.34 B: the built-in templates are in the site language (visitor dictionaries cs, de, es and the rest), the company falls back to the site name', [
    Applications::compose($c336Settings, ['name' => 'Jana', 'job_title' => 'Svářeč', 'language' => ''], 'rejection')['subject'],
    str_starts_with(Applications::compose($c336Settings, ['name' => '', 'job_title' => '', 'language' => ''], 'received')['text'], "Dobrý den,\n\nděkujeme za vaši žádost o místo u nás."),
    str_ends_with(Applications::compose($c336Settings, ['name' => '', 'job_title' => '', 'language' => 'de'], 'interview')['text'], "Mit freundlichen Grüßen\nNorthfield"),
    Applications::compose($c336Settings, ['name' => 'Ana', 'job_title' => 'Pintora', 'language' => 'es'], 'interview')['subject'],
    array_values(array_filter(['ar', 'cs', 'de', 'es', 'fr', 'hu', 'it', 'pl', 'pt', 'sk', 'zh', 'zh-hant'], fn (string $code): bool => count(array_intersect_key(require KALETA_SYSTEM . '/jazyky/' . $code . '.php',
        array_flip([...array_merge(...array_values(Applications::TEMPLATES)), 'a position with us']))) !== 7))],
    ['Vaše žádost o místo – Svářeč', true, true, 'Invitación a una entrevista – Pintora', []]);
$c336Source = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php');
check('3.34 B: the reply reuses the enquiry machinery (SMTP check, signature, HTML part, history) and logs no person in the mail log; the received confirmation only without the form\'s own', [
    (new ReflectionMethod(Kaleta\Core\EnquiryReply::class, 'html'))->isPublic(), str_contains($c336Source, '$blocked = EnquiryReply::unavailable($s);'), str_contains($c336Source, '$signature = EnquiryReply::signature($app, $user);'),
    str_contains($c336Source, "true, t('Reply to application #%d: %s', \$id, \$s->get('site_name')));"), str_contains($c336Source, "if (!empty(\$content['potvrzeni']) || count(\$to) !== 1) {"),
    isset(Kaleta\Core\Events::TYPES['application.replied'], Kaleta\Core\Events::TYPES['application.received'], Kaleta\Core\Events::TYPES['applications.moved']),
], [true, true, true, true, true, true]);
