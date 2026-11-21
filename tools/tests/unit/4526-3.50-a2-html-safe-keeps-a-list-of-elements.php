<?php

declare(strict_types=1);

/* ---------- 3.50 A2: Html::safe keeps a list of elements – an unknown one is unwrapped with its text; address attributes only where they load something (I49) ---------- */
use Kaleta\Core\Html as A526Html;

check('3.50 I49: an unknown or future element is unwrapped – its text and its allowed children stay, the element and its address go', [
    A526Html::safe('<p>Before <fencedframe src="https://evil.example/">inside <strong>bold</strong></fencedframe> after</p>'),
    A526Html::safe('<custom-element onclick="alert(1)">c</custom-element>'), A526Html::safe('<marquee>m</marquee>'),
    A526Html::safe('<x-a><x-b><em>deep</em></x-b></x-a>'), A526Html::safe('<p>/<collection>.ics</p>')],
    ['<p>Before inside <strong>bold</strong> after</p>', 'c', 'm', '<em>deep</em>', '<p>/.ics</p>']);
check('3.50 I49: src and poster only on media, href on links and areas, cite on quotes; the discarded elements still go with their content', [
    A526Html::safe('<div src="https://example.com/a.png" href="https://example.com/" cite="https://example.com/">d</div>'),
    A526Html::safe('<img src="https://example.com/a.png" alt="a">'), A526Html::safe('<video src="/media/v.mp4" poster="/media/p.jpg" controls></video>'),
    A526Html::safe('<a href="https://example.com/" cite="https://example.com/">a</a>'), A526Html::safe('<blockquote cite="https://example.com/">q</blockquote>'),
    A526Html::safe('<p>x<script>alert(1)</script><iframe src="https://example.com/"></iframe></p>'), A526Html::safe('<kaleta-widget title="2"></kaleta-widget>')],
    ['<div>d</div>', '<img src="https://example.com/a.png" alt="a">', '<video src="/media/v.mp4" poster="/media/p.jpg" controls=""></video>',
        '<a href="https://example.com/">a</a>', '<blockquote cite="https://example.com/">q</blockquote>', '<p>x</p>', '<kaleta-widget title="2"></kaleta-widget>']);
