<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\Google;

/**
 * Enquiries in a Google sheet (2.13, the Google connection with the drive.file scope – only files the site created):
 * the administrator clicks "Create the sheet" in Connections, the spreadsheet id is kept in the connection's settings,
 * and every enquiry appends a row (the queue action sheets.append, EnquiryDelivery). Columns: date, form, topic, e-mail,
 * page, name, phone, then every other field as "Label: value" in its own cell.
 */
final class EnquirySheet
{
    public const string API = 'https://sheets.googleapis.com/v4/spreadsheets';
    public const array COLUMNS = ['Date', 'Form', 'Topic', 'E-mail', 'Page', 'Name', 'Phone', 'Fields'];

    /** The spreadsheet with its title and the header row, in one call. @param list<string> $header @return array<string, mixed> */
    public static function createBody(string $title, array $header): array
    {
        return ['properties' => ['title' => $title], 'sheets' => [['properties' => ['title' => $header[1] ?? 'Enquiries', 'gridProperties' => ['frozenRowCount' => 1]],
            'data' => [['startRow' => 0, 'startColumn' => 0, 'rowData' => [['values' => array_map(fn (string $h): array => ['userEnteredValue' => ['stringValue' => $h], 'userEnteredFormat' => ['textFormat' => ['bold' => true]]], $header)]]]]]]];
    }

    /** One enquiry as a row. @param array<string, mixed> $payload @return list<string> */
    public static function row(array $payload): array
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $lead = EnquiryDelivery::lead($fields, (string) ($payload['email'] ?? ''));
        $row = [(string) ($payload['date'] ?? ''), (string) ($payload['form'] ?? ''), (string) ($payload['topic'] ?? ''), $lead['email'], (string) ($payload['page'] ?? ''), $lead['name'], $lead['phone']];
        foreach ($lead['text'] !== '' ? explode("\n", $lead['text']) : [] as $line) {
            $row[] = mb_substr($line, 0, 5000);
        }

        return $row;
    }

    /** @param array<string, mixed> $payload @return array{values: list<list<string>>} */
    public static function appendBody(array $payload): array
    {
        return ['values' => [self::row($payload)]];
    }

    public static function url(string $id): string
    {
        return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id);
    }

    /** "Create the sheet": the spreadsheet "<site> – enquiries" with its header; its id goes to the settings. '' or the error. */
    public static function create(App $app): string
    {
        if (!Connectors::isConnected($app->db(), Google::KEY)) {
            return t('Connect Google first.');
        }
        $title = t('%s – enquiries', $app->settings()->get('site_name'));
        $answer = Connectors::request($app, Google::KEY, 'POST', self::API, self::createBody($title, array_map('t', self::COLUMNS)), [], 'sheets.create');
        $id = $answer['json']['spreadsheetId'] ?? null;
        if ($answer['error'] !== '' || !is_string($id) || $id === '') {
            return $answer['error'] !== '' ? $answer['error'] : t('Google did not return the sheet.');
        }
        Connectors::updateConfig($app->db(), Google::KEY, ['sheet_id' => $id]);
        \Kaleta\Admin\ChangeLog::write($app, 'connectors', 'sheet', Google::KEY);

        return '';
    }

    /** The queue handler (sheets.append): one row per enquiry. @param array<string, mixed> $payload */
    public static function deliver(App $app, string $action, array $payload): string
    {
        $id = Connectors::config($app->db(), Google::KEY)['sheet_id'] ?? '';
        if ($id === '') {
            return t('No sheet – create it in Administration → Integrations.');
        }
        $answer = Connectors::request($app, Google::KEY, 'POST', self::API . '/' . rawurlencode($id) . '/values/A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', self::appendBody($payload), [], 'sheets.append');
        EnquiryDelivery::report($app->db(), Google::KEY, $answer['error']);

        return $answer['error'];
    }
}
