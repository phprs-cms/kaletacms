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
 * "application_forms", Core\Scheduler). The locks of the builder, the pages and the pop-ups (Publisher::guard*, guardRemoval,
 * guardClasses, Popups::rulesNarrowed) stop ordinary and accidental changes by people without Applications; this alert
 * covers the rest – whatever way a form went off the site.
 *
 * A page or a pop-up is watched while its published build holds an application form (directly or through components,
 * nested components bounded). It is reachable when the page is visible, not in the trash, without a password and still
 * true (valid_until), or the pop-up is switched on, true, inside its period, shown somewhere and its trigger can fire
 * (not "only by clicking", at most MAX_PAGES pages, MAX_SECONDS seconds or 100 % scrolled) – and the form in it, or the
 * component holding it, is hidden neither by its style, display conditions nor a class whose published style hides
 * (Build::showsApplicationForm). When one that was reachable is not, every user who may open Applications gets one
 * notification in the bell (Core\Inbox kind "form_unreachable", target "form:page:<id>" | "form:popup:<id>"); when it is
 * reachable again, those notifications are removed. The last state is the setting application_forms_watch (JSON).
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
    /** Rounds over the components – a component inside a component inside … is followed this deep. */
    private const int NESTING = 10;

    /** One run of the job: returns what it did, for System status. */
    public static function run(App $app): string
    {
        $s = $app->settings();
        if (!Extensions::isEnabled($s, Applications::EXTENSION)) {
            return 'off';
        }
        $db = $app->db();
        $current = self::scan($db, \Kaleta\Core\Clock::today());
        $stored = $s->get(self::SETTING);
        $state = self::state($stored);
        $changes = self::changes($state, $current);
        $s->set(self::SETTING, (string) json_encode($changes['state'], JSON_UNESCAPED_UNICODE));
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
                foreach ($users as $userId) {
                    Inbox::notify($app, $userId, self::KIND, $key, null, $title);
                }
            }
        }

        return 'watching ' . count($current) . ', unreachable ' . count($changes['alert']) . ', reachable again ' . count($changes['resolve']);
    }

    /**
     * Every page and pop-up whose published build holds an application form: key => whether it is reachable and its title.
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
        $holds = fn (string $json): bool => Build::droppedApplicationForms($json, null) !== [] || array_intersect(Build::fileFormsAndComponents($json)[1], $holding) !== [];
        $out = [];
        foreach ($db->all('SELECT ids, titulek, stavba, zobrazit, heslo_hash, valid_until, smazano FROM {stranky} WHERE stavba IS NOT NULL') as $r) {
            $json = (string) $r['stavba'];
            if ($holds($json)) {
                $out['form:page:' . (int) $r['ids']] = ['reachable' => self::pageCanShow($r, $today) && Build::showsApplicationForm($json, $hidingClasses, $showing), 'title' => (string) $r['titulek']];
            }
        }
        foreach ($db->all('SELECT idpp, nazev, stavba, aktivni, spoustec, hodnota, pravidla, valid_until FROM {popupy} WHERE stavba IS NOT NULL') as $r) {
            $json = (string) $r['stavba'];
            if ($holds($json)) {
                $out['form:popup:' . (int) $r['idpp']] = ['reachable' => self::popupCanShow($r, $today) && Build::showsApplicationForm($json, $hidingClasses, $showing), 'title' => (string) $r['nazev']];
            }
        }

        return $out;
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
     * period of its rules, shown on the whole site or on some chosen place, and with a trigger a visitor meets. Pure.
     */
    public static function popupCanShow(array $popup, string $today): bool
    {
        $until = Validity::date($popup['valid_until'] ?? null);
        $rules = Popups::sanitizeRules(is_array($popup['pravidla'] ?? null) ? $popup['pravidla'] : (array) json_decode((string) ($popup['pravidla'] ?? ''), true));
        if ((int) ($popup['aktivni'] ?? 0) !== 1 || ($until !== null && $until < $today)
            || ($rules['od'] !== '' && $today < $rules['od']) || ($rules['do'] !== '' && $today > $rules['do'])
            || ($rules['kde'] === 'vybrane' && $rules['stranky'] === [] && $rules['kolekce'] === [] && !$rules['novinky'])) {
            return false;
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
     * The stored state, or an empty one for '' or anything that does not decode. Pure.
     *
     * @return array{reachable: array<string, string>, alerted: array<string, string>}
     */
    public static function state(string $stored): array
    {
        $data = json_decode($stored, true);
        $map = function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $key => $title) {
                if (is_string($key) && preg_match('/^form:(page|popup):[1-9]\d{0,9}$/D', $key) === 1) {
                    $out[$key] = is_string($title) ? $title : '';
                }
            }

            return $out;
        };

        return ['reachable' => $map($data['reachable'] ?? null), 'alerted' => $map($data['alerted'] ?? null)];
    }

    /**
     * What changed since the last run: the keys that were reachable and are not any more (alerted once – also when the page
     * or the pop-up is gone), the reported keys that are reachable again (resolved), and the new state. Pure.
     *
     * @param array{reachable: array<string, string>, alerted: array<string, string>} $state
     * @param array<string, array{reachable: bool, title: string}> $current
     * @return array{state: array{reachable: array<string, string>, alerted: array<string, string>}, alert: array<string, string>, resolve: list<string>}
     */
    public static function changes(array $state, array $current): array
    {
        $alert = [];
        foreach ($state['reachable'] as $key => $title) {
            if (!($current[$key]['reachable'] ?? false) && !isset($state['alerted'][$key])) {
                $alert[$key] = $current[$key]['title'] ?? $title;
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
                $reachable[$key] = $row['title'];
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
        foreach ($db->all('SELECT idm, stavba FROM {komponenty} WHERE stavba IS NOT NULL') as $r) {
            $builds[(int) $r['idm']] = (string) $r['stavba'];
        }
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
