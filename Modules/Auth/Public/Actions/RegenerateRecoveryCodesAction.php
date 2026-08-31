<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Internal\Services\AccountOwner;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// A rejected caller gets a 404 and never a 403, so a prober cannot confirm the
// surface exists.
/**
 * @phpstan-type RecoveryCodeList list<string>
 */
final readonly class RegenerateRecoveryCodesAction
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecoveryCodeMinter $recoveryCodes,
        private AccountOwner $owner,
    ) {}

    /**
     * @return list<string> the ten fresh plaintext recovery codes
     */
    public function __invoke(User $caller, string $targetUsername): array
    {
        $target = Username::normalize($targetUsername);

        if ($target === '') {
            throw new InvalidArgumentException('RegenerateRecoveryCodesAction: target username must not be empty.');
        }

        $isOwner = $this->owner->isOwner($caller);
        $isSelf = Username::normalize($caller->username) === $target;

        if (! $isOwner && ! $isSelf) {
            throw new NotFoundHttpException;
        }

        $targetUser = User::query()->where('username', $target)->first();

        if (! $targetUser instanceof User) {
            throw new NotFoundHttpException;
        }

        /** @var list<string> $codesPlain */
        $codesPlain = $this->db->connection()->transaction(function () use ($targetUser): array {
            $now = $this->clock->now();

            $this->db->connection()->table('user_recovery_codes')
                ->where('user_id', $targetUser->id)
                ->whereNull('used_at')
                ->update(['used_at' => $now]);

            return $this->recoveryCodes->issueFor($targetUser->id);
        });

        return $codesPlain;
    }
}
