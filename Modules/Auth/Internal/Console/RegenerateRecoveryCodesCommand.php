<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

// The transaction is what stops a partial failure leaving the user with the
// old sheet burned and no fresh codes issued.
class RegenerateRecoveryCodesCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:regenerate-recovery-codes {username : Username whose recovery codes to rotate}';

    /** @var string */
    protected $description = 'Invalidate existing unused recovery codes and issue 10 fresh ones for the given user.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecoveryCodeMinter $recoveryCodes,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Larastan narrows the required `username` argument to string from the
        // typed signature, so no is_string() guard is needed.
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

            // Burning the old sheet stamps `used_at` rather than deleting, so
            // the audit chain of issued codes survives a rotation.
            $this->db->connection()->table('user_recovery_codes')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => $now]);

            return $this->recoveryCodes->issueFor($user->id);
        });

        $this->info("Regenerated {$user->username} recovery codes. Record them now — they will not be shown again:");
        foreach ($codesPlain as $code) {
            $this->line($code);
        }

        return self::SUCCESS;
    }
}
