<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

/**
 * Elements copied between Kaleta sites through the system clipboard (2.7). The builder writes a text envelope
 * {"kaleta":"elements","v":1,"site":"https://source.example","elements":[…],"classes":[…],"components":[…]} and the
 * builder of another site reads it back from a paste. The shared classes and the components the elements use travel
 * the same way as in a page export (PagePackage): a class the target site already has is kept, a missing one is
 * created, components are reused or created – by an administrator only. Element ids and anchors are made anew on paste;
 * media of the other site are pointed at it (https) or left out, and the number is reported.
 */
final class ElementClipboard
{
    public const string FORMAT = 'elements';
    public const int VERSION = 1;

    /** Top-level elements in one envelope. */
    public const int MAX_ELEMENTS = 200;

    /** Length of the envelope text the server reads (bytes). */
    public const int MAX_LENGTH = 2_000_000;

    /** The relative address of a file in Media as the build validators allow it. */
    private const string MEDIA_PATH = '/?(?:[A-Za-z0-9_.-]+/){0,3}(media/[A-Za-z0-9/_.-]{1,300})';

    /**
     * The envelope for the clipboard: the elements with the shared classes and components they use (components inside
     * components too).
     *
     * @param list<array<string, mixed>> $elements
     * @return array<string, mixed>
     */
    public static function pack(Db $db, array $elements, string $origin): array
    {
        $package = PagePackage::collect($db, ['deti' => $elements]);

        return ['kaleta' => self::FORMAT, 'v' => self::VERSION, 'site' => $origin, 'elements' => $elements, 'classes' => $package['tridy'], 'components' => $package['komponenty']];
    }

    /**
     * Checks an envelope from the clipboard. Anything that is not one (plain text, another JSON) gives null; the elements
     * themselves are checked later by Build::sanitize.
     *
     * @return array{site: string, prvky: list<array<string, mixed>>, tridy: list<array<string, mixed>>, komponenty: list<array<string, mixed>>}|null
     */
    public static function parse(mixed $data): ?array
    {
        if (!is_array($data) || ($data['kaleta'] ?? null) !== self::FORMAT || !is_int($data['v'] ?? null) || $data['v'] < 1 || $data['v'] > self::VERSION
            || !is_array($data['elements'] ?? null) || !array_is_list($data['elements'])) {
            return null;
        }
        $elements = array_values(array_filter($data['elements'], fn (mixed $e): bool => is_array($e) && is_string($e['typ'] ?? null)));
        if ($elements === [] || count($elements) > self::MAX_ELEMENTS) {
            return null;
        }
        $site = is_string($data['site'] ?? null) && preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#iD', rtrim($data['site'], '/')) ? strtolower(rtrim($data['site'], '/')) : '';
        $list = fn (string $key): array => is_array($data[$key] ?? null) && array_is_list($data[$key]) ? array_values(array_filter($data[$key], 'is_array')) : [];

        return ['site' => $site, 'prvky' => $elements, 'tridy' => $list('classes'), 'komponenty' => $list('components')];
    }

    /**
     * The signature this site puts on an envelope it packs (3.18.2, N3182-2). Any web page can put text into the clipboard
     * that claims to come from this site; only a signed envelope is the administrator's own copy, whose code elements
     * (Custom HTML) may be pasted as they are. The data is signed in a canonical form – the envelope travels through the
     * browser's JSON, which reorders numeric keys and writes 1.0 as 1.
     *
     * @param array<string, mixed> $envelope
     */
    public static function signature(array $envelope, string $key): string
    {
        $data = ['site' => $envelope['site'] ?? null, 'elements' => $envelope['elements'] ?? null, 'classes' => $envelope['classes'] ?? null, 'components' => $envelope['components'] ?? null];

        return hash_hmac('sha256', 'clipboard|' . json_encode(self::canonical($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $key);
    }

    /** Was the envelope (the decoded clipboard text) packed and signed by this site? */
    public static function isSigned(mixed $envelope, string $key): bool
    {
        return is_array($envelope) && is_string($envelope['sig'] ?? null) && $key !== '' && hash_equals(self::signature($envelope, $key), $envelope['sig']);
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Elements without code elements (Custom HTML, ADMIN_ONLY), also inside containers; $count says how many went.
     *
     * @param array<mixed> $elements
     * @return list<array<mixed>>
     */
    public static function withoutCode(array $elements, int &$count): array
    {
        $kept = [];
        foreach ($elements as $p) {
            if (!is_array($p)) {
                continue; // not an element – Build::sanitize would leave it out anyway
            }
            $className = Build::className((string) ($p['typ'] ?? ''));
            if ($className !== null && $className::ADMIN_ONLY) {
                $count++;
                continue;
            }
            if (is_array($p['deti'] ?? null)) {
                $p['deti'] = self::withoutCode(array_values($p['deti']), $count);
            }
            $kept[] = $p;
        }

        return $kept;
    }

    /**
     * Brings the elements of an envelope onto this site: missing classes and components are created and the component uses
     * pointed at this site's ids (PagePackage::import – an administrator only; for others the uses are emptied, the other
     * site's ids mean nothing here), media are relinked, ids and anchors dropped. From the same site nothing is imported –
     * classes, components and media are already here. The result still has to go through Build::sanitize.
     * Without $code the code elements are left out of the elements and of the components that come along (an envelope this
     * site did not sign – N3182-2).
     *
     * @param array{site: string, prvky: list<array<string, mixed>>, tridy: list<array<string, mixed>>, komponenty: list<array<string, mixed>>} $package
     * @return array{0: list<array<string, mixed>>, 1: array{tridy: int, komponenty: int}, 2: int, 3: int} elements, what was created, relinked images, left-out code elements
     */
    public static function import(Settings $s, array $package, string $thisSite, bool $admin, bool $code = true): array
    {
        $dropped = 0;
        if (!$code) {
            $package['prvky'] = self::withoutCode($package['prvky'], $dropped);
            foreach ($package['komponenty'] as $i => $k) {
                if (is_array($k['stavba'] ?? null) && array_is_list($k['stavba'])) {
                    $package['komponenty'][$i]['stavba'] = ['deti' => $k['stavba']]; // a build given as a plain list (N3182-10)
                }
                $build = $package['komponenty'][$i]['stavba'] ?? null;
                if (is_array($build) && is_array($build['deti'] ?? null)) {
                    $package['komponenty'][$i]['stavba']['deti'] = self::withoutCode(array_values($build['deti']), $dropped);
                }
            }
        }
        $elements = self::fresh($package['prvky']);
        if ($package['site'] !== '' && $package['site'] === strtolower(rtrim($thisSite, '/'))) {
            return [$elements, ['tridy' => 0, 'komponenty' => 0], 0, $dropped];
        }
        $images = 0;
        $elements = self::relinkMedia($elements, $package['site'], $images);
        [$build, $created] = PagePackage::import($s, ['tridy' => $package['tridy'], 'komponenty' => $package['komponenty']], ['deti' => $elements], $admin);
        $elements = is_array($build['deti'] ?? null) ? array_values($build['deti']) : [];

        return [$admin ? $elements : self::detachComponents($elements), $created, $images, $dropped];
    }

    /**
     * Elements without ids and anchors: Build::sanitize gives new ids, and an anchor would clash with the page the copy came
     * from (the builder drops anchors when duplicating too).
     *
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    public static function fresh(array $elements): array
    {
        foreach ($elements as $i => $p) {
            unset($p['id'], $p['kotva']);
            if (is_array($p['deti'] ?? null)) {
                $p['deti'] = self::fresh(array_values($p['deti']));
            }
            $elements[$i] = $p;
        }

        return $elements;
    }

    /**
     * Media of the other site: a relative address (media/2026/foto.jpg) exists only there. When the other site is on https,
     * the image is pointed at it – an https address is what the validators allow – and the visitor still sees it; from an
     * http site it is left out. Either way the images should be replaced by files from this site's Media, so their number
     * is counted. Content values and styles are walked (background images), also inside HTML text.
     *
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    public static function relinkMedia(array $elements, string $site, int &$count): array
    {
        $https = str_starts_with($site, 'https://');
        $value = function (mixed $v) use (&$value, &$count, $https, $site): mixed {
            if (is_array($v)) {
                foreach ($v as $k => $x) {
                    $v[$k] = $value($x);
                }

                return $v;
            }
            if (!is_string($v) || !str_contains($v, 'media/')) {
                return $v;
            }
            if (preg_match('#^' . self::MEDIA_PATH . '$#', $v, $m)) {
                $count++;

                return $https ? $site . '/' . $m[1] : '';
            }
            if (str_contains($v, '<')) {
                // addresses in HTML text: src, poster, srcset, href of a download – quoted or in a srcset list
                return (string) preg_replace_callback('#(["\'\s,])' . self::MEDIA_PATH . '(?=["\'\s,>])#', function (array $m) use (&$count, $https, $site): string {
                    $count++;

                    return $m[1] . ($https ? $site . '/' . $m[2] : '');
                }, $v);
            }

            return $v;
        };
        foreach ($elements as $i => $p) {
            foreach (['obsah', 'styl'] as $key) {
                if (is_array($p[$key] ?? null)) {
                    $p[$key] = $value($p[$key]);
                }
            }
            if (is_array($p['deti'] ?? null)) {
                $p['deti'] = self::relinkMedia(array_values($p['deti']), $site, $count);
            }
            $elements[$i] = $p;
        }

        return $elements;
    }

    /**
     * Component uses without a component: the other site's component ids are not this site's, and only an administrator
     * can bring the components along.
     *
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    private static function detachComponents(array $elements): array
    {
        foreach ($elements as $i => $p) {
            if (($p['typ'] ?? '') === 'komponenta' && is_array($p['obsah'] ?? null)) {
                $p['obsah']['komponenta'] = '';
            }
            if (is_array($p['deti'] ?? null)) {
                $p['deti'] = self::detachComponents(array_values($p['deti']));
            }
            $elements[$i] = $p;
        }

        return $elements;
    }
}
