<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;

uses(RefreshDatabase::class);

// Found on a paired desktop and phone. The insert wrote eleven columns and the
// create event named eight, so a move made on the phone with the memo
// "Verschoven vanaf telefoon" reached the desktop with memo NULL. Both devices
// held all sixteen ops for the pair — the memo was never captured, not lost in
// transit.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'movememo-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(3));
    $this->from = Category::create(['user_id' => null, 'name' => 'From', 'slug' => 'memo-from-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->to = Category::create(['user_id' => null, 'name' => 'To', 'slug' => 'memo-to-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->from->id, $this->period->start, 50000);
});

/**
 * @return list<array<string, mixed>>
 */
function memoAnnouncements(): array
{
    $created = [];
    foreach (Event::dispatched(EnvelopeMoveMutated::class) as [$event]) {
        if ($event->mutationType === 'create') {
            $created[] = $event->dirtyFields;
        }
    }

    return $created;
}

it('announces the memo on both rows of a move', function (): void {
    Event::fake([EnvelopeMoveMutated::class]);

    app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 2000, 'Verschoven vanaf telefoon');

    $announced = memoAnnouncements();
    expect($announced)->toHaveCount(2);
    foreach ($announced as $fields) {
        expect(array_keys($fields))->toContain('memo');
        expect($fields['memo'])->toBe('Verschoven vanaf telefoon');
    }
});

it('announces every column the move actually stored', function (): void {
    Event::fake([EnvelopeMoveMutated::class]);

    app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 2000, 'a memo');

    $stored = (array) DB::table('envelope_moves')->where('user_id', $this->user->id)->orderBy('id')->first();
    // id and user_id are seeded by the applier from the op envelope, and the
    // timestamps are read back by OpLogWriter, so none of the three has to be
    // named here. Every other stored column does.
    $exempt = ['id', 'user_id', 'created_at', 'updated_at'];
    $announced = array_keys(memoAnnouncements()[0]);

    expect(array_values(array_diff(array_keys($stored), $announced, $exempt)))->toBe([]);
});

it('carries a null memo without inventing one', function (): void {
    Event::fake([EnvelopeMoveMutated::class]);

    app(EnvelopeWriter::class)->move($this->user, $this->from->id, $this->to->id, $this->period->start, 2000);

    foreach (memoAnnouncements() as $fields) {
        expect(array_keys($fields))->toContain('memo');
        expect($fields['memo'])->toBeNull();
    }
});
