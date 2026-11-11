<?php

declare(strict_types=1);

use Kaleta\Core\WebImport;

/* ---------- 3.33 review L1: a video file not in Media never becomes a hotlinked Video element ---------- */
$i333vState = ['domena' => 'old.example', 'pozor' => []];
$i333vHtml = WebImport::localVideosOnly('<p>A</p><video src="https://cdn.other.example/tour.mp4"></video><video controls><source src="media/clinic-tour-1a2b3c.mp4"></video>'
    . '<video><source src="https://old.example/files/intro.webm"></video><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', 'https://old.example/about', $i333vState);
check('3.33 review L1: foreign and old-site video files are left out with an attention line; a video in Media and a YouTube frame stay', [
    substr_count($i333vHtml, '<video'), str_contains($i333vHtml, 'media/clinic-tour-1a2b3c.mp4'), str_contains($i333vHtml, 'youtube.com/embed'),
    WebImport::attentionLines($i333vState, false),
], [1, true, true, [
    '/about – the video https://cdn.other.example/tour.mp4 could not be downloaded: add it in the builder.',
    '/about – the video /files/intro.webm could not be downloaded: add it in the builder.',
]]);
check('3.33 review L1: HTML without a video is returned as it is', WebImport::localVideosOnly('<p>No <b>video</b></p>', 'https://old.example/', $i333vState), '<p>No <b>video</b></p>');
