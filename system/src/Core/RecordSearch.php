<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The search of the command palette (3.52, A4, A5): the records a person may open – pages, news, collection items,
 * media, enquiries, quotes, their own open tasks, users, customers, deals, job applications and internal files – in one
 * answer, grouped by kind. Each kind only with its section (the list of sections the person may open, Admin\Kernel::modules())
 * and with the conditions of its list in the administration (Auth::articleScope, collectionItemListScope,
 * EnquiryVisibility::visible, the folders of InternalFiles…). Used by admin.php?action=palette_search (image/admin.js)
 * and by MCP search (alias hledej); the palette's own lists of sections and actions are in Admin\Palette.
 */
final class RecordSearch
{
    /** The columns of the Media list's search (Admin\Modules\Media::SEARCH_COLUMNS – a unit test keeps them the same). */
    public const array MEDIA_COLUMNS = ['o.nazev', 'o.popis', 'o.obr_poloha'];

    /** The columns of the Enquiries list's search besides the data (Admin\Modules\Enquiries::SEARCH_COLUMNS – the same). */
    public const array ENQUIRY_COLUMNS = ['email', 'formular', 'poznamka', 'tema', 'stranka'];

    /** Records of one kind the palette shows; the rest is "+ N more". */
    public const int GROUP = 5;

    /** Rows of one kind read before ranking (the best GROUP of them are shown). */
    private const int FETCH = 20;

    /**
     * The records a person may open that match every word of $q (Db::likeWords – any order, diacritics do not matter),
     * grouped by kind: at most GROUP of each, the exact name and the start of the name first (rank()), the groups in
     * the order of their best record. total = all of that kind that match; more = its list with ?search= ('' when the
     * list cannot search). URLs are of the administration, without the origin. Under two characters: nothing.
     * $applicationTasks false leaves out the tasks about a job application (MCP search while applications_claude is off).
     *
     * @param list<string> $modules the sections the person may open (Kernel::modules())
     * @return list<array{type: string, label: string, items: list<array{id: int, title: string, note: string, url: string}>, total: int, more: string}>
     */
    public static function search(App $app, string $q, array $modules, bool $applicationTasks = true): array
    {
        $q = mb_substr(trim($q), 0, 100);
        if (!Search::longEnough($q)) { // 3.53: two characters, or one CJK character
            return [];
        }
        $may = fn (string $ident): bool => in_array($ident, $modules, true);
        $kinds = [
            'page' => ['pages', 'Pages', self::pages(...)],
            'news' => ['news', 'News', self::news(...)],
            'collection_item' => ['collections', 'Collection items', self::collectionItems(...)],
            'media' => ['media', 'Media', self::media(...)],
            'enquiry' => ['enquiries', 'Enquiries', self::enquiries(...)],
            'quote' => [Prices::SECTION, 'Quotes', self::quotes(...)],
            'task' => ['my_work', 'Tasks', fn (App $app, string $q): array => self::tasks($app, $q, $applicationTasks)],
            'user' => ['users', 'Users', self::users(...)],
            'contact' => [Contacts::SECTION, 'Customers', self::contacts(...)],
            'deal' => [Deals::SECTION, 'Deals', self::deals(...)],
            'application' => [Applications::SECTION, 'Applications', self::applications(...)],
            'internal_file' => [InternalFiles::SECTION, 'Internal files', self::internalFiles(...)],
        ];
        $groups = [];
        foreach ($kinds as $type => [$ident, $label, $find]) {
            if (!$may($ident)) {
                continue;
            }
            // each finder: [rows of at most FETCH as [id, title, note, url], total, the list's query with ?search= or '']
            [$rows, $total, $more] = $find($app, $q, $modules);
            if ($rows === []) {
                continue;
            }
            $ranks = array_map(fn (array $r): int => self::rank($r['title'], $q), $rows);
            array_multisort($ranks, SORT_ASC, array_keys($rows), SORT_ASC, $rows); // stable: the list's own order within a rank
            $groups[] = ['type' => $type, 'label' => t($label), 'items' => array_slice($rows, 0, self::GROUP), 'total' => max($total, count($rows)),
                'more' => $more !== '' ? $app->url('admin.php?' . $more . '&search=' . rawurlencode($q)) : '', 'best' => $ranks[0]];
        }
        usort($groups, fn (array $a, array $b): int => $a['best'] <=> $b['best']); // usort is stable: the order above within a rank

        return array_map(function (array $g): array {
            unset($g['best']);

            return $g;
        }, $groups);
    }

    /**
     * How well a name fits a search, without regard to case, diacritics, punctuation or script (Search::normalize, which
     * folds every Latin alphabet – 3.53, B4): 0 = the same words in any order ("novak jan" is "Jan Novák"), 1 = it starts
     * with it, 2 = every word of the search starts a word of it, 3 = anywhere (each word somewhere).
     */
    public static function rank(string $title, string $q): int
    {
        $name = Search::normalize($title);
        $wanted = Search::normalize($q);
        if ($wanted === '' || $name === '') {
            return 3;
        }
        $set = function (string $text): array {
            $words = array_values(array_unique(explode(' ', $text)));
            sort($words);

            return $words;
        };
        $starts = fn (string $word): bool => str_contains(' ' . $name, ' ' . $word);

        return match (true) {
            $set($name) === $set($wanted) => 0,
            str_starts_with($name, $wanted) => 1,
            array_filter(explode(' ', $wanted), fn (string $w): bool => !$starts($w)) === [] => 2,
            default => 3,
        };
    }

    /**
     * The start of an ORDER BY that reads the exact name first, then a name that starts with the search, before the
     * list's own order (3.53, B4) – so the best record is among the FETCH rows read, however many newer ones mention it.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function first(string $column, string $q): array
    {
        $c = $column . ' COLLATE ' . Db::SEARCH_COLLATION;

        return [$c . ' = ? DESC, ' . $c . ' LIKE ? DESC, ', [$q, addcslashes($q, '%_\\') . '%']];
    }

    /** The total of a kind: the rows read when fewer than FETCH, otherwise one COUNT with the same condition. */
    private static function total(App $app, array $rows, string $from, array $params): int
    {
        return count($rows) < self::FETCH ? count($rows) : (int) $app->db()->value('SELECT COUNT(*) ' . $from, $params);
    }

    /** "English · hidden" – the parts that are not empty. */
    private static function note(string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn (string $p): bool => $p !== ''));
    }

    /** Pages (the Pages list, trash left out): the name, the address, the SEO title or the text (SearchIndex::pageWords – as the list); the language and "hidden" as the note. */
    private static function pages(App $app, string $q): array
    {
        [$where, $params] = SearchIndex::pageWords($q, SearchIndex::ready($app->settings()));
        [$order, $orderParams] = self::first('titulek', $q);
        $from = 'FROM {stranky} WHERE smazano IS NULL AND ' . $where;
        $settings = $app->settings();
        $several = Language::additional($settings) !== [];
        $rows = array_map(fn (array $s): array => ['id' => (int) $s['ids'], 'title' => (string) $s['titulek'],
            'note' => self::note($several ? (Language::AVAILABLE[Language::ofContent($settings, (string) $s['jazyk'])][0] ?? (string) $s['jazyk']) : '', $s['zobrazit'] ? '' : t('hidden'), '/' . $s['seo_link']),
            'url' => $app->url('admin.php?module=pages&action=edit&id=' . (int) $s['ids'])],
            $app->db()->all('SELECT ids, titulek, seo_link, jazyk, zobrazit ' . $from . ' ORDER BY ' . $order . 'poradi, ' . $app->db()->sortText('titulek') . ' LIMIT ' . self::FETCH, [...$params, ...$orderParams]));

        return [$rows, self::total($app, $rows, $from, $params), 'module=pages'];
    }

    /** News the person may edit (an author only their own – articleScope, as the News list), by the title, the address or the text. */
    private static function news(App $app, string $q): array
    {
        [$where, $params] = Search::newsWords($q, '');
        [$order, $orderParams] = self::first('titulek', $q);
        $from = 'FROM {novinky} WHERE smazano IS NULL AND ' . $where . $app->auth()->articleScope();
        $rows = array_map(fn (array $c): array => ['id' => (int) $c['idc'], 'title' => (string) $c['titulek'],
            'note' => self::note(format_date((string) $c['datum']), $c['vydany'] ? '' : t('not published')),
            'url' => $app->url('admin.php?module=news&action=edit&id=' . (int) $c['idc'])],
            $app->db()->all('SELECT idc, titulek, datum, visible AND datum <= ? AS vydany ' . $from . ' ORDER BY ' . $order . 'datum DESC LIMIT ' . self::FETCH, [Clock::now(), ...$params, ...$orderParams]));

        return [$rows, self::total($app, $rows, $from, $params), 'module=news'];
    }

    /** Collection items outside the trash by the name, the address or the fields (SearchIndex::itemWords – as their list); the collection as the note (its list is per collection). */
    private static function collectionItems(App $app, string $q): array
    {
        [$where, $params] = SearchIndex::itemWords($q, SearchIndex::ready($app->settings()), 'p.');
        [$order, $orderParams] = self::first('p.nazev', $q);
        $from = 'FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL AND ' . $where . $app->auth()->collectionItemListScope('p.');
        $rows = array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'title' => (string) $p['nazev'],
            'note' => self::note((string) $p['kolekce'], $p['zobrazit'] ? '' : t('hidden')),
            'url' => $app->url('admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'])],
            $app->db()->all('SELECT p.idp, p.idk, p.nazev, p.zobrazit, k.nazev AS kolekce ' . $from . ' ORDER BY ' . $order . 'p.zobrazit DESC, ' . $app->db()->sortText('p.nazev') . ' LIMIT ' . self::FETCH, [...$params, ...$orderParams]));

        return [$rows, self::total($app, $rows, $from, $params), ''];
    }

    /** Files in Media by the name, the description or the file name (MEDIA_COLUMNS – as the Media list); a file opens its list filtered by it. */
    private static function media(App $app, string $q): array
    {
        [$where, $params] = Db::likeWords(self::MEDIA_COLUMNS, $q);
        [$order, $orderParams] = self::first('o.nazev', $q);
        $from = 'FROM {media} o WHERE ' . $where;
        $rows = array_map(function (array $m) use ($app): array {
            $file = basename((string) $m['obr_poloha']);

            return ['id' => (int) $m['ido'], 'title' => $m['nazev'] !== '' ? (string) $m['nazev'] : $file, 'note' => self::note(strtoupper(pathinfo($file, PATHINFO_EXTENSION)), $m['nazev'] !== '' ? $file : ''),
                'url' => $app->url('admin.php?module=media&search=' . rawurlencode($file))];
        }, $app->db()->all('SELECT o.ido, o.nazev, o.obr_poloha ' . $from . ' ORDER BY ' . $order . 'o.datum DESC LIMIT ' . self::FETCH, [...$params, ...$orderParams]));

        return [$rows, self::total($app, $rows, $from, $params), 'module=media'];
    }

    /**
     * Enquiries as the Enquiries list shows them: spam left out, and only what the person may see there (Enquiries::visible
     * – the job enquiries still moving only with Applications); the sender, the form, its topic and page, the note, the data.
     */
    private static function enquiries(App $app, string $q, array $modules): array
    {
        [$where, $params] = Db::likeWords(self::ENQUIRY_COLUMNS, $q, ['data']);
        $from = 'FROM {poptavky} WHERE ' . EnquiryVisibility::NOT_SPAM . ' AND ' . $where
            . ' AND ' . EnquiryVisibility::visible($app->db(), $app->settings(), in_array(Applications::SECTION, $modules, true));
        $rows = array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'title' => (string) ($p['email'] !== '' ? $p['email'] : ($p['formular'] !== '' ? $p['formular'] : '#' . $p['idp'])),
            'note' => self::note('#' . (int) $p['idp'], $p['email'] !== '' ? (string) $p['formular'] : '', format_date((string) $p['datum'])),
            'url' => $app->url('admin.php?module=enquiries&action=detail&id=' . (int) $p['idp'])],
            $app->db()->all('SELECT idp, datum, formular, email ' . $from . ' ORDER BY idp DESC LIMIT ' . self::FETCH, $params));

        return [$rows, self::total($app, $rows, $from, $params), 'module=enquiries'];
    }

    /** Quotes by the number, the title or the customer (Core\Quotes::list – the list of Quotes). */
    private static function quotes(App $app, string $q): array
    {
        $result = Quotes::list($app->db(), ['search' => $q], 1, self::FETCH);
        $rows = array_map(fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) ($r['title'] !== '' ? $r['title'] : $r['number']),
            'note' => self::note((string) $r['number'], (string) ($r['customer_company'] !== '' ? $r['customer_company'] : $r['customer_name'])),
            'url' => $app->url('admin.php?module=quotes&action=detail&id=' . (int) $r['id'])], $result['rows']);

        return [$rows, $result['total'], 'module=quotes'];
    }

    /** The person's own open tasks by the title – the ones My work lists for them (Core\Tasks::forUser); without the ones about an application on request. */
    private static function tasks(App $app, string $q, bool $applicationTasks = true): array
    {
        [$where, $params] = Db::likeWords(['title'], $q);
        $from = 'FROM {tasks} WHERE assignee_id = ? AND done_at IS NULL AND ' . $where . ($applicationTasks ? '' : " AND (target IS NULL OR target NOT LIKE 'application:%')");
        $params = [$app->auth()->id(), ...$params];
        [$order, $orderParams] = self::first('title', $q);
        try {
            $rows = array_map(fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'note' => $r['due_at'] !== null ? format_date((string) $r['due_at']) : '',
                'url' => $app->url('admin.php?module=my_work') . '#task-' . (int) $r['id']],
                $app->db()->all('SELECT id, title, due_at ' . $from . ' ORDER BY ' . $order . 'due_at IS NULL, due_at, id LIMIT ' . self::FETCH, [...$params, ...$orderParams]));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return [[], 0, '']; // before migration 0113
        }

        return [$rows, self::total($app, $rows, $from, $params), ''];
    }

    /** User accounts (administrators – the Users section) by the user name, the name or the e-mail. */
    private static function users(App $app, string $q): array
    {
        [$where, $params] = Db::likeWords(['user', 'jmeno', 'email'], $q);
        $from = 'FROM {uzivatele} WHERE ' . $where;
        $rows = array_map(fn (array $u): array => ['id' => (int) $u['idu'], 'title' => (string) ($u['jmeno'] !== '' ? $u['jmeno'] : $u['user']),
            'note' => self::note((string) $u['email'], $u['blokovat'] ? t('blocked') : ''), 'url' => $app->url('admin.php?module=users&action=edit&id=' . (int) $u['idu'])],
            $app->db()->all('SELECT idu, user, jmeno, email, blokovat ' . $from . ' ORDER BY blokovat, ' . $app->db()->sortText('user') . ' LIMIT ' . self::FETCH, $params));

        return [$rows, self::total($app, $rows, $from, $params), ''];
    }

    /** Customers by the name, the e-mail, the phone or the company (Core\Contacts::list – never an anonymised one). */
    private static function contacts(App $app, string $q): array
    {
        $result = Contacts::list($app->db(), ['search' => $q, 'sort' => 'activity'], 1, self::FETCH);
        $rows = array_map(fn (array $c): array => ['id' => (int) $c['id'], 'title' => Contacts::displayName($c),
            'note' => self::note((string) ($c['company_name'] ?? ''), $c['name'] !== '' ? (string) $c['email'] : ''),
            'url' => $app->url('admin.php?module=contacts&action=detail&id=' . (int) $c['id'])], $result['rows']);

        return [$rows, $result['total'], 'module=contacts'];
    }

    /** Deals, open and closed, by the title, the customer or the company (Core\Deals::list); the stage as the note. */
    private static function deals(App $app, string $q): array
    {
        $result = Deals::list($app, ['search' => $q, 'status' => 'all'], 1, self::FETCH);
        $stages = array_column(Deals::stages($app->settings()), 'label', 'key');
        $rows = array_map(fn (array $d): array => ['id' => (int) $d['id'], 'title' => (string) $d['title'],
            'note' => self::note((string) ($stages[$d['stage']] ?? $d['stage']), (string) ($d['company_name'] ?? '') !== '' ? (string) $d['company_name'] : (string) ($d['contact_name'] ?? '')),
            'url' => $app->url('admin.php?module=pipeline&action=detail&id=' . (int) $d['id'])], $result['rows']);

        return [$rows, $result['total'], 'module=pipeline&view=list&status=all'];
    }

    /** Job applications by the name, the e-mail, the position or the fields (Core\Applications::list – the list of Applications). */
    private static function applications(App $app, string $q): array
    {
        $result = Applications::list($app, ['search' => $q], 1, self::FETCH);
        $rows = array_map(fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['name'] !== '' ? (string) $r['name'] : t('Application #%s', (string) $r['id']),
            'note' => self::note('#' . (int) $r['id'], (string) $r['job_title'], t(Applications::STATUS_LABELS[(string) $r['status']] ?? (string) $r['status'])),
            'url' => $app->url('admin.php?module=applications&action=detail&id=' . (int) $r['id'])], $result['rows']);

        // status present and empty = every status, as the rows counted here (the list shows only the open ones without it)
        return [$rows, $result['total'], 'module=applications&status='];
    }

    /** Ready internal files of the folders the person sees, by the name, the description or the tags (Core\InternalFiles::files). */
    private static function internalFiles(App $app, string $q): array
    {
        $result = InternalFiles::files($app, ['search' => $q, 'status' => 'ready'], 1, self::FETCH);
        $rows = array_map(fn (array $f): array => ['id' => (int) $f['id'], 'title' => (string) $f['name'],
            'note' => self::note((string) $f['folder_name'], strtoupper((string) $f['extension'])),
            'url' => $app->url('admin.php?module=internal_files&action=file&id=' . (int) $f['id'])], $result['rows']);

        return [$rows, $result['total'], 'module=internal_files'];
    }
}
