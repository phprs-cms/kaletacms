<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Who sends the site's e-mails to visitors (3.51): the company name and postal address from Business details, written
 * once for the newsletter footer and the testimonial request – a commercial e-mail must name its sender. Front\Company
 * (the Company details element, structured data) reads the address from here too.
 */
final class SenderIdentity
{
    /** The postal address in lines (street; postcode and city; the country, only when it is not Czech). @return list<string> */
    public static function address(Settings $s): array
    {
        return array_values(array_filter([
            $s->get('company_street'),
            trim($s->get('company_postcode') . ' ' . $s->get('company_city')),
            $s->get('company_country') !== '' && $s->get('company_country') !== 'CZ' ? $s->get('company_country') : '',
        ], fn (string $r): bool => $r !== ''));
    }

    /**
     * The sender line in one language (the setting mail_identity filled with the company name and the address), or null
     * when the company name or the address is missing – then nothing that needs a named sender may go out.
     */
    public static function line(Settings $s, string $language): ?string
    {
        $company = trim($s->get('company_name'));
        $address = implode(', ', self::address($s));
        if ($company === '' || $address === '') {
            return null;
        }

        $line = strtr($s->wording('mail_identity', $language), ['{{company}}' => $company, '{{address}}' => $address, '{{registration}}' => Language::runWith($language !== '' ? $language : Language::defaults($s), fn (): string => self::registration($s))]);

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $line) ?? $line);
    }

    /**
     * 3.51: the company ID and the register entry (§ 435 of the Czech Civil Code: an entrepreneur names them in business
     * correspondence) as one sentence in the current language – "Company ID 12345678, Regional Court in Brno, C 1234." –
     * or '' while neither is filled in. In the current language – line() calls it in the language of the e-mail.
     */
    public static function registration(Settings $s): string
    {
        $parts = array_values(array_filter([
            trim($s->get('company_id')) !== '' ? t('Company ID') . ' ' . trim($s->get('company_id')) : '',
            trim($s->get('company_register')),
        ], fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return '';
        }
        // the punctuation of the language: full-width in Chinese, the Arabic comma in Arabic (3.51 review)
        [$comma, $stop] = match (Language::code()) {
            'zh', 'zh-hant' => ['，', '。'],
            'ar' => ['، ', '.'],
            default => [', ', '.'],
        };
        $sentence = implode($comma, $parts);

        return preg_match('/[.!?。]$/u', $sentence) === 1 ? $sentence : $sentence . $stop;
    }
}
