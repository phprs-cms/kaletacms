<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Applications;
use Kaleta\Core\Auth;
use Kaleta\Core\Db;
use Kaleta\Core\Extensions;
use Kaleta\Core\Inbox;
use Kaleta\Core\Validity;

/**
 * 3.50.1: the hourly check that published job application forms can still be reached on the site (background job
 * "application_forms", Core\Scheduler). The locks of the builder, the pages, the pop-ups and the site parts (Publisher::guard*,
 * guardRemoval, guardClasses, Popups::rulesNarrowed) stop ordinary and accidental changes by people without Applications;
 * this alert covers the rest – whatever way a form on a page, in a pop-up or in a site part went off the site.
 *
 * A page, a pop-up or a site part (header, footer, their variants, the wrappers) is watched while its published build holds
 * an application form (directly or through components, nested components bounded). It is reachable when the page is
 * visible, not in the trash, without a password and still true (valid_until), or the pop-up is switched on, true, inside its
 * period, shown somewhere that exists (3.50.1 release review: a chosen page that can show, a collection with item pages, or
 * news) and its trigger can fire (not "only by clicking", at most MAX_PAGES pages, MAX_SECONDS seconds or 100 % scrolled),
 * or the part is the default one, or a variant applied to a page that can show or to other content – and the form in it,
 * or the component holding it, is hidden neither by its style, display conditions nor a class whose published style hides
 * (Build::showsApplicationForm). When one that was reachable is not, every user who may open Applications gets one
 * notification in the bell (Core\Inbox kind "form_unreachable", target "form:page:<id>" | "form:popup:<id>" |
 * "form:part:<hash>"); when it is reachable again, those notifications are removed. The last state is the setting
 * application_forms_watch (JSON: the reachable ids by kind, the reported keys with their titles), kept under MAX_STATE bytes.
 * Only builds that mention a job application form or a component are read, one row at a time.
 */
final class ApplicationFormWatch
{
    public const string SETTING = 'application_forms_watch';
    public const string KIND = 'form_unreachable';
    public const int MAX_PAGES = 10;
    public const int MAX_SECONDS = 120;
    public const int MAX_SCROLL = 100;
    /** How many keys that were reported stay remembered (the oldest go first). */
    public const int KEEP_ALERTED = 100;
    /** The stored state stays below this many bytes (the setting is a TEXT column, 64 kB) – the reachable list is cut when it would not. */
    public const int MAX_STATE = 60000;
    /** Rounds over the components – a component inside a component inside … is followed this deep. */
    private const int NESTING = 10;
    /** Only builds containing one of these are read: an application form (its Purpose) or a component that may hold one. */
    private const string PREFILTER = '(stavba LIKE ? OR stavba LIKE ?)';
    private const array PREFILTER_PARAMS = ['%' . Applications::PURPOSE . '%', '%komponenta%'];

    /** One run of the job: returns what it did, for System status. */
    public static function run(App $app): string
    {
        $s = $app->settings();
        if (!Extensions::isEnabled($s, Applications::EXTENSION)) {
            return 'off';
        }
        $db = $app->db();
        // 3.50.1 release review: noted as run before the scan, so a scan that dies (memory, time) is not retried every minute
        $db->run('UPDATE {jobs} SET last_run = ? WHERE name = ?', [date('Y-m-d H:i:s'), 'application_forms']);
        $current = self::scan($db, \Kaleta\Core\Clock::today());
        $stored = $s->get(self::SETTING);
        $state = self::state($stored);
        $changes = self::changes($state, $current);
        $s->set(self::SETTING, self::encode($changes['state']));
        if ($stored === '') {
            return 'started, watching ' . count($current);
        }
        foreach ($changes['resolve'] as $key) {
            $db->run('DELETE FROM {notifications} WHERE kind = ? AND target = ?', [self::KIND, $key]);
        }
        if ($changes['alert'] !== []) {
            $users = array_map('intval', array_column($db->all('SELECT idu FROM {uzivatele} u WHERE blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))',
                [Auth::ADMIN, Applications::SECTION]), 'idu'));
            foreach ($changes['alert'] as $key => $title) {
                $title = $title !== '' ? $title : (str_starts_with($key, 'form:page:') ? t('deleted page') : t('deleted')); // gone without a trace
                foreach ($users as $userId) {
                    Inbox::notify($app, $userId, self::KIND, $key, null, $title);
                }
            }
        }

        return 'watching ' . count($current) . ', unreachable ' . count($changes['alert']) . ', reachable again ' . count($changes['resolve']);
    }

    /**
     * Every page, pop-up and site part whose published build holds an application form: key => whether it is reachable and
     * its title.
     *
     * @return array<string, array{reachable: bool, title: string}>
     */
    public static function scan(Db $db, string $today): array
    {
        $hidingClasses = [];
        foreach ($db->all('SELECT nazev, styl, css FROM {tridy}') as $r) {
            if (Build::classHides(['styl' => json_decode((string) $r['styl'], true), 'css' => (string) $r['css']])) {
                $hidingClasses[] = (string) $r['nazev'];
            }
        }
        [$holding, $showing] = self::components($db, $hidingClasses);
        $out = [];
        $stmt = $db->run('SELECT ids, titulek, stavba, zobrazit, heslo_hash, valid_until, smazano FROM {stranky} WHERE stavba IS NOT NULL AND ' . self::PREFILTER, self::PREFILTER_PARAMS);
        while (($r = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            [$holds, $shows] = Build::applicationFormPresence((string) $r['stavba'], $hidingClasses, $holding, $showing);
            if ($holds) {
                $out['form:page:' . (int) $r['ids']] = ['reachable' => $shows && self::pageCanShow($r, $today), 'title' => (string) $r['titulek']];
            }
        }
        $stmt->closeCursor();
        // pop-ups and site parts: the few that hold a form are kept, then the pages and collections they are limited to are looked up
        $popups = [];
        $stmt = $db->run('SELECT idpp, nazev, stavba, aktivni, spoustec, hodnota, pravidla, valid_until FROM {popupy} WHERE stavba IS NOT NULL AND ' . self::PREFILTER, self::PREFILTER_PARAMS);
        while (($r = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            [$holds, $shows] = Build::applicationFormPresence((string) $r['stavba'], $hidingClasses, $holding, $showing);
            if ($holds) {
                $popups[] = ['shows' => $shows] + array_diff_key($r, ['stavba' => true]);
            }
        }
        $stmt->closeCursor();
        $parts = [];
        $stmt = $db->run('SELECT typ, jazyk, varianta, nazev, stranky, pravidla, stavba FROM {casti} WHERE stavba IS NOT NULL AND ' . self::PREFILTER, self::PREFILTER_PARAMS);
        while (($r = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            [$holds, $shows] = Build::applicationFormPresence((string) $r['stavba'], $hidingClasses, $holding, $showing);
            if ($holds) {
                $parts[] = ['shows' => $shows] + array_diff_key($r, ['stavba' => true]);
            }
        }
        $stmt->closeCursor();
        $chosen = [];
        foreach ($popups as $r) {
            array_push($chosen, ...Popups::sanitizeRules((array) json_decode((string) ($r['pravidla'] ?? ''), true))['stranky']);
        }
        foreach ($parts as $r) {
            array_push($chosen, ...self::partPages($r));
        }
        $livePages = [];
        foreach (array_chunk(array_values(array_unique($chosen)), 500) as $chunk) {
            foreach ($db->all('SELECT ids, zobrazit, heslo_hash, valid_until, smazano FROM {stranky} WHERE ids IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                if (self::pageCanShow($r, $today)) {
                    $livePages[] = (int) $r['ids'];
                }
            }
        }
        $detailCollections = $popups === [] && $parts === [] ? [] : array_map('strval', array_column($db->all('SELECT seo_link FROM {kolekce} WHERE detail = 1'), 'seo_link'));
        foreach ($popups as $r) {
            $out['form:popup:' . (int) $r['idpp']] = ['reachable' => $r['shows'] && self::popupCanShow($r, $today, $livePages, $detailCollections), 'title' => (string) $r['nazev']];
        }
        foreach ($parts as $r) {
            $label = t(SiteParts::TYPES[(string) $r['typ']][0] ?? (string) $r['typ']);
            $out[self::partKey((string) $r['typ'], (string) $r['jazyk'], (string) $r['varianta'])] = ['reachable' => $r['shows'] && self::partCanShow($r, $livePages, $detailCollections),
                'title' => mb_substr($label . ((string) $r['jazyk'] !== '' ? ' (' . $r['jazyk'] . ')' : '') . ((string) $r['varianta'] !== '' ? ' – ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['varianta']) : ''), 0, 200)];
        }

        return $out;
    }

    /** The watch key of a site part: its type, language and variant hashed into the 40 characters of a notification target. Pure. */
    public static function partKey(string $type, string $language, string $variant): string
    {
        return 'form:part:' . substr(sha1($type . ':' . $language . ':' . $variant), 0, 16);
    }

    /**
     * Whether a site part row (varianta, stranky, pravidla) is drawn somewhere: the default version always, a variant when one
     * of its pages can show ($livePages), or it applies to news, the news list, a collection with item pages or pages under
     * a parent. Pure.
     *
     * @param list<int> $livePages
     * @param list<string> $detailCollections
     */
    public static function partCanShow(array $part, array $livePages, array $detailCollections): bool
    {
        if ((string) ($part['varianta'] ?? '') === '') {
            return true;
        }
        $rules = SiteParts::sanitizeRules(json_decode((string) ($part['pravidla'] ?? ''), true));

        return array_intersect(self::partPages($part), $livePages) !== [] || $rules['novinky'] || $rules['vypis'] || $rules['nadrazene'] !== []
            || array_intersect($rules['kolekce'], $detailCollections) !== [];
    }

    /** @return list<int> the pages a variant row applies to (none for the default version) */
    private static function partPages(array $part): array
    {
        if ((string) ($part['varianta'] ?? '') === '') {
            return [];
        }
        $pages = json_decode((string) ($part['stranky'] ?? ''), true);

        return array_values(array_filter(array_map('intval', is_array($pages) ? $pages : []), fn (int $ids): bool => $ids > 0));
    }

    /** Whether a page row (zobrazit, smazano, heslo_hash, valid_until) is on the site today for every visitor. Pure. */
    public static function pageCanShow(array $page, string $today): bool
    {
        $until = Validity::date($page['valid_until'] ?? null);

        return (int) ($page['zobrazit'] ?? 0) === 1 && ($page['smazano'] ?? null) === null && (string) ($page['heslo_hash'] ?? '') === ''
            && ($until === null || $until >= $today);
    }

    /**
     * Whether a pop-up row (aktivni, valid_until, pravidla, spoustec, hodnota) can open today: switched on, true, inside the
     * period of its rules, shown on the whole site or on some chosen place, and with a trigger a visitor meets. 3.50.1 release
     * review: with $livePages (the chosen pages that can show) and $detailCollections (collections with item pages) a pop-up
     * limited to chosen places needs one of them that exists, or news. Pure.
     *
     * @param list<int>|null $livePages
     * @param list<string>|null $detailCollections
     */
    public static function popupCanShow(array $popup, string $today, ?array $livePages = null, ?array $detailCollections = null): bool
    {
        $until = Validity::date($popup['valid_until'] ?? null);
        $rules = Popups::sanitizeRules(is_array($popup['pravidla'] ?? null) ? $popup['pravidla'] : (array) json_decode((string) ($popup['pravidla'] ?? ''), true));
        if ((int) ($popup['aktivni'] ?? 0) !== 1 || ($until !== null && $until < $today)
            || ($rules['od'] !== '' && $today < $rules['od']) || ($rules['do'] !== '' && $today > $rules['do'])
            || ($rules['kde'] === 'vybrane' && $rules['stranky'] === [] && $rules['kolekce'] === [] && !$rules['novinky'])) {
            return false;
        }
        if ($rules['kde'] === 'vybrane' && !$rules['novinky'] && $livePages !== null && $detailCollections !== null
            && array_intersect($rules['stranky'], $livePages) === [] && array_intersect($rules['kolekce'], $detailCollections) === []) {
            return false; // every chosen page is hidden, in the trash or gone, and no chosen collection has item pages
        }
        $value = (int) ($popup['hodnota'] ?? 0);

        return match ((string) ($popup['spoustec'] ?? '')) {
            'klik' => false,
            'stranky' => $value <= self::MAX_PAGES,
            'cas', 'necinnost' => $value <= self::MAX_SECONDS,
            'posun' => $value <= self::MAX_SCROLL,
            default => true,
        };
    }

    /**
     * The stored state, or an empty one for '' or anything that does not decode: the reachable keys and the reported keys
     * with their titles. Reads the 3.50.1 form ({"reachable": {key: title}}) and the compact one encode() writes. Pure.
     *
     * @return array{reachable: list<string>, alerted: array<string, string>}
     */
    public static function state(string $stored): array
    {
        $data = json_decode($stored, true);
        $data = is_array($data) ? $data : [];
        $valid = fn (mixed $key): bool => is_string($key) && preg_match('/^form:(?:(?:page|popup):[1-9]\d{0,9}|part:[0-9a-f]{16})$/D', $key) === 1;
        $reachable = [];
        foreach (is_array($data['reachable'] ?? null) ? array_keys($data['reachable']) : [] as $key) { // 3.50.1: key => title
            $reachable[] = $key;
        }
        foreach (['pages' => 'form:page:', 'popups' => 'form:popup:', 'parts' => 'form:part:'] as $group => $prefix) {
            foreach (is_array($data[$group] ?? null) ? $data[$group] : [] as $id) {
                if (is_int($id) || is_string($id)) {
                    $reachable[] = $prefix . $id;
                }
            }
        }
        $alerted = [];
        foreach (is_array($data['alerted'] ?? null) ? $data['alerted'] : [] as $key => $title) {
            if ($valid($key)) {
                $alerted[(string) $key] = is_string($title) ? $title : '';
            }
        }

        return ['reachable' => array_values(array_unique(array_filter($reachable, $valid))), 'alerted' => $alerted];
    }

    /**
     * The state as stored: the reachable ids grouped by kind (pages and pop-ups as numbers) and the reported keys with their
     * titles (at most 100 characters each) – below MAX_STATE bytes; on a site with very many watched pages the reachable list is cut (those pages are
     * then watched again from the next run on as new). Pure.
     *
     * @param array{reachable: list<string>, alerted: array<string, string>} $state
     */
    public static function encode(array $state): string
    {
        $groups = ['pages' => [], 'popups' => [], 'parts' => []];
        foreach ($state['reachable'] as $key) {
            if (preg_match('/^form:page:(\d+)$/D', $key, $m) === 1) {
                $groups['pages'][] = (int) $m[1];
            } elseif (preg_match('/^form:popup:(\d+)$/D', $key, $m) === 1) {
                $groups['popups'][] = (int) $m[1];
            } elseif (preg_match('/^form:part:([0-9a-f]+)$/D', $key, $m) === 1) {
                $groups['parts'][] = $m[1];
            }
        }
        $alerted = (object) array_map(fn (string $title): string => mb_substr($title, 0, 100), $state['alerted']);
        $json = (string) json_encode($groups + ['alerted' => $alerted], JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_STATE) {
            $keep = max(0, (int) (count($groups['pages']) * (self::MAX_STATE / strlen($json)) * 0.9));
            $groups['pages'] = array_slice($groups['pages'], 0, $keep);
            $json = (string) json_encode($groups + ['alerted' => $alerted], JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /**
     * What changed since the last run: the keys that were reachable and are not any more (alerted once – also when the page,
     * the pop-up or the part is gone, then with an empty title), the reported keys that are reachable again (resolved), and
     * the new state. Pure.
     *
     * @param array{reachable: list<string>, alerted: array<string, string>} $state
     * @param array<string, array{reachable: bool, title: string}> $current
     * @return array{state: array{reachable: list<string>, alerted: array<string, string>}, alert: array<string, string>, resolve: list<string>}
     */
    public static function changes(array $state, array $current): array
    {
        $alert = [];
        foreach ($state['reachable'] as $key) {
            if (!($current[$key]['reachable'] ?? false) && !isset($state['alerted'][$key])) {
                $alert[$key] = $current[$key]['title'] ?? '';
            }
        }
        $resolve = [];
        foreach (array_keys($state['alerted']) as $key) {
            if ($current[$key]['reachable'] ?? false) {
                $resolve[] = $key;
            }
        }
        $alerted = array_diff_key($state['alerted'], array_flip($resolve)) + $alert;
        if (count($alerted) > self::KEEP_ALERTED) {
            $alerted = array_slice($alerted, -self::KEEP_ALERTED, null, true);
        }
        $reachable = [];
        foreach ($current as $key => $row) {
            if ($row['reachable']) {
                $reachable[] = (string) $key;
            }
        }

        return ['state' => ['reachable' => $reachable, 'alerted' => $alerted], 'alert' => $alert, 'resolve' => $resolve];
    }

    /**
     * The components whose published build holds an application form (directly or through components it embeds), and those
     * of them that show one (Build::showsApplicationForm with the components found so far) – bounded by NESTING rounds.
     *
     * @param list<string> $hidingClasses
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function components(Db $db, array $hidingClasses): array
    {
        $builds = [];
        $stmt = $db->run('SELECT idm, stavba FROM {komponenty} WHERE stavba IS NOT NULL AND ' . self::PREFILTER, self::PREFILTER_PARAMS);
        while (($r = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $builds[(int) $r['idm']] = (string) $r['stavba'];
        }
        $stmt->closeCursor();
        $holding = [];
        $showing = [];
        for ($round = 0; $round < self::NESTING; $round++) {
            $grown = false;
            foreach ($builds as $idm => $json) {
                if (!isset($holding[$idm]) && (Build::droppedApplicationForms($json, null) !== [] || array_intersect(Build::fileFormsAndComponents($json)[1], array_keys($holding)) !== [])) {
                    $holding[$idm] = true;
                    $grown = true;
                }
                if (!isset($showing[$idm]) && Build::showsApplicationForm($json, $hidingClasses, array_keys($showing))) {
                    $showing[$idm] = true;
                    $grown = true;
                }
            }
            if (!$grown) {
                break;
            }
        }

        return [array_keys($holding), array_keys($showing)];
    }
}
