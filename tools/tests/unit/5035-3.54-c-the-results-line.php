<?php

declare(strict_types=1);

/* ---------- 3.54 C: a newsletter's results line – the visits key is what the statistics store for a visit through its link; visits only with a campaign name, statistics on and data still kept ---------- */
use Kaleta\Core\Mailing as C5035Mailing;
use Kaleta\Core\NewsletterResults as C5035Results;

// the campaign text the statistics store for a visit through a link of the e-mail (campaignUrl → Forms::campaign → Campaign::text)
// is exactly the key the results read – for a subject with diacritics, symbols and more than 60 characters
$c5035Origin = 'https://firma.example';
$c5035Keys = [];
foreach (['Jarní akce – 20 % na vše!', str_repeat('Velmi dlouhý předmět newsletteru ', 4), 'Ünïcødé & "quotes" <b>'] as $c5035Subject) {
    $c5035Url = C5035Mailing::campaignUrl($c5035Origin, $c5035Origin . '/novinky/clanek?x=1#kotva', $c5035Subject, 42, C5035Mailing::TAG_MIN);
    $c5035Keys[] = Kaleta\Front\Forms::campaignText(Kaleta\Front\Forms::campaign($c5035Url, $c5035Origin)) === C5035Results::campaignText(['subject' => $c5035Subject, 'id' => 42]);
}
check('3.54 C: the results read the campaign text the statistics store for a visit through the newsletter\'s link (three subjects)', $c5035Keys, [true, true, true]);
check('3.54 C: below TAG_MIN the link carries no campaign name – so the e-mail is untagged and its visits are unknown, never 0', [
    str_contains(C5035Mailing::campaignUrl($c5035Origin, $c5035Origin . '/', 'Pro tři lidi', 7, C5035Mailing::TAG_MIN - 1), 'utm_campaign='),
    str_contains(C5035Mailing::campaignUrl($c5035Origin, $c5035Origin . '/', 'Pro tři lidi', 7, C5035Mailing::TAG_MIN), 'utm_campaign=pro-tri-lidi-n7')], [false, true]);

// visits over an SQLite copy of the campaign table: from the start day, only with a campaign name in the frozen e-mail,
// only with the statistics on, never older than kept; the list's grouped query gives the same as the single one
$c5035App = function (string $extensions): Kaleta\Core\App {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    $pdo->exec('CREATE TABLE ka_stat_kampane (den TEXT NOT NULL, kampan TEXT NOT NULL, navstevy INTEGER NOT NULL, PRIMARY KEY (den, kampan))');
    $s = new Kaleta\Core\Settings($db);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['extensions' => $extensions]);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'db'))->setValue($app, $db);
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $s);

    return $app;
};
Kaleta\Core\Clock::freeze('2026-11-25 10:00:00');
$c5035On = $c5035App('newsletter,statistika');
$c5035Sent = ['id' => 5, 'subject' => 'Podzimní novinky', 'started_at' => '2026-11-20 09:00:00', 'sent_count' => 40, 'failed_count' => 1, 'skipped_count' => 2, 'unsubscribed_count' => 3,
    'html' => '<a href="https://firma.example/?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=podzimni-novinky-n5">x</a>'];
$c5035Other = ['id' => 6, 'subject' => 'Zimní novinky', 'started_at' => '2026-11-24 09:00:00', 'tagged' => 1];
foreach ([['2026-11-19', $c5035Sent, 9], ['2026-11-20', $c5035Sent, 4], ['2026-11-23', $c5035Sent, 1], ['2026-11-24', $c5035Other, 6], ['2026-11-21', ['id' => 99, 'subject' => 'Jiný'], 50]] as [$c5035Day, $c5035N, $c5035Visits]) {
    $c5035On->db()->run('INSERT INTO ka_stat_kampane (den, kampan, navstevy) VALUES (?, ?, ?)', [$c5035Day, C5035Results::campaignText($c5035N), $c5035Visits]);
}
$c5035Untagged = ['html' => '<p>no link to the site</p>'] + $c5035Sent;
check('3.54 C: sent, failed, skipped, unsubscribed and visits since the start day (the day before and another newsletter not counted)', C5035Mailing::results($c5035On, $c5035Sent),
    ['sent' => 40, 'failed' => 1, 'skipped' => 2, 'unsubscribed' => 3, 'visits' => 5]);
check('3.54 C: visits are unknown (null) without a campaign name, with the statistics off, before the start and after 400 days', [
    C5035Mailing::results($c5035On, $c5035Untagged)['visits'], C5035Mailing::results($c5035App('newsletter'), $c5035Sent)['visits'],
    C5035Mailing::results($c5035On, ['started_at' => null] + $c5035Sent)['visits'], C5035Mailing::results($c5035On, ['started_at' => '2025-10-20 09:00:00'] + $c5035Sent)['visits']],
    [null, null, null, null]);
$c5035Many = C5035Results::ofMany($c5035On, [array_diff_key($c5035Sent, ['html' => true]) + ['tagged' => 1], $c5035Other, ['id' => 7, 'subject' => 'Koncept', 'started_at' => null]]);
check('3.54 C: the list (one grouped query, the tagged flag of Mailing::all) gives each newsletter the same visits', [$c5035Many[5]['visits'], $c5035Many[6]['visits'], $c5035Many[7]['visits']], [5, 6, null]);
Kaleta\Core\Clock::freeze(null);
