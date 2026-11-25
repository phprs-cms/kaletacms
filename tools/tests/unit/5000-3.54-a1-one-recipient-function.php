<?php

declare(strict_types=1);

/* ---------- 3.54 A1/A2: one recipient function – the audience, its count, the campaign-name rule, strict values and the edit lock of a scheduled newsletter ---------- */
use Kaleta\Core\Mailing as A5000Mailing;

// an App over an SQLite copy of the tables (settings and the signed-in person set as the classes cache them)
$a5000App = function (array $settings, ?array $user = ['idu' => 1, 'admin' => 2]): Kaleta\Core\App {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    $pdo->exec('CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, email TEXT NOT NULL, stav INTEGER NOT NULL, language TEXT NOT NULL DEFAULT \'\', contact_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
    $pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('ab', 32) . "', '2026-11-25 10:00:00')"); // the fingerprint key
    $pdo->exec("CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, subject TEXT, preheader TEXT DEFAULT '', intro TEXT DEFAULT '', news_mode TEXT DEFAULT 'none', news_count INTEGER DEFAULT 3,
        news_ids TEXT DEFAULT '', button_label TEXT DEFAULT '', button_url TEXT DEFAULT '', language TEXT DEFAULT '', status TEXT DEFAULT 'draft', scheduled_at TEXT NULL,
        audience TEXT NOT NULL DEFAULT 'all', audience_filter TEXT NOT NULL DEFAULT '', author INTEGER NULL, created TEXT NULL, changed TEXT NULL)");
    $pdo->exec('CREATE TABLE ka_deals (id INTEGER PRIMARY KEY, contact_id INTEGER, stage TEXT, closed_at TEXT NULL)');
    $pdo->exec('CREATE TABLE ka_quotes (id INTEGER PRIMARY KEY, contact_id INTEGER, status TEXT, history TEXT)');
    $s = new Kaleta\Core\Settings($db);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $settings + ['site_language' => 'cs', 'additional_languages' => 'en,de', 'extensions' => 'newsletter,jazyky', 'pipeline_stages' => '']);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
    $auth = (new ReflectionClass(Kaleta\Core\Auth::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Auth::class, 'user'))->setValue($auth, $user);
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'db'))->setValue($app, $db);
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $s);
    (new ReflectionProperty(Kaleta\Core\App::class, 'auth'))->setValue($app, $auth);
    Kaleta\Core\Audience::forgetCustomers();

    return $app;
};
$a5000Try = function (callable $call): string {
    try {
        $result = $call();

        return is_array($result) ? (string) json_encode($result, JSON_UNESCAPED_UNICODE) : 'ok';
    } catch (\InvalidArgumentException | \DomainException $e) {
        return $e->getMessage();
    }
};

// three languages, unconfirmed rows, register addresses and subscribers not linked to a contact
$a5000 = $a5000App([]);
$a5000Db = $a5000->db();
foreach ([['a@example.com', 1, '', 1], ['b@example.com', 1, '', null], ['c@example.com', 1, 'en', 2], ['d@example.com', 1, 'en', 3], ['e@example.com', 1, 'de', 4],
    ['f@example.com', 0, '', 5], ['g@example.com', 0, 'en', null], ['bounced@example.com', 1, '', 6]] as [$a5000Mail, $a5000State, $a5000Lang, $a5000Contact]) {
    $a5000Db->insert('odberatele', ['email' => $a5000Mail, 'stav' => $a5000State, 'language' => $a5000Lang, 'contact_id' => $a5000Contact]);
}
$a5000Db->insert('restore_ledger', ['kind' => 'bounced', 'hash' => Kaleta\Core\RestoreLedger::fingerprint($a5000Db, 'bounced@example.com'), 'created_at' => '2026-11-25 10:00:00']);
$a5000Count = fn (array $n): int => A5000Mailing::recipientCount($a5000, $n);
check('3.54 A1: recipientCount() – everyone confirmed (register addresses are queued and skipped later, so they count); the default language is \'\' on both sides; each language only its own; unconfirmed never', [
    $a5000Count(['audience' => 'all', 'audience_filter' => '', 'language' => '']),
    $a5000Count(['audience' => 'language', 'audience_filter' => '', 'language' => '']),
    $a5000Count(['audience' => 'language', 'audience_filter' => '', 'language' => 'en']),
    $a5000Count(['audience' => 'language', 'audience_filter' => '', 'language' => 'de']),
    $a5000Count(['audience' => 'all', 'audience_filter' => '', 'language' => 'en']),
    $a5000Count([]), A5000Mailing::confirmedCount($a5000Db)],
    [6, 3, 2, 1, 6, 6, 6]);
check('3.54 A1: audienceProblem() fails closed – an unknown audience or filter and "only customers" without the pipeline or quotes are refused; recipientsWhere() then matches nobody (never a wider audience)', [
    A5000Mailing::audienceProblem($a5000, ['audience' => 'all', 'audience_filter' => '']),
    A5000Mailing::audienceProblem($a5000, ['audience' => 'customer']) !== null,
    A5000Mailing::audienceProblem($a5000, ['audience' => 'all', 'audience_filter' => 'vip']) !== null,
    A5000Mailing::audienceProblem($a5000, ['audience' => 'all', 'audience_filter' => 'customers']) !== null,
    A5000Mailing::recipientsWhere($a5000, ['audience' => 'customer']),
    $a5000Count(['audience' => 'Language']),
    $a5000Count(['audience' => 'all', 'audience_filter' => 'customers'])],
    [null, true, true, true, ['0 = 1', []], 0, 0]);
check('3.54 A2: emptyAudience() – the words of the audience: nobody of this language; the count of subscribers without a contact stays out of the text', [
    A5000Mailing::emptyAudience($a5000, ['audience' => 'language', 'language' => 'pl']),
    A5000Mailing::unlinkedCount($a5000, ['audience' => 'all']), A5000Mailing::unlinkedCount($a5000, ['audience' => 'language', 'language' => 'en']),
    A5000Mailing::unlinkedCount($a5000, ['audience' => 'all', 'audience_filter' => 'vip'])],
    ['There are no confirmed subscribers of this newsletter\'s language yet.', 1, 0, 0]);

// the campaign name (TAG_MIN = 5): the site has six confirmed of whom five are reachable, the English audience two
check('3.54 A1: the campaign-name rule counts the newsletter\'s audience – six confirmed (five reachable) get utm_campaign, the two English subscribers do not, and without an audience every confirmed subscriber counts as before', [
    A5000Mailing::reachableForCampaign($a5000Db, A5000Mailing::recipientsWhere($a5000, ['audience' => 'all'])),
    A5000Mailing::reachableForCampaign($a5000Db, A5000Mailing::recipientsWhere($a5000, ['audience' => 'language', 'language' => 'en'])),
    A5000Mailing::reachableForCampaign($a5000Db, A5000Mailing::recipientsWhere($a5000, ['audience' => 'language', 'language' => ''])),
    A5000Mailing::reachableForCampaign($a5000Db)],
    [5, 2, 2, 5]);

// strict values in sanitize(): a typo is refused, never "everyone"; customers_only is a strict boolean
$a5000Sanitize = fn (array $input, array $current = []): string => $a5000Try(fn (): array => array_intersect_key(A5000Mailing::sanitize($a5000, ['subject' => 'Jaro'] + $input, $current),
    array_flip(['audience', 'audience_filter', 'language'])));
check('3.54 A1: sanitize() – audience all | language, a typo or another case refused; customers_only true/false/1/0/"true"/"false" ("false" is false), anything else refused; audience_filter only \'\' | customers; two keys that disagree refused; no key keeps the current value', [
    $a5000Sanitize([]),
    $a5000Sanitize(['audience' => 'language', 'language' => 'en']),
    $a5000Sanitize(['audience' => 'customer']),
    $a5000Sanitize(['audience' => 'Language']),
    $a5000Sanitize(['customers_only' => 'false']),
    $a5000Sanitize(['customers_only' => true]),
    $a5000Sanitize(['customers_only' => 1]),
    $a5000Sanitize(['customers_only' => '0']),
    $a5000Sanitize(['customers_only' => 'TRUE']),
    $a5000Sanitize(['customers_only' => 'yes']),
    $a5000Sanitize(['customers_only' => '']),
    $a5000Sanitize(['customers_only' => null]),
    $a5000Sanitize(['audience_filter' => 'customers']),
    $a5000Sanitize(['audience_filter' => 'customer']),
    $a5000Sanitize(['audience_filter' => ['customers']]),
    $a5000Sanitize(['audience_filter' => '', 'customers_only' => true]),
    $a5000Sanitize(['subject' => 'Léto'], ['audience' => 'language', 'audience_filter' => 'customers', 'language' => 'de'])],
    ['{"language":"","audience":"all","audience_filter":""}', '{"language":"en","audience":"language","audience_filter":""}', 'audience must be all or language.', 'audience must be all or language.',
        '{"language":"","audience":"all","audience_filter":""}', '{"language":"","audience":"all","audience_filter":"customers"}', '{"language":"","audience":"all","audience_filter":"customers"}',
        '{"language":"","audience":"all","audience_filter":""}', '{"language":"","audience":"all","audience_filter":"customers"}', 'customers_only must be true or false.', 'customers_only must be true or false.',
        'customers_only must be true or false.', '{"language":"","audience":"all","audience_filter":"customers"}', 'audience_filter must be empty or customers.', 'audience_filter must be empty or customers.',
        'customers_only and audience_filter disagree.', '{"language":"de","audience":"language","audience_filter":"customers"}']);

// the edit lock: a scheduled newsletter cannot change who it goes to (the admin and MCP both save through save())
$a5000Db->insert('newsletters', ['id' => 1, 'subject' => 'Jaro', 'status' => 'scheduled', 'scheduled_at' => '2030-01-01 10:00:00', 'audience' => 'language', 'audience_filter' => '', 'language' => 'en']);
$a5000Db->insert('newsletters', ['id' => 2, 'subject' => 'Léto', 'status' => 'scheduled', 'scheduled_at' => '2030-01-01 10:00:00', 'audience' => 'all', 'audience_filter' => '', 'language' => 'en']);
$a5000Db->insert('newsletters', ['id' => 3, 'subject' => 'Podzim', 'status' => 'draft', 'audience' => 'all', 'audience_filter' => '', 'language' => '']);
$a5000Save = fn (int $id, array $input): string => $a5000Try(function () use ($a5000, $id, $input): array {
    A5000Mailing::save($a5000, $input + ['subject' => 'Změna'], $id);

    return [(string) $a5000->db()->value('SELECT subject FROM {newsletters} WHERE id = ?', [$id])];
});
$a5000Locked = 'The audience of a scheduled newsletter cannot change – unschedule it first.';
check('3.54 A2: a scheduled newsletter – a change of the audience, of the filter, or of the language while the audience is "language" is refused; its content, and the language of one for everyone, may still change; a draft may change everything', [
    $a5000Save(1, ['audience' => 'all']), $a5000Save(1, ['customers_only' => true]), $a5000Save(1, ['language' => 'de']), $a5000Save(1, ['language' => 'cs']),
    $a5000Save(1, ['intro' => 'Nový text']), $a5000Save(2, ['language' => 'de']), $a5000Save(2, ['audience' => 'language']),
    $a5000Save(3, ['audience' => 'language', 'language' => 'de', 'customers_only' => '1']),
    (string) json_encode($a5000Db->all('SELECT id, audience, audience_filter, language FROM {newsletters} ORDER BY id'))],
    [$a5000Locked, $a5000Locked, $a5000Locked, $a5000Locked, '["Změna"]', '["Změna"]', $a5000Locked, '["Změna"]',
        '[{"id":1,"audience":"language","audience_filter":"","language":"en"},{"id":2,"audience":"all","audience_filter":"","language":"de"},{"id":3,"audience":"language","audience_filter":"customers","language":"de"}]']);
check('3.54 A2/A3: audienceKey() – what decides the recipients: the language only for the audience "language"; a missing key is the column default', [
    A5000Mailing::audienceKey(['audience' => 'all', 'audience_filter' => '', 'language' => 'en']) === A5000Mailing::audienceKey(['audience' => 'all', 'audience_filter' => '', 'language' => 'de']),
    A5000Mailing::audienceKey(['audience' => 'language', 'audience_filter' => '', 'language' => 'en']) === A5000Mailing::audienceKey(['audience' => 'language', 'audience_filter' => '', 'language' => 'de']),
    A5000Mailing::audienceKey([]) === A5000Mailing::audienceKey(['audience' => 'all', 'audience_filter' => ''])],
    [true, false, true]);
check('3.54 A2: RecipientCountChanged carries both numbers and is a DomainException (the admin and MCP word it themselves)', [
    (new Kaleta\Core\RecipientCountChanged(42, 43))->expected, (new Kaleta\Core\RecipientCountChanged(42, 43))->actual,
    new Kaleta\Core\RecipientCountChanged(1, 2) instanceof \DomainException, (new Kaleta\Core\RecipientCountChanged(42, 41))->getMessage()],
    [42, 43, true, 'The number of recipients changed: was 42, now 41 – confirm again.']);
