<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\DesignSystem;

/**
 * Look drafts (1.7): the design system, the shared classes and the menus change the whole site at once, so a change goes
 * to one draft of the look first – from Site appearance, the builder, the menu editor and Claude alike. The draft is
 * previewed on the whole site (a signed link, or an administrator in the builder), then published in one step or thrown
 * away. Publishing keeps the previous look as a version (the last 20), which comes back into the draft with one click.
 *
 * The draft is one JSON in the settings (look_draft): {design_system: {...}, classes: {name: {styl, css} | null = delete},
 * menus: {"location|language": items | null = automatic}, identity: {logo?: path, favicon?: path}}. The logo and the site
 * icon join the draft in 3.12 (UX310-11) – before, they went live at once. Components and site parts have their own drafts in their builds.
 */
final class Look
{
    public const int VERSIONS = 20;

    /** Design system settings in words for the summary (colours come from DesignSystem::COLORS). */
    private const array DS_LABELS = ['pismo_titulky' => 'Heading font', 'pismo_text' => 'Text font', 'zaklad_min' => 'Base font size on phones',
        'zaklad_max' => 'Base font size on monitors', 'pomer_min' => 'Headings on phones', 'pomer_max' => 'Headings on monitors', 'sirka' => 'Content width',
        'sirka_textu' => 'Text width', 'zaobleni' => 'Corner radius', 'vlastni_pisma' => 'Custom fonts', 'typografie' => 'Typography styles'];

    /** The site identity in the draft (3.12): settings key => label. */
    public const array IDENTITY = ['logo' => 'Logo', 'favicon' => 'Site icon'];

    /** The draft look applies to this request (a preview or the builder of an administrator). */
    private static ?Settings $active = null;

    /** Makes the rest of the request render with the draft look. */
    public static function activate(Settings $s): void
    {
        self::$active = $s;
    }

    public static function isActive(): bool
    {
        return self::$active !== null;
    }

    /** @return array{design_system?: array<string, mixed>, classes?: array<string, ?array>, menus?: array<string, ?array>, identity?: array<string, string>} */
    public static function draft(Settings $s): array
    {
        $draft = json_decode($s->get('look_draft'), true);

        return is_array($draft) ? $draft : [];
    }

    public static function hasDraft(Settings $s): bool
    {
        return self::draft($s) !== [];
    }

    /** The draft design system while the draft look is active (DesignSystem::load), otherwise null. */
    public static function activeDesignSystem(): ?array
    {
        return self::$active !== null ? (self::draft(self::$active)['design_system'] ?? null) : null;
    }

    /** Draft items of a menu while the draft look is active: [whether the draft has the menu, its items or null = automatic]. */
    public static function activeMenu(string $location, string $language): array
    {
        $menus = self::$active !== null ? (self::draft(self::$active)['menus'] ?? []) : [];

        return array_key_exists($location . '|' . $language, $menus) ? [true, $menus[$location . '|' . $language]] : [false, null];
    }

    /**
     * The logo or the site icon this request shows (3.12): the draft one while the draft look is active, otherwise the
     * published setting. The page frame, the Logo element and the icon links in <head> read it.
     */
    public static function identity(Settings $s, string $key): string
    {
        $draft = self::$active !== null ? (self::draft(self::$active)['identity'] ?? []) : [];

        return is_string($draft[$key] ?? null) ? $draft[$key] : $s->get($key);
    }

    /** The logo or the site icon as editors should show it: the draft one when there is one. */
    public static function identityForEditing(Settings $s, string $key): string
    {
        $draft = self::draft($s)['identity'] ?? [];

        return is_string($draft[$key] ?? null) ? $draft[$key] : $s->get($key);
    }

    /* ---------- writing to the draft ---------- */

    /**
     * The logo or the site icon into the draft (3.12, UX310-11); the published value takes it out of the draft again.
     *
     * @return bool whether the draft now has a change of it
     */
    public static function setIdentity(Settings $s, string $key, string $value): bool
    {
        if (!isset(self::IDENTITY[$key])) {
            throw new \InvalidArgumentException('Unknown identity key: ' . $key);
        }
        $d = self::draft($s);
        $identity = $d['identity'] ?? [];
        if ($value === $s->get($key)) {
            unset($identity[$key]);
        } else {
            $identity[$key] = $value;
        }
        unset($d['identity']);
        if ($identity !== []) {
            $d['identity'] = $identity;
        }
        $s->set('look_draft', $d === [] ? '' : (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return isset($identity[$key]);
    }

    public static function setDesignSystem(Settings $s, array $ds): void
    {
        self::write($s, fn (array $d): array => ['design_system' => DesignSystem::sanitize($ds)] + $d);
    }

    /**
     * A class into the draft; null = delete it on publishing. A class the site does not have yet goes live at once – it
     * changes nothing that is published, and new pages need it – unless a published build already lists its name (3.15,
     * N315-1): then it would change the published page at once, so it waits in the draft like a change of an existing class.
     *
     * @param array{styl: array<string, mixed>, css: string}|null $class
     * @param bool $draftOnly even a new class waits in the draft (a kit from the fleet console is reviewed as a whole, 2.16)
     * @return bool whether it went to the draft
     */
    public static function setClass(Settings $s, string $name, ?array $class, bool $draftOnly = false): bool
    {
        $db = $s->db();
        if (!$draftOnly && $class !== null && $db->value('SELECT 1 FROM {tridy} WHERE nazev = ?', [$name]) === null && !MediaStaging::classInPublishedBuild($db, $name)) {
            $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, ?)', [$name, (string) json_encode($class['styl'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE), (string) $class['css'], Clock::now()]);
            $d = self::draft($s);
            if (isset($d['classes']) && array_key_exists($name, $d['classes'])) {
                unset($d['classes'][$name]);
                $s->set('look_draft', $d === ['classes' => []] || $d === [] ? '' : (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            \Kaleta\Front\Cache::clear();

            return false;
        }
        self::write($s, function (array $d) use ($name, $class): array {
            $d['classes'][$name] = $class === null ? null : ['styl' => $class['styl'] ?: new \stdClass(), 'css' => (string) $class['css']];

            return $d;
        });

        return true;
    }

    /** A menu into the draft; null = the automatic menu. */
    public static function setMenu(Settings $s, string $location, string $language, ?array $items): void
    {
        self::write($s, function (array $d) use ($location, $language, $items): array {
            $d['menus'][$location . '|' . $language] = $items === null ? null : Menu::sanitize($items);

            return $d;
        });
    }

    /** A renamed class keeps its draft under the new name. */
    public static function renameClass(Settings $s, string $old, string $new): void
    {
        $d = self::draft($s);
        if (isset($d['classes']) && array_key_exists($old, $d['classes'])) {
            $d['classes'][$new] = $d['classes'][$old];
            unset($d['classes'][$old]);
            $s->set('look_draft', (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private static function write(Settings $s, callable $change): void
    {
        $s->set('look_draft', (string) json_encode($change(self::draft($s)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function discard(Settings $s): void
    {
        $s->set('look_draft', '');
    }

    /* ---------- reading with the draft (editors) ---------- */

    /** The design system as editors should show it: the draft when there is one. */
    public static function designSystem(Settings $s): array
    {
        $draft = self::draft($s)['design_system'] ?? null;

        return is_array($draft) ? DesignSystem::sanitize($draft + DesignSystem::DEFAULTS) : DesignSystem::load($s);
    }

    /**
     * Shared classes, with the draft applied when asked: name => [styl, css, draft (changed in the draft)].
     *
     * @return array<string, array{styl: array<string, mixed>, css: string, draft: bool}>
     */
    public static function classes(Db $db, Settings $s, bool $withDraft): array
    {
        $classes = [];
        foreach ($db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev') as $r) {
            $classes[$r['nazev']] = ['styl' => json_decode((string) $r['styl'], true) ?: [], 'css' => (string) $r['css'], 'draft' => false];
        }
        if ($withDraft) {
            foreach (self::draft($s)['classes'] ?? [] as $name => $class) {
                if ($class === null) {
                    unset($classes[$name]);
                } else {
                    $classes[$name] = ['styl' => (array) ($class['styl'] ?? []), 'css' => (string) ($class['css'] ?? ''), 'draft' => true];
                }
            }
            ksort($classes);
        }

        return $classes;
    }

    /** Classes of the given names for the page CSS: the draft ones while the draft look is active. */
    public static function classesForCss(Db $db, array $names): array
    {
        if (self::$active === null) {
            return $db->all('SELECT nazev, styl, css FROM {tridy} WHERE nazev IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY nazev', $names);
        }
        $rows = [];
        foreach (self::classes($db, self::$active, true) as $name => $class) {
            if (in_array($name, $names, true)) {
                $rows[] = ['nazev' => $name, 'styl' => (string) json_encode($class['styl']), 'css' => $class['css']];
            }
        }

        return $rows;
    }

    /** Menu items as editors should show them: the draft when there is one. @return array{0: bool, 1: ?array} [from the draft, items] */
    public static function menuForEditing(Db $db, Settings $s, string $location, string $language): array
    {
        $menus = self::draft($s)['menus'] ?? [];
        if (array_key_exists($location . '|' . $language, $menus)) {
            return [true, $menus[$location . '|' . $language]];
        }

        return [false, Menu::load($db, $location, $language)];
    }

    /* ---------- publishing and versions ---------- */

    /**
     * What the draft changes, in words, for the admin bar, Claude and the change log (colours and fonts with before → after).
     * Without $menus the menus are left out – the admin bar shows them item by item (menuChanges, 3.12).
     *
     * @return list<string>
     */
    public static function summary(Db $db, Settings $s, bool $menus = true): array
    {
        $draft = self::draft($s);
        $lines = [];
        if (isset($draft['design_system'])) {
            $before = DesignSystem::load($s);
            $after = DesignSystem::sanitize($draft['design_system'] + DesignSystem::DEFAULTS);
            $changes = [];
            foreach (['barvy', 'barvy_tmave'] as $group) {
                // a dark primary or secondary without a value is derived automatically (3.6)
                [$new, $old] = [(array) $after[$group], (array) ($before[$group] ?? [])];
                foreach (array_keys($new + $old) as $k) {
                    $v = $new[$k] ?? null;
                    if (($old[$k] ?? null) !== $v) {
                        $label = t(DesignSystem::COLORS[$k] ?? (string) $k);
                        $changes[] = ($group === 'barvy_tmave' ? t('%s (dark mode)', $label) : $label) . ' ' . (is_string($old[$k] ?? null) ? $old[$k] : t('automatic')) . ' → ' . (is_string($v) ? $v : t('automatic'));
                    }
                }
            }
            foreach ($after as $k => $v) {
                if (!in_array($k, ['barvy', 'barvy_tmave'], true) && ($before[$k] ?? null) != $v) {
                    $changes[] = t(self::DS_LABELS[$k] ?? $k) . (is_scalar($v) && is_scalar($before[$k] ?? null) ? ' ' . self::valueName($k, $before[$k], $before) . ' → ' . self::valueName($k, $v, $after) : '');
                }
            }
            $changes = array_values(array_unique($changes));
            $lines[] = t('Design system: %s', $changes === [] ? t('no change') : implode(', ', array_slice($changes, 0, 8)) . (count($changes) > 8 ? ' …' : ''));
        }
        if (($draft['classes'] ?? []) !== []) {
            $existing = array_column($db->all('SELECT nazev FROM {tridy}'), 'nazev');
            $lines[] = t('Classes: %s', implode(', ', array_map(fn (string $name): string => $name . ' (' . ($draft['classes'][$name] === null ? t('deleted')
                : (in_array($name, $existing, true) ? t('changed') : t('new'))) . ')', array_keys($draft['classes']))));
        }
        if ($menus && ($draft['menus'] ?? []) !== []) {
            $lines[] = t('Menus: %s', implode(', ', array_map(fn (string $key): string => self::menuName($key, $draft['menus'][$key]), array_keys($draft['menus']))));
        }
        foreach (self::IDENTITY as $key => $label) {
            if (is_string($draft['identity'][$key] ?? null)) {
                $lines[] = t($label) . ': ' . self::fileName($s->get($key)) . ' → ' . self::fileName($draft['identity'][$key]);
            }
        }

        return $lines;
    }

    /** A logo or an icon in words: its file name, or "none". */
    private static function fileName(string $path): string
    {
        return $path === '' ? t('none') : basename($path);
    }

    /**
     * What publishing the draft menus changes for visitors (3.12, UX310-17): for each draft menu the items visitors see now
     * and after publishing (hidden pages drop out, as on the site), the labels that leave the menu and the new ones, and how
     * many draft items lead to hidden pages – those show only once the pages are published (an import brings its pages hidden).
     *
     * @return list<array{menu: string, before: int, after: int, removed: list<string>, added: list<string>, hidden: int}>
     */
    public static function menuChanges(App $app): array
    {
        $menus = self::draft($app->settings())['menus'] ?? [];
        if ($menus === []) {
            return [];
        }
        $hiddenPages = array_map('intval', array_column($app->db()->all('SELECT ids FROM {stranky} WHERE zobrazit = 0 AND smazano IS NULL'), 'ids'));
        $labels = fn (array $items): array => array_values(array_unique(array_filter(array_map(fn (array $i): string => trim((string) ($i['text'] ?? '')), Menu::flatten($items)), fn (string $text): bool => $text !== '')));
        $changes = [];
        $active = self::$active;
        foreach ($menus as $key => $items) {
            [$location, $language] = explode('|', (string) $key) + ['', ''];
            try {
                self::$active = null; // what visitors see now
                $before = Menu::items($app, $location, $language, 0);
                self::$active = $app->settings(); // the same menu with the draft, as the whole-site preview shows it
                $after = Menu::items($app, $location, $language, 0);
            } finally {
                self::$active = $active;
            }
            [$was, $will] = [$labels($before), $labels($after)];
            $changes[] = ['menu' => self::menuName((string) $key, $items === null ? null : []), 'before' => count(Menu::flatten($before)), 'after' => count(Menu::flatten($after)),
                'removed' => array_values(array_diff($was, $will)), 'added' => array_values(array_diff($will, $was)),
                'hidden' => count(array_filter(Menu::flatten(Menu::sanitize($items ?? [])), fn (array $i): bool => ($i['typ'] ?? '') === 'stranka' && in_array((int) ($i['ids'] ?? 0), $hiddenPages, true)))];
        }

        return $changes;
    }

    /**
     * One change from menuChanges() in words: "Main menu – items: 4 → 7. Leaves the menu: About us, Services. New: Home, Flats."
     *
     * @param array{menu: string, before: int, after: int, removed: list<string>, added: list<string>, hidden: int} $change
     */
    public static function menuChangeText(array $change): string
    {
        $names = fn (array $list): string => implode(', ', array_slice($list, 0, 6)) . (count($list) > 6 ? ' …' : '');
        $text = t('%s – items: %d → %d.', $change['menu'], $change['before'], $change['after']);
        $text .= $change['removed'] !== [] ? ' ' . t('Leaves the menu: %s.', $names($change['removed'])) : '';
        $text .= $change['added'] !== [] ? ' ' . t('New: %s.', $names($change['added'])) : '';

        return $text . ($change['hidden'] > 0 ? ' ' . t('Items leading to hidden pages (they show once you publish those pages): %d.', $change['hidden']) : '');
    }

    /**
     * Publishes all draft menus at once and leaves the rest of the draft waiting (3.12: "Publish the menus" after an import
     * brought them). The published look is kept as one version first, as publish() does. Returns what was published.
     *
     * @return list<string>
     */
    public static function publishMenus(App $app): array
    {
        $s = $app->settings();
        $draft = self::draft($s);
        $menus = $draft['menus'] ?? [];
        if ($menus === []) {
            return [];
        }
        $summary = [t('Menus: %s', implode(', ', array_map(fn (string $key): string => self::menuName($key, $menus[$key]), array_map('strval', array_keys($menus)))))];
        self::keepVersion($app, $summary);
        foreach ($menus as $key => $items) {
            [$location, $language] = explode('|', (string) $key) + ['', ''];
            Menu::save($app->db(), $location, $language, $items);
        }
        unset($draft['menus']);
        $s->set('look_draft', $draft === [] ? '' : (string) json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($app, 'appearance', 'publish menus', mb_substr($summary[0], 0, 255));

        return $summary;
    }

    /** A draft menu in words: "Main menu (EN) – automatic". */
    private static function menuName(string $key, ?array $items): string
    {
        [$location, $language] = explode('|', $key) + ['', ''];

        return t(Menu::LOCATIONS[$location] ?? $location) . ($language !== '' ? ' (' . strtoupper($language) . ')' : '') . ($items === null ? ' – ' . t('automatic') : '');
    }

    /**
     * What the draft touches, in a few words for the one-line admin bar (3.6): "menu, colours". The full summary() opens
     * under it.
     *
     * @return list<string>
     */
    public static function areas(Settings $s): array
    {
        $draft = self::draft($s);
        $areas = [];
        if (isset($draft['design_system'])) {
            $before = DesignSystem::load($s);
            $after = DesignSystem::sanitize($draft['design_system'] + DesignSystem::DEFAULTS);
            foreach ($after as $key => $value) {
                if (($before[$key] ?? null) == $value) {
                    continue;
                }
                $areas[] = match ($key) {
                    'barvy', 'barvy_tmave' => t('colours'),
                    'pismo_titulky', 'pismo_text', 'vlastni_pisma', 'typografie' => t('fonts'),
                    default => t('sizes'),
                };
            }
            $areas = $areas === [] ? [t('design system')] : $areas;
        }
        if (($draft['classes'] ?? []) !== []) {
            $areas[] = t('classes');
        }
        if (($draft['menus'] ?? []) !== []) {
            $areas[] = t('menu');
        }
        if (is_string($draft['identity']['logo'] ?? null)) {
            $areas[] = t('logo');
        }
        if (is_string($draft['identity']['favicon'] ?? null)) {
            $areas[] = t('site icon');
        }

        return array_values(array_unique($areas));
    }

    /**
     * A design system value as the Site appearance form names it (3.5): "Modern sans-serif" instead of moderni, "large"
     * instead of l, sizes in px. The stored values do not change.
     *
     * @param array<string, mixed> $ds the design system the value belongs to (names of custom fonts)
     */
    private static function valueName(string $key, int|float|string|bool $value, array $ds): string
    {
        $value = (string) $value;
        if ($key === 'pismo_titulky' || $key === 'pismo_text') {
            $fonts = $key === 'pismo_titulky' ? \Kaleta\Front\SiteIdentity::TITLE_FONTS : \Kaleta\Front\SiteIdentity::TEXT_FONTS;
            if (isset($fonts[$value])) {
                return t($fonts[$value][0]);
            }
            $customFonts = is_array($ds['vlastni_pisma'] ?? null) ? $ds['vlastni_pisma'] : [];
            $font = preg_match('/^vlastni-(\d+)$/D', $value, $m) ? ($customFonts[(int) $m[1] - 1] ?? null) : null;

            return is_array($font) && is_string($font['nazev'] ?? null) && $font['nazev'] !== '' ? $font['nazev'] : t('custom font');
        }

        return match ($key) {
            'zaobleni' => isset(DesignSystem::RADIUS_NAMES[$value]) ? t(DesignSystem::RADIUS_NAMES[$value]) : $value,
            'pomer_min', 'pomer_max' => isset(DesignSystem::RATIOS[$value]) ? t(DesignSystem::RATIOS[$value]) : $value,
            'zaklad_min', 'zaklad_max', 'sirka', 'sirka_textu' => round((float) $value * 16) . ' px',
            default => $value,
        };
    }

    /** The published look (to keep as a version before publishing). */
    private static function snapshot(Db $db, Settings $s): array
    {
        $menus = [];
        foreach ($db->all('SELECT umisteni, jazyk, polozky FROM {menu}') as $r) {
            $menus[$r['umisteni'] . '|' . $r['jazyk']] = json_decode((string) $r['polozky'], true) ?: [];
        }

        return ['design_system' => DesignSystem::load($s), 'classes' => array_map(fn (array $c): array => ['styl' => $c['styl'] ?: new \stdClass(), 'css' => $c['css']],
            self::classes($db, $s, false)), 'menus' => $menus, 'identity' => ['logo' => $s->get('logo'), 'favicon' => $s->get('favicon')]];
    }

    /**
     * Publishes the draft look: the published one is kept as a version first. Returns the summary of what changed.
     *
     * @return list<string>
     */
    public static function publish(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $draft = self::draft($s);
        if ($draft === []) {
            return [];
        }
        $summary = self::summary($db, $s);
        self::keepVersion($app, $summary);
        // 3.15: the files waiting for approval the draft uses (custom fonts, background images of classes, the logo) go public
        // with it (Core\MediaStaging)
        MediaStaging::promoteReferenced($db, (string) json_encode($draft, JSON_UNESCAPED_SLASHES));
        if (isset($draft['design_system'])) {
            $s->set('design_system', (string) json_encode(DesignSystem::sanitize($draft['design_system'] + DesignSystem::DEFAULTS), JSON_UNESCAPED_SLASHES));
        }
        foreach ($draft['classes'] ?? [] as $name => $class) {
            if ($class === null) {
                $db->delete('tridy', ['nazev' => $name]);
            } else {
                $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = VALUES(zmeneno)',
                    [$name, (string) json_encode($class['styl'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE), (string) $class['css'], Clock::now()]);
            }
        }
        foreach ($draft['menus'] ?? [] as $key => $items) {
            [$location, $language] = explode('|', $key) + ['', ''];
            Menu::save($db, $location, $language, $items);
        }
        if (is_string($draft['identity']['logo'] ?? null)) {
            $s->set('logo', $draft['identity']['logo']);
        }
        if (is_string($draft['identity']['favicon'] ?? null)) {
            \Kaleta\Front\SiteIdentity::saveIcon($s, $draft['identity']['favicon']);
        }
        self::discard($s);
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($app, 'appearance', 'publish look', mb_substr(implode(' · ', $summary), 0, 255));

        return $summary;
    }

    /**
     * Publishes one menu of the draft and leaves the rest of the draft waiting (3.6, "Save and publish menu" in the menu
     * editor): a menu changes nothing but itself, so it does not have to wait for colours or classes someone else is
     * still preparing. The published look is kept as a version first, as publish() does. Returns false when the draft
     * has no such menu.
     */
    public static function publishMenu(App $app, string $location, string $language): bool
    {
        $db = $app->db();
        $s = $app->settings();
        $draft = self::draft($s);
        $key = $location . '|' . $language;
        if (!array_key_exists($key, $draft['menus'] ?? [])) {
            return false;
        }
        $items = $draft['menus'][$key];
        $summary = [t('Menus: %s', self::menuName($key, $items))];
        self::keepVersion($app, $summary);
        Menu::save($db, $location, $language, $items);
        unset($draft['menus'][$key]);
        if ($draft['menus'] === []) {
            unset($draft['menus']);
        }
        $s->set('look_draft', $draft === [] ? '' : (string) json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($app, 'appearance', 'publish menu', mb_substr($summary[0], 0, 255));

        return true;
    }

    /**
     * The published look as a version (the last 20) before something of the draft goes live, with an event.
     *
     * @param list<string> $summary what is being published
     */
    private static function keepVersion(App $app, array $summary): void
    {
        $db = $app->db();
        Events::record($db, 'look.published', 'info', mb_substr(t('The look was published: %s', implode(', ', $summary)), 0, 255), ['user' => $app->auth()->id() ?: null]);
        $db->insert('look_versions', ['data' => (string) json_encode(self::snapshot($db, $app->settings()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => mb_substr(implode(' · ', $summary), 0, 500), 'author' => $app->auth()->user()['idu'] ?? null, 'created' => date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {look_versions} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {look_versions} ORDER BY id DESC LIMIT ' . self::VERSIONS . ') keep)');
    }

    /** @return list<array{id: int, summary: string, created: string, author: ?string}> newest first */
    public static function versions(Db $db): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'summary' => (string) $r['summary'], 'created' => (string) $r['created'], 'author' => $r['autor']],
            $db->all('SELECT v.id, v.summary, v.created, u.jmeno AS autor FROM {look_versions} v LEFT JOIN {uzivatele} u ON u.idu = v.author ORDER BY v.id DESC LIMIT ' . self::VERSIONS));
    }

    /** A kept version back into the draft (the site changes only after publishing): classes and menus it did not have go away. */
    public static function restoreVersion(App $app, int $id): void
    {
        $db = $app->db();
        $s = $app->settings();
        $version = json_decode((string) $db->value('SELECT data FROM {look_versions} WHERE id = ?', [$id]), true);
        if (!is_array($version)) {
            throw new \InvalidArgumentException('The look version does not exist.');
        }
        $classes = [];
        foreach (array_keys(self::classes($db, $s, false)) as $name) {
            $classes[$name] = null;
        }
        foreach ((array) ($version['classes'] ?? []) as $name => $class) {
            $classes[$name] = ['styl' => $class['styl'] ?? new \stdClass(), 'css' => (string) ($class['css'] ?? '')];
        }
        $menus = [];
        foreach ($db->all('SELECT umisteni, jazyk FROM {menu}') as $r) {
            $menus[$r['umisteni'] . '|' . $r['jazyk']] = null;
        }
        $menus = array_merge($menus, (array) ($version['menus'] ?? []));
        // the logo and the icon (versions from 3.12 on): only what differs from the published ones
        $identity = array_filter(array_intersect_key((array) ($version['identity'] ?? []), self::IDENTITY), fn (mixed $v, string $k): bool => is_string($v) && $v !== $s->get($k), ARRAY_FILTER_USE_BOTH);
        $s->set('look_draft', (string) json_encode(['design_system' => $version['design_system'] ?? DesignSystem::DEFAULTS, 'classes' => $classes ?: new \stdClass(),
            'menus' => $menus ?: new \stdClass()] + ($identity !== [] ? ['identity' => $identity] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
