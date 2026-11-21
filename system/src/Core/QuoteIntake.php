<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.49 (from Core\Quotes): a quote from an enquiry – the lines a new quote of an enquiry starts with (its basket, the
 * product page it was sent from, the products its fields name), the draft Kaleta prepares for a new enquiry about products
 * after the response (quote_auto_draft, at most AUTO_DRAFT_DAILY_MAX a day) and the draft that goes with an enquiry sorted
 * out as spam. Core\Quotes keeps forwards under the old names (index.php calls Quotes::afterResponse).
 */
final class QuoteIntake
{
    /** 3.42 review M1: an enquiry's field values are read for products at most this many times, each value in at most PARTS_MAX parts. */
    public const int LOOKUPS_MAX = 50;
    public const int PARTS_MAX = 30;

    /** 3.42: the setting "prepare a draft quote for new enquiries about products" (off by default). */
    public const string AUTO_DRAFT_SETTING = 'quote_auto_draft';

    /** 3.44 (N168 a): at most this many drafts are prepared automatically in one day. */
    public const int AUTO_DRAFT_DAILY_MAX = 50;

    /** @var list<int> enquiries whose draft is prepared after the response (prepareLater) */
    private static array $preparing = [];

    /**
     * 3.42: a new enquiry from the site's form – its draft quote is prepared after the response (afterResponse(), index.php),
     * so the visitor never waits; nothing is queued while the feature or the setting is off.
     */
    public static function prepareLater(App $app, int $enquiryId): void
    {
        $s = $app->settings();
        if ($enquiryId > 0 && Prices::isOn($s) && $s->bool(self::AUTO_DRAFT_SETTING)) {
            self::$preparing[] = $enquiryId;
        }
    }

    /** After the page was sent (index.php, after the customer link): prepares what prepareLater() queued. Never fails the request. */
    public static function afterResponse(App $app): void
    {
        if (self::$preparing === []) {
            return;
        }
        $ids = self::$preparing;
        self::$preparing = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        foreach ($ids as $id) {
            try {
                self::prepareDraft($app, $id);
            } catch (\Throwable) {
                // a courtesy – the enquiry stands, a person makes the quote from it
            }
        }
    }

    /** How many drafts prepareLater() queued in this request (tests). */
    public static function preparing(): int
    {
        return count(self::$preparing);
    }

    /**
     * 3.42: prepares a DRAFT quote for an enquiry about products – when the feature and AUTO_DRAFT_SETTING are on, the
     * enquiry is not spam, a job application or anonymised, no quote of it exists yet (one draft per enquiry, never
     * sent), and linesFromEnquiry() matched at least one product. Linked like "New quote" from the enquiry (its customer,
     * open deal); the responsible person is the enquiry's, else the deal's, contact's or company's, when they may open
     * Quotes – they get a bell that names the quote only (no amount). 3.44 (N168 a): at most AUTO_DRAFT_DAILY_MAX a day.
     * Returns the new quote's id or null.
     */
    public static function prepareDraft(App $app, int $enquiryId): ?int
    {
        $db = $app->db();
        $s = $app->settings();
        if (!Prices::isOn($s) || !$s->bool(self::AUTO_DRAFT_SETTING)) {
            return null;
        }
        $enquiry = $db->one('SELECT idp, kategorie, prirazeno, contact_id, anonymizovano FROM {poptavky} WHERE idp = ? AND ' . EnquiryVisibility::visible($db, $s, false), [$enquiryId]);
        if ($enquiry === null || in_array((string) $enquiry['kategorie'], ['spam', 'job'], true) || $enquiry['anonymizovano'] !== null
            || $db->value('SELECT 1 FROM {quotes} WHERE enquiry_id = ? LIMIT 1', [$enquiryId]) !== null) {
            return null;
        }
        $found = self::linesFromEnquiry($app, $enquiryId);
        if ($found['matched'] === 0) {
            return null;
        }
        // 3.44 (N168 a): at most AUTO_DRAFT_DAILY_MAX prepared drafts a day – a flood of enquiries naming products stays
        // enquiries; the first one over the cap leaves one warning in the events of the day. 3.44 review L4: counted under a
        // lock in the marker quote_auto_drafts_day, so parallel enquiries cannot pass it together and a draft discarded with
        // its spam enquiry still counts
        $lock = $db->lockName('quote-auto-drafts');
        if ((int) $db->value('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            return null;
        }
        try {
            $today = Clock::today();
            [$day, $count] = array_pad(explode('|', (string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'quote_auto_drafts_day'") ?? '')), 2, '0');
            $count = $day === $today ? (int) $count : 0;
            if ($count >= self::AUTO_DRAFT_DAILY_MAX) {
                if ($db->value("SELECT 1 FROM {events} WHERE type = 'quote.auto_draft_limit' AND created_at >= ? LIMIT 1", [$today . ' 00:00:00']) === null) {
                    Events::record($db, 'quote.auto_draft_limit', 'warning', mb_substr(t('Draft quotes are no longer prepared today – %d were prepared already.', self::AUTO_DRAFT_DAILY_MAX), 0, 255), ['enquiry' => $enquiryId]);
                }

                return null;
            }
            $s->set('quote_auto_drafts_day', $today . '|' . ($count + 1));
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $owner = 0;
        $deal = Deals::openForEnquiry($db, $enquiryId);
        $contact = $enquiry['contact_id'] !== null ? Contacts::find($db, (int) $enquiry['contact_id']) : null;
        $company = $contact !== null && $contact['company_id'] !== null ? Contacts::findCompany($db, (int) $contact['company_id']) : null;
        foreach ([$enquiry['prirazeno'], $deal !== null ? (Deals::get($db, $deal)['owner_id'] ?? null) : null, $contact['owner_id'] ?? null, $company['owner_id'] ?? null] as $candidate) {
            if ($candidate !== null && (int) $candidate > 0 && Quotes::mayOpen($db, $s, (int) $candidate)) {
                $owner = (int) $candidate;
                break;
            }
        }
        $id = Quotes::save($app, null, ['enquiry_id' => $enquiryId, 'lines' => $found['lines'], 'owner_id' => $owner], 0)['id'];
        if ($owner > 0) {
            Inbox::notify($app, $owner, 'quote_prepared', 'quote:' . $id, null);
        }

        return $id;
    }

    /**
     * 3.42 review L6: an enquiry sorted out as spam (Core\Triage::save – a person, Claude or the assistant) takes its
     * automatically prepared draft along while nobody touched it: a draft without a number made by Kaleta itself
     * (created_by NULL, prepareDraft()) that was never saved since (updated_at = created_at). Its lines, tasks and bells go
     * too. Returns how many went.
     */
    public static function dropPreparedDraft(Db $db, int $enquiryId): int
    {
        try {
            $ids = array_map('intval', array_column($db->all("SELECT id FROM {quotes} WHERE enquiry_id = ? AND status = 'draft' AND number IS NULL AND created_by IS NULL"
                . ' AND sent_at IS NULL AND updated_at = created_at', [$enquiryId]), 'id'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before the quotes tables
        }
        foreach ($ids as $id) {
            $db->transaction(function (Db $db) use ($id): void {
                Tasks::forgetTargets($db, ['quote:' . $id]);
                Inbox::forgetTargets($db, ['quote:' . $id]);
                $db->run('DELETE FROM {quote_lines} WHERE quote_id = ?', [$id]);
                $db->run('DELETE FROM {quotes} WHERE id = ?', [$id]);
            });
        }

        return count($ids);
    }

    /**
     * The lines of an enquiry basket, as the form stored them ("2 × Name – Variant (code)", Builder\Products::basketLines):
     * quantity, name, variant name and code. Other text is ignored (pure).
     *
     * @return list<array{quantity: int, name: string, variant: string, code: string}>
     */
    public static function parseBasket(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*(\d{1,4})\s*×\s*(.+?)(?:\s+–\s+(.+?))?(?:\s+\(([^()]{1,60})\))?\s*$/u', $line, $m) === 1 && (int) $m[1] > 0) {
                $out[] = ['quantity' => (int) $m[1], 'name' => trim($m[2]), 'variant' => trim($m[3] ?? ''), 'code' => trim($m[4] ?? '')];
            }
        }

        return $out;
    }

    /**
     * The lines a new quote of an enquiry starts with: every basket line of the enquiry matched to a product – by its code
     * (a variant's or the product's), then by its name (and the variant's name) in the products collections; what cannot be
     * matched becomes a free line with its text and quantity and no price. 3.42: then the item whose detail page the form
     * was sent from (source kolekce:<idk> of a products collection – the products preset or one mapped in Quotes → Price list set-up
     * – and the item's address in the page; left out when a basket line or a field names that item already), and every
     * form field whose value names a product by its name or code (case does not matter; a field with several values –
     * ticked boxes, lines – is read value by value), each once with quantity 1. The prices come from the price list when the quote is saved (save()), never from the enquiry.
     *
     * @return array{lines: list<array<string, mixed>>, matched: int, unmatched: int}
     */
    public static function linesFromEnquiry(App $app, int $enquiryId): array
    {
        return Prices::withCodeIndex(fn (): array => self::linesFromEnquiryNow($app, $enquiryId));
    }

    /** @return array{lines: list<array<string, mixed>>, matched: int, unmatched: int} */
    private static function linesFromEnquiryNow(App $app, int $enquiryId): array
    {
        $db = $app->db();
        $enquiry = $db->one('SELECT data, zdroj, prvek, stranka FROM {poptavky} WHERE idp = ? AND ' . EnquiryVisibility::visible($db, $app->settings(), false), [$enquiryId]);
        $fields = json_decode((string) ($enquiry['data'] ?? ''), true);
        $fields = is_array($fields) ? $fields : [];
        // 3.44 (N163): a visitor's submission of a site form gives basket lines only from its basket field (the labels of the
        // form's basket fields in its published build) – a "1 × …" line typed into a message is plain text; an enquiry
        // without a site form (added by staff or imported) is read as before
        $fromForm = $enquiry !== null && (string) $enquiry['zdroj'] !== '' && (string) $enquiry['prvek'] !== '';
        $basketLabels = $fromForm ? Prices::basketLabels($db, (string) $enquiry['zdroj'], (string) $enquiry['prvek'], (string) $enquiry['stranka']) : [];
        $lines = [];
        $matched = 0;
        $unmatched = 0;
        $names = null;
        $seen = [];
        $plain = [];
        foreach ($fields as $field) {
            $isBasket = is_array($field) && (!$fromForm || (!isset($field[2]) && in_array((string) ($field[0] ?? ''), $basketLabels, true)));
            $basket = $isBasket ? self::parseBasket((string) ($field[1] ?? '')) : [];
            if ($basket === [] && is_array($field) && !isset($field[2]) && is_scalar($field[1] ?? null)) {
                $plain[] = (string) $field[1]; // 3.42: read below for product names and codes (an attachment – [2] – never)
            }
            foreach ($basket as $b) {
                $hit = $b['code'] !== '' ? Prices::findCode($db, $b['code']) : null;
                if ($hit === null) {
                    $names ??= self::productNames($db);
                    $item = $names[mb_strtolower($b['name'])] ?? null;
                    if ($item !== null) {
                        $variant = '';
                        foreach ($item['variants'] as $v) {
                            if ($b['variant'] !== '' && mb_strtolower($v['name']) === mb_strtolower($b['variant'])) {
                                $variant = $v['code'];
                            }
                        }
                        $hit = ($b['variant'] === '' || $variant !== '') ? ['item_id' => $item['idp'], 'variant_code' => $variant] : null;
                    }
                }
                if ($hit !== null) {
                    $lines[] = ['item_id' => $hit['item_id'], 'variant_code' => $hit['variant_code'], 'quantity' => (string) $b['quantity']];
                    $seen[$hit['item_id'] . ':' . $hit['variant_code']] = true;
                    $matched++;
                } else {
                    $lines[] = ['name' => $b['name'] . ($b['variant'] !== '' ? ' – ' . $b['variant'] : ''), 'code' => $b['code'], 'quantity' => (string) $b['quantity'], 'unit_price' => null];
                    $unmatched++;
                }
                if (count($lines) >= Quotes::MAX_LINES) {
                    break 2;
                }
            }
        }
        if ($enquiry !== null) {
            $found = [];
            $budget = self::LOOKUPS_MAX;
            foreach ($plain as $value) {
                array_push($found, ...self::namedProducts($db, $value, $names, $budget));
            }
            // the item of the page, unless a field already names it (or one of its variants)
            $page = self::pageItem($db, (string) $enquiry['zdroj'], (string) $enquiry['stranka']);
            if ($page !== null && !in_array($page, array_column($found, 'item_id'), true) && !in_array($page, array_column($lines, 'item_id'), true)) {
                array_unshift($found, ['item_id' => $page, 'variant_code' => '']);
            }
            foreach ($found as $hit) {
                $key = $hit['item_id'] . ':' . $hit['variant_code'];
                if (isset($seen[$key]) || count($lines) >= Quotes::MAX_LINES) {
                    continue;
                }
                $seen[$key] = true;
                $lines[] = ['item_id' => $hit['item_id'], 'variant_code' => $hit['variant_code'], 'quantity' => '1'];
                $matched++;
            }
        }

        return ['lines' => $lines, 'matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * 3.42: the item whose detail page an enquiry was sent from – the source kolekce:<idk> names a products collection
     * (Prices::productCollections) and the page address ends with /<collection>/<item> (the reading of Front\EnquiryTopic);
     * the default-language item when several share the address. null otherwise.
     */
    private static function pageItem(Db $db, string $source, string $page): ?int
    {
        if (preg_match('/^kolekce:(\d+)$/D', $source, $m) !== 1) {
            return null;
        }
        $collection = array_values(array_filter(Prices::productCollections($db), fn (array $c): bool => $c['idk'] === (int) $m[1]))[0] ?? null;
        if ($collection === null || $collection['seo_link'] === ''
            || preg_match('#/' . preg_quote($collection['seo_link'], '#') . '/([a-z0-9-]{1,160})/?(?:\?.*)?$#', $page, $slug) !== 1) {
            return null;
        }
        $id = $db->value("SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND smazano IS NULL ORDER BY jazyk = '' DESC, idp LIMIT 1", [$collection['idk'], $slug[1]]);

        return $id === null ? null : (int) $id;
    }

    /**
     * 3.42: the products a form value names – the whole value, or else each of its parts (lines, or values separated by a
     * comma or semicolon, as ticked boxes are stored): a product's code or variant code (Prices::findCode), a product's
     * name, or "Name – Variant". Case does not matter. Nothing for a long text (a message is not a choice).
     *
     * @param array<string, array<string, mixed>>|null $names productNames(), loaded on first need
     * @return list<array{item_id: int, variant_code: string}>
     */
    private static function namedProducts(Db $db, string $value, ?array &$names, int &$budget): array
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 1000 || $budget <= 0) {
            return [];
        }
        $one = function (string $text) use ($db, &$names, &$budget): ?array {
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > 250 || $budget <= 0) {
                return null;
            }
            $budget--;
            $hit = Prices::findCode($db, $text);
            if ($hit !== null) {
                return $hit;
            }
            $names ??= self::productNames($db);
            $item = $names[mb_strtolower($text)] ?? null;
            if ($item !== null) {
                return ['item_id' => $item['idp'], 'variant_code' => ''];
            }
            if (preg_match('/^(.+?)\s+[–-]\s+(.+)$/u', $text, $m) === 1 && ($item = $names[mb_strtolower(trim($m[1]))] ?? null) !== null) {
                foreach ($item['variants'] as $v) {
                    if ($v['code'] !== '' && mb_strtolower($v['name']) === mb_strtolower(trim($m[2]))) {
                        return ['item_id' => $item['idp'], 'variant_code' => $v['code']];
                    }
                }
            }

            return null;
        };
        $whole = $one($value);
        if ($whole !== null) {
            return [$whole];
        }
        $out = [];
        foreach (array_slice(preg_split('/\R|[,;]\s*/u', $value) ?: [], 0, self::PARTS_MAX) as $part) {
            $hit = $one($part);
            if ($hit !== null) {
                $out[] = $hit;
            }
        }

        return $out;
    }

    /** Lower-case product name => item, for matching basket lines by name (default language first). @return array<string, array<string, mixed>> */
    private static function productNames(Db $db): array
    {
        $out = [];
        foreach (Prices::productCollections($db) as $c) {
            foreach (Prices::itemsOf($db, $c['idk']) as $item) {
                $out[mb_strtolower($item['name'])] ??= $item;
            }
        }

        return $out;
    }
}
