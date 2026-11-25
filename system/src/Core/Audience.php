<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.54 (A1): who a newsletter goes to. A newsletter goes to every confirmed subscriber (audience "all") or to the
 * confirmed subscribers of its language ("language"; '' is the default language on both sides), optionally narrowed to
 * customers (audience_filter "customers": a won deal or a quote the customer accepted – the rule of testimonial requests).
 * where() is the one definition of the recipients that Core\Mailing uses for the count shown, the send guard, the
 * campaign-name rule and the queue. Everything here fails closed: an unknown value matches nobody and is refused, never
 * read as "everyone".
 */
final class Audience
{
    /** The audience (column ka_newsletters.audience) => the English label (t() in the administration). */
    public const array AUDIENCES = ['all' => 'Everyone confirmed', 'language' => 'Subscribers of this newsletter\'s language'];

    /** The filter on top of the audience (column audience_filter) => the English label. */
    public const array FILTERS = ['' => 'Everyone in the audience', 'customers' => 'Only customers'];

    /** @var \WeakMap<Db, list<int>>|null the contacts that accepted a quote themself, read once per request and database */
    private static ?\WeakMap $acceptedQuotes = null;

    /**
     * Why this newsletter's audience cannot be sent to, or null when it can: an unknown audience or filter value is refused,
     * and "only customers" is refused while neither the sales pipeline nor quotes is switched on – never a wider audience
     * instead. A missing key is the column default (all, no filter).
     *
     * @param array<string, mixed> $n
     */
    public static function problem(App $app, array $n): ?string
    {
        if (!isset(self::AUDIENCES[(string) ($n['audience'] ?? 'all')])) {
            return 'The newsletter has an unknown audience – choose who it goes to again.';
        }
        $filter = (string) ($n['audience_filter'] ?? '');
        if (!isset(self::FILTERS[$filter])) {
            return 'The newsletter has an unknown audience filter – choose who it goes to again.';
        }
        if ($filter === 'customers' && !self::customersAvailable($app->settings())) {
            return 'Only customers needs the sales pipeline or quotes switched on in Extensions – switch one on, or send to everyone in the audience.';
        }

        return null;
    }

    /** Can a newsletter be narrowed to customers on this site (a won deal needs the pipeline, an accepted quote the quotes)? */
    public static function customersAvailable(Settings $s): bool
    {
        return Extensions::isEnabled($s, 'pipeline') || Extensions::isEnabled($s, Prices::EXTENSION);
    }

    /**
     * The recipients as a WHERE over "{odberatele} o": always confirmed (o.stav = 1); the audience "language" adds the
     * newsletter's language; the filter "customers" adds the customer set, joined on contact_id – never on the address (a
     * merge maps a second address to one contact), so a subscriber not linked to a contact yet is not a customer. An
     * audience problem() refuses matches nobody.
     *
     * @param array<string, mixed> $n
     * @return array{0: string, 1: list<mixed>}
     */
    public static function where(App $app, array $n): array
    {
        if (self::problem($app, $n) !== null) {
            return ['0 = 1', []];
        }
        [$where, $params] = self::base($n);
        if ((string) ($n['audience_filter'] ?? '') === 'customers') {
            [$customers, $more] = self::customerCondition($app);
            $where .= ' AND ' . $customers;
            $params = [...$params, ...$more];
        }

        return [$where, $params];
    }

    /**
     * The confirmed subscribers of the audience (its language, without the customers filter) not linked to a customer
     * record yet – none of them counts as a customer until the linking job reaches them (Contacts::linkWaitingSubscribers).
     *
     * @param array<string, mixed> $n
     */
    public static function unlinked(App $app, array $n): int
    {
        if (self::problem($app, $n) !== null) {
            return 0;
        }
        [$where, $params] = self::base($n);

        return (int) $app->db()->value('SELECT COUNT(*) FROM {odberatele} o WHERE ' . $where . ' AND o.contact_id IS NULL', $params);
    }

    /**
     * Why the audience is empty, in the words of that audience (English; t() in the administration, as is in MCP).
     *
     * @param array<string, mixed> $n
     */
    public static function emptyText(App $app, array $n): string
    {
        $db = $app->db();
        if (Mailing::confirmedCount($db) === 0) {
            return 'There are no confirmed subscribers yet.';
        }
        [$where, $params] = self::base($n);
        if ((int) $db->value('SELECT COUNT(*) FROM {odberatele} o WHERE ' . $where, $params) === 0) {
            return 'There are no confirmed subscribers of this newsletter\'s language yet.';
        }

        return self::unlinked($app, $n) > 0
            ? 'None of the confirmed subscribers is a customer yet – some of them are not linked to a customer record yet; that happens within a few minutes.'
            : 'None of the confirmed subscribers is a customer yet.';
    }

    /**
     * What decides who a newsletter goes to – the audience, the filter and, for the audience "language", the language: a
     * scheduled newsletter cannot change it (Mailing::save) and a restore must not change it (Core\RestoreLedger).
     *
     * @param array<string, mixed> $n
     */
    public static function key(array $n): string
    {
        $audience = (string) ($n['audience'] ?? 'all');

        return $audience . '|' . (string) ($n['audience_filter'] ?? '') . ($audience === 'language' ? '|' . (string) ($n['language'] ?? '') : '');
    }

    /**
     * The filter from the input of the administration or Claude – audience_filter ('' | customers) or the boolean
     * customers_only of MCP, parsed strictly (true/false, 1/0, "true"/"false"; the string "false" is false). Anything else
     * is refused, never read as "everyone"; both keys given must agree. Neither key keeps the current value.
     *
     * @param array<string, mixed> $input
     */
    public static function filterFrom(array $input, string $current): string
    {
        $filter = null;
        if (array_key_exists('audience_filter', $input)) {
            $filter = is_string($input['audience_filter']) ? trim($input['audience_filter']) : null;
            if ($filter === null || !isset(self::FILTERS[$filter])) {
                throw new \InvalidArgumentException('audience_filter must be empty or customers.');
            }
        }
        if (array_key_exists('customers_only', $input)) {
            $only = $input['customers_only'];
            $flag = match (true) {
                $only === true, $only === 1, is_string($only) && in_array(strtolower(trim($only)), ['1', 'true'], true) => true,
                $only === false, $only === 0, is_string($only) && in_array(strtolower(trim($only)), ['0', 'false'], true) => false,
                default => throw new \InvalidArgumentException('customers_only must be true or false.'),
            };
            if ($filter !== null && $filter !== ($flag ? 'customers' : '')) {
                throw new \InvalidArgumentException('customers_only and audience_filter disagree.');
            }
            $filter = $flag ? 'customers' : '';
        }

        return $filter ?? (isset(self::FILTERS[$current]) ? $current : throw new \InvalidArgumentException('audience_filter must be empty or customers.'));
    }

    /** The customer set is read again on the next call (tests; a long run that changed quotes). */
    public static function forgetCustomers(): void
    {
        self::$acceptedQuotes = null;
    }

    /**
     * Confirmed, and of the newsletter's language for the audience "language" (a subscriber from before 3.51 whose language
     * could not be told is '' too, so the default language's newsletter has them).
     *
     * @param array<string, mixed> $n
     * @return array{0: string, 1: list<mixed>}
     */
    private static function base(array $n): array
    {
        return (string) ($n['audience'] ?? 'all') === 'language' ? ['o.stav = 1 AND o.language = ?', [(string) ($n['language'] ?? '')]] : ['o.stav = 1', []];
    }

    /**
     * The customer set as a condition on o.contact_id – a won deal (Deals::wonContactsSql, while the pipeline is on) or a
     * quote the customer accepted (Quotes::customerAcceptedContactIds, while quotes are on): the rule of testimonial
     * requests (Testimonials::maySend), each half only with its feature. Nothing on = nobody.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function customerCondition(App $app): array
    {
        $s = $app->settings();
        $db = $app->db();
        $parts = [];
        $params = [];
        if (Extensions::isEnabled($s, 'pipeline')) {
            [$sql, $won] = Deals::wonContactsSql($s);
            $parts[] = 'o.contact_id IN (' . $sql . ')';
            $params = [...$params, ...$won];
        }
        if (Extensions::isEnabled($s, Prices::EXTENSION)) {
            self::$acceptedQuotes ??= new \WeakMap();
            $ids = self::$acceptedQuotes[$db] ??= Quotes::customerAcceptedContactIds($db);
            if ($ids !== []) {
                $parts[] = 'o.contact_id IN (' . implode(',', array_map('intval', $ids)) . ')';
            }
        }

        return $parts === [] ? ['0 = 1', []] : ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
