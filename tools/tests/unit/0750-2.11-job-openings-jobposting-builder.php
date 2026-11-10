<?php

declare(strict_types=1);

/* ---------- 2.11: job openings – JobPosting (Builder\CollectionSchema), the application form of the preset, retention of applications (Core\Jobs) ---------- */
$jobsPreset = Kaleta\Builder\Presets::get('jobs');
$jobFields = Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $jobsPreset['fields']));
$jobCollection = fn (string $currency): array => ['seo_link' => 'jobs', 'detail' => 1, 'pole' => $jobFields, 'schema_org' => json_encode(['mena' => $currency] + $jobsPreset['schema'])];
$jobIssuer = ['@type' => 'LocalBusiness', '@id' => 'https://example.cz/#firma', 'name' => 'Web', 'legalName' => 'Truhlárna s.r.o.', 'url' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg',
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']];
$jobItem = ['nazev' => 'Truhlář', 'seo_link' => 'truhlar', 'datum' => '2026-10-02 10:00:00', 'valid_until' => '2026-11-30',
    'data' => ['location' => 'Brno', 'employment_type' => 'plný úvazek', 'salary_min' => '35 000', 'salary_max' => '45000', 'salary_unit' => 'per month', 'description' => '<p>Výroba <b>nábytku</b>.</p>']];
$posting = Kaleta\Builder\CollectionSchema::forItem($jobCollection('CZK'), $jobItem, 'https://example.cz/jobs/truhlar', 'meta description', '', $jobIssuer['@id'], $jobIssuer);
check('2.11 JobPosting: title, dates, the hiring organization from the company, the place with the company country, a salary range with the collection currency, recognised type and unit', $posting, [
    '@type' => 'JobPosting', 'title' => 'Truhlář', 'url' => 'https://example.cz/jobs/truhlar', 'description' => 'Výroba nábytku.', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'validThrough' => '2026-11-30',
    'employmentType' => 'FULL_TIME', 'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Truhlárna s.r.o.', 'sameAs' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg'],
    'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']],
    'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'CZK', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 35000.0, 'maxValue' => 45000.0, 'unitText' => 'MONTH']]]);
$bareItem = ['nazev' => 'Svářeč', 'seo_link' => 'svarec', 'datum' => '2026-10-02 10:00:00', 'valid_until' => null, 'data' => ['employment_type' => 'podle dohody', 'salary_min' => '', 'salary_max' => '']];
$bare = Kaleta\Builder\CollectionSchema::forItem($jobCollection('CZK'), $bareItem, 'https://example.cz/jobs/svarec', 'meta description', '', $jobIssuer['@id'], []);
check('2.11 JobPosting: what is missing is left out, never guessed – no validThrough, salary, place or organization; an unknown employment type stays the text; the meta description', $bare,
    ['@type' => 'JobPosting', 'title' => 'Svářeč', 'url' => 'https://example.cz/jobs/svarec', 'description' => 'meta description', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'employmentType' => 'podle dohody']);
$oneFigure = ['data' => ['salary_min' => '250', 'salary_unit' => 'za hodinu', 'employment_type' => 'Teilzeit']] + $jobItem;
check('2.11 JobPosting: a single salary figure is a value; without the collection currency no salary at all', [
    Kaleta\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['baseSalary'], Kaleta\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['employmentType'],
    isset(Kaleta\Builder\CollectionSchema::forItem($jobCollection(''), $jobItem, 'u', '', '', 'i', [])['baseSalary'])],
    [['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'value' => 250.0, 'unitText' => 'HOUR']], 'PART_TIME', false]);
check('2.11 CollectionSchema::employmentType and salaryUnit in several languages; unknown = the text, resp. nothing', [
    array_map(Kaleta\Builder\CollectionSchema::employmentType(...), ['full-time', 'HPP', 'Vollzeit', 'pełny etat', 'part-time', 'zkrácený úvazek', 'niepełny etat', 'na IČO', 'freelance contract', 'brigáda', 'stáž', 'Internship', '', 'flexible']),
    array_map(Kaleta\Builder\CollectionSchema::salaryUnit(...), ['per month', 'měsíčně', 'pro Monat', 'miesięcznie', 'per hour', 'za hodinu', 'pro Stunde', 'za rok', 'p. a.', 'týdně', 'per day', '', 'brutto'])],
    [['FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'PART_TIME', 'PART_TIME', 'PART_TIME', 'CONTRACTOR', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'INTERN', '', 'flexible'],
        ['MONTH', 'MONTH', 'MONTH', 'MONTH', 'HOUR', 'HOUR', 'HOUR', 'YEAR', 'YEAR', 'WEEK', 'DAY', '', '']]);
check('2.11 JobPosting: the other types are unchanged – a service still gets its offer', Kaleta\Builder\CollectionSchema::forItem(['seo_link' => 's', 'detail' => 1, 'pole' => [['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'cislo']],
    'schema_org' => '{"typ":"Service","pole":{"price":"cena"},"mena":"EUR"}'], ['nazev' => 'Montáž', 'data' => ['cena' => '1200']], 'u', 'd', '', 'https://example.cz/#firma')['offers'],
    ['@type' => 'Offer', 'price' => '1200', 'priceCurrency' => 'EUR', 'url' => 'u']);
// the item template the preset brings: the job text and the Job application form with a CV and the hidden job name
$findForm = function (array $nodes) use (&$findForm): ?array {
    foreach ($nodes as $n) {
        if (($n['typ'] ?? '') === 'formular') {
            return $n;
        }
        if (($found = $findForm($n['deti'] ?? [])) !== null) {
            return $found;
        }
    }

    return null;
};
$jobForm = $findForm(Kaleta\Builder\Presets::itemTemplate($jobsPreset, $jobFields)['deti']);
check('2.11 jobs preset: the item template has a Job application form – name, e-mail, phone, a required CV attachment, a message, consent and the hidden job name that survives sanitizing', [
    $jobForm['obsah']['nazev'] ?? null, array_column($jobForm['obsah']['pole'] ?? [], 'typ'), $jobForm['obsah']['pole'][3]['povinne'] ?? null, $jobForm['obsah']['pole'][6]['hodnota'] ?? null, $jobForm['obsah']['pole'][5]['povinne'] ?? null],
    [t('Job application'), ['text', 'email', 'tel', 'soubor', 'textarea', 'souhlas', 'skryte'], true, '{{nazev}}', true]); // t(): the dictionary of an earlier test is still set
check('2.11 jobs preset: JobPosting data, newest first, hidden jobs lead to the jobs page, the contact links to the team', [$jobsPreset['schema']['typ'], $jobsPreset['list'], $jobsPreset['redirect_hidden'], $jobsPreset['fields'][8][3] ?? null, str_contains($jobsPreset['claude'], 'valid_until')],
    ['JobPosting', ['razeni' => 'nejnovejsi'], true, ['preset' => 'people'], true]);
// retention of applications: enquiries from a jobs collection (their source) older than the months
$jobSources = ['kolekce:5'];
$applications = [
    ['idp' => 1, 'zdroj' => 'kolekce:5', 'datum' => '2026-03-01 10:00:00'], // an old application
    ['idp' => 2, 'zdroj' => 'kolekce:5', 'datum' => '2026-09-01 10:00:00'], // a recent one
    ['idp' => 3, 'zdroj' => 'stranka:7', 'datum' => '2025-01-01 10:00:00'], // an old enquiry from a page – not an application
    ['idp' => 4, 'zdroj' => 'kolekce:9', 'datum' => '2025-01-01 10:00:00'], // an old enquiry from another collection's item page
    ['idp' => 5, 'zdroj' => 'kolekce:5', 'datum' => '2026-04-02 12:00:00'], // exactly six months – not older yet
];
check('2.11 Jobs::expiredApplications – only from a jobs collection and older than the months', array_column(Kaleta\Core\Jobs::expiredApplications($applications, $jobSources, 6, '2026-10-02 12:00:00'), 'idp'), [1]);
check('2.11 Jobs::expiredApplications – a shorter retention takes the one at the limit too; 0 months or no jobs collection = nothing', [
    array_column(Kaleta\Core\Jobs::expiredApplications($applications, $jobSources, 1, '2026-10-02 12:00:00'), 'idp'), Kaleta\Core\Jobs::expiredApplications($applications, $jobSources, 0, '2026-10-02 12:00:00'),
    Kaleta\Core\Jobs::expiredApplications($applications, [], 6, '2026-10-02 12:00:00')], [[1, 2, 5], [], []]);
check('2.11 Jobs::suggestedRetention – by the company country, then by the site language; unknown = no hint', [Kaleta\Core\Jobs::suggestedRetention('CZ', 'en'), Kaleta\Core\Jobs::suggestedRetention('', 'de'), Kaleta\Core\Jobs::suggestedRetention('at', ''),
    Kaleta\Core\Jobs::suggestedRetention('FR', 'fr'), Kaleta\Core\Jobs::suggestedRetention('', 'en')], [['CZ', 6], ['DE', 6], ['AT', 6], null, null]);
check('2.11: the audit kind, the event and the setting of job applications are known', [Kaleta\Core\Audit::KINDS['job'], isset(Kaleta\Core\Events::TYPES['applications.purged']), Kaleta\Core\Settings::DEFAULTS['job_applications_months'], Kaleta\Builder\CollectionSchema::TYPES['JobPosting'][0]],
    ['Job openings', true, '0', 'Job opening']);
