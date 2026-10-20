<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Fleet\Console;
use Kaleta\Fleet\Customers;
use Kaleta\Fleet\Inbox;
use Kaleta\Fleet\Kit;

/**
 * The fleet console (2.9, extension "fleet", Fleet\Console): every paired site on one screen, the ones that need attention
 * first; a site's last report; pairing keys; the update ring of each site and allowing a new version by hand. Since 2.16
 * also the shared design kit (Fleet\Kit) the sites receive as drafts. Since 3.17 the console opens on the inbox – what waits
 * on every site (Fleet\Inbox) – and sites belong to customers (Fleet\Customers).
 */
final class Fleet extends Module
{
    public const string IDENT = 'fleet';
    public const string NAME = 'Fleet';
    public const string GROUP = 'Administration';
    public const string ICON = 'web';
    public const string EXTENSION = 'fleet';
    public const bool ADMIN_ONLY = true;

    /**
     * The inbox (3.17, Fleet\Inbox): everything that waits on the sites, the most urgent and the oldest first – filtered by
     * kind, site, customer and "only urgent"; the filter bar counts each kind under the other filters.
     */
    protected function actionList(): Response
    {
        $all = Inbox::load($this->db, time());
        $customer = $this->request->getInt('customer');
        $filters = ['site' => $this->request->getInt('site'), 'customer' => $customer, 'urgent' => $this->request->get('urgent') === '1'];
        $kind = isset(Inbox::KINDS[$this->request->get('kind')]) ? $this->request->get('kind') : '';
        $narrowed = Inbox::filter($all, $filters);
        $sites = Console::sites($this->db);

        return $this->view('inbox', 'Fleet', ['rows' => Inbox::filter($narrowed, ['kind' => $kind]), 'counts' => Inbox::counts($narrowed), 'narrowed' => count($narrowed),
            'total' => count($all), 'urgent' => count(array_filter($all, fn (array $r): bool => $r['urgency'] === 'urgent')), 'kind' => $kind, 'filters' => $filters,
            'sites' => array_map(fn (array $s): string => (string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url'], array_column($sites, null, 'id')),
            'customers' => Customers::names($this->db), 'siteCount' => count($sites), 'pairingKey' => $this->pairingKey()]);
    }

    /** All sites with what needs attention (2.9), since 3.17 with their customer and how much waits on each. */
    protected function actionSites(): Response
    {
        $sites = Console::overview($this->db, time());
        $show = $this->request->get('show') === 'all' ? 'all' : 'attention';
        $query = mb_strtolower(trim($this->request->get('q')));
        $customers = Customers::names($this->db);
        $customer = isset($customers[$this->request->getInt('customer')]) ? $this->request->getInt('customer') : 0;
        $needing = count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        $listed = array_values(array_filter($sites, fn (array $s): bool => ($show === 'all' || $s['score'] >= Console::REASONS['warnings'] || $query !== '' || $customer > 0)
            && ($query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['url'] . ' ' . ($customers[(int) $s['customer_id']] ?? '')), $query))
            && ($customer === 0 || (int) $s['customer_id'] === $customer)));

        return $this->view('list', 'Fleet', ['sites' => $listed, 'total' => count($sites), 'needing' => $needing, 'show' => $show, 'query' => $query,
            'customers' => $customers, 'customer' => $customer, 'waiting' => Inbox::waitingBySite(Inbox::load($this->db, time())),
            'latest' => Console::latest($this->app)[0], 'pairingKey' => $this->pairingKey()]);
    }

    /** The customers (3.17): how many sites each has and how much waits, renaming, the new version for one customer's sites. */
    protected function actionCustomers(): Response
    {
        $waiting = Inbox::waitingBySite(Inbox::load($this->db, time()));
        $bySite = array_column(Console::sites($this->db), 'customer_id', 'id');
        $perCustomer = [];
        foreach ($waiting as $siteId => $n) {
            $id = (int) ($bySite[$siteId] ?? 0);
            $perCustomer[$id] = ($perCustomer[$id] ?? 0) + $n;
        }

        return $this->view('customers', 'Customers', ['customers' => Customers::all($this->db), 'waiting' => $perCustomer,
            'without' => count(array_filter($bySite, fn (mixed $c): bool => $c === null)), 'latest' => Console::latest($this->app)[0]]);
    }

    /** Sets the customer and the note of one site (the site detail). */
    protected function actionCustomer(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back('', 'sites');
        }
        Customers::assign($this->db, [(int) $site['id']], $this->request->post('customer'));
        Customers::setNote($this->db, (int) $site['id'], $this->request->post('note'));
        ChangeLog::write($this->app, 'fleet', 'customer', $site['url'] . ': ' . Customers::clean($this->request->post('customer')));

        return $this->back('Saved.', 'detail', ['id' => (int) $site['id']]);
    }

    /** Gives the ticked sites of the list one customer ('' = none). */
    protected function actionCustomerBulk(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'sites');
        }
        $ids = array_map('intval', is_array($_POST['sites'] ?? null) ? $_POST['sites'] : []);
        if ($ids === []) {
            return $this->back('Tick the sites first.', 'sites', ['show' => 'all'], 'chyba');
        }
        $name = Customers::clean($this->request->post('customer'));
        $count = Customers::assign($this->db, $ids, $name);
        ChangeLog::write($this->app, 'fleet', 'customer_bulk', $name . ': ' . $count);

        return $this->back($name === '' ? t('%d sites have no customer now.', $count) : t('%d sites belong to %s now.', $count, $name), 'sites',
            ($id = Customers::find($this->db, $name)) !== null ? ['customer' => $id] : ['show' => 'all']);
    }

    /** Renames a customer for all its sites (a name another customer has merges the two). */
    protected function actionCustomerRename(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'customers');
        }
        $id = $this->request->postInt('id');
        $old = (string) ($this->db->value('SELECT name FROM {fleet_customers} WHERE id = ?', [$id]) ?? '');
        if (!Customers::rename($this->db, $id, $this->request->post('name'))) {
            return $this->back('Fill in the new name.', 'customers', [], 'chyba');
        }
        ChangeLog::write($this->app, 'fleet', 'customer_rename', $old . ' → ' . Customers::clean($this->request->post('name')));

        return $this->back('Saved.', 'customers');
    }

    protected function actionDetail(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->back('The site is not in the console.', '', [], 'chyba');
        }
        $events = $this->db->all("SELECT created_at, type, severity, message FROM {events} WHERE type LIKE 'fleet.%' AND JSON_EXTRACT(data, '$.site') = ? ORDER BY id DESC LIMIT 20", [(int) $site['id']]);

        return $this->view('detail', (string) $site['name'], ['site' => $site, 'events' => $events, 'latest' => Console::latest($this->app)[0], 'customers' => Customers::names($this->db)]);
    }

    /** The pairing key made just now, shown once (the inbox or the list of sites, wherever the owner lands). */
    private function pairingKey(): string
    {
        $key = $this->app->session->get('fleet_pairing_key');
        $this->app->session->set('fleet_pairing_key', null);

        return is_string($key) ? $key : '';
    }

    protected function actionPairingKey(): Response
    {
        if ($this->request->isPost()) {
            if ($this->app->settings()->get('site_url') === '') {
                return $this->back('Fill in the site address in Settings → General first.', '', [], 'chyba');
            }
            $this->app->session->set('fleet_pairing_key', Console::newPairingKey($this->app));
            ChangeLog::write($this->app, 'fleet', 'pairing_key');
        }

        return $this->back();
    }

    protected function actionRing(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            $ring = $this->request->post('ring') === 'canary' ? 'canary' : 'normal';
            $this->db->update('fleet_sites', ['ring' => $ring], ['id' => (int) $site['id']]);
            ChangeLog::write($this->app, 'fleet', 'ring', $site['url'] . ': ' . $ring);
        }

        return $this->back('Saved.', $site !== null ? 'detail' : '', $site !== null ? ['id' => (int) $site['id']] : []);
    }

    /** Allows the newest version now – one site (id), one customer's sites (customer, 3.17) or every site that lets the console decide. */
    protected function actionAllow(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'sites');
        }
        $id = $this->request->postInt('id');
        $customer = $id > 0 ? 0 : $this->request->postInt('customer');
        $count = $customer > 0 ? Console::allowNowForCustomer($this->app, $customer) : Console::allowNow($this->app, $id > 0 ? $id : null);
        ChangeLog::write($this->app, 'fleet', 'allow_update', ($id > 0 ? '#' . $id : ($customer > 0 ? 'customer #' . $customer : 'all')) . ': ' . $count);

        return $this->back($count > 0 ? t('The new version is allowed on %d sites; each installs it with its next report (within an hour).', $count) : 'No site waits for a new version it may install.',
            $id > 0 ? 'detail' : ($customer > 0 ? 'customers' : 'sites'), $id > 0 ? ['id' => $id] : []);
    }

    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            $id = $this->request->postInt('id');
            Console::checkUptime($this->app, $id > 0 ? $id : null);
        }

        return $this->back('Checked.', $this->request->postInt('id') > 0 ? 'detail' : 'sites', $this->request->postInt('id') > 0 ? ['id' => $this->request->postInt('id')] : []);
    }

    protected function actionRemove(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            Console::remove($this->app, (int) $site['id']);
            ChangeLog::write($this->app, 'fleet', 'remove', (string) $site['url']);
        }

        return $this->back('The site was removed from the console. It stops reporting with its next report; pair it again to bring it back.', 'sites');
    }

    /** The shared design kit (2.16, Fleet\Kit): what the console offers the sites, and the versions published so far. */
    protected function actionKit(): Response
    {
        $s = $this->app->settings();

        return $this->view('kit', 'Shared kit', [
            'designSystem' => \Kaleta\Builder\DesignSystem::load($s), 'classes' => \Kaleta\Core\Look::classes($this->db, $s, false),
            'components' => \Kaleta\Builder\Components::all($this->db), 'sections' => $this->db->all('SELECT idx, nazev FROM {sekce} ORDER BY ' . $this->db->sortText('nazev')),
            'kits' => Kit::history($this->db), 'sites' => Console::sites($this->db), 'applied' => Kit::appliedVersions($this->db), 'customers' => Customers::names($this->db),
        ]);
    }

    /** Publishes a new kit version from the chosen parts; the sites that opted in pick it up with their next report, as drafts. */
    protected function actionKitPublish(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'kit');
        }
        $ids = fn (string $key): array => array_values(array_filter(array_map('intval', is_array($_POST[$key] ?? null) ? $_POST[$key] : []), fn (int $id): bool => $id > 0));
        $classes = $this->request->postBool('classes_all') ? null : array_values(array_filter(is_array($_POST['classes'] ?? null) ? $_POST['classes'] : [], 'is_string'));
        $customers = Customers::names($this->db);
        $customer = isset($customers[$this->request->postInt('customer')]) ? $this->request->postInt('customer') : null; // 3.17: one customer's sites only
        try {
            $kit = Kit::publish($this->app, ['design_system' => $this->request->postBool('design_system'), 'classes' => $classes, 'components' => $ids('components'), 'sections' => $ids('sections'),
                'customer' => $customer]);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), 'kit', [], 'chyba');
        }
        ChangeLog::write($this->app, 'fleet', 'kit_publish', 'version ' . $kit['version'] . ': ' . $kit['summary'] . ($customer !== null ? ' (' . $customers[$customer] . ')' : ''));

        return $this->back(t('Kit version %d is published (%s). Every site that receives the kit picks it up with its next report, within an hour – as drafts for its people to publish.', $kit['version'], $kit['summary']), 'kit');
    }

    /** @return array<string, mixed>|null the site from ?id= or the posted id, with its attention and decoded report */
    private function site(): ?array
    {
        $id = $this->request->isPost() ? $this->request->postInt('id') : $this->request->getInt('id');
        $row = $this->db->one('SELECT * FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $beat = json_decode((string) ($row['heartbeat'] ?? ''), true);

        return $row + Console::attention($row, time()) + ['beat' => is_array($beat) ? $beat : []];
    }
}
