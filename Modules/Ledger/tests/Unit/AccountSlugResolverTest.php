<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\AccountSlugResolver;

uses(RefreshDatabase::class);

function accountSlugUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function asrSeed(int $userId, string $slug, string $iban): void
{
    DB::table('accounts')->insert([
        'user_id' => $userId,
        'name' => 'Seeded',
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->resolver = $this->app->make(AccountSlugResolver::class);
    $this->owner = accountSlugUser('asr-owner');
});

it('kebab-cases the account name and adds nothing else', function (): void {
    expect($this->resolver->resolveUnique($this->owner->id, 'ASN Betaalrekening'))
        ->toBe('asn-betaalrekening');
});

it('walks to a numeric suffix when the base slug is taken', function (): void {
    asrSeed($this->owner->id, 'asn-bank', 'NL57ASNB0123456789');

    expect($this->resolver->resolveUnique($this->owner->id, 'ASN bank'))->toBe('asn-bank-2');
});

it('keeps walking past every taken suffix', function (): void {
    asrSeed($this->owner->id, 'asn-bank', 'NL57ASNB0123456789');
    asrSeed($this->owner->id, 'asn-bank-2', 'NL22ASNB0555999111');
    asrSeed($this->owner->id, 'asn-bank-3', 'NL33ASNB0555999222');

    expect($this->resolver->resolveUnique($this->owner->id, 'ASN bank'))->toBe('asn-bank-4');
});

it('scopes the walk to one user, so a neighbour holding the slug does not push it along', function (): void {
    $other = accountSlugUser('asr-other');
    asrSeed($other->id, 'asn-bank', 'NL57ASNB0123456789');

    expect($this->resolver->resolveUnique($this->owner->id, 'ASN bank'))->toBe('asn-bank');
});

it('falls back to a fixed base when the name slugs to nothing', function (): void {
    expect(AccountSlugResolver::slugify('🎉🎉'))->toBe('account');
    expect($this->resolver->resolveUnique($this->owner->id, '🎉🎉'))->toBe('account');
});
