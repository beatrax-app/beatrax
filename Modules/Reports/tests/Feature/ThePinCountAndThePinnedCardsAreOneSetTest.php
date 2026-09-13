<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Services\PinnedReportsQuery;
use Modules\Reports\Internal\Services\SavedReportsQuery;

uses(RefreshDatabase::class);

// `pinned` and `pin_order` are written together and travel as two separate
// field ops, so a concurrent unpin on a second device converges on a row
// carrying one of them and not the other. The dashboard needs both before it
// draws a card, and so does the cap inside the write -- the library's count
// and its button read the flag alone, so one screen said "2 of 3 pinned" over
// a dashboard drawing one card, and offered to unpin a card nobody could see.

function pcsUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'pcs-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function pcsReport(DatabaseManager $db, User $user, string $name, bool $pinned, ?int $pinOrder): int
{
    return $db->connection()->table('saved_reports')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'definition' => json_encode(['metric' => 'spend', 'dimension' => 'category', 'periodPreset' => 'this_month']),
        'pinned' => $pinned,
        'pin_order' => $pinOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, int>
 */
function pcsCrossedSet(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pcsUser();

    pcsReport($db, $user, 'Whole pin', true, 1);
    pcsReport($db, $user, 'Flag without an order', true, null);
    pcsReport($db, $user, 'Order without a flag', false, 2);

    test()->actingAs($user);

    return [
        'library' => app(SavedReportsQuery::class)->forUser($user)->filter(static fn ($row): bool => $row->pinned)->count(),
        'dashboard' => count(app(PinnedReportsQuery::class)->forUser($user)),
    ];
}

it('counts a pin on the library exactly where the dashboard draws one', function (): void {
    $counts = pcsCrossedSet();

    expect($counts['library'])->toBe($counts['dashboard'])
        ->and($counts['library'])->toBe(1);
});

it('says the same number on the page the reader reads it from', function (): void {
    pcsCrossedSet();

    Livewire::test(ReportsIndex::class)->assertSee('1 of 3 pinned');
});
