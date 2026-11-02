<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The conversation of one enquiry (3.25), computed and never stored: the customer's message, the replies e-mailed from the
 * enquiry (ka_poptavky.replies, Core\EnquiryReply) and the team's internal notes (Core\Comments) in the order of time.
 * Pure – the detail screen renders it and the unit test checks it.
 */
final class EnquiryThread
{
    /**
     * The customer's message among the form fields: the longest value that reads like a sentence (several words, or
     * several lines) – not an attachment, not the sender's address, not a single word such as a phone number or a date.
     * '' when the form carries no such text (a call-back form with a name and a phone).
     *
     * @param list<mixed> $data the form data of the enquiry: [[label, value, attachment?], …]
     */
    public static function message(array $data, string $email = ''): string
    {
        $best = '';
        foreach ($data as $field) {
            if (!is_array($field) || isset($field[2]) || !is_string($field[1] ?? null)) {
                continue;
            }
            $value = trim($field[1]);
            $sentence = str_contains($value, "\n") || (mb_strlen($value) >= 12 && preg_match('/\S\s+\S/u', $value) === 1);
            if ($sentence && $value !== $email && mb_strlen($value) > mb_strlen($best)) {
                $best = $value;
            }
        }

        return $best;
    }

    /**
     * The entries in the order of time (an older entry first; at the same time the customer first, then as written).
     *
     * @param array<string, mixed> $enquiry a row of ka_poptavky (datum, email, data)
     * @param list<array<string, mixed>> $replies EnquiryReply::history()
     * @param list<array<string, mixed>> $notes Comments::of()
     * @return list<array{type: 'customer'|'reply'|'note', at: string, entry: array<string, mixed>}>
     */
    public static function entries(array $enquiry, array $replies, array $notes): array
    {
        $data = json_decode((string) ($enquiry['data'] ?? ''), true);
        $entries = [['type' => 'customer', 'at' => (string) ($enquiry['datum'] ?? ''), 'entry' => ['from' => (string) ($enquiry['email'] ?? ''),
            'text' => self::message(is_array($data) ? array_values($data) : [], (string) ($enquiry['email'] ?? ''))]]];
        foreach ($replies as $r) {
            $entries[] = ['type' => 'reply', 'at' => (string) ($r['at'] ?? ''), 'entry' => $r];
        }
        foreach ($notes as $n) {
            $entries[] = ['type' => 'note', 'at' => (string) ($n['at'] ?? ''), 'entry' => $n];
        }
        $order = array_keys($entries);
        array_multisort(array_column($entries, 'at'), SORT_ASC, SORT_STRING, $order, SORT_ASC, $entries);

        return $entries;
    }
}
