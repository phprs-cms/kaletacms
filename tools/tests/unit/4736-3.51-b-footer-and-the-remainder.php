<?php

declare(strict_types=1);

/* ---------- 3.51 B5 and B1: the footer names the sender and the reason per language; recipients = sent + failed + skipped ---------- */
use Kaleta\Core\Mailing as B736Mailing;
use Kaleta\Core\Settings as B736Settings;

$b736Settings = function (array $values): B736Settings {
    $s = new B736Settings(new Kaleta\Core\Db('invalid-b736:', '', ''));
    (new ReflectionProperty(B736Settings::class, 'values'))->setValue($s, $values + ['site_language' => 'cs', 'additional_languages' => 'en',
        'company_name' => 'Firma s.r.o.', 'company_street' => 'Hlavní 1', 'company_postcode' => '110 00', 'company_city' => 'Praha', 'company_country' => 'CZ']);
    (new ReflectionProperty(B736Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(B736Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
check('3.51 B5: footer() – the default wording of each language with the company, the address and the site; a stored wording of a language wins; no company = no sender line', [
    B736Mailing::footer($b736Settings([]), '', 'Firma'), B736Mailing::footer($b736Settings([]), 'en', 'Firma EN'),
    B736Mailing::footer($b736Settings(['mail_identity_en' => 'From {{company}} ({{address}})', 'newsletter_reason_en' => 'You asked for news of {{site}}.']), 'en', 'Firma EN'),
    B736Mailing::footer($b736Settings(['company_name' => '']), 'en', 'Firma')['identity']],
    [['identity' => 'Odesílatel: Firma s.r.o., Hlavní 1, 110 00 Praha.', 'reason' => 'Tento e-mail dostáváte, protože jste se přihlásili k odběru novinek z webu Firma a přihlášení potvrdili.', 'privacy' => ''],
        ['identity' => 'Sent by Firma s.r.o., Hlavní 1, 110 00 Praha.', 'reason' => 'You receive this e-mail because you signed up for news from Firma EN and confirmed it.', 'privacy' => ''],
        ['identity' => 'From Firma s.r.o. (Hlavní 1, 110 00 Praha)', 'reason' => 'You asked for news of Firma EN.', 'privacy' => ''], null]);
$b736Registered = ['company_id' => '12345678', 'company_register' => 'Krajský soud v Brně, C 1234', 'cookies_policy_url' => '/zasady', 'cookies_policy_url_en' => 'https://example.com/privacy'];
check('3.51 (owner decisions): the sender line names the company ID and the register entry in the e-mail\'s language; one of them alone; neither = the line ends after the address; the privacy policy link of the language version, absolute, the default language\'s when a version has none, none without the site address or the setting', [
    Kaleta\Core\Language::runWith('cs', fn () => B736Mailing::footer($b736Settings($b736Registered), '', 'Firma', 'https://firma.cz')),
    Kaleta\Core\Language::runWith('en', fn () => B736Mailing::footer($b736Settings($b736Registered), 'en', 'Firma', 'https://firma.cz'))['identity'],
    Kaleta\Core\Language::runWith('en', fn () => B736Mailing::footer($b736Settings($b736Registered), 'en', 'Firma', 'https://firma.cz'))['privacy'],
    Kaleta\Core\Language::runWith('en', fn () => B736Mailing::footer($b736Settings(['company_id' => '12345678', 'cookies_policy_url' => '/zasady']), 'en', 'Firma', 'https://firma.cz')),
    Kaleta\Core\Language::runWith('en', fn () => B736Mailing::footer($b736Settings(['company_register' => 'Amtsgericht Berlin HRB 1.']), 'en', 'Firma'))['identity'],
    B736Mailing::footer($b736Settings(['cookies_policy_url' => '/zasady']), '', 'Firma')['privacy'],
    B736Mailing::footer($b736Settings(['cookies_policy_url' => 'javascript:alert(1)']), '', 'Firma', 'https://firma.cz')['privacy']],
    [['identity' => 'Odesílatel: Firma s.r.o., Hlavní 1, 110 00 Praha. IČO 12345678, Krajský soud v Brně, C 1234.', 'reason' => 'Tento e-mail dostáváte, protože jste se přihlásili k odběru novinek z webu Firma a přihlášení potvrdili.', 'privacy' => 'https://firma.cz/zasady'],
        'Sent by Firma s.r.o., Hlavní 1, 110 00 Praha. Company ID 12345678, Krajský soud v Brně, C 1234.', 'https://example.com/privacy',
        ['identity' => 'Sent by Firma s.r.o., Hlavní 1, 110 00 Praha. Company ID 12345678.', 'reason' => 'You receive this e-mail because you signed up for news from Firma and confirmed it.', 'privacy' => 'https://firma.cz/zasady'],
        'Sent by Firma s.r.o., Hlavní 1, 110 00 Praha. Amtsgericht Berlin HRB 1.', '', '']);
check('3.51 B5: {{reason}} in the introduction is the reason sentence; empty lines start paragraphs', B736Mailing::paragraphs("Dobrý den,\n\n{{reason}}\n\n\n Díky ", 'Protože jste chtěli.'),
    ['Dobrý den,', 'Protože jste chtěli.', 'Díky']);

// the remainder: an SQLite copy; finish() ends a newsletter whatever happened to its queue rows during the send
$b736Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$b736Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($b736Db, 'pdo'))->setValue($b736Db, $b736Pdo);
$b736Pdo->exec("CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, status TEXT, recipients INTEGER DEFAULT 0, sent_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, finished_at TEXT NULL)");
$b736Pdo->exec("CREATE TABLE ka_newsletter_queue (id INTEGER PRIMARY KEY, newsletter_id INTEGER, subscriber_id INTEGER, attempts INTEGER DEFAULT 0, next_attempt TEXT NULL, sent_at TEXT NULL, error TEXT DEFAULT '', reply TEXT DEFAULT '', tried_at TEXT NULL)");
$b736Pdo->exec("CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, email TEXT, stav INTEGER)");
// 1: 6 recipients – 3 sent, 1 failed, one unsubscribed in the meantime (row done, 'unsubscribed'), one erased (its row deleted);
// 2: cancelled while 4 of 10 were sent and 5 still waited; 3: more counted than recipients (never below 0); 4: still waiting rows, not finished
$b736Pdo->exec("INSERT INTO ka_newsletters (id, status, recipients, sent_count, failed_count) VALUES (1, 'sending', 6, 3, 1), (2, 'paused', 10, 4, 1), (3, 'sending', 2, 2, 1), (4, 'sending', 3, 1, 0)");
$b736Pdo->exec("INSERT INTO ka_newsletter_queue (newsletter_id, subscriber_id, next_attempt, sent_at, error) VALUES (1, 1, NULL, '2026-11-22 09:00:00', ''), (1, 2, NULL, '2026-11-22 09:00:00', ''),
    (1, 3, NULL, '2026-11-22 09:00:00', ''), (1, 4, NULL, NULL, 'x'), (1, 5, NULL, NULL, 'unsubscribed'),
    (2, 1, NULL, '2026-11-22 09:00:00', ''), (2, 2, '2026-11-22 10:00:00', NULL, ''), (2, 3, '2026-11-22 10:00:00', NULL, ''), (4, 1, '2026-11-22 10:00:00', NULL, '')");
$b736Finish = new ReflectionMethod(B736Mailing::class, 'finish');
$b736Sent = "status = 'sending' AND NOT EXISTS (SELECT 1 FROM {newsletter_queue} q WHERE q.newsletter_id = {newsletters}.id AND q.next_attempt IS NOT NULL)";
$b736Ends = [$b736Finish->invoke(null, $b736Db, 1, $b736Sent, 'sent'), $b736Finish->invoke(null, $b736Db, 2, "status IN ('sending', 'paused')", 'cancelled'),
    $b736Finish->invoke(null, $b736Db, 3, $b736Sent, 'sent'), $b736Finish->invoke(null, $b736Db, 4, $b736Sent, 'sent'), $b736Finish->invoke(null, $b736Db, 2, "status IN ('sending', 'paused')", 'cancelled')];
$b736Rows = $b736Db->all('SELECT id, status, recipients, sent_count, failed_count, skipped_count, finished_at IS NOT NULL AS ended FROM {newsletters} ORDER BY id');
check('3.51 B1: the end – skipped_count is the remainder, so recipients = sent + failed + skipped after an unsubscribe, an erasure (its row gone) and a cancel; never below 0; a newsletter with rows waiting does not end; a second cancel does nothing', [
    $b736Ends, array_map(fn (array $r): string => $r['status'] . '|' . $r['skipped_count'] . '|' . $r['ended'], $b736Rows),
    array_map(fn (array $r): bool => in_array($r['status'], ['sent', 'cancelled'], true) ? (int) $r['recipients'] === $r['sent_count'] + $r['failed_count'] + $r['skipped_count'] : true, array_slice($b736Rows, 0, 2)),
    $b736Db->all("SELECT COUNT(*) AS n FROM {newsletter_queue} WHERE newsletter_id = 2 AND next_attempt IS NULL AND error = 'cancelled'")[0]['n']],
    [[true, true, true, false, false], ['sent|2|1', 'cancelled|5|1', 'sent|0|1', 'sending|0|0'], [true, true], 2]);
