<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\Google;

/**
 * Enquiries to the connected services (2.13): a row in a Google sheet (EnquirySheet) and a lead in the CRM
 * (EnquiryCrm – HubSpot, Pipedrive, Raynet). Front\Forms calls enquiryReceived() after the insert; every destination
 * whose switch is on gets the enquiry through the connector queue (Core\Connectors::queue) – never inline, the visitor
 * must not wait for an outside service, and a failure is retried and ends as a connector.failed event.
 *
 * The mapping is by field type (lead()): the form's e-mail field, the first text field labelled like a name, the phone
 * field, a company field, and the rest as plain text for the note. Job applications (Core\Jobs sources) carry CVs and
 * go out only when the administrator ticks it – and then only the file name, never the file (Forms stores it that way).
 */
final class EnquiryDelivery
{
    /** Settings every destination of enquiries has (Connector::settings()); the switch and the forms are checked here. */
    public const array SETTINGS = [
        'enquiries' => ['Send enquiries here', '', 'check'],
        'enquiry_forms' => ['Only these forms', 'Form names separated by commas; empty = every form'],
        'enquiry_jobs' => ['Include job applications', 'They carry CVs – only the file name goes out, never the file', 'check'],
    ];

    /** The queue action of a service's deliveries; a CRM unless listed. */
    private const array ACTIONS = [Google::KEY => 'sheets.append'];

    /** A text field labelled like this holds the sender's name (the site languages). */
    private const string NAME_LABEL = '/(^|[^\p{L}])(name|vorname|nachname|jm[eé]no|meno|imi[eę]|nom|nombre|nome)([^\p{L}]|$)/iu';
    private const string COMPANY_LABEL = '/(^|[^\p{L}])(company|firma|firm|spole[cč]nost|spoločnosť|soci[eé]t[eé]|empresa|azienda|unternehmen)([^\p{L}]|$)/iu';

    /**
     * Queues the enquiry for every connected destination that wants it. Returns how many were queued.
     *
     * @param list<array{0: string, 1: string, 2: string}> $fields label, value, field type (fields())
     */
    public static function enquiryReceived(App $app, int $idp, string $source, string $form, string $topic, string $email, string $page, array $fields): int
    {
        if (Demo::active()) {
            return 0; // the public demo calls no other servers
        }
        $db = $app->db();
        $application = in_array($source, Jobs::sources($db), true);
        $queued = 0;
        foreach (Connectors::SERVICES as $class) {
            $config = Connectors::config($db, $class::KEY);
            if (($config['enquiries'] ?? '') !== '1' || !self::formWanted($config['enquiry_forms'] ?? '', $form) || ($application && ($config['enquiry_jobs'] ?? '') !== '1') || !Connectors::isConnected($db, $class::KEY)) {
                continue;
            }
            $action = self::ACTIONS[$class::KEY] ?? 'crm.lead';
            if ($action === 'sheets.append' && ($config['sheet_id'] ?? '') === '') {
                continue; // the sheet is created by the administrator; the screen says so
            }
            Connectors::queue($db, $action, ['service' => $class::KEY, 'enquiry' => $idp, 'date' => date('Y-m-d H:i'), 'form' => $form, 'topic' => $topic,
                'email' => $email, 'page' => $app->request->origin() . $page, 'fields' => $fields]);
            $queued++;
        }

        return $queued;
    }

    /** Does the destination take this form? An empty list means every form. */
    public static function formWanted(string $list, string $form): bool
    {
        $wanted = array_filter(array_map(fn (string $n): string => mb_strtolower(trim($n)), explode(',', $list)), fn (string $n): bool => $n !== '');

        return $wanted === [] || in_array(mb_strtolower(trim($form)), $wanted, true);
    }

    /**
     * The enquiry's fields with their types, from what Forms stores ([label, value, attachment path?]) and the types it
     * collected in the same order. The attachment path never leaves the server.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $data
     * @param list<string> $types
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function fields(array $data, array $types): array
    {
        return array_map(fn (int $i, array $d): array => [(string) $d[0], (string) $d[1], $types[$i] ?? 'text'], array_keys($data), $data);
    }

    /**
     * The lead mapped by field type: the e-mail, the phone, the first text field labelled like a name (otherwise the first
     * text field), a company field, and the rest as "Label: value" lines – without the consent tick.
     *
     * @param list<array{0: string, 1: string, 2: string}> $fields
     * @return array{name: string, email: string, phone: string, company: string, text: string}
     */
    public static function lead(array $fields, string $email = ''): array
    {
        $lead = ['name' => '', 'email' => $email, 'phone' => '', 'company' => '', 'text' => ''];
        $used = [];
        $firstText = null;
        foreach ($fields as $i => [$label, $value, $type]) {
            if ($value === '') {
                continue;
            }
            if ($type === 'email' && ($lead['email'] === '' || $lead['email'] === $value)) {
                $lead['email'] = $value;
                $used[$i] = true;
            } elseif ($type === 'tel' && $lead['phone'] === '') {
                $lead['phone'] = $value;
                $used[$i] = true;
            } elseif ($type === 'text' && $lead['company'] === '' && preg_match(self::COMPANY_LABEL, $label) === 1) {
                $lead['company'] = $value;
                $used[$i] = true;
            } elseif ($type === 'text' && $lead['name'] === '' && preg_match(self::NAME_LABEL, $label) === 1) {
                $lead['name'] = $value;
                $used[$i] = true;
            } elseif ($type === 'text' && $firstText === null) {
                $firstText = $i;
            }
        }
        if ($lead['name'] === '' && $firstText !== null) {
            $lead['name'] = $fields[$firstText][1];
            $used[$firstText] = true;
        }
        $lines = [];
        foreach ($fields as $i => [$label, $value, $type]) {
            if (!isset($used[$i]) && $value !== '' && $type !== 'souhlas') {
                $lines[] = $label . ': ' . $value;
            }
        }
        $lead['text'] = implode("\n", $lines);

        return $lead;
    }

    /** "Jan Novák" → ["Jan", "Novák"]; one word is the last name. @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $last = (string) array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /** "<form> – <topic>", the title of a lead. @param array<string, mixed> $payload */
    public static function title(array $payload): string
    {
        return mb_substr((string) ($payload['form'] ?? '') . (($payload['topic'] ?? '') !== '' ? ' – ' . $payload['topic'] : ''), 0, 200);
    }

    /**
     * The enquiry as the text of a note: where it came from, then the fields the mapping did not place. The visitor
     * wrote it – a CRM user must read it as data.
     *
     * @param array<string, mixed> $payload
     */
    public static function note(array $payload, string $text): string
    {
        $head = [t('Form') . ': ' . ($payload['form'] ?? ''), t('Page') . ': ' . ($payload['page'] ?? ''), t('Date') . ': ' . ($payload['date'] ?? '')];
        if (($payload['topic'] ?? '') !== '') {
            array_splice($head, 1, 0, [t('Topic') . ': ' . $payload['topic']]);
        }

        return mb_substr(implode("\n", $head) . ($text !== '' ? "\n\n" . $text : ''), 0, 20000);
    }

    /** The last error of a service for the Connections screen ('' = the last delivery went through). */
    public static function report(Db $db, string $service, string $error): void
    {
        $db->update('connectors', ['last_error' => mb_substr($error, 0, 255)], ['service' => $service]);
    }
}
