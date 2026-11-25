<?php

declare(strict_types=1);

/* ---------- 3.54 A1: the customer set of a newsletter is the per-contact rule of testimonial requests – a won deal or a quote the customer accepted, each only with its feature ---------- */
use Kaleta\Core\Deals as A5010Deals;
use Kaleta\Core\Mailing as A5010Mailing;
use Kaleta\Core\Quotes as A5010Quotes;

// the App of unit 5000 ($a5000App), with deals and quotes: contact 1 won, 2 an open "won", 3 lost, 4 accepted a quote on its page, 5 a quote a
// person marked accepted, 6 accepted then declined, 7 won and accepted twice (counts once), 8 nothing; contact 9 is a merged contact with two addresses
$a5010Fixture = function (string $extensions) use ($a5000App): Kaleta\Core\App {
    $app = $a5000App(['extensions' => 'newsletter,customers,' . $extensions, 'pipeline_stages' => '']);
    $db = $app->db();
    foreach ([[1, 'won', '2026-10-01 10:00:00'], [2, 'won', null], [3, 'lost', '2026-10-01 10:00:00'], [7, 'won', '2026-10-02 10:00:00'], [9, 'won', '2026-10-03 10:00:00'], [8, 'proposal', null]] as [$c, $stage, $closed]) {
        $db->insert('deals', ['contact_id' => $c, 'stage' => $stage, 'closed_at' => $closed]);
    }
    $by = fn (string $to, string $via): array => ['action' => 'status', 'from' => 'sent', 'to' => $to] + ($via === 'customer' ? ['via' => 'customer'] : ['by' => 1]);
    foreach ([[4, 'accepted', [$by('accepted', 'customer')]], [5, 'accepted', [$by('accepted', 'person')]], [6, 'declined', [$by('accepted', 'customer'), $by('declined', 'customer')]],
        [7, 'accepted', [$by('accepted', 'customer')]], [7, 'accepted', [$by('accepted', 'customer')]], [8, 'accepted', null]] as [$c, $status, $history]) {
        $db->insert('quotes', ['contact_id' => $c, 'status' => $status, 'history' => $history !== null ? json_encode($history) : null]);
    }
    foreach (range(1, 8) as $c) {
        $db->insert('odberatele', ['email' => 'c' . $c . '@example.com', 'stav' => 1, 'language' => '', 'contact_id' => $c]);
    }
    $db->insert('odberatele', ['email' => 'nine@example.com', 'stav' => 1, 'language' => '', 'contact_id' => 9]);
    $db->insert('odberatele', ['email' => 'nine.second@example.com', 'stav' => 1, 'language' => 'en', 'contact_id' => 9]);
    $db->insert('odberatele', ['email' => 'nobody@example.com', 'stav' => 1, 'language' => '', 'contact_id' => null]);
    $db->insert('odberatele', ['email' => 'waiting@example.com', 'stav' => 0, 'language' => '', 'contact_id' => 1]);

    return $app;
};
$a5010Customers = function (Kaleta\Core\App $app): array {
    $db = $app->db();
    $ids = array_map('intval', array_column($db->all('SELECT DISTINCT o.contact_id FROM {odberatele} o WHERE ' . A5010Mailing::recipientsWhere($app, ['audience' => 'all', 'audience_filter' => 'customers'])[0]
        . ' ORDER BY o.contact_id', A5010Mailing::recipientsWhere($app, ['audience' => 'all', 'audience_filter' => 'customers'])[1]), 'contact_id'));
    $s = $app->settings();
    $perContact = array_values(array_filter(range(1, 9), fn (int $c): bool => (Kaleta\Core\Extensions::isEnabled($s, 'pipeline') && A5010Deals::hasWon($db, $c, $s))
        || (Kaleta\Core\Extensions::isEnabled($s, 'quotes') && A5010Quotes::acceptedByCustomer($db, $c))));

    return [$ids, $perContact, A5010Mailing::recipientCount($app, ['audience' => 'all', 'audience_filter' => 'customers'])];
};
// the stages: the default set of the pipeline (its kind won is the key 'won')
check('3.54 A1: the default pipeline has a stage of the kind won with the key "won" (the fixture relies on it)',
    in_array('won', array_column(array_filter(A5010Deals::parseStages(''), fn (array $st): bool => $st['kind'] === 'won'), 'key'), true), true);
check('3.54 A1: pipeline and quotes on – the set equals the per-contact rule (1, 4, 7, 9); the merged contact\'s two addresses are both mailed (11 recipients would be wrong: 5)', $a5010Customers($a5010Fixture('pipeline,quotes')),
    [[1, 4, 7, 9], [1, 4, 7, 9], 5]);
check('3.54 A1: only the pipeline – won deals only (1, 7, 9)', $a5010Customers($a5010Fixture('pipeline')), [[1, 7, 9], [1, 7, 9], 4]);
check('3.54 A1: only quotes – quotes the customer accepted only (4, 7); the one a person marked and the one declined later are not', $a5010Customers($a5010Fixture('quotes')), [[4, 7], [4, 7], 2]);
$a5010Off = $a5010Fixture('');
check('3.54 A1: neither feature – "only customers" is refused and matches nobody (and has no hint); the audience counts its subscriber without a contact', [
    A5010Mailing::audienceProblem($a5010Off, ['audience_filter' => 'customers']) !== null, A5010Mailing::recipientCount($a5010Off, ['audience_filter' => 'customers']),
    A5010Mailing::unlinkedCount($a5010Off, ['audience_filter' => 'customers']), A5010Mailing::unlinkedCount($a5010Off, ['audience' => 'all'])],
    [true, 0, 0, 1]);
$a5010Both = $a5010Fixture('pipeline,quotes');
check('3.54 A1: Quotes::customerAcceptedContactIds() – each contact once, sorted; Deals::wonContactsSql() is the predicate of hasWon', [
    A5010Quotes::customerAcceptedContactIds($a5010Both->db()),
    array_map('intval', array_column($a5010Both->db()->all(A5010Deals::wonContactsSql($a5010Both->settings())[0] . ' ORDER BY contact_id', A5010Deals::wonContactsSql($a5010Both->settings())[1]), 'contact_id')),
    A5010Deals::hasWon($a5010Both->db(), 9, $a5010Both->settings()), A5010Deals::hasWon($a5010Both->db(), 2, $a5010Both->settings())],
    [[4, 7], [1, 7, 9], true, false]);
check('3.54 A1: the customers audience of one language – the merged contact\'s English address only; the empty state names customers', [
    A5010Mailing::recipientCount($a5010Both, ['audience' => 'language', 'language' => 'en', 'audience_filter' => 'customers']),
    A5010Mailing::emptyAudience($a5010Fixture('quotes'), ['audience' => 'language', 'language' => 'en', 'audience_filter' => 'customers']),
    A5010Mailing::emptyAudience($a5010Both, ['audience' => 'all', 'audience_filter' => 'customers'])],
    [1, 'None of the confirmed subscribers is a customer yet.', 'None of the confirmed subscribers is a customer yet – some of them are not linked to a customer record yet; that happens within a few minutes.']);
