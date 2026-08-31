<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

use Modules\Core\Internal\Backup\BackupContentsUnreadableException;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Support\Lang;
use Throwable;

// What a reader is told when a restore refuses, in place of the exception's
// own message. Those messages are machine text: they name internal phases and
// carry absolute filesystem paths, and they ship in one language while the
// screen around them ships in twenty-six.
enum RestoreRefusal: string
{
    case WrongPassphrase = 'restore_wrong_passphrase';

    case NotABackup = 'restore_not_a_backup';

    case ContentsUnreadable = 'restore_contents_unreadable';

    case CouldNotRead = 'restore_could_not_read';

    case NotSupportedHere = 'restore_not_supported';

    case Unknown = 'restore_failed';

    public static function forThrowable(Throwable $e): self
    {
        return match (true) {
            $e instanceof BackupDecryptionException => self::WrongPassphrase,
            $e instanceof BackupContentsUnreadableException => self::ContentsUnreadable,
            $e instanceof BackupFormatException => self::NotABackup,
            $e instanceof BackupIoException => self::CouldNotRead,
            $e instanceof BackupNotSupportedException => self::NotSupportedHere,
            default => self::Unknown,
        };
    }

    public function sentence(): string
    {
        return Lang::get('core::backup.errors.'.$this->value);
    }
}
