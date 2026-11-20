<?php

declare(strict_types=1);

use Kaleta\Core\ApplicationCounts;
use Kaleta\Core\ApplicationFiles;
use Kaleta\Core\ApplicationMove;
use Kaleta\Core\ApplicationReplies;
use Kaleta\Core\ApplicationRetention;
use Kaleta\Core\Applications;

/* ---------- 3.47 pre-step: Core\Applications split into ApplicationFiles, ApplicationRetention, ApplicationReplies (+ Move, Counts) ---------- */
// Every member that moved keeps a forward on Core\Applications (callers, migration 0120, tools/test-migrations.sh). The forward
// must take the same parameters (name, type, default, by reference) and return the same type as the method it calls, and an
// alias constant must hold its target's value – compared through reflection, no source text is read.

/** @var array<class-string, list<string>> target class => the methods Applications forwards to it */
$p347Methods = [
    ApplicationFiles::class => ['checkUpload', 'contentFits', 'sealFile', 'writeSealed', 'markFilesSince', 'backupMissesFiles', 'headerKey', 'openSealed', 'sealedTarget', 'filePath', 'paths', 'pathFiles', 'attachmentInfo', 'attachment', 'attachments', 'attachmentsLocked', 'encodeAttachments', 'lockedCount', 'lockFiles', 'unlockFiles', 'sealPending', 'sealEntry', 'sweepOrphans', 'resealHeld', 'heldCount', 'unsealedCount', 'rowFiles'],
    ApplicationRetention::class => ['confirmedUpTo', 'confirmRetention', 'unconfirmed', 'startingRetention', 'adoptRetention', 'awaitingConfirmation', 'deletionDate', 'expired', 'dueForWarning', 'purge'],
    ApplicationReplies::class => ['storedTemplates', 'template', 'fill', 'languageOf', 'compose', 'safeName', 'confirmReceipt', 'reply', 'rejectWithTemplate'],
    ApplicationMove::class => ['movingCondition', 'movingLeft'],
    ApplicationCounts::class => ['monthCounts', 'countingChanged', 'movesIn'],
];
/** @var array<class-string, list<string>> target class => the constants Applications keeps as aliases */
$p347Constants = [
    ApplicationFiles::class => ['CV_EXTENSIONS', 'CONTENT_TYPES', 'MAX_FILES', 'FOLDER', 'OLD_FOLDER', 'SEAL_BATCH', 'FILE_MAGIC', 'ORPHAN_DAYS', 'ORPHAN_SUFFIX', 'FILES_SINCE', 'DB_SEALED'],
    ApplicationRetention::class => ['WARN_DAYS', 'RETENTION_CONFIRMED', 'UNCONFIRMED_DAYS'],
    ApplicationReplies::class => ['TEMPLATES', 'TEMPLATE_LABELS', 'TEMPLATE_MAX', 'SAFE_NAME_MAX', 'BULK_MAX'],
];

$p347Signature = static function (ReflectionMethod $m): string {
    $params = array_map(static fn (ReflectionParameter $p): string => ($p->getType() ?? 'mixed') . ($p->isPassedByReference() ? ' &' : ' ') . '$' . $p->getName()
        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), $m->getParameters());

    return ($m->isStatic() ? 'static ' : '') . ($m->isPublic() ? 'public ' : 'private ') . $m->getName() . '(' . implode(', ', $params) . '): ' . ($m->getReturnType() ?? 'mixed');
};
$p347Mismatch = [];
$p347Count = 0;
foreach ($p347Methods as $p347Class => $p347Names) {
    foreach ($p347Names as $p347Name) {
        $p347Count++;
        if (!method_exists($p347Class, $p347Name) || !method_exists(Applications::class, $p347Name)) {
            $p347Mismatch[] = $p347Class . '::' . $p347Name . ' missing';
            continue;
        }
        $p347Forward = $p347Signature(new ReflectionMethod(Applications::class, $p347Name));
        $p347Target = $p347Signature(new ReflectionMethod($p347Class, $p347Name));
        if ($p347Forward !== $p347Target) {
            $p347Mismatch[] = $p347Forward . ' ≠ ' . $p347Class . ': ' . $p347Target;
        }
    }
}
check('3.47 pre-step: each forward on Core\\Applications has the signature of the method it calls (parameters, defaults, return type)', [$p347Mismatch, $p347Count], [[], 51]);

$p347Left = [];
foreach ([ApplicationFiles::class, ApplicationRetention::class, ApplicationReplies::class] as $p347Class) {
    foreach ((new ReflectionClass($p347Class))->getMethods(ReflectionMethod::IS_PUBLIC) as $p347Method) {
        if (!in_array($p347Method->getName(), $p347Methods[$p347Class], true)) {
            $p347Left[] = $p347Class . '::' . $p347Method->getName();
        }
    }
}
check('3.47 pre-step: every public method of the three new classes is forwarded (nothing a caller used under the old name is lost)', $p347Left, []);

$p347Wrong = [];
foreach ($p347Constants as $p347Class => $p347Names) {
    foreach ($p347Names as $p347Name) {
        $p347Alias = new ReflectionClassConstant(Applications::class, $p347Name);
        if (!$p347Alias->isPublic() || $p347Alias->getValue() !== constant($p347Class . '::' . $p347Name)) {
            $p347Wrong[] = $p347Name;
        }
    }
}
check('3.47 pre-step: the alias constants of Core\\Applications hold the values of the moved ones', $p347Wrong, []);

check('3.47 pre-step: the moved code answers the same through the forward and the new class (pure methods)', [
    Applications::contentFits('pdf', 'application/pdf') === ApplicationFiles::contentFits('pdf', 'application/pdf'),
    Applications::sealedTarget('2026/05/abc.pdf') === ApplicationFiles::sealedTarget('2026/05/abc.pdf'),
    Applications::deletionDate(['decided_at' => '2026-01-10 08:00:00'], 6) === ApplicationRetention::deletionDate(['decided_at' => '2026-01-10 08:00:00'], 6),
    Applications::safeName('Jana Nováková') === ApplicationReplies::safeName('Jana Nováková'),
    Applications::movesIn('[{"at":"2026-03-02","to":"hired"}]', '2026-03-01', '2026-04-01') === ApplicationCounts::movesIn('[{"at":"2026-03-02","to":"hired"}]', '2026-03-01', '2026-04-01'),
    ApplicationRetention::deletionDate(['decided_at' => '2026-01-10 08:00:00'], 6), ApplicationCounts::movesIn('[{"at":"2026-03-02","to":"hired"}]', '2026-03-01', '2026-04-01')],
    [true, true, true, true, true, '2026-07-10', ['hired']]);
