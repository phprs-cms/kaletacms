<?php

declare(strict_types=1);

use Kaleta\Core\InternalFiles;
use Kaleta\Mcp\Catalog;

/* ---------- 3.42 A: internal files – a document library for the team that is never public ---------- */

// who sees a folder: one rule, administrators always, roles by the user's custom role (the hook for departments later)
$i342Admin = ['admin' => 2, 'role' => null];
$i342Editor = ['admin' => 1, 'role' => 4];
$i342Author = ['admin' => 0, 'role' => null];
$i342All = ['audience' => 'all', 'roles' => ''];
$i342Roles = ['audience' => 'roles', 'roles' => '4,9'];
$i342Other = ['audience' => 'roles', 'roles' => '9'];
check('3.42 A: InternalFiles::audienceMatches – everyone with the section for audience all; roles only for a member of one of them; administrators always; nobody signed out', [
    InternalFiles::audienceMatches($i342All, $i342Author), InternalFiles::audienceMatches($i342Roles, $i342Editor), InternalFiles::audienceMatches($i342Other, $i342Editor),
    InternalFiles::audienceMatches($i342Other, $i342Admin), InternalFiles::audienceMatches($i342Roles, $i342Author), InternalFiles::audienceMatches($i342All, null),
    InternalFiles::audienceMatches(['audience' => 'roles', 'roles' => ''], $i342Editor)],
    [true, true, false, true, false, false, false]);

check('3.42 A: role ids, tags, names and extensions as stored (pure)', [
    InternalFiles::roleIds('4, 9,x,0,4,-2'), InternalFiles::tags(' contract, HR ,hr,, 2026 '), InternalFiles::tags(['a', "b\nc", '']), count(InternalFiles::tags(implode(',', range(1, 30)))),
    InternalFiles::safeName("../../etc/pass\x00wd.pdf"), InternalFiles::safeName('  Price list 2026.xlsx  '), InternalFiles::extension('Report.PDF'), InternalFiles::extension('page.html'),
    InternalFiles::extension('font.woff2'), InternalFiles::extension('run.php'), InternalFiles::nameWithExtension('Notes.txt', 'md'), InternalFiles::nameWithExtension('Price list', 'pdf'),
    InternalFiles::nameWithExtension('Archive.tar', 'zip')],
    [[4, 9], ['contract', 'HR', '2026'], ['a', 'b c'], 20, 'etc pass wd.pdf', 'Price list 2026.xlsx', 'pdf', '', '', '', 'Notes.md', 'Price list.pdf', 'Archive.tar.zip']);

check('3.42 A: the download type follows the extension; an unknown one is a plain download; the stored path never leaves storage/internal-files', [
    InternalFiles::contentType('pdf'), InternalFiles::contentType('md'), InternalFiles::contentType('svg'), InternalFiles::contentType('exe'),
    InternalFiles::diskPath('2026/11/' . str_repeat('ab', 16) . '.bin') === InternalFiles::FOLDER . '/2026/11/' . str_repeat('ab', 16) . '.bin',
    InternalFiles::diskPath('../config.php'), InternalFiles::diskPath('2026/11/../../x.bin'), str_contains(InternalFiles::FOLDER, '/media'), str_ends_with(InternalFiles::FOLDER, '/storage/internal-files')],
    ['application/pdf', 'text/markdown; charset=utf-8', 'image/svg+xml', 'application/octet-stream', true, null, null, false, true]);

// the check before storing: the extension, the content and SVG cleaned
$i342Dir = sys_get_temp_dir() . '/kaleta-i342-' . bin2hex(random_bytes(4));
mkdir($i342Dir);
$i342File = static function (string $name, string $content) use ($i342Dir): string {
    file_put_contents($i342Dir . '/' . $name, $content);

    return $i342Dir . '/' . $name;
};
$i342Check = static function (string $name, string $content) use ($i342File): string {
    $r = InternalFiles::check($i342File($name, $content), $name);

    return is_string($r) ? 'refused: ' . $r : $r['extension'] . ($r['content'] !== null ? ' cleaned' : '');
};
$i342Svg = InternalFiles::check($i342File('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><rect width="5" height="5" onclick="x()"/></svg>'), 'logo.svg');
check('3.42 A: InternalFiles::check – text and Markdown pass; HTML, PHP or a script under any extension, an unknown extension, an image that is no image and text not in UTF-8 are refused; an SVG is cleaned or refused', [
    $i342Check('notes.md', "# Onboarding\n\n- keys\n"), $i342Check('list.csv', "code;price\nA;1\n"), $i342Check('page.txt', "<?php echo 'x';"), $i342Check('index.html', '<p>x</p>'),
    $i342Check('trick.pdf', "<!DOCTYPE html><html><script>x</script></html>"), $i342Check('photo.png', 'not an image at all'), $i342Check('latin.txt', "caf\xE9"),
    $i342Check('empty.svg', '<html>no drawing</html>'), is_array($i342Svg) ? [$i342Svg['extension'], str_contains((string) $i342Svg['content'], 'script'), str_contains((string) $i342Svg['content'], 'onclick'), str_contains((string) $i342Svg['content'], '<rect')] : $i342Svg,
    InternalFiles::check($i342Dir . '/missing.pdf', 'missing.pdf')],
    ['md', 'csv', 'refused: The file content does not match its extension.', 'refused: This file type cannot be stored in Internal files.',
        'refused: The file content does not match its extension.', 'refused: The file content does not match its extension.', 'refused: A text document must be in UTF-8.',
        'refused: The SVG file is not a drawing – it cannot be stored.', ['svg', false, false, true], 'The file could not be uploaded.']);
array_map('unlink', glob($i342Dir . '/*') ?: []);
rmdir($i342Dir);

// the section: Workspace, given explicitly (never a default of a level), site-neutral, never journaled
check('3.42 A: the section Internal files – in Workspace after Bookings, given only explicitly, never a default of a level, site-neutral; its tables are never journaled for undo', [
    Kaleta\Admin\Modules\InternalFiles::GROUP, Kaleta\Admin\Modules\InternalFiles::GRANTED_EXPLICITLY, Kaleta\Admin\Modules\InternalFiles::IDENT,
    in_array(InternalFiles::SECTION, Kaleta\Admin\Modules\Users::defaultModules(1), true), in_array(InternalFiles::SECTION, Kaleta\Admin\Modules\Users::defaultModules(0), true),
    in_array(InternalFiles::SECTION, Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES, true),
    array_search(Kaleta\Admin\Modules\InternalFiles::class, Kaleta\Admin\Kernel::MODULES, true) - array_search(Kaleta\Admin\Modules\Bookings::class, Kaleta\Admin\Kernel::MODULES, true),
    array_map(fn (string $t): bool => Kaleta\Core\AgentJournal::journaled($t), ['internal_folders', 'internal_files', 'internal_file_versions'])],
    ['Workspace', true, 'internal_files', false, false, true, 1, [false, false, false]]);

// MCP: on demand, English only, read / draft, never deletes, site-neutral
check('3.42 A: the MCP tools of internal files – on demand (the work set is at its budget), English only, list and get read, save is a draft-class tool that never changes the site, no delete tool', [
    Catalog::TOOLS['list_internal_files'], Catalog::TOOLS['get_internal_file'], Catalog::TOOLS['save_internal_file'],
    Catalog::setsOf('list_internal_files'), Catalog::setsOf('save_internal_file'), in_array('save_internal_file', Catalog::ON_DEMAND, true),
    Catalog::isSiteNeutral('save_internal_file'), Catalog::isIdempotent('save_internal_file'), Catalog::isOpenWorld('get_internal_file'),
    Kaleta\Mcp\Translator::czech('list_internal_files'), Kaleta\Mcp\Translator::czech('save_internal_file'),
    array_values(array_filter(array_keys(Catalog::TOOLS), fn (string $t): bool => str_contains($t, 'internal') && str_starts_with($t, 'delete'))),
    in_array('save_internal_file', Kaleta\Mcp\Confirmation::TOOLS, true)],
    [['read', ''], ['read', ''], ['draft', ''], [], [], true, true, false, false, 'list_internal_files', 'save_internal_file', [], false]);

// never public: only these files name the tables – no page, search, sitemap, llms.txt, export, blueprint, page package,
// heartbeat, report or media picker can read them (the structure of 3.38's prices, unit 2800)
$i342Allowed = ['src/Core/InternalFiles.php'];
$i342Found = [];
foreach (['src', 'views'] as $i342Folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/' . $i342Folder, FilesystemIterator::SKIP_DOTS)) as $i342Path) {
        if (str_ends_with((string) $i342Path, '.php') && preg_match('/\{internal_(?:folders|files|file_versions)\}|\bka_internal_(?:folders|files|file_versions)\b|->(?:insert|update|delete)\(\s*\'internal_(?:folders|files|file_versions)\'/',
            (string) file_get_contents((string) $i342Path)) === 1) {
            $i342Found[] = substr((string) $i342Path, strlen(KALETA_SYSTEM) + 1);
        }
    }
}
sort($i342Found);
check('3.42 A: the tables of internal files are named only by Core\InternalFiles – nothing public can read them', $i342Found, $i342Allowed);
