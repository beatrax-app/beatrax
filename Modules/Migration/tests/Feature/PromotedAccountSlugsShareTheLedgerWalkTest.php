<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// accounts.slug carries one unique(user_id, slug) and used to have two
// writers: AccountSlugResolver for imports and a private copy in the
// promoter. A promoted account has to see the accounts an import already
// made, and fall back the same way when a name slugs to nothing.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'promoted-slug-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

function promoteYnab4ForSlugs(User $user): void
{
    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    app(ConfirmMigration::class)->__invoke($run->id, $user);
}

it('walks past an account an import already parked on the same slug', function (): void {
    $promotedName = 'Checking';

    Account::create([
        'user_id' => $this->user->id,
        'name' => $promotedName,
        'slug' => 'checking',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    promoteYnab4ForSlugs($this->user);

    $slugs = DB::table('accounts')
        ->where('user_id', $this->user->id)
        ->where('name', $promotedName)
        ->orderBy('id')
        ->pluck('slug')
        ->all();

    expect($slugs)->toBe(['checking', 'checking-2']);
});

it('leaves every promoted account on a slug no other account under the user holds', function (): void {
    promoteYnab4ForSlugs($this->user);

    $slugs = DB::table('accounts')->where('user_id', $this->user->id)->pluck('slug')->all();

    expect($slugs)->not->toBeEmpty()
        ->and(array_unique($slugs))->toHaveCount(count($slugs));
});
