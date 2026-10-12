<?php
/**
 * Kaleta 3.12: Arabic search reads taa marbuta as ha (ة → ه) and alef wasla as alef (ٱ → ا) – Core\Search::normalize. The
 * news index (ka_novinky.hledani) stored before keeps the old letters, so the rows that have them are emptied, and
 * Search::complete() fills them again in batches before the next search (as after any update). Self-contained on purpose:
 * plain SQL on the bytes of the two letters (UTF-8 D8 A9 and D9 B1), whichever release's code runs it.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    $db->run("UPDATE {novinky} SET hledani = NULL WHERE hledani IS NOT NULL
        AND (LOCATE(UNHEX('D8A9'), CAST(hledani AS BINARY)) > 0 OR LOCATE(UNHEX('D9B1'), CAST(hledani AS BINARY)) > 0)");
};
