<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Links from each part of the administration to its article in the guide on kaletacms.com (2.4). The articles have the
 * same address in every language; the admin language picks the version (/guide, /cs/guide, /de/guide). The anchors
 * are the English headings – in another language the link simply opens the top of the article.
 */
final class Guide
{
    public const string BASE = 'https://kaletacms.com';

    /** Languages the guide is written in; other admin languages get the English one. */
    public const array LANGUAGES = ['en', 'cs', 'de'];

    /**
     * module => article#anchor; '' is the Dashboard, 'account' is My account. The office sections (quotes, applications,
     * internal_files, my_work) have no article online: their link opens a chapter in the administration (CHAPTERS, 3.45).
     */
    public const array MODULES = [
        '' => 'first-steps#the-dashboard',
        'account' => 'claude-connect',
        'pages' => 'page-settings',
        'news' => 'news#writing',
        'categories' => 'news',
        'tags' => 'news',
        'collections' => 'collections',
        'facts' => 'company-details#business-facts',
        'business' => 'company-details', // 3.2: the Business details hub (formerly Business details)
        'status' => 'site-health', // 3.2: System status (formerly a Settings tab)
        'claude_settings' => 'claude-connect', // 3.2: Claude settings (connecting, instructions, guardrails)
        'blueprints' => 'industry-blueprints',
        'connectors' => 'connections',
        'whistleblowing' => 'privacy-cookies', // 3.50: the screen opens the chapter Privacy and retention (CHAPTERS); this is its online article
        'enquiries' => 'forms#enquiries',
        'contacts' => 'customers', // 3.28: Customers (own guide article since 3.31.1)
        'pipeline' => 'pipeline', // 3.31: Pipeline (own guide article since 3.31.1)
        'bookings' => 'bookings',
        'requests' => 'claude-operator', // 2.15: requests to Claude; 3.2: the article that describes Ask Claude
        'subscribers' => 'newsletter',
        'newsletters' => 'newsletter#send-newsletters-from-kaleta',
        'media' => 'media',
        'appearance' => 'site-appearance',
        'parts' => 'site-parts',
        'menu' => 'menus',
        'components' => 'components',
        'popups' => 'popups',
        'users' => 'users-roles',
        'roles' => 'users-roles#custom-roles',
        'stats' => 'statistics',
        'redirects' => 'seo#redirects-and-404s',
        'broken_links' => 'seo#redirects-and-404s', // 3.50: a tab of the Redirects hub since 3.48 – the article on redirects and 404s
        'audit' => 'seo#site-audit',
        'changelog' => 'backups-updates',
        'notebook' => 'claude-capabilities',
        'schedules' => 'claude-capabilities', // 2.17: scheduled runs – what a routine in Claude does on the site is described with its capabilities
        'transfer' => 'wordpress-import',
        'extensions' => 'extensions',
        'addons' => 'addons',
        'fleet' => 'fleet-console',
    ];

    /** Settings tab => article#anchor */
    public const array SETTINGS = [
        'general' => 'first-steps',
        'sections' => 'first-steps', // 3.48 B (U70): where the settings of the sections are
        'languages' => 'languages#how-language-versions-work', // 3.23 (C12): the tabs that left General
        'security' => 'users-roles',
        'agency' => 'first-steps',
        'company' => 'company-details',
        'seo' => 'seo#settings-seo-and-geo',
        'analytics' => 'statistics#settings-analytics',
        'cookies' => 'privacy-cookies',
        'mail' => 'email',
        'reports' => 'email',
        'webhooks' => 'forms#connecting-other-tools',
        'backups' => 'backups-updates',
        'firewall' => 'site-health#firewall',
        'console' => 'fleet-console#pair-a-site',
        'health' => 'site-health',
    ];

    /**
     * My account tab => article#anchor (3.23.1, N-C7): the e-mail signature tab has its own article; the account details
     * (and the Claude connection on them) keep MODULES['account'].
     */
    public const array ACCOUNT = [
        'signature' => 'email#your-e-mail-signature',
    ];

    /** The builder of a page, news item, collection template, site part, component or pop-up */
    public const array BUILDER = [
        'parts' => 'site-parts',
        'components' => 'components',
        'popups' => 'popups',
        'collections' => 'collection-lists',
    ];

    /**
     * The guide to the office part in the administration itself (3.45, CPO 5): a chapter per section, plain and short, for
     * a person who has never used a CRM. It opens at admin.php?action=guide&chapter=<key> (Admin\GuidePage); the heading
     * link of each section of `modules` leads there instead of the online article, and `online` (an article of MODULES)
     * adds "More in the online guide" at the end. Every text is English with its translations in the admin dictionaries.
     * 3.50.1 (CPO-l2, l9): `always` lists a chapter in the index and the palette for every signed-in person; `tabs` gives a
     * chapter whole tabs of Settings or My account (forScreen checks them before the articles), so those screens open a
     * chapter instead of a stand-in article. A chapter with tabs of My account is listed for everybody, one with tabs of
     * Settings for whoever may open Settings.
     *
     * @var array<string, array{title: string, summary: string, modules: list<string>, online: ?string, steps: list<string>, tips: list<string>, always?: bool, tabs?: array<string, list<string>>}>
     */
    public const array CHAPTERS = [
        'my-work' => [
            'title' => 'My work and the team',
            'summary' => 'What waits for you today, and how the team shares the work.',
            'modules' => ['my_work'],
            'online' => null,
            'steps' => [
                'My work lists what waits for you: your tasks first, then the enquiries you handle, quotes to finish or follow up, the pages and items you are responsible for and the answers to your requests to Claude. Each row opens the place where the work is done.',
                'Add a task with New task – for yourself or for a colleague, with a due date and a reminder. Complete a task or snooze it right in the list.',
                'Give an enquiry, a deal, a quote, a page or an item to a colleague with Responsible person. They get a notification behind the bell. Being responsible never hides the record from anybody else.',
                'Summary shows how much the team did in a period – only counts. Administrators also have the Team tab with everybody’s open tasks and the tasks nobody has.',
            ],
            'tips' => [
                'Going on holiday? Add an absence with a deputy in My account. While you are away, a copy of your notifications goes to the deputy.',
                'My account → Colleagues shows the positions and work phones of your colleagues, A–Z or by department. Administrators create departments in Users → Departments.',
                'Ask Claude “What is waiting for me today?” – it reads My work and can prepare replies, but sends nothing without your yes.',
            ],
        ],
        // 3.50.1 (CPO-l9): the tabs of My account other than Claude and Signature, which keep their online articles
        'account' => [
            'title' => 'My account',
            'summary' => 'Your details, absences, sign-in security and your colleagues – yours alone, whatever your role.',
            'modules' => [],
            'online' => null,
            'tabs' => ['account' => ['', 'absence', 'security', 'colleagues']],
            'steps' => [
                'My details holds your name, e-mail, the language of the administration and how notifications reach you: each one at once, one summary every morning, or none.',
                'Absence: add the days you are away with a deputy. While you are away, a copy of your notifications goes to the deputy; bookings linked to your account close for those days.',
                'Security: change your password, turn on two-step sign-in with an app, add a passkey and print the backup codes – they get you in when the phone is lost.',
                'Colleagues lists the positions and work phones of the people of the site, A–Z or by department, with the org chart.',
            ],
            'tips' => [
                'Claude and Signature, the other two tabs, have their own articles in the online guide.',
                'Changing your password disconnects the apps connected under your account, and revokes your tokens unless you untick that.',
            ],
        ],
        'customers' => [
            'title' => 'Customers',
            'summary' => 'One record per customer across enquiries, bookings and newsletter sign-ups.',
            'modules' => ['contacts'],
            'online' => 'customers',
            'steps' => [
                'A customer appears by itself when somebody sends an enquiry, books an appointment or signs up for the newsletter – one record per e-mail address. You can also add one with New customer.',
                'The page of a customer shows everything in one timeline: enquiries, bookings, deals, quotes and the team’s log of calls, meetings, e-mails and notes.',
                'People of one firm belong to a company (the Companies tab). A company has its own page with its people and deals.',
                'Two records of one person? An administrator merges them with Merge with… on the page of a customer – always a decision of a person, never automatic.',
            ],
            'tips' => [
                'Ctrl K (⌘K on a Mac) finds a customer by name, e-mail or company from anywhere in the administration.',
                'Customers hold personal data and are never shown on the site. A customer with no activity for the period in Settings → Privacy and cookies is anonymised.',
            ],
        ],
        'pipeline' => [
            'title' => 'Pipeline',
            'summary' => 'Deals from the first contact to won or lost, on one board.',
            'modules' => ['pipeline'],
            'online' => 'pipeline',
            'steps' => [
                'Create a deal with New deal, or with Create deal on an enquiry – the form is filled in from the enquiry.',
                'The board has a column for each stage. Move a card by dragging it, with the keyboard (Space, the arrows, Space) or with the stage menu on the card.',
                'Won and Lost close a deal. Lost asks for the reason, so you learn why deals slip away.',
                'Each deal keeps its stage history, the enquiry it came from, the customer, its quotes and the team’s comments and tasks.',
            ],
            'tips' => [
                'Plan the next step as a task on the deal. The filter “No next step” shows the deals nobody is moving.',
                'Administrators rename, add and reorder the stages in Stages.',
                'Reports and summaries count deals, never their values.',
            ],
        ],
        'quotes' => [
            'title' => 'Quotes and internal prices',
            'summary' => 'Prices only your team sees, and quotes your customer opens by a private link.',
            'modules' => ['quotes'],
            'online' => null,
            'steps' => [
                'An administrator switches Quotes on in Features and gives the section to the people who make quotes, in Users or Roles.',
                'In Settings of Quotes, set the numbering, how long a quote is valid and VAT; on Price list set-up, choose which collections feed the price list. Until they are done, First steps with quotes at the top of the list show what is missing.',
                'Fill in Internal prices – a row for each product or variant, or your own rows; a CSV file can be imported. Changing prices needs the right “Quotes: change internal prices”.',
                'Make a quote with New quote, or from an enquiry, a deal or a customer – the customer and the lines from the enquiry basket are filled in for you.',
                'Send it by e-mail (it needs an SMTP server in Settings → Mail). The customer opens it by a private link and can download a PDF. The quote gets its number when it is first sent.',
            ],
            'tips' => [
                'The settings can prepare a draft quote for each new enquiry about products – you check it and send it.',
                'Mark a quote accepted or declined yourself, or let the customer decide on its page when the settings allow it.',
                'Claude can prepare quotes as drafts. It sends one only when you ask, and never goes below your largest discount.',
            ],
        ],
        'applications' => [
            'title' => 'Applications',
            'summary' => 'Applications from your job pages, kept apart from enquiries and customers.',
            'modules' => ['applications'],
            'online' => null,
            'steps' => [
                'Applications come from the forms on your job pages and from forms whose Purpose is “Job application”. CVs are stored encrypted.',
                'An administrator chooses who may open them: tick Applications in Users, or give the ready-made role HR in Roles.',
                'The list opens on the applications not decided yet. Waiting shows new ones without a reply for more than a week.',
                'Move an application through New, Reviewed, Interview, Offer and Hired – or Rejected, with a reason. The Board shows the applications of one position in columns.',
                'Reply from a template – received, invitation, rejection – and the status moves with it. The templates for each language are in the settings of Applications.',
            ],
            'tips' => [
                'The Talent pool keeps people who agreed to hear about future openings, until their consent runs out.',
                'Applications are deleted automatically after the period in the settings; each one shows the day.',
                'Statistics show twelve months of counts, never people.',
            ],
        ],
        'internal-files' => [
            'title' => 'Internal files',
            'summary' => 'The team’s documents – price lists, contracts, manuals – never on the public site.',
            'modules' => ['internal_files'],
            'online' => null,
            'steps' => [
                'An administrator creates folders and decides who sees each one: everybody with the section, chosen roles or departments.',
                'Upload a file into a folder with a description and tags, so colleagues find it by a word.',
                'Replace keeps the older versions, and each of them can still be downloaded. The status says whether a document is still valid.',
                'Files are downloaded only through the administration – they never get a public address.',
            ],
            'tips' => [
                'Only an empty folder can be deleted. A file is deleted by an administrator or by whoever uploaded it.',
                'Claude can find files by their name and description, but never gets their contents.',
            ],
        ],
        // 3.50: the whistleblowing channel had only the online article on cookies as a stand-in
        'privacy' => [
            'always' => true, // 3.50.1 (CPO-l2): personal data requests and retention concern every site, also with every feature off
            'title' => 'Privacy and retention',
            'summary' => 'Whistleblowing reports, personal data on request, and how long the site keeps what people send.',
            'modules' => ['whistleblowing'],
            'online' => 'privacy-cookies',
            'steps' => [
                'Reports from the whistleblowing channel are stored encrypted and only the people with the section Whistleblowing see them. Confirm each one and give the reporter feedback in time – the screen shows both deadlines.',
                'When somebody asks what the site holds about them, open Enquiries → Personal data request and enter their e-mail – the screen lists every place, downloads it for the person and erases it when you decide so. Claude finds the same when you ask it.',
                'Settings → Privacy and cookies says how long enquiries, applications, bookings and other records are kept. The site deletes older ones by itself.',
            ],
            'tips' => [
                'The record of processing activities for your privacy policy is prepared by Claude from what the site really does.',
                'A backup restored later applies the erasures again, so a deleted person does not come back.',
            ],
        ],
        // 3.50.1 (CPO-l9): the notebook and the scheduled runs had only the article on Claude's capabilities as a stand-in
        'claude-notes' => [
            'title' => 'Claude\'s notebook and scheduled runs',
            'summary' => 'What Claude remembers between conversations, and the work it does on a schedule.',
            'modules' => ['notebook', 'schedules'],
            'online' => 'claude-capabilities',
            'steps' => [
                'The Notebook keeps notes for whoever works on the site next – Claude in a new conversation or a colleague: decisions, promises to the client, where things are. Pin the important ones.',
                'Claude reads the notebook when it starts and writes its decisions into it, so it does not ask about them again. You can add, edit and delete notes yourself.',
                'Scheduled runs say what Claude should do and when – pick up requests every morning, weekly care, a monthly newsletter. Set up in Claude gives the prompt for a routine in your Claude app.',
            ],
            'tips' => [
                'The site never runs Claude itself: the routine connects over a drafts-only connection and the site records each run.',
                'When the run that picks up requests stops, the top bar says so.',
            ],
        ],
        // 3.50.1 (CPO-l9): the change log had the article on backups as a stand-in
        'changelog' => [
            'title' => 'Change log',
            'summary' => 'Who changed what and when – people and Claude – and undoing a whole Claude session.',
            'modules' => ['changelog'],
            'online' => null,
            'steps' => [
                'The change log lists every change in the administration and over the Claude connection, newest first, with who made it and in which section.',
                'Filter by who made the change – people, Claude or both – by section, or by a word in the detail. Each change by Claude carries the name of its connection.',
                'Claude sessions groups the changes one connection made in a row. Undo puts back everything the session changed; rows somebody changed afterwards stay unless you choose to overwrite them.',
            ],
            'tips' => [
                'The log keeps the history for six months, not the content – older texts come back from the versions of a page or an item.',
                'Claude reads the same log with list_changes.',
            ],
        ],
        // 3.50.1 (CPO-l9): four Settings tabs that had the first-steps or users article as a stand-in
        'site-settings' => [
            'title' => 'Settings for the whole site',
            'summary' => 'Settings of sections, sign-in security, the agency and the monthly reports.',
            'modules' => [],
            'online' => 'first-steps',
            'tabs' => ['settings' => ['sections', 'security', 'agency', 'reports']],
            'steps' => [
                'Settings of sections lists the settings that live with their section – quotes, applications, the stages of the pipeline, bookings – with a link to each.',
                'Security sets who must have two-step sign-in, whether accounts with a passkey sign in with it only, and whether unused accounts and connections are suspended by themselves.',
                'Agency names who built and looks after the site: the sign-in screen and the foot of the administration show whom to ask for help.',
                'Reports keeps the monthly report of the site – view and print it, or have it e-mailed to you or your client at the start of each month.',
            ],
            'tips' => [
                'Blocking addresses and countries is in Settings → Firewall.',
                'Once an agency is named, the tab Reporting to a console appears for pairing the site with a fleet console.',
            ],
        ],
        'fleet' => [
            'title' => 'Fleet: all your sites on one screen',
            'summary' => 'For agencies and IT teams who look after several Kaleta sites.',
            'modules' => ['fleet'],
            'online' => 'fleet-console',
            'steps' => [
                'Pair a site: make a pairing key here and paste it on the other site in Settings → Reporting to a console.',
                'Today shows first what needs attention – a failed backup, an update waiting, a certificate about to expire.',
                'The inbox collects what waits on every site. Each site keeps its history and its tasks.',
                'Sites belong to customers: the console makes a monthly report for each customer and sends alerts by your rules.',
            ],
            'tips' => [
                'Accept a known state of one check with a note and a last day – it stops alerting until then.',
                'New versions reach the sites in rings, so your test sites get them first.',
            ],
        ],
    ];

    /** The chapter of the guide in the administration for a section, or null (3.45). */
    public static function chapterFor(string $module): ?string
    {
        foreach (self::CHAPTERS as $key => $chapter) {
            if (in_array($module, $chapter['modules'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The chapters a person sees in the index and the palette: those of the sections they may open (3.45).
     *
     * @param list<string> $modules the sections the person may open
     * @return list<string>
     */
    public static function chaptersFor(array $modules): array
    {
        // 3.50.1: `always` chapters for everybody; a chapter of tabs follows its screen (My account belongs to everybody)
        $screens = [...$modules, 'account'];

        return array_keys(array_filter(self::CHAPTERS, fn (array $c): bool => ($c['always'] ?? false)
            || array_intersect([...$c['modules'], ...array_keys($c['tabs'] ?? [])], $screens) !== []));
    }

    /** The chapter that holds a tab of Settings or My account ($screen 'settings' or 'account'), or null (3.50.1). */
    public static function chapterForTab(string $screen, string $tab): ?string
    {
        foreach (self::CHAPTERS as $key => $chapter) {
            if (in_array($tab, $chapter['tabs'][$screen] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }

    public static function url(string $article, string $language): string
    {
        return self::BASE . (in_array($language, self::LANGUAGES, true) && $language !== 'en' ? '/' . $language : '') . '/guide/' . $article;
    }

    /**
     * The guide to the open screen, or null. $module '' = Dashboard, 'account' = My account. A section with a chapter in the
     * administration (CHAPTERS) gets its relative address admin.php?action=guide&chapter=… (3.45); every other link is the
     * absolute address of the online article.
     */
    public static function forScreen(string $module, string $action, string $tab, string $language): ?string
    {
        if ($action === 'builder') {
            return self::url(self::BUILDER[$module] ?? 'builder-basics', $language);
        }
        if (($chapter = self::chapterFor($module) ?? self::chapterForTab($module, $tab)) !== null) {
            return 'admin.php?action=guide&chapter=' . $chapter;
        }
        if ($module === 'settings') {
            return self::url(self::SETTINGS[$tab] ?? self::SETTINGS['general'], $language);
        }
        if ($module === 'account' && isset(self::ACCOUNT[$tab])) {
            return self::url(self::ACCOUNT[$tab], $language);
        }
        $article = self::MODULES[$module] ?? null;

        return $article === null ? null : self::url($article, $language);
    }
}
