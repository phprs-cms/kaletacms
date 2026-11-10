<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): a form notification's subject names the person by name or company only; the mail log never ---------- */
$r332Mail = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php');
check('3.32: the notification subject – "<form>: <site> – <name or company>", never the e-mail address; the log keeps "<form>: <site>"; a queued mail keeps its full subject in its body', [
    Kaleta\Front\Forms::notificationSubject('Contact', 'Hartsteel', 'olivia@example.com', [['Name', 'Olivia Hart'], ['E-mail', 'olivia@example.com']]),
    Kaleta\Front\Forms::notificationSubject('Contact', 'Hartsteel', 'olivia@example.com', [['Company', 'Bracket Works Ltd'], ['E-mail', 'olivia@example.com']]),
    Kaleta\Front\Forms::notificationSubject('Contact', 'Hartsteel', 'olivia@example.com', [['E-mail', 'olivia@example.com'], ['Message', 'Hello there, we need help']]),
    Kaleta\Front\Forms::notificationSubject('Contact', 'Hartsteel', 'olivia@example.com', [['Name', 'other@example.org']]),
    str_contains($r332Mail, "'predmet' => mb_substr(\$logSubject !== '' ? \$logSubject : \$subject, 0, 255)"), str_contains($r332Mail, "(\$logSubject !== '' ? ['predmet' => \$subject] : [])"),
    str_contains($r332Mail, "is_string(\$body['predmet'] ?? null) ? \$body['predmet'] : \$z['predmet']"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Front/Forms.php'), 'Mail::send($siteSettings, implode(\', \', $to), $subject, $text, \'\', $headers, true, $base);')],
    [['Contact: Hartsteel – Olivia Hart', 'Contact: Hartsteel'], ['Contact: Hartsteel – Bracket Works Ltd', 'Contact: Hartsteel'], ['Contact: Hartsteel', 'Contact: Hartsteel'], ['Contact: Hartsteel', 'Contact: Hartsteel'],
        true, true, true, true]);

