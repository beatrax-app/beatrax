<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;

// Both writers hand the id they read back to an EnvelopeAssignmentMutated sync
// op. A read that finds nothing used to coerce to 0, and a create op with
// primary key 0 replays against whatever row the peer happens to hold.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'pk-zero-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'pk-zero-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->announced = [];
    app(Dispatcher::class)->listen(
        EnvelopeAssignmentMutated::class,
        function (EnvelopeAssignmentMutated $event): void {
            if ($event->mutationType === 'create') {
                $this->announced[] = $event->assignmentId;
            }
        },
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The row is gone by the time the write reads its id back, which is the one
// outcome the four read-back sites disagreed about. Registered after any
// fixture rows are in, so only the write under test loses its row.
function pkZeroVanishTheAssignmentJustInserted(): void
{
    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with(ltrim($query->sql), 'insert into "envelope_assignments"')) {
            DB::table('envelope_assignments')->delete();
        }
    });
}

it('refuses an assignment whose id it cannot read back', function (): void {
    pkZeroVanishTheAssignmentJustInserted();

    $write = fn (): mixed => app(EnvelopeWriter::class)
        ->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse('2026-08-15'), 10000);

    expect($write)->toThrow(IdReadBackFailedException::class)
        ->and($this->announced)->toBe([])
        ->and(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses a rekey whose ids it cannot read back', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-10 09:00:00']);
    $this->user->refresh();

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse('2026-07-15'), 10000);
    $this->announced = [];

    pkZeroVanishTheAssignmentJustInserted();

    DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => 28]);
    $this->user->refresh();

    $rekey = fn (): mixed => app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods(15);

    // The rekey owns one transaction, so the refusal puts every assignment back
    // on the key it was written under rather than leaving the plan half-moved.
    expect($rekey)->toThrow(IdReadBackFailedException::class)
        ->and($this->announced)->toBe([])
        ->and(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(1);
});
