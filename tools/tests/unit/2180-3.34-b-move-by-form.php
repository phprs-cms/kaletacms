<?php

declare(strict_types=1);

use Kaleta\Core\ApplicationMove;

/* ---------- 3.34 (B): the admin tool "Move by form" – enquiries of a form to applications and back ---------- */
check('3.34 B move: a form is the pair source + name; the key survives a name with a bar', [
    ApplicationMove::key('Work | with us', 'stranka:4'), ApplicationMove::parseKey('stranka:4|Work | with us'), ApplicationMove::parseKey('no bar')],
    ['stranka:4|Work | with us', ['form' => 'Work | with us', 'source' => 'stranka:4'], null]);
check('3.34 B move: the statuses each way – an enquiry new → new, read or resolved → reviewed; an application new → new, decided → resolved, the rest → read', [
    array_map(ApplicationMove::statusFromEnquiry(...), [0, 1, 2]), array_map(ApplicationMove::statusToEnquiry(...), Kaleta\Core\Applications::STATUSES)],
    [['new', 'reviewed', 'reviewed'], [0, 1, 1, 1, 2, 2]]);
check('3.34 B move: form names that look like applications are listed first (several languages); others not', array_map(fn (string $n): int => preg_match(ApplicationMove::LOOKS, $n),
    ['Job application', 'Kariéra', 'Pracujte u nás – žádost o místo', 'Bewerbung', 'Trabaja con nosotros', 'Send your CV', 'Poptávka', 'Contact us', 'Newsletter']),
    [1, 1, 1, 1, 1, 1, 0, 0, 0]);
$m338Source = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php');
check('3.34 B move: repeatable (an enquiry moves once – source_enquiry_id), in batches, deals keep going without the link, the sealed copies go after the way back; administrators only', [
    substr_count($m338Source, 'NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp)'), str_contains($m338Source, "ORDER BY idp LIMIT ' . self::BATCH"),
    str_contains($m338Source, 'Deals::forgetEnquiries($db, [$idp]);'), str_contains($m338Source, '@unlink($path); // the sealed copy'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Applications.php'), "return \$this->error('Only an administrator can move enquiries to applications.', 403);")],
    [2, true, true, true, 2]);
