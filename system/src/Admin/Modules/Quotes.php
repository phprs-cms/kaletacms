<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\App;
use Kaleta\Core\Auth;
use Kaleta\Core\Money;
use Kaleta\Core\Prices;
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
 *  - prices_save: the rows of the grid that changed (one change-log line with the count – never an amount).
 *  - item_prices_save: the box "Internal prices" on a product's form in Collections.
 *  - prices_export: the price list as CSV; prices_import: the uploaded CSV checked into a preview (new, changed, unknown);
 *    prices_import_run: writes it.
 *  - list_save, list_archive, list_default (administrators): add or rename a price list, archive or restore it, make it
 *    the default.
 *  - settings, settings_save (administrators): numbering and the next number, validity, VAT, the largest discount Claude
 *    may save, how long the customer's link works, retention, the deal value on sending.
 */
final class Quotes extends Module
{
    public const string IDENT = Prices::SECTION;
    public const string NAME = 'Quotes';
    public const string GROUP = 'Workspace';
    public const string ICON = 'clanek';
    public const string EXTENSION = Prices::EXTENSION;
    public const bool GRANTED_EXPLICITLY = true;

    /** The session key of a checked CSV import waiting for its confirmation. */
    private const string IMPORT_KEY = 'prices_import';

    protected function actionList(): Response
    {
        return $this->back('', 'prices'); // the quote list comes with the quote screens (3.38, the next part)
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

        return $this->view('prices', 'Internal prices', ['lists' => $lists, 'list' => $list, 'filter' => $filter, 'grid' => Prices::grid($this->db, $list['id'], $filter, $page),
            'own' => Prices::ownRows($this->db, $list['id']), 'collections' => Prices::productCollections($this->db), 'rates' => Prices::vatRates($s),
            'defaultRate' => Prices::defaultVatRate($s), 'canEdit' => $this->app->auth()->canEditPrices(), 'admin' => $this->app->auth()->isAdmin(),
            'import' => $this->pendingImport($list['id'])]);
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
        $keys = ['quote_number_format', 'quote_valid_days', 'quote_vat_payer', 'quote_vat_rate', 'quote_vat_rates', 'quote_max_discount', 'quote_link_days', 'quotes_months', 'quote_set_deal_value'];

        return $this->view('settings', 'Quotes', ['values' => array_combine($keys, array_map(fn (string $k): string => $s->get($k), $keys)), 'next' => Store::nextNumber($this->db, $s),
            'people' => array_map(fn (array $u): string => (string) ($u['jmeno'] ?: $u['user']), $this->db->all('SELECT DISTINCT u.jmeno, u.user FROM {uzivatele} u JOIN {uzivatele_prava} p ON p.fk_id_user = u.idu'
                . ' WHERE p.ident_modulu = ? AND u.admin <> ? AND u.blokovat = 0 ORDER BY u.jmeno', [Prices::SECTION, Auth::ADMIN]))]);
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
        $s->set('quote_set_deal_value', $this->request->postBool('quote_set_deal_value') ? '1' : '0');
        $s->set('quote_valid_days', (string) max(1, min(365, $this->request->postInt('quote_valid_days', 30))));
        $s->set('quote_link_days', (string) max(1, min(365, $this->request->postInt('quote_link_days', 30))));
        $s->set('quotes_months', (string) max(0, min(120, $this->request->postInt('quotes_months', 36))));
        $next = $this->request->postInt('quote_next_number');
        $message = 'The settings of quotes were saved.';
        if ($next > 0) {
            $s->set('quote_next_number', (string) $next);
            if (!Store::raiseCounter($this->db, $next)) {
                $message = t('The settings of quotes were saved. The next number stays %s – a series only goes up.', Store::nextNumber($this->db, $s));
            }
        }
        ChangeLog::write($this->app, Prices::SECTION, 'settings', '');

        return $this->back($message, 'settings');
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
        if ($item === null) {
            return null;
        }

        return ['item' => $item, 'lists' => Prices::lists($app->db()), 'prices' => Prices::ofItem($app->db(), $itemId), 'canEdit' => $app->auth()->canEditPrices(),
            'rates' => Prices::vatRates($app->settings())];
    }
}
