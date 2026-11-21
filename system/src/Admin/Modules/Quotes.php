<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\App;
use Kaleta\Core\Assignment;
use Kaleta\Core\Auth;
use Kaleta\Core\Contacts as Customers;
use Kaleta\Core\Db;
use Kaleta\Core\Deals;
use Kaleta\Core\Language;
use Kaleta\Core\Money;
use Kaleta\Core\Prices;
use Kaleta\Core\QuoteLineSets;
use Kaleta\Core\Quotes as Store;
use Kaleta\Core\Response;

/**
 * Quotes and internal prices (3.38, Core\Prices and Core\Quotes) in the Workspace group, for the people an administrator
 * chooses (GRANTED_EXPLICITLY: after the update only administrators open it; the feature "quotes" is off until switched
 * on). Nothing here ever changes the public site (Kernel::SITE_NEUTRAL_MODULES). Every action checks the section on the
 * server; changing a price also needs the area Auth::PRICES_AREA (Auth::canEditPrices).
 *
 *  - list: the quotes (the quote screens of the next part; until then it opens Internal prices).
 *  - prices: the grid of a price list – the products of the products collections with their variants, the own rows, a
 *    collection filter, a search, "without a price"; CSV export and import; the price lists (administrators).
 *  - product_search_json (3.49 C): the editor's choice of a product by a word, with the internal price – GET, read-only.
 *  - prices_save: the rows of the grid that changed (one change-log line with the count – never an amount).
 *  - item_prices_save: the box "Internal prices" on a product's form in Collections.
 *  - prices_export: the price list as CSV; prices_import: the uploaded CSV checked into a preview (new, changed, unknown);
 *    prices_import_run: writes it.
 *  - list_save, list_archive, list_default (administrators): add or rename a price list, archive or restore it, make it
 *    the default.
 *  - settings, settings_save (administrators): numbering and the next number, validity, VAT, the largest discount Claude
 *    may save, how long the customer's link works, retention, the deal value on sending; 3.42 the automatic draft.
 *  - setup (administrators, 3.45 U62): "Price list set-up" – the collections in the price list, the default texts and the
 *    saved line sets, so Settings is one form.
 *  - collections_save (administrators, 3.42): which collections feed the price list, their code and variants fields
 *    (Prices::saveMapping) – on Price list set-up (3.45: the one place; Internal prices links to it).
 *  - 3.45 (CPO 3): the list opens with the first steps of quotes for administrators (setupFacts, setupChecklist) until they
 *    are done or hidden (Admin\Kernel action hide_first_steps with list=quotes).
 */
final class Quotes extends Module
{
    use \Kaleta\Admin\QuoteSending; // 3.38: send, preview, link, link_withdraw, texts_save (the document, sending and the customer's link)
    use \Kaleta\Admin\QuoteExtras; // 3.40: task, follow_up, customer_list, line_set_rename, line_set_delete

    public const string IDENT = Prices::SECTION;
    public const string NAME = 'Quotes';
    public const string GROUP = 'Workspace';
    public const string ICON = 'clanek';
    public const string EXTENSION = Prices::EXTENSION;
    public const string HUB = 'sales'; // 3.48 (decision 6): under the Pipeline's item ("Sales") when the person has both
    public const string PARENT = 'pipeline';
    public const bool GRANTED_EXPLICITLY = true;

    /** Quotes on a page of the list, in the box of a record, products and deals the editor offers. */
    public const int PAGE = 30;
    public const int BOX = 10;
    public const int PRODUCT_CHOICES = 1000;
    public const int DEAL_CHOICES = 300;

    /** The label of a status in lists, boxes and the bar (the colours of views/admin: koncept, plan, vydano, chyba). */
    /**
     * The name of a quote on its screens (3.44, U59): a numbered one is "<number> – <title>"; a draft has no number yet, so it is
     * "Draft quote – <title, or the customer's company or name>" instead of "#12" (the list and MCP keep Quotes::label).
     *
     * @param array<string, mixed> $quote Store::get
     */
    public static function heading(array $quote): string
    {
        $title = trim((string) $quote['title']);
        if ($quote['number'] !== null && $quote['number'] !== '') {
            return $quote['number'] . ($title !== '' ? ' – ' . $title : '');
        }
        $customer = $quote['customer'] ?? [];
        $about = $title !== '' ? $title : trim((string) (($customer['company'] ?? '') !== '' ? $customer['company'] : ($customer['name'] ?? '')));

        return t('Draft quote') . ($about !== '' ? ' – ' . $about : '');
    }

    public static function statusClass(string $status): string
    {
        return 'stitek' . match ($status) {
            'draft' => ' stitek-koncept',
            'sent' => ' stitek-plan',
            'accepted' => ' stitek-vydano',
            'declined' => ' stitek-chyba',
            default => '',
        };
    }

    /** The session key of a checked CSV import waiting for its confirmation. */
    private const string IMPORT_KEY = 'prices_import';

    /** 3.45 (CPO 3): the setting an administrator sets with Hide on the first steps of quotes (Admin\Kernel, hide_first_steps). */
    public const string STEPS_HIDDEN = 'quotes_steps_hidden';

    /**
     * 3.45 (CPO 3): what the path to the first quote has done, read from the data – the company on the document, VAT, a
     * collection in the price list, a price, mail that can reach a customer (SMTP, as sending requires) and a quote that has
     * gone out (sent or finalised – a draft prepared by itself does not count).
     *
     * @return array{company: bool, vat: bool, collection: bool, prices: bool, mail: bool, quote: bool}
     */
    public static function setupFacts(App $app): array
    {
        $s = $app->settings();
        $db = $app->db();

        return [
            'company' => $s->get('company_name') !== '' && $s->get('company_street') !== '',
            'vat' => !$s->bool('quote_vat_payer') || Prices::defaultVatRate($s) !== null,
            'collection' => Prices::productCollections($db) !== [],
            'prices' => $db->value('SELECT 1 FROM {prices} LIMIT 1') !== null,
            'mail' => \Kaleta\Core\EnquiryReply::unavailable($s) === null,
            'quote' => $db->value('SELECT 1 FROM {quotes} WHERE number IS NOT NULL AND number <> \'\' LIMIT 1') !== null,
        ];
    }

    /**
     * 3.45 (CPO 3): the first steps of quotes in their order, each with its link (relative to admin.php) and whether it is
     * done; empty once every step is done. Pure – the facts come from setupFacts. The same path is the MCP prompt
     * set_up_quotes.
     *
     * @param array<string, bool> $done setupFacts
     * @return list<array{key: string, title: string, text: string, url: string, done: bool}>
     */
    public static function setupChecklist(array $done): array
    {
        $steps = [
            ['company', 'Fill in your company details', 'Name, address and ID numbers – the quote document shows them as the supplier.', 'admin.php?module=business'],
            ['vat', 'Set VAT and numbering', 'The default VAT rate (or that you are not a VAT payer) and how quotes are numbered.', 'admin.php?module=' . self::IDENT . '&action=settings'],
            ['collection', 'Put your products in the price list', 'Choose the collection with your products or services; each item gets a row in Internal prices.', 'admin.php?module=' . self::IDENT . '&action=setup#price-collections'],
            ['prices', 'Fill in prices', 'Internal prices without VAT, which only your team sees – and own rows such as installation or transport.', 'admin.php?module=' . self::IDENT . '&action=prices'],
            ['mail', 'Set up sending e-mail', 'Quotes go to customers through your mail server (SMTP), so they do not land in spam.', 'admin.php?module=settings&tab=mail'],
            ['quote', 'Send a test quote to yourself', 'Create a quote with “New quote”, send it to your own address and see what the customer gets.', 'admin.php?module=' . self::IDENT],
        ];
        $out = array_map(fn (array $k): array => ['key' => $k[0], 'title' => $k[1], 'text' => $k[2], 'url' => $k[3], 'done' => (bool) ($done[$k[0]] ?? false)], $steps);

        return array_filter($out, fn (array $k): bool => !$k['done']) === [] ? [] : $out;
    }

    /* ---------- the quotes (3.38 B) ---------- */

    /** The quote list: filters by status, the responsible person ("mine", nobody, a person), a word and a record (the box's "All"); paging. */
    protected function actionList(): Response
    {
        $me = $this->app->auth()->id();
        $filter = self::filter(['status' => $this->request->get('status'), 'owner' => $this->request->get('owner'), 'search' => $this->request->get('search'),
            'enquiry' => $this->request->get('enquiry'), 'deal' => $this->request->get('deal'), 'contact' => $this->request->get('contact'), 'company' => $this->request->get('company')]);
        $owner = match ($filter['owner']) {
            'mine' => $me,
            'none' => -1,
            '' => 0,
            default => (int) $filter['owner'],
        };
        $result = Store::list($this->db, ['owner' => $owner] + $filter, max(1, $this->request->getInt('page', 1)), self::PAGE);
        $steps = $this->app->auth()->isAdmin() && !$this->app->settings()->bool(self::STEPS_HIDDEN) ? self::setupChecklist(self::setupFacts($this->app)) : null; // 3.45 (CPO 3)
        if ($steps === []) {
            $this->app->settings()->set(self::STEPS_HIDDEN, '1'); // 3.45 review L7: complete – remembered, the facts stop being read
        }

        return $this->view('list', 'Quotes', ['filter' => $filter, 'query' => array_filter($filter, fn (int|string $v): bool => $v !== '' && $v !== 0 && $v !== 'all'), 'result' => $result,
            'people' => self::people($this->db), 'me' => $me, 'admin' => $this->app->auth()->isAdmin(),
            'steps' => $steps ?? [],
            'owners' => Assignment::labels($this->db, array_values(array_filter(array_column($result['rows'], 'owner_id'))))]);
    }

    /**
     * The filter of the list from the query: status (Store::LIST_STATUSES, all by default), owner '' (anyone) | mine | none
     * | a user id, a word (at most 100 characters), the record of a box (enquiry, deal, contact, company ids). Anything else
     * falls back to the default. Pure.
     *
     * @param array<string, string> $q
     * @return array{status: string, owner: string, search: string, enquiry: int, deal: int, contact: int, company: int}
     */
    public static function filter(array $q): array
    {
        $owner = trim($q['owner'] ?? '');
        $id = fn (string $key): int => ctype_digit(trim($q[$key] ?? '')) ? (int) trim($q[$key]) : 0;

        return ['status' => in_array($q['status'] ?? '', Store::LIST_STATUSES, true) ? $q['status'] : 'all',
            'owner' => in_array($owner, ['mine', 'none'], true) || (ctype_digit($owner) && (int) $owner > 0) ? $owner : '',
            'search' => mb_substr(trim($q['search'] ?? ''), 0, 100), 'enquiry' => $id('enquiry'), 'deal' => $id('deal'), 'contact' => $id('contact'), 'company' => $id('company')];
    }

    /**
     * A new draft (POST – "New quote" on the list, "New quote" in the box of an enquiry, a deal, a customer or a
     * company): Store::save fills the customer, the open deal and the enquiry's basket lines (linesFromEnquiry) and checks
     * the sections (an enquiry needs Enquiries, a customer Customers, a deal the Pipeline); then the editor opens.
     */
    protected function actionCreate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $fields = [];
        foreach (['enquiry' => 'enquiry_id', 'deal' => 'deal_id', 'contact' => 'contact_id', 'company' => 'company_id'] as $param => $key) {
            if ($this->request->postInt($param) > 0) {
                $fields[$key] = $this->request->postInt($param);
            }
        }
        try {
            $saved = Store::save($this->app, null, $fields, $this->app->auth()->id());
        } catch (\DomainException $e) {
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($this->returnTo($fields));
        }
        if ($saved['warnings'] !== []) {
            $this->app->session->flash('info', implode(' ', $saved['warnings']));
        }

        return Response::redirect($this->url('edit', ['id' => $saved['id']]));
    }

    /** The editor of a draft (a sent quote opens its detail). */
    protected function actionEdit(): Response
    {
        $quote = Store::get($this->db, $this->request->getInt('id'));
        if ($quote === null) {
            return $this->error('The quote does not exist.', 404);
        }
        if ($quote['status'] !== 'draft') {
            return Response::redirect($this->url('detail', ['id' => $quote['id']]));
        }

        return $this->editor($quote, [], null);
    }

    /**
     * Saves the editor (only a draft): the customer (a contact or company chosen anew fills the customer block again), the
     * texts, validity, discount, VAT mode, language, price list, responsible person, deal and the lines – the posted ones
     * (a ticked "remove" drops one) plus a product, an own row or a free line added below. The server computes the totals.
     * Then: next=send → the send dialog (with deal_value when a deal is linked), next=finalise → the number without an
     * e-mail (and the deal value when the box is ticked), next=preview → the detail, otherwise back to the editor.
     * A refused save shows the editor again with what was typed.
     */
    protected function actionSave(): Response
    {
        $quote = Store::get($this->db, $this->request->postInt('id'));
        if (!$this->request->isPost() || $quote === null) {
            return $quote === null && $this->request->isPost() ? $this->error('The quote does not exist.', 404) : $this->back();
        }
        if ($quote['status'] !== 'draft') {
            return $this->back('A sent quote is frozen – revise it to make a new draft.', 'detail', ['id' => $quote['id']], 'chyba');
        }
        $me = $this->app->auth()->id();
        $next = $this->request->post('next');
        $dealValue = $this->request->postBool('set_deal_value');
        try {
            $fields = $this->postedQuote($quote);
            // 3.40: a saved line set goes after the saved lines, priced from the quote's list (and currency) as it is after the save
            $set = $next === 'line_set' ? (QuoteLineSets::find($this->db, $this->request->postInt('line_set')) ?? throw new \DomainException(t('Choose a line set to insert.'))) : null;
            $saved = $this->db->transaction(function () use ($quote, $fields, $me, $set): array {
                $saved = Store::save($this->app, $quote['id'], $fields, $me);
                if ($set !== null) { // one transaction: a set that does not fit (Quotes::MAX_LINES) leaves the draft as it was
                    $saved['warnings'] = [...$saved['warnings'], ...QuoteLineSets::insert($this->app, $quote['id'], $set['id'], $me)['warnings']];
                }

                return $saved;
            });
            $warnings = $saved['warnings'];
            if ($next === 'save_set') { // 3.40: the lines as a saved line set (an existing name is replaced)
                $set = QuoteLineSets::fromQuote($this->app, $quote['id'], $this->request->post('set_name'), $me);
                $setName = QuoteLineSets::cleanName($this->request->post('set_name'));
                ChangeLog::write($this->app, self::IDENT, 'line_set', t('Line set “%s” saved', $setName));
                $this->app->session->flash('ok', t($set['replaced'] ? 'The line set “%s” now holds these lines.' : 'The lines were saved as the line set “%s”.', $setName));
            }
            if ($next === 'finalise') {
                $done = Store::finalise($this->app, $quote['id'], $me);
                if ($dealValue && $done['deal_id'] !== null && isset($this->kernel->modules()[Pipeline::IDENT]) && Store::setDealValue($this->app, $quote['id'], $me)) {
                    $warnings[] = t('The value of the deal is now the net total of the quote.');
                }
                $this->app->session->flash('ok', t('Quote %s is finalised – hand it over or print it.', (string) $done['number']));
            }
        } catch (\DomainException $e) {
            $this->app->session->flash('chyba', $e->getMessage());
            $fresh = Store::get($this->db, $quote['id']) ?? $quote;
            if ($fresh['status'] !== 'draft') {
                return Response::redirect($this->url('detail', ['id' => $quote['id']]));
            }

            return $this->editor($fresh, $this->postedDraft(), $this->postedLines());
        }
        if ($warnings !== []) {
            $this->app->session->flash('info', implode(' ', array_values(array_unique($warnings))));
        }

        return match ($next) {
            'finalise' => Response::redirect($this->url('detail', ['id' => $quote['id']])),
            'preview' => Response::redirect($this->url('preview', ['id' => $quote['id']])), // the customer's document (part C, Admin\QuoteSending)
            // the send dialog and the e-mail are the next part's (action send); the box "set the deal value" travels along
            'send' => Response::redirect($this->url('send', ['id' => $quote['id']] + ((Store::get($this->db, $quote['id'])['deal_id'] ?? null) !== null ? ['deal_value' => $dealValue ? 1 : 0] : []))),
            'save_set' => Response::redirect($this->url('edit', ['id' => $quote['id']]) . '#line-sets'),
            default => in_array($next, ['add', 'line_set'], true) || $this->request->post('line_move') !== '' || $this->request->post('line_drop') !== ''
                ? Response::redirect($this->url('edit', ['id' => $quote['id']]) . $this->lineAnchor($quote['id'], $next)) : $this->back('The quote was saved.', 'edit', ['id' => $quote['id']]),
        };
    }

    /**
     * A quote: the document (for a draft its current lines, once sent the frozen snapshot), the status with its actions in
     * the bar (sent: Mark accepted, Mark declined with a reason; accepted or declined: back to sent; any numbered: Revise;
     * a draft: Edit), the history, and in the side column the facts, the customer, the enquiry and the deal (with "Move the
     * deal to won" / "Mark the deal lost" offered – never done by itself).
     */
    protected function actionDetail(): Response
    {
        $quote = Store::get($this->db, $this->request->getInt('id'));
        if ($quote === null) {
            return $this->error('The quote does not exist.', 404);
        }
        $modules = $this->kernel->modules();
        $deal = $quote['deal_id'] !== null && isset($modules[Pipeline::IDENT]) ? Deals::get($this->db, $quote['deal_id']) : null;
        $stages = Deals::stages($this->app->settings());
        $enquiry = null;
        if ($quote['enquiry_id'] !== null && isset($modules['enquiries'])) {
            $enquiry = $this->db->one('SELECT idp, datum, formular, tema, zdroj, email, data, anonymizovano FROM {poptavky} WHERE idp = ? AND ' . Enquiries::visible($this->db, $this->app->settings(), false), [$quote['enquiry_id']]);
        }
        $people = array_values(array_filter([$quote['owner_id'], $quote['created_by'], $quote['sent_by'], $quote['decided_by'], ...array_map(fn (array $h): ?int => isset($h['by']) ? (int) $h['by'] : null, $quote['history'])]));
        $list = Prices::priceList($this->db, $quote['price_list_id']);

        return $this->view('detail', self::heading($quote), ['q' => $quote, 'deal' => $deal, 'enquiry' => $enquiry,
            'dealStage' => $deal !== null ? Deals::stage($this->app->settings(), $deal['stage']) : null,
            'won' => array_values(array_filter($stages, fn (array $s): bool => $s['kind'] === 'won'))[0] ?? null,
            'lost' => array_values(array_filter($stages, fn (array $s): bool => $s['kind'] === 'lost'))[0] ?? null,
            'names' => Assignment::labels($this->db, $people), 'listName' => $list !== null ? $list['name'] : '',
            'canContacts' => isset($modules[Customers::SECTION]), 'canDelete' => $this->app->auth()->isAdmin() || ($quote['number'] === null && $quote['created_by'] === $this->app->auth()->id()),
            'revisions' => array_map(fn (array $r): array => $r + ['url' => $this->url('detail', ['id' => $r['id']])], $this->revisions($quote)),
            'refusal' => $quote['status'] === 'draft' ? Store::sendRefusal($quote, false) : null,
            // 3.40: the tasks of the quote (follow-ups), "first opened on" while it is recorded, the customer's own decision
            'tasks' => $this->taskPanel('quote:' . $quote['id'], ['id' => $quote['id']]), 'tracking' => $this->app->settings()->bool('quote_track_opened'),
            'followUpDays' => Store::FOLLOW_UP_DAYS,
            'fileBox' => $quote['anonymised_at'] === null ? InternalFiles::box($this->app, $modules, 'quote', (int) $quote['id']) : null]); // 3.49 B (review C1: not for an anonymised quote)
    }

    /** Mark accepted, declined (with a reason), back to sent, or expired – Store::setStatus; nothing moves the deal by itself. */
    protected function actionStatus(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        $status = $this->request->post('status');
        try {
            $changed = Store::setStatus($this->app, $id, $status, $this->app->auth()->id(), $this->request->post('reason') !== '' ? $this->request->post('reason') : null);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }
        if (!$changed) {
            return $this->back('', 'detail', ['id' => $id]);
        }

        return $this->back(t('The quote is now %s.', mb_strtolower(t(Store::STATUS_LABELS[$status] ?? $status))), 'detail', ['id' => $id]);
    }

    /** Revise a numbered quote: a new draft with the same lines (its number gets /2 when sent; the original expires then). */
    protected function actionRevise(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        try {
            $new = Store::revise($this->app, $id, $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('A new draft of the quote – the original stays valid until this one is sent.', 'edit', ['id' => $new]);
    }

    /** Delete: a draft by its author or an administrator, a numbered quote by an administrator only (Store::delete). */
    protected function actionDelete(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        try {
            Store::delete($this->app, $id, $this->app->auth()->id(), $this->app->auth()->isAdmin());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('The quote was deleted.');
    }

    /**
     * The totals of the editor as typed, without saving (a small fetch of image/admin.js on change – the arithmetic lives
     * only here, in Core\Money): {net, vat, gross, lines: [line_net…], unpriced} formatted, or {error}.
     */
    protected function actionTotals(): Response
    {
        $quote = Store::get($this->db, $this->request->postInt('id'));
        if (!$this->request->isPost() || $quote === null) {
            return Response::json(['error' => t('The quote does not exist.')], 404);
        }
        try {
            $lines = [];
            foreach ($this->postedLines() as $line) {
                if ($line['remove'] || ($line['name'] === '' && $line['item_id'] === '' && $line['code'] === '')) {
                    $lines[] = null;
                    continue;
                }
                $price = Money::parse($line['unit_price']);
                $lines[] = ['unit_price' => $price === null ? null : Money::toDecimal($price), 'quantity' => Money::quantityText(Money::quantity($line['quantity'])),
                    'discount_percent' => Money::percentText(Money::percent($line['discount_percent'])),
                    'vat_rate' => Money::percentText(Money::percent($line['vat_rate'] !== '' ? $line['vat_rate'] : (Prices::defaultVatRate($this->app->settings()) ?? '0')))];
            }
            $totals = Store::computeTotals(array_values(array_filter($lines)), Money::percentText(Money::percent($this->request->post('discount_percent'))), $this->request->post('vat_mode'));
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()]);
        }
        $currency = $quote['currency'];
        $fmt = fn (string $decimal): string => Money::format(Money::fromDecimal($decimal), $currency);

        return Response::json(['net' => $fmt($totals['net']), 'vat' => $fmt($totals['vat']), 'gross' => $fmt($totals['gross']), 'unpriced' => $totals['unpriced'],
            'lines' => array_map(fn (?array $l): string => $l === null || $l['unit_price'] === null ? '' : $fmt(Money::toDecimal(Money::lineNet((int) Money::fromDecimal($l['unit_price']),
                Money::quantity($l['quantity']), Money::percent($l['discount_percent'])))), $lines)]);
    }

    protected function actionPrices(): Response
    {
        $s = $this->app->settings();
        $lists = Prices::lists($this->db, true);
        if (array_filter($lists, fn (array $l): bool => $l['archived_at'] === null) === []) {
            Prices::defaultList($this->db, $s);
            $lists = Prices::lists($this->db, true);
        }
        $chosen = $this->request->getInt('list');
        $list = array_values(array_filter($lists, fn (array $l): bool => $l['id'] === $chosen))[0] ?? array_values(array_filter($lists, fn (array $l): bool => $l['is_default']))[0] ?? $lists[0];
        $filter = ['collection' => $this->request->getInt('collection'), 'search' => $this->request->get('search'), 'missing' => $this->request->get('missing') === '1'];
        $page = max(1, $this->request->getInt('page', 1));

        // 3.45 (U62): the section's name in the heading, the tab's name in the tab; the mapping of collections lives on Price list set-up
        return $this->view('prices', 'Quotes', ['lists' => $lists, 'list' => $list, 'filter' => $filter, 'grid' => Prices::grid($this->db, $list['id'], $filter, $page),
            'own' => Prices::ownRows($this->db, $list['id']), 'collections' => Prices::productCollections($this->db), 'rates' => Prices::vatRates($s),
            'defaultRate' => Prices::defaultVatRate($s), 'canEdit' => $this->app->auth()->canEditPrices(), 'admin' => $this->app->auth()->isAdmin(),
            'import' => $this->pendingImport($list['id']), 'anyCollection' => \Kaleta\Builder\Collections::all($this->db) !== []]);
    }

    /** The rows of the grid (and the own rows) as posted: only the ones that changed are written. */
    protected function actionPricesSave(): Response
    {
        $listId = $this->request->postInt('list');
        $back = ['list' => $listId] + array_filter(['collection' => $this->request->postInt('collection'), 'search' => $this->request->post('search'),
            'missing' => $this->request->post('missing'), 'page' => $this->request->postInt('page')]);
        if (!$this->request->isPost()) {
            return $this->back('', 'prices', $back);
        }
        if (!$this->app->auth()->canEditPrices()) {
            return $this->error('Changing internal prices needs the permission “Quotes: change internal prices” (Users or Roles).', 403);
        }
        $rows = $this->postedRows();
        $code = $this->request->post('new_code');
        if ($code !== '' || $this->request->post('new_price') !== '') {
            $rows[] = ['code' => $code, 'label' => $this->request->post('new_label'), 'unit_price' => $this->request->post('new_price'), 'unit' => $this->request->post('new_unit'),
                'vat_rate' => $this->request->post('new_vat'), 'note' => $this->request->post('new_note')];
        }
        $changed = 0;
        $errors = [];
        foreach ($rows as $row) {
            try {
                $done = Prices::save($this->app, $listId, $row, $this->app->auth()->id(), false);
                $changed += $done['action'] !== 'unchanged' ? 1 : 0;
            } catch (\DomainException $e) {
                $errors[] = ($row['code'] ?? '') !== '' ? $row['code'] . ': ' . $e->getMessage() : $e->getMessage();
            }
        }
        if ($changed > 0) {
            $name = Prices::priceList($this->db, $listId)['name'] ?? '';
            ChangeLog::write($this->app, Prices::SECTION, 'price', t('Internal prices changed in %1$s: %2$d', $name, $changed));
        }
        if ($errors !== []) {
            return $this->back(t('Saved: %d. Not saved: %s', $changed, implode('; ', array_slice($errors, 0, 5))), 'prices', $back, 'chyba');
        }

        return $this->back(t('Internal prices saved: %d.', $changed), 'prices', $back);
    }

    /** The box on a product's form in Collections: its rows in every price list. */
    protected function actionItemPricesSave(): Response
    {
        $idk = $this->request->postInt('idk');
        $idp = $this->request->postInt('idp');
        $back = $this->app->url('admin.php?' . http_build_query(['module' => 'collections', 'action' => 'item', 'id' => $idk, 'item' => $idp])) . '#internal-prices';
        if (!$this->request->isPost() || $idp <= 0) {
            return Response::redirect($back);
        }
        if (!$this->app->auth()->canEditPrices()) {
            return $this->error('Changing internal prices needs the permission “Quotes: change internal prices” (Users or Roles).', 403);
        }
        $lists = $this->request->postList('list');
        $rows = $this->postedRows();
        $changed = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            try {
                $done = Prices::save($this->app, (int) ($lists[$i] ?? 0), ['item_id' => $idp] + $row, $this->app->auth()->id(), false);
                $changed += $done['action'] !== 'unchanged' ? 1 : 0;
            } catch (\DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($changed > 0) {
            ChangeLog::write($this->app, Prices::SECTION, 'price', t('Internal prices changed: item #%d', $idp));
        }
        $this->app->session->flash($errors === [] ? 'ok' : 'chyba', $errors === [] ? t('Internal prices saved: %d.', $changed) : implode('; ', array_slice($errors, 0, 5)));

        return Response::redirect($back);
    }

    protected function actionPricesExport(): Response
    {
        $list = Prices::priceList($this->db, $this->request->getInt('list')) ?? Prices::defaultList($this->db, $this->app->settings());
        $csv = Prices::exportCsv($this->app, $list['id']);

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="internal-prices-' . strtolower($list['currency']) . '-' . $list['id'] . '-' . date('Y-m-d') . '.csv"']);
    }

    /** An uploaded CSV, checked into a preview kept in the session until it is written or discarded. */
    protected function actionPricesImport(): Response
    {
        $listId = $this->request->postInt('list');
        if (!$this->request->isPost()) {
            return $this->back('', 'prices', ['list' => $listId]);
        }
        if (!$this->app->auth()->canEditPrices()) {
            return $this->error('Changing internal prices needs the permission “Quotes: change internal prices” (Users or Roles).', 403);
        }
        if ($this->request->post('discard') !== '') {
            $this->app->session->set(self::IMPORT_KEY, null);

            return $this->back('', 'prices', ['list' => $listId]);
        }
        $file = $this->request->file('csv');
        if ($file === null || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) || (int) $file['size'] > 5 * 1024 * 1024) {
            return $this->back('Choose a CSV file (at most 5 MB).', 'prices', ['list' => $listId], 'chyba');
        }
        try {
            $rows = Prices::parseCsv((string) file_get_contents((string) $file['tmp_name']));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'prices', ['list' => $listId], 'chyba');
        }
        if (Prices::priceList($this->db, $listId) === null) {
            return $this->error('The price list does not exist.', 404);
        }
        $this->app->session->set(self::IMPORT_KEY, ['list' => $listId, 'rows' => $rows, 'name' => mb_substr(basename((string) $file['name']), 0, 120)]);

        return $this->back('', 'prices', ['list' => $listId, 'import' => 1]);
    }

    protected function actionPricesImportRun(): Response
    {
        $listId = $this->request->postInt('list');
        if (!$this->request->isPost()) {
            return $this->back('', 'prices', ['list' => $listId]);
        }
        if (!$this->app->auth()->canEditPrices()) {
            return $this->error('Changing internal prices needs the permission “Quotes: change internal prices” (Users or Roles).', 403);
        }
        $pending = $this->app->session->get(self::IMPORT_KEY);
        if (!is_array($pending) || (int) ($pending['list'] ?? 0) !== $listId || !is_array($pending['rows'] ?? null)) {
            return $this->back('The checked file is no longer here – upload it again.', 'prices', ['list' => $listId], 'chyba');
        }
        $this->app->session->set(self::IMPORT_KEY, null);
        try {
            $result = Prices::import($this->app, $listId, $pending['rows'], $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'prices', ['list' => $listId], 'chyba');
        }

        return $this->back(t('Import finished: %1$d saved, %2$d removed, %3$d skipped.', $result['saved'], $result['removed'], $result['skipped']), 'prices', ['list' => $listId]);
    }

    protected function actionListSave(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator manages the price lists.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'prices');
        }
        try {
            $id = Prices::saveList($this->app, $this->request->postInt('id') ?: null, $this->request->post('name'), $this->request->post('currency'), $this->request->post('note'));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'prices', ['list' => $this->request->postInt('id')], 'chyba');
        }

        return $this->back('The price list was saved.', 'prices', ['list' => $id]);
    }

    protected function actionListArchive(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator manages the price lists.', 403);
        }
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'prices', ['list' => $id]);
        }
        try {
            Prices::archiveList($this->app, $id, $this->request->post('archive') === '1');
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'prices', ['list' => $id], 'chyba');
        }

        return $this->back('The price list was saved.', 'prices', ['list' => $id]);
    }

    protected function actionListDefault(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator manages the price lists.', 403);
        }
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'prices', ['list' => $id]);
        }
        try {
            Prices::makeDefault($this->app, $id);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'prices', ['list' => $id], 'chyba');
        }

        return $this->back('The price list was saved.', 'prices', ['list' => $id]);
    }

    protected function actionSettings(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        $s = $this->app->settings();
        $keys = ['quote_number_format', 'quote_valid_days', 'quote_vat_payer', 'quote_vat_rate', 'quote_vat_rates', 'quote_max_discount', 'quote_link_days', 'quotes_months', 'quote_set_deal_value',
            'quote_customer_decision', 'quote_track_opened', 'quote_attach_pdf', Store::AUTO_DRAFT_SETTING]; // 3.40, 3.42

        return $this->view('settings', 'Quotes', ['values' => array_combine($keys, array_map(fn (string $k): string => $s->get($k), $keys)), 'next' => Store::nextNumber($this->db, $s),
            'people' => array_map(fn (array $u): string => (string) ($u['jmeno'] ?: $u['user']), $this->db->all('SELECT DISTINCT u.jmeno, u.user FROM {uzivatele} u JOIN {uzivatele_prava} p ON p.fk_id_user = u.idu'
                . ' WHERE p.ident_modulu = ? AND u.admin <> ? AND u.blokovat = 0 ORDER BY u.jmeno', [Prices::SECTION, Auth::ADMIN]))]);
    }

    /**
     * 3.45 (U62): Price list set-up (administrators) – what used to make Settings 3,000 px long: the collections in the price
     * list (3.42), the default texts (3.38) and the saved line sets (3.40). Settings keeps the one form.
     */
    protected function actionSetup(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        $sets = QuoteLineSets::all($this->db);

        return $this->view('setup', 'Quotes', ['lineSets' => $sets, 'setOwners' => Assignment::labels($this->db, array_values(array_filter(array_column($sets, 'created_by'))))] + $this->mappingChoices());
    }

    /**
     * 3.42: which collections feed the price list (administrators; 3.45: the form is on Price list set-up): a
     * posted collection[] is mapped with its code_<idk> and variants_<idk> field; the products preset collections are
     * always products and are not posted.
     */
    protected function actionCollectionsSave(): Response
    {
        $back = $this->request->post('back') === 'prices' ? 'prices' : 'setup'; // 3.45: an older form may still post back=prices
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', $back);
        }
        $entries = [];
        foreach (array_map('intval', $this->request->postList('collection')) as $idk) {
            if ($idk > 0) {
                $entries[] = ['collection' => $idk, 'code' => $this->request->post('code_' . $idk), 'variants' => $this->request->post('variants_' . $idk)]
                    + ($this->request->post('basket_form') === '1' ? ['basket' => $this->request->post('basket_' . $idk) === '1'] : []); // 3.44 review M1
            }
        }
        $saved = Prices::saveMapping($this->app, $entries);

        $this->app->session->flash('ok', t('Collections in the price list saved: %d.', count($saved)));

        return Response::redirect($this->url($back) . '#price-collections');
    }

    /**
     * 3.42: the collections for the mapping form – every collection with its fields that can be a code or variants, whether
     * it is a products preset (always in the price list) and its saved mapping.
     *
     * @return array{mapping: array<int, array{collection: int, code: string, variants: string, basket?: bool}>, mappable: list<array{idk: int, name: string, preset: bool, items: int, detail: bool, code: list<array{key: string, label: string}>, variants: list<array{key: string, label: string}>}>}
     */
    private function mappingChoices(): array
    {
        $counts = $this->db->pairs('SELECT idk, COUNT(*) FROM {kolekce_polozky} WHERE smazano IS NULL AND jazyk = \'\' GROUP BY idk');
        $out = [];
        foreach (\Kaleta\Builder\Collections::all($this->db) as $c) {
            $fields = Prices::mappableFields($c);
            $out[] = ['idk' => (int) $c['idk'], 'name' => (string) $c['nazev'], 'preset' => ($c['preset'] ?? '') === 'products', 'items' => (int) ($counts[$c['idk']] ?? 0), 'detail' => (int) ($c['detail'] ?? 0) === 1] + $fields;
        }

        return ['mapping' => array_column(Prices::mapping($this->db), null, 'collection'), 'mappable' => $out];
    }

    protected function actionSettingsSave(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of quotes.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'settings');
        }
        $s = $this->app->settings();
        $format = trim($this->request->post('quote_number_format'));
        $refusal = Store::formatRefusal($format !== '' ? $format : Store::NUMBER_FORMAT);
        if ($refusal !== null) {
            return $this->back($refusal, 'settings', [], 'chyba');
        }
        foreach (['quote_vat_rate', 'quote_max_discount'] as $key) {
            try {
                $value = $this->request->post($key);
                if ($value !== '' || $key === 'quote_max_discount') {
                    $value = Money::short(Money::percentText(Money::percent($value)));
                }
            } catch (\DomainException $e) {
                return $this->back($e->getMessage(), 'settings', [], 'chyba');
            }
            $s->set($key, $value);
        }
        $rates = [];
        foreach (preg_split('/[\s,;]+/', $this->request->post('quote_vat_rates')) ?: [] as $rate) {
            try {
                if (trim($rate) !== '') {
                    $rates[] = Money::short(Money::percentText(Money::percent($rate)));
                }
            } catch (\DomainException $e) {
                return $this->back($e->getMessage(), 'settings', [], 'chyba');
            }
        }
        $s->set('quote_number_format', $format !== '' ? $format : Store::NUMBER_FORMAT);
        $s->set('quote_vat_rates', implode(',', array_unique($rates)));
        $s->set('quote_vat_payer', $this->request->postBool('quote_vat_payer') ? '1' : '0');
        if (!$this->request->postBool('quote_vat_payer')) {
            Store::withoutVat($this->db); // 3.38 review L8: the drafts follow – gross = net, as their document shows
        }
        $s->set('quote_set_deal_value', $this->request->postBool('quote_set_deal_value') ? '1' : '0');
        // 3.40 (design A-12, both off by default): the customer's Accept / Decline on the quote page; "first opened on" –
        // switching it off forgets the times recorded so far
        $s->set('quote_customer_decision', $this->request->postBool('quote_customer_decision') ? '1' : '0');
        if (!$this->request->postBool('quote_track_opened') && $s->bool('quote_track_opened')) {
            Store::forgetOpened($this->db);
        }
        $s->set('quote_track_opened', $this->request->postBool('quote_track_opened') ? '1' : '0');
        $s->set('quote_attach_pdf', $this->request->postBool('quote_attach_pdf') ? '1' : '0'); // 3.40
        $s->set(Store::AUTO_DRAFT_SETTING, $this->request->postBool(Store::AUTO_DRAFT_SETTING) ? '1' : '0'); // 3.42
        $s->set('quote_valid_days', (string) max(1, min(365, $this->request->postInt('quote_valid_days', 30))));
        $s->set('quote_link_days', (string) max(1, min(365, $this->request->postInt('quote_link_days', 30))));
        $held = [];
        if ($this->request->hasPost('quotes_months')) {
            // 3.48 B (U40): set in Settings → Privacy and cookies; a page of 3.47 still open posts it – 3.50.1 (D1): a shorter
            // period waits on the confirmation screen, a longer one is saved; a form without it leaves it
            $held = \Kaleta\Core\Retention::saveOrHold($this->app, ['quotes_months' => (string) max(0, min(120, $this->request->postInt('quotes_months', 36)))]);
        }
        $next = $this->request->postInt('quote_next_number');
        $message = 'The settings of quotes were saved.';
        if ($next > 0) {
            $s->set('quote_next_number', (string) $next);
            if (!Store::raiseCounter($this->db, $next)) {
                $message = t('The settings of quotes were saved. The next number stays %s – a series only goes up.', Store::nextNumber($this->db, $s));
            }
        }
        ChangeLog::write($this->app, Prices::SECTION, 'settings', '');
        if ($held !== []) {
            if ($message !== 'The settings of quotes were saved.') { // 3.50.1 review: the refused lower number is still said
                $this->app->session->flash('info', $message);
            }
            $this->app->session->flash('info', t('Settings saved. A shorter retention period makes data go sooner – confirm it below.'));

            return Response::redirect(\Kaleta\Core\Retention::confirmationUrl($this->app, $held));
        }

        return $this->back($message, 'settings');
    }

    /* ---------- the editor and the boxes (3.38 B) ---------- */

    /**
     * The editor of a draft: the quote, what a refused save typed ($draft scalar fields, $lines the posted lines), the price
     * lists, the VAT rates, the products of the price list for "Add product" (code or #item, name and internal price), the
     * own rows, the responsible people, the customers, companies and open deals the person may choose, the languages.
     *
     * @param array<string, mixed> $quote Store::get
     * @param array<string, string> $draft
     * @param list<array<string, mixed>>|null $lines
     */
    private function editor(array $quote, array $draft, ?array $lines): Response
    {
        $s = $this->app->settings();
        $modules = $this->kernel->modules();
        $canContacts = isset($modules[Customers::SECTION]);
        $canDeals = isset($modules[Pipeline::IDENT]);
        $list = Prices::priceList($this->db, $quote['price_list_id']) ?? Prices::defaultList($this->db, $s);
        $lists = Prices::lists($this->db);
        if (!in_array($list['id'], array_column($lists, 'id'), true)) {
            $lists[] = $list + ['prices' => 0];
        }
        $products = array_map(fn (array $p): array => ['value' => $p['value'], 'label' => $p['name'] . ' · ' . $p['note']], self::products($this->db, $list, '', self::PRODUCT_CHOICES));
        $deals = [];
        if ($canDeals) {
            foreach (Deals::list($this->app, ['status' => 'open'], 1, self::DEAL_CHOICES)['rows'] as $d) {
                $deals[$d['id']] = $d['title'];
            }
            if ($quote['deal_id'] !== null && !isset($deals[$quote['deal_id']]) && ($own = Deals::get($this->db, $quote['deal_id'])) !== null) {
                $deals[$own['id']] = $own['title'];
            }
        }
        $languages = [];
        foreach (Language::additional($s) === [] ? [] : [Language::defaults($s), ...Language::additional($s)] as $code) {
            $languages[Language::column($s, $code)] = Language::AVAILABLE[$code][0] ?? $code;
        }

        return $this->view('edit', self::heading($quote), ['q' => $quote, 'draft' => $draft,
            'lines' => $lines ?? $quote['lines'], 'list' => $list, 'lists' => $lists, 'rates' => Prices::vatRates($s), 'defaultRate' => Prices::defaultVatRate($s),
            'products' => array_slice($products, 0, self::PRODUCT_CHOICES), 'own' => Prices::ownRows($this->db, $list['id']), 'people' => self::people($this->db, (int) $quote['owner_id']),
            'contacts' => $canContacts ? Pipeline::contactChoices($this->db, $quote['contact_id']) : [], 'companies' => $canContacts ? Pipeline::companyChoices($this->db, $quote['company_id']) : [],
            'deals' => $deals, 'canContacts' => $canContacts, 'canDeals' => $canDeals, 'languages' => $languages,
            'maxDiscount' => Money::short(Money::percentText(Money::percent(trim($s->get('quote_max_discount')) !== '' ? $s->get('quote_max_discount') : '20'))),
            'dealValue' => $s->bool('quote_set_deal_value'), 'me' => $this->app->auth()->id(), 'lineSets' => QuoteLineSets::all($this->db), // 3.40
            'canDelete' => $this->app->auth()->isAdmin() || $quote['created_by'] === $this->app->auth()->id()]);
    }

    /**
     * The products of a price list "Add product" offers – code or #item, the name (with the variant) and the internal price
     * (or "no internal price"); by a word in the name or code. At most $limit.
     *
     * @param array<string, mixed> $list Prices::priceList
     * @return list<array{value: string, name: string, note: string}>
     */
    private static function products(Db $db, array $list, string $search, int $limit): array
    {
        $out = [];
        foreach (Prices::grid($db, (int) $list['id'], $search !== '' ? ['search' => $search] : [], 1, $limit)['rows'] as $row) {
            foreach ($row['lines'] as $line) {
                if ($line['gone'] || ($line['variant'] === '' && $line['code'] === '' && $row['variants'] !== [])) {
                    continue;
                }
                $out[] = ['value' => $line['code'] !== '' ? (string) $line['code'] : '#' . $row['idp'], 'name' => $row['name'] . ($line['name'] !== '' ? ' – ' . $line['name'] : ''),
                    'note' => $line['price'] !== null ? Money::format($line['price']['minor'], (string) $list['currency']) : t('no internal price')];
            }
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * The choice of a product in the editor (3.49 C): the products of the price list ?list= (the default one when it is
     * unknown) by a word in the name or code, with the internal price – only inside Quotes, never anywhere public. GET,
     * read-only, no-store.
     */
    protected function actionProductSearchJson(): Response
    {
        $q = $this->searchWord();
        $list = Prices::priceList($this->db, $this->request->getInt('list')) ?? Prices::defaultList($this->db, $this->app->settings());

        return Response::json(['products' => $q === '' ? [] : self::products($this->db, $list, $q, self::PICK_ROWS)], 200, Response::NO_STORE);
    }

    /**
     * The lines the editor posted as parallel lists, raw (the editor shows them again after a refused save).
     *
     * @return list<array{id: int, item_id: string, variant_code: string, code: string, name: string, description: string, quantity: string, unit: string, unit_price: string, discount_percent: string, vat_rate: string, remove: bool}>
     */
    private function postedLines(): array
    {
        $r = $this->request;
        // a line is removed by its index in line_remove[] (before 3.44), its line_removed[] flag (the Remove button with
        // JavaScript) or line_drop (the Remove button without it, which saves)
        $remove = array_map('intval', $r->postList('line_remove'));
        if ($r->post('line_drop') !== '' && ctype_digit($r->post('line_drop'))) {
            $remove[] = (int) $r->post('line_drop');
        }
        $flags = $r->postList('line_removed');
        $lines = [];
        foreach ($r->postList('line_name') as $i => $name) {
            $value = fn (string $key): string => trim((string) ($r->postList($key)[$i] ?? ''));
            $lines[] = ['id' => (int) $value('line_id'), 'item_id' => $value('line_item'), 'variant_code' => $value('line_variant'), 'code' => $value('line_code'), 'name' => trim((string) $name),
                'description' => $value('line_description'), 'quantity' => $value('line_quantity'), 'unit' => $value('line_unit'), 'unit_price' => $value('line_price'),
                'discount_percent' => $value('line_discount'), 'vat_rate' => $value('line_vat'), 'remove' => in_array($i, $remove, true) || ($flags[$i] ?? '') === '1'];
        }

        return self::moveLine($lines, $r->post('line_move'));
    }

    /**
     * 3.44 (U58): Up or Down of a line without JavaScript – "<index>:up|down" swaps the line with its neighbour (the lines are
     * saved in the posted order, Quotes::save numbers their positions). Anything else, or a move past either end, leaves them.
     *
     * @template T
     * @param list<T> $lines
     * @return list<T>
     */
    public static function moveLine(array $lines, string $move): array
    {
        if (preg_match('/^(\d{1,4}):(up|down)$/', $move, $m) !== 1) {
            return $lines;
        }
        $from = (int) $m[1];
        $to = $m[2] === 'up' ? $from - 1 : $from + 1;
        if (!isset($lines[$from], $lines[$to])) {
            return $lines;
        }
        [$lines[$from], $lines[$to]] = [$lines[$to], $lines[$from]];

        return $lines;
    }

    /**
     * Where the editor lands after a save that stays in it (3.44, U58): the first line that was added (a set or Add a line –
     * they go after the kept lines) or the line that was moved, so it is not under the sticky bar (image/admin.css gives the
     * rows a scroll margin); else the lines.
     */
    private function lineAnchor(int $quoteId, string $next): string
    {
        $count = count(Store::get($this->db, $quoteId)['lines'] ?? []);
        $kept = count(array_filter($this->postedLines(), fn (array $l): bool => !$l['remove'] && ($l['name'] !== '' || $l['item_id'] !== '' || $l['code'] !== '')));
        if (preg_match('/^(\d{1,4}):(up|down)$/', $this->request->post('line_move'), $m) === 1) {
            $at = $m[2] === 'up' ? (int) $m[1] : (int) $m[1] + 2;

            return '#line-' . max(1, min($count, $at));
        }

        return in_array($next, ['add', 'line_set'], true) && $count > $kept ? '#line-' . ($kept + 1) : '#lines';
    }

    /** The scalar fields of the editor as posted (shown again after a refused save). @return array<string, string> */
    private function postedDraft(): array
    {
        $out = [];
        foreach (['title', 'intro', 'terms', 'valid_until', 'discount_percent', 'vat_mode', 'language', 'owner_id', 'contact_id', 'company_id', 'deal_id', 'price_list_id',
            'customer_name', 'customer_company', 'customer_address', 'customer_reg_no', 'customer_vat_id', 'customer_email'] as $key) {
            if ($this->request->hasPost($key)) {
                $out[$key] = $this->request->post($key);
            }
        }

        return $out;
    }

    /**
     * The fields of Store::save from the editor: the customer block is sent unless a contact or company was newly chosen
     * (then it is filled from them); the price list only while the quote has no lines (one quote, one currency); the lines
     * without the removed and empty ones, plus a product (its code or #item), an own row or a free line added below.
     *
     * @param array<string, mixed> $quote Store::get
     * @return array<string, mixed>
     * @throws \DomainException
     */
    private function postedQuote(array $quote): array
    {
        $r = $this->request;
        $modules = $this->kernel->modules();
        $fields = ['expected_updated_at' => $r->post('updated_at')];
        foreach (['title', 'intro', 'terms', 'valid_until', 'discount_percent', 'vat_mode'] as $key) {
            if ($r->hasPost($key)) {
                $fields[$key] = $r->post($key);
            }
        }
        if ($r->hasPost('language')) {
            $fields['language'] = $r->post('language');
        }
        if ($r->hasPost('owner_id')) {
            $fields['owner_id'] = $r->postInt('owner_id');
        }
        $chosen = false;
        if (isset($modules[Customers::SECTION])) {
            foreach (['contact_id', 'company_id'] as $key) {
                if ($r->hasPost($key) && $r->postInt($key) !== (int) ($quote[$key] ?? 0)) {
                    $fields[$key] = $r->postInt($key);
                    $chosen = $chosen || $r->postInt($key) > 0;
                }
            }
        }
        if (!$chosen) {
            $fields['customer'] = [];
            foreach (array_keys(Store::CUSTOMER) as $key) {
                $fields['customer'][$key] = $r->post('customer_' . $key);
            }
        }
        if (isset($modules[Pipeline::IDENT]) && $r->hasPost('deal_id') && $r->postInt('deal_id') !== (int) ($quote['deal_id'] ?? 0)) {
            $fields['deal_id'] = $r->postInt('deal_id');
        }
        $listId = $quote['price_list_id'];
        if ($quote['lines'] === [] && $r->postInt('price_list_id') > 0 && $r->postInt('price_list_id') !== $quote['price_list_id']) {
            $fields['price_list_id'] = $listId = $r->postInt('price_list_id');
        }
        $lines = [];
        foreach ($this->postedLines() as $line) {
            if ($line['remove'] || ($line['name'] === '' && $line['item_id'] === '' && $line['code'] === '')) {
                continue;
            }
            $lines[] = ['id' => $line['id'], 'name' => $line['name'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit' => $line['unit'],
                'unit_price' => $line['unit_price'], 'discount_percent' => $line['discount_percent'], 'vat_rate' => $line['vat_rate']]
                + ((int) $line['item_id'] > 0 ? ['item_id' => (int) $line['item_id'], 'variant_code' => $line['variant_code']] : ['code' => $line['code']]);
        }
        $quantity = $r->post('add_quantity');
        $product = trim($r->post('add_product'));
        if ($product !== '') {
            if (preg_match('/^#(\d+)$/', $product, $m) === 1) {
                $lines[] = ['item_id' => (int) $m[1], 'quantity' => $quantity];
            } elseif (Prices::findCode($this->db, $product) !== null || Prices::byCode($this->db, $listId, $product) !== null) {
                $lines[] = ['code' => $product, 'quantity' => $quantity];
            } else {
                throw new \DomainException(t('No product or own row of the price list has the code %s – choose one from the list.', mb_substr($product, 0, 60)));
            }
        }
        if ($r->post('add_own') !== '') {
            $lines[] = ['code' => $r->post('add_own'), 'quantity' => $r->post('add_own_quantity')];
        }
        if (trim($r->post('add_free_name')) !== '') {
            $lines[] = ['name' => $r->post('add_free_name'), 'unit_price' => $r->post('add_free_price'), 'quantity' => $r->post('add_free_quantity'), 'code' => ''];
        }
        $fields['lines'] = $lines;

        return $fields;
    }

    /** Where a refused "New quote" returns: the record it came from, or the list. @param array<string, int> $fields */
    private function returnTo(array $fields): string
    {
        return $this->app->url(match (true) {
            isset($fields['enquiry_id']) => 'admin.php?module=enquiries&action=detail&id=' . $fields['enquiry_id'],
            isset($fields['deal_id']) => Deals::adminPath($fields['deal_id']),
            isset($fields['contact_id']) => Customers::adminPath($fields['contact_id']),
            isset($fields['company_id']) => Customers::companyAdminPath($fields['company_id']),
            default => 'admin.php?module=' . self::IDENT,
        });
    }

    /**
     * Who may be responsible for a quote: active administrators and people with the section Quotes (and $keep, a former
     * one, so a stored choice stays visible).
     *
     * @return array<int, string> idu => name
     */
    private static function people(Db $db, int $keep = 0): array
    {
        $name = "IF(jmeno = '', user, jmeno)";

        return array_map('strval', $db->pairs('SELECT idu, ' . $name . ' FROM {uzivatele} u WHERE (blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))) OR idu = ? ORDER BY '
            . $db->sortText($name), [Auth::ADMIN, Prices::SECTION, $keep]));
    }

    /**
     * The other quotes of the same series: the original of a revision and its revisions (number, status, link).
     *
     * @param array<string, mixed> $quote Store::get
     * @return list<array{id: int, label: string, status: string}>
     */
    private function revisions(array $quote): array
    {
        $root = $quote['revision_of'] ?? $quote['id'];
        $out = [];
        foreach ($this->db->all('SELECT id, number, status FROM {quotes} WHERE (id = ? OR revision_of = ?) AND id <> ? ORDER BY id', [$root, $root, $quote['id']]) as $r) {
            $out[] = ['id' => (int) $r['id'], 'label' => Store::label($r['number'], (int) $r['id']), 'status' => (string) $r['status']];
        }

        return $out;
    }

    /**
     * What views/admin/quotes/box.php shows on an enquiry, a deal, a customer or a company: their quotes (newest first) with
     * the status, the net total and the validity, and "New quote" (a POST that makes the draft from the record – the
     * enquiry's basket, the deal's customer). Null for someone who may not open Quotes (or with the feature off), so the
     * page carries no amount.
     *
     * @param array<string, class-string<Module>> $modules Kernel::modules()
     * @param 'enquiry'|'deal'|'contact'|'company' $kind
     * @return array<string, mixed>|null
     */
    public static function box(App $app, array $modules, string $kind, int $id): ?array
    {
        if (!isset($modules[self::IDENT]) || $id <= 0) {
            return null;
        }
        $quotes = Store::of($app->db(), $kind, $id, self::BOX + 1);

        $priceList = null;
        if (in_array($kind, ['contact', 'company'], true) && isset($modules[Customers::SECTION])) {
            // 3.40: the price list a new quote for this customer takes (Store::customerList), chosen here
            $current = Store::customerListId($app->db(), $kind, $id);
            $priceList = ['current' => $current, 'lists' => array_values(array_filter(Prices::lists($app->db(), true), fn (array $l): bool => $l['archived_at'] === null || $l['id'] === $current)),
                'default' => Prices::defaultList($app->db(), $app->settings())['name'], 'action' => $app->url('admin.php?module=' . self::IDENT . '&action=customer_list')];
        }

        return ['kind' => $kind, 'id' => $id, 'quotes' => array_map(fn (array $q): array => $q + ['url' => $app->url(Store::adminPath($q['id']))], array_slice($quotes, 0, self::BOX)),
            'more' => count($quotes) > self::BOX, 'createUrl' => $app->url('admin.php?module=' . self::IDENT . '&action=create'),
            'allUrl' => $app->url('admin.php?module=' . self::IDENT . '&' . $kind . '=' . $id), 'priceList' => $priceList];
    }

    /**
     * The price rows a form posted as parallel lists (item[], variant[], code[], label[], price[], unit[], vat[], note[]).
     *
     * @return list<array<string, mixed>>
     */
    private function postedRows(): array
    {
        $r = $this->request;
        $items = $r->postList('item');
        $prices = $r->postList('price');
        $variants = $r->postList('variant');
        $codes = $r->postList('code');
        $labels = $r->postList('label');
        $units = $r->postList('unit');
        $vats = $r->postList('vat');
        $notes = $r->postList('note');
        $rows = [];
        foreach ($prices as $i => $price) {
            $item = (int) ($items[$i] ?? 0);
            $rows[] = ($item > 0 ? ['item_id' => $item, 'variant_code' => $variants[$i] ?? ''] : ['code' => $codes[$i] ?? '', 'label' => $labels[$i] ?? ''])
                + ['unit_price' => $price, 'unit' => $units[$i] ?? '', 'vat_rate' => $vats[$i] ?? '', 'note' => $notes[$i] ?? ''];
        }

        return $rows;
    }

    /** The checked import of this list waiting for its confirmation, with its preview; null = none. @return array<string, mixed>|null */
    private function pendingImport(int $listId): ?array
    {
        $pending = $this->app->session->get(self::IMPORT_KEY);
        if (!is_array($pending) || (int) ($pending['list'] ?? 0) !== $listId || !is_array($pending['rows'] ?? null)) {
            return null;
        }

        return ['name' => (string) ($pending['name'] ?? '')] + Prices::importPreview($this->db, $listId, $pending['rows']);
    }

    /** The box "Internal prices" of an item form (Collections) – only with the feature and the section; null = no box. @return array<string, mixed>|null */
    public static function itemBox(App $app, int $itemId): ?array
    {
        if ($itemId <= 0 || !Prices::isOn($app->settings()) || !$app->auth()->hasModule(Prices::SECTION)) {
            return null;
        }
        $item = Prices::item($app->db(), $itemId);
        if ($item === null || !$item['product']) {
            return null; // 3.42: only items of a products collection – the products preset or one mapped in Quotes → Price list set-up
        }

        return ['item' => $item, 'lists' => Prices::lists($app->db()), 'prices' => Prices::ofItem($app->db(), $itemId), 'canEdit' => $app->auth()->canEditPrices(),
            'rates' => Prices::vatRates($app->settings())];
    }
}
