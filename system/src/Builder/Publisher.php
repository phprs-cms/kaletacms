<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;

/**
 * Publishing a build draft (page, site part, collection, component or popup) – from the editor and from MCP. The previous published version goes to the history
 * (ka_stavba_revize, the last 20 for each target), the site cache is cleared. Only a person or a connection that may publish
 * gets here, so the files waiting for approval the build uses – its own and those of the shared classes it lists – go public
 * with it (Core\MediaStaging::promoteBuild).
 */
final class Publisher
{
    public const int VERSIONS_KEPT = 20;

    /** 3.50 N132: the refusal of a publish that would remove an application form (Translator::MESSAGES has it in English). */
    public const string APPLICATION_FORM_REFUSED = Build::APPLICATION_FORM_REFUSED;

    /** 3.50 review: the refusal of a publish that would change the recipients of a published application form. */
    public const string APPLICATION_RECIPIENTS_REFUSED = Build::APPLICATION_RECIPIENTS_REFUSED;

    /** 3.50.1: the refusal of a publish that would hide a published application form or put a form with a file field beside it. */
    public const string APPLICATION_FORM_SHADOWED = Build::APPLICATION_FORM_SHADOWED;

    /** 3.50.1: the refusal of deleting a pop-up or component that holds an application form. */
    public const string APPLICATION_FORM_REMOVAL_REFUSED = Build::APPLICATION_FORM_REMOVAL_REFUSED;

    /** 3.50.1 review: the refusal of switching off a pop-up, changing its rules or hiding a page that holds an application form. */
    public const string APPLICATION_FORM_OFF_SITE_REFUSED = Build::APPLICATION_FORM_OFF_SITE_REFUSED;

    /**
     * 3.50 N132: a person who may not open Applications cannot publish a build that drops an application form of the
     * published one – later applications would arrive as ordinary enquiries with their CVs unencrypted, nor one that changes
     * the recipients (or hidden copy) of a published application form (3.50 review), nor (3.50.1, D2) one that hides it or
     * puts another form with a file field beside it (Build::shadowedApplicationForms). Saving the draft is never refused.
     * 3.50.1 (D3): Build::$purposeAllowed null with a person signed in resolves from that person; nobody signed in (imports,
     * the CLI) counts as allowed. A person without the section who publishes a build holding an application form leaves a
     * line in the change log of Applications.
     *
     * @throws \DomainException
     */
    public static function guard(array $row, ?App $app = null): void
    {
        if (self::mayChangeApplicationForms($app)) {
            return;
        }
        $published = $row['stavba'] ?? null;
        $new = $row['stavba_koncept'] ?? $published;
        if (Build::droppedApplicationForms($published, $new) !== []) {
            throw new \DomainException(self::APPLICATION_FORM_REFUSED);
        }
        // 3.50 review: nor one that gives a published application form other recipients – sanitize() locks them in every
        // draft write, but a restored older version (or a draft someone with the section left) could still carry old ones
        if (Build::changedApplicationRecipients($published, $new) !== []) {
            throw new \DomainException(self::APPLICATION_RECIPIENTS_REFUSED);
        }
        if (Build::shadowedApplicationForms($published, $new) !== [] || ($app !== null && self::componentsWithFileForm($app->db(), Build::newComponentReferences($published, $new)))) {
            throw new \DomainException(self::APPLICATION_FORM_SHADOWED);
        }
        if ($app !== null && Build::droppedApplicationForms($new, null) !== []) { // the trace (A3d): the build holds an application form
            \Kaleta\Core\ChangeLog::write($app, \Kaleta\Core\Applications::SECTION, 'build published',
                mb_substr(t('Published by someone without Applications – it holds a job application form: %s', (string) ($row['titulek'] ?? $row['nazev'] ?? '')), 0, 255));
        }
    }

    /**
     * 3.50.1: removing what holds a published application form (a pop-up, a component) – refused for a person who may not
     * open Applications, by the rule of guard(). $published is the stored build as the database returns it (not a string =
     * no build). 3.50.1 review: also switching off a pop-up, changing its rules and trashing or hiding a page, with
     * $refusal (APPLICATION_FORM_OFF_SITE_REFUSED) when it is not a deletion.
     *
     * @throws \DomainException
     */
    public static function guardRemoval(mixed $published, ?App $app = null, string $refusal = self::APPLICATION_FORM_REMOVAL_REFUSED): void
    {
        if (!self::mayChangeApplicationForms($app) && Build::droppedApplicationForms(is_string($published) ? $published : null, null) !== []) {
            throw new \DomainException($refusal);
        }
    }

    /**
     * 3.50.1 review: whether one of the components (or a component they embed, published builds only) holds a form with a
     * file field that is not an application form – rule (b) of Build::shadowedApplicationForms through a new "komponenta"
     * reference.
     *
     * @param list<int> $components
     */
    private static function componentsWithFileForm(Db $db, array $components): bool
    {
        $seen = [];
        while ($components !== [] && count($seen) < 50) {
            $idm = array_shift($components);
            if (isset($seen[$idm])) {
                continue;
            }
            $seen[$idm] = true;
            $stored = $db->value('SELECT stavba FROM {komponenty} WHERE idm = ?', [$idm]);
            [$file, $inner] = Build::fileFormsAndComponents(is_string($stored) ? $stored : null);
            if ($file) {
                return true;
            }
            array_push($components, ...$inner);
        }

        return false;
    }

    /** Build::$purposeAllowed, or when it was not set (null) the person signed in; nobody signed in = allowed (D3). */
    private static function mayChangeApplicationForms(?App $app): bool
    {
        return Build::$purposeAllowed ?? ($app === null || $app->auth()->user() === null || $app->auth()->hasModule(\Kaleta\Core\Applications::SECTION));
    }

    /** Page: the content without the layout is saved into the text – search, llms.txt, the API and the return to text draw on it. */
    public static function page(App $app, array $page): void
    {
        self::guard($page, $app);
        $new = $page['stavba_koncept'] ?? $page['stavba'];
        self::version($app, ['ids' => $page['ids']], $page['stavba'], $new, $page['zmeneno'] ?? null);
        $text = Build::asText(Build::fromJson($new) ?? []);
        $app->db()->update('stranky', ['stavba' => $new, 'stavba_koncept' => null] + \Kaleta\Core\Clock::edited($app->auth()->id()) + ($text !== '' ? ['text' => $text] : []), ['ids' => $page['ids']]); // when and who (3.20, C11)
        // a page that waited hidden for its first build (3.5, Modules\Pages::visibility) goes on the site with it – unless a
        // publisher set a publish date: then the schedule shows it, not this publish (N35-1)
        $now = date('Y-m-d H:i:s');
        $app->db()->run('UPDATE {stranky} SET zobrazit = 1, show_on_publish = 0, zverejnit_od = NULL WHERE ids = ? AND show_on_publish = 1 AND smazano IS NULL AND (zverejnit_od IS NULL OR zverejnit_od <= ?)', [$page['ids'], $now]);
        $app->db()->run('UPDATE {stranky} SET show_on_publish = 0 WHERE ids = ? AND show_on_publish = 1 AND zverejnit_od > ?', [$page['ids'], $now]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Core\PageCache::clear();
    }

    public static function part(App $app, array $row): void
    {
        self::guard($row, $app);
        $new = $row['stavba_koncept'] ?? $row['stavba'];
        $variant = (string) ($row['varianta'] ?? '');
        self::version($app, ['cast' => SiteParts::versionKey($row['typ'], $row['jazyk'], $variant)], $row['stavba'], $new, $row['zmeneno'] ?? null);
        $app->db()->update('casti', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'varianta' => $variant]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Core\PageCache::clear();
    }

    /**
     * Item template of a collection in the language from Collections::inLanguage (versions under the key „kolekce:<idk>“,
     * for another language „kolekce:<idk>:<jazyk>“).
     */
    public static function collection(App $app, array $collection): void
    {
        self::guard($collection, $app);
        $new = $collection['stavba_koncept'] ?? $collection['stavba'];
        self::version($app, ['cast' => Collections::templateKey($collection)], $collection['stavba'], $new, $collection['zmeneno'] ?? null);
        Collections::writeTemplate($app->db(), $collection, ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Core\PageCache::clear();
    }

    /** Popup (versions under the key „popup:<idpp>“). */
    public static function popup(App $app, array $popup): void
    {
        self::guard($popup, $app);
        $new = $popup['stavba_koncept'] ?? $popup['stavba'];
        self::version($app, ['cast' => 'popup:' . (int) $popup['idpp']], $popup['stavba'], $new, $popup['zmeneno'] ?? null);
        $app->db()->update('popupy', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idpp' => $popup['idpp']]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Core\PageCache::clear();
    }

    /** Component (versions under the key „komponenta:<idm>“) – the change shows on all pages where it is used. */
    public static function component(App $app, array $component): void
    {
        self::guard($component, $app);
        $new = $component['stavba_koncept'] ?? $component['stavba'];
        self::version($app, ['cast' => 'komponenta:' . (int) $component['idm']], $component['stavba'], $new, $component['zmeneno'] ?? null);
        $app->db()->update('komponenty', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idm' => $component['idm']]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Core\PageCache::clear();
    }

    /** Saves the previous published version to the history. @param array{ids?: int|string, cast?: string} $target */
    public static function version(App $app, array $target, ?string $old, ?string $newVersion, ?string $date): void
    {
        // 2.8: every publishing is an event (who: a user id and whether it came through a Claude connection)
        \Kaleta\Core\Events::record($app->db(), 'build.published', 'info', t('Published: %s', isset($target['ids']) ? 'page ' . (int) $target['ids'] : (string) ($target['cast'] ?? '')),
            $target + ['user' => $app->auth()->id() ?: null, 'claude' => $app->auth()->connection() !== null]);
        if ($old === null || $old === $newVersion) {
            return;
        }
        $db = $app->db();
        $db->insert('stavba_revize', $target + ['datum' => $date ?? date('Y-m-d H:i:s'), 'kdo' => $app->auth()->id() ?: null, 'stavba' => $old]);
        [$whereParts, $value] = self::whereClause($target);
        $boundary = $db->value('SELECT idr FROM {stavba_revize} WHERE ' . $whereParts . ' ORDER BY idr DESC LIMIT 1 OFFSET ' . self::VERSIONS_KEPT, [$value]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {stavba_revize} WHERE ' . $whereParts . ' AND idr <= ?', [$value, $boundary]);
        }
    }

    /** @param array{ids?: int|string, cast?: string} $target @return list<array<string, mixed>> */
    public static function listAll(Db $db, array $target): array
    {
        [$whereParts, $value] = self::whereClause($target);

        return $db->all("SELECT r.idr, r.datum, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r." . $whereParts . ' ORDER BY r.idr DESC', [$value]);
    }

    /** @param array{ids?: int|string, cast?: string} $target */
    public static function load(Db $db, array $target, int $idr): ?string
    {
        [$whereParts, $value] = self::whereClause($target);
        $build = $db->value('SELECT stavba FROM {stavba_revize} WHERE idr = ? AND ' . $whereParts, [$idr, $value]);

        return $build === null ? null : (string) $build;
    }

    /** @return array{0: string, 1: int|string} */
    private static function whereClause(array $target): array
    {
        // a page's draft history (3.25, DraftHistory) shares the table under cast "draft…" – published versions have none
        return isset($target['ids']) ? ['ids = ? AND cast IS NULL', (int) $target['ids']] : ['cast = ?', (string) $target['cast']];
    }
}
