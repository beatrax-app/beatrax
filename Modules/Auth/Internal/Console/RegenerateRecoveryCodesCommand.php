<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

// Runs inside a database transaction so a partial failure never leaves
// the user with no usable recovery path. The plaintext codes are
// printed to the console exactly once -- the operator must record
// them on the spot, or re-run the command.
class RegenerateRecoveryCodesCommand extends Command
{
    private const RECOVERY_CODE_COUNT = 10;

    /** @var string */
    protected $signature = 'beatrax:regenerate-recovery-codes {username : Username whose recovery codes to rotate}';

    /** @var string */
    protected $description = 'Invalidate existing unused recovery codes and issue 10 fresh ones for the given user.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly RecoveryCodeGenerator $codeGenerator,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // The required `username` argument is narrowed to a string by
        // Larastan against the typed signature (mirrors the
        // ResetPasswordCommand carve-out), so no is_string() guard
        // is needed here.
        $username = strtolower(trim($this->argument('username')));
        if ($username === '') {
            $this->error('Username is required.');

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()->where('username', $username)->first();
        if (! $user instanceof User) {
            $this->error("User not found: {$username}");

            return self::FAILURE;
        }

        /** @var list<string> $codesPlain */
        $codesPlain = $this->db->connection()->transaction(function () use ($user): array {
            $now = $this->clock->now();

            // Burn every outstanding (unused) code so the old printed
            // sheet stops working. We stamp `used_at` rather than
            // deleting so the audit chain is preserved.
            $this->db->connection()->table('user_recovery_codes')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => $now]);

            // Generate the fresh batch — distinct codes only, mirror
            // the SignupAction collision guard.
            $codesPlain = [];
            while (count($codesPlain) < self::RECOVERY_CODE_COUNT) {
                $code = $this->codeGenerator->generate();
                if (in_array($code, $codesPlain, true)) {
                    continue;
                }
                $codesPlain[] = $code;
            }

            foreach ($codesPlain as $plainCode) {
                UserRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hasher->make($plainCode),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return $codesPlain;
        });

        $this->info("Regenerated {$user->username} recovery codes. Record them now — they will not be shown again:");
        foreach ($codesPlain as $code) {
            $this->line($code);
        }

        return self::SUCCESS;
    }
}
