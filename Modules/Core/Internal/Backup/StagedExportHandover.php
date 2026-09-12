<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\LockStore;

/**
 * @link ../../../../.docs/features/core/one-export-action.md#how-the-archive-leaves-the-process
 */
final readonly class StagedExportHandover
{
    private const string CLAIMS_KEY = 'beatrax.staged_exports';

    private const string SPENT_KEY_PREFIX = 'beatrax.staged_export.';

    public function __construct(
        private Session $session,
        private Clock $clock,
        private CurrentUser $currentUser,
    ) {}

    // The token is 32 hex characters naming a claim that records its owner, so
    // it is neither guessable nor usable by another account, and it survives
    // exactly one granted download.
    public function stage(string $path, string $filename): string
    {
        $this->pruneStale();

        $token = bin2hex(random_bytes(16));

        $claims = $this->claims();
        $claims[$token] = ['path' => $path, 'name' => $filename, 'user' => $this->currentUser->id()];
        $this->session->put(self::CLAIMS_KEY, $claims);

        return $token;
    }

    /**
     * @return array{path: string, name: string}|null
     */
    public function claim(string $token): ?array
    {
        $claims = $this->claims();
        $granted = $this->grantable($claims[$token] ?? null);

        // Spent only where it is granted. Forgetting the token first would let
        // a request that is refused take the owner's one download with it.
        if ($granted === null) {
            return null;
        }

        // The session is not what can make this single-use. Laravel serves
        // concurrent requests on one session without blocking, so both read
        // the claims before either writes them back and each unsets a token
        // the other is still holding — one archive, handed over twice.
        if (! $this->spend($token)) {
            return null;
        }

        unset($claims[$token]);
        $this->session->put(self::CLAIMS_KEY, $claims);

        return $granted;
    }

    // Taking the lock IS the spend, so it is never released: the loser of the
    // race is refused rather than queued behind the winner. It outlives the
    // claim on purpose — an hour is when the sweep drops the file a token
    // could still name, so nothing survives the marker that guards it.
    private function spend(string $token): bool
    {
        return LockStore::lockProvider()
            ->lock(self::SPENT_KEY_PREFIX.$token, Duration::Hour->seconds())
            ->get() === true;
    }

    // Every reason a claim may not be honoured, decided before anything is
    // spent: whose archive it is — a session outlives the account acting in it,
    // and this file is a copy of one account's whole database — and whether it
    // still names a file this application wrote.
    /**
     * @return array{path: string, name: string}|null
     */
    private function grantable(mixed $claim): ?array
    {
        if (! is_array($claim)) {
            return null;
        }

        $path = is_string($claim['path'] ?? null) ? $claim['path'] : '';
        $name = is_string($claim['name'] ?? null) ? $claim['name'] : '';
        $owner = is_int($claim['user'] ?? null) ? $claim['user'] : 0;

        $granted = $owner === $this->currentUser->id()
            && $name !== ''
            && self::isStagedArchive($path);

        return $granted ? ['path' => $path, 'name' => $name] : null;
    }

    // A claim names a file this application wrote into its own staging
    // directory. Re-checked at download time rather than trusted from the
    // session, so a claim is never a way to read a path by naming it.
    private static function isStagedArchive(string $path): bool
    {
        $staging = realpath(UserDataPathService::appPath('tmp-backups'));
        $resolved = $path === '' ? false : realpath($path);

        return $staging !== false
            && $resolved !== false
            && is_file($resolved)
            && str_starts_with($resolved, $staging.DIRECTORY_SEPARATOR);
    }

    // Everything in the staging directory, not just the archives this class
    // hands over: an abandoned .sqlite.enc backup is the same whole database,
    // and a plaintext snapshot orphaned by a crash is worse than either.
    private function pruneStale(): void
    {
        $staging = UserDataPathService::appPath('tmp-backups');
        $cutoff = $this->clock->now()->getTimestamp() - Duration::Hour->seconds();

        foreach ((array) glob($staging.DIRECTORY_SEPARATOR.'beatrax-*') as $staged) {
            if (! is_string($staged) || ! is_file($staged)) {
                continue;
            }

            $modified = @filemtime($staged);

            if ($modified !== false && $modified < $cutoff) {
                @unlink($staged);
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function claims(): array
    {
        $claims = $this->session->get(self::CLAIMS_KEY);

        return is_array($claims) ? $claims : [];
    }
}
