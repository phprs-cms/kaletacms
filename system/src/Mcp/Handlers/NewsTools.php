<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Categories;
use Kaleta\Core\SocialDrafts;
use Kaleta\Mcp\Schema;

/**
 * MCP tools: news (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NewsTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function newsToolsDefinitions(): array
    {
        $newsItem = [
            'titulek' => Schema::text('Titulek novinky'), 'uvod' => Schema::text('Perex jako HTML (1-2 odstavce)'), 'text' => Schema::text('Text jako HTML'),
            'kategorie' => Schema::text('Název nebo adresa (seo_link) kategorie'), 'stitky' => Schema::text('Štítky oddělené čárkou'),
            'seo_titulek' => Schema::text('Titulek pro vyhledávače (nepovinné)'), 'seo_popis' => Schema::text('Popis pro vyhledávače, do 160 znaků'), 'canonical_url' => Schema::text('Canonical URL: https://… or /path on the site; empty = this page'),
            'obrazek' => Schema::text('Adresa hlavního obrázku (z nástroje seznam_medii)'), 'obrazek_popis' => Schema::text('Popisek hlavního obrázku (prázdné = z knihovny médií)'),
            'faq' => Schema::text('Otázky a odpovědi: otázka na řádku, odpověď pod ní, mezi dvojicemi prázdný řádek'),
            'datum' => Schema::text('Datum vydání RRRR-MM-DD HH:MM; budoucí = naplánování'),
            'vydat' => ['type' => 'boolean', 'description' => 'true = vydat (jen s právem vydávat a na výslovný pokyn uživatele), jinak koncept'],
            'valid_until' => Schema::text('True until YYYY-MM-DD (2.10): after this day it hides itself; empty string = always (optional)'), 'review_by' => Schema::text('Review by YYYY-MM-DD (2.10): on this day the site audit and the event content.review ask the user to check it; empty string = none (optional)'),
        ];
        return [
            'seznam_novinek' => ['Seznam novinek (nejnovější první).', Schema::object(['stav' => Schema::text('vse | vydane | plan | koncepty'), 'kategorie' => Schema::text('název nebo adresa kategorie'), 'hledat' => Schema::text('slova v titulku, adrese, perexu, textu nebo klíčových slovech'), 'limit' => Schema::number('1-50, výchozí 20')])],
            'nacti_novinku' => ['Celá novinka včetně textu a štítků.', Schema::object(['id' => Schema::number('ID novinky (idc)')], ['id'])],
            'vytvor_novinku' => ['Založí novinku. Bez "vydat": true vznikne koncept.', Schema::object($newsItem, ['titulek', 'kategorie'])],
            'uprav_novinku' => ['Změní zadaná pole novinky; ostatní ponechá. Předchozí verze se uloží do historie.', Schema::object(['id' => Schema::number('ID novinky')] + $newsItem, ['id'])],
            'seznam_kategorii' => ['Kategorie novinek s počty.', Schema::object([])],
            'vytvor_kategorii' => ['Založí kategorii novinek (editor a správce).', Schema::object(['nazev' => Schema::text('Název'), 'popis' => Schema::text('Popis (HTML)')], ['nazev'])],
            'trash_news' => ['Moves a news item to the trash (only when the user explicitly asks). It disappears from the site and can be restored for 30 days. A published one needs the publishing permission.', Schema::object(['id' => Schema::number('news item ID')], ['id'])],
            'update_category' => ['Changes a news category: name, description, slug (the old address redirects) or order (editors and administrators).',
                Schema::object(['id' => Schema::number('category ID from list_categories'), 'name' => Schema::text('new name'), 'description' => Schema::text('description as HTML'), 'slug' => Schema::text('new slug'), 'order' => Schema::number('order, lower = first')], ['id'])],
            'delete_category' => ['Deletes an empty news category (editors and administrators; only when the user explicitly asks). A category with news items – even in the trash – cannot be deleted.', Schema::object(['id' => Schema::number('category ID')], ['id'])],
            'get_social_drafts' => ['Social post drafts of a published news item (read-only, 2.13): for each network chosen in Settings → General (default Facebook and LinkedIn) the text, the tracked link (utm_source = the network, utm_campaign = the news slug – the statistics count it), the image and whether the person has posted it. They are prepared when the news item is published; a draft or a scheduled news item has none. The site never posts anywhere – the user copies them; polish the text with update_social_draft.',
                Schema::object(['id' => Schema::number('news ID')], ['id'])],
            'update_social_draft' => ['Changes the text of one social post draft (2.13). Keep the tracked link in the text – except on Instagram, where the link goes to the bio – and the hashtags if they fit; on X at most 280 characters with a link counted as 23. Never posts anywhere.',
                Schema::object(['id' => Schema::number('draft id from get_social_drafts'), 'text' => Schema::text('the new text of the post')], ['id', 'text'])],
        ];
    }

    /** list_news (seznam_novinek) */
    private function toolListNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        // the trash is neither listed nor edited via MCP; an author sees their own news items, and without the News section
        // only what visitors see (3.3.2, N12) – Auth::newsListScope, shared with translation_status
        $where = ['c.smazano IS NULL' . $auth->newsListScope('c.')];
        $p = [];
        $statuses = ['vydane' => 'c.visible = 1 AND c.datum <= ?', 'plan' => 'c.visible = 1 AND c.datum > ?', 'koncepty' => 'c.visible = 0'];
        if (isset($statuses[$a['stav'] ?? ''])) {
            $where[] = $statuses[$a['stav']];
            if ($a['stav'] !== 'koncepty') {
                $p[] = \Kaleta\Core\Clock::now(); // the site's clock (3.12, data model §6)
            }
        }
        if (!empty($a['kategorie'])) {
            $where[] = 'c.tema = ?';
            $p[] = $this->category((string) $a['kategorie']);
        }
        // a number (a year) is searched as before 3.52, never ignored
        $search = is_scalar($a['hledat'] ?? null) ? trim((string) $a['hledat']) : '';
        if ($search !== '') {
            // 3.52 (A7): the same words and columns as the admin list of news
            [$sql, $words] = \Kaleta\Core\Search::newsWords($search);
            $where[] = $sql;
            array_push($p, ...$words);
        }

        return $db->all(
            'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS kategorie, c.datum, c.visible AS vydana
             FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
            [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
        );
    }

    /** get_news (nacti_novinku) */
    private function toolGetNews(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $c = $this->newsItem((int) ($a['id'] ?? 0));
        if (!$this->app->auth()->hasModule('news') && (!$c['visible'] || strtotime((string) $c['datum']) > time())) {
            throw new \InvalidArgumentException('Novinka neexistuje nebo k ní uživatel nemá přístup.'); // 3.3.2 (N12): a draft only with the News section
        }
        $generated = $c['obrazek'] === '' && $this->app->settings()->get('share_image') === ''
            ? \Kaleta\Front\ShareImage::url($this->app, \Kaleta\Core\Facts::fillText($c['seo_titulek'] !== '' ? $c['seo_titulek'] : $c['titulek'], $this->app)) : null; // drawn by the site (2.12)

        return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'datum', 'visible', 'faq', 'seo_titulek', 'seo_popis', 'canonical_url']))
            + ($generated !== null ? ['share_image_generated' => $generated] : [])
            + self::validityOutput($c) + ['kategorie' => $db->value('SELECT nazev FROM {kategorie} WHERE idt = ?', [$c['tema']]),
                'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                'adresa' => $this->app->request->origin() . $this->app->url('novinky/' . $c['seo_link'])];
    }

    /** create_news and update_news (vytvor_novinku, uprav_novinku) */
    private function toolCreateNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('news')) {
            throw new \DomainException('K novinkám nemáš přístup (role uživatele).');
        }

        return $this->saveNewsItem($name === 'uprav_novinku' ? $this->newsItem((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_news: the same as create_news */
    private function toolUpdateNews(string $name, array $a): mixed
    {
        return $this->toolCreateNews($name, $a);
    }

    /** trash_news */
    private function toolTrashNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('news'), 'News items can be deleted only by users with the News section.');
        $item = $db->one('SELECT idc, visible, autor FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]) ?? throw new \InvalidArgumentException('The news item does not exist. Use list_news.');
        $need($auth->canPublish() || (!$item['visible'] && (int) $item['autor'] === $auth->id()), 'A published news item or someone else’s can be deleted only with the publishing permission.');
        $db->run('UPDATE {novinky} SET smazano = ?, visible = 0 WHERE idc = ?', [\Kaleta\Core\Clock::now(), $id]);
        \Kaleta\Core\PageCache::clear();

        return ['trashed' => $id, 'restore' => 'restore_from_trash with type news within 30 days'];
    }

    /** get_social_drafts (2.13, Core\SocialDrafts) */
    private function toolGetSocialDrafts(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('news')) {
            throw new \DomainException('Social post drafts belong to news – the user has no access to the News section.');
        }
        $c = $this->newsItem((int) ($a['id'] ?? 0));
        $published = $c['visible'] && strtotime((string) $c['datum']) <= time();
        if ($published) {
            SocialDrafts::prepare($this->app, (int) $c['idc']); // a news item published through Claude gets its drafts here at the latest
        }

        return ['news_id' => (int) $c['idc'], 'published' => $published,
            'drafts' => array_map(fn (array $d): array => array_diff_key($d, ['idc' => 1]), SocialDrafts::forNews($this->app->db(), (int) $c['idc'])),
            'networks' => SocialDrafts::networks($this->app->settings()),
            'note' => $published ? 'The user copies and posts them (Administration → News → the news item → Social posts); the site never posts anywhere. update_social_draft changes a text.'
                : 'Drafts are prepared when the news item is published (update_news with publish: true, or when its scheduled time comes).'];
    }

    /** update_social_draft (2.13) */
    private function toolUpdateSocialDraft(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('news')) {
            throw new \DomainException('Social post drafts belong to news – the user has no access to the News section.');
        }
        $db = $this->app->db();
        $draft = SocialDrafts::find($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The draft does not exist. Use get_social_drafts.');
        $this->newsItem($draft['idc']); // the user's scope (an author only their own news)
        $error = SocialDrafts::update($db, $draft['id'], (string) ($a['text'] ?? ''));
        if ($error !== null) {
            throw new \DomainException($error);
        }
        \Kaleta\Core\ChangeLog::write($this->app, 'news', 'social draft', $draft['network'] . ' #' . $draft['idc']);

        return ['draft' => array_diff_key((array) SocialDrafts::find($db, $draft['id']), ['idc' => 1]), 'x_length' => $draft['network'] === 'x' ? SocialDrafts::xLength((string) ($a['text'] ?? '')) : null];
    }

    /** list_categories (seznam_kategorii) */
    private function toolListCategories(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'jazyk' => $r['jazyk'], 'novinek' => (int) $r['pocet_clanku']], Categories::listAll($db));
    }

    /** create_category (vytvor_kategorii) */
    private function toolCreateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        if (!$auth->canPublish() || !$auth->hasModule('categories')) {
            throw new \DomainException('Kategorie smí zakládat editor nebo správce.');
        }
        $displayName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
        if ($displayName === '') {
            throw new \InvalidArgumentException('Chybí název kategorie.');
        }
        $seo = $this->availableSlug('kategorie', slugify($displayName, 110));

        $description = \Kaleta\Core\Html::safeOrFail((string) ($a['popis'] ?? ''), 'description');

        $id = $db->insert('kategorie', ['nazev' => $displayName, 'seo_link' => $seo, 'popis' => $description]);

        return ['id' => $id, 'adresa' => \Kaleta\Core\Slug::fromId($db, 'kategorie', $id)]; // nothing to transliterate: category-<id>
    }

    /** update_category */
    private function toolUpdateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be changed by editors and administrators.');
        $c = $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]) ?? throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        $changes = [];
        if (trim((string) ($a['name'] ?? '')) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['name']), 0, 255);
        }
        if (isset($a['description'])) {
            $changes['popis'] = \Kaleta\Core\Html::forUserOrFail((string) $a['description'], $auth, 'description');
        }
        if (isset($a['order'])) {
            $changes['hodnost'] = max(0, min(65535, (int) $a['order']));
        }
        if (trim((string) ($a['slug'] ?? '')) !== '') {
            $changes['seo_link'] = \Kaleta\Core\Slug::makeUnique(slugify((string) $a['slug'], 110), fn (string $x): bool => \Kaleta\Core\Slug::taken($db, 'kategorie', $x, (string) $c['jazyk'], $id), 120);
        }
        if ($changes !== []) {
            $db->update('kategorie', $changes, ['idt' => $id]);
            if (isset($changes['seo_link']) && $changes['seo_link'] !== $c['seo_link']) {
                \Kaleta\Admin\Modules\Redirects::add($db, \Kaleta\Core\Slug::redirectPath($db, 'novinky/kategorie/' . $c['seo_link'], (string) $c['jazyk']),
                    \Kaleta\Core\Slug::redirectPath($db, 'novinky/kategorie/' . $changes['seo_link'], (string) $c['jazyk']));
            }
            \Kaleta\Core\PageCache::clear();
        }
        $c = (array) $db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$id]);

        return ['id' => $id, 'name' => $c['nazev'], 'slug' => $c['seo_link'], 'order' => (int) $c['hodnost']];
    }

    /** delete_category */
    private function toolDeleteCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be deleted by editors and administrators.');
        if ($db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$id]) === null) {
            throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE tema = ?', [$id]) > 0) {
            throw new \DomainException('The category still has news items (including those in the trash) – move them to another category first.');
        }
        $db->delete('kategorie', ['idt' => $id]);

        return ['deleted' => $id];
    }
}
