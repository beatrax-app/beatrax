<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Pots\Public\Services\PotWriter;

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
