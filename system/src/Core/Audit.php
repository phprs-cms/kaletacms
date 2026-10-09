<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;
use Kaleta\Builder\Check;

/**
 * Site audit (1.9): what hurts a site in search engines and for visitors, collected across the whole site – for the
 * administrator (Administration → Site audit) and for Claude (site_audit), who can then fix it.
 *
 *  - links on the site to pages, news or items that do not exist (pages, site parts, templates, components, pop-ups,
 *    the menu, collection items and news), and broken external links found by the background link check (Core\Links:
 *    since 2.14 in page builds and collection items too, with the element);
 *  - orphan pages (2.14, Core\InternalLinks): published pages, news and items nothing on the site links to;
 *  - pages and item pages without a description, duplicate titles;
 *  - menu items pointing at hidden or deleted pages;
 *  - the builder check of every published build: buttons without a link, images without alt, the heading outline;
 *  - the most frequent addresses that end in 404 and have no redirect;
 *  - accessibility (2.3, the European Accessibility Act, WCAG 2.2 AA): colour contrast of the design system, links that
 *    do not say where they lead, images in text without alt, empty links, tables without header cells, frames without a
 *    title, and whether the site has an accessibility statement;
 *  - real-user speed (2.8): pages whose p75 LCP got worse by more than a quarter against the previous 30 days (Core\WebVitals);
 *  - review by (2.10): pages, news items, collection items and pop-ups whose review-by day has come (Core\Validity);
 *  - before handing the site over (2.4): what an agency checks before a client takes it – mail, backups, two-step sign-in,
 *    legal pages, indexing, tracking without consent, the client's own account, the agency's contact; since 2.8 also the
 *    security hygiene (Core\SecurityHygiene): unused accounts and Claude connections, the automatic suspension.
 *
 * Runs on demand only: a company site has hundreds of rows, not millions.
 */
final class Audit
{
    /** Kinds of findings in the order they are shown. */
    public const array KINDS = [
        'link' => 'Broken links', 'orphan' => 'Pages nobody links to', 'menu' => 'Menu', 'description' => 'Missing descriptions', 'title' => 'Duplicate titles',
        'build' => 'Buttons, images and headings', 'review' => 'Review by', 'job' => 'Job openings', 'document' => 'Document expires soon', 'accessibility' => 'Accessibility', 'not_found' => 'Frequent 404 errors', 'speed' => 'Speed', 'fact' => 'Facts', 'blueprint' => 'Industry checks', 'handover' => 'Before handing over',
    ];

    /** At most this many findings of one kind – beyond that the list would not help anyone. */
    private const int PER_KIND = 100;

    /** @var array<string, bool> resolved internal paths */
    private array $resolved = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return list<array{kind: string, where: string, message: string, edit: string, url: string, target: array<string, int|string>, element?: string}>
     */
    public function run(): array
    {
        $this->findings = [];
        $this->pages();
        $this->parts();
        $this->collections();
        $this->menus();
        $this->news();
        $this->brokenLinks();
        $this->orphans();
        $this->review();
        $this->jobs();
        $this->documents();
        $this->accessibility();
        $this->notFound();
        $this->speed();
        $this->facts();
        $this->blueprint();
        $this->handover();
        $order = array_flip(array_keys(self::KINDS));
        $counts = [];
        $out = [];
        foreach ($this->findings as $f) {
            $counts[$f['kind']] = ($counts[$f['kind']] ?? 0) + 1;
            if ($counts[$f['kind']] <= self::PER_KIND) {
                $out[] = $f;
            }
        }
        usort($out, fn (array $a, array $b): int => $order[$a['kind']] <=> $order[$b['kind']]);

        return $out;
    }

    /**
     * Only the checks of the whole site before handing it over (2.7: the end of the migration parity report).
     *
     * @return list<array<string, mixed>>
     */
    public function handoverFindings(): array
    {
        $this->findings = [];
        $this->handover();

        return $this->findings;
    }

    /* ---------- sources ---------- */

    private function pages(): void
    {
        $db = $this->app->db();
        $home = (int) $this->app->settings()->get('home_page');
        $titles = [];
        foreach ($db->all('SELECT ids, titulek, seo_titulek, seo_link, popis, text, stavba, zobrazit, noindex, jazyk FROM {stranky} WHERE smazano IS NULL') as $p) {
            $where = t('Page “%s”', $p['titulek']);
            $target = ['page' => (int) $p['ids']];
            $url = (int) $p['ids'] === $home ? '' : (string) $p['seo_link'];
            $edit = 'admin.php?module=pages&action=edit&id=' . (int) $p['ids'];
            $build = $p['stavba'] !== null ? Build::fromJson((string) $p['stavba']) : null;
            $this->links($p['stavba'] ?? (string) $p['text'], $where, $build !== null ? 'admin.php?module=pages&action=builder&id=' . (int) $p['ids'] : $edit, $url, $target);
            if (!$p['zobrazit']) {
                continue; // a hidden page is not in search engines – only its links matter (it may be published later)
            }
            if ($build !== null) {
                foreach (Check::builds($build, true, 50) as $c) {
                    $this->add('build', $where, $c['zprava'], 'admin.php?module=pages&action=builder&id=' . (int) $p['ids'], $url, $target, $c['id']);
                }
            }
            if ($p['noindex']) {
                continue;
            }
            if (trim((string) $p['popis']) === '') {
                $this->add('description', $where, t('No description for search engines – they then make up their own from the page text.'), $edit, $url, $target);
            }
            $titles[$p['jazyk'] . '|' . mb_strtolower(trim($p['seo_titulek'] !== '' ? (string) $p['seo_titulek'] : (string) $p['titulek']))][] = [$where, $edit, $url, $target];
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.seo_titulek, p.popis, p.data, p.jazyk, k.seo_link AS kolekce, k.pole, k.nazev AS kolekce_nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL') as $p) {
            $where = t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']);
            $edit = 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'];
            $url = ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce'] . '/' . $p['seo_link'];
            $target = ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']];
            if (trim((string) $p['popis']) === '' && !$this->hasLongerText((string) $p['data'], (string) $p['pole'])) {
                $this->add('description', $where, t('No description for search engines and no longer text to take one from.'), $edit, $url, $target);
            }
            $titles[$p['jazyk'] . '|' . mb_strtolower(trim($p['seo_titulek'] !== '' ? (string) $p['seo_titulek'] : (string) $p['nazev']))][] = [$where, $edit, $url, $target];
        }
        foreach ($titles as $key => $same) {
            if (count($same) < 2) {
                continue;
            }
            foreach ($same as [$where, $edit, $url, $target]) {
                $this->add('title', $where, t('The title “%s” is used %d times – search engines cannot tell the pages apart.', explode('|', $key, 2)[1], count($same)), $edit, $url, $target);
            }
        }
    }

    private function parts(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT typ, jazyk, varianta, nazev, stavba FROM {casti} WHERE stavba IS NOT NULL') as $c) {
            $where = t('Site part “%s”', trim($c['typ'] . ' ' . $c['varianta'] . ' ' . $c['jazyk']));
            $edit = 'admin.php?module=parts&action=builder&type=' . rawurlencode((string) $c['typ']) . ($c['varianta'] !== '' ? '&variant=' . rawurlencode((string) $c['varianta']) : '') . '&language=' . rawurlencode((string) $c['jazyk']);
            $this->links((string) $c['stavba'], $where, $edit, null, ['part' => (string) $c['typ']]);
            foreach (Check::builds((array) Build::fromJson((string) $c['stavba']), false, 50) as $f) {
                $this->add('build', $where, $f['zprava'], $edit, null, ['part' => (string) $c['typ']], $f['id']);
            }
        }
        foreach ($db->all('SELECT idm, nazev, stavba FROM {komponenty} WHERE stavba IS NOT NULL') as $c) {
            $this->links((string) $c['stavba'], t('Component “%s”', $c['nazev']), 'admin.php?module=components&action=builder&id=' . (int) $c['idm'], null, ['component' => (int) $c['idm']]);
        }
        foreach ($db->all('SELECT idpp, nazev, stavba FROM {popupy} WHERE stavba IS NOT NULL AND aktivni = 1') as $c) {
            $this->links((string) $c['stavba'], t('Pop-up “%s”', $c['nazev']), 'admin.php?module=popups&action=builder&id=' . (int) $c['idpp'], null, ['popup' => (int) $c['idpp']]);
        }
    }

    private function collections(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT idk, nazev, seo_link, detail, stavba FROM {kolekce}') as $k) {
            if ($k['detail'] && $k['stavba'] !== null) {
                $this->links((string) $k['stavba'], t('Item template of “%s”', $k['nazev']), 'admin.php?module=collections&action=builder&id=' . (int) $k['idk'], null, ['collection' => (string) $k['seo_link']]);
            }
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.data, k.seo_link AS kolekce, k.nazev AS kolekce_nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.zobrazit = 1 AND p.smazano IS NULL') as $p) {
            $this->links((string) $p['data'], t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']), 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'], null,
                ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]);
        }
    }

    private function menus(): void
    {
        $db = $this->app->db();
        $pages = [];
        foreach ($db->all('SELECT ids, titulek, zobrazit, smazano FROM {stranky}') as $p) {
            $pages[(int) $p['ids']] = $p;
        }
        foreach ($db->all('SELECT umisteni, jazyk, polozky FROM {menu}') as $m) {
            $where = t('Menu “%s”', t(Menu::LOCATIONS[$m['umisteni']] ?? $m['umisteni'])) . ($m['jazyk'] !== '' ? ' (' . $m['jazyk'] . ')' : '');
            $walk = function (array $items) use (&$walk, $pages, $where): void {
                foreach ($items as $i) {
                    if (($i['typ'] ?? '') === 'stranka') {
                        $p = $pages[(int) ($i['ids'] ?? 0)] ?? null;
                        $message = match (true) {
                            $p === null => t('An item points at a page that no longer exists.'),
                            $p['smazano'] !== null => t('The item “%s” points at a page in the trash.', $p['titulek']),
                            !$p['zobrazit'] => t('The item “%s” points at a hidden page – visitors do not see it in the menu.', $p['titulek']),
                            default => null,
                        };
                        if ($message !== null) {
                            $this->add('menu', $where, $message, 'admin.php?module=menu', null, ['menu' => 'menu']);
                        }
                    } elseif (($i['typ'] ?? '') === 'odkaz') {
                        $this->checkUrl((string) ($i['url'] ?? ''), $where, 'admin.php?module=menu', null, ['menu' => 'menu']);
                    }
                    if (is_array($i['deti'] ?? null)) {
                        $walk($i['deti']);
                    }
                }
            };
            $walk(json_decode((string) $m['polozky'], true) ?: []);
        }
    }

    private function news(): void
    {
        if (!Extensions::isEnabled($this->app->settings(), 'novinky')) {
            return;
        }
        $db = $this->app->db();
        foreach ($db->all('SELECT idc, titulek, seo_link, jazyk, uvod, text FROM {novinky} WHERE visible = 1 AND smazano IS NULL ORDER BY datum DESC LIMIT 500') as $c) {
            $this->links($c['uvod'] . ' ' . $c['text'], t('News item “%s”', $c['titulek']), 'admin.php?module=news&action=edit&id=' . (int) $c['idc'],
                $this->relative($this->app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk'])), ['news' => (int) $c['idc']]);
        }
    }

    /** External links the background check found broken (Core\Links) – in news items, page builds and collection items. */
    private function brokenLinks(): void
    {
        $where = ['news' => 'News item “%s”', 'page' => 'Page “%s”', 'item' => 'Item “%s”'];
        foreach (Links::broken($this->app, 200) as $v) {
            $this->findings[] = ['kind' => 'link', 'where' => t($where[$v['kind']], $v['title']), 'message' => t('The link %s does not work (%s).', $v['url'], $v['status'] === 0 ? t('no response') : 'HTTP ' . $v['status']),
                'edit' => $v['edit'], 'url' => $v['page'] === '' ? '' : $this->app->request->origin() . $this->app->url($v['page']), 'target' => $v['target']] + ($v['element'] !== '' ? ['element' => $v['element']] : []);
        }
    }

    /** Orphans (2.14, Core\InternalLinks): published content nothing on the site links to. */
    private function orphans(): void
    {
        $where = ['news' => 'News item “%s”', 'page' => 'Page “%s”', 'item' => 'Item “%s”'];
        foreach (InternalLinks::orphans($this->app) as $o) {
            $this->add('orphan', t($where[$o['kind']], $o['title']), t('No published page, menu or text links here – visitors and search engines reach it only by its address. Add a link from a related page (Claude: suggest_internal_links).'),
                $o['edit'], $o['path'], $o['target']);
        }
    }

    /** Review by (2.10): every page, news item, collection item and pop-up whose review-by day has come, with where to edit it. */
    private function review(): void
    {
        $db = $this->app->db();
        $home = (int) $this->app->settings()->get('home_page');
        $message = fn (string $day): string => t('Asked for a review by %s.', format_date($day));
        foreach ($db->all('SELECT ids, titulek, seo_link, jazyk, review_by FROM {stranky} WHERE review_by IS NOT NULL AND review_by <= CURDATE() AND smazano IS NULL ORDER BY review_by') as $p) {
            $this->add('review', t('Page “%s”', $p['titulek']), $message((string) $p['review_by']), 'admin.php?module=pages&action=edit&id=' . (int) $p['ids'],
                (int) $p['ids'] === $home ? '' : ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['seo_link'], ['page' => (int) $p['ids']]);
        }
        if (Extensions::isEnabled($this->app->settings(), 'novinky')) {
            foreach ($db->all('SELECT idc, titulek, seo_link, jazyk, review_by FROM {novinky} WHERE review_by IS NOT NULL AND review_by <= CURDATE() AND smazano IS NULL ORDER BY review_by') as $c) {
                $this->add('review', t('News item “%s”', $c['titulek']), $message((string) $c['review_by']), 'admin.php?module=news&action=edit&id=' . (int) $c['idc'],
                    $this->relative($this->app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk'])), ['news' => (int) $c['idc']]);
            }
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, p.review_by, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.detail FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.review_by IS NOT NULL AND p.review_by <= CURDATE() AND p.smazano IS NULL ORDER BY p.review_by') as $p) {
            $this->add('review', t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']), $message((string) $p['review_by']), 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'],
                $p['detail'] ? ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce'] . '/' . $p['seo_link'] : null, ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]);
        }
        foreach ($db->all('SELECT idpp, nazev, review_by FROM {popupy} WHERE review_by IS NOT NULL AND review_by <= CURDATE() ORDER BY review_by') as $c) {
            $this->add('review', t('Pop-up “%s”', $c['nazev']), $message((string) $c['review_by']), 'admin.php?module=popups&action=edit&id=' . (int) $c['idpp'], null, ['popup' => (int) $c['idpp']]);
        }
    }

    /**
     * Job openings (2.11): a visible job without a closing date ("true until") never hides itself, and its JobPosting has no
     * validThrough – Google then cannot tell it from an expired one (Builder\CollectionSchema).
     */
    private function jobs(): void
    {
        foreach ($this->app->db()->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.detail FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk'
            . ' WHERE k.preset = ? AND p.zobrazit = 1 AND p.smazano IS NULL AND p.valid_until IS NULL ORDER BY p.nazev', [Jobs::PRESET]) as $p) {
            $this->add('job', t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']), t('Job opening without a closing date – set “true until” to the application deadline: the job then hides itself and search engines get validThrough, which they need to tell an open job from an expired one.'),
                'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'],
                $p['detail'] ? ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce'] . '/' . $p['seo_link'] : null, ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]);
        }
    }

    /** Document library (2.11, Core\Documents): visible documents whose true-until day comes within 30 days – a new edition is due, or the date needs moving. */
    private function documents(): void
    {
        foreach ($this->app->db()->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, p.valid_until, k.seo_link AS kolekce, k.nazev AS kolekce_nazev, k.detail FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk'
            . ' WHERE k.preset = ? AND p.zobrazit = 1 AND p.smazano IS NULL AND p.valid_until IS NOT NULL AND p.valid_until BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY ORDER BY p.valid_until', [Documents::PRESET, Documents::EXPIRY_WARNING_DAYS]) as $p) {
            $this->add('document', t('Item “%s” (%s)', $p['nazev'], $p['kolekce_nazev']), t('The document is true until %s – upload the new edition or move the date; the day after, it hides itself and its download address stops working.', format_date((string) $p['valid_until'])),
                'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'],
                $p['detail'] ? ($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce'] . '/' . $p['seo_link'] : null, ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]);
        }
    }

    /** Link texts that do not say where the link leads (screen reader users often list the links of a page on their own). */
    private const string VAGUE_LINK = '/^(click here|here|read more|more|learn more|details|link|this|zde|sem|tady|klikněte sem|klikněte zde|více|číst dál|číst více|'
        . 'hier|mehr|weiterlesen|mehr erfahren|ici|cliquez ici|plus|en savoir plus|aquí|haz clic aquí|más|leer más|qui|clicca qui|di più|leggi di più|'
        . 'tutaj|kliknij tutaj|więcej|czytaj więcej|tu|kliknite sem|viac|čítať ďalej)[.!…]?$/iu';

    /** Page addresses of an accessibility statement in the site languages. */
    private const string STATEMENT = '/(accessibility|pristupnost|prístupnosť|pristupnost|barrierefreiheit|accessibilite|accesibilidad|accessibilita|dostepnosc)/i';

    private function accessibility(): void
    {
        $db = $this->app->db();
        $s = $this->app->settings();
        // the design system: text and buttons in light and (when the site has it) dark mode
        $ds = \Kaleta\Builder\DesignSystem::load($s);
        // the dark mode palette with its derived (or chosen) primary and secondary colours (3.6, DesignSystem::darkColors)
        $looks = ['' => false] + (in_array($s->get('dark_mode'), ['auto', 'tmavy'], true) ? [t(' (dark mode)') => true] : []);
        foreach ($looks as $suffix => $dark) {
            foreach (\Kaleta\Builder\DesignSystem::contrasts($ds, $dark) as $c) {
                if (!$c['ok']) {
                    $this->add('accessibility', t('Site appearance') . $suffix, $c['min'] < 4.5
                        ? t('%s has a contrast of %s : 1 – a focus ring needs at least 3 : 1.', t($c['popis']), number_format($c['pomer'], 1))
                        : t('%s has a contrast of %s : 1 – text needs at least 4.5 : 1.', t($c['popis']), number_format($c['pomer'], 1)),
                        'admin.php?module=appearance', null, ['look' => 'design_system']);
                }
            }
        }
        $home = (int) $s->get('home_page');
        $statement = false;
        foreach ($db->all('SELECT ids, titulek, seo_link, text, stavba FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1') as $p) {
            $statement = $statement || preg_match(self::STATEMENT, (string) $p['seo_link']) === 1;
            $build = $p['stavba'] !== null ? Build::fromJson((string) $p['stavba']) : null;
            $edit = $build !== null ? 'admin.php?module=pages&action=builder&id=' . (int) $p['ids'] : 'admin.php?module=pages&action=edit&id=' . (int) $p['ids'];
            $this->accessibleContent($build, (string) $p['text'], t('Page “%s”', $p['titulek']), $edit, (int) $p['ids'] === $home ? '' : (string) $p['seo_link'], ['page' => (int) $p['ids']]);
        }
        if (Extensions::isEnabled($s, 'novinky')) {
            foreach ($db->all('SELECT idc, titulek, seo_link, jazyk, uvod, text FROM {novinky} WHERE visible = 1 AND smazano IS NULL ORDER BY datum DESC LIMIT 500') as $c) {
                $this->accessibleContent(null, $c['uvod'] . ' ' . $c['text'], t('News item “%s”', $c['titulek']), 'admin.php?module=news&action=edit&id=' . (int) $c['idc'],
                    $this->relative($this->app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk'])), ['news' => (int) $c['idc']]);
            }
        }
        if (!$statement) {
            $this->add('accessibility', t('The whole site'), t('No accessibility statement – the European Accessibility Act expects a service to say how accessible it is and whom to contact about barriers. Add a page such as /accessibility.'),
                'admin.php?module=pages', null, ['site' => 'accessibility_statement']);
        }
    }

    /**
     * Accessibility of one page or news item: the HTML of its text and of the text elements of its build, and the button
     * texts of the build.
     *
     * @param array<string, mixed>|null $build
     * @param array<string, int|string> $target
     */
    private function accessibleContent(?array $build, string $text, string $where, string $edit, ?string $url, array $target): void
    {
        $fragments = [[$text, null]];
        $walk = function (array $nodes) use (&$walk, &$fragments, $where, $edit, $url, $target): void {
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $content = is_array($n['obsah'] ?? null) ? $n['obsah'] : [];
                foreach (['html', 'text'] as $key) {
                    if (is_string($content[$key] ?? null) && str_contains($content[$key], '<')) {
                        $fragments[] = [$content[$key], (string) ($n['id'] ?? '')];
                    }
                }
                if (($n['typ'] ?? '') === 'tlacitko' && is_string($content['text'] ?? null) && preg_match(self::VAGUE_LINK, trim(strip_tags($content['text'])))) {
                    $this->add('accessibility', $where, t('The button “%s” does not say what it does – screen readers read buttons and links on their own.', trim(strip_tags($content['text']))), $edit, $url, $target, (string) ($n['id'] ?? ''));
                }
                if (is_array($n['deti'] ?? null)) {
                    $walk($n['deti']);
                }
            }
        };
        if ($build !== null) {
            $walk($build['deti'] ?? []);
        }
        foreach ($fragments as [$html, $element]) {
            if ($html === '' || !str_contains($html, '<')) {
                continue;
            }
            $found = [];
            preg_match_all('#<img\b(?![^>]*\balt=)[^>]*>#i', $html, $m);
            if ($m[0] !== []) {
                $found[] = t('An image in the text has no description for blind visitors (alt).');
            }
            preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $html, $links, PREG_SET_ORDER);
            foreach ($links as [, $attributes, $inner]) {
                $label = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));
                if ($label === '' && !preg_match('/aria-label=|title=/i', $attributes) && !preg_match('#<img\b[^>]*\balt="[^"]+#i', $inner)) {
                    $found[] = t('A link has no text – a screen reader has nothing to read.');
                } elseif ($label !== '' && preg_match(self::VAGUE_LINK, $label)) {
                    $found[] = t('The link “%s” does not say where it leads – screen readers read links on their own.', $label);
                }
            }
            if (preg_match('#<table\b#i', $html) && !preg_match('#<th\b#i', $html)) {
                $found[] = t('A table has no header cells (th) – screen readers cannot tell what the columns mean.');
            }
            if (preg_match('#<iframe\b(?![^>]*\btitle=)#i', $html)) {
                $found[] = t('An embedded frame (iframe) has no title.');
            }
            foreach (array_unique($found) as $message) {
                $this->add('accessibility', $where, $message, $edit, $url, $target, $element);
            }
        }
    }

    /** Before handing the site over to a client (2.4) – each item says what to set and where. */
    /** The checks of the applied industry blueprints (2.11, Core\Blueprint): facts, items, company details, pages, fresh prices. */
    private function blueprint(): void
    {
        foreach (Blueprint::findings($this->app) as $i => [$name, $message, $edit]) {
            $this->add('blueprint', $name, $message, $edit, null, ['blueprint' => $i]);
        }
    }

    private function handover(): void
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $site = t('The whole site');
        $check = function (bool $ok, string $message, string $edit, string $key) use ($site): void {
            if (!$ok) {
                $this->add('handover', $site, $message, $edit, null, ['handover' => $key]);
            }
        };
        $check($s->get('site_email') !== '', t('No site e-mail: enquiries and password resets have nowhere to go.'), 'admin.php?module=settings&tab=general', 'site_email');
        $check($s->get('mail_mode') === 'smtp', t('E-mail goes out through the host’s mail() – set an SMTP server so enquiries and newsletters do not end up in spam.'), 'admin.php?module=settings&tab=mail', 'smtp');
        $check($s->get('remote_backup') !== '' && $s->get('remote_backup') !== 'vypnuto', t('Backups stay on the same server – add an off-site copy (FTPS or S3) in case the hosting is lost.'), 'admin.php?module=settings&tab=backups', 'remote_backup');
        $check(trim($s->get('company_name')) !== '' && trim($s->get('company_street')) !== '', t('Company details are missing – the footer, the imprint and search engines use them.'), 'admin.php?module=business', 'company');
        $check($s->bool('indexing'), t('Search engines are blocked – switch indexing on when the site goes live.'), 'admin.php?module=settings&tab=seo', 'indexing');
        $check($s->get('favicon') !== '' || is_file(KALETA_ROOT . '/media/ikona-32.png'), t('No site icon (favicon) – browsers and phones show a blank one.'), 'admin.php?module=appearance', 'favicon');
        $tracking = trim($s->get('ga4_id') . $s->get('matomo_url') . $s->get('marketing_code')) !== '';
        $check(!$tracking || $s->get('cookies_mode') !== 'zadna', t('Analytics or marketing codes run without a cookie bar – visitors in the EU must consent first.'), 'admin.php?module=settings&tab=cookies', 'cookies');
        $check($s->get('security_contact') !== '', t('No security contact – add who takes reports of security problems (published as security.txt).'), 'admin.php?module=settings&tab=seo', 'security_contact');
        // accounts and access (2.8): the same findings as System status, each with the user to fix
        $hygiene = SecurityHygiene::findings($this->app);
        foreach ($hygiene['two_step'] as $u) {
            $this->add('handover', $site, t('The administrator %s signs in without two-step sign-in or a passkey.', SecurityHygiene::displayName($u)),
                'admin.php?module=users&action=edit&id=' . (int) $u['idu'], null, ['handover' => 'two_step', 'user' => (int) $u['idu']]);
        }
        foreach ($hygiene['unused_accounts'] as $u) {
            $this->add('handover', $site, t('The account %s has not been used for %d days (last activity %s) – block it, or let the automatic suspension do it.', SecurityHygiene::displayName($u), SecurityHygiene::daysAgo((string) $u['last']), format_date((string) $u['last'])),
                'admin.php?module=users&action=edit&id=' . (int) $u['idu'], null, ['handover' => 'unused_account', 'user' => (int) $u['idu']]);
        }
        foreach ($hygiene['unused_connections'] as $c) {
            $this->add('handover', $site, t('The Claude connection “%s” of %s has not been used for %d days – revoke it, or let the automatic suspension do it.', (string) $c['name'], (string) $c['user'], SecurityHygiene::daysAgo((string) $c['last'])),
                'admin.php?module=users&action=edit&id=' . (int) $c['idu'] . '#napojeni', null, ['handover' => 'unused_connection', 'user' => (int) $c['idu'], 'connection' => (string) $c['name']]);
        }
        $check(SecurityHygiene::autoSuspend($s) !== [], t('Unused accounts and Claude connections are only reported – switch on the automatic suspension (Settings → General) so that leftover access closes itself.'), 'admin.php?module=settings&tab=general', 'auto_suspend');
        $check((int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE admin < 2 AND blokovat = 0') > 0, t('The client has no account of their own yet – create one with the Client role (Users → Roles).'), 'admin.php?module=users', 'client_account');
        $check($s->get('agency_name') !== '' && ($s->get('agency_email') !== '' || $s->get('agency_phone') !== ''), t('Your contact is not set – the client will not see whom to ask (Settings → General → Built and looked after by).'), 'admin.php?module=settings&tab=general', 'agency');
        if (Extensions::isEnabled($s, 'newsletter')) {
            $check($s->int('tasks_last_run') > 0, t('Background tasks have never run – newsletters are sent only while they do. Add the cron line from System status.'), 'admin.php?module=status', 'cron');
        }
        // 2.8: the cached domain and mail watch – a missing SPF or DMARC record, a certificate or a domain about to expire
        foreach (DomainWatch::handoverFindings(DomainWatch::cached($s)) as $finding) {
            $this->add('handover', $site, $finding['message'], 'admin.php?module=status', null, ['handover' => $finding['key']]);
        }
    }

    private function notFound(): void
    {
        foreach (NotFound::pending($this->app, 30, 25) as $n) {
            $path = trim($n['cesta'], '/');
            $this->add('not_found', '/' . $path, t('%d visits in the last 30 days ended with “page not found” – add a redirect to the right page.', (int) $n['pocet']),
                'admin.php?module=redirects&from=' . rawurlencode('/' . $path) . '#upravit', null, ['redirect_from' => '/' . $path]);
        }
    }

    /**
     * Business facts (2.10): a {{fact.key}} token of a fact that does not exist and a computed token that cannot be
     * computed show nothing to visitors; a proof number typed in as digits (the counter) goes stale – it should be a fact.
     */
    private function facts(): void
    {
        $known = Facts::all($this->app);
        foreach (Facts::texts($this->app->db()) as $t) {
            preg_match_all(Facts::TOKEN_PATTERN, Facts::withoutCode($t['text']), $m);
            foreach (array_unique(array_diff($m[1], array_keys($known))) as $key) {
                $this->add('fact', $t['where'], t('The fact {{fact.%s}} does not exist – visitors see nothing in its place. Create it in Facts, or fix the key.', $key), $t['edit'], null, $t['target']);
            }
            preg_match_all(Facts::COMPUTED_PATTERN, Facts::withoutCode($t['text']), $m, PREG_SET_ORDER);
            foreach (array_unique(array_map(fn (array $c): string => $c[1] . ':' . $c[2], $m)) as $token) {
                [$kind, $argument] = explode(':', $token, 2);
                if (Facts::computed($this->app, $kind, $argument) === null) {
                    $this->add('fact', $t['where'], t('The token {{%s}} cannot be computed – visitors see nothing in its place. years_since takes a year, a date (YYYY-MM-DD) or a fact with one; count takes the address of a collection, or news.', $token), $t['edit'], null, $t['target']);
                }
            }
            foreach ($t['build'] !== null ? Facts::typedNumbers($t['build']) : [] as $n) {
                $this->add('fact', $t['where'], t('The number %s is typed in – make it a fact ({{fact.key}}) or a count, so it stays true.', $n['number']), $t['edit'], null, $t['target'], $n['id']);
            }
        }
    }

    /** Real-user speed (2.8): pages that got slower – p75 LCP of the last 30 days against the 30 days before, with enough measurements in both. */
    private function speed(): void
    {
        if (!Extensions::isEnabled($this->app->settings(), 'statistika')) {
            return;
        }
        foreach (WebVitals::regressions($this->app->db()) as $r) {
            $this->add('speed', $r['path'], t('Loading got slower: visitors wait %s s for the main content (p75 LCP) in the last 30 days, %s s in the 30 days before (%d measurements) – check the images, fonts and embeds above the fold.',
                format_count($r['current'] / 1000, 1), format_count($r['previous'] / 1000, 1), $r['samples']), 'admin.php?module=stats', $this->relative($r['path']), ['path' => $r['path']]);
        }
    }

    /* ---------- links ---------- */

    /** Every link in a build (JSON) or HTML; internal ones must lead somewhere. */
    private function links(string $content, string $where, string $edit, ?string $url, array $target): void
    {
        preg_match_all('#"(?:odkaz|url|href)":"((?:[^"\\\\]|\\\\.)*)"|href=\\\\?"([^"\\\\]*)\\\\?"#', $content, $m, PREG_SET_ORDER);
        $seen = [];
        foreach ($m as $match) {
            $link = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($link === '' || isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $this->checkUrl($link, $where, $edit, $url, $target);
        }
    }

    private function checkUrl(string $link, string $where, string $edit, ?string $url, array $target): void
    {
        if ($link === '' || str_contains($link, '{{') || preg_match('#^(\#|mailto:|tel:|javascript:)#i', $link)) {
            return;
        }
        $origin = $this->app->request->origin() . $this->app->request->basePath();
        if (preg_match('#^https?://#i', $link)) {
            if (!str_starts_with(strtolower($link), strtolower($origin) . '/') && strtolower(rtrim($link, '/')) !== strtolower($origin)) {
                return; // an external link – the background link check tests those
            }
            $link = substr($link, strlen($origin));
        }
        if (!str_starts_with($link, '/')) {
            return;
        }
        $path = (string) parse_url($link, PHP_URL_PATH);
        if (!$this->resolves($path)) {
            $this->add('link', $where, t('The link %s leads to a page that does not exist.', $path), $edit, $url, $target);
        }
    }

    /** Does an internal path lead to something on the site (a page, news, an item, a file or a system address)? */
    public function resolves(string $path): bool
    {
        $key = '/' . trim(rawurldecode($path), '/');

        return $this->resolved[$key] ??= self::pathResolves($this->app->db(), $this->app->settings(), $path);
    }

    /**
     * The same answer without an Audit: also used by the WordPress import (3.6, N36-1), which must not take over an address
     * the site already routes (a page in a language version, the news list, a news item, a category or tag).
     */
    public static function pathResolves(Db $db, Settings $settings, string $path): bool
    {
        $path = '/' . trim(rawurldecode($path), '/');
        $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        $language = Language::defaults($settings);
        if ($segments !== [] && in_array($segments[0], Language::additional($settings), true)) {
            $language = array_shift($segments);
        }
        $rest = '/' . implode('/', $segments);
        [$internal] = Routes::internalPath($rest, $language, $db);
        $s = $internal === '/' ? [] : explode('/', ltrim($internal, '/'));

        return match (true) {
            $s === [] => true,
            is_file(KALETA_ROOT . '/' . ltrim($path, '/')) && preg_match('#^/(media|image)/#', $path) === 1 => true,
            in_array($s[0], ['hledani', 'rss.xml', 'feed.json', 'sitemap.xml', 'robots.txt', 'llms.txt', 'admin.php', 'mcp'], true) => true,
            $s[0] === 'novinky' => self::newsPathExists($db, array_slice($s, 1)),
            $db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND zobrazit = 1 AND smazano IS NULL', [implode('/', $s)]) !== null => true,
            count($s) === 2 && $db->value('SELECT 1 FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND k.detail = 1 AND p.seo_link = ? AND p.zobrazit = 1 AND p.smazano IS NULL', [$s[0], $s[1]]) !== null => true,
            $db->value('SELECT 1 FROM {presmerovani} WHERE z_adresy = ?', [trim($path, '/')]) !== null => true,
            default => false,
        };
    }

    /** @param list<string> $s the path after /novinky */
    private static function newsPathExists(Db $db, array $s): bool
    {
        return match (true) {
            $s === [] => true,
            count($s) === 1 => $db->value('SELECT 1 FROM {novinky} WHERE seo_link = ? AND visible = 1 AND smazano IS NULL', [$s[0]]) !== null,
            count($s) === 2 && $s[0] === 'kategorie' => $db->value('SELECT 1 FROM {kategorie} WHERE seo_link = ?', [$s[1]]) !== null,
            count($s) === 2 && $s[0] === 'stitek' => $db->value('SELECT 1 FROM {stitky} WHERE seo_link = ?', [$s[1]]) !== null,
            default => false,
        };
    }

    /* ---------- helpers ---------- */

    private function hasLongerText(string $data, string $fields): bool
    {
        $values = json_decode($data, true) ?: [];
        foreach (json_decode($fields, true) ?: [] as $f) {
            if (in_array($f['typ'] ?? '', ['radky', 'html'], true) && trim(strip_tags((string) ($values[$f['klic']] ?? ''))) !== '') {
                return true;
            }
        }

        return false;
    }

    /** A public URL of the app (with the base path) as a path inside the site. */
    private function relative(string $url): string
    {
        return ltrim(substr($url, strlen($this->app->request->basePath())), '/');
    }

    /**
     * @param ?string $url path of the page on the site ('' = the home page, null = no page of its own)
     * @param array<string, int|string> $target what to fix, for Claude
     */
    private function add(string $kind, string $where, string $message, string $edit, ?string $url, array $target, ?string $element = null): void
    {
        $this->findings[] = ['kind' => $kind, 'where' => $where, 'message' => $message, 'edit' => $this->app->url($edit),
            'url' => $url === null ? '' : $this->app->request->origin() . $this->app->url($url), 'target' => $target] + ($element !== null ? ['element' => $element] : []);
    }
}
