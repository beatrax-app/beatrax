<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// pots.category_id outlived the link it carried: the column is still there, both
// PotWriter writes still take a category id, and a pot re-linked to one reads as
// a budget the envelope grid knows nothing about. Only the create path had a
// test, so the edit path could have given the retired link straight back.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'potcat-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN pots',
        'slug' => 'potcat-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'potcat-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->writer = app(PotWriter::class);
});

it('names every pot write that still accepts a category id', function (): void {
    $accepting = [];

    foreach ((new ReflectionClass(PotWriter::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === 'categoryId') {
                $accepting[] = $method->getName();
            }
        }
    }

    sort($accepting);

    expect($accepting)->toBe(['save', 'update'], implode("\n", [
        'A third write taking a category id has appeared, and the two tests below',
        'do not cover it. Give it the same refusal, then name it here — the pin is',
        'what stops a new write path inheriting the retired link by omission.',
    ]));
});

it('refuses to create a pot against a category, and leaves no pot behind', function (): void {
    expect(fn () => $this->writer->save($this->user, 'Rent', null, $this->account->id, null, $this->category->id))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('pots')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses to edit a pot onto a category, and leaves the stored link where it was', function (): void {
    $pot = $this->writer->save($this->user, 'Rent', null, $this->account->id, null, null);

    expect(fn () => $this->writer->update($this->user, $pot->id, 'Rent', null, $this->category->id))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('pots')->where('id', $pot->id)->value('category_id'))->toBeNull();
});

// Every other write refuses the link outright; restore() rewrote the row without
// looking at it, so an archived legacy pot came back active and category-linked
// — a shape no create, no edit and no envelope activation can produce.
function acrArchivedCategoryPot(int $userId, int $accountId, int $categoryId): int
{
    return DB::table('pots')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => $categoryId,
        'name' => 'Legacy groceries pot',
        'currency' => 'EUR',
        'status' => 'archived',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('clears the retired link when it restores an archived pot', function (): void {
    $potId = acrArchivedCategoryPot($this->user->id, $this->account->id, $this->category->id);

    $this->writer->restore($this->user, $potId);

    $row = DB::table('pots')->where('id', $potId)->first();

    expect($row->status)->toBe('active')
        ->and($row->category_id)->toBeNull();
});

it('writes no movement while it clears the link', function (): void {
    $potId = acrArchivedCategoryPot($this->user->id, $this->account->id, $this->category->id);

    $this->writer->restore($this->user, $potId);

    expect(DB::table('pot_movements')->where('pot_id', $potId)->count())->toBe(0);
});

// A column cleared and not announced is one the peer still holds: the next
// frame from this device carries only `status`, and the link survives there.
it('announces the cleared link beside the status', function (): void {
    $potId = acrArchivedCategoryPot($this->user->id, $this->account->id, $this->category->id);

    Event::fake([EntityMutated::class]);

    app(PotWriter::class)->restore($this->user, $potId);

    Event::assertDispatched(
        EntityMutated::class,
        static function (EntityMutated $event): bool {
            $fields = $event->dirtyFields;

            return $event->table === 'pots'
                && $fields === ['status' => 'active', 'category_id' => null];
        },
    );
});

it('announces nothing but the status for a pot that never carried a link', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, null, null);
    $this->writer->archive($this->user, $pot->id);

    Event::fake([EntityMutated::class]);

    app(PotWriter::class)->restore($this->user, $pot->id);

    Event::assertDispatched(
        EntityMutated::class,
        static fn (EntityMutated $event): bool => $event->dirtyFields === ['status' => 'active'],
    );
});
