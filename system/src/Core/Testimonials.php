<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Presets;

/**
 * Testimonial requests with consent (2.12): after a won enquiry the site sends the customer a personal link; what they
 * write – their words, their name and role, a photo if they want – arrives as a hidden draft item of the References
 * collection, together with the consent they ticked and when. Nothing is published without the administrator.
 *
 *  - The link is /_testimonial/<token>: 32 random hex characters, valid for 30 days and once; only a hash is stored.
 *  - Consent is explicit and separate for the words with the name and for the photo; the texts the customer saw are kept
 *    with the request, so the site can show what was agreed.
 *  - The References collection (preset references) is created when the site has none.
 */
final class Testimonials
{
    public const string PRESET = 'references';

    public const int DAYS = 30;

    public const int MAX_PHOTO = 8 * 1024 * 1024;

    /**
     * Creates a request for an enquiry with an e-mail address; sends the e-mail when asked. Returns the link (absolute) and
     * whether the e-mail went out.
     *
     * @return array{link: string, sent: bool}
     * @throws \DomainException when the enquiry has no e-mail address
     */
    public static function request(App $app, int $idp, bool $send): array
    {
        $db = $app->db();
        $enquiry = $db->one('SELECT idp, email FROM {poptavky} WHERE idp = ?', [$idp]);
        if ($enquiry === null || filter_var((string) $enquiry['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('The enquiry has no e-mail address to send the request to.');
        }
        $token = bin2hex(random_bytes(16));
        $db->insert('testimonial_requests', ['idp' => $idp, 'token_hash' => hash('sha256', $token), 'email' => (string) $enquiry['email'],
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + self::DAYS * 86400)]);
        $link = Mailing::absolute($app, '_testimonial/' . $token);
        $sent = false;
        if ($send) {
            $site = $app->settings()->get('site_name');
            // in the language of the site the customer wrote to, not of the admin who clicked
            [$subject, $text] = Language::runWith($app->settings()->get('site_language') ?: 'cs', fn (): array => [t('Would you share your experience with %s?', $site),
                t("Hello,\n\nthank you for working with us. Would you write a few words about your experience? It takes a minute, and we publish it only with your consent:\n\n%s\n\nThe link works for %d days.\n\n%s", $link, self::DAYS, $site)]);
            $sent = Mail::send($app->settings(), (string) $enquiry['email'], $subject, $text);
        }
        \Kaleta\Admin\ChangeLog::write($app, 'enquiries', 'testimonial_request', '#' . $idp . ($sent ? ' (e-mail)' : ''));

        return ['link' => $link, 'sent' => $sent];
    }

    /** The open request of a token from a link: not used, not expired; null otherwise. @return array<string, mixed>|null */
    public static function find(Db $db, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return null;
        }

        return $db->one('SELECT * FROM {testimonial_requests} WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), date('Y-m-d H:i:s')]);
    }

    /** The two consents, as the customer sees them. @return array{words: string, photo: string} */
    public static function consents(string $site): array
    {
        return [
            'words' => t('I agree that %s may publish my words with my name and role on its website.', $site),
            'photo' => t('I agree that %s may publish the photo I uploaded next to them.', $site),
        ];
    }

    /**
     * What the customer sent, checked: the words (required, plain text), the name (required), the role and the consents.
     *
     * @return array{text: string, name: string, role: string, words: bool, photo: bool}|string the clean answer or an error for the customer
     */
    public static function clean(array $input): array|string
    {
        $line = fn (string $key, int $max): string => mb_substr(trim(strip_tags(str_replace(["\r", "\n"], ' ', is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''))), 0, $max);
        $text = mb_substr(trim(strip_tags(str_replace("\r\n", "\n", is_scalar($input['text'] ?? null) ? (string) $input['text'] : ''))), 0, 3000);
        $answer = ['text' => $text, 'name' => $line('name', 120), 'role' => $line('role', 160), 'words' => ($input['consent_words'] ?? '') === '1', 'photo' => ($input['consent_photo'] ?? '') === '1'];

        return match (true) {
            mb_strlen($text) < 10 => t('Please write a few words.'),
            $answer['name'] === '' => t('Please fill in your name.'),
            !$answer['words'] => t('We can publish your words only with your consent.'),
            default => $answer,
        };
    }

    /**
     * The References collection the answers land in (the first collection made from the references preset), or null when the
     * site has none yet – the enquiry detail then offers to create it first (3.20, C16).
     *
     * @return array<string, mixed>|null
     */
    public static function collection(Db $db): ?array
    {
        return $db->one('SELECT * FROM {kolekce} WHERE preset = ? ORDER BY idk LIMIT 1', [self::PRESET]);
    }

    /**
     * Saves the answer: the photo (only with its consent) into Media, a hidden draft item in References, the consents with
     * the request, and marks the link used. Returns the item id.
     *
     * @param array{text: string, name: string, role: string, words: bool, photo: bool} $answer
     * @param array<string, mixed>|null $photo an item of $_FILES
     */
    public static function save(App $app, array $request, array $answer, ?array $photo): int
    {
        $db = $app->db();
        $collection = self::collection($db);
        $idk = $collection !== null ? (int) $collection['idk'] : (int) Presets::create($app, self::PRESET);
        $collection = (array) Collections::byId($db, $idk);
        $image = '';
        if ($answer['photo'] && $photo !== null && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int) ($photo['size'] ?? 0) <= self::MAX_PHOTO) {
            try {
                $saved = Images::save($photo);
                $saved['nazev'] = $answer['name'];
                $db->insert('media', $saved + ['vlastnik' => 0, 'datum' => date('Y-m-d H:i:s')]);
                $image = $saved['obr_poloha'];
            } catch (\RuntimeException) {
                $image = ''; // a photo that cannot be read is left out; the words still arrive
            }
        }
        $keys = array_column((array) $collection['pole'], 'typ', 'klic');
        $data = array_filter([
            'quote' => isset($keys['quote']) ? $answer['text'] : null,
            'client' => isset($keys['client']) ? trim($answer['name'] . ($answer['role'] !== '' ? ', ' . $answer['role'] : '')) : null,
            'image' => isset($keys['image']) && $image !== '' ? $image : null,
        ], fn (?string $v): bool => $v !== null);
        $consents = self::consents((string) $app->settings()->get('site_name'));
        $itemId = $db->insert('kolekce_polozky', ['idk' => $idk, 'nazev' => mb_substr($answer['name'], 0, 200), 'seo_link' => self::slug($db, $idk, $answer['name']),
            'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zobrazit' => 0, 'jazyk' => '', 'datum' => date('Y-m-d H:i:s'), 'poradi' => 0]);
        $db->update('testimonial_requests', ['used_at' => date('Y-m-d H:i:s'), 'item_id' => $itemId,
            'consent' => $consents['words'] . ($answer['photo'] && $image !== '' ? "\n" . $consents['photo'] : '')], ['id' => (int) $request['id']]);
        Events::record($db, 'testimonial.received', 'info', t('A testimonial arrived – a hidden draft in %s.', (string) $collection['nazev']), ['item' => $itemId]);

        return $itemId;
    }

    private static function slug(Db $db, int $idk, string $name): string
    {
        return Slug::makeUnique(slugify($name !== '' ? $name : 'reference', 150) ?: 'reference', fn (string $s): bool => $db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?', [$idk, $s]) !== null
            || \Kaleta\Builder\CollectionCategories::slugIsCategory($db, $idk, $s), 160); // never a category's address (3.7, N37-8)
    }

    /** The requests of an enquiry for its detail: when, to whom, whether it came back and the draft it made. @return list<array<string, mixed>> */
    public static function ofEnquiry(Db $db, int $idp): array
    {
        return $db->all('SELECT id, email, created_at, expires_at, used_at, item_id FROM {testimonial_requests} WHERE idp = ? ORDER BY id DESC', [$idp]);
    }
}
