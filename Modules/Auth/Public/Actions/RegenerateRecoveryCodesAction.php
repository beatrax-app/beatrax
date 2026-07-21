<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The caller must either be a developer (owner regenerating a partner's
// codes) or the target user themselves; any other caller raises a 404,
// never a 403, so a probing non-developer cannot confirm the surface exists.
/**
 * @phpstan-type RecoveryCodeList list<string>
 */
final class RegenerateRecoveryCodesAction
{
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly Clock $clock,
        private readonly RecoveryCodeGenerator $generator,
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

            $codesPlain = [];
            while (count($codesPlain) < self::RECOVERY_CODE_COUNT) {
                $code = $this->generator->generate();
                if (in_array($code, $codesPlain, true)) {
                    continue;
                }
                $codesPlain[] = $code;
            }

            foreach ($codesPlain as $plainCode) {
                $this->db->connection()->table('user_recovery_codes')->insert([
                    'user_id' => $targetUser->id,
                    'code_hash' => $this->hasher->make($plainCode),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return $codesPlain;
        });

        return $codesPlain;
    }
}
