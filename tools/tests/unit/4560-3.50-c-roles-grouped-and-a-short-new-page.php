<?php

declare(strict_types=1);

/* ---------- 3.50 C: roles grouped like the menu (U53), the role summary, a short form for a new page (U56) ---------- */
use Kaleta\Admin\MenuGroups as C350Groups;
use Kaleta\Admin\Modules\Pages as C350Pages;
use Kaleta\Admin\Modules\Roles as C350Roles;
use Kaleta\Admin\Modules\Users as C350Users;
use Kaleta\Core\Auth as C350Auth;

$c350Sections = C350Roles::configurable();
$c350Grouped = C350Roles::sectionGroups($c350Sections);
$c350Flat = array_merge(...array_map(fn (array $g): array => $g['sections'], $c350Grouped));
check('3.50 C (U53): the role form groups the sections like the menu – the same boxes (field moduly[], the same values and names), each once, the groups in menu order, the two areas under Appearance and Workspace', [
    $c350Flat == $c350Sections, count($c350Flat) === count($c350Sections),
    array_values(array_diff(array_column($c350Grouped, 'group'), C350Groups::ORDER)),
    array_column($c350Grouped, 'group') === array_values(array_intersect(C350Groups::ORDER, array_column($c350Grouped, 'group'))),
    array_keys(array_filter(array_column($c350Grouped, 'sections', 'group'), fn (array $s): bool => isset($s[C350Auth::APPEARANCE_AREA]))),
    array_keys(array_filter(array_column($c350Grouped, 'sections', 'group'), fn (array $s): bool => isset($s[C350Auth::PRICES_AREA]))),
    C350Roles::sectionGroups(['nonsense' => 'X', 'pages' => 'Pages']),
], [true, true, [], true, ['Appearance'], ['Workspace'], [['group' => 'Content', 'sections' => ['pages' => 'Pages']], ['group' => '', 'sections' => ['nonsense' => 'X']]]]);
check('3.50 C (U53): the summary of a role no longer starts "Does not write news" for everybody – News is the section "news"; without it the sentence starts with what the person has', Kaleta\Core\Language::runWith('en', fn (): array => [
    C350Users::summary(C350Auth::EDITOR, ['news', 'pages']), C350Users::summary(C350Auth::AUTHOR, ['news']), C350Users::summary(C350Auth::EDITOR, ['pages', 'contacts']),
    C350Users::summary(C350Auth::AUTHOR, []), str_contains(C350Users::summary(C350Auth::EDITOR, ['pages', 'appearance_area']), 'Appearance: menu, site parts, pop-ups and components'),
], 'admin-'), ['Writes, edits and publishes news by all authors. Also has access to: Pages.', 'Writes and edits their own news; an editor publishes it.', 'Has access to: Pages, Customers.',
    'Has access to no section yet.', true]);

// a new page as Pages::actionNew starts it
$c350New = ['ids' => 0, 'seo_link' => '', 'titulek' => '', 'popis' => '', 'seo_titulek' => '', 'obrazek' => '', 'noindex' => 0, 'canonical_url' => '', 'text' => '', 'zobrazit' => 1, 'v_menu' => 1,
    'poradi' => 100, 'stavba' => null, 'stavba_koncept' => null, 'nadrazena' => null, 'zverejnit_od' => null, 'valid_until' => null, 'review_by' => null, 'jazyk' => '', 'preklad_z' => null];
check('3.50 C (U56): "More settings" of a new page start folded – open when a field holds anything but a new page\'s default, or has an error (an error of the title or the text keeps them folded)', [
    C350Pages::moreSettingsOpen($c350New, []), C350Pages::moreSettingsOpen($c350New, ['titulek' => 'Fill in the title.']), C350Pages::moreSettingsOpen($c350New, ['seo_link' => 'taken']),
    C350Pages::moreSettingsOpen(['nadrazena' => 4] + $c350New, []), C350Pages::moreSettingsOpen(['seo_link' => 'about'] + $c350New, []),
    C350Pages::moreSettingsOpen(['valid_until' => '2027-01-01'] + $c350New, []), C350Pages::moreSettingsOpen(['zobrazit' => 0] + $c350New, []),
    C350Pages::moreSettingsOpen(['zobrazit' => 0, 'show_on_publish' => true] + $c350New, []), C350Pages::moreSettingsOpen(['v_menu' => 0] + $c350New, []),
    C350Pages::moreSettingsOpen(['poradi' => 5] + $c350New, []), C350Pages::moreSettingsOpen(['owner_id' => 3] + $c350New, []), C350Pages::moreSettingsOpen(['noindex' => 1] + $c350New, []),
], [false, false, true, true, true, true, true, false, true, true, true, true]);
