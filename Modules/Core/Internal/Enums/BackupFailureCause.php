<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Enums;

// Four failures share the one `backup_corrupt` kind, so the raiser records
// which; the banner used to infer it from whether a `.suspect` file existed,
// and three of the four then accused a database they had just cleared.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#an-error-that-names-a-cause-the-code-had-already-ruled-out
 */
enum BackupFailureCause: string
{
    // SourceUnreadable: nothing usable was produced, and the source is why.
    // CopySuspect: a copy was kept as `.suspect` because it did not verify.
    // WriteFailed: the database is sound, the files could not be written.
    // RestoreFailed: the pre-restore snapshot is the undo.

    case SourceUnreadable = 'source_unreadable';

    case CopySuspect = 'copy_suspect';

    case WriteFailed = 'write_failed';

    case RestoreFailed = 'restore_failed';
}
