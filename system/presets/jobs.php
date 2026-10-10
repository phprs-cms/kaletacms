<?php

// Job openings (2.11): a page for each job with JobPosting data and an application form with a CV. The item's "true until"
// (Core\Validity) is the closing date – after it the job hides itself and its address leads to the jobs page. Applications
// are enquiries with their own retention (Core\Jobs).
return [
    'name' => 'Job openings',
    'button' => 'New job openings',
    'description' => 'Location, employment type, salary, description, requirements and an application form with a CV on each job page; a job hides itself after its closing date.',
    'order' => 60,
    'detail' => true,
    'redirect_hidden' => true,
    'fields' => [
        ['location', 'Location', 'text'],
        ['employment_type', 'Employment type', 'text'],
        ['salary_min', 'Salary from', 'cislo'],
        ['salary_max', 'Salary to', 'cislo'],
        ['salary_unit', 'Salary unit', 'text'],
        ['description', 'Description', 'html'],
        ['requirements', 'Requirements', 'html'],
        ['we_offer', 'We offer', 'html'],
        ['contact', 'Contact', 'polozka', ['preset' => 'people']],
        ['start_date', 'Start date', 'datum'],
    ],
    'schema' => ['typ' => 'JobPosting', 'pole' => ['description' => 'description', 'employmentType' => 'employment_type', 'jobLocation' => 'location',
        'baseSalary' => 'salary_min', 'baseSalaryMax' => 'salary_max', 'salaryUnit' => 'salary_unit']],
    'claude' => 'One item per job. ALWAYS set valid_until to the closing date (the application deadline): after it the job hides itself, its address leads to the jobs page, '
        . 'and search engines get validThrough – without it the site audit lists the job (kind job). A Collection list of it on the jobs page (newest first); the item page '
        . 'carries the job text and a "Job application" form with a CV, whose submissions are in Enquiries. employment_type as text (full-time, part-time, contract, temporary, internship – recognised for structured data); '
        . 'salary_unit "per month" or "per hour". Salaries appear in structured data only with the collection currency: set it in Collections → structured data, or with update_collection schema_org {"type":"JobPosting","fields":{…the current mapping from list_collections…},"currency":"EUR"}. '
        . 'The contact field links a job to a person when the site has a Team collection. Applications are personal data: Enquiries → "Delete job applications after" sets how long they are kept.',
    'list' => ['razeni' => 'nejnovejsi'],
    'card' => ['location', 'employment_type'],
    'template' => function (array $fields): array {
        $n = \Kaleta\Builder\Build::fresh(...);
        $types = array_column($fields, 'typ', 'klic');
        // one line per fact; a text field that the administrator removed is left out
        $fact = fn (string $key, string $label, string $tags): ?array => isset($types[$key]) ? $n('text', ['html' => '<p><strong>' . e(t($label)) . ':</strong> ' . $tags . '</p>']) : null;
        $section = fn (string $key, string $label): array => isset($types[$key]) ? [['znacka' => 'h2'] + $n('nadpis', ['text' => t($label)]), $n('text', ['html' => '{{' . $key . '}}'])] : [];

        return array_values(array_filter([
            ['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}']),
            $fact('location', 'Location', '{{location}}'),
            $fact('employment_type', 'Employment type', '{{employment_type}}'),
            isset($types['salary_min'], $types['salary_max']) ? $fact('salary_min', 'Salary', '{{salary_min}}–{{salary_max}} {{salary_unit}}') : null,
            $fact('start_date', 'Start date', '{{start_date}}'),
            $fact('contact', 'Contact', '{{contact}}'),
            isset($types['description']) ? $n('text', ['html' => '{{description}}']) : null,
            ...$section('requirements', 'Requirements'),
            ...$section('we_offer', 'We offer'),
            ['znacka' => 'h2'] + $n('nadpis', ['text' => t('Apply for this job')]),
            // the hidden field carries the job name: {{nazev}} is filled on the item page and comes back with the form (Front\Forms)
            $n('formular', ['nazev' => t('Job application'), 'tlacitko' => t('Send application'), 'dekujeme' => t('Thank you for your application. We will get back to you.'), 'pole' => [
                ['popisek' => t('Full name'), 'typ' => 'text', 'povinne' => true],
                ['popisek' => t('Email'), 'typ' => 'email', 'povinne' => true],
                ['popisek' => t('Phone'), 'typ' => 'tel', 'povinne' => false],
                ['popisek' => t('CV'), 'typ' => 'soubor', 'povinne' => true],
                ['popisek' => t('A few words about you'), 'typ' => 'textarea', 'povinne' => false],
                ['popisek' => t('I agree to the processing of my personal data for the purpose of this selection procedure.'), 'typ' => 'souhlas', 'povinne' => true],
                ['popisek' => t('Job opening'), 'typ' => 'skryte', 'hodnota' => '{{nazev}}'],
            ]]),
        ]));
    },
];
