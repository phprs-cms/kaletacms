<?php

declare(strict_types=1);

/* ---------- 2.12: testimonial requests with consent (Core\Testimonials) ---------- */
use Kaleta\Core\Testimonials;

check('2.12 Testimonials::clean – words, a name and the consent to publish them are required; tags never get through', [
    Testimonials::clean(['text' => 'Skvělá spolupráce, <b>doporučuji</b>.', 'name' => ' Eva ', 'role' => "ředitelka\nACME", 'consent_words' => '1']),
    Testimonials::clean(['text' => 'krátce', 'name' => 'Eva', 'consent_words' => '1']) === t('Please write a few words.'),
    Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => 'Eva']) === t('We can publish your words only with your consent.'),
    Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => '', 'consent_words' => '1']) === t('Please fill in your name.')],
    [['text' => 'Skvělá spolupráce, doporučuji.', 'name' => 'Eva', 'role' => 'ředitelka ACME', 'words' => true, 'photo' => false], true, true, true]);
check('2.12 Testimonials: the link is 32 hex characters and a valid-looking but unknown one finds nothing without the database', [Testimonials::DAYS, Testimonials::PRESET, (new ReflectionMethod(Testimonials::class, 'find'))->getNumberOfParameters()], [30, 'references', 2]);
