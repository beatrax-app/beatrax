<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Recovery;

use Modules\Auth\Public\Support\Username;

final class RecoveryCodeFormatter
{
    /**
     * @param  list<string>  $codes
     */
    public function format(array $codes): string
    {
        return implode("\n", $codes);
    }

    public function filenameFor(string $username): string
    {
        return 'beatrax-recovery-codes-'.$this->usernameSlug($username).'.txt';
    }

    // The username reaches a download attribute and an OS share sheet, and the
    // rule shaping it binds only accounts made after it, so what a filesystem
    // refuses is stripped here too. Public because the screen says what it
    // saved, and naming a different file than the one written is worse.
    public function usernameSlug(string $username): string
    {
        $slug = (string) preg_replace('/[^a-z0-9._-]+/', '-', Username::normalize($username));
        $slug = trim($slug, '-.');

        return $slug === '' ? 'account' : mb_substr($slug, 0, Username::MAX_LENGTH);
    }
}
