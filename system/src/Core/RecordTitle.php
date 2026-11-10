<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The display title of a record inside the administration (3.32, U27/U41/R2): who and what instead of "Enquiry #3 · Contact"
 * or "Deal #2". One place builds it for the enquiry list, My work, tasks, the bell and the default title of a deal made from
 * an enquiry.
 *
 *  - An enquiry: "<who> – <what>" – who = the person's name from the form (Contacts::fromFields), else the company field,
 *    else the e-mail; what = the topic of a collection item (a product, a service – specific), else the first WORDS words of
 *    the customer's message (EnquiryThread::message), else the topic (a page or pop-up title such as "Contact"). Never just
 *    the form's name unless nothing else is known. An anonymised enquiry has no person – its topic or form name.
 *  - A place of Core\Inbox::places carries 'name' (the enquiry's title, the deal's title, the customer's name); place()
 *    shows it only to a viewer who may open the record's section (Assignment::KINDS) – anyone else, and every e-mail, gets
 *    Inbox::where(): an enquiry by its number and form, a deal by its number only, a customer by number (3.31.1 rules).
 *
 * Pure apart from the dictionaries.
 */
final class RecordTitle
{
    /** How many words of the customer's message a title takes. */
    public const int WORDS = 6;

    /** The longest title (a deal title holds 200). */
    public const int MAX = 160;

    /**
     * The display title of an enquiry from its row (formular, tema, zdroj, email, data, anonymizovano – missing keys are
     * empty): "Olivia Hart – We need 40 custom brackets…". The form's name ("Enquiry") only when nothing else is known.
     *
     * @param array<string, mixed> $row a row of ka_poptavky
     */
    public static function enquiry(array $row): string
    {
        $who = self::enquiryWho($row);
        $what = self::enquiryWhat($row);
        $title = $who !== '' && $what !== '' ? $who . ' – ' . $what : ($who !== '' ? $who : $what);
        if ($title === '') {
            $form = self::line((string) ($row['formular'] ?? ''));
            $title = $form !== '' ? $form : t('Enquiry');
        }

        return self::cut($title, self::MAX);
    }

    /**
     * Who wrote an enquiry: the name field, else the company field, else the e-mail; '' for an anonymised one or none.
     *
     * @param array<string, mixed> $row
     */
    public static function enquiryWho(array $row): string
    {
        if (($row['anonymizovano'] ?? null) !== null) {
            return '';
        }
        $people = self::enquiryPeople($row);
        $who = $people['name'] !== '' ? $people['name'] : ($people['company'] !== '' ? $people['company'] : $people['email']);

        return self::cut($who, 80);
    }

    /**
     * Who an enquiry names, each part on its own (3.32 – also what Deals::neutralTitle looks for in a deal's title once
     * the enquiry goes): the name from the form, the company field (when it is short enough to be a name) and the e-mail;
     * '' for a part not known. Reads the row as it is – also one already anonymised (then it is empty).
     *
     * @param array<string, mixed> $row
     * @return array{name: string, company: string, email: string}
     */
    public static function enquiryPeople(array $row): array
    {
        $email = self::line(trim((string) ($row['email'] ?? '')));
        $fields = self::fields($row);
        $name = self::line(Contacts::fromFields($fields, $email)['name']);
        $company = '';
        foreach ($fields as $field) {
            if (is_array($field) && !isset($field[2]) && is_scalar($field[0] ?? null) && is_scalar($field[1] ?? null)
                && preg_match(EnquiryDelivery::COMPANY_LABEL, (string) $field[0]) === 1) {
                $value = self::line((string) $field[1]);
                if ($value !== '' && mb_strlen($value) <= 100) {
                    $company = $value;
                    break;
                }
            }
        }

        return ['name' => $name, 'company' => $company, 'email' => $email];
    }

    /**
     * The first WORDS words of the customer's message ("We need 40 custom brackets for…"), '' when there is none or the
     * enquiry is anonymised. The message is personal; the topic of a collection item is not.
     *
     * @param array<string, mixed> $row
     */
    public static function enquiryExcerpt(array $row): string
    {
        if (($row['anonymizovano'] ?? null) !== null) {
            return '';
        }
        // the name or company is never the message (a "Hartsteel Ltd" field reads like a short sentence)
        $who = self::enquiryWho($row);
        $fields = array_values(array_filter(self::fields($row), fn (mixed $f): bool => !is_array($f) || !is_scalar($f[1] ?? null)
            || (self::line((string) $f[1]) !== $who && preg_match('/\p{L}{2}/u', (string) $f[1]) === 1))); // a phone number is no message
        $message = self::line(EnquiryThread::message($fields, trim((string) ($row['email'] ?? ''))));
        if ($message === '') {
            return '';
        }
        $words = preg_split('/\s+/u', $message) ?: [];
        $first = implode(' ', array_slice($words, 0, self::WORDS));

        return self::cut($first, 100) . (count($words) > self::WORDS && mb_strlen($first) <= 100 ? '…' : '');
    }

    /**
     * What an enquiry is about: the topic of a collection item, else the first WORDS words of the message, else the topic.
     *
     * @param array<string, mixed> $row
     */
    public static function enquiryWhat(array $row): string
    {
        $topic = self::line((string) ($row['tema'] ?? ''));
        if ($topic !== '' && str_starts_with((string) ($row['zdroj'] ?? ''), 'kolekce:')) {
            return self::cut($topic, 100);
        }
        $excerpt = self::enquiryExcerpt($row);

        return $excerpt !== '' ? $excerpt : self::cut($topic, 100);
    }

    /**
     * The topic of an enquiry on its own – a collection item, a page or pop-up title; never personal. '' when none.
     *
     * @param array<string, mixed> $row
     */
    public static function enquiryTopic(array $row): string
    {
        return self::cut(self::line((string) ($row['tema'] ?? '')), 100);
    }

    /**
     * How a place of Inbox::places reads in the administration for a viewer who may open $sections: its name for a viewer
     * with the record's section ("Enquiry: Olivia Hart – …", "Deal “Maintenance contract”", "Customer “Olivia Hart”"),
     * otherwise as the e-mails name it (Inbox::where – a deal by its number only).
     *
     * @param array<string, mixed>|null $place
     * @param array<string, mixed> $sections Admin\Kernel::modules() (ident => class) of the viewer
     */
    public static function place(?array $place, array $sections): string
    {
        $kind = (string) ($place['kind'] ?? '');
        $name = trim((string) ($place['name'] ?? ''));
        $section = Assignment::KINDS[$kind] ?? null;
        if ($place === null || $name === '' || $section === null || !isset($sections[$section])) {
            return Inbox::where($place);
        }

        return match ($kind) {
            'enquiry' => t('Enquiry: %s', $name),
            'deal' => t('Deal “%s”', $name),
            'contact' => t('Customer “%s”', $name),
            default => Inbox::where($place),
        };
    }

    /** @return list<mixed> the stored form fields of an enquiry row */
    private static function fields(array $row): array
    {
        $data = is_array($row['data'] ?? null) ? $row['data'] : json_decode((string) ($row['data'] ?? ''), true);

        return is_array($data) ? array_values($data) : [];
    }

    /** One line: whitespace folded, control characters out. */
    private static function line(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) preg_replace('/\s+/u', ' ', $text)));
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
