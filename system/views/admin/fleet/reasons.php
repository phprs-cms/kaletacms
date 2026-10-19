<?php
/** Labels of the reasons for attention (Fleet\Console::REASONS) – shared by the list and the detail of a site. */
return [
    'down' => t('does not answer'), 'not_reporting' => t('stopped reporting'), 'errors' => t('errors'), 'update_failed' => t('an update failed'),
    'jobs_failing' => t('background jobs fail'), 'no_backup' => t('no recent backup'), 'no_heartbeat' => t('no report yet'), 'warnings' => t('warnings'),
    'no_cron' => t('cron not set up'), 'enquiries' => t('enquiries waiting'), 'update_available' => t('a new version is available'), 'audit' => t('audit findings'),
    // 3.16
    'certificate_expiring' => t('the certificate expires soon'), 'domain_expiring' => t('the domain expires soon'), 'requests_waiting' => t('requests wait over 48 hours'),
    'sign_in_attacks' => t('many failed sign-ins'), 'bookings_to_confirm' => t('bookings to confirm'),
];
