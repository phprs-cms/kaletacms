<?php

declare(strict_types=1);

/* ---------- 3.40 review: the fixes of the security review of quote PDFs, quotes II and applications II ---------- */

// M1: no address under /application-consent/ is ever kept among the addresses not found (a mangled link still carries its token)
check('3.40 review M1: every path under /application-consent/ is private – mangled, with a language prefix, in any case; /quote/ still is, other addresses are not', array_map(Kaleta\Core\NotFound::isPrivate(...), [
    'application-consent/' . str_repeat('a', 64) . '.', '/application-consent/' . str_repeat('b', 64) . ')', 'application-consent/' . str_repeat('c', 64) . '/',
    'en/application-consent/x', '/de-at/Application-Consent/x', 'APPLICATION-CONSENT/abc', 'quote/x', 'application-consents/x', 'application-consent', 'jobs/application-consent/x']),
    [true, true, true, true, true, true, true, false, false, false]);
