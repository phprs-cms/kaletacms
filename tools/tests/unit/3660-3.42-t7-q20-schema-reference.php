<?php

declare(strict_types=1);

/* ---------- 3.42 T7 (audit Q20): docs/schema.md is generated from schema.sql and names.php and kept current ---------- */

require_once KALETA_ROOT . '/tools/schema-doc.php';

$q20Sql = (string) file_get_contents(KALETA_ROOT . '/system/sql/schema.sql');
$q20Names = require KALETA_ROOT . '/system/sql/names.php';
$q20Doc = kaleta_schema_doc($q20Sql, $q20Names);
check('3.42 Q20: docs/schema.md is what tools/schema-doc.php makes of schema.sql and names.php today (run php tools/schema-doc.php after a schema change)',
    is_file(KALETA_ROOT . '/docs/schema.md') && (string) file_get_contents(KALETA_ROOT . '/docs/schema.md') === $q20Doc, true);
check('3.42 Q20: every table of a small schema gets a section, every column its English name, type and note, the keys a line', [
    kaleta_schema_doc("-- News items\nCREATE TABLE ka_novinky (\n    idc INT UNSIGNED NOT NULL AUTO_INCREMENT, -- the id\n    tema INT NULL,\n    PRIMARY KEY (idc)\n) ENGINE=InnoDB;\n", ['novinky' => ['news', ['idc' => 'id', 'tema' => 'category_id']]]),
], [implode("\n", array_slice(explode("\n", $q20Doc), 0, 9)) . "\n\n1 tables:\n\n[`ka_novinky`](#ka_novinky) (news)\n\n## ka_novinky\n\nEnglish name: `news`. News items\n\n| Column | English | Type | Note |\n|---|---|---|---|\n| `idc` | `id` | INT UNSIGNED NOT NULL AUTO_INCREMENT | the id |\n| `tema` | `category_id` | INT NULL |  |\n\nKeys: `PRIMARY KEY (idc)`.\n"]);
