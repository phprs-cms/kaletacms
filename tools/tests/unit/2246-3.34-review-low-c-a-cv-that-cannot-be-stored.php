<?php

declare(strict_types=1);

/* ---------- 3.34 review low (c): no silent OK without the CV; more files than kept are refused with a message ---------- */
$lcForms = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Forms.php');
check('[source-pin] 3.34 review low (c): Forms refuses more than MAX_FILES (soubory) and turns a CV that cannot be stored into a message (ulozeni); receive() throws and removes what it sealed', [
    str_contains($lcForms, "if (\$application && count(\$attachments) > \\Kaleta\\Core\\Applications::MAX_FILES) {\n            return \$redirectUri('soubory');"),
    str_contains($lcForms, "} catch (\\RuntimeException) {\n                return \$redirectUri('ulozeni');"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php'), "throw new \\RuntimeException('A CV could not be stored.');"),
    Kaleta\Builder\Elements\Form::messages('soubory') !== Kaleta\Builder\Elements\Form::messages(''), Kaleta\Builder\Elements\Form::messages('ulozeni') !== Kaleta\Builder\Elements\Form::messages('')],
    [true, true, true, true, true]);
