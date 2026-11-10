<?php

declare(strict_types=1);

/* ---------- backups to S3: AWS Signature V4 signing (the value verified by an independent computation) ---------- */
check('404: sondy robotů se nezapisují, skutečné adresy ano', array_map(Kaleta\Core\NotFound::isBot(...), ['wp/v2/users', 'sellers.json', 'api/session/properties', '_next', 'api/novinky/x', '@fs/../../../../../proc/self/environ', 'media/../config.php', 'about-us', 'en', 'cenik-2019', 'v1..2']),
    [true, true, true, true, true, true, true, false, false, false, false]);
// 1.9: structured data of collection item pages – only mapped fields, an offer needs a price and a currency
$sdFields = [['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'text'], ['klic' => 'druh', 'popisek' => 'Druh', 'typ' => 'text'], ['klic' => 'zacatek', 'popisek' => 'Začátek', 'typ' => 'datum']];
check('Strukturovaná data kolekce: neznámé pole a typ se zahodí', [Kaleta\Builder\CollectionSchema::sanitize(['typ' => 'Service', 'pole' => ['price' => 'cena', 'serviceType' => 'neni', 'hack' => 'druh'], 'mena' => 'eur'], $sdFields),
    Kaleta\Builder\CollectionSchema::sanitize(['typ' => 'Recipe'], $sdFields)], [['typ' => 'Service', 'pole' => ['price' => 'cena'], 'mena' => 'EUR'], null]);
$sdCollection = ['pole' => $sdFields, 'schema_org' => '{"typ":"Service","pole":{"price":"cena","serviceType":"druh"},"mena":"EUR"}'];
check('Strukturovaná data kolekce: služba s nabídkou', Kaleta\Builder\CollectionSchema::forItem($sdCollection, ['nazev' => 'Revize', 'data' => ['cena' => '1 200,50', 'druh' => '<b>Elektro</b>']], 'https://x.test/sluzby/revize', 'Popis', '', 'https://x.test/#firma'),
    ['@type' => 'Service', 'name' => 'Revize', 'url' => 'https://x.test/sluzby/revize', 'description' => 'Popis', 'serviceType' => 'Elektro', 'provider' => ['@id' => 'https://x.test/#firma'],
        'offers' => ['@type' => 'Offer', 'price' => '1200.50', 'priceCurrency' => 'EUR', 'url' => 'https://x.test/sluzby/revize']]);
check('Strukturovaná data kolekce: událost bez začátku ne, bez typu nic', [Kaleta\Builder\CollectionSchema::forItem(['pole' => $sdFields, 'schema_org' => '{"typ":"Event","pole":{"startDate":"zacatek"}}'], ['nazev' => 'A', 'data' => []], 'u', '', '', 'i'),
    Kaleta\Builder\CollectionSchema::forItem(['pole' => $sdFields], ['nazev' => 'A', 'data' => []], 'u', '', '', 'i')], [null, null]);
