<?php

declare(strict_types=1);

/* ---------- 3.49 A: replies – saved replies, one sender, attachments ---------- */
use Kaleta\Core\ApplicationReplies as A449Applications;
use Kaleta\Core\EnquiryReply as A449Reply;
use Kaleta\Core\Mail as A449Mail;
use Kaleta\Core\QuoteDelivery as A449Quote;
use Kaleta\Core\ReplyAttachments as A449Files;
use Kaleta\Core\ReplyMail as A449ReplyMail;
use Kaleta\Core\Request as A449Request;
use Kaleta\Core\SavedReplies as A449Saved;

// ReplyMail::fill – only the keys given, any other {{x}} stays, an empty name takes its comma or space along
check('3.49 A: ReplyMail::fill replaces only the keys given and keeps any other {{x}}; an empty name takes its comma or space', [
    A449ReplyMail::fill('Dear {{name}}, about {{topic}} – {{company}} {{other}}', ['name' => ' Eva ', 'topic' => 'Windows', 'company' => 'Okna s.r.o.']),
    A449ReplyMail::fill('Hello {{name}}, thanks.', ['name' => '']), A449ReplyMail::fill('Hello, {{name}}!', ['name' => '  ']), A449ReplyMail::fill('{{name}}{{topic}}', ['topic' => 'T']),
    A449ReplyMail::fill('No placeholders', ['name' => 'X'])],
    ['Dear Eva, about Windows – Okna s.r.o. {{other}}', 'Hello, thanks.', 'Hello!', '{{name}}T', 'No placeholders']);

// ApplicationReplies::fill gives the results it always gave (the cases of 2160 and 2242)
check('3.49 A: ApplicationReplies::fill (now through ReplyMail::fill) gives the old results – the name trimmed, an empty one with its comma, position and company as they are', [
    A449Applications::fill('Dear {{name}}, thank you for applying for {{position}} at {{company}}.', ['name' => ' Jana ', 'position' => 'Welder', 'company' => 'Northfield']),
    A449Applications::fill("Dobrý den, {{name}},\n{{position}}", ['name' => '', 'position' => 'a position with us', 'company' => 'X']),
    A449Applications::fill('{{name}} {{position}} {{company}} {{unknown}}', ['name' => 'A', 'position' => ' B ', 'company' => 'C'])],
    ['Dear Jana, thank you for applying for Welder at Northfield.', "Dobrý den,\na position with us", 'A  B  C {{unknown}}']);

// SavedReplies::clean – trimmed to the limits, a subject on one line, removed and empty rows out, a name and a text needed, at most 30
$a449Clean = A449Saved::clean([['id' => '4', 'name' => "  Price\tlist  ", 'subject' => "Re:\n prices", 'text' => "a\r\nb "], ['name' => '', 'text' => ''], ['name' => 'Gone', 'text' => 'x', 'remove' => '1'],
    ['name' => str_repeat('n', 100), 'subject' => str_repeat('s', 300), 'text' => str_repeat('t', 6000)]]);
check('3.49 A: SavedReplies::clean – limits, one-line subject, removed and empty rows out, a name and a text needed, at most 30 per language', [
    $a449Clean[0], is_array($a449Clean) ? [mb_strlen($a449Clean[1]['name']), mb_strlen($a449Clean[1]['subject']), mb_strlen($a449Clean[1]['text'])] : null, count($a449Clean),
    A449Saved::clean([['name' => 'Only a name', 'text' => ' ']]), A449Saved::clean([['name' => '', 'text' => 'Only a text']]),
    is_string(A449Saved::clean(array_fill(0, 31, ['name' => 'N', 'text' => 'T']))), count((array) A449Saved::clean(array_fill(0, 30, ['name' => 'N', 'text' => 'T'])))],
    [['id' => 4, 'name' => 'Price list', 'subject' => 'Re:  prices', 'text' => "a\nb"], [80, 200, 5000], 2,
        'Every saved reply needs a name and a text.', 'Every saved reply needs a name and a text.', true, 30]);

// SavedReplies::languageOf – the prefix of the page when it is an additional language of the site, else the default ('')
$a449Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($a449Settings, ['site_language' => 'cs', 'additional_languages' => 'en,de', 'extensions' => 'jazyky']);
check('3.49 A: SavedReplies::languageOf – /en/… is en, an unknown or a switched-off prefix and an empty path are the default language (\'\')', [
    A449Saved::languageOf($a449Settings, ['stranka' => '/en/contact']), A449Saved::languageOf($a449Settings, ['stranka' => '/de']), A449Saved::languageOf($a449Settings, ['stranka' => '/fr/contact']),
    A449Saved::languageOf($a449Settings, ['stranka' => '/xx/page']), A449Saved::languageOf($a449Settings, ['stranka' => '']), A449Saved::languageOf($a449Settings, ['stranka' => '/kontakt'])],
    ['en', 'de', '', '', '', '']);

// get_email_signature: full rows while their JSON stays under 4,000 bytes, the rest as names with more: true
$a449All = ['' => [['id' => 1, 'language' => '', 'name' => 'Prices', 'subject' => 'Our prices', 'text' => 'Dear {{name}}, …'], ['id' => 2, 'language' => '', 'name' => 'Long', 'subject' => '', 'text' => str_repeat('x', 3990)]],
    'en' => [['id' => 3, 'language' => 'en', 'name' => 'Thanks', 'subject' => '', 'text' => 'Thank you']]];
$a449Claude = A449Saved::claudeRows($a449All, 'cs');
check('3.49 A: saved replies for Claude – full rows under 4,000 bytes of JSON, then names with more: true; the default language as its code', [
    $a449Claude, strlen((string) json_encode(array_values(array_filter($a449Claude, fn (array $r): bool => !isset($r['more']))), JSON_UNESCAPED_UNICODE)) <= 4000,
    A449Saved::claudeRows([], 'cs')],
    [[['name' => 'Prices', 'language' => 'cs', 'subject' => 'Our prices', 'text' => 'Dear {{name}}, …'], ['name' => 'Long', 'language' => 'cs', 'more' => true], ['name' => 'Thanks', 'language' => 'en', 'more' => true]], true, []]);

// ReplyAttachments::mailName – ASCII, the extension of the checked file kept
check('3.49 A: ReplyAttachments::mailName keeps .pdf for a Czech and a Cyrillic name, spaces become a dash, another extension stays in the name', [
    A449Files::mailName('Ceník.pdf', 'pdf'), A449Files::mailName('Прайс лист.pdf', 'pdf'), A449Files::mailName('Ceník služeb 2026.PDF', 'pdf'), A449Files::mailName('report', 'docx'),
    A449Files::mailName('../../etc/passwd.pdf', 'pdf')],
    ['Cenik.pdf', 'attachment.pdf', 'Cenik-sluzeb-2026.pdf', 'report.docx', 'passwd.pdf']);

// ReplyAttachments::collect – the refusals that need no database (uploads only)
$a449Dir = sys_get_temp_dir() . '/kaleta-a449-' . bin2hex(random_bytes(4));
mkdir($a449Dir);
$a449Pdf = $a449Dir . '/a.pdf';
file_put_contents($a449Pdf, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
$a449Big = $a449Dir . '/big.pdf';
$a449Handle = fopen($a449Big, 'w');
ftruncate($a449Handle, 5_000_001); // a sparse file: its size is known without reading it
fclose($a449Handle);
$a449Html = $a449Dir . '/fake.pdf';
file_put_contents($a449Html, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
$a449Svg = $a449Dir . '/d.svg';
file_put_contents($a449Svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
$a449Up = fn (string $path, string $name, int $error = UPLOAD_ERR_OK): array => ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => $error, 'size' => (int) @filesize($path)];
$a449App = new Kaleta\Core\App([]);
$a449Refusal = function (array $internal, array $uploads) use ($a449App): string {
    try {
        A449Files::collect($a449App, $internal, $uploads);
    } catch (\DomainException $e) {
        return $e->getMessage();
    }

    return 'collected';
};
$a449Ok = A449Files::collect($a449App, [], [$a449Up($a449Pdf, 'Ceník.pdf')]);
$a449Refusals = Kaleta\Core\Language::runWith('en', fn (): array => [
    $a449Refusal([1, 2, 3], [$a449Up($a449Pdf, 'a.pdf'), $a449Up($a449Pdf, 'b.pdf'), $a449Up($a449Pdf, 'c.pdf')]),
    str_starts_with($a449Refusal([], [$a449Up($a449Big, 'big.pdf')]), 'The attachments are too large together'), str_contains($a449Refusal([], [$a449Up($a449Big, 'big.pdf')]), 'big.pdf ('),
    $a449Refusal([], [$a449Up($a449Html, 'fake.pdf')]), $a449Refusal([], [$a449Up($a449Svg, 'd.svg')]), $a449Refusal([], [$a449Up($a449Pdf, 'x.pdf', UPLOAD_ERR_PARTIAL)])], 'admin-');
check('3.49 A: ReplyAttachments::collect – too many, too large by filesize (before a read), forbidden content, an uploaded SVG, a failed upload; a good PDF goes as Cenik.pdf, never queued', [
    ...$a449Refusals,
    [$a449Ok[0]['name'], $a449Ok[0]['type'], $a449Ok[0]['file'], $a449Ok[0]['queue'], strlen($a449Ok[0]['data'])], A449Files::history($a449Ok)],
    ['Up to 5 files can go with one e-mail.', true, true, 'fake.pdf: The file content does not match its extension.', 'An SVG drawing cannot be sent as an attachment: d.svg', 'The file x.pdf could not be uploaded.',
        ['Cenik.pdf', 'application/pdf', 0, false, filesize($a449Pdf)], [['name' => 'Cenik.pdf', 'size' => filesize($a449Pdf), 'file' => 0]]]);
array_map('unlink', glob($a449Dir . '/*') ?: []);
rmdir($a449Dir);

// Request::fileList – the nested shape of files[], one failed slot kept with its code, empty slots out; a single field is a list of one
$a449Request = new A449Request([], ['x' => '1'], ['REQUEST_METHOD' => 'POST'], ['files' => ['name' => ['a.pdf', '', 'b.pdf'], 'type' => ['application/pdf', '', ''], 'tmp_name' => ['/tmp/a', '', ''],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE], 'size' => [10, 0, 0]], 'one' => ['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/c', 'error' => 0, 'size' => 3],
    'deep' => ['name' => ['a' => ['b' => 'x']], 'error' => ['a' => ['b' => 0]]]]);
check('3.49 A: Request::fileList normalises files[] (one failed slot keeps its code, an empty slot is left out), a single field is a list of one, a deeper nesting and a missing field are none', [
    $a449Request->fileList('files'), $a449Request->fileList('one'), $a449Request->fileList('deep'), $a449Request->fileList('missing')],
    [[['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 10], ['name' => 'b.pdf', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]],
        [['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/c', 'error' => 0, 'size' => 3]], [], []]);

// a body over post_max_size: only a POST that lost both $_POST and $_FILES and announced more than the limit
$a449Limit = Kaleta\Core\Files::postLimit();
check('3.49 A: Request::bodyTooLarge – only an empty POST whose Content-Length passes post_max_size (the admin then names the size instead of "the form has expired")', [
    (new A449Request([], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) ($a449Limit + 1)]))->bodyTooLarge(),
    (new A449Request([], ['_csrf' => 'x'], ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) ($a449Limit + 1)]))->bodyTooLarge(),
    (new A449Request([], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) $a449Limit]))->bodyTooLarge(),
    (new A449Request([], [], ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => (string) ($a449Limit + 1)]))->bodyTooLarge(),
    (new A449Request([], [], [], []))->postRows('replies'), (new A449Request([], ['replies' => [['name' => 'A', 'deep' => ['x']], 'no', ['text' => 'B']]], []))->postRows('replies')],
    [$a449Limit > 0, false, false, false, [], [['name' => 'A'], ['text' => 'B']]]);

// Mail: an attachment marked queue => false keeps the mark (the queue writer leaves it out); other attachments are as before
$a449Mail = A449Mail::attachments([['name' => 'Ceník.pdf', 'type' => 'application/pdf', 'data' => 'x'], ['name' => 'b.pdf', 'type' => 'application/pdf', 'data' => 'y', 'queue' => false]]);
check('3.49 A: Mail::attachments keeps queue => false and nothing else changes for the other attachments; together over the limit it still throws', [
    $a449Mail, (function (): string {
        try {
            A449Mail::attachments([['name' => 'q.pdf', 'type' => 'application/pdf', 'data' => str_repeat('p', 600_000)], ['name' => 'f.pdf', 'type' => 'application/pdf', 'data' => str_repeat('f', 4_900_000), 'queue' => false]]);
        } catch (\InvalidArgumentException $e) {
            return 'too large';
        }
        return 'fits';
    })(), count(A449Mail::attachments([['name' => 'f.pdf', 'type' => 'application/pdf', 'data' => str_repeat('f', 4_900_000), 'queue' => false]]))],
    [[['name' => 'Cen-k.pdf', 'type' => 'application/pdf', 'data' => 'x'], ['name' => 'b.pdf', 'type' => 'application/pdf', 'data' => 'y', 'queue' => false]], 'too large', 1]);

// the reply history: decode keeps the files, anonymised empties them; a quote's history loses them when it is anonymised
$a449Stored = json_encode([['at' => '2026-11-20 10:00:00', 'by' => 'Eva', 'to' => 'a@example.com', 'subject' => 'S', 'text' => 'T', 'mail' => 3, 'status' => 'sent',
    'files' => [['name' => 'Cenik.pdf', 'size' => 10, 'file' => 7], ['name' => '', 'size' => 1], 'x']], ['at' => '2026-11-20 11:00:00', 'text' => 'Old one']]);
$a449Decoded = A449Reply::decode($a449Stored);
check('3.49 A: EnquiryReply::decode keeps the files (cleaned) and adds no key to a reply without them; anonymised() empties them with the text', [
    $a449Decoded[0]['files'], array_key_exists('files', $a449Decoded[1]), array_key_exists('files', A449Reply::decode(A449Reply::anonymised($a449Stored))[0]),
    A449Reply::decode(A449Reply::anonymised($a449Stored))[0]['text'],
    json_decode(A449Quote::withoutFiles(json_encode([['action' => 'created'], ['action' => 'sent', 'files' => [['name' => 'Jana-Novakova.pdf', 'size' => 3, 'file' => 0]]]])), true)],
    [[['name' => 'Cenik.pdf', 'size' => 10, 'file' => 7]], false, false, '', [['action' => 'created'], ['action' => 'sent']]]);

// MCP: no parameter on the work set (D6), the instructions stay under 7,000 bytes and say where the saved replies and attachments are
$a449Instructions = Kaleta\Mcp\Translator::instructions();
check('3.49 A: the instructions name get_email_signature for saved replies and the admin for attachments, under 7,000 bytes; reply_to_enquiry and send_quote take no attachments', [
    strlen($a449Instructions) < 7000, str_contains($a449Instructions, 'get_email_signature brings the firm\'s saved replies, attachments are added by a person in the admin'),
    array_values(array_filter(array_keys((array) (array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), 'inputSchema', 'name')['reply_to_enquiry']['properties'] ?? [])), fn (string $p): bool => str_contains($p, 'attach') || str_contains($p, 'file'))),
    array_values(array_filter(array_keys((array) (array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), 'inputSchema', 'name')['send_quote']['properties'] ?? [])), fn (string $p): bool => str_contains($p, 'attach') || str_contains($p, 'file')))],
    [true, true, [], ['attach_pdf']]); // attach_pdf: the quote's own PDF (3.40), no file of the person's choice

// the table: in the names map and the time zone conversion; never in the site export (D13)
check('3.49 A: ka_saved_replies is in Core\Names and TimeZoneConversion (updated_at), never in the tables the site import brings', [
    Kaleta\Core\Names::english('saved_replies'), in_array('updated_at', Kaleta\Core\TimeZoneConversion::INSTANTS['saved_replies'] ?? [], true), in_array('saved_replies', Kaleta\Core\SiteImport::TABLES, true)],
    ['saved_replies', true, false]);
