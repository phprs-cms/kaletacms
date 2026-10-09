<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Images;
use Kaleta\Core\Settings;

/**
 * Reading news for the site (table ka_novinky). The site shows only a published news item (visible = 1) whose publish
 * date has already come.
 */
final class NewsRepository
{
    private const string SELECT = "
        SELECT c.*, t.nazev AS tema_jm, t.seo_link AS tema_seo,
               NULLIF(u.jmeno, '') AS autor_jm, -- the sign-in name is never shown on the site; without a filled-in name no author is printed
               u.pozice AS autor_pozice, u.foto AS autor_foto, u.bio AS autor_bio, u.url AS autor_url
        FROM {novinky} c
        JOIN {kategorie} t ON t.idt = c.tema
        LEFT JOIN {uzivatele} u ON u.idu = c.autor";

    /**
     * Columns for listings: without the long texts (text, FAQ) that a listing does not print. The keys stay in the array
     * (empty) so that templates do not break. A new ka_novinky column that should be visible in listings must be added here too.
     */
    private const string LIST_COLUMNS = "c.idc, c.seo_link, c.titulek, c.uvod, '' AS text, c.obrazek, c.tema, c.autor, c.datum, c.visible, c.t_slova, c.noindex, '' AS faq, c.visit,
        c.zmeneno, c.aktualizovano, c.jazyk, c.preklad_z";

    private const string PUBLISHED = 'c.visible = 1 AND c.datum <= NOW()';

    /** Condition "published news item in the language of the currently shown site version". */
    private readonly string $published;

    /** @param string $base path to the installation ("" or "/web") - prepended to URLs of images from media/ */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base = '')
    {
        $this->published = self::PUBLISHED . " AND c.jazyk = '" . \Kaleta\Core\Language::siteColumn() . "'";
    }

    /**
     * Adjusts a news item before it is passed to the template: the URL of the main image from media/ gets the installation path.
     *
     * @param array<string, mixed> $newsItem
     * @return array<string, mixed>
     */
    private function prepare(array $newsItem): array
    {
        if ($newsItem['obrazek'] !== '' && !preg_match('#^(https?:)?/#', $newsItem['obrazek'])) {
            $newsItem['obrazek'] = $this->base . '/' . $newsItem['obrazek'];
        }
        // responsive images: the main image and images in the text get a srcset from the variants created on upload
        $newsItem['obrazek_srcset'] = Images::srcset(ltrim(substr($newsItem['obrazek'], strlen($this->base)), '/'), $this->base);
        foreach (['uvod', 'text'] as $part) {
            if (str_contains($newsItem[$part], 'media/')) {
                $newsItem[$part] = preg_replace_callback('#<img\b(?![^>]*\bsrcset=)([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[^"]+))"#i', function (array $m): string {
                    $srcset = Images::srcset($m[3], $this->base);

                    return $srcset === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '" srcset="' . e($srcset) . '" sizes="(max-width: 800px) 100vw, 800px"';
                }, $newsItem[$part]) ?? $newsItem[$part];
            }
        }

        return $newsItem;
    }

    public function perPage(): int
    {
        return max(1, $this->settings->int('news_per_page'));
    }

    /**
     * Published news, newest first.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} news and their total count
     */
    public function listPublished(int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        return $this->query($this->published, [], 'c.datum DESC, c.idc DESC', $pageNumber, $limit, $withText);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function inCategory(int $idt, int $pageNumber, ?int $limit = null): array
    {
        return $this->query($this->published . ' AND c.tema = ?', [$idt], 'c.datum DESC, c.idc DESC', $pageNumber, $limit);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function withTag(int $ids, int $pageNumber): array
    {
        return $this->query($this->published . ' AND EXISTS (SELECT 1 FROM {novinky_stitky} cs WHERE cs.idc = c.idc AND cs.ids = ?)', [$ids], 'c.datum DESC, c.idc DESC', $pageNumber);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function search(string $q, int $pageNumber): array
    {
        // index without diacritics (Core\Search): "nabrezi" finds "nábřeží"; short words and parts of words are searched in the title
        \Kaleta\Core\Search::complete($this->db); // news from before the index are filled in automatically
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $query = \Kaleta\Core\Search::query($q);
        if ($query === '') {
            return $this->query($this->published . ' AND c.titulek LIKE ?', [$like], 'c.datum DESC, c.idc DESC', $pageNumber);
        }

        return $this->query(
            $this->published . ' AND (MATCH(c.hledani) AGAINST (? IN BOOLEAN MODE) OR c.titulek LIKE ?)',
            [$query, $like],
            'c.datum DESC, c.idc DESC',
            $pageNumber,
        );
    }

    /** @return array<string, mixed>|null */
    public function bySlug(string $seo, bool $includeUnpublished = false): ?array
    {
        // with slugs per language (3.9) /news/x and /en/news/x may be two news items: the one of the version asked for
        // comes first; one of another version still answers there (Front\Kernel redirects to its own version)
        $newsItem = $this->db->one(self::SELECT . ' WHERE c.seo_link = ? AND c.smazano IS NULL' . ($includeUnpublished ? '' : ' AND ' . self::PUBLISHED) . ' ORDER BY c.jazyk = ? DESC LIMIT 1',
            [$seo, \Kaleta\Core\Language::siteColumn()]);
        if ($newsItem === null) {
            return null;
        }
        // caption, author and alt of the main image: from the news item, otherwise from the media library
        $library = $newsItem['obrazek'] !== '' && !preg_match('#^(https?:)?//#', $newsItem['obrazek'])
            ? $this->db->one('SELECT nazev, popis, autor FROM {media} WHERE obr_poloha = ? LIMIT 1', [ltrim($newsItem['obrazek'], '/')]) : null;
        $description = $newsItem['obrazek_popis'] !== '' ? $newsItem['obrazek_popis'] : (string) ($library['popis'] ?? '');
        $author = $newsItem['obrazek_autor'] !== '' ? $newsItem['obrazek_autor'] : (string) ($library['autor'] ?? '');
        $newsItem['obrazek_alt'] = (string) ($library['nazev'] ?? '') !== '' ? (string) $library['nazev'] : $description;
        $parts = array_filter([e($description), $author !== '' ? '<span class="clanek-foto-autor">' . e(t('Photo: %s', $author)) . '</span>' : '']);
        $newsItem['obrazek_popisek_html'] = $parts === [] ? '' : '<figcaption class="clanek-popisek">' . implode(' ', $parts) . '</figcaption>';

        return $this->prepare($newsItem);
    }

    /**
     * Similar news: first by the number of shared tags, then newer ones from the same category.
     *
     * @param array<string, mixed> $newsItem
     * @return list<array<string, mixed>>
     */
    public function similar(array $newsItem, int $count = 4): array
    {
        return $this->db->all(
            'SELECT c.titulek, c.seo_link, c.datum, COUNT(cs.ids) AS shoda
             FROM {novinky} c LEFT JOIN {novinky_stitky} cs ON cs.idc = c.idc AND cs.ids IN (SELECT ids FROM {novinky_stitky} WHERE idc = ?)
             WHERE ' . $this->published . ' AND c.idc <> ? AND (c.tema = ? OR cs.ids IS NOT NULL) AND c.datum > NOW() - INTERVAL 2 YEAR
             GROUP BY c.idc, c.titulek, c.seo_link, c.datum ORDER BY shoda DESC, c.datum DESC LIMIT ?',
            [$newsItem['idc'], $newsItem['idc'], $newsItem['tema'], $count],
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function query(string $where, array $params, string $order, int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        // fixed count (RSS, feeds, API) = nobody paginates, the total count is not computed
        $total = $limit !== null ? 0 : (int) $this->db->value("SELECT COUNT(*) FROM {novinky} c WHERE {$where}", $params);
        $limit ??= $this->perPage();
        $pageNumber = max(1, min($pageNumber, 100000));
        $newsItems = $this->db->all(
            ($withText ? self::SELECT : str_replace('SELECT c.*,', 'SELECT ' . self::LIST_COLUMNS . ',', self::SELECT)) . " WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $limit, ($pageNumber - 1) * $limit],
        );

        return [array_map($this->prepare(...), $newsItems), $total];
    }
}
