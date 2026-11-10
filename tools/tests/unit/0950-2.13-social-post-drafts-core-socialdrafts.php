<?php

declare(strict_types=1);

/* ---------- 2.13: social post drafts (Core\SocialDrafts) ---------- */
$sdLink = Kaleta\Core\SocialDrafts::trackedLink('https://example.com/novinky/nova-hala', 'facebook', 'nova-hala');
check('2.13 SocialDrafts: the tracked link carries the network, the medium and the news slug; an existing query is kept', [$sdLink, Kaleta\Core\SocialDrafts::trackedLink('https://example.com/novinky/a?x=1', 'x', 'a')],
    ['https://example.com/novinky/nova-hala?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala', 'https://example.com/novinky/a?x=1&utm_source=x&utm_medium=social&utm_campaign=a']);
check('2.13 SocialDrafts: hashtags from the tags – CamelCase without diacritics, at most three, no duplicates', [Kaleta\Core\SocialDrafts::hashtags(['Nová hala', 'výroba', 'nova hala', 'CNC stroje', 'čtvrtý']), Kaleta\Core\SocialDrafts::hashtags(['', '!!!'])],
    [['#NovaHala', '#Vyroba', '#CncStroje'], []]);
$sdLead = trim(str_repeat('Otevřeli jsme novou výrobní halu s moderními stroji. ', 12)); // about 620 characters
$sdTags = ['#NovaHala', '#Vyroba'];
$sdFacebook = Kaleta\Core\SocialDrafts::text('facebook', 'Nová hala', $sdLead, $sdTags, $sdLink);
$sdLinkedin = Kaleta\Core\SocialDrafts::text('linkedin', 'Nová hala', $sdLead, $sdTags, $sdLink);
$sdX = Kaleta\Core\SocialDrafts::text('x', 'Nová hala', $sdLead, $sdTags, $sdLink);
$sdInstagram = Kaleta\Core\SocialDrafts::text('instagram', 'Nová hala', $sdLead, $sdTags, $sdLink);
check('2.13 SocialDrafts: Facebook and LinkedIn start with the title and end with the hashtags and the link; Facebook shortens the lead, LinkedIn keeps more of it',
    [str_starts_with($sdFacebook, "Nová hala\n\nOtevřeli"), str_ends_with($sdFacebook, "#NovaHala #Vyroba\n\n" . $sdLink), str_contains($sdFacebook, '…'), str_ends_with($sdLinkedin, $sdLink), mb_strlen($sdLinkedin) > mb_strlen($sdFacebook)],
    [true, true, true, true, true]);
check('2.13 SocialDrafts: X fits 280 with the link counted as 23 – the hashtags and the link stay whole, the lead gives way', [Kaleta\Core\SocialDrafts::xLength($sdX) <= 280, Kaleta\Core\SocialDrafts::xLength($sdX) > 240, str_ends_with($sdX, "#NovaHala #Vyroba\n" . $sdLink),
    Kaleta\Core\SocialDrafts::xLength('abc https://example.com/a/very/long/path/that/goes/on'), Kaleta\Core\SocialDrafts::xLength(Kaleta\Core\SocialDrafts::text('x', str_repeat('T', 300), '', [], $sdLink))], [true, true, true, 27, 280]);
check('2.13 SocialDrafts: Instagram has no link in the text, says link in bio and keeps the hashtags', [str_contains($sdInstagram, 'http'), str_ends_with($sdInstagram, "#NovaHala #Vyroba\n\n" . t('Link in bio'))], [false, true]);
check('2.13 SocialDrafts: the chosen networks in a fixed order, unknown ones dropped; shorten() cuts at a word; plain() strips the editor HTML',
    [Kaleta\Core\SocialDrafts::chosen('x, facebook,evil'), Kaleta\Core\SocialDrafts::chosen(Kaleta\Core\SocialDrafts::DEFAULT_NETWORKS), Kaleta\Core\SocialDrafts::shorten('Dlouhý text s mnoha slovy', 14), Kaleta\Core\SocialDrafts::shorten('krátký', 20), Kaleta\Core\SocialDrafts::plain('<p>A &amp; B</p><p>C</p>')],
    [['facebook', 'x'], ['facebook', 'linkedin'], 'Dlouhý text…', 'krátký', 'A & B C']);
check('2.13 SocialDrafts: finish() completes an assistant text – its own links out, the hashtags and the tracked link back, X within its limit, Instagram without a link',
    [Kaleta\Core\SocialDrafts::finish('linkedin', 'Nový text https://evil.example/x od asistenta.', $sdTags, $sdLink), Kaleta\Core\SocialDrafts::xLength(Kaleta\Core\SocialDrafts::finish('x', $sdLead, $sdTags, $sdLink)) <= 280, str_contains(Kaleta\Core\SocialDrafts::finish('instagram', 'Text', [], $sdLink), 'http')],
    ["Nový text od asistenta.\n\n#NovaHala #Vyroba\n\n" . $sdLink, true, false]);
