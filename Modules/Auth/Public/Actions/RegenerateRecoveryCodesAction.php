<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// A rejected caller gets a 404 and never a 403, so a prober cannot confirm the
// surface exists.
/**
 * @phpstan-type RecoveryCodeList list<string>
 */
final class RegenerateRecoveryCodesAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly RecoveryCodeMinter $recoveryCodes,
    ) {}

    /**
     * @return list<string> the ten fresh plaintext recovery codes
     */
    public function __invoke(User $caller, string $targetUsername): array
    {
        $target = strtolower(trim($targetUsername));

        if ($target === '') {
            throw new InvalidArgumentException('RegenerateRecoveryCodesAction: target username must not be empty.');
        }

        $isOwner = $caller->is_developer === true;
        $isSelf = strtolower(trim($caller->username)) === $target;

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
