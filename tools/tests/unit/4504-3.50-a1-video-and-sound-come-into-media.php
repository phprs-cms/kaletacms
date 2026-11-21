<?php

declare(strict_types=1);

/* ---------- 3.50 A1 (N139, N140): lazy video and sound come into Media or are left out with a note; a page pauses instead of holding a batch ---------- */
use Kaleta\Core\WebImport as A350Import;

$a350Text = '<p>' . str_repeat('A paragraph long enough to be the content of the old page. ', 3) . '</p>';

// N139: data-src of <video>, <source> and <audio> becomes src (absolute) before the files go into Media; a real src stays
$a350Lazy = A350Import::extract('<main><h1>Media</h1>' . $a350Text . '<audio data-src="/sound/a.mp3" controls></audio>'
    . '<audio src="data:audio/mp3;base64,AA" data-src="b.mp3"></audio><audio src="/kept.mp3" data-src="/other.mp3"></audio>'
    . '<video data-src="/film/v.mp4" poster="/img/poster.jpg"></video></main>', 'https://old.example/page/');
check('3.50 N139: lazy sound and video get their address from data-src (a data: placeholder is no address), absolute, and the poster too', [
    str_contains($a350Lazy['obsah'], 'src="https://old.example/sound/a.mp3"'), str_contains($a350Lazy['obsah'], 'src="https://old.example/page/b.mp3"'),
    str_contains($a350Lazy['obsah'], 'src="https://old.example/kept.mp3"'), str_contains($a350Lazy['obsah'], 'data-src'),
    array_column($a350Lazy['prvky'], 'kind'), str_contains($a350Lazy['prvky'][0]['html'] ?? '', 'src="https://old.example/film/v.mp4"'),
    str_contains($a350Lazy['prvky'][0]['html'] ?? '', 'poster="https://old.example/img/poster.jpg"')],
    [true, true, true, false, ['video'], true, true]);

// N139: a sound or a poster image that did not come into Media is left out with a line for the person; Media's own stay
$a350State = ['domena' => 'old.example', 'pozor' => []];
$a350Kept = A350Import::localVideosOnly('<p>x</p><audio src="https://old.example/a.mp3"></audio><audio src="/media/2026/11/b-1a2b3c.mp3"></audio>'
    . '<video src="/media/2026/11/v-1a2b3c.mp4" poster="https://cdn.example/p.jpg"></video><video src="media/2026/11/w-1a2b3c.mp4" poster="media/2026/11/p-1a2b3c.jpg"></video>', 'https://old.example/page/', $a350State);
check('3.50 N139: an <audio> outside Media goes like a video; a poster outside Media is dropped and the video kept; both said', [
    substr_count($a350Kept, '<audio'), str_contains($a350Kept, 'b-1a2b3c.mp3'), substr_count($a350Kept, '<video'), substr_count($a350Kept, 'poster='),
    A350Import::attentionLines($a350State, false)],
    [1, true, 2, 1, ['/page – the poster image https://cdn.example/p.jpg of a video is not in Media and was left out.', '/page – the sound /a.mp3 could not be downloaded: add it in the builder.']]);

// N139 + N140 through files() itself (no database: the limits say no before anything is downloaded)
$a350Web = (new ReflectionClass(A350Import::class))->newInstanceWithoutConstructor();
$a350Files = (new ReflectionMethod(A350Import::class, 'files'))->getClosure($a350Web);
$a350State = ['domena' => 'old.example', 'pozor' => [], 'zachovano' => ['soubory' => A350Import::FILES_PER_IMPORT]]; // the run's budget is used up
$a350Html = $a350Files('<p><audio controls><source src="https://old.example/s.mp3"></audio><a href="https://old.example/price.pdf">Prices</a></p>', 'https://old.example/page/', $a350State);
check('3.50 N139: audio[src] and its <source> join the linked files – a sound that cannot come into Media is left out, never played from the old site', [
    str_contains($a350Html, '<audio'), str_contains($a350Html, 'href="/price.pdf"'), A350Import::attentionLines($a350State, false)],
    [false, true, ['/page – the sound /s.mp3 could not be downloaded: add it in the builder.', '/page – the file /price.pdf was not downloaded: the import reached its limit of 200 linked files; it still links to the old site.']]);
$a350State = ['domena' => 'old.example', 'pozor' => [], 'stranka' => ['adresa' => 'https://old.example/page/', 'pokusy' => 1, 'obrazky' => 0, 'soubory' => 10]];
$a350Files('<p><a href="https://old.example/eleventh.pdf">Eleventh</a></p>', 'https://old.example/page/', $a350State);
check('3.50 N140: the limit of linked files holds for the whole page – content and widgets together – not for each piece', [
    $a350State['stranka']['soubory'], A350Import::attentionLines($a350State, false)], [11, ['/page – the file /eleventh.pdf could not be downloaded into Media: it still links to the old site.']]);
$a350Count = (new ReflectionMethod(A350Import::class, 'pageCount'))->getClosure(null);
$a350State = [];
check('3.50 N140: pageCount counts images and files of the page in progress, each on its own', [$a350Count($a350State, 'obrazky'), $a350Count($a350State, 'obrazky'), $a350Count($a350State, 'soubory'), $a350State['stranka']],
    [1, 2, 1, ['obrazky' => 2, 'soubory' => 1]]);

// N140: OUTSIDE_TIME past the batch's end a download pauses the page (ImportPaused – no RuntimeException, so not an error)
$a350InTime = (new ReflectionMethod(A350Import::class, 'stillInTime'))->getClosure($a350Web);
$a350End = new ReflectionProperty(A350Import::class, 'end');
$a350Outcome = function () use ($a350InTime): string {
    try {
        $a350InTime();

        return 'goes on';
    } catch (Kaleta\Core\ImportPaused $e) {
        return 'paused:' . ($e instanceof RuntimeException ? 'error' : 'signal');
    }
};
$a350End->setValue($a350Web, 0.0);
$a350Results = [$a350Outcome()];
$a350End->setValue($a350Web, microtime(true) - 5);
$a350Results[] = $a350Outcome();
$a350End->setValue($a350Web, microtime(true) - A350Import::OUTSIDE_TIME - 1);
$a350Results[] = $a350Outcome();
check('3.50 N140: no batch end (a single call) and 5 s past the end go on; OUTSIDE_TIME past it pauses with a signal that is no error', [$a350Results, A350Import::OUTSIDE_TIME], [['goes on', 'goes on', 'paused:signal'], 20.0]);
