<?php

declare(strict_types=1);

use Kaleta\Core\Search;

/* ---------- search ---------- */
check('Hledani::normalizuj', Search::normalize('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'nabrezi vltavy promena');
// 3.52 (B1): the site search no longer builds a MATCH … AGAINST query – SiteSearch::words() takes the words of a query
check('SiteSearch::words: one-letter words drop out, two letters stay (3.52)', array_column(Kaleta\Core\SiteSearch::words('co je v Nábřeží', 'cs'), 'word'), ['co', 'je', 'nabrezi']);
check('SiteSearch::words: fulltext operators are only separators', array_column(Kaleta\Core\SiteSearch::words('+tajne -verejne "fraze" (x) ~y*', 'cs'), 'word'), ['tajne', 'verejne', 'fraze']);
check('SiteSearch::words: at most 6 words, no repeats', array_column(Kaleta\Core\SiteSearch::words('aa bb aa cc dd ee ff gg hh', 'cs'), 'word'), ['aa', 'bb', 'cc', 'dd', 'ee', 'ff']);
