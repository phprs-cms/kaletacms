<?php

declare(strict_types=1);

use Kaleta\Builder\Elements\GoogleReviews;

/* ---------- 3.41 b: the remaining low findings of the deep security audit of 10 November (N113–N123) ---------- */

// N122: a Google review with "<!--<script" or "</script>" never leaves the JSON-LD tag
$s341Review = GoogleReviews::jsonLdScript(['@type' => 'Review', 'reviewBody' => 'Great <!--<script> and </script><b>x</b>']);
check('3.41 N122: the review JSON-LD carries no < or > of a review – the tag ends where it should and the JSON reads back', [
    substr_count($s341Review, '<'), substr_count($s341Review, '>'), substr_count($s341Review, '</script>'),
    json_decode(substr($s341Review, strlen('<script type="application/ld+json">'), -strlen('</script>')), true)['reviewBody'] ?? null,
], [2, 2, 1, 'Great <!--<script> and </script><b>x</b>']);
