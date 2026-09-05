<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Console;

use Illuminate\Console\Command;
use Modules\Budgets\Public\Services\BudgetNudgeDispatch;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Notifications\Internal\Support\NotificationPassOutcome;

// Scheduled hourly, not daily, so "you're at 90%" arrives near the spend that
// crossed the threshold. The per-period occurrence key stops the same crossing
// re-firing on the next tick inside the same budget period.
final class EmitBudgetNudgesCommand extends Command
{
    /** @var string */
    protected $signature = 'budgets:emit-nudges';

    /** @var string */
    protected $description = 'Emit budget threshold nudges for every user.';

    public function __construct(
        private readonly BudgetNudgeDispatch $nudges,
        private readonly DeferredNotificationPasses $deferred,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $emitted = 0;
        $deferred = 0;

        User::query()->lazyById(100)->each(function (User $user) use (&$emitted, &$deferred): void {
            // Asked before the carryover fold rather than after it. A scheduled
            // process holds no app-lock key, so every nudge it derived would be
            // refused at the seal — and asking here means the mark records the
            // keyless process, not that this user crossed a threshold.
            if ($this->deferred->deferIfKeyless($user->id, DeferredNotificationPass::BudgetNudges)) {
                $deferred++;

                return;
            }

            $this->nudges->forUser($user->id);
            $emitted++;
        });

        $this->info(NotificationPassOutcome::line('Budget nudges', $emitted, $deferred));

        return self::SUCCESS;
    }
}
